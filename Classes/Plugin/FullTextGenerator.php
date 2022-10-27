<?php

namespace Kitodo\Dlf\Plugin;
use DOMdocument;
use DOMattr;
use XMLReader;
use XMLWriter;
use XMLReaderIterator;
use XMLWritingIteration;
use DateTimeImmutable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogLevel;

session_start(); // Start a PHP session to temporarily hold some variables ($_SESSION["ocrEngine"]) globaly for this user only

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
   * Reads and returns the METS file.
   * //TODO for the future: Do not re-download the METS file, but use the one from presentation in &doc if possible
   * 
   * @access protected
   * 
   * @param \Kitodo\Dlf\Common\Document doc
   * 
   * @return string METS XML content
   */
  protected static function getMetsXML($doc) {
    return file_get_contents($doc->uid);
  }

  /**
   * Write METS XML file to its corresponding directory with the fulltext ALTO files, if it does not exist yet.
   * 
   * @access protected
   * 
   * @param \Kitodo\Dlf\Common\Document doc
   * @param String xml_path Path to the output folder
   * 
   */
  protected static function writeMetsXML($doc, $xml_path) {
    if(!file_exists($xml_path)){ //check if METS XML file already exists
      $file = self::getMetsXML($doc);
      // $file = $doc->xml->asXML(); //Alternative: Get METS XML file from doc object -> faster but slightly different header
      $metsFile = fopen($xml_path, "w+") or die("Unable to write METS XML file!"); //create METS XML file
      fwrite($metsFile, $file); //write METS XML file
      fclose($metsFile);
    }
  }

  /**
   * Get the URN of the document by reparsing the METS XML.
   * 
   * Unfortunately the URN is not stored consistently by PResentation with different METS XML files.
   * 
   * @access protected
   * 
   * @param \Kitodo\Dlf\Common\Document doc
   * 
   * @return string The document's URN or null if not found.
   */
  protected static function getDocURN($doc) {
    $reader = new XMLReader();
    $reader->open("$doc->uid"); //open Mets XML
    $urn;
    while ($reader->read()) {
      if($reader->name=="mods:identifier" && substr($reader->readInnerXml(),0,3)=='urn'){ //if XML key is mods:identifier and value starts with 'urn'
        $urn = $reader->readInnerXml();
        //Help: no way to check for attribute like type='urn'
        //echo '<script>alert("'.$reader->name.' | '.$reader->localName.' | '.$reader->prefix.' | '.$reader->$namespaceURI.' | '.$reader->xmlLang.' | '.$reader->getAttribute('urn').' | '.$reader->readString().' | '.$reader->$baseURI.' | '.$reader->readInnerXml().'")</script>'; //DEBUG
        //                    //mods:identifier  |   identifier           |   mods              |   -                        |  |                   |                                  | urn:nbn:de:bsz:180-digosi-30 |                    |  urn:nbn:de:bsz:180-digosi-30
      }
    }
    return $urn;
  }

  protected static function updateMetsXML($doc, $xml_path, $alto_path, $new_xml_path, $ocr_script) {
    //Set up XML reader and writer:
    $reader = new XMLReader();
    $reader->open($xml_path);
    $writer = new XMLWriter();
    $writer->openUri($new_xml_path);
    $iterator = new XMLWritingIteration($writer, $reader);
    $writer->startDocument('1.0', 'UTF-8');

    //prepare some variables:
    $datetime = new DateTimeImmutable ();
    $datestamp = $datetime->format(DateTimeImmutable::ATOM); //Time in format: "Y-m-d\TH:i:sP" eg "2022-10-26T14:11:21+00:00"

    $alto_id = substr($alto_path, strrpos($alto_path, '/')+1);

    //Add ALTO entry to <mets:fileGrp USE="FULLTEXT">
    foreach ($iterator as $node) {
      $isElement = $node->nodeType === XMLReader::ELEMENT;
      if($isElement && $node->name === 'mets:fileSec'){

        $iterator->write(); //Write current node: <mets:fileSec>
        $node->read(); //Go inside current node: all <mets:fileGrp>

        //Write new node:
        $writer->setIndentString('  ');
        $writer->setIndent(true); //do not write all elements in one line
        $writer->startElement('mets:fileGrp'); // <mets:fileGrp USE="FULLTEXT">
          $writer->writeAttribute('USE', 'FULLTEXT'); 
          $writer->startElement('mets:file'); // <mets:file ID="ALTO_log59088_431.xml" MIMETYPE="text/xml" CREATED="2022-10-26T14:28:16+00:00" SOFTWARE="DFG-Viewer-5-OCR-tesseract-basic">
            $writer->writeAttribute('ID', "ALTO_$alto_id");
            $writer->writeAttribute('MIMETYPE', 'text/xml');
            $writer->writeAttribute('CREATED', $datestamp);
            $writer->writeAttribute('SOFTWARE', "DFG-Viewer-5-OCR-$ocr_script");
            $writer->startElement('mets:FLocat'); // <mets:FLocat LOCTYPE="URL" xlink:href="https://digi.bib.uni-mannheim.de/fileadmin/digi/log59088/alto/log59088_431.xml"/>
              $writer->writeAttribute('LOCTYPE', 'URL');
              $writer->writeAttribute('xlink:href', "http://".$_SERVER['HTTP_HOST']."/".$alto_path);
            $writer->endElement();
          $writer->endElement();
        $writer->endElement();
        $writer->setIndent(false);
      }
      $iterator->write();
    }
    $writer->endDocument();
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
    /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("FullTextGen.genDocLocalPath: '.$conf['fulltextFolder'].'")</script>'; //DEBUG

    $urn = self::getDocURN($doc); // eg.: urn:nbn:de:bsz:180-digosi-30
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
   * @param \Kitodo\Dlf\Common\Document doc
   * @param int page_num
   *
   * @return string
   */
  public static function getPageLocalPath($ext_key, $doc, $page_num) {
    $outputFolder_path = self::genDocLocalPath($ext_key, $doc);
    $ocrEngine = self::getOCRengine($ext_key);
    $page_id = self::getPageLocalId($doc, $page_num);
    return "$outputFolder_path/$ocrEngine/$page_id.xml";
  }

  /**
   * Checks and returns the OCR-Engine //WIP
   * 
   * @access protected
   *
   * @param string ext_key
   *
   * @return string
   */
  protected static function getOCRengine($ext_key){
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($ext_key);
    if(is_null($_SESSION["ocrEngine"])){ //if not set, get default value
      $_SESSION["ocrEngine"] = $conf['ocrEngine'];
    }
    // $_SESSION["ocrEngine"] = "tess";
    return $_SESSION["ocrEngine"];
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
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($ext_key);
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
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($ext_key);
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
    $conf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($ext_key);
    for ($i=1; $i <= $doc->numPages; $i++) {
      if (!(self::checkLocal($ext_key, $doc, $i) || self::checkInProgress($ext_key, $doc, $i))) {
	      self::generatePageOCRwithScript($ext_key, $conf, $doc, $ocr_script, $images_urls[$i], $i, $conf['ocrDelay']);
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
      return self::generatePageOCRwithScript($ext_key, $conf, $doc, $ocr_script, $image_url, $page_num);
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
   * @param \Kitodo\Dlf\Common\Document doc
   * @param string ocr_script
   * @param string image_url
   * @param int page_num 
   * @param int sleep_interval
   *
   * @return void
   */
  protected static function generatePageOCRwithScript($ext_key, $conf, $doc, $ocr_script, $image_url, $page_num, $sleep_interval = 0) {
    /* DEBUG */ if($conf['ocrDebug']) echo '<script>alert("FullTextGen.genPageOCR")</script>'; //DEBUG

    //Working dir is "/var/www/typo3/public"; //same as "/var/www/html" because sym link

    //Parse parameter and setup variables:
    $ocr_scripts_folder = "typo3conf/ext/dlf/Classes/Plugin/Tools/FullTextGenerationScripts";
    $ocr_script       = self::getOCRengine($ext_key);                 //OCR-Engine/Script set in settings (//TODO: or via UI)
    $ocr_script_path  = "$ocr_scripts_folder/$ocr_script.sh";         //Path to OCR-Engine/Script
    // $ocr_script_path  = "$ocr_scripts_folder/tesseract-basic.sh";  //Path to OCR-Engine/Script
    $page_id          = self::getPageLocalId($doc, $page_num);        //Page number
    $image_path       = $conf['fulltextImagesFolder'] . "/$page_id";  //Imagefile path
    $document_path    = self::genDocLocalPath($ext_key, $doc);        //Document specific path (eg. fileadmin/fulltextfolder/URN/nbn/de/bsz/180/digosi/30/)
    $origMets_path    = $document_path."/".self::getDocLocalId($doc).".xml"; //Path to original METS
    $outputFolder_path = "$document_path/$ocr_script";                //Fulltextfolder (eg. fileadmin/fulltextfolder/URN/nbn/de/bsz/180/digosi/30/tesseract-basic/)
    if (!file_exists($outputFolder_path)){ mkdir($outputFolder_path, 0777, true); }  //Create documents path if not present
    self::writeMetsXML($doc, $origMets_path);                         //Write original METS XML file
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
                      //doc,  xml_path,       alto_path,    new_xml_path
    self::updateMetsXML($doc, $origMets_path, $output_path, $outputFolder_path."/".self::getDocLocalId($doc).".xml", $ocr_script);

  }

  /** 
   *  Returns the shell command nessesary to run the shell ORC script
   * 
   *  @access protected
   * 
   *  @param string ocr_script_path
   *  @param string image_path
   *  @param string output_path
   *  @param int page_id
   *  @param string OCR_languages
   *  @param string OCR_options
   * 
   *  @return string OCR-script shell command
   */
  protected static function genOCRscriptShellCommand($ocr_script_path, $image_path, $output_path, $page_id, $OCR_languages, $OCR_options){
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
  protected static function genShellCommand($ocrPlaceholderText, $ocr_script_path, $image_path, $temp_output_path, $output_path, $page_id, $OCR_languages, $OCR_options){
    $ocr_shell_command = "";
    if ($ocrPlaceholderText) { //create first dummy xmls to prevent multiple tesseract jobs for the same page, then OCR
      self::createPlaceholderFulltext($output_path, $ocrPlaceholderText);
      $ocr_shell_command = self::genOCRscriptShellCommand($ocr_script_path, $image_path, $temp_output_path, $page_id, $OCR_languages, $OCR_options);
      $ocr_shell_command .= " && mv -f $temp_output_path.xml $output_path ";
    } else { //do not create dummy xml, write direcly the final file
      $ocr_shell_command = self::genOCRscriptShellCommand($ocr_script_path, $image_path, $output_path, $page_id, $OCR_languages, $OCR_options);
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

  /**
   * Create placeholder (WIP) file at given path with given text
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
