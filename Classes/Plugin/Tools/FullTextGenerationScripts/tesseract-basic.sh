#!/bin/bash

function usage() { 
	echo "No parameter set";
	exit 1; 
}

function test() {
	tesseract https://digi.bib.uni-mannheim.de/fileadmin/digi/1652998276/max/1652998276_0001.jpg testout -l frak2021_1.069 txt pdf alto
	exit 0;
}

# Check for parameter:
[ $# -eq 0 ] && usage # If no parameter given call usage()

# Paramaters:
while [ $# -gt 0 ] ; do
  case $1 in
  	-h | --help) 		usage;;
	--page_id) 			page_id="$2" ;;			#Page number
	--image_path) 		image_path="$2" ;;		#Image path/URL
	#--image_URL) 		image_URL="$2" ;;		#Image URL
	#--doc_path)			doc_path="$2" ;;		#Fulltextfolder path
	--xml_path)			xml_path="$2" ;;		#Fulltextfile path
	--ocrLanguages) 	ocrLanguages="$2" ;;	#Models&Languages for Tesseract
	--ocrOptions)		ocrOptions="$2" ;;		#Output types
	--test)				test ;;
  esac
  shift
done

# Check for required parameters:
if [[ -z ${image_path} || -z ${xml_path} || -z ${ocrLanguages} || -z ${ocrOptions} ]] ; then
  echo "Missing parameter" #TODO
  exit 1
fi
 
# Parse URL or Path and run tesseract:
regex='(https?|ftp|file)://[-[:alnum:]\+&@#/%?=~_|!:,.;]*[-[:alnum:]\+&@#/%=~_|]' #Regex for URL validation ( https://stackoverflow.com/a/3184819 )
if [[ (${image_path} =~ $regex) || (-f ${image_path}) ]] ; then # If image_path is a valid URL or a local file
    #echo "Link valid and file exists"
	echo "Running OCR: tesseract $image_path $xml_path -l $ocrLanguages $ocrOptions"
	tesseract $image_path $xml_path -l $ocrLanguages $ocrOptions
	exit 0
else
	echo "File not found: ${image_path}" 
	exit 2
fi
