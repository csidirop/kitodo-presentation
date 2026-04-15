######################
Bootstrap Default Viewer Setup
######################

This document summarizes the bootstrap viewer work that was added to make Kitodo.Presentation usable through an explicit CLI setup step, without requiring ``dfg-viewer`` or ``slub_digitalcollections`` as additional setup extensions.

Scope
=====

The implementation covers the first setup step only:

* create a usable page tree through the CLI setup command
* write a bootstrap site configuration during the CLI setup command
* seed the configuration folder with a reduced DFG-viewer-style default dataset
* provide a working standalone viewer page
* make remote images work through the internal proxy

Overview
========

The bootstrap flow now has three main parts:

1. manual CLI root setup
2. standalone viewer templates and TypoScript
3. bootstrap configuration seeding

What Was Added and Why
======================

Manual root-page creation
-------------------------

Files:

* ``Classes/Service/BootstrapRootSetupService.php``
* ``Resources/Private/Data/BootstrapSiteConfig.yaml``

What changed:

* The bootstrap root page, viewer page and configuration folder are created directly by the CLI setup service.
* The bootstrap site configuration is written from a template during the same command run.
* The extension no longer ships ``Initialisation`` payload files that TYPO3 imports automatically during extension setup.

Why:

* Extension setup should stay inert.
* The page tree and site setup must now happen only when the CLI command is executed explicitly.

Manual setup trigger
--------------------

Files:

* ``Configuration/Services.yaml``
* ``Classes/Command/BootstrapSetupCommand.php``
* ``Classes/Service/BootstrapRootSetupService.php``

What changed:

* Added the ``kitodo:setup`` CLI command as the explicit bootstrap trigger.
* Removed the automatic install/setup event listeners.
* The bootstrap service now creates the shipped bootstrap root page tree itself instead of relying on TYPO3 ``Initialisation`` imports.
* The command supports both a default auto-generated setup mode and optional custom parameters for identifier, base path, root title and slugs.

Why:

* Bootstrap setup should no longer run implicitly during extension installation, even when the shipped bootstrap page tree already exists.
* The backend workflow can later reuse the same service logic behind a manual UI.

Default command
^^^^^^^^^^^^^^^

::

   php /var/www/typo3/vendor/bin/typo3 kitodo:setup

This creates a new bootstrap group with generated values for:

* site identifier
* site base
* root page title
* root page slug
* viewer page slug

Custom command
^^^^^^^^^^^^^^

::

   php /var/www/typo3/vendor/bin/typo3 kitodo:setup \
     --identifier=my-instance \
     --base=/my-instance/ \
     --root-title="My Instance" \
     --root-slug=/ \
     --viewer-slug=/viewer

Available options:

* ``--identifier`` for a custom site identifier
* ``--base`` for a custom site base path
* ``--root-title`` for a custom root page title
* ``--root-slug`` for a custom root page slug
* ``--viewer-slug`` for a custom viewer page slug

Validation notes:

* ``--identifier`` only accepts lowercase letters, digits and hyphens.
* ``--base`` must start with ``/``.
* ``--root-slug`` and ``--viewer-slug`` must start with ``/``.
* In the current multi-site setup model, custom ``--root-slug`` values are only allowed together with the root base ``/``.

Bootstrap finalization service
------------------------------

Files:

* ``Classes/Service/BootstrapRootSetupService.php``

What changed:

* Creates or reuses the bootstrap root page and child pages.
* Writes the bootstrap site configuration from a template.
* Ensures a root ``sys_template`` exists.
* Forces inclusion of both the base and bootstrap static TypoScript.
* Rewrites template constants to the imported page IDs.
* Seeds the configuration folder.
* Creates a Solr core record when possible.
* Creates the viewer content elements on the imported viewer page.

Why:

* The CLI setup must now work without TYPO3 automatic extension imports.
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
