<?php

declare(strict_types=1);

namespace Kitodo\Dlf\Service;

use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ViewerBootstrapService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteConfiguration $siteConfiguration,
        private readonly ConfigurationSeedService $configurationSeedService,
    ) {
    }

    /**
     * @param array{
     *   parentPid?: int,
     *   siteIdentifier?: string,
     *   siteBase?: ?string,
     *   siteTitle?: string,
     *   rootTitle?: string,
     *   viewerTitle?: string,
     *   viewerSlug?: string,
     *   storageTitle?: string
     * } $options
     * @return array{rootPageId:int,viewerPageId:int,storagePageId:int,siteIdentifier:string,dataFolder:string}
     */
    public function bootstrap(array $options = []): array
    {
        $parentPid = (int)($options['parentPid'] ?? 0);
        $siteTitle = trim((string)($options['siteTitle'] ?? 'Kitodo.Presentation'));
        $rootTitle = trim((string)($options['rootTitle'] ?? $siteTitle));
        $viewerTitle = trim((string)($options['viewerTitle'] ?? 'Viewer'));
        $viewerSlug = trim((string)($options['viewerSlug'] ?? 'viewer'));
        $storageTitle = trim((string)($options['storageTitle'] ?? 'Kitodo Configuration'));
        $siteIdentifier = $this->buildUniqueSiteIdentifier((string)($options['siteIdentifier'] ?? $siteTitle));
        $siteBase = $this->normalizeSiteBase($options['siteBase'] ?? null);
        $rootSlug = '/';
        if ($viewerSlug === '') {
            $viewerSlug = 'viewer';
        }

        $rootPageId = $this->createPage([
            'pid' => $parentPid,
            'title' => $rootTitle,
            'slug' => $rootSlug,
            'doktype' => 1,
            'is_siteroot' => 1,
        ]);

        $viewerPageId = $this->createPage([
            'pid' => $rootPageId,
            'title' => $viewerTitle,
            'slug' => $viewerSlug,
            'doktype' => 1,
            'nav_hide' => 1,
        ]);

        $storagePageId = $this->createPage([
            'pid' => $rootPageId,
            'title' => $storageTitle,
            'doktype' => 254,
            'module' => 'tools',
            'nav_hide' => 1,
        ]);

        $this->createRootTemplate($rootPageId, $rootPageId, $viewerPageId, $storagePageId);
        $this->createSiteConfiguration($siteIdentifier, $rootPageId, $siteBase, $siteTitle);
        $dataFolder = $this->createDataFolders();
        $this->configurationSeedService->seedDefaults($storagePageId);

        return [
            'rootPageId' => $rootPageId,
            'viewerPageId' => $viewerPageId,
            'storagePageId' => $storagePageId,
            'siteIdentifier' => $siteIdentifier,
            'dataFolder' => $dataFolder,
        ];
    }

    /**
     * @param array<string, int|string> $page
     */
    private function createPage(array $page): int
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $now = time();
        $record = array_merge([
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'title' => 'Page',
            'slug' => '',
            'doktype' => 1,
            'hidden' => 0,
            'is_siteroot' => 0,
            'nav_hide' => 0,
            'perms_userid' => 1,
            'perms_groupid' => 1,
            'perms_user' => 31,
            'perms_group' => 27,
            'perms_everybody' => 1,
        ], $page);

        $connection->insert('pages', $record);
        return (int)$connection->lastInsertId('pages');
    }

    private function createRootTemplate(int $rootPageId, int $rootPid, int $viewerPid, int $storagePid): void
    {
        $constants = implode(LF, [
            'plugin.tx_dlf.bootstrap.rootPid = ' . $rootPid,
            'plugin.tx_dlf.bootstrap.viewerPid = ' . $viewerPid,
            'plugin.tx_dlf.bootstrap.storagePid = ' . $storagePid,
            'plugin.tx_dlf.persistence.storagePid = ' . $storagePid,
        ]);

        $this->connectionPool->getConnectionForTable('sys_template')->insert(
            'sys_template',
            [
                'pid' => $rootPageId,
                'tstamp' => time(),
                'crdate' => time(),
                'title' => 'Kitodo Viewer Bootstrap',
                'root' => 1,
                'clear' => 3,
                'include_static_file' => implode(',', [
                    'EXT:fluid_styled_content/Configuration/TypoScript/',
                    'EXT:dlf/Configuration/TypoScript/',
                    'EXT:dlf/Configuration/TypoScript/Toolbox/',
                    'EXT:dlf/Configuration/TypoScript/Bootstrap/',
                ]),
                'constants' => $constants,
            ]
        );
    }

    private function createSiteConfiguration(string $identifier, int $rootPageId, string $base, string $siteTitle): void
    {
        $configuration = [
            'rootPageId' => $rootPageId,
            'base' => $base,
            'websiteTitle' => $siteTitle,
            'languages' => [
                [
                    'title' => 'Deutsch',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'de_DE.UTF-8',
                    'navigationTitle' => 'German',
                    'flag' => 'de',
                    'websiteTitle' => $siteTitle,
                ],
                [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/en/',
                    'locale' => 'en_US.UTF-8',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                    'fallbackType' => 'strict',
                    'fallbacks' => '',
                    'websiteTitle' => $siteTitle,
                ],
            ],
            'errorHandling' => [],
            'routes' => [],
        ];
        $this->siteConfiguration->write($identifier, $configuration);

        $siteFolder = Environment::getConfigPath() . '/sites/' . $identifier;
        @chmod($siteFolder, 0755);
        @chmod($siteFolder . '/config.yaml', 0644);
    }

    private function createDataFolders(): string
    {
        $baseFolder = Environment::getPublicPath() . '/fileadmin/presentation';
        GeneralUtility::mkdir_deep($baseFolder, 'data');
        GeneralUtility::mkdir_deep($baseFolder, 'import');
        return $baseFolder . '/data';
    }

    private function buildUniqueSiteIdentifier(string $seed): string
    {
        $identifier = trim($seed);
        if ($identifier === '') {
            $identifier = 'kitodo-presentation';
        }
        $identifier = strtolower((string)preg_replace('/[^a-z0-9]+/', '-', $identifier));
        $identifier = trim($identifier, '-');
        if ($identifier === '') {
            $identifier = 'kitodo-presentation';
        }

        $existingIdentifiers = array_keys($this->siteConfiguration->getAllExistingSites(false));
        if (!in_array($identifier, $existingIdentifiers, true)) {
            return $identifier;
        }

        $suffix = 2;
        do {
            $candidate = $identifier . '-' . $suffix;
            $suffix++;
        } while (in_array($candidate, $existingIdentifiers, true));

        return $candidate;
    }

    private function normalizeSiteBase(?string $siteBase): string
    {
        $siteBase = trim((string)$siteBase);
        if ($siteBase === '') {
            return '/';
        }
        if ($siteBase === '/') {
            return '/';
        }
        if (!str_ends_with($siteBase, '/')) {
            $siteBase .= '/';
        }
        return $siteBase;
    }
}
