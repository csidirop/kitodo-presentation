<?php

declare(strict_types=1);

namespace Kitodo\Dlf\Service;

use Kitodo\Dlf\Common\Solr\Solr;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service class for running the manual bootstrap root setup.
 */
final class BootstrapRootSetupService
{
    private const SITE_IDENTIFIER_PREFIX = 'kitodo-presentation';
    private const ROOT_PAGE_TITLE_PREFIX = 'Kitodo Presentation';
    private const VIEWER_PAGE_TITLE = 'Viewer';
    private const CONFIGURATION_PAGE_TITLE = 'Kitodo Configuration';
    private const TEMPLATE_TITLE = 'Kitodo Presentation Bootstrap';
    private const BASIC_STATIC_FILE = 'EXT:dlf/Configuration/TypoScript/';
    private const BOOTSTRAP_STATIC_FILE = 'EXT:dlf/Configuration/TypoScript/Bootstrap/';
    private const SITE_CONFIGURATION_TEMPLATE = 'EXT:dlf/Resources/Private/Data/BootstrapSiteConfig.yaml';
    private const LEGACY_VIEWER_PLUGIN_TYPES = [
        'dlf_navigation',
        'dlf_toolbox',
        'dlf_pageview',
        'dlf_tableofcontents',
        'dlf_metadata',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteConfiguration $siteConfiguration,
        private readonly CacheManager $cacheManager,
        private readonly BootstrapConfigurationImportService $bootstrapConfigurationImportService,
    ) {}

    /**
     * Runs the manual setup for a new bootstrap root page tree.
     *
     * @param array{identifier?:mixed,base?:mixed,rootTitle?:mixed,rootSlug?:mixed,viewerSlug?:mixed} $options
     * @return array{siteIdentifier:string,siteBase:string,rootPageId:int,viewerPageId:int,configurationPageId:int,templateId:int,solrCoreUid:?int}
     */
    public function runSetup(array $options = []): array
    {
        $context = $this->buildSetupContext($options);

        $rootPageId = $this->createRootPage($context);
        $this->writeSiteConfiguration($context, $rootPageId);

        $configurationPageId = $this->createPage($rootPageId, self::CONFIGURATION_PAGE_TITLE, [
            'doktype' => 254,
            'sorting' => 256,
        ]);
        $viewerPageId = $this->createPage($rootPageId, self::VIEWER_PAGE_TITLE, [
            'slug' => $context['viewerPageSlug'],
            'sorting' => 512,
        ]);

        $templateId = $this->ensureTemplate($rootPageId);
        $this->bootstrapConfigurationImportService->seed($configurationPageId);
        $solrCoreUid = $this->ensureSolrCore($configurationPageId);
        $this->cleanupLegacyViewerContentElements($viewerPageId);
        $this->updateTemplate(
            $templateId,
            $rootPageId,
            $viewerPageId,
            $configurationPageId,
            $solrCoreUid
        );
        $this->cacheManager->flushCaches();

        return [
            'siteIdentifier' => $context['siteIdentifier'],
            'siteBase' => $context['siteBase'],
            'rootPageId' => $rootPageId,
            'viewerPageId' => $viewerPageId,
            'configurationPageId' => $configurationPageId,
            'templateId' => $templateId,
            'solrCoreUid' => $solrCoreUid,
        ];
    }

    /**
     * Determines the next unique bootstrap site identifier, titles and slugs.
     *
     * @param array{identifier?:mixed,base?:mixed,rootTitle?:mixed,rootSlug?:mixed,viewerSlug?:mixed} $options
     * @return array{index:int,siteIdentifier:string,siteBase:string,defaultLanguageBase:string,englishLanguageBase:string,rootPageTitle:string,rootPageSlug:string,viewerPageSlug:string}
     */
    private function buildSetupContext(array $options): array
    {
        $nextIndex = $this->determineNextGroupIndex();
        $customIdentifier = $this->normalizeIdentifierOption($options['identifier'] ?? null);
        $customBase = $this->normalizeBaseOption($options['base'] ?? null);
        $customRootTitle = $this->normalizeTextOption($options['rootTitle'] ?? null);
        $customRootSlug = $this->normalizeSlugOption($options['rootSlug'] ?? null, 'root-slug');
        $customViewerSlug = $this->normalizeSlugOption($options['viewerSlug'] ?? null, 'viewer-slug');

        $siteIdentifier = $customIdentifier ?? ($nextIndex === 1
            ? self::SITE_IDENTIFIER_PREFIX
            : self::SITE_IDENTIFIER_PREFIX . '-' . $nextIndex);
        $siteBase = $customBase ?? ($customIdentifier !== null
            ? '/' . $siteIdentifier . '/'
            : ($nextIndex === 1 ? '/' : '/' . $siteIdentifier . '/'));
        $rootPageTitle = $customRootTitle ?? ($nextIndex === 1
            ? self::ROOT_PAGE_TITLE_PREFIX
            : self::ROOT_PAGE_TITLE_PREFIX . ' ' . $nextIndex);
        $rootPageSlug = $customRootSlug ?? '/';
        $viewerPageSlug = $customViewerSlug ?? '/viewer';

        $this->assertSiteIdentifierAvailable($siteIdentifier);
        $this->assertRootTitleAvailable($rootPageTitle);
        $this->assertBaseCompatibleWithSlugs($siteBase, $rootPageSlug, $viewerPageSlug);

        return [
            'index' => $nextIndex,
            'siteIdentifier' => $siteIdentifier,
            'siteBase' => $siteBase,
            'defaultLanguageBase' => $siteBase,
            'englishLanguageBase' => rtrim($siteBase, '/') . '/en/',
            'rootPageTitle' => $rootPageTitle,
            'rootPageSlug' => $rootPageSlug,
            'viewerPageSlug' => $viewerPageSlug,
        ];
    }

    /**
     * Finds the next free numeric index for bootstrap groups by inspecting existing site folders and root pages.
     */
    private function determineNextGroupIndex(): int
    {
        $maxIndex = 0;
        $sitesPath = Environment::getConfigPath() . '/sites';
        if (is_dir($sitesPath)) {
            foreach ((array)scandir($sitesPath) as $entry) {
                if (!is_string($entry) || $entry === '.' || $entry === '..') {
                    continue;
                }

                if ($entry === self::SITE_IDENTIFIER_PREFIX) {
                    $maxIndex = max($maxIndex, 1);
                    continue;
                }

                if (preg_match('/^' . preg_quote(self::SITE_IDENTIFIER_PREFIX, '/') . '-(\d+)$/', $entry, $matches) === 1) {
                    $maxIndex = max($maxIndex, (int)$matches[1]);
                }
            }
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $rows = $queryBuilder
            ->select('title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchFirstColumn();

        foreach ($rows as $title) {
            if ($title === self::ROOT_PAGE_TITLE_PREFIX) {
                $maxIndex = max($maxIndex, 1);
                continue;
            }

            if (is_string($title) && preg_match('/^' . preg_quote(self::ROOT_PAGE_TITLE_PREFIX, '/') . ' (\d+)$/', $title, $matches) === 1) {
                $maxIndex = max($maxIndex, (int)$matches[1]);
            }
        }

        return $maxIndex + 1;
    }

    /**
     * Creates a fresh bootstrap root page for the next group.
     *
     * @param array{rootPageTitle:string,rootPageSlug:string} $context
     */
    private function createRootPage(array $context): int
    {
        return $this->insertRow('pages', [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'title' => $context['rootPageTitle'],
            'slug' => $context['rootPageSlug'],
            'is_siteroot' => 1,
            'sorting' => $this->nextSortingForPid(0),
        ]);
    }

    /**
     * Creates a child page below a freshly created bootstrap root page.
     *
     * @param array<string, int|string> $data
     */
    private function createPage(int $pid, string $title, array $data): int
    {
        return $this->insertRow('pages', array_merge([
            'pid' => $pid,
            'tstamp' => time(),
            'crdate' => time(),
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'title' => $title,
        ], $data));
    }

    /**
     * Writes a unique bootstrap site configuration for the freshly created root page.
     *
     * @param array{siteIdentifier:string,siteBase:string,defaultLanguageBase:string,englishLanguageBase:string} $context
     */
    private function writeSiteConfiguration(array $context, int $rootPageId): void
    {
        $templatePath = GeneralUtility::getFileAbsFileName(self::SITE_CONFIGURATION_TEMPLATE);
        $configuration = Yaml::parseFile($templatePath);
        if (!is_array($configuration)) {
            throw new \RuntimeException(sprintf('Bootstrap site template "%s" is invalid.', self::SITE_CONFIGURATION_TEMPLATE));
        }

        $configuration['base'] = $context['siteBase'];
        $configuration['rootPageId'] = $rootPageId;
        if (isset($configuration['languages'][0]) && is_array($configuration['languages'][0])) {
            $configuration['languages'][0]['base'] = $context['defaultLanguageBase'];
        }
        if (isset($configuration['languages'][1]) && is_array($configuration['languages'][1])) {
            $configuration['languages'][1]['base'] = $context['englishLanguageBase'];
        }

        $this->siteConfiguration->write($context['siteIdentifier'], $configuration);
    }

    /**
     * Ensures the chosen site identifier does not already exist.
     */
    private function assertSiteIdentifierAvailable(string $siteIdentifier): void
    {
        $siteDirectory = Environment::getConfigPath() . '/sites/' . $siteIdentifier;
        if (is_dir($siteDirectory)) {
            throw new \RuntimeException(sprintf('The site identifier "%s" already exists.', $siteIdentifier));
        }
    }

    /**
     * Ensures the chosen root title is not already used by another root page.
     */
    private function assertRootTitleAvailable(string $rootPageTitle): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $count = $queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter($rootPageTitle)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();

        if ((int)$count > 0) {
            throw new \RuntimeException(sprintf('The root page title "%s" is already in use.', $rootPageTitle));
        }
    }

    /**
     * Validates the optional base/slug combination conservatively.
     */
    private function assertBaseCompatibleWithSlugs(string $siteBase, string $rootSlug, string $viewerSlug): void
    {
        if ($siteBase !== '/' && $rootSlug !== '/') {
            throw new \RuntimeException('Custom root slugs are only supported together with the root site base "/".');
        }
        if ($viewerSlug === '/') {
            throw new \RuntimeException('The viewer slug must not be "/".');
        }
    }

    private function normalizeIdentifierOption(mixed $value): ?string
    {
        $value = $this->normalizeTextOption($value);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $value) !== 1) {
            throw new \RuntimeException('The --identifier value may only contain lowercase letters, numbers and hyphens.');
        }
        return $value;
    }

    private function normalizeBaseOption(mixed $value): ?string
    {
        $value = $this->normalizeTextOption($value);
        if ($value === null) {
            return null;
        }
        if (!str_starts_with($value, '/')) {
            throw new \RuntimeException('The --base value must start with "/".');
        }
        if (!str_ends_with($value, '/')) {
            $value .= '/';
        }
        return $value;
    }

    private function normalizeSlugOption(mixed $value, string $optionName): ?string
    {
        $value = $this->normalizeTextOption($value);
        if ($value === null) {
            return null;
        }
        if (!str_starts_with($value, '/')) {
            throw new \RuntimeException(sprintf('The --%s value must start with "/".', $optionName));
        }
        return rtrim($value, '/') ?: '/';
    }

    private function normalizeTextOption(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
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
     * Removes legacy bootstrap viewer content elements that are no longer used.
     */
    private function cleanupLegacyViewerContentElements(int $viewerPageId): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder
            ->delete('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($viewerPageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter('list')),
                $queryBuilder->expr()->in(
                    'list_type',
                    $queryBuilder->createNamedParameter(self::LEGACY_VIEWER_PLUGIN_TYPES, Connection::PARAM_STR_ARRAY)
                )
            )
            ->executeStatement();
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
     * Returns the next sorting value below a page.
     */
    private function nextSortingForPid(int $pid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $sorting = $queryBuilder
            ->selectLiteral('MAX(sorting)')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();

        return ((int)$sorting) + 256;
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
