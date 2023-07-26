#!/bin/bash

### Update METS XML with given ALTO file ###
# This script provides an uniform way to update local METS XML files with new generated ALTO files

set -euo pipefail # exit on: error, undefined variable, pipefail

# Paramaters:
while [ $# -gt 0 ] ; do
  case $1 in
  --pageId)       pageId="$2" ;;     #Page ID (eg. log59088_1)
  --URL)          URL="$2" ;;        #URL
  --outputPath)   outputPath="$2" ;; #Fulltextfile path
  esac
  shift
done

# UPDATE METS:
docLocalId=$(rev <<< "$pageId" | cut -d _ -f 2- | rev) # (eg. log59088)
pageNum=$(rev <<< "$pageId" | cut -d _ -f 1 | rev) # (eg. 1)
outputFolder=$(rev <<< "$outputPath" | cut -d / -f 2- | rev)
#temp
URL="http://localhost/fileadmin/fulltextFolder//URN/nbn/de/bsz/180/digosi/30/kraken-german_print"
#/temp

cd $outputFolder
mv $docLocalId.xml mets.xml

ocrd --log-level INFO workspace add --file-grp FULLTEXT --file-id "fulltext-$pageId" --page-id="$pageNum" --mimetype text/xml "$pageId.xml"
perl -pi -e 's/LOCTYPE="OTHER" OTHERLOCTYPE="FILE"/LOCTYPE="URL"/' mets.xml
perl -pi -e s,'"log59088_','"'$URL'/log59088_', mets.xml

mv mets.xml $docLocalId.xml
