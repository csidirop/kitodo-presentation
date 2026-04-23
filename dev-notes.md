# Dev Notes

Base commit for this note: `f97a317847b37d6b70d842e65b708e0e776075b5`

This file summarizes:
1. the main prompts and decision changes that drove the work after that commit
2. the surviving code and documentation changes in the repository relative to that commit

It is intentionally grouped by feature area instead of listing every micro-edit chronologically.

## Prompt History Summary

### 1. Bootstrap setup moved from install-time automation to explicit setup flows
The original direction after the base commit was to make the extension usable out of the box by copying the practical setup behavior from `dfg-viewer` / `slub_digitalcollections`.

That was then narrowed and changed in several steps:
- first, only step 1 was pursued: create the initial page tree and make the viewer work
- later, automatic install/setup triggering was removed
- the setup responsibility was split into a manual CLI bootstrap flow and a separate tenant defaults flow
- the setup had to work in non-empty TYPO3 instances, so identifier/base/path validation and duplicate checks were added

### 2. The bootstrap viewer became a standalone default viewer shell
The viewer work started as a rough bootstrap shell and was then iterated heavily:
- initial page and config folder creation
- functional viewer shell with navigation, toolbox, metadata and table of contents
- fixes for routing, page selection, next/previous navigation and downloads
- improved landing page and empty-view behavior
- styling and naming cleanup (`dlf-bootstrap-*` -> `tx-dlf-default-viewer-*`)

A key later realization was that some early runtime controller patches were not actually required once setup and viewer wiring were corrected. Those controller changes were mostly rolled back and the surviving implementation now stays closer to the original runtime behavior.

### 3. Tenant setup stopped using one monolithic bootstrap JSON dataset
The tenant configuration path initially used one combined bootstrap dataset. That was later replaced by the same four-step flow used by the backend new-tenant module:
- namespaces / formats
- structures
- metadata
- Solr core

The CLI and backend module now share one tenant defaults service so the behavior is aligned.

### 4. Several cleanup rounds removed temporary or obsolete code
After the functional setup worked, multiple cleanup passes removed:
- install-time event listeners and install bootstrap service variants
- obsolete bootstrap data import code and its combined dataset
- stale viewer content-element creation logic
- redundant backend-layout scaffolding for those old content elements
- unused service dependencies left over from earlier refactors

## Surviving Changes Since the Base Commit

## 1. Root Page Bootstrap Setup

### Files
- `Classes/Command/BootstrapSetupCommand.php`
- `Classes/Service/BootstrapRootSetupService.php`
- `Resources/Private/Data/BootstrapSiteConfig.yaml`
- `Configuration/Services.yaml`
- `Configuration/TCA/Overrides/sys_template.php`
- `Configuration/TypoScript/Bootstrap/constants.typoscript`
- `Configuration/TypoScript/Bootstrap/setup.typoscript`

### What changed
A new explicit bootstrap setup flow was introduced.

`BootstrapSetupCommand.php`:
- registers the CLI command `kitodo:setup`
- accepts optional overrides for:
  - `--identifier`
  - `--base`
  - `--root-title`
  - `--root-slug`
  - `--viewer-slug`
- reports the created site identifier, base, root page, viewer page, configuration page and template record
- no longer pretends to create a Solr core during root setup

`BootstrapRootSetupService.php`:
- creates a fresh root page at `pid=0`
- creates two children below it:
  - `Viewer`
  - `Kitodo Configuration`
- writes a TYPO3 site configuration based on `BootstrapSiteConfig.yaml`
- creates or updates a root `sys_template`
- rewrites TypoScript constants so the generated page IDs are injected into the bootstrap TypoScript
- flushes caches after setup

Validation and naming logic added in this service:
- unique generated site identifiers like `kitodo-presentation`, `kitodo-presentation-2`, ...
- unique generated root page titles like `Kitodo Presentation`, `Kitodo Presentation 2`, ...
- validation for custom identifiers, base paths and slugs
- protection against duplicate site identifiers
- protection against duplicate root page titles
- protection against duplicate site bases
- default `/` is only used for the first bootstrap site if `/` is still unused by another TYPO3 site
- otherwise the generated default base becomes `/<identifier>/`

`BootstrapSiteConfig.yaml`:
- is now the site-config template used by the service
- contains the base two-language site definition that the service rewrites dynamically

`Configuration/TCA/Overrides/sys_template.php`:
- adds bootstrap TypoScript as a static include so the root template can reference it cleanly

`Configuration/TypoScript/Bootstrap/constants.typoscript`:
- provides bootstrap placeholder constants that are later filled by the setup service

`Configuration/TypoScript/Bootstrap/setup.typoscript`:
- provides the root landing page and viewer page wiring
- defines the standalone plugin objects used by the viewer shell
- sets storage PID and bootstrap page IDs via constants
- sets toolbox defaults needed by the default viewer
- sets pageview `useInternalProxy = 1`
- includes the default viewer stylesheet and bootstrap template configuration

### Why this was needed
The earlier install-triggered setup was too implicit and too fragile for non-empty instances. The current root setup makes the initial page tree creation explicit, repeatable and targetable.

## 2. Tenant Defaults Setup

### Files
- `Classes/Command/TenantSetupCommand.php`
- `Classes/Service/TenantModuleSetupService.php`
- `Classes/Service/TenantDefaultsSetupService.php`
- `Configuration/Services.yaml`

### What changed
A second explicit setup flow was introduced for tenant defaults.

`TenantSetupCommand.php`:
- registers `kitodo:tenantSetup`
- targets the configuration folder directly through `--config-page`
- supports selective execution of setup steps:
  - `--namespaces`
  - `--formats` as alias for `--namespaces`
  - `--structures`
  - `--metadata`
  - `--solr-core`
- if no step flags are given, all four steps are executed
- now uses the wording `Tenant setup` and `Namespaces` in CLI output

`TenantModuleSetupService.php`:
- validates that the provided page exists
- validates that the provided page is a configuration folder (`doktype = 254`)
- validates that the configuration folder has a valid parent root page
- delegates actual record creation to `TenantDefaultsSetupService`
- flushes caches after setup
- after cleanup, it no longer returns an unused `rootPageId` in the CLI result payload

`TenantDefaultsSetupService.php`:
- is now the shared implementation used by both CLI and backend new-tenant module
- loads defaults from the split data files:
  - `Resources/Private/Data/FormatDefaults.json`
  - `Resources/Private/Data/StructureDefaults.json`
  - `Resources/Private/Data/MetadataDefaults.json`
- creates tenant-scoped records on the selected configuration folder PID
- creates namespace/format records in `tx_dlf_formats`
- creates structure records in `tx_dlf_structures`
- creates metadata records in `tx_dlf_metadata`
- creates metadata-format relation records in `tx_dlf_metadataformat`
- creates translated structure and metadata labels for configured site languages
- creates a Solr core record in `tx_dlf_solrcores` when requested
- uses additive behavior: existing matching records are skipped instead of duplicated

Important refactor in this service:
- the earlier Extbase-based format write path was removed
- format records are now written directly and per configuration PID, just like the other tenant defaults
- metadata now resolves available formats from `tx_dlf_formats` on the same configuration PID instead of from global repository state

### Why this was needed
The tenant setup needed to stop depending on one combined bootstrap dump and instead match the real backend tenant flow. The CLI and backend module now share one implementation instead of maintaining separate logic.

## 3. Backend New-Tenant Module Refactor

### File
- `Classes/Controller/Backend/NewTenantController.php`

### What changed
This controller changed substantially because the setup logic was moved out of it.

Before the refactor, the backend module contained more of the setup behavior itself. After the refactor it became a thin coordinator.

Current responsibilities:
- reads the selected page ID from the backend request
- sets `storagePid` for the module request context
- validates that the selected page is a configuration folder
- renders the backend module status page
- delegates each setup action to `TenantDefaultsSetupService`

Specific changes:
- added injection of `TenantDefaultsSetupService`
- `addFormatAction()`, `addMetadataAction()`, `addStructureAction()`, `addSolrCoreAction()` now call the shared service with one active step each
- `indexAction()` now only reports current counts and default totals
- direct repository-based counting was removed during cleanup and replaced with direct per-PID table counts, which better matches the tenant-scoped setup behavior
- stale state and dependencies from the earlier local-logic implementation were removed
- the small local `getRecords()` helper remains so the module can count against the shipped defaults files

### What was necessary vs incidental
Necessary:
- delegating the setup actions into the shared tenant service
- keeping status-page functionality in `indexAction()`
- keeping backend validation of the configuration folder

Later cleanup only:
- removing obsolete injected repositories
- removing unused state/imports
- switching the status counts to direct DB counts

## 4. CLI / DataHandler Fix in Helper

### File
- `Classes/Common/Helper.php`

### What changed
`Helper::processDatabaseAsAdmin()` was adjusted for the CLI setup path.

The final behavior is:
- in backend requests, it still requires an admin backend user
- in CLI mode, it now ensures TYPO3's `_cli_` backend user is initialized and authenticated before `DataHandler` is used
- only then does it run the data/command maps as admin

### Why this was needed
The tenant CLI originally reached `DataHandler` without a real authenticated backend admin user. That caused table permission warnings like attempts to modify `tx_dlf_metadata` or `tx_dlf_metadataformat` without permission. The helper fix is what made the CLI tenant setup actually insert records instead of silently failing after validation passed.

## 5. Default Viewer Templates, Landing Page and Styling

### Files
- `Resources/Private/Templates/Bootstrap/Landing.html`
- `Resources/Private/Templates/Bootstrap/Viewer.html`
- `Resources/Public/Stylesheets/default-viewer.css`
- `Configuration/TypoScript/Bootstrap/setup.typoscript`
- `Resources/Private/Templates/PageView/Main.html`

### What changed
A standalone default viewer UI was introduced, separate from the older page-content based approach.

`Landing.html`:
- became the bootstrap welcome page shown on the root page
- includes a short project introduction
- includes example links for supported source/document categories
- includes a bottom input form similar in purpose to the `dfg-viewer` demo URL field
- is designed as a regular page instead of a floating placeholder widget
- supports fallback to this shipped landing content while keeping the page root itself as the landing page

`Viewer.html`:
- became the standalone viewer shell rendered on the generated `Viewer` page
- provides the high-level structure for:
  - navigation
  - toolbox
  - metadata
  - table of contents
  - pageview area
- renders those viewer components directly through TypoScript plugin objects instead of depending on page content records
- keeps the viewer chrome visible even when no document is selected, while showing a centered notice in the stage area

`default-viewer.css`:
- contains the dedicated CSS for the standalone viewer and landing page
- was iterated multiple times to move away from a rough bootstrap appearance toward a cleaner default viewer shell
- now contains the final naming scheme using `tx-dlf-default-viewer-*`
- holds layout, panel, stage, toolbar, landing-page and responsive rules for the default viewer

`PageView/Main.html`:
- the bootstrap-specific pageview fork was removed again
- the surviving change is that the original pageview template now includes the additional score block needed by the default viewer setup

### Why this was needed
The goal was to ship a usable standalone default viewer without relying on extra extensions and without forcing editors to assemble the viewer from page content elements.

## 6. Viewer Partials and Small Frontend Behavior Fixes

### Files
- `Resources/Private/Partials/Navigation/PageSelect.html`
- `Resources/Private/Partials/Toolbox/ImageDownloadTool.html`
- `Resources/Private/Partials/Toolbox/PdfDownloadTool.html`
- `Resources/Public/JavaScript/PageView/Utility.js`

### What changed
`PageSelect.html`:
- changed away from the earlier broken selector behavior
- the selector now uses a plain GET-based submission path that works reliably with the current viewer request model

`ImageDownloadTool.html` and `PdfDownloadTool.html`:
- were adjusted so their links open in a new tab / target context instead of appearing inert inside the viewer chrome

`Utility.js`:
- contains the surviving image-loading adjustment used by the pageview for the default viewer path

### Why this was needed
These were targeted fixes for viewer usability regressions found while testing real documents: page selection, downloads, and image loading had to be stabilized without reintroducing the earlier controller-level proxy hacks that were later removed.

## 7. Service Wiring and Registration

### Files
- `Configuration/Services.yaml`
- `Documentation/Developers/Index.rst`

### What changed
`Services.yaml` now registers the current explicit setup surface:
- `kitodo:setup`
- `kitodo:tenantSetup`

It also carries the current CLI descriptions used by TYPO3 command registration.

`Documentation/Developers/Index.rst`:
- links the new developer setup notes into the documentation index

## 8. Documentation Added for the New Setup Model

### Files
- `Documentation/Developers/BootstrapViewerSetup.rst`
- `Documentation/Developers/TenantSetup.rst`
- `Documentation/Developers/Index.rst`

### What changed
Two dedicated developer notes were added.

`BootstrapViewerSetup.rst` documents:
- root page setup command
- generated defaults
- validation rules
- site base behavior
- viewer wiring
- what is intentionally out of scope for root setup

`TenantSetup.rst` documents:
- tenant setup command
- the required configuration-folder target
- step flags and ordering
- additive behavior
- applied default record types
- language handling

These docs were also cleaned up later to remove references to older bootstrap import code that no longer exists.

## Prompt-to-Change Map

This is the shortest useful mapping from user requests to the code that survived:

- "setup should not run on extension install anymore; use CLI"
  - `BootstrapSetupCommand.php`
  - `BootstrapRootSetupService.php`
  - `Services.yaml`

- "separate page creation setup from tenant setup"
  - `TenantSetupCommand.php`
  - `TenantModuleSetupService.php`
  - `TenantDefaultsSetupService.php`
  - `NewTenantController.php`

- "tenant CLI should target the config folder and match the backend new-tenant flow"
  - `TenantSetupCommand.php`
  - `TenantModuleSetupService.php`
  - `TenantDefaultsSetupService.php`
  - `NewTenantController.php`

- "default viewer should be shipped and styled as a real standalone viewer"
  - `setup.typoscript`
  - `Landing.html`
  - `Viewer.html`
  - `default-viewer.css`
  - `PageView/Main.html`

- "rename viewer UI naming from bootstrap to default-viewer"
  - `Landing.html`
  - `Viewer.html`
  - `default-viewer.css`
  - `setup.typoscript`

- "clean up obsolete code after the refactors"
  - `BootstrapRootSetupService.php`
  - `TenantDefaultsSetupService.php`
  - `TenantModuleSetupService.php`
  - `NewTenantController.php`
  - docs cleanup in both developer RST files

## Important Final State

The current architecture after the post-commit work is:
- `kitodo:setup`
  - creates a new root page tree and site config
  - wires the default standalone viewer
  - does not seed tenant defaults
- `kitodo:tenantSetup`
  - targets an existing configuration folder
  - applies namespaces, structures, metadata and optional Solr setup
- backend new-tenant module
  - uses the same tenant defaults service as the CLI
- default viewer
  - is rendered directly from TypoScript + templates, not from generated viewer content elements

## Known Areas Still Not Fully Unified

These are not dead code, but they still have duplication and may be future cleanup targets:
- `NewTenantController.php` and `TenantDefaultsSetupService.php` both load defaults-file data from `Resources/Private/Data/*Defaults.json`
- `BootstrapRootSetupService.php` still has its own small DB helper methods and YAML-based site template generation instead of sharing a broader setup utility
- the documentation files now reflect the current implementation, but they are still separate and slightly overlapping
