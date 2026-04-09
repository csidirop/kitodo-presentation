<?php

declare(strict_types=1);

namespace Kitodo\Dlf\Service;

use Kitodo\Dlf\Common\Solr\Solr;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Service class for finalizing the bootstrap viewer setup after installation and setup imports.
 */
final class InstallBootstrapService
{
    private const SITE_IDENTIFIER = 'kitodo-presentation';
    private const VIEWER_PAGE_TITLE = 'Viewer';
    private const CONFIGURATION_PAGE_TITLE = 'Kitodo Configuration';
    private const TEMPLATE_TITLE = 'Kitodo Presentation Bootstrap';
    private const BASIC_STATIC_FILE = 'EXT:dlf/Configuration/TypoScript/';
    private const BOOTSTRAP_STATIC_FILE = 'EXT:dlf/Configuration/TypoScript/Bootstrap/';
    private const VIEWER_CONTENT_ELEMENTS = [
        ['list_type' => 'dlf_navigation', 'colPos' => 10, 'sorting' => 128, 'header' => 'Viewer Navigation'],
        ['list_type' => 'dlf_toolbox', 'colPos' => 10, 'sorting' => 256, 'header' => 'Viewer Toolbox'],
        ['list_type' => 'dlf_pageview', 'colPos' => 20, 'sorting' => 128, 'header' => 'Page View'],
        ['list_type' => 'dlf_tableofcontents', 'colPos' => 30, 'sorting' => 128, 'header' => 'Contents'],
        ['list_type' => 'dlf_metadata', 'colPos' => 30, 'sorting' => 256, 'header' => 'Metadata'],
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly BootstrapConfigurationImportService $bootstrapConfigurationImportService,
    ) {}

    /**
     * Completes the bootstrap setup after the initial installation assets were imported.
     */
    public function finalizeInitialInstallation(): void
    {
        $rootPageId = $this->resolveRootPageId();
        if ($rootPageId === null) {
            return;
        }

        $viewerPage = $this->findPageByTitle($rootPageId, self::VIEWER_PAGE_TITLE);
        $configurationPage = $this->findPageByTitle($rootPageId, self::CONFIGURATION_PAGE_TITLE);

        if ($viewerPage === null || $configurationPage === null) {
            return;
        }

        $templateId = $this->ensureTemplate($rootPageId);
        $this->bootstrapConfigurationImportService->seed((int)$configurationPage['uid']);
        $solrCoreUid = $this->ensureSolrCore((int)$configurationPage['uid']);
        $this->ensureViewerContentElements((int)$viewerPage['uid']);

        $this->updateTemplate(
            $templateId,
            $rootPageId,
            (int)$viewerPage['uid'],
            (int)$configurationPage['uid'],
            $solrCoreUid
        );
    }


    /**
     * Resolves the imported bootstrap root page from the shipped site configuration.
     */
    private function resolveRootPageId(): ?int
    {
        try {
            return $this->siteFinder->getSiteByIdentifier(self::SITE_IDENTIFIER)->getRootPageId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function findPageByTitle(int $pid, string $title): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $row = $queryBuilder
            ->select('uid', 'pid', 'doktype')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter($title)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    /**
     * Creates or updates the root template and ensures the required static TypoScript includes are present.
     */
    private function ensureTemplate(int $rootPageId): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_template');
        $row = $queryBuilder
            ->select('uid', 'include_static_file')
            ->from('sys_template')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($rootPageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('sorting')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row !== false) {
            $this->updateRow('sys_template', (int)$row['uid'], [
                'include_static_file' => $this->mergeStaticFiles((string)$row['include_static_file']),
                'title' => self::TEMPLATE_TITLE,
                'root' => 1,
                'clear' => 3,
                'tstamp' => time(),
            ]);

            return (int)$row['uid'];
        }

        return $this->insertRow('sys_template', [
            'pid' => $rootPageId,
            'tstamp' => time(),
            'crdate' => time(),
            'deleted' => 0,
            'hidden' => 0,
            'sorting' => 256,
            'title' => self::TEMPLATE_TITLE,
            'root' => 1,
            'clear' => 3,
            'include_static_file' => $this->mergeStaticFiles(''),
            'constants' => '',
            'config' => '',
        ]);
    }

    /**
     * Creates a default Solr core record for the bootstrap configuration folder if possible.
     */
    private function ensureSolrCore(int $configurationPageId): ?int
    {
        $existing = $this->findOneByPid('tx_dlf_solrcores', $configurationPageId);
        if ($existing !== null) {
            return (int)$existing['uid'];
        }

        try {
            $indexName = Solr::createCore('');
        } catch (\Throwable) {
            return null;
        }

        if ($indexName === '') {
            return null;
        }

        return $this->insertRow('tx_dlf_solrcores', [
            'pid' => $configurationPageId,
            'tstamp' => time(),
            'crdate' => time(),
            'cruser_id' => 0,
            'deleted' => 0,
            'label' => 'Default Solr Core (PID ' . $configurationPageId . ')',
            'index_name' => $indexName,
        ]);
    }

    /**
     * Creates or updates the content elements needed by the standalone bootstrap viewer page.
     */
    private function ensureViewerContentElements(int $viewerPageId): void
    {
        foreach (self::VIEWER_CONTENT_ELEMENTS as $definition) {
            $existing = $this->findViewerContentElement(
                $viewerPageId,
                (string)$definition['list_type'],
                (int)$definition['colPos']
            );

            $data = [
                'pid' => $viewerPageId,
                'tstamp' => time(),
                'crdate' => time(),
                'deleted' => 0,
                'hidden' => 0,
                'CType' => 'list',
                'list_type' => (string)$definition['list_type'],
                'colPos' => (int)$definition['colPos'],
                'sorting' => (int)$definition['sorting'],
                'header' => (string)$definition['header'],
                'pi_flexform' => '',
                'sys_language_uid' => 0,
                'l18n_parent' => 0,
                'l18n_diffsource' => '',
            ];

            if ($existing !== null) {
                $this->updateRow('tt_content', (int)$existing['uid'], $data);
                continue;
            }

            $this->insertRow('tt_content', $data);
        }
    }

    private function findViewerContentElement(int $viewerPageId, string $listType, int $colPos): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $row = $queryBuilder
            ->select('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($viewerPageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter('list')),
                $queryBuilder->expr()->eq('list_type', $queryBuilder->createNamedParameter($listType)),
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter($colPos, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    /**
     * Rewrites the bootstrap template constants to the imported page IDs and optional Solr core.
     */
    private function updateTemplate(
        int $templateId,
        int $rootPageId,
        int $viewerPageId,
        int $configurationPageId,
        ?int $solrCoreUid
    ): void {
        $existingTemplate = $this->findByUid('sys_template', $templateId);
        $constants = [
            'plugin.tx_dlf.persistence.storagePid = ' . $configurationPageId,
            'plugin.tx_dlf.bootstrap.rootPid = ' . $rootPageId,
            'plugin.tx_dlf.bootstrap.viewerPid = ' . $viewerPageId,
        ];

        if ($solrCoreUid !== null) {
            $constants[] = 'plugin.tx_dlf.persistence.solrCoreUid = ' . $solrCoreUid;
        }

        $this->updateRow('sys_template', $templateId, [
            'tstamp' => time(),
            'include_static_file' => $this->mergeStaticFiles((string)($existingTemplate['include_static_file'] ?? '')),
            'constants' => implode(PHP_EOL, $constants),
        ]);
    }


    private function findOneByPid(string $table, int $pid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $row = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    private function findByUid(string $table, int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT))
            )
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

    /**
     * Merges the required base and bootstrap static TypoScript includes into the template record.
     */
    private function mergeStaticFiles(string $includeStaticFile): string
    {
        $files = array_filter(array_map('trim', explode(',', $includeStaticFile)));
        $files[] = self::BASIC_STATIC_FILE;
        $files[] = self::BOOTSTRAP_STATIC_FILE;
        return implode(',', array_values(array_unique($files)));
    }
}
