<?php

/**
 * (c) Kitodo. Key to digital objects e.V. <contact@kitodo.org>
 *
 * This file is part of the Kitodo and TYPO3 projects.
 *
 * @license GNU General Public License version 3 or later.
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Kitodo\Dlf\Api\Viaf;

use Kitodo\Dlf\Common\Helper;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * VIAF API Profile class
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 **/
class Profile
{
    /**
     * @access private
     * @var Logger This holds the logger
     */
    protected $logger;

    /**
     * @access private
     * @var Client This holds the client
     */
    private $client;

    /**
     * @access private
     * @var \SimpleXmlElement|false The raw VIAF profile or false if not found
     **/
    private $raw = null;

    /**
     * Constructs client instance
     *
     * @param string $viaf: the VIAF identifier of the profile
     *
     * @return void
     **/
    public function __construct($viaf)
    {
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(static::class);
        $this->client = new Client($viaf, GeneralUtility::makeInstance(RequestFactory::class));
    }

    /**
     * Get the VIAF profile data
     *
     * @return array|false
     **/
    public function getData()
    {
        $this->getRaw();
        if (!empty($this->raw)) {
            $data = [];
            $data['address'] = $this->getAddress();
            $data['fullName'] = $this->getFullName();
            return $data;
        } else {
            $this->logger->warning('No data found for given VIAF URL');
            return false;
        }
    }

    /**
     * Get the address
     *
     * @return string|false
     **/
    public function getAddress()
    {
        $this->getRaw();
        if (!empty($this->raw->asXML())) {
            return (string) $this->raw->xpath('./ns1:nationalityOfEntity/ns1:data/ns1:text')[0];
        } else {
            $this->logger->warning('No address found for given VIAF URL');
            return false;
        }
    }

    /**
     * Get the full name
     *
     * @return string|false
     **/
    public function getFullName()
    {
        $this->getRaw();
        if (!empty($this->raw->asXML())) {
            $rawName = $this->raw->xpath('./ns1:mainHeadings/ns1:data/ns1:text');
            $name = (string) $rawName[0];
            $name = trim(trim(trim($name), ','), '.');
            return $name;
        } else {
            $this->logger->warning('No name found for given VIAF URL');
            return false;
        }
    }

    /**
     * Get the VIAF raw profile data
     *
     * @return void
     **/
    protected function getRaw()
    {
        $data = $this->client->getData();
        if (!isset($this->raw) && $data != false) {
            $this->raw = Helper::getXmlFileAsString($data);
            $this->raw->registerXPathNamespace('ns1', 'http://viaf.org/viaf/terms#');
        }
    }
}
