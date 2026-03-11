<?php

declare(strict_types=1);

namespace Kitodo\Dlf\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ConfigurationSeedService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function seedDefaults(int $storagePid): void
    {
        $this->seedFormats($storagePid);
        $this->seedStructures($storagePid);
        $this->seedMetadata($storagePid);
    }

    private function seedFormats(int $storagePid): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_dlf_formats');
        $existingTypes = $connection->createQueryBuilder()
            ->select('type')
            ->from('tx_dlf_formats')
            ->executeQuery()
            ->fetchFirstColumn();

        $defaults = $this->loadJsonFile('EXT:dlf/Resources/Private/Data/FormatDefaults.json');
        $now = time();
        foreach ($defaults as $type => $values) {
            if (in_array($type, $existingTypes, true)) {
                continue;
            }
            $connection->insert('tx_dlf_formats', [
                'pid' => $storagePid,
                'tstamp' => $now,
                'crdate' => $now,
                'cruser_id' => 0,
                'deleted' => 0,
                'type' => $type,
                'root' => $values['root'],
                'namespace' => $values['namespace'],
                'class' => $values['class'],
            ]);
        }
    }

    private function seedStructures(int $storagePid): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_dlf_structures');
        $count = (int)$connection->createQueryBuilder()
            ->count('uid')
            ->from('tx_dlf_structures')
            ->where('pid = ' . (int)$storagePid)
            ->executeQuery()
            ->fetchOne();
        if ($count > 0) {
            return;
        }

        $defaults = $this->loadJsonFile('EXT:dlf/Resources/Private/Data/StructureDefaults.json');
        $now = time();
        foreach ($defaults as $indexName => $values) {
            $connection->insert('tx_dlf_structures', [
                'pid' => $storagePid,
                'tstamp' => $now,
                'crdate' => $now,
                'cruser_id' => 0,
                'deleted' => 0,
                'sys_language_uid' => 0,
                'l18n_parent' => 0,
                'l18n_diffsource' => '',
                'hidden' => 0,
                'toplevel' => (int)$values['toplevel'],
                'label' => $this->humanize($indexName),
                'index_name' => $indexName,
                'oai_name' => $values['oai_name'],
                'thumbnail' => 0,
                'status' => 0,
            ]);
        }
    }

    private function seedMetadata(int $storagePid): void
    {
        $metadataConnection = $this->connectionPool->getConnectionForTable('tx_dlf_metadata');
        $count = (int)$metadataConnection->createQueryBuilder()
            ->count('uid')
            ->from('tx_dlf_metadata')
            ->where('pid = ' . (int)$storagePid)
            ->executeQuery()
            ->fetchOne();
        if ($count > 0) {
            return;
        }

        $formats = $this->fetchFormatMap();
        $metadataFormatConnection = $this->connectionPool->getConnectionForTable('tx_dlf_metadataformat');
        $defaults = $this->loadJsonFile('EXT:dlf/Resources/Private/Data/MetadataDefaults.json');
        $now = time();

        foreach ($defaults as $indexName => $values) {
            $metadataConnection->insert('tx_dlf_metadata', [
                'pid' => $storagePid,
                'tstamp' => $now,
                'crdate' => $now,
                'cruser_id' => 0,
                'deleted' => 0,
                'sys_language_uid' => 0,
                'l18n_parent' => 0,
                'l18n_diffsource' => '',
                'hidden' => 0,
                'sorting' => (int)($values['sorting'] ?? 0),
                'label' => $this->humanize($indexName),
                'index_name' => $indexName,
                'format' => 0,
                'default_value' => (string)($values['default_value'] ?? ''),
                'wrap' => (string)($values['wrap'] ?? ''),
                'index_tokenized' => (int)($values['index_tokenized'] ?? 0),
                'index_stored' => (int)($values['index_stored'] ?? 0),
                'index_indexed' => (int)($values['index_indexed'] ?? 0),
                'index_boost' => (float)($values['index_boost'] ?? 1.0),
                'is_sortable' => (int)($values['is_sortable'] ?? 0),
                'is_facet' => (int)($values['is_facet'] ?? 0),
                'is_listed' => (int)($values['is_listed'] ?? 0),
                'index_autocomplete' => (int)($values['index_autocomplete'] ?? 0),
                'status' => 0,
            ]);
            $metadataUid = (int)$metadataConnection->lastInsertId('tx_dlf_metadata');

            $formatUids = [];
            foreach ($values['format'] ?? [] as $formatConfiguration) {
                $formatRoot = (string)($formatConfiguration['format_root'] ?? '');
                if ($formatRoot === '' || !isset($formats[$formatRoot])) {
                    continue;
                }
                $metadataFormatConnection->insert('tx_dlf_metadataformat', [
                    'pid' => $storagePid,
                    'tstamp' => $now,
                    'crdate' => $now,
                    'cruser_id' => 0,
                    'deleted' => 0,
                    'parent_id' => $metadataUid,
                    'encoded' => $formats[$formatRoot],
                    'xpath' => (string)($formatConfiguration['xpath'] ?? ''),
                    'xpath_sorting' => (string)($formatConfiguration['xpath_sorting'] ?? ''),
                    'subentries' => 0,
                    'mandatory' => 0,
                ]);
                $formatUids[] = (string)$metadataFormatConnection->lastInsertId('tx_dlf_metadataformat');
            }

            $metadataConnection->update(
                'tx_dlf_metadata',
                ['format' => implode(',', $formatUids)],
                ['uid' => $metadataUid]
            );
        }
    }

    /**
     * @return array<string, int>
     */
    private function fetchFormatMap(): array
    {
        $rows = $this->connectionPool->getConnectionForTable('tx_dlf_formats')
            ->createQueryBuilder()
            ->select('uid', 'root')
            ->from('tx_dlf_formats')
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['root']] = (int)$row['uid'];
        }
        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJsonFile(string $fileReference): array
    {
        $path = GeneralUtility::getFileAbsFileName($fileReference);
        $contents = file_get_contents($path);
        return is_string($contents) ? (json_decode($contents, true) ?? []) : [];
    }

    private function humanize(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}
