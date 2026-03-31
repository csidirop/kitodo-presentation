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

namespace Kitodo\Dlf\Controller\Backend;

use Kitodo\Dlf\Common\Helper;
use Kitodo\Dlf\Common\Solr\Solr;
use Kitodo\Dlf\Controller\AbstractController;
use Kitodo\Dlf\Domain\Model\Format;
use Kitodo\Dlf\Domain\Model\SolrCore;
use Kitodo\Dlf\Domain\Repository\FormatRepository;
use Kitodo\Dlf\Domain\Repository\MetadataRepository;
use Kitodo\Dlf\Domain\Repository\SolrCoreRepository;
use Kitodo\Dlf\Domain\Repository\StructureRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Localization\LocalizationFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Fluid\View\TemplateView;
use TYPO3Fluid\Fluid\View\ViewInterface;

/**
 * Controller class for the backend module 'New Tenant'.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class NewTenantController extends AbstractController
{
    protected const CONFIGURATION_FOLDER_TITLE = 'Kitodo Configuration';
    protected const VIEWER_PAGE_TITLE = 'Viewer';
    protected const TEMPLATE_TITLE = 'Kitodo.Presentation';
    protected const VIEWER_PLUGINS = [
        'dlf_navigation' => 'Navigation',
        'dlf_pageview' => 'Page View',
        'dlf_metadata' => 'Metadata',
        'dlf_tableofcontents' => 'Table of Contents',
    ];

    /**
     * @access protected
     * @var int
     */
    protected int $pid;

    /**
     * @access protected
     * @var mixed[]
     */
    protected array $pageInfo;

    /**
     * @access protected
     * @var mixed[] All configured site languages
     */
    protected array $siteLanguages;

    /**
     * @access protected
     * @var LocalizationFactory Language factory to get language key/values by our own.
     */
    protected LocalizationFactory $languageFactory;

    /**
     * @access protected
     * @var FormatRepository
     */
    protected FormatRepository $formatRepository;

    /**
     * @access public
     *
     * @param FormatRepository $formatRepository
     *
     * @return void
     */
    public function injectFormatRepository(FormatRepository $formatRepository): void
    {
        $this->formatRepository = $formatRepository;
    }

    /**
     * @access protected
     * @var MetadataRepository
     */
    protected MetadataRepository $metadataRepository;

    /**
     * @access public
     *
     * @param MetadataRepository $metadataRepository
     *
     * @return void
     */
    public function injectMetadataRepository(MetadataRepository $metadataRepository): void
    {
        $this->metadataRepository = $metadataRepository;
    }

    /**
     * @access protected
     * @var StructureRepository
     */
    protected StructureRepository $structureRepository;

    /**
     * @access public
     *
     * @param StructureRepository $structureRepository
     *
     * @return void
     */
    public function injectStructureRepository(StructureRepository $structureRepository): void
    {
        $this->structureRepository = $structureRepository;
    }

    /**
     * @access protected
     * @var SolrCoreRepository
     */
    protected SolrCoreRepository $solrCoreRepository;

    /**
     * @access public
     *
     * @param SolrCoreRepository $solrCoreRepository
     *
     * @return void
     */
    public function injectSolrCoreRepository(SolrCoreRepository $solrCoreRepository): void
    {
        $this->solrCoreRepository = $solrCoreRepository;
    }

    /**
     * Returns a response object with either the given html string or the current rendered view as content.
     *
     * @access protected
     *
     * @param bool $isError whether to render the non-error or error template
     *
     * @param mixed[] $extraData extra view data used to render the template (in addition to $viewData of AbstractController)
     *
     * @return ResponseInterface the response
     */
    protected function templateResponse(bool $isError, array $extraData): ResponseInterface
    {
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $messageQueue = $flashMessageService->getMessageQueueByIdentifier();

        $moduleTemplateFactory = GeneralUtility::makeInstance(ModuleTemplateFactory::class);
        $moduleTemplate = $moduleTemplateFactory->create($this->request);
        $moduleTemplate->assignMultiple($this->viewData);
        $moduleTemplate->assignMultiple($extraData);
        $moduleTemplate->setFlashMessageQueue($messageQueue);
        $template = $isError ? 'Backend/NewTenant/Error' : 'Backend/NewTenant/Index';
        return $moduleTemplate->renderResponse($template);
    }

    /**
     * Initialization for all actions
     *
     * @access protected
     *
     * @return void
     */
    protected function initializeAction(): void
    {
        $this->pid = (int) ($this->request->getQueryParams()['id'] ?? null);

        $this->setStoragePid($this->pid);

        $this->languageFactory = GeneralUtility::makeInstance(LocalizationFactory::class);

        try {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($this->pid);
        } catch (SiteNotFoundException $e) {
            $site = new NullSite();
        }
        $this->siteLanguages = $site->getLanguages();
    }


    /**
     * Action adding formats records
     *
     * @access public
     *
     * @return ResponseInterface the response
     */
    public function addFormatAction(): ResponseInterface
    {
        $this->ensureDefaultFormats();
        return $this->redirect('index');
    }

    /**
     * Action adding metadata records
     *
     * @access public
     *
     * @return ResponseInterface the response
     */
    public function addMetadataAction(): ResponseInterface
    {
        $this->ensureMetadataRecords();
        return $this->redirect('index');
    }

    /**
     * Action adding Solr core records
     *
     * @access public
     *
     * @return ResponseInterface the response
     */
    public function addSolrCoreAction(): ResponseInterface
    {
        $this->ensureSolrCore();
        return $this->redirect('index');
    }

    /**
     * Action adding structure records
     *
     * @access public
     *
     * @return ResponseInterface the response
     */
    public function addStructureAction(): ResponseInterface
    {
        $this->ensureStructureRecords();
        return $this->redirect('index');
    }

    /**
     * Action creating a dfg-viewer-like initial setup on a regular page.
     */
    public function bootstrapAction(): ResponseInterface
    {
        $selectedPageUid = $this->pid;
        $this->pageInfo = BackendUtility::readPageAccess($this->pid, $GLOBALS['BE_USER']->getPagePermsClause(1)) ?: [];

        if (empty($this->pageInfo) || (int) ($this->pageInfo['doktype'] ?? 0) === 254) {
            return $this->redirect('error');
        }

        ['configurationFolder' => $configurationFolderUid, 'viewerPage' => $viewerPageUid] = $this->ensureInitialPages();
        $templateRootPageUid = $this->resolveTemplateRootPageUid($selectedPageUid);

        $this->pid = $configurationFolderUid;
        $this->setStoragePid($configurationFolderUid);

        $recordInfos = $this->buildRecordInfos($configurationFolderUid);

        if ($recordInfos['formats']['numCurrent'] < $recordInfos['formats']['numDefault']) {
            $this->ensureDefaultFormats();
        }
        if ($recordInfos['structures']['numCurrent'] < $recordInfos['structures']['numDefault']) {
            $this->ensureStructureRecords();
        }
        if ($recordInfos['metadata']['numCurrent'] < $recordInfos['metadata']['numDefault']) {
            $this->ensureMetadataRecords();
        }

        $solrCoreUid = $this->ensureSolrCore();

        $this->ensureTemplateRecord($templateRootPageUid, $configurationFolderUid, $solrCoreUid);
        $this->ensureViewerPlugins($viewerPageUid);

        Helper::addMessage(
            'Initial setup created below the selected page.',
            'Kitodo.Presentation',
            \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::OK,
            false
        );

        return $this->redirect('index');
    }

    /**
     * Main function of the module
     *
     * @access public
     *
     * @return ResponseInterface the response
     */
    public function indexAction(): ResponseInterface
    {
        $this->pageInfo = BackendUtility::readPageAccess($this->pid, $GLOBALS['BE_USER']->getPagePermsClause(1)) ?: [];

        if (empty($this->pageInfo)) {
            return $this->redirect('error');
        }

        if ((int) ($this->pageInfo['doktype'] ?? 0) === 254) {
            return $this->templateResponse(false, [
                'mode' => 'storage',
                'recordInfos' => $this->buildRecordInfos($this->pid),
            ]);
        }

        return $this->templateResponse(false, [
            'mode' => 'bootstrap',
            'bootstrapInfos' => $this->buildBootstrapInfos(),
        ]);
    }

    /**
     * Error function - there is nothing to do at the moment.
     *
     * @access public
     *
     * @return ResponseInterface
     */
    public function errorAction(): ResponseInterface
    {
        return $this->templateResponse(true, []);
    }

    /**
     * Get language label for given key and language.
     *
     * @access private
     *
     * @param string $index
     * @param string $lang
     * @param mixed[] $langArray
     *
     * @return string
     */
    private function getLLL(string $index, string $lang, array $langArray): string
    {
        if (isset($langArray[$lang][$index][0]['target'])) {
            return $langArray[$lang][$index][0]['target'];
        } elseif (isset($langArray['default'][$index][0]['target'])) {
            return $langArray['default'][$index][0]['target'];
        } else {
            return 'Missing translation for ' . $index;
        }
    }

    /**
     * Get records from file for given record type.
     *
     * @access private
     *
     * @param string $recordType
     *
     * @return mixed[]
     */
    private function getRecords(string $recordType): array
    {
        $filePath = GeneralUtility::getFileAbsFileName('EXT:dlf/Resources/Private/Data/' . $recordType . 'Defaults.json');
        if (file_exists($filePath)) {
            $fileContents = file_get_contents($filePath);
            if (is_string($fileContents)) {
                $records = json_decode($fileContents, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $records;
                }
            }
        }
        return [];
    }

    private function setStoragePid(int $pid): void
    {
        $frameworkConfiguration = $this->configurationManager->getConfiguration($this->configurationManager::CONFIGURATION_TYPE_FRAMEWORK);
        $frameworkConfiguration['persistence']['storagePid'] = $pid;
        $this->configurationManager->setConfiguration($frameworkConfiguration);
    }

    private function getDefaultLanguageCode(): string
    {
        if (isset($this->siteLanguages[0])) {
            return $this->siteLanguages[0]->getLocale()->getLanguageCode();
        }

        return 'en';
    }

    private function ensureDefaultFormats(): void
    {
        $formatsDefaults = $this->getRecords('Format');
        $doPersist = false;

        foreach ($formatsDefaults as $type => $values) {
            if ($this->formatRepository->findOneBy(['type' => $type]) === null) {
                $newRecord = GeneralUtility::makeInstance(Format::class);
                $newRecord->setType($type);
                $newRecord->setRoot($values['root']);
                $newRecord->setNamespace($values['namespace']);
                $newRecord->setClass($values['class']);
                $this->formatRepository->add($newRecord);
                $doPersist = true;
            }
        }

        if ($doPersist) {
            GeneralUtility::makeInstance(PersistenceManager::class)->persistAll();
        }
    }

    private function ensureMetadataRecords(): void
    {
        $metadataDefaults = $this->getRecords('Metadata');
        $metadataLabels = $this->languageFactory->getParsedData(
            'EXT:dlf/Resources/Private/Language/locallang_metadata.xlf',
            $this->getDefaultLanguageCode()
        );

        $insertedFormats = $this->formatRepository->findAll();
        $availableFormats = [];
        foreach ($insertedFormats as $insertedFormat) {
            $availableFormats[$insertedFormat->getRoot()] = $insertedFormat->getUid();
        }

        $defaultWrap = BackendUtility::getTcaFieldConfiguration('tx_dlf_metadata', 'wrap')['default'];
        $data = [];
        foreach ($metadataDefaults as $indexName => $values) {
            if ($this->metadataRepository->findOneBy(['pid' => $this->pid, 'indexName' => $indexName]) !== null) {
                continue;
            }

            $formatIds = [];

            foreach ($values['format'] as $format) {
                if (!isset($availableFormats[$format['format_root']])) {
                    continue;
                }

                $format['encoded'] = $availableFormats[$format['format_root']];
                unset($format['format_root']);
                $formatIds[] = uniqid('NEW');
                $data['tx_dlf_metadataformat'][end($formatIds)] = $format;
                $data['tx_dlf_metadataformat'][end($formatIds)]['pid'] = $this->pid;
            }

            $data['tx_dlf_metadata'][uniqid('NEW')] = [
                'pid' => $this->pid,
                'label' => $this->getLLL('metadata.' . $indexName, $this->getDefaultLanguageCode(), $metadataLabels),
                'index_name' => $indexName,
                'format' => implode(',', $formatIds),
                'default_value' => $values['default_value'],
                'wrap' => !empty($values['wrap']) ? $values['wrap'] : $defaultWrap,
                'index_tokenized' => $values['index_tokenized'],
                'index_stored' => $values['index_stored'],
                'index_indexed' => $values['index_indexed'],
                'index_boost' => $values['index_boost'],
                'is_sortable' => $values['is_sortable'],
                'is_facet' => $values['is_facet'],
                'is_listed' => $values['is_listed'],
                'index_autocomplete' => $values['index_autocomplete'],
            ];
        }

        if (empty($data)) {
            return;
        }

        $metadataIds = Helper::processDatabaseAsAdmin($data, [], true);
        $insertedMetadata = [];
        foreach ($metadataIds as $uid) {
            $metadata = $this->metadataRepository->findByUid((int) $uid);
            if ($metadata != null) {
                $insertedMetadata[$uid] = $metadata->getIndexName();
            }
        }

        foreach ($this->siteLanguages as $siteLanguage) {
            if ($siteLanguage->getLanguageId() === 0) {
                continue;
            }

            $translateData = [];
            foreach ($insertedMetadata as $id => $indexName) {
                $translateData['tx_dlf_metadata'][uniqid('NEW')] = [
                    'pid' => $this->pid,
                    'sys_language_uid' => $siteLanguage->getLanguageId(),
                    'l18n_parent' => $id,
                    'label' => $this->getLLL('metadata.' . $indexName, $siteLanguage->getLocale()->getLanguageCode(), $metadataLabels),
                ];
            }

            Helper::processDatabaseAsAdmin($translateData);
        }
    }

    private function ensureStructureRecords(): void
    {
        $structureDefaults = $this->getRecords('Structure');
        $structureLabels = $this->languageFactory->getParsedData(
            'EXT:dlf/Resources/Private/Language/locallang_structure.xlf',
            $this->getDefaultLanguageCode()
        );

        $data = [];
        foreach ($structureDefaults as $indexName => $values) {
            if ($this->structureRepository->findOneBy(['pid' => $this->pid, 'indexName' => $indexName]) !== null) {
                continue;
            }

            $data['tx_dlf_structures'][uniqid('NEW')] = [
                'pid' => $this->pid,
                'toplevel' => $values['toplevel'],
                'label' => $this->getLLL('structure.' . $indexName, $this->getDefaultLanguageCode(), $structureLabels),
                'index_name' => $indexName,
                'oai_name' => $values['oai_name'],
                'thumbnail' => 0,
            ];
        }

        if (empty($data)) {
            return;
        }

        $structureIds = Helper::processDatabaseAsAdmin($data, [], true);
        $insertedStructures = [];
        foreach ($structureIds as $uid) {
            $structure = $this->structureRepository->findByUid((int) $uid);
            if ($structure !== null) {
                $insertedStructures[$uid] = $structure->getIndexName();
            }
        }

        foreach ($this->siteLanguages as $siteLanguage) {
            if ($siteLanguage->getLanguageId() === 0) {
                continue;
            }

            $translateData = [];
            foreach ($insertedStructures as $id => $indexName) {
                $translateData['tx_dlf_structures'][uniqid('NEW')] = [
                    'pid' => $this->pid,
                    'sys_language_uid' => $siteLanguage->getLanguageId(),
                    'l18n_parent' => $id,
                    'label' => $this->getLLL('structure.' . $indexName, $siteLanguage->getLocale()->getLanguageCode(), $structureLabels),
                ];
            }

            Helper::processDatabaseAsAdmin($translateData);
        }
    }

    private function ensureSolrCore(): ?int
    {
        $beLabels = $this->languageFactory->getParsedData(
            'EXT:dlf/Resources/Private/Language/locallang_be.xlf',
            $this->getDefaultLanguageCode()
        );

        $existingSolrCore = $this->solrCoreRepository->findOneBy(['pid' => $this->pid]);
        if ($existingSolrCore !== null) {
            return $existingSolrCore->getUid();
        }

        $newRecord = GeneralUtility::makeInstance(SolrCore::class);
        $newRecord->setLabel($this->getLLL('flexform.solrcore', $this->getDefaultLanguageCode(), $beLabels) . ' (PID ' . $this->pid . ')');
        $indexName = Solr::createCore('');
        if (empty($indexName)) {
            return null;
        }

        $newRecord->setIndexName($indexName);
        $this->solrCoreRepository->add($newRecord);
        GeneralUtility::makeInstance(PersistenceManager::class)->persistAll();

        return $newRecord->getUid() ?: null;
    }

    private function buildRecordInfos(int $storagePid): array
    {
        $this->pid = $storagePid;
        $this->setStoragePid($storagePid);

        $formatsDefaults = $this->getRecords('Format');
        $structuresDefaults = $this->getRecords('Structure');
        $metadataDefaults = $this->getRecords('Metadata');

        return [
            'formats' => [
                'numCurrent' => $this->formatRepository->countAll(),
                'numDefault' => count($formatsDefaults),
            ],
            'structures' => [
                'numCurrent' => $this->structureRepository->count(['pid' => $storagePid]),
                'numDefault' => count($structuresDefaults),
            ],
            'metadata' => [
                'numCurrent' => $this->metadataRepository->count(['pid' => $storagePid]),
                'numDefault' => count($metadataDefaults),
            ],
            'solrcore' => [
                'numCurrent' => $this->solrCoreRepository->count(['pid' => $storagePid]),
            ],
        ];
    }

    private function buildBootstrapInfos(): array
    {
        $configurationFolder = $this->findChildPage(self::CONFIGURATION_FOLDER_TITLE, 254);
        $viewerPage = $this->findChildPage(self::VIEWER_PAGE_TITLE);
        $template = $this->findTemplateRecord($this->resolveTemplateRootPageUid($this->pid));
        $templateReady = $template !== null
            && (int) ($template['root'] ?? 0) === 1
            && str_contains((string) ($template['include_static_file'] ?? ''), 'EXT:dlf/Configuration/TypoScript/');
        $viewerPluginsPresent = 0;

        if ($viewerPage !== null) {
            foreach (array_keys(self::VIEWER_PLUGINS) as $listType) {
                if ($this->findViewerPlugin((int) $viewerPage['uid'], $listType) !== null) {
                    $viewerPluginsPresent++;
                }
            }
        }

        $recordInfos = $configurationFolder !== null
            ? $this->buildRecordInfos((int) $configurationFolder['uid'])
            : null;
        $recordsReady = $recordInfos !== null
            && $recordInfos['formats']['numCurrent'] >= $recordInfos['formats']['numDefault']
            && $recordInfos['structures']['numCurrent'] >= $recordInfos['structures']['numDefault']
            && $recordInfos['metadata']['numCurrent'] >= $recordInfos['metadata']['numDefault']
            && $recordInfos['solrcore']['numCurrent'] >= 1;

        return [
            'configurationFolder' => $configurationFolder !== null,
            'viewerPage' => $viewerPage !== null,
            'template' => $templateReady,
            'viewerPluginsPresent' => $viewerPluginsPresent,
            'viewerPluginsExpected' => count(self::VIEWER_PLUGINS),
            'ready' => $configurationFolder !== null
                && $viewerPage !== null
                && $templateReady
                && $viewerPluginsPresent === count(self::VIEWER_PLUGINS)
                && $recordsReady,
            'recordInfos' => $recordInfos,
        ];
    }

    private function ensureInitialPages(): array
    {
        $configurationFolder = $this->findChildPage(self::CONFIGURATION_FOLDER_TITLE, 254);
        $viewerPage = $this->findChildPage(self::VIEWER_PAGE_TITLE);
        $data = [];
        $newConfigurationFolder = null;
        $newViewerPage = null;

        if ($configurationFolder === null) {
            $newConfigurationFolder = uniqid('NEW');
            $data['pages'][$newConfigurationFolder] = [
                'pid' => $this->pid,
                'title' => self::CONFIGURATION_FOLDER_TITLE,
                'doktype' => 254,
            ];
        }

        if ($viewerPage === null) {
            $newViewerPage = uniqid('NEW');
            $data['pages'][$newViewerPage] = [
                'pid' => $this->pid,
                'title' => self::VIEWER_PAGE_TITLE,
                'doktype' => 1,
            ];
        }

        $createdRecords = !empty($data) ? Helper::processDatabaseAsAdmin($data) : [];

        return [
            'configurationFolder' => $configurationFolder['uid'] ?? $createdRecords[$newConfigurationFolder] ?? 0,
            'viewerPage' => $viewerPage['uid'] ?? $createdRecords[$newViewerPage] ?? 0,
        ];
    }

    private function ensureTemplateRecord(int $pageUid, int $storagePid, ?int $solrCoreUid): void
    {
        $template = $this->findTemplateRecord($pageUid);
        $includeStaticFile = $this->mergeStaticTemplates(
            (string) ($template['include_static_file'] ?? ''),
            [
                'EXT:fluid_styled_content/Configuration/TypoScript/',
                'EXT:dlf/Configuration/TypoScript/',
            ]
        );
        $constants = $this->upsertTypoScriptConstant((string) ($template['constants'] ?? ''), 'plugin.tx_dlf.persistence.storagePid', (string) $storagePid);
        if ($solrCoreUid !== null) {
            $constants = $this->upsertTypoScriptConstant($constants, 'plugin.tx_dlf.persistence.solrCoreUid', (string) $solrCoreUid);
        }

        $data = [];
        if ($template === null) {
            $data['sys_template'][uniqid('NEW')] = [
                'pid' => $pageUid,
                'title' => self::TEMPLATE_TITLE,
                'root' => 1,
                'clear' => 3,
                'include_static_file' => $includeStaticFile,
                'constants' => $constants,
            ];
        } else {
            $data['sys_template'][(string) $template['uid']] = [
                'title' => self::TEMPLATE_TITLE,
                'root' => 1,
                'clear' => 3,
                'include_static_file' => $includeStaticFile,
                'constants' => $constants,
            ];
        }

        Helper::processDatabaseAsAdmin($data);
    }

    private function resolveTemplateRootPageUid(int $fallbackPageUid): int
    {
        try {
            return GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($fallbackPageUid)->getRootPageId();
        } catch (SiteNotFoundException $e) {
            return $fallbackPageUid;
        }
    }

    private function ensureViewerPlugins(int $pageUid): void
    {
        $data = [];
        foreach (self::VIEWER_PLUGINS as $listType => $header) {
            if ($this->findViewerPlugin($pageUid, $listType) !== null) {
                continue;
            }

            $data['tt_content'][uniqid('NEW')] = [
                'pid' => $pageUid,
                'colPos' => 0,
                'CType' => 'list',
                'list_type' => $listType,
                'header' => $header,
            ];
        }

        if (!empty($data)) {
            Helper::processDatabaseAsAdmin($data);
        }
    }

    private function findChildPage(string $title, ?int $doktype = null): ?array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder
            ->select('uid', 'doktype')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($this->pid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter($title))
            )
            ->setMaxResults(1);

        if ($doktype !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('doktype', $queryBuilder->createNamedParameter($doktype, \PDO::PARAM_INT))
            );
        }

        $row = $queryBuilder->executeQuery()->fetchAssociative();
        return is_array($row) ? $row : null;
    }

    private function findTemplateRecord(int $pageUid): ?array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_template');
        $row = $queryBuilder
            ->select('uid', 'root', 'include_static_file', 'constants')
            ->from('sys_template')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, \PDO::PARAM_INT))
            )
            ->orderBy('root', 'DESC')
            ->addOrderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function findViewerPlugin(int $pageUid, string $listType): ?array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $row = $queryBuilder
            ->select('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter('list')),
                $queryBuilder->expr()->eq('list_type', $queryBuilder->createNamedParameter($listType))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function mergeStaticTemplates(string $currentValue, array $requiredTemplates): string
    {
        $templates = array_filter(array_map('trim', explode(',', $currentValue)));
        foreach ($requiredTemplates as $requiredTemplate) {
            if (!in_array($requiredTemplate, $templates, true)) {
                $templates[] = $requiredTemplate;
            }
        }

        return implode(',', $templates);
    }

    private function upsertTypoScriptConstant(string $currentConstants, string $key, string $value): string
    {
        $line = $key . ' = ' . $value;
        $pattern = '/^' . preg_quote($key, '/') . '\s*=.*$/m';

        if (preg_match($pattern, $currentConstants) === 1) {
            return preg_replace($pattern, $line, $currentConstants, 1) ?? $currentConstants;
        }

        $trimmedConstants = trim($currentConstants);
        if ($trimmedConstants === '') {
            return $line;
        }

        return $trimmedConstants . PHP_EOL . $line;
    }
}
