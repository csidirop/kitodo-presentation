######################
Bootstrap Default Viewer Setup
######################

This document summarizes the bootstrap viewer work that was added to make Kitodo.Presentation usable directly after extension installation, without requiring ``dfg-viewer`` or ``slub_digitalcollections`` as additional setup extensions.

Scope
=====

The implementation covers the first setup step only:

* create a usable page tree on installation/setup
* ship a bootstrap site configuration scaffold
* seed the configuration folder with a reduced DFG-viewer-style default dataset
* provide a working standalone viewer page
* make remote images work through the internal proxy

Overview
========

The bootstrap flow now has four main parts:

1. initial data import
2. post-import bootstrap service
3. standalone viewer templates and TypoScript
4. runtime fixes needed for remote documents, fulltext and navigation

What Was Added and Why
======================

Initial installation payload
----------------------------

Files:

* ``Initialisation/data.xml``
* ``Initialisation/Site/kitodo-presentation/config.yaml``

What changed:

* ``data.xml`` now creates the initial page tree used by the bootstrap setup.
* The page tree includes a root page, a viewer page and a configuration folder.
* A site configuration scaffold is shipped for the bootstrap site.

Why:

* TYPO3 needs real pages and a site to render the viewer immediately after installation.
* The previous state required manual setup or external helper extensions.

Install/setup event listeners
-----------------------------

Files:

* ``Configuration/Services.yaml``
* ``Classes/EventListener/FinalizeInstallBootstrap.php``
* ``Classes/EventListener/FinalizeSetupBootstrap.php``

What changed:

* Two event listeners are registered to run the bootstrap finalization logic.

Why:

* TYPO3 can reach this extension through different installation/setup paths.
* The bootstrap work needs to run after the basic import happened, regardless of whether that import was triggered by package installation or extension setup.

Bootstrap finalization service
------------------------------

Files:

* ``Classes/Service/InstallBootstrapService.php``

What changed:

* Resolves the imported root page and child pages.
* Ensures a root ``sys_template`` exists.
* Forces inclusion of both the base and bootstrap static TypoScript.
* Rewrites template constants to the imported page IDs.
* Seeds the configuration folder.
* Creates a Solr core record when possible.
* Creates the viewer content elements on the imported viewer page.

Why:

* The XML import alone is not enough because imported UIDs and runtime configuration vary per instance.
* The viewer page must be functional without manual TypoScript, plugin or extension-configuration work.

DFG-Viewer-style bootstrap dataset
---------------------------

Files:

* ``Classes/Service/BootstrapConfigurationImportService.php``
* ``Resources/Private/Data/BootstrapConfigurationDefaults.json``

What changed:

* Added a dedicated importer for a reduced DFG-Viewer-style default dataset.
* The importer upserts records into the configuration folder by stable matching rules.
* It seeds formats, metadata, metadata-format mappings, metadata subentries and structures.

Why:

* A plain page tree is not enough to make the viewer useful.
* The DLF viewer expects a non-trivial base configuration to resolve formats, structures and metadata mappings.
* The goal was to replicate the practical setup value of the DFG-Viewer without depending on that or any other extension.

Standalone bootstrap TypoScript
--------------------------------

Files:

* ``Configuration/TypoScript/Bootstrap/constants.typoscript``
* ``Configuration/TypoScript/Bootstrap/setup.typoscript``
* ``Configuration/TCA/Overrides/sys_template.php``

What changed:

* Added a dedicated bootstrap TypoScript set with bootstrap constants for the imported root and viewer page IDs.
* The viewer page uses explicit ``EXTBASEPLUGIN`` definitions for navigation, toolbox, pageview, contents and metadata.
* ``plugin.tx_dlf_pageview.settings.useInternalProxy = 1`` is enabled.
* ``plugin.tx_dlf_toolbox.settings.fullTextScrollElement`` is configured.

Why:

* The bootstrap viewer should not depend on pre-existing ``tt_content.list.20`` TypoScript integration.
* The pageview and toolbox needed explicit settings to work in a minimal standalone page.

Bootstrap templates
-------------------

Files:

* ``Resources/Private/Templates/Bootstrap/Landing.html``
* ``Resources/Private/Templates/Bootstrap/Viewer.html``

What changed:

* Added a landing page for the empty-viewer state.
* Added clickable sample links for test documents.
* Added a standalone viewer shell with metadata, contents, navigation and toolbox areas.
* Reused the original DLF PageView template.
* Extended the original PageView template with the score container needed by the standalone viewer shell.

Why:

* The stock output was too bare for a usable out-of-the-box viewer.
* The bootstrap page needed a self-contained shell that works without ``dfg-viewer``.
* The standalone viewer shell still needed a predictable PageView integration point, but this is now handled by TypoScript settings and a small extension in the original template.

Bootstrap styling
-----------------

Files:

* ``Resources/Public/Stylesheets/default-viewer.css``

What changed:

* Added a dedicated stylesheet for the standalone viewer.

Why:

* The viewer needed its own layout and visual shell.

Known Tradeoffs
===============

* The seeded DFG-Viewer dataset is reduced and focused on the default-language bootstrap use case.
* The bootstrap setup still depends on a reduced imported default dataset and bootstrap-specific TypoScript settings.
