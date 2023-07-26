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

# Check if METS-data is wrapped in an OAI node:
set +euo pipefail # unset error exits
grep -qzo '<OAI-PMH.*>.*</OAI-PMH>' mets.xml
oai=$?
set -euo pipefail # re set error exits
if [ $oai ] ; then
  # Extract the inner mets node from the OAI wrapper, make it valid xml and pretty print it:
    # xmlstarlet sel -t -c "//mets:mets" mets.xml > mets_tmp1.xml
    # echo '<?xml version="1.0" encoding="utf-8"?>' | cat - mets_tmp1.xml > mets_tmp2.xml
    # xmllint --format mets_tmp2.xml
  (echo '<?xml version="1.0" encoding="utf-8"?>' && xmlstarlet sel -t -c "//mets:mets" mets.xml) | xmllint --format - > mets_tmp.xml
  mv mets_tmp.xml mets.xml
fi

# Update METS with given ALTO file:
ocrd --log-level INFO workspace add --file-grp FULLTEXT --file-id "fulltext-$pageId" --page-id="$pageNum" --mimetype text/xml "$pageId.xml"
perl -pi -e 's/LOCTYPE="OTHER" OTHERLOCTYPE="FILE"/LOCTYPE="URL"/' mets.xml
perl -pi -e s,'"'$pageId'','"'$URL'/'$pageId'', mets.xml

# Validate METS:
#apt -y install libxml2-utils
#xmllint --noout --schema http://www.loc.gov/standards/mets/mets.xsd mets.xml

mv mets.xml $docLocalId.xml
