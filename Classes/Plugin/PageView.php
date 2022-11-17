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

namespace Kitodo\Dlf\Plugin;

use Kitodo\Dlf\Common\Helper;
use Kitodo\Dlf\Common\IiifManifest;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use Ubl\Iiif\Presentation\Common\Model\Resources\ManifestInterface;
use Ubl\Iiif\Presentation\Common\Vocabulary\Motivation;
use Kitodo\Dlf\Plugin\FullTextGenerator;

/**
 * Plugin 'Page View' for the 'dlf' extension
 *
 * @author Sebastian Meyer <sebastian.meyer@slub-dresden.de>
 * @author Christos Sidiropoulos <christos.sidiropoulos@uni-mannheim.de>
 * 
 * @package TYPO3
 * @subpackage dlf
 * @access public
 */
class PageView extends \Kitodo\Dlf\Common\AbstractPlugin
{
    public $scriptRelPath = 'Classes/Plugin/PageView.php';

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
     * @var array
     * @access protected
     */
    protected static $ocrEngines = [];

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
        $viewerConfiguration = '
            $(document).ready(function() {
                if (dlfUtils.exists(dlfViewer)) {
                    tx_dlf_viewer = new dlfViewer({
                        controls: ["' . implode('", "', $this->controls) . '"],
                        div: "' . $this->conf['elementId'] . '",
                        images: ' . json_encode($this->images) . ',
                        fulltexts: ' . json_encode($this->fulltexts) . ',
                        annotationContainers: ' . json_encode($this->annotationContainers) . ',
                        useInternalProxy: ' . ($this->conf['useInternalProxy'] ? 1 : 0) . '
                    });
                }
            });
        ';

        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addJsFooterInlineCode('kitodo-pageview-configuration', $viewerConfiguration);
    }

    /**
     * Adds pageview interaction (crop, magnifier and rotation)
     *
     * @access protected
     *
     * @return array Marker array
     */
    protected function addInteraction()
    {
        $markerArray = [];
        if ($this->piVars['id']) {
            if ($this->conf['crop']) {
                $markerArray['###EDITBUTTON###'] = '<a href="javascript: tx_dlf_viewer.activateSelection();" title="' . htmlspecialchars($this->pi_getLL('editMode', '')) . '">' . htmlspecialchars($this->pi_getLL('editMode', '')) . '</a>';
                $markerArray['###EDITREMOVE###'] = '<a href="javascript: tx_dlf_viewer.resetCropSelection();" title="' . htmlspecialchars($this->pi_getLL('editRemove', '')) . '">' . htmlspecialchars($this->pi_getLL('editRemove', '')) . '</a>';
            } else {
                $markerArray['###EDITBUTTON###'] = '';
                $markerArray['###EDITREMOVE###'] = '';
            }
            if ($this->conf['magnifier']) {
                $markerArray['###MAGNIFIER###'] = '<a href="javascript: tx_dlf_viewer.activateMagnifier();" title="' . htmlspecialchars($this->pi_getLL('magnifier', '')) . '">' . htmlspecialchars($this->pi_getLL('magnifier', '')) . '</a>';
            } else {
                $markerArray['###MAGNIFIER###'] = '';
            }
        }
        return $markerArray;
    }

    /**
     * Adds form to save cropping data to basket
     *
     * @access protected
     *
     * @return array Marker array
     */
    protected function addBasketForm()
    {
        $markerArray = [];
        // Add basket button
        if ($this->conf['basketButton'] && $this->conf['targetBasket'] && $this->piVars['id']) {
            $label = htmlspecialchars($this->pi_getLL('addBasket', ''));
            $params = [
                'id' => $this->piVars['id'],
                'addToBasket' => true
            ];
            if (empty($this->piVars['page'])) {
                $params['page'] = 1;
            }
            $basketConf = [
                'parameter' => $this->conf['targetBasket'],
                'forceAbsoluteUrl' => !empty($this->conf['forceAbsoluteUrl']) ? 1 : 0,
                'forceAbsoluteUrl.' => ['scheme' => !empty($this->conf['forceAbsoluteUrl']) && !empty($this->conf['forceAbsoluteUrlHttps']) ? 'https' : 'http'],
                'additionalParams' => GeneralUtility::implodeArrayForUrl($this->prefixId, $params, '', true, false),
                'title' => $label
            ];
            $output = '<form id="addToBasketForm" action="' . $this->cObj->typoLink_URL($basketConf) . '" method="post">';
            $output .= '<input type="hidden" name="tx_dlf[startpage]" id="startpage" value="' . htmlspecialchars($this->piVars['page']) . '">';
            $output .= '<input type="hidden" name="tx_dlf[endpage]" id="endpage" value="' . htmlspecialchars($this->piVars['page']) . '">';
            $output .= '<input type="hidden" name="tx_dlf[startX]" id="startX">';
            $output .= '<input type="hidden" name="tx_dlf[startY]" id="startY">';
            $output .= '<input type="hidden" name="tx_dlf[endX]" id="endX">';
            $output .= '<input type="hidden" name="tx_dlf[endY]" id="endY">';
            $output .= '<input type="hidden" name="tx_dlf[rotation]" id="rotation">';
            $output .= '<button id="submitBasketForm" onclick="this.form.submit()">' . $label . '</button>';
            $output .= '</form>';
            $output .= '<script>';
            $output .= '$(document).ready(function() { $("#submitBasketForm").click(function() { $("#addToBasketForm").submit(); }); });';
            $output .= '</script>';
            $markerArray['###BASKETBUTTON###'] = $output;
        } else {
            $markerArray['###BASKETBUTTON###'] = '';
        }
        return $markerArray;
    }

    /**
     * Get image's URL and MIME type
     *
     * @access protected
     *
     * @param int $page: Page number
     * @param int $mode: modes: 0 for UI (default), 1 for OCR processing
     *
     * @return array URL and MIME type of image file
     */
    protected function getImage(int $page, int $mode=0) : array
    {
        $image = [];
        // Get @USE value of METS fileGrp.
        $fileGrpsImages = GeneralUtility::trimExplode(',', $this->conf['fileGrpImages']);

        $count = count($fileGrpsImages);
        // set loop variables:
        if($mode==0){ //default mode: get the lowest image for UI
            $i=0;       // loop variable: start
            $j=$count;  // loop variable: end
            $x=1;       // loop variable: increment
        } else { //OCR mode: get the highest image for OCR processing
            $i=$count-1;
            $j=-1; //-1 because we want to include the last element (see loop condition !=)
            $x=-1;
        }

        for ($i; $i!=$j; $i+=$x){
            $fileGrpImages = $fileGrpsImages[$i];
            // Get image link.
            if (!empty($this->doc->physicalStructureInfo[$this->doc->physicalStructure[$page]]['files'][$fileGrpImages])) {
                $image['url'] = $this->doc->getFileLocation($this->doc->physicalStructureInfo[$this->doc->physicalStructure[$page]]['files'][$fileGrpImages]);
                if ($this->conf['useInternalProxy']) {
                    // Configure @action URL for form.
                    $linkConf = [
                        'parameter' => $GLOBALS['TSFE']->id,
                        'forceAbsoluteUrl' => !empty($this->conf['forceAbsoluteUrl']) ? 1 : 0,
                        'forceAbsoluteUrl.' => ['scheme' => !empty($this->conf['forceAbsoluteUrl']) && !empty($this->conf['forceAbsoluteUrlHttps']) ? 'https' : 'http'],
                        'additionalParams' => '&eID=tx_dlf_pageview_proxy&url=' . urlencode($image['url']) . '&uHash=' . GeneralUtility::hmac($image['url'], 'PageViewProxy'),
                    ];
                    $image['url'] = $this->cObj->typoLink_URL($linkConf);
                }
                $image['mimetype'] = $this->doc->getFileMimeType($this->doc->physicalStructureInfo[$this->doc->physicalStructure[$page]]['files'][$fileGrpImages]);
                break;
            } else {
                $this->logger->notice('No image file found for page "' . $page . '" in fileGrp "' . $fileGrpImages . '"');
            }
        }
        if (empty($image)) {
            $this->logger->warning('No image file found for page "' . $page . '" in fileGrps "' . $this->conf['fileGrpImages'] . '"');
        }
        return $image;
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
        /**DEBUG**/ if($this->conf['ocrDebug']) echo '<script>alert("PageView.getFulltext ")</script>'; //DEBUG
        $fulltext = [];
        $this->logger = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)->getLogger(__CLASS__);

        // Get fulltext link:
        $fileGrpsFulltext = GeneralUtility::trimExplode(',', $this->conf['fileGrpFulltext']);
        while ($fileGrpFulltext = array_shift($fileGrpsFulltext)) {
            //check if fulltext is remote present:
            if (!empty($this->doc->physicalStructureInfo[$this->doc->physicalStructure[$page]]['files'][$fileGrpFulltext])) {
                $fulltext['url'] = $this->doc->getFileLocation($this->doc->physicalStructureInfo[$this->doc->physicalStructure[$page]]['files'][$fileGrpFulltext]);
                /**DEBUG**/ if($this->conf['ocrDebug']) echo '<script>alert("PageView.getFulltext: present: "'.$fulltext['url'].')</script>'; //DEBUG
                if ($this->conf['useInternalProxy']) {
                    // Configure @action URL for form.
                    $linkConf = [
                        'parameter' => $GLOBALS['TSFE']->id,
                        'forceAbsoluteUrl' => !empty($this->conf['forceAbsoluteUrl']) ? 1 : 0,
                        'forceAbsoluteUrl.' => ['scheme' => !empty($this->conf['forceAbsoluteUrl']) && !empty($this->conf['forceAbsoluteUrlHttps']) ? 'https' : 'http'],
                        'additionalParams' => '&eID=tx_dlf_pageview_proxy&url=' . urlencode($fulltext['url']) . '&uHash=' . GeneralUtility::hmac($fulltext['url'], 'PageViewProxy'),
                    ];
                    $fulltext['url'] = $this->cObj->typoLink_URL($linkConf);
                }
                $fulltext['mimetype'] = $this->doc->getFileMimeType($this->doc->physicalStructureInfo[$this->doc->physicalStructure[$page]]['files'][$fileGrpFulltext]);
                break;
            //check if fulltext was already ocred on demand (and is locally present):
            } else if (FullTextGenerator::checkLocal($this->extKey, $this->doc, $this->piVars['page'])) {
                $fulltext['url'] = "http://" . $_SERVER['HTTP_HOST'] . "/" . FullTextGenerator::getPageLocalPath($this->extKey, $this->doc, $this->piVars['page']);
                /**DEBUG**/ if($this->conf['ocrDebug']) echo '<script>alert("PageView.getFulltext: checkLocal true: ' .  $fulltext['url'] . '")</script>'; //DEBUG
            //no fulltext:
            } else {
                /**DEBUG**/ if($this->conf['ocrDebug']) echo '<script>alert("PageView.getFulltext: checkLocal false ")</script>'; //DEBUG
                $this->logger->notice('No full-text file found for page "' . $page . '" in fileGrp "' . $fileGrpFulltext . '"');
            }
        }
        if (empty($fulltext)) {
            /**DEBUG**/ if($this->conf['ocrDebug']) echo '<script>alert("PageView.getFulltext: fulltext var is emty ")</script>'; //DEBUG
            $this->logger->notice('No full-text file found for page "' . $page . '" in fileGrps "' . $this->conf['fileGrpFulltext'] . '"');
        }
        
        return $fulltext;
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
        if ($this->doc instanceof IiifManifest) {
            $canvasId = $this->doc->physicalStructure[$page];
            $iiif = $this->doc->getIiif();
            if ($iiif instanceof ManifestInterface) {
                $canvas = $iiif->getContainedResourceById($canvasId);
                /* @var $canvas \Ubl\Iiif\Presentation\Common\Model\Resources\CanvasInterface */
                if ($canvas != null && !empty($canvas->getPossibleTextAnnotationContainers(Motivation::PAINTING))) {
                    $annotationContainers = [];
                    /*
                     *  TODO Analyzing the annotations on the server side requires loading the annotation lists / pages
                     *  just to determine wether they contain text annotations for painting. This will take time and lead to a bad user experience.
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
     * Generates page or book fulltexts via FullTextGenerator.php
     * 
     * @access protected
     * 
     * @return void
     */
    protected function generateFullText():void {
        FullTextGenerator::createPageFullText($this->extKey, $this->doc, $this->getImage($this->piVars['page'], 1)["url"], $this->piVars['page'], self::getOCRengine($this->extKey));
        if($_POST["request"]["type"] == "book") {
            //collect all images urls:
            $images = array();
            for ($i=1; $i <= $this->doc->numPages; $i++) {
                $images[$i] = $this->getImage($i, 1)["url"];
            }
            FullTextGenerator::createBookFullText($this->extKey, $this->doc, $images);
        }
    }

  /**
   * Checks and returns the OCR-Engine
   * 
   * @access public
   * 
   * @param string $extKey
   *
   * @return string
   */
  public static function getOCRengine(string $extKey):string {
    $conf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($extKey);
    $ocrEngine = '';

    if(!is_null($_COOKIE['tx-dlf-ocrEngine']) && str_contains(self::$ocrEngines, $_COOKIE['tx-dlf-ocrEngine'])){
        $ocrEngine = $_COOKIE['tx-dlf-ocrEngine'];
    } else { 
        $GLOBALS['BE_USER']->simplelog("OCR Engine wrong: ".$_COOKIE["tx-dlf-ocrEngine"], "dlf", 2); //write log
        $ocrEngine = "default" .$conf['ocrEngine'] ; //get default default value
    }

    return $ocrEngine;
  }

    /**
     * Parses the json with all active OCR Engines.
     *
     * @access protected
     *
     * @param string $ocrEnginesPath: Path to the JSON containing all active OCR engines
     * @return void
     */
    protected function parseOCRengines(string $ocrEnginesPath):void{
        self::$ocrEngines = file_get_contents($ocrEnginesPath);
        setcookie('tx-dlf-ocrEngines', self::$ocrEngines, ['SameSite' => 'lax']);
    }

    /**
     * The main method of the PlugIn
     *
     * @access public
     *
     * @param string $content: The PlugIn content
     * @param array $conf: The PlugIn configuration
     *
     * @return string The content that is displayed on the website
     */
    public function main($content, $conf)
    {
        $this->init($conf);
        $this->loadDocument(); // Load current document
        $this->parseOCRengines(GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('dlf')['ocrEngines']);
        //Proccess request: Do OCR on given image(s):
        if ($_POST["request"]) {
            $this->generateFullText();
        }

        if ($this->doc === null || $this->doc->numPages < 1) {
            // Quit without doing anything if required variables are not set.
            return $content;
        } else {
            if (!empty($this->piVars['logicalPage'])) {
                $this->piVars['page'] = $this->doc->getPhysicalPage($this->piVars['logicalPage']);
                // The logical page parameter should not appear again
                unset($this->piVars['logicalPage']);
            }
            // Set default values if not set.
            // $this->piVars['page'] may be integer or string (physical structure @ID)
            if ((int) $this->piVars['page'] > 0 || empty($this->piVars['page'])) {
                $this->piVars['page'] = MathUtility::forceIntegerInRange((int) $this->piVars['page'], 1, $this->doc->numPages, 1);
            } else {
                $this->piVars['page'] = array_search($this->piVars['page'], $this->doc->physicalStructure);
            }
            $this->piVars['double'] = MathUtility::forceIntegerInRange($this->piVars['double'], 0, 1, 0);
        }
        // Load template file.
        $this->getTemplate();
        // Get image data.
        $this->images[0] = $this->getImage($this->piVars['page']);
        $this->fulltexts[0] = $this->getFulltext($this->piVars['page']);
        $this->annotationContainers[0] = $this->getAnnotationContainers($this->piVars['page']);
        if ($this->piVars['double'] && $this->piVars['page'] < $this->doc->numPages) {
            $this->images[1] = $this->getImage($this->piVars['page'] + 1);
            $this->fulltexts[1] = $this->getFulltext($this->piVars['page'] + 1);
            $this->annotationContainers[1] = $this->getAnnotationContainers($this->piVars['page'] + 1);
        }
        // Get the controls for the map.
        $this->controls = explode(',', $this->conf['features']);
        // Fill in the template markers.
        $markerArray = array_merge($this->addInteraction(), $this->addBasketForm());
        $this->addViewerJS();
        $content .= $this->templateService->substituteMarkerArray($this->template, $markerArray);
        return $this->pi_wrapInBaseClass($content);
    }
}
