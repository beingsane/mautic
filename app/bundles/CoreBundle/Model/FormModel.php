<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic, NP. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.com
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CoreBundle\Model;

use Mautic\UserBundle\Entity\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class FormModel
 *
 * @package Mautic\CoreBundle\Model
 */
class FormModel extends CommonModel
{

    /**
     * Get a specific entity
     *
     * @param $id
     * @return null|object
     */
    public function getEntity($id = null)
    {
        if (null !== $id) {
            $repo = $this->getRepository();
            if (method_exists($repo, 'getEntity')) {
                return $repo->getEntity($id);
            } else {
                return $repo->find($id);
            }
        } else {
            return null;
        }
    }

    /**
     * Return list of entities
     *
     * @param array $args [start, limit, filter, orderBy, orderByDir]
     * @return mixed
     */
    public function getEntities(array $args = array())
    {
        //set the translator
        $repo = $this->getRepository();
        $repo->setTranslator($this->translator);
        $repo->setCurrentUser(
            $this->factory->getUser()
        );

        return $repo->getEntities($args);
    }

    /**
     * Lock an entity to prevent multiple people from editing
     *
     * @param $entity
     */
    public function lockEntity($entity)
    {
        //lock the row if applicable
        if (method_exists($entity, 'setCheckedOut')) {
            $user = $this->factory->getUser();
            if ($user->getId()) {
                $entity->setCheckedOut(new \DateTime());
                $entity->setCheckedOutBy($user);
                $this->em->persist($entity);
                $this->em->flush();
            }
        }
    }

    /**
     * Check to see if the entity is locked
     *
     * @param $entity
     * @return bool
     */
    public function isLocked($entity)
    {
        if (method_exists($entity, 'getCheckedOut')) {
            $checkedOut = $entity->getCheckedOut();
            if (!empty($checkedOut)) {
                //is it checked out by the current user?
                $checkedOutBy = $entity->getCheckedOutBy();
                if (!empty($checkedOutBy) && $checkedOutBy->getId() !==
                    $this->factory->getUser()->getId()) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Unlock an entity that prevents multiple people from editing
     *
     * @param $entity
     */
    public function unlockEntity($entity)
    {
        //unlock the row if applicable
        if (method_exists($entity, 'setCheckedOut')) {
            //flush any potential changes
            $this->em->refresh($entity);

            $entity->setCheckedOut(null);
            $entity->setCheckedOutBy(null);

            $this->em->persist($entity);
            $this->em->flush();
        }
    }

    /**
     * Create/edit entity
     *
     * @param       $entity
     * @param       $unlock
     * @return mixed
     */
    public function saveEntity($entity, $unlock = true)
    {
        $isNew = ($entity->getId()) ? false : true;

        //set some defaults
        $this->setTimestamps($entity, $isNew, $unlock);

        $event = $this->dispatchEvent("pre_save", $entity, $isNew);
        $this->getRepository()->saveEntity($entity);
        $this->dispatchEvent("post_save", $entity, $isNew, $event);

        return $entity;
    }


    /**
     * Save an array of entities
     *
     * @param  $entities
     * @return array
     */
    public function saveEntities($entities, $unlock = true)
    {
        //iterate over the results so the events are dispatched on each delete
        $batchSize = 20;
        foreach ($entities as $k => $entity) {
            $isNew = ($entity->getId()) ? false : true;

            //set some defaults
            $this->setTimestamps($entity, $isNew, $unlock);

            $event = $this->dispatchEvent("pre_save", $entity, $isNew);
            $this->getRepository()->saveEntity($entity, false);
            $this->dispatchEvent("post_save", $entity, $isNew, $event);

            if ((($k + 1) % $batchSize) === 0) {
                $this->em->flush();
            }
        }
        $this->em->flush();
    }

    /**
     * Toggles entity publish status
     *
     * @param $entity
     */
    public function togglePublishStatus($entity)
    {
        $status = $entity->getPublishStatus();

        switch ($status) {
            case 'unpublished':
                $entity->setIsPublished(true);
                break;
            case 'published':
            case 'expired':
            case 'pending':
                $entity->setIsPublished(false);
                break;
        }

        //set timestamp changes
        $this->setTimestamps($entity, false, false);

        //hit up event listeners
        $event = $this->dispatchEvent("pre_save", $entity, false);
        $this->getRepository()->saveEntity($entity);
        $this->dispatchEvent("post_save", $entity, false, $event);
    }


    /**
     * Set timestamps and user ids
     *
     * @param $entity
     * @param $isNew
     * @param $unlock
     */
    public function setTimestamps(&$entity, $isNew, $unlock = true)
    {
        $user = $this->factory->getUser(true);
        if ($isNew) {
            if (method_exists($entity, 'setDateAdded')) {
                $entity->setDateAdded(new \DateTime());
            }

            if ($user instanceof User && method_exists($entity, 'setCreatedBy')) {
                $entity->setCreatedBy($user);
            }
        } else {
            if (method_exists($entity, 'setDateModified')) {
                $entity->setDateModified(new \DateTime());
            }

            if ($user instanceof User && method_exists($entity, 'setModifiedBy')) {
                $entity->setModifiedBy($user);
            }
        }

        //unlock the row if applicable
        if ($unlock && method_exists($entity, 'setCheckedOut')) {
            $entity->setCheckedOut(null);
            $entity->setCheckedOutBy(null);
        }
    }

    /**
     * Delete an entity
     *
     * @param  $entity
     */
    public function deleteEntity($entity)
    {
        //take note of ID before doctrine wipes it out
        $id = $entity->getId();
        $event = $this->dispatchEvent("pre_delete", $entity);
        $this->getRepository()->deleteEntity($entity);
        //set the id for use in events
        $entity->deletedId = $id;
        $this->dispatchEvent("post_delete", $entity, false, $event);
    }

    /**
     * Delete an array of entities
     *
     * @param  $ids
     * @return array
     */
    public function deleteEntities($ids)
    {
        $entities = array();
        //iterate over the results so the events are dispatched on each delete
        $batchSize = 20;
        foreach ($ids as $k => $id) {
            $entity = $this->getEntity($id);
            $entities[$id] = $entity;
            if ($entity !== null) {
                $event = $this->dispatchEvent("pre_delete", $entity);
                $this->getRepository()->deleteEntity($entity, false);
                $this->dispatchEvent("post_delete", $entity, false, $event);
            }
            if ((($k + 1) % $batchSize) === 0) {
                $this->em->flush();
            }
        }
        $this->em->flush();
        //retrieving the entities while here so may as well return them so they can be used if needed
        return $entities;
    }

    /**
     * Creates the appropriate form per the model
     *
     * @param      $entity
     * @param      $formFactory
     * @param null $action
     * @param array $options
     * @return mixed
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function createForm($entity, $formFactory, $action = null, $options = array())
    {
        throw new NotFoundHttpException('Form object not found.');
    }

    /**
     * Dispatches events for child classes
     *
     * @param $action
     * @param $entity
     * @param $isNew
     * @param $event
     */
    protected function dispatchEvent($action, &$entity, $isNew = false, $event = false)
    {
        //...
    }

    /**
     * Set default subject for user contact form
     *
     * @param $subject
     * @param $entity
     * @return mixed
     */
    public function getUserContactSubject($subject, $entity)
    {
        switch ($subject) {
            case 'locked':
                $msg = 'mautic.user.user.contact.locked';
                break;
            default:
                $msg = 'mautic.user.user.contact.regarding';
                break;
        }

        $nameGetter = $this->getNameGetter();
        $subject    = $this->translator->trans($msg, array(
            '%entityName%' => $entity->$nameGetter(),
            '%entityId%'   => $entity->getId()
        ));

        return $subject;
    }

    /**
     * Returns the function used to name the entity
     *
     * @return string
     */
    public function getNameGetter()
    {
        return "getName";
    }

    /**
     * Retrieve entity based on id/alias slugs
     *
     * @param $slug1
     * @param $slug2
     * @param $slug3
     */
    public function getEntityBySlugs($slug1, $slug2 = '', $slug3 = '')
    {
        if (!empty($slug3)) {
            $idSlug = $slug3;
        } elseif (!empty($slug2)) {
            $idSlug = $slug2;
        } else {
            $idSlug = $slug1;
        }

        $parts  = explode(':', $idSlug);
        if (count($parts) == 2) {
            $entity = $this->getEntity($parts[0]);

            if (!empty($entity)) {
                return $entity;
            }
        }

        return false;
    }
}