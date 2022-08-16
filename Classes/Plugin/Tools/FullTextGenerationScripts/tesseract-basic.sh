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
	--page_id) 			page_id="$2" ;;
	--image_path) 		image_path="$2" ;;
	--image_URL) 		image_URL="$2" ;;
	--doc_path)			doc_path="$2" ;;
	--xml_path)			xml_path="$2" ;;
	--temp_xml_path)	temp_xml_path="$2" ;;
	--lock_folder)		lock_folder="$2" ;;
	--image_download_command) image_download_command="$2" ;;
	--ocrLanguages) 	ocrLanguages="$2" ;;
	--ocrOptions)		ocrOptions="$2" ;;
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
