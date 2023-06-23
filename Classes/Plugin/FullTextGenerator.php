<?php

namespace Kitodo\Dlf\Plugin;

use Kitodo\Dlf\Common\Doc;
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
  protected static function genDocLocalPath(string $extKey, Doc $doc):string {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($extKey);
    /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("FullTextGen.genDocLocalPath: '.$conf['fulltextFolder'].'")</script>'; //DEBUG

    $urn = FullTextXMLtools::getDocURN($doc); // eg.: urn:nbn:de:bsz:180-digosi-30
    if($urn){ //$urn is present
      $outputFolder_path = $conf['fulltextFolder'] . "/" . str_replace("urn/","URN/",str_replace("-", "/", str_replace(":", "/", $urn))); // -> URN/nbn/de/bsz/180/digosi/30
    } else { //no urn was present
      // $outputFolder_path = $conf['fulltextFolder'] . "/noURN/" . sha1($doc->uid); // -> URN/ff0fdd600d8b46542ebe329c00a397841b71e757 //TODO: remove if doc doesn't get the URL back
      $outputFolder_path = $conf['fulltextFolder'] . "/noURN/" . sha1($GLOBALS["_GET"]["tx_dlf"]["id"]); // -> URN/ff0fdd600d8b46542ebe329c00a397841b71e757
    }

    return $outputFolder_path;
  }

  /**
   * Returns local path to the doc's page (uses genDocLocalPath)
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
    $outputFolder_path = self::genDocLocalPath($extKey, $doc);
    $ocrEngine = PageViewController::getOCRengine($extKey);
    $pageId = self::getPageLocalId($doc, $pageNum);
    return "$outputFolder_path/$ocrEngine/$pageId.xml";
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
  public static function createBookFullText(string $extKey, Doc $doc, array $imageUrls, string $ocrEngine):void {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($extKey);

    for ($pageNum=1; $pageNum <= $doc->numPages; $pageNum++) {
      if (!(self::checkLocal($extKey, $doc, $pageNum) || self::checkInProgress($extKey, $doc, $pageNum))) {
	      self::generatePageOCR($extKey, $conf, $doc, $imageUrls[$pageNum], $pageNum, $conf['ocrDelay'], $ocrEngine);
      }
    }
  }

  /**
   * Create fulltext for given page from document
   * 
   * @access protected
   *
   * @param string extKey
   * @param Doc doc
   * @param string imageUrl
   * @param int pageNum
   * @param string $ocrEngine
   *
   * @return bool
   */
  public static function createPageFullText(string $extKey, Doc $doc, string $imageUrl, int $pageNum, string $ocrEngine):void {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($extKey);

    if (!(self::checkLocal($extKey, $doc, $pageNum) || self::checkInProgress($extKey, $doc, $pageNum))) {
      self::generatePageOCR($extKey, $conf, $doc, $imageUrl, $pageNum, $conf['ocrDelay'], $ocrEngine);
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
   * @param Doc doc
   * @param string imageUrl
   * @param int pageNum 
   * @param int sleepInterval
   * @param string $ocrEngine
   *
   * @return void
   */
  protected static function generatePageOCR(string $extKey, array $conf, Doc $doc, string $imageUrl, int $pageNum, int $sleepInterval = 0, string $ocrEngine):void {
    /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("FullTextGen.genPageOCR")</script>'; //DEBUG

    //Working dir is "/var/www/typo3/public"; //same as "/var/www/html" because sym link

    //Parse parameter and setup variables:
    $ocrEngineFolder  = "typo3conf/ext/dlf/Classes/Plugin/Tools/FullTextGenerationScripts";
    $ocrEngine_path   = "$ocrEngineFolder/$ocrEngine.sh";             //Path to OCR-Engine/Script
    $pageId           = self::getPageLocalId($doc, $pageNum);         //Page number (eg. log59088_1)
    $image_path       = $conf['fulltextImagesFolder'] . "/$pageId";   //Imagefile path (eg. fileadmin/fulltextimages/log59088_1)
    $document_path    = self::genDocLocalPath($extKey, $doc);         //Document specific path (eg. fileadmin/fulltextfolder/URN/nbn/de/bsz/180/digosi/30/)
    $outputFolder_path = "$document_path/$ocrEngine";                 //Fulltextfolder (eg. fileadmin/fulltextfolder/URN/nbn/de/bsz/180/digosi/30/tesseract-basic/)
    $origMets_path    = $document_path."/".self::getDocLocalId($doc).".xml"; //Path to original METS
    $newMets_path     = $outputFolder_path."/".self::getDocLocalId($doc).".xml"; //Path to updated METS
    if (!file_exists($outputFolder_path)){ mkdir($outputFolder_path, 0777, true); }  //Create documents path if not present
    FullTextXMLtools::writeMetsXML($doc, $origMets_path);             //Write original METS XML file
    $output_path      = "$outputFolder_path/$pageId.xml";             //Fulltextfile path
    $tempOutput_path  = $conf['fulltextTempFolder'] . "/$pageId";     //Fulltextfile TMP path
    $lockFolder       = $conf['fulltextLockFolder'] . "/";            //Folder used to store locks
    $lockFile         = $lockFolder . hash("md5", $imageUrl);         //File used to lock OCR command
    $imageDownloadCommand =":";                                       //non empty command without effect //TODO: find better solution
    $ocrShellCommand = "";

    // Locking command, so that only a limited number of an OCR-Engines can run at the same time
    if ($conf['ocrLock']) { //hold only when wanted //TODO: check what downsides not waiting can have
      if (!file_exists($lockFile)) { // If no lock on image url, go on
        fopen($lockFile, "w") ; //write lock
        while(count(scandir($lockFolder))-2 > (int) $conf['ocrThreads']) { //wait as long as there more locks written as set in options
          session_write_close(); //close session to allow other accesses (otherwise no new site can be loaded as long as the lock is active)
          sleep(1);
          session_start();
        }
      } else { //there is already OCR running for this image, so return -> this will show the gen placeholder fulltext till the OCR is completed
        return;
      }
    }

    //Build OCR script command:
    //Determine if the image should be downloaded. Than use remote URL ($imageUrl) or local PATH ($image_path):
    if ($conf['ocrDwnlTempImage']){ //download image
      $imageDownloadCommand = "wget $imageUrl -O $image_path"; //wget image and save to $image_path
      $ocrShellCommand .= self::genShellCommand($conf['ocrPlaceholderText'], $ocrEngine_path, $image_path, $tempOutput_path, $output_path, $pageId);
      $ocrShellCommand .= " && rm $image_path";  // Remove used image
    } else { //do not download image, pass URL to the engine
      $ocrShellCommand .= self::genShellCommand($conf['ocrPlaceholderText'], $ocrEngine_path, $imageUrl, $tempOutput_path, $output_path, $pageId);
    }

    /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("'.$ocrShellCommand.'")</script>'; //DEBUG

    //Execute shell commands:
    exec("$imageDownloadCommand && sleep $sleepInterval && $ocrShellCommand", $output, $retval);

    //Errorhandling, when OCR script failed:
    if($retval!=0){ //if exitcode != 0 -> script not successful
      //!. write to log:
      $errorMsg = "OCR script failed with status: \"$retval\" \n Error: \"" . implode(" ",$output) ."\"";
      $errorMsg .= "\nOn \"$ocrEngine\", with image: \"$imageUrl\" and page: $pageNum";
      //$GLOBALS['BE_USER']->writelog(4, 0, 2, 0, "$errorMsg", null); //write error to log
      //Errorflags: 0 = message, 1 = error (user problem), 2 = System Error (which should not happen), 3 = security notice (admin)
      
      //2. Give feedback to user:
      echo '<script>alert("There was an error with your OCR job. Try again later or with an other OCR engine.")</script>';
      
      //3. remove placeholder:
      if ($conf['ocrPlaceholder']) {
        unlink($output_path);
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
    if (file_exists($newMets_path)){ // there is already an updated METS
      FullTextXMLtools::updateMetsXML($newMets_path, $output_path, $newMets_path, $ocrEngine);
    } else { // there is no updated METS
      FullTextXMLtools::updateMetsXML($origMets_path, $output_path, $newMets_path, $ocrEngine);
    }
  }

  /** 
   *  Returns the shell command nessesary to run the shell ORC script
   * 
   *  @access protected
   * 
   *  @param string ocrEngine_path
   *  @param string image_path
   *  @param string output_path
   *  @param string pageId
   * 
   *  @return string OCR-script shell command
   */
  protected static function genOCRshellCommand(string $ocrEngine_path, string $image_path, string $output_path, string $pageId):string{
    return "./$ocrEngine_path --image_path $image_path --output_path $output_path --page_id $pageId ";
  }

  /**
   *  Genereates the complete OCR shell command
   * 
   *  @access protected
   * 
   *  @param string ocrPlaceholderText
   *  @param string ocrEngine_path
   *  @param string image_path
   *  @param string tempOutput_path
   *  @param string output_path
   *  @param int pageId
   * 
   *  @return string Full OCR-script shell command
   */
  protected static function genShellCommand(string $ocrPlaceholderText, string $ocrEngine_path, string $image_path, string $tempOutput_path, string $output_path, string $pageId):string{
    $ocrShellCommand = "";
    if ($ocrPlaceholderText) { //create first dummy xmls to prevent multiple tesseract jobs for the same page, then OCR
      FullTextXMLtools::createPlaceholderFulltext($output_path, $ocrPlaceholderText);
      $ocrShellCommand = self::genOCRshellCommand($ocrEngine_path, $image_path, $tempOutput_path, $pageId);
      $ocrShellCommand .= " && mv -f $tempOutput_path.xml $output_path ";
    } else { //do not create dummy xml, write direcly the final file
      $ocrShellCommand = self::genOCRshellCommand($ocrEngine_path, $image_path, $output_path, $pageId);
    }
    return $ocrShellCommand;
  }

  /** 
   * DEBUG: echo debuf alerts to show the values of all vars
   */
  protected static function varOutput($conf, $pageId, $image_path, $outputFolder_path, $output_path, $tempOutput_path, $lock_folder, $imageDownloadCommand, $ocrShellCommand){
    exec("pwd", $output, $retval);
    echo '<script>alert("pwd: ' .implode(" ",$output). '")</script>';
    echo '<script>alert("0. $dwlImage: ' . $conf['ocrDwnlTempImage'] . '")</script>';
    echo '<script>alert("1. $pageId: ' . $pageId . '")</script>';
    echo '<script>alert("2. $image_path: ' . $image_path . '")</script>';
    echo '<script>alert("3. $outputFolder_path: ' . $outputFolder_path . '")</script>';
    echo '<script>alert("4. $output_path: ' . $output_path . '")</script>';
    echo '<script>alert("5. $tempOutput_path: ' . $tempOutput_path . '")</script>';
    echo '<script>alert("6. $lock_folder: ' . $lock_folder . '")</script>';
    echo '<script>alert("9. $imageDownloadCommand: ' . $imageDownloadCommand . '")</script>';
    echo '<script>alert("10. $ocrShellCommand: ' . $ocrShellCommand . '")</script>';
  }
}
?>
