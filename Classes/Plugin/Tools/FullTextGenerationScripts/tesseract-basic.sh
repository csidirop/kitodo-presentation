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
if [[ -n ${image_path} ]] ; then # check if var is set
	if [ -f ${image_path} ]; then # check if image is downloaded
		echo "Running OCR: with local image"
		echo "Running: tesseract $image_path $xml_path -l $ocrLanguages $ocrOptions"
		tesseract $image_path $xml_path -l $ocrLanguages $ocrOptions
		exit 0
	else
		echo "File not found: ${image_path}" 
		exit 2
	fi
else
	echo "Running OCR: with remote image"
	#TODO
fi
