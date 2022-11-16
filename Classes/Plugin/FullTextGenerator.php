<?php

namespace Kitodo\Dlf\Plugin;

use Kitodo\Dlf\Common\Document;
use Kitodo\Dlf\Plugin\FullTextXMLtools;
use Kitodo\Dlf\Plugin\PageView;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogLevel;

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
   * Returns local id of doc (i.e. is needed for fulltext storage)  
   * 
   * @access protected
   *
   * @param Document doc
   *
   * @return string
   */
  protected static function getDocLocalId(Document $doc):string {
    return $doc->toplevelId;
  }

  /**
   * Returns local id of page
   * 
   * @access protected
   *
   * @param Document doc
   * @param int page_num
   *
   * @return string
   */
  protected static function getPageLocalId(Document $doc, int $page_num):string {
    $doc_id = self::getDocLocalId($doc);
    return "{$doc_id}_$page_num";
  }

  /**
   * Generates and returns a document specific local path for the fulltext doc (for example can be a folder)
   * 
   * @access protected
   *
   * @param string ext_key
   * @param Document doc
   *
   * @return string path to documents specific fulltext folder
   */
  protected static function genDocLocalPath(string $ext_key, Document $doc):string {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($ext_key);
    /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("FullTextGen.genDocLocalPath: '.$conf['fulltextFolder'].'")</script>'; //DEBUG

    $urn = FullTextXMLtools::getDocURN($doc); // eg.: urn:nbn:de:bsz:180-digosi-30
    if($urn){ //$urn is present
      $outputFolder_path = $conf['fulltextFolder'] . "/" . str_replace("urn/","URN/",str_replace("-", "/", str_replace(":", "/", $urn))); // -> URN/nbn/de/bsz/180/digosi/30
    } else { //no urn was present
      $outputFolder_path = $conf['fulltextFolder'] . "/noURN/" . sha1($doc->uid); // -> URN/ff0fdd600d8b46542ebe329c00a397841b71e757
    }
    
    return $outputFolder_path;
  }

  /**
   * Returns local path to the doc's page (uses genDocLocalPath)
   * 
   * @access public
   *
   * @param string ext_key
   * @param Document doc
   * @param int page_num
   *
   * @return string
   */
  public static function getPageLocalPath(string $ext_key, Document $doc, int $page_num):string {
    $outputFolder_path = self::genDocLocalPath($ext_key, $doc);
    $ocrEngine = PageView::getOCRengine($ext_key);
    $page_id = self::getPageLocalId($doc, $page_num);
    return "$outputFolder_path/$ocrEngine/$page_id.xml";
  }

  /**
   * Checks whether local fulltext is present
   * 
   * @access public
   *
   * @param string ext_key
   * @param Document doc
   * @param int page_num
   *
   * @return bool
   */
  public static function checkLocal(string $ext_key, Document $doc, int $page_num):bool {
    return file_exists(self::getPageLocalPath($ext_key, $doc, $page_num));
  }

  /**
   * Checks whether fulltext file is in progress (temporary file is present)
   * 
   * @access public
   *
   * @param string ext_key
   * @param Document doc
   * @param int page_num
   *
   * @return bool
   */
  public static function checkInProgress(string $ext_key, Document $doc, int $page_num):bool {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($ext_key);
    return file_exists($conf['fulltextTempFolder'] . '/' . self::getPageLocalId($doc, $page_num) . ".xml");
  }

  /**
   * Create fulltext for all pages in document
   * 
   * @access public
   *
   * @param string ext_key
   * @param Document doc
   * @param array images_urls
   * @param string $ocrEngine
   *
   * @return void
   */
  public static function createBookFullText(string $ext_key, Document $doc, array $images_urls, string $ocrEngine):void {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($ext_key);

    for ($page_num=1; $page_num <= $doc->numPages; $page_num++) {
      if (!(self::checkLocal($ext_key, $doc, $page_num) || self::checkInProgress($ext_key, $doc, $page_num))) {
	      self::generatePageOCR($ext_key, $conf, $doc, $images_urls[$page_num], $page_num, $conf['ocrDelay'], $ocrEngine);
      }
    }
  }

  /**
   * Create fulltext for given page from document
   * 
   * @access protected
   *
   * @param string ext_key
   * @param Document doc
   * @param int page_num
   * @param string $ocrEngine
   *
   * @return bool
   */
  public static function createPageFullText(string $ext_key, Document $doc, string $image_url, int $page_num, string $ocrEngine):void {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($ext_key);

    if (!(self::checkLocal($ext_key, $doc, $page_num) || self::checkInProgress($ext_key, $doc, $page_num))) {
      self::generatePageOCR($ext_key, $conf, $doc, $image_url, $page_num, $conf['ocrDelay'], $ocrEngine);
    }
  }

  /**
   * Main method for creating OCR full texts of a particular page of a document.
   * It builds and executes the command for the specifiend OCR engine script.
   * 
   * @access protected
   *
   * @param string ext_key
   * @param array conf
   * @param Document doc
   * @param string image_url
   * @param int page_num 
   * @param int sleep_interval
   * @param string $ocrEngine
   *
   * @return void
   */
  protected static function generatePageOCR(string $ext_key, array $conf, Document $doc, string $image_url, int $page_num, int $sleep_interval = 0, string $ocrEngine):void {
    /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("FullTextGen.genPageOCR")</script>'; //DEBUG

    //Working dir is "/var/www/typo3/public"; //same as "/var/www/html" because sym link

    //Parse parameter and setup variables:
    $ocr_scripts_folder = "typo3conf/ext/dlf/Classes/Plugin/Tools/FullTextGenerationScripts";
    $ocr_script_path  = "$ocr_scripts_folder/$ocrEngine.sh";          //Path to OCR-Engine/Script
    $page_id          = self::getPageLocalId($doc, $page_num);        //Page number
    $image_path       = $conf['fulltextImagesFolder'] . "/$page_id";  //Imagefile path
    $document_path    = self::genDocLocalPath($ext_key, $doc);        //Document specific path (eg. fileadmin/fulltextfolder/URN/nbn/de/bsz/180/digosi/30/)
    $outputFolder_path = "$document_path/$ocr_script";                //Fulltextfolder (eg. fileadmin/fulltextfolder/URN/nbn/de/bsz/180/digosi/30/tesseract-basic/)
    $origMets_path    = $document_path."/".self::getDocLocalId($doc).".xml"; //Path to original METS
    $newMets_path     = $outputFolder_path."/".self::getDocLocalId($doc).".xml"; //Path to updated METS
    if (!file_exists($outputFolder_path)){ mkdir($outputFolder_path, 0777, true); }  //Create documents path if not present
    FullTextXMLtools::writeMetsXML($doc, $origMets_path);             //Write original METS XML file
    $output_path      = "$outputFolder_path/$page_id.xml";            //Fulltextfile path
    $temp_output_path = $conf['fulltextTempFolder'] . "/$page_id";    //Fulltextfile TMP path
    $lock_folder      = $conf['fulltextTempFolder'] . "/lock";        //Folder used to lock ocr command
    $image_download_command =":";                                     //non empty command without effect //TODO: find better solution
    $ocr_shell_command = "";

    //Build OCR script command:
    //Determine if the image should be downloaded. Than use remote URL ($image_url) or local PATH ($image_path):
    if ($conf['ocrDwnlTempImage']){ //download image
      $image_download_command = "wget $image_url -O $image_path"; //wget image and save to $image_path
      $ocr_shell_command .= self::genShellCommand($conf['ocrPlaceholderText'], $ocr_script_path, $image_path, $temp_output_path, $output_path, $page_id, $conf['ocrLanguages'], $conf['ocrOptions']);
      $ocr_shell_command .= " && rm $image_path";  // Remove used image
    } else { //do not download image, pass URL to the engine
      $ocr_shell_command .= self::genShellCommand($conf['ocrPlaceholderText'], $ocr_script_path, $image_url, $temp_output_path, $output_path, $page_id, $conf['ocrLanguages'], $conf['ocrOptions']);
    }

    // Locking command, so that only one instance of tesseract can run in one time moment
    // TODO: use something like semaphores. That way it is posible to run multiple instances at the same time
    if ($conf['ocrLock']) {
      $ocr_shell_command = "while ! mkdir \"$lock_folder\"; do sleep 3; done; $ocr_shell_command; rm -r $lock_folder;" ;
    }

    //Debug:
    /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("'.$ocr_shell_command.'")</script>'; //DEBUG

    //Execute shell commands:
    exec("($image_download_command && sleep $sleep_interval && $ocr_shell_command)", $output, $retval);

    //Send alert if something went wrong //TODO: later write to log?
    if($retval!=0){ //if exitcode != 0 -> script not successful
      echo '<script>alert(" Status '.$retval.' \n Error: '.implode(" ",$output).'")</script>';
    }

    if (file_exists($newMets_path)){ // there is already an updated METS
      FullTextXMLtools::updateMetsXML($newMets_path, $output_path, $newMets_path, $ocr_script);
    } else { // there is no updated METS
      FullTextXMLtools::updateMetsXML($origMets_path, $output_path, $newMets_path, $ocr_script);
    }
  }

  /** 
   *  Returns the shell command nessesary to run the shell ORC script
   * 
   *  @access protected
   * 
   *  @param string ocr_script_path
   *  @param string image_path
   *  @param string output_path
   *  @param string page_id
   *  @param string OCR_languages
   *  @param string OCR_options
   * 
   *  @return string OCR-script shell command
   */
  protected static function genOCRshellCommand(string $ocr_script_path, string $image_path, string $output_path, string $page_id, string $OCR_languages, string $OCR_options):string{
    return "./$ocr_script_path --image_path $image_path --output_path $output_path --page_id $page_id --ocrLanguages $OCR_languages --ocrOptions $OCR_options ";
  }

  /**
   *  Genereates the complete OCR shell command
   * 
   *  @access protected
   * 
   *  @param string ocrPlaceholderText
   *  @param string ocr_script_path
   *  @param string image_path
   *  @param string temp_output_path
   *  @param string output_path
   *  @param int page_id
   *  @param string OCR_languages
   *  @param string OCR_options
   * 
   *  @return string Full OCR-script shell command
   */
  protected static function genShellCommand(string $ocrPlaceholderText, string $ocr_script_path, string $image_path, string $temp_output_path, string $output_path, string $page_id, string $OCR_languages, string $OCR_options):string{
    $ocr_shell_command = "";
    if ($ocrPlaceholderText) { //create first dummy xmls to prevent multiple tesseract jobs for the same page, then OCR
      FullTextXMLtools::createPlaceholderFulltext($output_path, $ocrPlaceholderText);
      $ocr_shell_command = self::genOCRshellCommand($ocr_script_path, $image_path, $temp_output_path, $page_id, $OCR_languages, $OCR_options);
      $ocr_shell_command .= " && mv -f $temp_output_path.xml $output_path ";
    } else { //do not create dummy xml, write direcly the final file
      $ocr_shell_command = self::genOCRshellCommand($ocr_script_path, $image_path, $output_path, $page_id, $OCR_languages, $OCR_options);
    }
    return $ocr_shell_command;
  }

  /** 
   * DEBUG: echo debuf alerts to show the values of all vars
   */
  protected static function varOutput($conf, $page_id, $image_path, $outputFolder_path, $output_path, $temp_output_path, $lock_folder, $image_download_command, $ocr_shell_command){
    exec("pwd", $output, $retval);
    echo '<script>alert("pwd: ' .implode(" ",$output). '")</script>';
    echo '<script>alert("0. $dwlImage: ' . $conf['ocrDwnlTempImage'] . '")</script>';
    echo '<script>alert("1. $page_id: ' . $page_id . '")</script>';
    echo '<script>alert("2. $image_path: ' . $image_path . '")</script>';
    echo '<script>alert("3. $outputFolder_path: ' . $outputFolder_path . '")</script>';
    echo '<script>alert("4. $output_path: ' . $output_path . '")</script>';
    echo '<script>alert("5. $temp_output_path: ' . $temp_output_path . '")</script>';
    echo '<script>alert("6. $lock_folder: ' . $lock_folder . '")</script>';
    echo '<script>alert("7. $ocrLanguages: ' . $conf['ocrLanguages'] . '")</script>';
    echo '<script>alert("8. $ocrOptions: ' . $conf['ocrOptions']  . '")</script>';
    echo '<script>alert("9. $image_download_command: ' . $image_download_command . '")</script>';
    echo '<script>alert("10. $ocr_shell_command: ' . $ocr_shell_command . '")</script>';
  }
}
?>
