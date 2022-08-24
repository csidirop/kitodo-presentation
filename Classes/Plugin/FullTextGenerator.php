<?php

//OCR-Test: Copied from KIT project.

namespace Kitodo\Dlf\Plugin;
use DOMdocument;
use DOMattr;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogLevel;

class FullTextGenerator {
  protected $conf = [];

  /**
   * Returns local id of doc (i.e. is needed for fulltext storage)  
   * 
   * @access protected
   *
   * @param \Kitodo\Dlf\Common\Document doc
   *
   * @return string
   */
  protected static function getDocLocalId($doc) {
    return $doc->toplevelId;
  }

  /**
   * Get the URN of the document
   * 
   * @access protected
   * 
   * @param \Kitodo\Dlf\Common\Document doc
   * 
   * @return string the document's URN
   */
  protected static function getDocURN($doc) {
    //var_dump($doc->getLogicalUnits()[self::getDocLocalId($doc)]['contentIds']);
    return $doc->getLogicalUnits()[self::getDocLocalId($doc)]['contentIds'];
  }

  /**
   * Returns local id of page   
   * 
   * @access protected
   *
   * @param \Kitodo\Dlf\Common\Document doc
   * @param int page_num
   *
   * @return string
   */
  protected static function getPageLocalId($doc, $page_num) {
    $doc_id = self::getDocLocalId($doc);
    return "{$doc_id}_$page_num";
  }
  
  /**
   * Generates and returns a document specific local path for the fulltext doc (for example can be a folder)
   * 
   * @access protected
   *
   * @param \Kitodo\Dlf\Common\Document doc
   *
   * @return string path to documents specific fulltext folder
   */
  protected static function genDocLocalPath($ext_key, $doc) {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($ext_key);
    $doc_id = self::getDocLocalId($doc);
    /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("FullTextGen.genDocLocalPath: '.$conf['fulltextFolder'] . "/$doc_id".'")</script>'; //DEBUG

    $urn = self::getDocURN($doc); // eg.: urn:nbn:de:bsz:180-digosi-30
    $doc_path = $conf['fulltextFolder'] . "/" . str_replace("urn/","",str_replace("-", "/", str_replace(":", "/", $urn))); // -> nbn/de/bsz/180/digosi/30
    //echo '<script>alert("genDocLocalPath: '.$doc_path.'")</script>'; //DEBUG
    return $doc_path;
    //return $conf['fulltextFolder'] . "/$doc_id"; //OLD TODO: remove
  }
  
  /**
   * Returns local path to the doc's page (uses genDocLocalPath)
   * 
   * @access public
   *
   * @param string ext_key
   * @param \Kitodo\Dlf\Common\Document doc
   * @param int page_num
   *
   * @return string
   */
  public static function getPageLocalPath($ext_key, $doc, $page_num) {
    $doc_path = self::genDocLocalPath($ext_key, $doc);
    $page_id = self::getPageLocalId($doc, $page_num);
    return "$doc_path/$page_id.xml";
  }

  /**
   * Checks whether local fulltext is present
   * 
   * @access public
   *
   * @param string ext_key
   * @param \Kitodo\Dlf\Common\Document doc
   * @param int page_num
   *
   * @return bool
   */
  public static function checkLocal($ext_key, $doc, $page_num) {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)
      ->get($ext_key);
    return file_exists(self::getPageLocalPath($ext_key, $doc, $page_num));
  }

  /**
   * Checks whether fulltext file is in progress (temporary file is present)
   * 
   * @access public
   *
   * @param string ext_key
   * @param \Kitodo\Dlf\Common\Document doc
   * @param int page_num
   *
   * @return bool
   */
  public static function checkInProgress($ext_key, $doc, $page_num) {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)
      ->get($ext_key);
    return file_exists($conf['fulltextTempFolder'] . '/' . self::getPageLocalId($doc, $page_num) . ".xml");
  }

  /**
   * Create fulltext for all pages in document
   * 
   * @access public
   *
   * @param string ext_key
   * @param \Kitodo\Dlf\Common\Document doc
   * @param array images_urls
   *
   * @return void
   */
  public static function createBookFullText($ext_key, $doc, $images_urls) { 
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)
      ->get($ext_key);
    
    for ($i=1; $i <= $doc->numPages; $i++) {
      $delay = $i * $conf['ocrDelay'];
      if (!(self::checkLocal($ext_key, $doc, $i) || self::checkInProgress($ext_key, $doc, $i))) {
	      self::generatePageOCRwithScript($ext_key, $conf, $doc, $images_urls[$i], $i, $delay);
      }
    }
  }

  /**
   * 
   * @access protected
   *
   * @param string ext_key
   * @param \Kitodo\Dlf\Common\Document doc
   * @param int page_num
   *
   * @return bool
   */
  public static function createPageFullText($ext_key, $doc, $image_url, $page_num) {
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($ext_key);

    if (!(self::checkLocal($ext_key, $doc, $page_num) || self::checkInProgress($ext_key, $doc, $page_num))) {
      return self::generatePageOCRwithScript($ext_key, $conf, $doc, $image_url, $page_num);
      //return self::generatePageOCR($ext_key, $conf, $doc, $image_url, $page_num); //TODO einbinden
    }
  }

  protected static function generatePageOCRwithScript($ext_key, $conf, $doc, $image_url, $page_num, $sleep_interval = 0) { 
    /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("FullTextGen.genPageOCR")</script>'; //DEBUG
    
    //Working dir is "/var/www/typo3/public"; //same as /var/www/html because sym link

    //Parse parameter:
    //TODO code clean up
    //TODO outsource to different funktion -> later multiple scripts
    $ocr_script_path = "typo3conf/ext/dlf/Classes/Plugin/Tools/FullTextGenerationScripts/tesseract-basic.sh";

    $page_id = self::getPageLocalId($doc, $page_num);           //Page number
    $image_path = $conf['fulltextImagesFolder'] . "/$page_id";  //Imagefile path
    $doc_path = self::genDocLocalPath($ext_key, $doc);          //Fulltextfolder path (fileadmin/fulltextfolder/)
    if (!file_exists($doc_path)){
      mkdir($doc_path, 0777, true);                             //Create folder structure (fileadmin/fulltextfolder/nbn/de/bsz/180/digosi/30)
    }
    $xml_path = self::getPageLocalPath($ext_key, $doc, $page_num); //Fulltextfile path
    $temp_xml_path = $conf['fulltextTempFolder'] . "/$page_id";    //Fulltextfile TMP path
    $lock_folder = $conf['fulltextTempFolder'] . "/lock";          //Folder used to lock ocr command
    $image_download_command =":"; //non empty command without effect //TODO find better solution

    //Build OCR script Command:
    //TODO: Cleanup merge identical code parts
    //Distinguish if image is remote (URL) or local (PATH):
    if ($conf['dwnlTempImage']){ //download image
      $image_download_command = "wget $image_url -O $image_path";    //wget image and save to $image_path

      //check if placeholder files have to be created:
      if ($conf['ocrDummyText']) { //create first dummy xmls to prevent multiple tesseract jobs for the same page, then OCR
        self::createPlaceholderFulltext($xml_path, $conf['ocrDummyText']); 
        $ocr_shell_command = self::genOCRshellCommand($conf, $ocr_script_path, $image_path, $temp_xml_path, $page_id, $conf['ocrLanguages'], $conf['ocrOptions']);
        $ocr_shell_command .= " && mv -f $temp_xml_path.xml $xml_path ";
      } else { //do not create dummy xml, write direcly the final file
        $ocr_shell_command = self::genOCRshellCommand($conf, $ocr_script_path, $image_path, $xml_path, $page_id, $conf['ocrLanguages'], $conf['ocrOptions']);
      }

      $ocr_shell_command .= " && rm $image_path";  // Remove used image
    } else { //do not download image, pass URL to the engine
      
      //check if placeholder files have to be created:
      if ($conf['ocrDummyText']) { //create first dummy xmls to prevent multiple tesseract jobs for the same page, then OCR
        self::createPlaceholderFulltext($xml_path, $conf['ocrDummyText']); 
        $ocr_shell_command = self::genOCRshellCommand($conf, $ocr_script_path, $image_url, $temp_xml_path, $page_id, $conf['ocrLanguages'], $conf['ocrOptions']);
        $ocr_shell_command .= " && mv -f $temp_xml_path.xml $xml_path ";
      } else { //do not create dummy xml, write direcly the final file
        $ocr_shell_command = self::genOCRshellCommand($conf, $ocr_script_path, $image_url, $xml_path, $page_id, $conf['ocrLanguages'], $conf['ocrOptions']);
      }

    }

    //* DEBUG */ if($conf['ocrDebug']) self::varOutput($conf, $page_id, $image_path, $image_path_abs, $doc_path, $xml_path, $xml_path_abs, $temp_xml_path, $temp_xml_path_abs, $lock_folder, $image_download_command, $ocr_shell_command);

    // Locking command, so that only one instance of tesseract can run in one time moment
    if ($conf['ocrLock']) {
      //TODO eleganter, min. file statt folder
      $ocr_shell_command = "while ! mkdir \"$lock_folder\"; do sleep 3; done; $ocr_shell_command; rm -r $lock_folder;" ;
    }

    /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("'.$ocr_shell_command.'")</script>'; //DEBUG

    //Execute OCR commands:
    exec("($image_download_command && sleep $sleep_interval && $ocr_shell_command)", $output, $retval);

    if($retval!=0){ //if exitcode != 0 -> script not successful
      echo '<script>alert(" Status '.$retval.' \n Error: '.implode(" ",$output).'")</script>';
    }
  }

  /**
   * Main Method for creation of new Fulltext for a page
   * Saves a XML file with fulltext
   * 
   * @access protected
   *
   * @param string ext_key
   * @param array conf
   * @param \Kitodo\Dlf\Common\Document doc
   * @param string image_url
   * @param int page_num 
   *
   * @return void
   */
  protected static function generatePageOCR($ext_key, $conf, $doc, $image_url, $page_num, $sleep_interval = 0) { 
    //TODO remove!
    /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("FullTextGen.genPageOCR")</script>'; //DEBUG
    $page_id = self::getPageLocalId($doc, $page_num);
    $image_path = $conf['fulltextImagesFolder'] . "/$page_id";

    $doc_path = self::genDocLocalPath($ext_key, $doc);
    if (!file_exists($doc_path)){
      mkdir($doc_path);
    } 
    $xml_path = self::getPageLocalPath($ext_key, $doc, $page_num);
    $temp_xml_path = $conf['fulltextTempFolder'] . "/$page_id";

    $lock_folder = $conf['fulltextTempFolder'] . "/lock"; // Folder used to lock ocr command

    $image_download_command = "wget $image_url -O $image_path";  

    if ($conf['ocrDummyText']) {
      /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("FullTextGen.genPageOCR: ocrDummyText true")</script>'; //DEBUG
      // Schema:  tesseract fileadmin/test_images/test.jpg fileadmin/temp_xmls/test_temp.xml -l de alto && mv -f fileadmin/temp_xmls/test.xml fileadmin/test_xmls/test.xml
      $ocr_shell_command = self::getDummyOCRCommand($conf, $image_path, $temp_xml_path, $xml_path);
    } else {
      /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("FullTextGen.genPageOCR: ocrDummyText false")</script>'; //DEBUG
      // Schema:  tesseract fileadmin/test_images/test.jpg fileadmin/test_xmls/test.xml -l de alto 
      $ocr_shell_command = $conf['ocrEngine'] . " $image_path $xml_path " . " -l " . $conf['ocrLanguages'] . " " . $conf['ocrOptions'] . ";";
    }
    echo '<script>alert("z1")</script>';
    // Removing used image
    $ocr_shell_command .= " rm $image_path";

    // Locking command, so that only one instance of tesseract can run in one time moment
    if ($conf['ocrLock']) {
      $ocr_shell_command= "while ! mkdir \"$lock_folder\"; do sleep 3; done; $ocr_shell_command rm -r $lock_folder;" ;
    }

    exec("($image_download_command && sleep $sleep_interval && ($ocr_shell_command)) > /dev/null 2>&1 &");
  }

  protected static function genOCRshellCommand($conf, $ocr_script_path, $image_path, $xml_path, $page_id, $OCR_languages, $OCR_options){
    return "./$ocr_script_path --image_path $image_path --xml_path $xml_path --page_id $page_id --ocrLanguages $OCR_languages --ocrOptions $OCR_options ";
  }

  protected static function varOutput($conf, $page_id, $image_path, $image_path_abs, $doc_path, $xml_path, $xml_path_abs, $temp_xml_path, $temp_xml_path_abs, $lock_folder, $image_download_command, $ocr_shell_command){
    exec("pwd", $output, $retval);
    echo '<script>alert("pwd: ' .implode(" ",$output). '")</script>';
    echo '<script>alert("1. $page_id: ' . $page_id . '")</script>';
    echo '<script>alert("2. $image_path: ' . $image_path . '")</script>';
    echo '<script>alert("2a. $image_path_abs: ' . $image_path_abs . '")</script>';
    //echo '<script>alert("2a. $image_path: ' . (__DIR__.$image_path) . '")</script>';
    echo '<script>alert("3. $doc_path: ' . $doc_path . '")</script>';
    echo '<script>alert("4. $xml_path: ' . $xml_path . '")</script>';
    echo '<script>alert("4a. $xml_path_abs: ' . $xml_path_abs . '")</script>';
    echo '<script>alert("5. $temp_xml_path: ' . $temp_xml_path . '")</script>';
    echo '<script>alert("5a. $temp_xml_path_abs: ' . $temp_xml_path_abs . '")</script>';
    echo '<script>alert("6. $lock_folder: ' . $lock_folder . '")</script>';
    echo '<script>alert("7. $image_download_command: ' . $image_download_command . '")</script>';
    echo '<script>alert("8. $ocrLanguages: ' . $conf['ocrLanguages'] . '")</script>';
    echo '<script>alert("9. $ocrOptions: ' . $conf['ocrOptions']  . '")</script>';
    echo '<script>alert("10. $ocr_shell_command: ' . $ocr_shell_command . '")</script>';

  }

  protected static function getDummyOCRCommand($conf, $image_path, $temp_xml_path, $xml_path) {
      self::createPlaceholderFulltext($xml_path, $conf['ocrDummyText']);
      return $conf['ocrEngine'] . " $image_path $temp_xml_path " . " -l " . $conf['ocrLanguages'] . " " . $conf['ocrOptions'] . " && mv -f $temp_xml_path.xml $xml_path;";
  }

  /**
   * Create dummy/placeholder (WIP) file at given path with given text
   * 
   * @access protected
   *
   * @param string path
   * @param string text
   *
   * @return void
   */
  protected static function createPlaceholderFulltext($path, $text) {
    $dom = new DOMdocument();

    $root = $dom->createelement("alto");
    $fulltext_dummy= $dom->createElement("Fulltext", "WIP");

    $layout = $dom->createelement("Layout");
    $page = $dom->createelement("Page");
    $print_space = $dom->createelement("PrintSpace");
    $textblock = $dom->createelement("TextBlock");
  
    $text = ["\n","\n","\n","\n","\n","\n","\n","\n", $text];
    foreach($text as $line) {
      $textline = $dom->createelement("TextLine");
      $string = $dom->createelement("String");
      $content_attr = new DOMattr("CONTENT", $line);
      $string->setattributenode($content_attr);
      $textline->appendchild($string);
      $textblock->appendchild($textline);
    }
    
    $print_space->appendchild($textblock);
    $page->appendchild($print_space);
    $layout->appendchild($page);
    $root->appendChild($fulltext_dummy);
    $root->appendchild($layout);
    $dom->appendchild($root);
    $dom->formatOutput = true;
    $dom->save($path);
  }
}
?>
