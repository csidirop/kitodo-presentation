#!/bin/bash

### Main entry script for running the OCR-Engines, starting the OCR prosses and do everthing related ###

set -euo pipefail # exit on: error, undefined variable, pipefail

# Test function, for manually testing the script
function test() {
    CLR_B='\e[1;34m' # Bold Blue
    CLR_G='\e[32m' # Green
    CLR_R='\e[31m' # Red
    NC='\e[0m' # No Color

    if [ -d "typo3conf/ext/dlf/Classes/Plugin/Tools/FullTextGenerationScripts/" ]; then 
        cd typo3conf/ext/dlf/Classes/Plugin/Tools/FullTextGenerationScripts
    fi

    echo -e "${CLR_B}Starting tests:${NC}"
    ocrEngines_passed=()
    ocrEngines_failed=()
    # Iterate through all .sh files in the current directory:
    for file in *.sh; do
        if [ "$file" != "OCRmain.sh" ] && [ "$file" != "UpdateMets.sh" ]; then # exclude OCRmain.sh and UpdateMets.sh
            echo -e "${CLR_B}Running '$file --test':${NC}"
            set +euo pipefail # unset error exits
            bash "$file" "--test" # Run the ocrEngine in test mode
            success=$?
            set -euo pipefail # re-set error exits
            if [ "$success" == 0 ]; then
                echo -e "\n${CLR_G}$file: passed${NC}\n"
                ocrEngines_passed+=("$file")
            else
                echo -e "\n${CLR_R}$file: failed${NC}\n"
                ocrEngines_failed+=("$file")
            fi
        fi
    done

    echo -e "${CLR_B}Finished tests. Summering results:${NC}"

    echo -e "${CLR_G}${#ocrEngines_passed[@]} engines passed:${NC}"
    for engine in "${ocrEngines_passed[@]}"; do
        echo -e "\t $engine"
    done
    echo -e "${CLR_R}${#ocrEngines_failed[@]} engines failed:${NC}"
    for engine in "${ocrEngines_failed[@]}"; do
        echo -e "\t $engine"
    done

    exit 0;
}


# Paramaters:
while [ $# -gt 0 ] ; do
	case $1 in
		--ocrEngine)			ocrEngine="$2" ;;		# OCR-Engine to use
		--pageId)				pageId="$2" ;;			# Page ID (eg. log59088_1)
		--pageNum)				pageNum="$2" ;;			# Page number (eg. 1)
		--imagePath)			imagePath="$2" ;;		# Image path/URL
		--outputPath)			outputPath="$2" ;;		# Fulltextfile path
		--tmpOutputPath)		tmpOutputPath="$2" ;;	# Temporary Fulltextfile path
		--tmpImagePath)			tmpImagePath="$2" ;;	# Temporary image path
		--url)					url="$2" ;;				# Alto URL (e.g http://localhost/fileadmin/fulltextFolder//URN/nbn/de/bsz/180/digosi/27/tesseract-basic/log59088_1.xml)
		--ocrUpdateMets) 		ocrUpdateMets="$2" ;;	# Update METS XML with given ALTO file (1|0)
		--ocrIndexMets) 		ocrIndexMets="$2" ;;	# Index METS XML with updated METS XML (only if ocrUpdateMets is 1) (1|0)
		--test)					test ;;
	esac
		shift
done


SECONDS=0 #messure time

# Run given OCR-Engine:
$ocrEngine --pageId $pageId --pageNum $pageNum --imagePath $imagePath --outputPath $tmpOutputPath --tmpImagePath $tmpImagePath

# Move temporary output file to final location, if it is not already there:
if [ "$outputPath" != "$tmpOutputPath" ]; then 
	mkdir -p $(dirname $outputPath) # Create directory if it does not exist
	mv -v -f $tmpOutputPath.xml $outputPath
fi

# Update METS file:
if [ "$ocrUpdateMets" == "1" ]; then
	./typo3conf/ext/dlf/Classes/Plugin/Tools/FullTextGenerationScripts/UpdateMets.sh --pageId $pageId --pageNum $pageNum --outputPath $outputPath --url $url --ocrEngine $ocrEngine --ocrIndexMets $ocrIndexMets
fi

echo -e "OCR completed in $SECONDS seconds"

exit 0
