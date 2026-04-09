<?php

declare(strict_types=1);

namespace Kitodo\Dlf\Service;

use Kitodo\Dlf\Format\AudioVideoMD;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service class for importing the bundled bootstrap configuration dataset into the configuration folder.
 */
final class BootstrapConfigurationImportService
{
    private const DATASET_FILE = 'EXT:dlf/Resources/Private/Data/BootstrapConfigurationDefaults.json';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Seeds the bootstrap configuration folder with the bundled bootstrap configuration defaults.
     */
    public function seed(int $configurationPageId): void
    {
        $dataset = $this->loadDataset();
        if ($dataset === []) {
            return;
        }

        $formatUidMap = $this->seedFormats($configurationPageId, $dataset['formats'] ?? []);
        $metadataUidMap = $this->seedMetadata($configurationPageId, $dataset['metadata'] ?? []);
        $metadataFormatUidMap = $this->seedMetadataFormats(
            $configurationPageId,
            $dataset['metadata_formats'] ?? [],
            $metadataUidMap,
            $formatUidMap
        );
        $this->seedMetadataSubentries(
            $configurationPageId,
            $dataset['metadata_subentries'] ?? [],
            $metadataFormatUidMap
        );
        $this->seedStructures($configurationPageId, $dataset['structures'] ?? []);
    }

    /**
     * Loads and decodes the shipped bootstrap configuration dataset.
     *
     * @return array<string, mixed>
     */
    private function loadDataset(): array
    {
        $filePath = GeneralUtility::getFileAbsFileName(self::DATASET_FILE);
        if (!is_file($filePath)) {
            return [];
        }

        $contents = file_get_contents($filePath);
        if (!is_string($contents)) {
            return [];
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Creates or updates format records and returns a map from exported UIDs to local UIDs.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function seedFormats(int $configurationPageId, array $rows): array
    {
        $uidMap = [];

        foreach ($rows as $row) {
            $existing = $this->findOneByFields(
                'tx_dlf_formats',
                [
                    'pid' => $configurationPageId,
                    'type' => (string)$row['type'],
                ]
            );

            $data = [
                'pid' => $configurationPageId,
                'tstamp' => time(),
                'crdate' => time(),
                'cruser_id' => 0,
                'deleted' => 0,
                'type' => (string)$row['type'],
                'root' => (string)$row['root'],
                'namespace' => (string)$row['namespace'],
                'class' => $this->normalizeFormatClass((string)$row['type'], (string)$row['class']),
            ];

            if ($existing !== null) {
                $this->updateRow('tx_dlf_formats', (int)$existing['uid'], $data);
                $uidMap[(int)$row['old_uid']] = (int)$existing['uid'];
                continue;
            }

            $uidMap[(int)$row['old_uid']] = $this->insertRow('tx_dlf_formats', $data);
        }

        return $uidMap;
    }

    /**
     * Creates or updates metadata records and returns a map from exported UIDs to local UIDs.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function seedMetadata(int $configurationPageId, array $rows): array
    {
        $uidMap = [];

        foreach ($rows as $row) {
            $existing = $this->findOneByFields(
                'tx_dlf_metadata',
                [
                    'pid' => $configurationPageId,
                    'sys_language_uid' => 0,
                    'index_name' => (string)$row['index_name'],
                ]
            );

            $data = [
                'pid' => $configurationPageId,
                'tstamp' => time(),
                'crdate' => time(),
                'cruser_id' => 0,
                'deleted' => 0,
                'sys_language_uid' => 0,
                'l18n_parent' => 0,
                'l18n_diffsource' => '',
                'hidden' => 0,
                'sorting' => (int)$row['sorting'],
                'label' => (string)$row['label'],
                'index_name' => (string)$row['index_name'],
                'default_value' => (string)$row['default_value'],
                'wrap' => (string)$row['wrap'],
                'index_tokenized' => (int)$row['index_tokenized'],
                'index_stored' => (int)$row['index_stored'],
                'index_indexed' => (int)$row['index_indexed'],
                'index_boost' => (float)$row['index_boost'],
                'is_sortable' => (int)$row['is_sortable'],
                'is_facet' => (int)$row['is_facet'],
                'is_listed' => (int)$row['is_listed'],
                'index_autocomplete' => (int)$row['index_autocomplete'],
                'status' => (int)$row['status'],
            ];

            if ($existing !== null) {
                $this->updateRow('tx_dlf_metadata', (int)$existing['uid'], $data);
                $uidMap[(int)$row['old_uid']] = (int)$existing['uid'];
                continue;
            }

            $uidMap[(int)$row['old_uid']] = $this->insertRow('tx_dlf_metadata', $data);
        }

        return $uidMap;
    }

    /**
     * Creates or updates metadata-to-format mappings using the remapped local UIDs.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, int> $metadataUidMap
     * @param array<int, int> $formatUidMap
     * @return array<int, int>
     */
    private function seedMetadataFormats(
        int $configurationPageId,
        array $rows,
        array $metadataUidMap,
        array $formatUidMap
    ): array {
        $uidMap = [];

        foreach ($rows as $row) {
            $parentUid = $metadataUidMap[(int)$row['parent_old_uid']] ?? null;
            $encodedUid = $formatUidMap[(int)$row['encoded_old_uid']] ?? null;

            if ($parentUid === null || $encodedUid === null) {
                continue;
            }

            $existing = $this->findOneByFields(
                'tx_dlf_metadataformat',
                [
                    'pid' => $configurationPageId,
                    'parent_id' => $parentUid,
                    'encoded' => $encodedUid,
                    'xpath' => (string)$row['xpath'],
                ]
            );

            $data = [
                'pid' => $configurationPageId,
                'tstamp' => time(),
                'crdate' => time(),
                'cruser_id' => 0,
                'deleted' => 0,
                'parent_id' => $parentUid,
                'encoded' => $encodedUid,
                'xpath' => (string)$row['xpath'],
                'xpath_sorting' => (string)$row['xpath_sorting'],
                'subentries' => (int)$row['subentries'],
                'mandatory' => (int)$row['mandatory'],
            ];

            if ($existing !== null) {
                $this->updateRow('tx_dlf_metadataformat', (int)$existing['uid'], $data);
                $uidMap[(int)$row['old_uid']] = (int)$existing['uid'];
                continue;
            }

            $uidMap[(int)$row['old_uid']] = $this->insertRow('tx_dlf_metadataformat', $data);
        }

        return $uidMap;
    }

    /**
     * Creates or updates metadata subentries for the remapped metadata-format relations.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, int> $metadataFormatUidMap
     */
    private function seedMetadataSubentries(
        int $configurationPageId,
        array $rows,
        array $metadataFormatUidMap
    ): void {
        foreach ($rows as $row) {
            $parentUid = $metadataFormatUidMap[(int)$row['parent_old_uid']] ?? null;
            if ($parentUid === null) {
                continue;
            }

            $existing = $this->findOneByFields(
                'tx_dlf_metadatasubentries',
                [
                    'pid' => $configurationPageId,
                    'parent_id' => $parentUid,
                    'sys_language_uid' => 0,
                    'index_name' => (string)$row['index_name'],
                    'xpath' => (string)$row['xpath'],
                ]
            );

            $data = [
                'pid' => $configurationPageId,
                'parent_id' => $parentUid,
                'tstamp' => time(),
                'crdate' => time(),
                'cruser_id' => 0,
                'deleted' => 0,
                'sys_language_uid' => 0,
                'l18n_parent' => 0,
                'l18n_diffsource' => '',
                'hidden' => (int)$row['hidden'],
                'sorting' => (int)$row['sorting'],
                'label' => (string)$row['label'],
                'index_name' => (string)$row['index_name'],
                'xpath' => (string)$row['xpath'],
                'default_value' => (string)$row['default_value'],
                'wrap' => (string)$row['wrap'],
            ];

            if ($existing !== null) {
                $this->updateRow('tx_dlf_metadatasubentries', (int)$existing['uid'], $data);
                continue;
            }

            $this->insertRow('tx_dlf_metadatasubentries', $data);
        }
    }

    /**
     * Creates or updates structure records for the bootstrap configuration folder.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function seedStructures(int $configurationPageId, array $rows): void
    {
        foreach ($rows as $row) {
            $existing = $this->findOneByFields(
                'tx_dlf_structures',
                [
                    'pid' => $configurationPageId,
                    'sys_language_uid' => 0,
                    'index_name' => (string)$row['index_name'],
                ]
            );

            $data = [
                'pid' => $configurationPageId,
                'tstamp' => time(),
                'crdate' => time(),
                'cruser_id' => 0,
                'deleted' => 0,
                'sys_language_uid' => 0,
                'l18n_parent' => 0,
                'l18n_diffsource' => '',
                'hidden' => (int)$row['hidden'],
                'toplevel' => (int)$row['toplevel'],
                'label' => (string)$row['label'],
                'index_name' => (string)$row['index_name'],
                'oai_name' => (string)$row['oai_name'],
                'thumbnail' => (int)$row['thumbnail'],
                'status' => (int)$row['status'],
            ];

            if ($existing !== null) {
                $this->updateRow('tx_dlf_structures', (int)$existing['uid'], $data);
                continue;
            }

            $this->insertRow('tx_dlf_structures', $data);
        }
    }

    /**
     * Replaces exported format classes with runtime-safe local class names where needed.
     */
    private function normalizeFormatClass(string $type, string $className): string
    {
        if ($type === 'AUDIOMD') {
            return AudioVideoMD::class;
        }

        return $className;
    }

    /**
     * Finds a single existing record by a stable field set for upsert matching.
     * 
     * @param array<string, int|string> $conditions
     * @return array<string, mixed>|null
     */
    private function findOneByFields(string $table, array $conditions): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->select('uid')->from($table);

        $expressions = [
            $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
        ];

        foreach ($conditions as $field => $value) {
            $parameterType = is_int($value) ? Connection::PARAM_INT : Connection::PARAM_STR;
            $expressions[] = $queryBuilder->expr()->eq(
                $field,
                $queryBuilder->createNamedParameter($value, $parameterType)
            );
        }

        $row = $queryBuilder
            ->where(...$expressions)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertRow(string $table, array $data): int
    {
        $connection = $this->connectionPool->getConnectionForTable($table);
        $connection->insert($table, $data);

        return (int)$connection->lastInsertId($table);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updateRow(string $table, int $uid, array $data): void
    {
        $connection = $this->connectionPool->getConnectionForTable($table);
        $connection->update($table, $data, ['uid' => $uid]);
    }
}
