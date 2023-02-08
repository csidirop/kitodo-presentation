<?php

namespace Kitodo\Dlf\Plugin;
use DOMdocument;
use DOMattr;
use XMLReader;
use XMLWriter;
use XMLReaderIterator;
use XMLWritingIteration;
use DateTimeImmutable;

use Kitodo\Dlf\Common\Doc;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogLevel;

/**
 * Plugin 'FullText XMLtools' for the 'dlf' extension
 * Some tools to work with XML files used in Kitodo\Dlf\Plugin\FullTextGenerator
 *
 * @author Christos Sidiropoulos <christos.sidiropoulos@uni-mannheim.de>
 * @package TYPO3
 * @subpackage dlf
 * @access public
 */
class FullTextXMLtools {

  /**
   * Get the URN of the document by reparsing the METS XML.
   * 
   * Unfortunately the URN is not stored consistently in different METS XML files so its not by Presentation.
   * The URN is parsed from the METS XML file from this locations:
   *   'mods:identifier'/'identifier'
   *   'mods:recordIdentifier'
   *   '"mets:div'
   * 
   * @access protected
   * 
   * @param Doc doc
   * 
   * @return string The document's URN or null if not found.
   */
  public static function getDocURN(Doc $doc):?string {
    $reader = new XMLReader();
    $reader->open("$doc->uid"); //open METS XML
    $urn = null;
    while ($reader->read()) {
      if((($reader->name=="mods:identifier")||($reader->name=="identifier")) && ($reader->getAttribute("type") === 'urn') && !empty($reader->readString())){ //if XML key is 'mods:identifier'/'identifier' and attribute 'type' is 'urn'
        $urn = $reader->readString();
        break;
      } else if (($reader->name=="mods:recordIdentifier") && ($reader->getAttribute("source") === 'urn') && !empty($reader->readString())){ //if XML key is 'mods:recordIdentifier' and attribute 'source' is 'urn'
        $urn = $reader->readString();
        break;
      } else if (($reader->name=="mets:div") && (substr($reader->getAttribute("CONTENTIDS"),0,3) === 'urn') && !empty($reader->readString())){ //if XML key is under '<mets:structMap TYPE="LOGICAL">' with '"mets:div' and attribute is 'CONTENTIDS' and starts with 'urn'
        $urn = $reader->getAttribute("CONTENTIDS");
        break;
      }
    }
    return $urn;
  }

  /**
   * Write METS XML file to its corresponding directory with the fulltext ALTO files, if it does not exist yet.
   * 
   * @access protected
   * 
   * @param Doc doc
   * @param string xml_path Path to the output folder
   * 
   */
  public static function writeMetsXML(Doc $doc, string $xml_path):void {
    if(!file_exists($xml_path)){ //check if METS XML file already exists
      $file = self::getMetsXML($doc);
      // $file = $doc->xml->asXML(); //Alternative: Get METS XML file from doc object -> faster but slightly different header
      $metsFile = fopen($xml_path, "w+") or die("Unable to write METS XML file!"); //create METS XML file
      fwrite($metsFile, $file); //write METS XML file
      fclose($metsFile);
    }
  }

  /**
   * Reads and returns the METS file.
   * 
   * @access protected
   * 
   * @param Doc doc
   * 
   * @return string METS XML content
   */
  protected static function getMetsXML(Doc $doc):string {
    return file_get_contents($doc->uid);
  }

  /**
   *  Update METS XML file with the genereted fulltext ALTO file. 
   * 
   *  @access protected
   * 
   *  @param string xml_path Path to the original METS XML
   *  @param string alto_path Path to the ALTO XML
   *  @param string new_xml_path Path to the updated METS XML
   *  @param string ocr_script Name of the ocr generation script
   * 
   */
  public static function updateMetsXML(string $xml_path, string $alto_path, string $new_xml_path, string $ocr_script):void {
    //Set up XML reader and writer:
    $reader = new XMLReader();
    $reader->open($xml_path);
    $writer = new XMLWriter();
    $writer->openUri($new_xml_path."-tmp"); //in case $xml_path == $new_xml_path add "-tmp"
    $iterator = new XMLWritingIteration($writer, $reader);
    $writer->startDocument('1.0', 'UTF-8');

    //prepare some variables:
    $fulltextPresent = $xml_path===$new_xml_path;
    $datetime = new DateTimeImmutable ();
    $datestamp = $datetime->format(DateTimeImmutable::ATOM); //Time in format: "Y-m-d\TH:i:sP" eg "2022-10-26T14:11:21+00:00"
    $alto_filename = substr($alto_path, strrpos($alto_path, '/')+1); //log59088_491.xml
    $alto_id = substr($alto_filename, 0, strlen($alto_filename)-4); //log59088_491
    $page_num = substr($alto_id, strrpos($alto_id, '_')+1);

    //Add ALTO entry to <mets:fileGrp USE="FULLTEXT">
    foreach ($iterator as $node) {
      $isElement = $node->nodeType === XMLReader::ELEMENT;
      if($isElement && ($node->name === 'mets:fileSec' || $node->name === 'fileSec')){
        $iterator->write(); //Write current node: <mets:fileSec>
        $node->read(); //Go inside current node: <mets:fileSec> -> at level <mets:fileGrp>

        $writer->setIndentString('  ');
        $writer->setIndent(true); //do not write all elements in one line

        if($fulltextPresent){ //A FULLTEXT element already exists
          $node->read(); //Go inside current node: all <mets:fileGrp>
          if($isElement && $node->getAttribute("USE") === 'FULLTEXT'){ // Check if attribute is FULLTEXT
            $iterator->write(); //Write current node:<mets:fileGrp USE="FULLTEXT">
            $node->read(); //Go inside current node: <mets:fileGrp> -> at level <mets:file>
            //Update node:
            self::updateMetsNode($writer, $alto_path, $alto_id, $datestamp, $ocr_script);
          }
        } else { //A FULLTEXT element already does not exists: create one
          //Write new node:
          $writer->startElement('mets:fileGrp'); // <mets:fileGrp USE="FULLTEXT">
            $writer->writeAttribute('USE', 'FULLTEXT'); 
            self::updateMetsNode($writer, $alto_path, $alto_id, $datestamp, $ocr_script);
          $writer->endElement();
        }
        $writer->setIndent(false);
      }

      //Update <mets:structMap TYPE="PHYSICAL"> entry:
      if($isElement && ($node->name === 'mets:div') && ($node->getAttribute("TYPE") === 'page') && ($node->getAttribute("ORDER") === $page_num)){
        $iterator->write(); //Write current node: <mets:div> TYPE="page"
        $node->read(); //Go inside current node: <mets:div> TYPE="page" -> at level <mets:fptr>

        $writer->setIndentString('  ');
        $writer->setIndent(true); //do not write all elements in one line
        $writer->startElement('mets:fptr');
        $writer->writeAttribute('FILEID', "ALTO_$alto_id");
        $writer->endElement();
        $writer->setIndent(false);
      }

      $iterator->write(); //Write current node
    }
    $writer->endDocument();
    rename($new_xml_path."-tmp", $new_xml_path);
  }

  protected static function updateMetsNode(XMLWriter $writer, string $alto_path, string $alto_id, string $datestamp, string $ocr_script):void {
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
  public static function createPlaceholderFulltext(string $path, string $text):void {
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
