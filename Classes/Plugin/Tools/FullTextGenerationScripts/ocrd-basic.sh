#!/bin/bash

### OCRD OCR generation script ###
# This script provides an uniform way to run OCR with OCRD on local or remote images

set -euo pipefail # exit on: error, undefined variable, pipefail

# Test fuction, for manually testing the script
function test() {
	ocrdkitodo=ocrd-manager-UBMA
	ssh ${ocrdkitodo} wget -P /data/production-test-from-docker/images/ "https://digi.bib.uni-mannheim.de/fileadmin/digi/1652998276/max/1652998276_0001.jpg"
	ssh ${ocrdkitodo} for_production.sh --proc-id 1 --lang deu --script Fraktur "production-test-from-docker"
	scp ${ocrdkitodo}:"/data/production-test-from-docker/ocr/alto/1652998276_0001.xml" "./1652998276_0001_ocrd-basic.xml"
	ssh ${ocrdkitodo} rm -r "/data/production-test-from-docker/"
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


# Parse URL or Path and run OCR:
regex='(https?|ftp|file)://[-[:alnum:]\+&@#/%?=~_|!:,.;]*[-[:alnum:]\+&@#/%=~_|]' #Regex for URL validation ( https://stackoverflow.com/a/3184819 )
if [[ (${imagePath} =~ $regex) || (-f ${imagePath}) ]] ; then # If imagePath is a valid URL or a local file
	jobname=$(cksum <<< ${imagePath} | cut -f 1 -d ' ') # speudorandom unique jobname
	filename=$(basename ${imagePath}) # extract filename from url
	ocrdkitodo=ocrd-manager-UBMA #TODO fix: get it from system #OCRD kitodo/manager hostname
	echo "Running OCRD-OCR: with image $imagePath outputpath $outputPath on ${ocrdkitodo} in job ${jobname}"

	# start job:
	ssh ${ocrdkitodo} wget -P "/data/${jobname}/images/" "${imagePath}"
	ssh ${ocrdkitodo} for_production.sh --proc-id 1 --lang deu --script Fraktur "${jobname}"

	# get fulltexts:
	scp ${ocrdkitodo}:"/data/${jobname}/ocr/alto/${filename::-4}.xml" "${outputPath}.xml"

	# clean dir:
	ssh ${ocrdkitodo} rm -r "/data/${jobname}/"

	exit 0
else
	echo "File not found: ${imagePath}"
	exit 2
fi
