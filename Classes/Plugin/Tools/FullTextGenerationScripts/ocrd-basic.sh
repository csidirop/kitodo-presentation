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
	--page_id)			page_id="$2" ;;			#Page number
	--image_path)		image_path="$2" ;;		#Image path/URL
	--output_path)		output_path="$2" ;;		#Fulltextfile path
	--test)				test ;;
  esac
  shift
done


# Parse URL or Path and run OCR:
regex='(https?|ftp|file)://[-[:alnum:]\+&@#/%?=~_|!:,.;]*[-[:alnum:]\+&@#/%=~_|]' #Regex for URL validation ( https://stackoverflow.com/a/3184819 )
if [[ (${image_path} =~ $regex) || (-f ${image_path}) ]] ; then # If image_path is a valid URL or a local file
	jobname=$(cksum <<< ${image_path} | cut -f 1 -d ' ') # speudorandom unique jobname
	filename=$(basename ${image_path}) # extract filename from url
	ocrdkitodo=ocrd-manager-UBMA #TODO fix: get it from system #OCRD kitodo/manager hostname
	echo "Running OCRD-OCR: with image $image_path outputpath $output_path on ${ocrdkitodo} in job ${jobname}"

	# start job:
	ssh ${ocrdkitodo} wget -P "/data/${jobname}/images/" "${image_path}"
	ssh ${ocrdkitodo} for_production.sh --proc-id 1 --lang deu --script Fraktur "${jobname}"

	# get fulltexts:
	scp ${ocrdkitodo}:"/data/${jobname}/ocr/alto/${filename::-4}.xml" "${output_path}.xml"

	# clean dir:
	ssh ${ocrdkitodo} rm -r "/data/${jobname}/"

	exit 0
else
	echo "File not found: ${image_path}"
	exit 2
fi
