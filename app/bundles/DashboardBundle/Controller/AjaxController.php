<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic, NP. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.com
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\DashboardBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use Mautic\CoreBundle\Helper\InputHelper;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class AjaxController
 *
 * @package Mautic\DashboardBundle\Controller
 */
class AjaxController extends CommonAjaxController
{
    /**
     * Count how many visitors is currently viewing some page.
     * 
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function viewingVisitorsAction(Request $request)
    {
        $dataArray  = array('success' => 0);

        /** @var \Mautic\PageBundle\Entity\PageRepository $pageRepository */
        $pageRepository = $this->factory->getEntityManager()->getRepository('MauticPageBundle:Hit');
        $dataArray['viewingVisitors'] = $pageRepository->countVisitors(60, true);

        $dataArray['success'] = 1;

        return $this->sendJsonResponse($dataArray);
    }
}