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
	--pageId)			pageId="$2" ;;			#Page number
	--imagePath)		imagePath="$2" ;;		#Image path/URL
	--outputPath)		outputPath="$2" ;;		#Fulltextfile path
	--tmpImagePath)		tmpImagePath="$2" ;;	#Temporarily image path
	--test)				test ;;
  esac
  shift
done


# Parse URL or Path and run tesseract:
regex='(https?|ftp|file)://[-[:alnum:]\+&@#/%?=~_|!:,.;]*[-[:alnum:]\+&@#/%=~_|]' #Regex for URL validation ( https://stackoverflow.com/a/3184819 )
if [[ (${imagePath} =~ $regex) || (-f ${imagePath}) ]] ; then # If imagePath is a valid URL or a local file
	echo "Running OCR: kraken-basic"

	filename=$(basename ${imagePath}) # extract filename from url
	wget ${imagePath} -P "$tmpImagePath"
	kraken --alto --input "${tmpImagePath}/${filename}" "${outputPath}.xml" segment --baseline ocr
	rm "${tmpImagePath}/${filename}"

	exit 0
else
	echo "File not found: ${imagePath}"
	exit 2
fi
