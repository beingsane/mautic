<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic, NP. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.com
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\FormBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use Mautic\CoreBundle\Helper\CsvHelper;
use Mautic\FormBundle\Entity\Action;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Mautic\PageBundle\Entity\Page;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class LoadFormResultData
 *
 * @package Mautic\FormBundle\DataFixtures\ORM
 */
class LoadFormResultData extends AbstractFixture implements OrderedFixtureInterface, ContainerAwareInterface
{

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * {@inheritdoc}
     */

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * @param ObjectManager $manager
     */
    public function load(ObjectManager $manager)
    {
        $factory   = $this->container->get('mautic.factory');
        $pageModel = $factory->getModel('page.page');
        $repo      = $factory->getModel('form.submission')->getRepository();

        $importResults = function($results) use ($factory, $pageModel, $repo) {
            foreach ($results as $count => $rows) {
                $submission = new Submission();
                $submission->setDateSubmitted(new \DateTime());

                foreach ($rows as $col => $val) {
                    if ($val != "NULL") {
                        $setter = "set" . ucfirst($col);
                        if (in_array($col, array('form', 'page', 'ipAddress'))) {
                            $entity = $this->getReference($col . '-' . $val);
                            if ($col == 'page') {
                                $submission->setReferer($pageModel->generateUrl($entity));
                            }
                            $submission->$setter($entity);
                            unset($rows[$col]);
                        } else {
                            //the rest are custom field values
                            break;
                        }
                    }
                }

                $submission->setResults($rows);
                $repo->saveEntity($submission);
            }
        };

        $results = CsvHelper::csv_to_array(__DIR__ . '/fakeresultdata.csv');
        $importResults($results);

        sleep(2);

        $results2 = CsvHelper::csv_to_array(__DIR__ . '/fakeresult2data.csv');
        $importResults($results2);
    }

    /**
     * @return int
     */
    public function getOrder()
    {
        return 8;
    }
}