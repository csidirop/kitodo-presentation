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

namespace Kitodo\Dlf\Controller;

use Kitodo\Dlf\Common\Doc;
use Kitodo\Dlf\Common\IiifManifest;
use Kitodo\Dlf\Domain\Model\Document;
use Kitodo\Dlf\Plugin\FullTextGenerator;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Database\Connection;
use Ubl\Iiif\Presentation\Common\Model\Resources\ManifestInterface;
use Ubl\Iiif\Presentation\Common\Vocabulary\Motivation;

/**
 * Controller class for the plugin 'Page View'.
 *
 * @author Sebastian Meyer <sebastian.meyer@slub-dresden.de>
 * @package TYPO3
 * @subpackage dlf
 * @access public
 */
class PageViewController extends AbstractController
{
    /**
     * Holds the controls to add to the map
     *
     * @var array
     * @access protected
     */
    protected $controls = [];

    /**
     * Holds the current images' URLs and MIME types
     *
     * @var array
     * @access protected
     */
    protected $images = [];

    /**
     * Holds the current fulltexts' URLs
     *
     * @var array
     * @access protected
     */
    protected $fulltexts = [];

    /**
     * Holds the current AnnotationLists / AnnotationPages
     *
     * @var array
     * @access protected
     */
    protected $annotationContainers = [];

    /**
     * Holds the parsed active OCR engines
     * @var string
     * @access protected
     */
    protected static $ocrEngines = "";

    /**
     * The main method of the plugin
     *
     * @return void
     */
    public function mainAction()
    {
        // Load current document.
        $this->loadDocument($this->requestData);
        $this->parseOCRengines(GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('dlf')['ocrEngines']."/".GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('dlf')['ocrEnginesConfig']);
        $this->clearPageCache();

        // Proccess request: Do OCR on given image(s):
        if ($_POST["request"]) {
            $this->generateFullText();
            $this->parseOCRengines();
        }

        if ($this->isDocMissingOrEmpty()) {
            // Quit without doing anything if required variables are not set.
            return '';
        } else {
            if (!empty($this->requestData['logicalPage'])) {
                $this->requestData['page'] = $this->document->getDoc()->getPhysicalPage($this->requestData['logicalPage']);
                // The logical page parameter should not appear again
                unset($this->requestData['logicalPage']);
            }
            // Set default values if not set.
            // $this->requestData['page'] may be integer or string (physical structure @ID)
            if ((int) $this->requestData['page'] > 0 || empty($this->requestData['page'])) {
                $this->requestData['page'] = MathUtility::forceIntegerInRange((int) $this->requestData['page'], 1, $this->document->getDoc()->numPages, 1);
            } else {
                $this->requestData['page'] = array_search($this->requestData['page'], $this->document->getDoc()->physicalStructure);
            }
            $this->requestData['double'] = MathUtility::forceIntegerInRange($this->requestData['double'], 0, 1, 0);
        }
        // Get image data.
        $this->images[0] = $this->getImage($this->requestData['page']);
        $this->fulltexts[0] = $this->getFulltext($this->requestData['page']);
        $this->annotationContainers[0] = $this->getAnnotationContainers($this->requestData['page']);
        if ($this->requestData['double'] && $this->requestData['page'] < $this->document->getDoc()->numPages) {
            $this->images[1] = $this->getImage($this->requestData['page'] + 1);
            $this->fulltexts[1] = $this->getFulltext($this->requestData['page'] + 1);
            $this->annotationContainers[1] = $this->getAnnotationContainers($this->requestData['page'] + 1);
        }

        // Get the controls for the map.
        $this->controls = explode(',', $this->settings['features']);

        $this->view->assign('forceAbsoluteUrl', $this->settings['forceAbsoluteUrl']);

        $this->addViewerJS();

        $this->view->assign('images', $this->images);
        $this->view->assign('docId', $this->requestData['id']);
        $this->view->assign('page', $this->requestData['page']);
    }

    /**
     * Get fulltext URL and MIME type
     *
     * @access protected
     *
     * @param int $page: Page number
     *
     * @return array URL and MIME type of fulltext file
     */
    protected function getFulltext($page)
    {
        $fulltext = [];
        // Get fulltext link:
        $fileGrpsFulltext = GeneralUtility::trimExplode(',', $this->extConf['fileGrpFulltext']);

        //check if remote fulltext exists:
        while ($fileGrpFulltext = array_shift($fileGrpsFulltext)) { 
            if (!empty($this->document->getDoc()->physicalStructureInfo[$this->document->getDoc()->physicalStructure[$page]]['files'][$fileGrpFulltext])) { //fulltext is remote present
                if (PageViewController::getOCRengine(Doc::$extKey) == "originalremote") {
                    $fulltext['url'] = $this->document->getDoc()->getFileLocation($this->document->getDoc()->physicalStructureInfo[$this->document->getDoc()->physicalStructure[$page]]['files'][$fileGrpFulltext]);
                    if ($this->settings['useInternalProxy']) {
                        // Configure @action URL for form.
                        $uri = $this->uriBuilder->reset()
                            ->setTargetPageUid($GLOBALS['TSFE']->id)
                            ->setCreateAbsoluteUri(!empty($this->settings['forceAbsoluteUrl']) ? true : false)
                            ->setArguments([
                                'eID' => 'tx_dlf_pageview_proxy',
                                'url' => $fulltext['url'],
                                'uHash' => GeneralUtility::hmac($fulltext['url'], 'PageViewProxy')
                                ])
                            ->build();

                        $fulltext['url'] = $uri;
                    }
                    $fulltext['mimetype'] = $this->document->getDoc()->getFileMimeType($this->document->getDoc()->physicalStructureInfo[$this->document->getDoc()->physicalStructure[$page]]['files'][$fileGrpFulltext]);
                }
                setcookie('tx-dlf-ocr-remotepresent', "Y", ['SameSite' => 'lax']);
                break;
            } else { //no fulltext present
                $this->logger->notice('No full-text file found for page "' . $page . '" in fileGrp "' . $fileGrpFulltext . '"');
                setcookie('tx-dlf-ocr-remotepresent', "N", ['SameSite' => 'lax']);
            }
        }

        //check if local fulltext exists:
        if (PageViewController::getOCRengine(Doc::$extKey) != "originalremote") {
            if (FullTextGenerator::checkLocal(Doc::$extKey, $this->document, $page)) { //fulltext is locally present
                $fulltext['url'] = PageViewController::getServerUrl() . "/" . FullTextGenerator::getPageLocalPath(Doc::$extKey, $this->document, $page);
                $fulltext['mimetype'] = "text/xml";
            }
        }

        if (empty($fulltext)) {
            $this->logger->notice('No full-text file found for page "' . $page . '" in fileGrps "' . $this->extConf['fileGrpFulltext'] . '"');
        }
        return $fulltext;
    }

    /**
     * Adds Viewer javascript
     *
     * @access protected
     *
     * @return void
     */
    protected function addViewerJS()
    {
        // Viewer configuration.
        $viewerConfiguration = '$(document).ready(function() {
                if (dlfUtils.exists(dlfViewer)) {
                    tx_dlf_viewer = new dlfViewer({
                        controls: ["' . implode('", "', $this->controls) . '"],
                        div: "' . $this->settings['elementId'] . '",
                        progressElementId: "' . $this->settings['progressElementId'] . '",
                        images: ' . json_encode($this->images) . ',
                        fulltexts: ' . json_encode($this->fulltexts) . ',
                        annotationContainers: ' . json_encode($this->annotationContainers) . ',
                        useInternalProxy: ' . ($this->settings['useInternalProxy'] ? 1 : 0) . '
                    });
                }
            });';
        $this->view->assign('viewerConfiguration', $viewerConfiguration);
    }

    /**
     * Get all AnnotationPages / AnnotationLists that contain text Annotations with motivation "painting"
     *
     * @access protected
     *
     * @param int $page: Page number
     * @return array An array containing the IRIs of the AnnotationLists / AnnotationPages as well as
     *               some information about the canvas.
     */
    protected function getAnnotationContainers($page)
    {
        if ($this->document->getDoc() instanceof IiifManifest) {
            $canvasId = $this->document->getDoc()->physicalStructure[$page];
            $iiif = $this->document->getDoc()->getIiif();
            if ($iiif instanceof ManifestInterface) {
                $canvas = $iiif->getContainedResourceById($canvasId);
                /* @var $canvas \Ubl\Iiif\Presentation\Common\Model\Resources\CanvasInterface */
                if ($canvas != null && !empty($canvas->getPossibleTextAnnotationContainers(Motivation::PAINTING))) {
                    $annotationContainers = [];
                    /*
                     *  TODO Analyzing the annotations on the server side requires loading the annotation lists / pages
                     *  just to determine whether they contain text annotations for painting. This will take time and lead to a bad user experience.
                     *  It would be better to link every annotation and analyze the data on the client side.
                     *
                     *  On the other hand, server connections are potentially better than client connections. Downloading annotation lists
                     */
                    foreach ($canvas->getPossibleTextAnnotationContainers(Motivation::PAINTING) as $annotationContainer) {
                        if (($textAnnotations = $annotationContainer->getTextAnnotations(Motivation::PAINTING)) != null) {
                            foreach ($textAnnotations as $annotation) {
                                if (
                                    $annotation->getBody()->getFormat() == 'text/plain'
                                    && $annotation->getBody()->getChars() != null
                                ) {
                                    $annotationListData = [];
                                    $annotationListData['uri'] = $annotationContainer->getId();
                                    $annotationListData['label'] = $annotationContainer->getLabelForDisplay();
                                    $annotationContainers[] = $annotationListData;
                                    break;
                                }
                            }
                        }
                    }
                    $result = [
                        'canvas' => [
                            'id' => $canvas->getId(),
                            'width' => $canvas->getWidth(),
                            'height' => $canvas->getHeight(),
                        ],
                        'annotationContainers' => $annotationContainers
                    ];
                    return $result;
                }
            }
        }
        return [];
    }

    /**
     * Get image's URL and MIME type
     *
     * @access protected
     *
     * @param int $page: Page number
     *
     * @return array URL and MIME type of image file
     */
    protected function getImage($page)
    {
        $image = [];
        // Get @USE value of METS fileGrp.
        $fileGrpsImages = GeneralUtility::trimExplode(',', $this->extConf['fileGrpImages']);
        while ($fileGrpImages = array_pop($fileGrpsImages)) {
            // Get image link.
            if (!empty($this->document->getDoc()->physicalStructureInfo[$this->document->getDoc()->physicalStructure[$page]]['files'][$fileGrpImages])) {
                $image['url'] = $this->document->getDoc()->getFileLocation($this->document->getDoc()->physicalStructureInfo[$this->document->getDoc()->physicalStructure[$page]]['files'][$fileGrpImages]);
                $image['mimetype'] = $this->document->getDoc()->getFileMimeType($this->document->getDoc()->physicalStructureInfo[$this->document->getDoc()->physicalStructure[$page]]['files'][$fileGrpImages]);

                // Only deliver static images via the internal PageViewProxy.
                // (For IIP and IIIF, the viewer needs to build and access a separate metadata URL, see `getMetdadataURL` in `OLSources.js`.)
                if ($this->settings['useInternalProxy'] && !str_contains(strtolower($image['mimetype']), 'application')) {
                    // Configure @action URL for form.
                    $uri = $this->uriBuilder->reset()
                        ->setTargetPageUid($GLOBALS['TSFE']->id)
                        ->setCreateAbsoluteUri(!empty($this->settings['forceAbsoluteUrl']) ? true : false)
                        ->setArguments([
                            'eID' => 'tx_dlf_pageview_proxy',
                            'url' => $image['url'],
                            'uHash' => GeneralUtility::hmac($image['url'], 'PageViewProxy')
                            ])
                        ->build();
                    $image['url'] = $uri;
                }
                break;
            } else {
                $this->logger->notice('No image file found for page "' . $page . '" in fileGrp "' . $fileGrpImages . '"');
            }
        }
        if (empty($image)) {
            $this->logger->warning('No image file found for page "' . $page . '" in fileGrps "' . $this->extConf['fileGrpImages'] . '"');
        }
        return $image;
    }

    /**
     * Parses the json with all active OCR Engines. The parsed engines are stored in the cookie `tx-dlf-ocrEngines`.
     * 
     * The `ocrEngines.json` has following scheme:
     * {
     * "ocrEngines": [
     *   {
     *       "name": "Tesseract",
     *       "de": "Tesseract",
     *       "en": "Tesseract",
     *       "class": "tesseract",
     *       "data": "tesseract-basic"
     *   }, ....
     *
     * @access protected
     *
     * @param string $ocrEnginesPath: Path to the JSON containing all active OCR engines. Can ignored if the file is already in memory.
     * 
     * @return void
     */
    protected function parseOCRengines(string $ocrEnginesPath = null):void {
        if ($ocrEnginesPath != null) { // no need to reload the file if it's already in memory
            self::$ocrEngines = file_get_contents($ocrEnginesPath);
        }

        $availEngines = $this->checkFulltextAvailability((int) $this->requestData['page']); // check availability of fulltexts for each engine
        $ocrEnginesJson = json_decode(self::$ocrEngines, true);

        // Add availability to json:
        foreach ($ocrEnginesJson['ocrEngines'] as &$engine) {
            if (in_array($engine['data'], $availEngines)) {
                $engine['avail'] = 'Y'; // fulltext is available
            }
        }

        self::$ocrEngines = json_encode($ocrEnginesJson);
        setcookie('tx-dlf-ocrEngines', self::$ocrEngines, ['SameSite' => 'lax']);
    }

    /**
     * Checks if the fulltext is locally available for all active OCR engines.
     * 
     * @access protected
     * 
     * @param int $page: Page number
     * 
     * @return array An array containing the names of all OCR engines that have a local fulltext available
     * 
     */
    protected function checkFulltextAvailability(int $page):array {
        $ocrEnginesArray = json_decode(self::$ocrEngines, true)['ocrEngines'];
        $resArray = array();

        $path = FullTextGenerator::getDocLocalPath(Doc::$extKey, $this->document);
        $topLevelId = $this->document->getDoc()->toplevelId; // (eg. "log59088")

        //check if path exists:
        for($i=0; $i<count($ocrEnginesArray); $i++){
            $data = $ocrEnginesArray[$i]['data'];
            if(isset($data) && !empty($data) && file_exists($path.'/'.$data.'/'.$topLevelId.'_'.$page.'.xml')) {
                array_push($resArray, $data);
            }
        }
        return $resArray;
    }

    /**
     * Checks and returns the OCR-Engine
     * 
     * @access public
     * 
     * @param string $extKey
     *
     * @return string The selected OCR engine
     */
    public static function getOCRengine(string $extKey):string {
        $conf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($extKey);

        if(!is_null($_COOKIE['tx-dlf-ocrEngine']) && (str_contains(self::$ocrEngines, $ocrEngine=$_COOKIE['tx-dlf-ocrEngine']) || $ocrEngine == "originalremote")){
            return $ocrEngine;
        } else {
            return $conf['ocrEngine'] ; //get default default value
        }
    }

    /**
     * Generates page or book fulltexts via FullTextGenerator.php
     * 
     * @access protected
     * 
     * @return void
     */
    protected function generateFullText():void {
        if(($engine = PageViewController::getOCRengine(Doc::$extKey)) == "originalremote") {
            return;
        }

        // OCR all pages: (type=book)
        if($_POST["request"]["type"] == "book") {
            //collect all image urls:
            $images = array();
            for ($i=1; $i <= $this->document->getDoc()->numPages; $i++) {
                $images[$i] = $this->getImage($i)["url"];
            }
            FullTextGenerator::createBookFullText(Doc::$extKey, $this->document, $images, $engine);
            return;
        }

        // OCR only this page:
        FullTextGenerator::createPageFullText(Doc::$extKey, $this->document, $this->getImage($this->requestData['page'])["url"], $this->requestData['page'], $engine);
    }

    /**
     * Returns the server URL (including networkprotocol: http or https)
     * eg. https://www.example.com
     * 
     * @access public
     * 
     * @return string The server URL
     */
    public static function getServerUrl():string {
        //check server protocol (parts from https://stackoverflow.com/a/14270161):
        if (GeneralUtility::getIndpEnv('TYPO3_SSL') == true
            || isset($_SERVER['HTTPS'])  &&  ($_SERVER['HTTPS'] == 'on'  ||  $_SERVER['HTTPS'] == 1)
            || isset($_SERVER['HTTP_X_FORWARDED_PROTO'])  &&  $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
            return 'https://' . $_SERVER['HTTP_HOST'];
        } else {
            return 'http://'. $_SERVER['HTTP_HOST'];
        }
    }

    /**
     * This function is a workaround to circumvent TYPO3s disturbing caching.
     * It clears the stored page cache (for presentations viewer only!) on calling.
     * 
     * @access protected
     * 
     * @return void
     */
    protected function clearPageCache():void{
        $objectManager = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Object\ObjectManager::class);
        $objectManager->get(\TYPO3\CMS\Extbase\Service\CacheService::class)->clearPageCache($GLOBALS['TSFE']->id);
    }
}
