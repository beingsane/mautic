<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic, NP. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.com
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\PageBundle\Controller;

use Mautic\CoreBundle\Controller\FormController as CommonFormController;
use Mautic\PageBundle\Event\PageEvent;
use Mautic\PageBundle\PageEvents;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PublicController extends CommonFormController
{
    public function indexAction($slug1, $slug2 = '', $slug3 = '')
    {
        //find the page
        $factory    = $this->get('mautic.factory');
        $model      = $factory->getModel('page.page');
        $translator = $this->get('translator');
        $entity     = $model->getEntityBySlugs($slug1, $slug2, $slug3);

        if (!empty($entity)) {
            $category     = $entity->getCategory();
            $catPublished = (!empty($category)) ? $category->isPublished() : true;
            $published    = $entity->isPublished();

            //make sure the page is published or deny access if not
            if ((!$catPublished || !$published) && (!$this->get('mautic.security')->hasEntityAccess(
                    'page:pages:viewown', 'page:pages:viewother', $entity->getCreatedBy()))
            ) {
                $model->hitPage($entity, $this->request, 401);
                throw new AccessDeniedHttpException($translator->trans('mautic.core.url.error.401'));
            }

            //make sure URLs match up
            $url = $model->generateUrl($entity, false);

            if ($this->request->getRequestUri() != $url) {
                $model->hitPage($entity, $this->request, 301);
                return $this->redirect($url, 301);
            }

            //let's check for preferred languages if we have a multi-language group of pages
            $parent   = $entity->getTranslationParent();
            $children = $entity->getTranslationChildren();
            if ($parent || count($children)) {
                $session = $this->get('session');
                if ($parent) {
                    $children = $parent->getTranslationChildren();
                } else {
                    $parent = $entity;
                }

                //check to see if this group has already been redirected
                $doNotRedirect = $session->get('mautic.page.'.$parent->getId().'.donotredirect', false);

                if (empty($doNotRedirect)) {
                    $session->set('mautic.page.'.$parent->getId().'.donotredirect', 1);

                    //generate a list of translations
                    $langs    = array($parent->getLanguage());
                    foreach ($children as $c) {
                        $langs[$c->getId()] = $c->getLanguage();
                    }

                    //loop through the translations to ensure there is a generic option for each
                    //dialect (i.e en if en_US is present)
                    $pageLangs = array();
                    $pageIds   = array();
                    foreach ($langs as $id => $l) {
                        $pageIds[]   = $id;
                        $pageLangs[] = $l;
                        if (strpos($l, '_') !== false) {
                            $base = substr($l, 0, 2);
                            if (!in_array($base, $pageLangs)) {
                                $pageLangs[] = $base;
                                $pageIds[]   = $id;
                            }
                        }
                    }

                    //get the browser preferred languages
                    $langs = explode(',', $this->request->server->get('HTTP_ACCEPT_LANGUAGE'));
                    foreach ($langs as $k => $l) {
                        if ($pos = strpos($l, ';q=') !== false) {
                            //remove weights
                            $l = substr($l, 0, ($pos+1));
                        }
                        //change - to _
                        $langs[$k] = str_replace('-', '_', $l);
                    }

                    //loop through the browser languages to ensure there is a generic option for each
                    //dialect (i.e en if en_US is present)
                    $userLangs = array();
                    foreach ($langs as $k => $l) {
                        $userLangs[] = $l;

                        if (strpos($l, '_') !== false) {
                            $base = substr($l, 0, 2);
                            if (!in_array($base, $langs) && !in_array($base, $userLangs)) {
                                $userLangs[] = $base;
                            }
                        }
                    }

                    //get translations in order of browser preference
                    $matches = array_intersect($userLangs, $pageLangs);
                    if (!empty($matches)) {
                        $preferred = reset($matches);
                        $key       = array_search($preferred, $pageLangs);
                        $pageId    = $pageIds[$key];

                        //redirect if not already on the correct page
                        if ($pageId != $entity->getId()) {
                            $page = ($pageId == $parent->getId()) ? $parent : $children[$pageId];
                            $url  = $model->generateUrl($page, false);
                            $model->hitPage($entity, $this->request, 302);
                            return $this->redirect($url, 302);
                        }
                    }
                }
            }

            //all the checks pass so display the content
            $template   = $entity->getTemplate();

            $kernelDir  = $this->container->getParameter('kernel.root_dir');
            $configFile = $kernelDir . '/Resources/views/Templates/'.$template.'/config.php';

            if (!file_exists($configFile)) {
                throw $this->createNotFoundException($translator->trans('mautic.page.page.error.template.notfound'));
            }

            $tmplConfig = include_once $configFile;

            if (!isset($tmplConfig['slots']['page'])) {
                throw $this->createNotFoundException($translator->trans('mautic.page.page.error.template.notfound'));
            }

            $slots      = $tmplConfig['slots']['page'];
            $dispatcher = $this->get('event_dispatcher');
            if ($dispatcher->hasListeners(PageEvents::PAGE_ON_DISPLAY)) {
                $event = new PageEvent($entity);
                $dispatcher->dispatch(PageEvents::PAGE_ON_DISPLAY, $event);
                $content = $event->getContent();
            } else {
                $content = $entity->getContent();
            }

            $model->hitPage($entity, $this->request, 200);

            $googleAnalytics = $this->get('mautic.factory')->getParameter('google_analytics');

            return $this->render('MauticPageBundle::public.html.php', array(
                'slots'           => $slots,
                'content'         => $content,
                'page'            => $entity,
                'template'        => $template,
                'googleAnalytics' => $googleAnalytics
            ));
        }

        $model->hitPage($entity, $this->request, 404);
        throw $this->createNotFoundException($translator->trans('mautic.core.url.error.404'));
    }

    public function trackingImageAction()
    {
        ignore_user_abort(true);

        //turn off gzip compression
        if ( function_exists( 'apache_setenv' ) ) {
            apache_setenv( 'no-gzip', 1 );
        }

        ini_set('zlib.output_compression', 0);

        $response = new Response();

        //removing any content encoding like gzip etc.
        $response->headers->set('Content-encoding', 'none');

        //check to ses if request is a POST
        if ($this->request->getMethod() == 'GET') {
            //return 1x1 pixel transparent gif
            $response->headers->set('Content-type', 'image/gif');
            //avoid cache time on browser side
            $response->headers->set('Content-Length', '42');
            $response->headers->set('Cache-Control', 'private, no-cache, no-cache=Set-Cookie, proxy-revalidate');
            $response->headers->set('Expires', 'Wed, 11 Jan 2000 12:59:00 GMT');
            $response->headers->set('Last-Modified', 'Wed, 11 Jan 2006 12:59:00 GMT');
            $response->headers->set('Pragma', 'no-cache');

            $response->setContent(sprintf('%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%',71,73,70,56,57,97,1,0,1,0,128,255,0,192,192,192,0,0,0,33,249,4,1,0,0,0,0,44,0,0,0,0,1,0,1,0,0,2,2,68,1,0,59));
        } else {
            $response->setContent(' ');
        }

        //Create page entry
        $this->get('mautic.factory')->getModel('page.page')->hitPage(null, $this->request);

        return $response;
    }
}