#!/bin/bash

function usage() { 
	echo "Help TODO" 1>&2; 
	exit 1; 
}

function test() {
	tesseract https://digi.bib.uni-mannheim.de/fileadmin/digi/1652998276/max/1652998276_0001.jpg testout -l frak2021_1.069 txt pdf
	exit 0;
}

[ $# -eq 0 ] && usage

# Paramaters:
while [ $# -gt 0 ] ; do
  case $1 in
  	-h | --help) 		usage;;
	--page_id) 			page_id="$2" ;;			#Page number
	--image_path) 		image_path="$2" ;;		#Imagefile path
	--image_URL) 		image_URL="$2" ;;		#Image URL
	--doc_path)			doc_path="$2" ;;		#Fulltextfolder path
	--xml_path)			xml_path="$2" ;;		#Fulltextfile path
	--temp_xml_path)	temp_xml_path="$2" ;;	#Fulltextfile TMP path
	--lock_folder)		lock_folder="$2" ;;		#Folder used to lock ocr command
	--image_download_command) image_download_command="$2" ;; #wget image and save to $image_path
	--ocrLanguages) 	ocrLanguages="$2" ;;	#Models&Languages for Tesseract
	--ocrOptions)		ocrOptions="$2" ;;		#Output types
	--test)				test ;;
  esac
  shift
done

# Check for required parameters:
if [[ -z ${page_id} || -z ${ocrLanguages} || -z ${ocrOptions} ]] ; then
  echo "Missing parameter" #TODO
  exit 1
fi

# Distinguish if image is remote or local: 
if [[ -n ${image_path} ]] ; then
	echo "Running OCR: with local image"
	echo "Running: tesseract $image_path $temp_xml_path -l $ocrLanguages $ocrOptions"
	tesseract $image_path $temp_xml_path -l $ocrLanguages $ocrOptions
	exit 0
else
	echo "Running OCR: with remote image"
	#TODO
fi
