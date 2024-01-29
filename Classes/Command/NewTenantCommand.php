<?php

/**
 * (c) Kitodo. Key to digital objects e.V. <contact@kitodo.org>
 *
 * This file is part of the Kitodo and TYPO3 projects.
 *
 * @license GNU General Public License version 3 or later.
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Kitodo\Dlf\Command;

use Kitodo\Dlf\Common\AbstractDocument;
use Kitodo\Dlf\Command\BaseCommand;
use Kitodo\Dlf\Common\Indexer;
use Kitodo\Dlf\Domain\Model\Document;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * CLI Command for setting up new tenant structures.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class NewTenantCommand extends BaseCommand
{

    /**
     * Configure the command by defining the name, options and arguments
     *
     * @access public
     *
     * @return void
     */
    public function configure(): void
    {
        $this
            ->setDescription('Setup new tenant structures.')
            ->setHelp('')
            ->addOption(
                'pid',
                'p',
                InputOption::VALUE_REQUIRED,
                'UID of the Kitodo configuration storage folder.'
            )
            ->addOption(
                'solr',
                's',
                InputOption::VALUE_REQUIRED,
                '[UID|index_name] of the Solr core the document should be added to.'
            )
            ->addOption(
                'create-all',
                null,
                InputOption::VALUE_NONE,
                'This option will create the new tenant structures and metadata.'
            )
            ->addOption(
                'create-structures',
                null,
                InputOption::VALUE_NONE,
                'This option will create the new tenant structures.'
            )
            ->addOption(
                'create-metadata',
                null,
                InputOption::VALUE_NONE,
                'This option will create the new tenant metadata.'
            );
    }

    /**
     * Executes the command setting up new tenant structures.
     *
     * @access protected
     *
     * @param InputInterface $input The input parameters
     * @param OutputInterface $output The Symfony interface for outputs on console
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());

        $this->initializeRepositories($input->getOption('pid'));

        # Check if storagePid is valid:
        if ($this->storagePid == 0) {
            $io->error('ERROR: No valid PID (' . $this->storagePid . ') given.');
            return 1;
        }

        # Check if solr core is valid:
        if (
            !empty($input->getOption('solr'))
            && !is_array($input->getOption('solr'))
        ) {
            $allSolrCores = $this->getSolrCores($this->storagePid);
            $solrCoreUid = $this->getSolrCoreUid($allSolrCores, $input->getOption('solr'));

            // Abort if solrCoreUid is empty or not in the array of allowed solr cores.
            if (empty($solrCoreUid) || !in_array($solrCoreUid, $allSolrCores)) {
                $outputSolrCores = [];
                foreach ($allSolrCores as $indexName => $uid) {
                    $outputSolrCores[] = $uid . ' : ' . $indexName;
                }
                if (empty($outputSolrCores)) {
                    $io->error('ERROR: No valid Solr core ("' . $input->getOption('solr') . '") given. No valid cores found on PID ' . $this->storagePid . ".\n");
                    return 1;
                } else {
                    $io->error('ERROR: No valid Solr core ("' . $input->getOption('solr') . '") given. ' . "Valid cores are (<uid>:<index_name>):\n" . implode("\n", $outputSolrCores) . "\n");
                    return 1;
                }
            }
        } else {
            $io->error('ERROR: Required parameter --solr|-s is missing or array.');
            return 1;
        }

        if(!$input->getOption('create-all')) {
        } else {
            $this->createStructures($input, $io);
            $this->createMetadata($input, $io);
            $io->success('All done!');
            return 0;
        }

        $paramterSet = 0;

        if(!$input->getOption('create-structures')) {
        } else {
            $this->createStructures($input, $io);
            $paramterSet++;
        }

        if(!$input->getOption('create-metadata')) {
        } else {
            $this->createMetadata($input, $io);
            $paramterSet++;
        }

        if ($paramterSet == 0) {
            $io->error('ERROR: No valid parameter set.');
            return 1;
        }

        $io->success('All done!');

        return 0;
    }

    /**
     * Create structures for new tenant.
     *
     * @access private
     *
     * @param InputInterface $input The input parameters
     * @param SymfonyStyle $io
     *
     * @return void
     */
    private function createStructures($input, $io): void
    {
        // $tenant = new \Kitodo\Dlf\Controller\Backend\NewTenantController();
        // $tenant->initializeAction();
        // $tenant->addStructureAction();
        $io->success('Structures created!');
    }

    /**
     * Create metadata for new tenant.
     *
     * @access private
     *
     * @param InputInterface $input The input parameters
     * @param SymfonyStyle $io
     *
     * @return void
     */
    private function createMetadata($input, $io): void
    {
        // $tenant = new \Kitodo\Dlf\Controller\Backend\NewTenantController();
        // $tenant->addMetadataAction();
        $io->success('Metadata created!');
    }
}
