#!/bin/bash

### Main entry script for running the OCR-Engines, starting the OCR prosses and do everthing related ###

set -euo pipefail # exit on: error, undefined variable, pipefail

# Test fuction, for manually testing the script
function test() { 
	CLR_G='\e[32m' # Green
	NC='\e[0m' # No Color

	if [ -d "typo3conf/ext/dlf/Classes/Plugin/Tools/FullTextGenerationScripts/" ]; then 
		cd typo3conf/ext/dlf/Classes/Plugin/Tools/FullTextGenerationScripts
	fi
	echo -e "Starting tests:"
	echo -e "tesseract-basc.sh:"
	./tesseract-basic.sh --test
	echo -e "${CLR_G}tesseract-basic.sh: OK${NC}"
	echo -e "kraken-basic.sh:"
	./kraken-basic.sh --test
	echo -e "${CLR_G}kraken-basic.sh: OK${NC}"
	echo -e "ocrd-basic.sh:"
	./ocrd-basic.sh --test
	echo -e "${CLR_G}ocrd-basic.sh: OK${NC}"
	echo -e "${CLR_G}All tests passed${NC}"

	exit 0;
}


# Paramaters:
while [ $# -gt 0 ] ; do
	case $1 in
		--ocrEngine)			ocrEngine="$2" ;;		# OCR-Engine to use
		--pageId)				pageId="$2" ;;			# Page number
		--imagePath)			imagePath="$2" ;;		# Image path/URL
		--outputPath)			outputPath="$2" ;;		# Fulltextfile path
		--tmpOutputPath)		tmpOutputPath="$2" ;;	# Temporary Fulltextfile path
		--tmpImagePath)			tmpImagePath="$2" ;;	# Temporary image path
		--test)					test ;;
	esac
		shift
done


# Run given OCR-Engine:
$ocrEngine --pageId $pageId --imagePath $imagePath --outputPath $tmpOutputPath --tmpImagePath $tmpImagePath

# Move temporary output file to final location, if it is not already there:
if [ "$outputPath" != "$tmpOutputPath" ]; then 
	mv -v -f $tmpOutputPath.xml $outputPath
fi

# Update METS file:
./typo3conf/ext/dlf/Classes/Plugin/Tools/FullTextGenerationScripts/UpdateMets.sh --pageId $pageId --outputPath $outputPath 

exit 0
