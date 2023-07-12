#!/bin/bash

### Update METS XML with given ALTO file ###
# This script provides an uniform way to update local METS XML files with new generated ALTO files

set -euo pipefail # exit on: error, undefined variable, pipefail

# Test fuction, for manually testing the script
function test() {

}


# Paramaters:
while [ $# -gt 0 ] ; do
  case $1 in
	--pageId)			pageId="$2" ;;			#Page ID (eg. log59088_1)
	--pageNum)			pageNum="$2" ;;			#Page number (eg. 1)
	--URL)				URL="$2" ;;				#URL
	--outputPath)		outputPath="$2" ;;		#Fulltextfile path
	--tmpImagePath)		tmpImagePath="$2" ;;	#Temporarily image path
	--test)				test ;;
  esac
  shift
done


# UPDATE METS:

mv NAME mets.xml

#temp
URL="http://localhost/fileadmin/fulltextFolder//URN/nbn/de/bsz/180/digosi/30/kraken-german_print"
pageNum = 999
#/temp

getDocLocalId = $(cut -d _ -f 1 <<< $pageID)

ocrd --log-level INFO workspace add -G FULLTEXT --file-id "fulltext-$pageId" --page-id="$pageNum" --mimetype text/xml "$pageId.xml"
perl -pi -e 's/LOCTYPE="OTHER" OTHERLOCTYPE="FILE"/LOCTYPE="URL"/' mets.xml
perl -pi -e s,'"log59088_','"'$URL'/log59088_', mets.xml

mv mets.xml NAME