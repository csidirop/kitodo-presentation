<?php

namespace Kitodo\Dlf\Plugin;

use Kitodo\Dlf\Common\Doc;
use Kitodo\Dlf\Domain\Model\Document;
use Kitodo\Dlf\Plugin\FullTextXMLtools;
use Kitodo\Dlf\Controller\PageViewController;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Plugin 'FullText Generator' for the 'dlf' extension
 * Generates fulltext ALTO files from METS XML files and writes them to localy beside the updated METS XML file.
 *
 * @author Christos Sidiropoulos <christos.sidiropoulos@uni-mannheim.de>
 * @package TYPO3
 * @subpackage dlf
 * @access public
 */
class FullTextGenerator {
  protected $conf = [];

  /**
   * Returns local id of doc.
   * (eg. "log59088")
   * 
   * @access protected
   *
   * @param Doc doc
   *
   * @return string
   */
  protected static function getDocLocalId(Doc $doc):string {
    return $doc->toplevelId;
  }

  /**
   * Returns local id of page.
   * (eg. "log59088_1")
   * 
   * @access protected
   *
   * @param Doc doc
   * @param int pageNum
   *
   * @return string
   */
  protected static function getPageLocalId(Doc $doc, int $pageNum):string {
    return self::getDocLocalId($doc) . "_" . $pageNum;
  }

  /**
   * Returns a document specific local path where the fulltexts are stored.
   * The Path is generated from the document's URN if present, otherwise from the document's UID hash
   * This creates unique paths for each document.
   * (eg. "fileadmin/fulltextfolder/URN/nbn/de/bsz/180/digosi/30")
   * 
   * @access protected
   *
   * @param string extKey
   * @param Doc doc
   *
   * @return string path to documents specific fulltext folder
   */
  protected static function getDocLocalPath(string $extKey, Doc $doc):string {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($extKey);
    
    return $conf['fulltextFolder'] . "/" . self::generateUniqueDocLocalPath($doc);
  }

  /**
   * Returns a unique document specific local path depending on the its urn.
   * eg.: urn:nbn:de:bsz:180-digosi-30 -> URN/nbn/de/bsz/180/digosi/30
   * 
   * @access protected
   * 
   * @param Doc doc
   * 
   * @return string path
   */
  protected static function generateUniqueDocLocalPath(Doc $doc):string {
    $urn = FullTextXMLtools::getDocURN($doc);
    if($urn){ //$urn is present
      $uniquePath = "/" . str_replace("urn/","URN/", str_replace("-", "/", str_replace(":", "/", $urn))); // -> URN/nbn/de/bsz/180/digosi/30
    } else { //no urn was present
      // $outputFolderPath = $conf['fulltextFolder'] . "/noURN/" . sha1($doc->uid); // -> URN/ff0fdd600d8b46542ebe329c00a397841b71e757 //TODO: remove if doc doesn't get the URL back
      $uniquePath = "/noURN/" . sha1($GLOBALS["_GET"]["tx_dlf"]["id"]); // -> URN/ff0fdd600d8b46542ebe329c00a397841b71e757
    }
    return $uniquePath;
  }

  /**
   * Returns local path to the doc's page (uses getDocLocalPath)
   * 
   * @access public
   *
   * @param string extKey
   * @param Doc doc
   * @param int pageNum
   *
   * @return string
   */
  public static function getPageLocalPath(string $extKey, Doc $doc, int $pageNum):string {
    $outputFolderPath = self::getDocLocalPath($extKey, $doc);
    $ocrEngine = PageViewController::getOCRengine($extKey);
    $pageId = self::getPageLocalId($doc, $pageNum);
    return "$outputFolderPath/$ocrEngine/$pageId.xml";
  }

  /**
   * Checks whether local fulltext is present
   * 
   * @access public
   *
   * @param string extKey
   * @param Doc doc
   * @param int pageNum
   *
   * @return bool
   */
  public static function checkLocal(string $extKey, Doc $doc, int $pageNum):bool {
    return file_exists(self::getPageLocalPath($extKey, $doc, $pageNum));
  }

  /**
   * Checks whether fulltext file is in progress (temporary file is present)
   * 
   * @access public
   *
   * @param string extKey
   * @param Doc doc
   * @param int pageNum
   *
   * @return bool
   */
  public static function checkInProgress(string $extKey, Doc $doc, int $pageNum):bool {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($extKey);
    return file_exists($conf['fulltextTempFolder'] . '/' . self::getPageLocalId($doc, $pageNum) . ".xml");
  }

  /**
   * Create fulltext for all pages in document
   * 
   * @access public
   *
   * @param string extKey
   * @param Doc doc
   * @param array images_urls
   * @param string $ocrEngine
   *
   * @return void
   */
  public static function createBookFullText(string $extKey, Document $document, array $imageUrls, string $ocrEngine):void {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($extKey);
    $doc = $document->getDoc();

    for ($pageNum=1; $pageNum <= $doc->numPages; $pageNum++) {
      if (!(self::checkLocal($extKey, $doc, $pageNum) || self::checkInProgress($extKey, $doc, $pageNum))) {
	      self::generatePageOCR($extKey, $conf, $document, $imageUrls[$pageNum], $pageNum, $conf['ocrDelay'], $ocrEngine);
      }
    }
  }

  /**
   * Create fulltext for given page from document
   * 
   * @access protected
   *
   * @param string extKey
   * @param Document document
   * @param string imageUrl
   * @param int pageNum
   * @param string $ocrEngine
   *
   * @return bool
   */
  public static function createPageFullText(string $extKey, Document $document, string $imageUrl, int $pageNum, string $ocrEngine):void {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($extKey);
    $doc = $document->getDoc();

    if (!(self::checkLocal($extKey, $doc, $pageNum) || self::checkInProgress($extKey, $doc, $pageNum))) {
      self::generatePageOCR($extKey, $conf, $document, $imageUrl, $pageNum, $conf['ocrDelay'], $ocrEngine);
    }
  }

  /**
   * Main method for creating OCR full texts of a particular page of a document.
   * It builds and executes the command for the specifiend OCR engine script.
   * 
   * @access protected
   *
   * @param string extKey
   * @param array conf
   * @param Document document
   * @param string imageUrl
   * @param int pageNum 
   * @param int sleepInterval
   * @param string $ocrEngine
   *
   * @return void
   */
  protected static function generatePageOCR(string $extKey, array $conf, Document $document, string $imageUrl, int $pageNum, int $sleepInterval = 0, string $ocrEngine):void {
    //Working dir is "/var/www/typo3/public"; //same as "/var/www/html" because sym link

    //Parse parameter and setup variables:
    $doc              = $document->getDoc();
    $ocrEngineFolder  = "typo3conf/ext/dlf/Classes/Plugin/Tools/FullTextGenerationScripts";
    $ocrEnginePath    = "$ocrEngineFolder/$ocrEngine.sh";             //Path to OCR-Engine/Script
    $pageId           = self::getPageLocalId($doc, $pageNum);         //Page ID (eg. log59088_1)
    $documentPath     = self::getDocLocalPath($extKey, $doc);         //Document specific path (eg. fileadmin/fulltextFolder/URN/nbn/de/bsz/180/digosi/30/)
    $outputFolderPath = "$documentPath/$ocrEngine";                   //Fulltextfolder (eg. fileadmin/fulltextFolder/URN/nbn/de/bsz/180/digosi/30/tesseract-basic/)
    $origMetsPath     = $documentPath."/".self::getDocLocalId($doc).".xml"; //Path to original METS (eg. fileadmin/fulltextFolder/URN/nbn/de/bsz/180/digosi/30/log59088.xml)
    $newMetsPath      = $outputFolderPath."/".self::getDocLocalId($doc).".xml"; //Path to updated METS
    $outputPath       = "$outputFolderPath/$pageId.xml";              //Fulltextfile path
    $tmpOutputFolderPath = $conf['fulltextTempFolder'] . self::generateUniqueDocLocalPath($doc) . "/$ocrEngine"; //(eg. fileadmin/_temp_/ocrTempFolder/fulltext/URN/nbn/de/bsz/180/digosi/30/tesseract-basic)
    $tmpImagePath     = $conf['fulltextImagesFolder'] . self::generateUniqueDocLocalPath($doc) . "/$pageId"; //Imagefile path (eg. fileadmin/_temp_/ocrTempFolder/images/URN/nbn/de/bsz/180/digosi/30/log59088_1)
    $tmpOutputPath    = $tmpOutputFolderPath . "/$pageId";            //Fulltextfile temporary path (eg. fileadmin/_temp_/ocrTempFolder/fulltext/URN/nbn/de/bsz/180/digosi/30/tesseract-basic/log59088_295)
    $lockFolder       = $conf['fulltextLockFolder'] . "/";            //Folder used to store locks
    $lockFile         = $lockFolder . hash("md5", $imageUrl);         //File used to lock OCR command
    $imageDownloadCommand =":";                                       //non empty command without effect //TODO: find better solution
    $ocrShellCommand = "";

    // Create folders and write original METS if not present:
    if (!file_exists($tmpOutputFolderPath)){ mkdir($tmpOutputFolderPath, 0777, true); } //Create documents temporary path if not present
    if (!file_exists($outputFolderPath)){ mkdir($outputFolderPath, 0777, true); }       //Create documents path if not present
    FullTextXMLtools::writeMetsXML($document, $origMetsPath);                           //Write original METS XML file
    FullTextXMLtools::writeMetsXML($document, $newMetsPath);                            //Write new METS XML file

    // Locking command, so that only a limited number of an OCR-Engines can run at the same time
    if ($conf['ocrLock']) { //hold only when wanted //TODO: check what downsides not waiting can have
      if (!file_exists($lockFile)) { // If no lock on image url, go on
        while(count(scandir($lockFolder))-2 > (int) $conf['ocrThreads']) { //wait as long as there more locks written as set in options
          session_write_close(); //close session to allow other accesses (otherwise no new site can be loaded as long as the lock is active)
          sleep(1);
          session_start();
        }
        fopen($lockFile, "w") ; //write lock
      } else { //there is already OCR running for this image, so return -> this will show the gen placeholder fulltext till the OCR is completed
        return;
      }
    }

    //Build OCR script command:
    //Determine if the image should be downloaded. Than use remote URL ($imageUrl) or local PATH ($tmpImagePath):
    if ($conf['ocrDwnlTempImage']){ //download image
      $imageDownloadCommand = "wget $imageUrl -O $tmpImagePath"; //wget image and save to $tmpImagePath
      $ocrShellCommand .= self::genShellCommand($ocrEnginePath, $tmpImagePath, $tmpOutputPath, $outputPath, $tmpImagePath, $pageId, $conf['ocrPlaceholderText'], $document->getLocation());
      $ocrShellCommand .= " && rm $tmpImagePath";  // Remove used image
    } else { //do not download image, pass URL to the engine
      $ocrShellCommand .= self::genShellCommand($ocrEnginePath, $imageUrl, $tmpOutputPath, $outputPath, $tmpImagePath, $pageId, $conf['ocrPlaceholderText'], "http://".$_SERVER['HTTP_HOST']."/".$outputPath);
    }

    /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("'.$ocrShellCommand.'")</script>'; //DEBUG

    //Execute shell commands:
    exec("$imageDownloadCommand && sleep $sleepInterval && $ocrShellCommand", $output, $retval);

    //Errorhandling, when OCR script failed:
    if($retval!=0){ //if exitcode != 0 -> script not successful
      //!. write to log:
      $errorMsg = "OCR script failed with status: $retval | Errormessage: " . implode(" ",$output);
      $errorMsg .= " | On $ocrEngine, with image: $imageUrl and page: $pageNum";
      //$GLOBALS['BE_USER']->writelog(4, 0, 2, 0, "$errorMsg", null); //write error to log
      //Errorflags: 0 = message, 1 = error (user problem), 2 = System Error (which should not happen), 3 = security notice (admin)
      
      //2. Give feedback to user:
      echo '<script>alert("There was an error with your OCR job. Try again later or with an other OCR engine.")</script>';
      /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("'.$errorMsg.'")</script>'; //DEBUG
      
      //3. remove placeholder:
      if ($conf['ocrPlaceholder']) {
        unlink($outputPath);
      }

      //4. Reload page: (without action query part)
      $url="/viewer?tx_dlf[id]=".$GLOBALS["_GET"]['tx_dlf']["id"]."&tx_dlf[page]=".$GLOBALS["_GET"]['tx_dlf']["page"]."&no_cache=1";
      header("Refresh:0; url=$url");
    }

    //Remove lock:
    if ($conf['ocrLock']) {
      unlink($lockFile);
    }

    //Write/update updated METS XML file:
    // if (file_exists($newMetsPath)){ // there is already an updated METS
    //   FullTextXMLtools::updateMetsXML($newMetsPath, $outputPath, $newMetsPath, $ocrEngine);
    // } else { // there is no updated METS
    //   FullTextXMLtools::updateMetsXML($origMetsPath, $outputPath, $newMetsPath, $ocrEngine);
    // }
  }

  /** 
   *  Returns the shell command nessesary to run the shell ORC script
   * 
   *  @access protected
   * 
   *  @param string ocrEnginePath
   *  @param string imagePath
   *  @param string outputPath
   *  @param string pageId
   * 
   *  @return string OCR-script shell command
   */
  protected static function genOCRshellCommand(string $ocrEnginePath, string $imagePath, string $tmpOutputPath, string $outputPath, string $tmpImagePath, string $pageId, string $url):string{
    return "./typo3conf/ext/dlf/Classes/Plugin/Tools/FullTextGenerationScripts/OCRmain.sh --ocrEngine $ocrEnginePath --imagePath $imagePath --tmpOutputPath $tmpOutputPath --outputPath $outputPath --pageId $pageId --tmpImagePath $tmpImagePath --url $url";
  }

  /**
   *  Genereates the complete OCR shell command
   * 
   *  @access protected
   * 
   *  @param string ocrPlaceholderText
   *  @param string ocrEnginePath
   *  @param string imagePath
   *  @param string tmpOutputPath
   *  @param string outputPath
   *  @param int pageId
   * 
   *  @return string Full OCR-script shell command
   */
  protected static function genShellCommand(string $ocrEnginePath, string $imagePath, string $tmpOutputPath, string $outputPath, string $tmpImagePath, string $pageId, string $ocrPlaceholderText, string $url):string{
    $ocrShellCommand = "";
    if ($ocrPlaceholderText) { //create first dummy xmls to prevent multiple tesseract jobs for the same page, then OCR
      FullTextXMLtools::createPlaceholderFulltext($outputPath, $ocrPlaceholderText);
      $ocrShellCommand = self::genOCRshellCommand($ocrEnginePath, $imagePath, $tmpOutputPath, $outputPath, $tmpImagePath, $pageId, $url);
      # tmpOutputPath is used to create a dummy xml file, which is later replaced by the real one by the OCR script
    } else { //do not create dummy xml, write direcly the final file
      $ocrShellCommand = self::genOCRshellCommand($ocrEnginePath, $imagePath, $outputPath, $outputPath, $tmpImagePath, $pageId, $url);
    }
    return $ocrShellCommand;
  }
}
?>
