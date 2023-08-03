#!/bin/bash

### Update METS XML with given ALTO file ###
# This script provides an uniform way to update local METS XML files with new generated ALTO files

set -euo pipefail # exit on: error, undefined variable, pipefail

# Paramaters:
while [ $# -gt 0 ] ; do
  case $1 in
  --pageId)       pageId="$2" ;;     #Page ID (eg. log59088_1)
  --url)          url="$2" ;;        #URL
  --outputPath)   outputPath="$2" ;; #Fulltextfile path
  --ocrEngine)    ocrEngine="$2" ;;  #OCR-Engine
  esac
  shift
done

# UPDATE METS:

# Extract same values from parameters:
docLocalId=$(rev <<< "$pageId" | cut -d _ -f 2- | rev) # (eg. log59088)
pageNum=$(rev <<< "$pageId" | cut -d _ -f 1 | rev) # (eg. 1)
outputFolder=$(rev <<< "$outputPath" | cut -d / -f 2- | rev)
ocrEngine=$(rev <<< "$ocrEngine" | cut -d '/' -f 1 | cut -d '.' -f 2- | rev) # (eg. tesseract-basic)

cd $outputFolder
mv $docLocalId.xml $docLocalId.xml.backup # Backup METS
cp $docLocalId.xml.backup mets.xml

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
# mm-update add-file -G FULLTEXT -m text/xml -u $url $pageId.xml  # Add ALTO file to METS via mm-update
ocrd --log-level INFO workspace add --file-grp FULLTEXT --file-id "fulltext-$pageId" --page-id="$pageNum" --mimetype text/xml "$pageId.xml"
sed -i 's/LOCTYPE="OTHER" OTHERLOCTYPE="FILE"/LOCTYPE="URL"/' mets.xml # Replace LOCTYPE OTHER with URL
sed -i s,"\"$pageId.xml","\"$url", mets.xml # Replace ALTO file path with URL
xmlstarlet ed -L -a "//mets:file[@ID='fulltext-$pageId']" -t attr -n "CREATED" -v "$(date +%Y-%m-%dT%H:%M:%S%z)" mets.xml # Add Date attribute to file node
xmlstarlet ed -L -a "//mets:file[@ID='fulltext-$pageId']" -t attr -n "SOFTWARE" -v "DFG-Viewer-OCR-On-Demand-$ocrEngine" mets.xml # Add OCR-ENGINE attribute to file node

# Validate METS:
#apt -y install libxml2-utils
#xmllint --noout --schema http://www.loc.gov/standards/mets/mets.xsd mets.xml

mv mets.xml $docLocalId.xml
