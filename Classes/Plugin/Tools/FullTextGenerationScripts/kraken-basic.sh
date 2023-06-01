#!/bin/bash

### Kraken OCR generation script ###
# This script provides an uniform way to run OCR with Kraken on local or remote images

set -euo pipefail # exit on: error, undefined variable, pipefail

# Test fuction, for manually testing the script
function test() {
	wget https://digi.bib.uni-mannheim.de/fileadmin/digi/1652998276/max/1652998276_0001.jpg
	kraken --alto --input 1652998276_0001.jpg 1652998276_0001_basic.xml segment --baseline ocr
	exit 0;
}


# Paramaters:
while [ $# -gt 0 ] ; do
  case $1 in
	--page_id)			page_id="$2" ;;			#Page number
	--image_path)		image_path="$2" ;;		#Image path/URL
	--output_path)		output_path="$2" ;;		#Fulltextfile path
	--test)				test ;;
  esac
  shift
done


# Parse URL or Path and run tesseract:
regex='(https?|ftp|file)://[-[:alnum:]\+&@#/%?=~_|!:,.;]*[-[:alnum:]\+&@#/%=~_|]' #Regex for URL validation ( https://stackoverflow.com/a/3184819 )
if [[ (${image_path} =~ $regex) || (-f ${image_path}) ]] ; then # If image_path is a valid URL or a local file
	echo "Running OCR: kraken-basic"

	filename=$(basename ${image_path}) # extract filename from url
	wget ${image_path} -P fileadmin/_temp_/ocrTempFolder/images/
	kraken --alto --input "fileadmin/_temp_/ocrTempFolder/images/${filename}" "${output_path}.xml" segment --baseline ocr
	rm "fileadmin/_temp_/ocrTempFolder/images/${filename}"

	exit 0
else
	echo "File not found: ${image_path}"
	exit 2
fi
