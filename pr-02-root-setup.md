# PR Title

Add a automated bootstrap root page tree setup

# PR Description

## Summary

This PR adds a automated root page tree setup via the new tenant module or CLI.

The bootstrap pagetree looks like one the DFG-Viewer sets up, with a root page, a viewer page, and a configuration folder. It also creates a site configuration and root TypoScript template with the required sets and constants to render the Basic Viewer.

## TODO - add a screenshot of the page tree here

It introduces the logic needed to create a new root page, site configuration, viewer page, configuration folder, and root TypoScript template for the standalone Basic Viewer.

This PR builds on top of the Basic Viewer foundation.
## TODO Add link to PR

## Changes

- add the CLI command: `php /var/www/typo3/vendor/bin/typo3 kitodo:setup`
- add `BootstrapRootSetupService` to create the page tree and site configuration
- add TYPO3 site configuration from `Resources/Private/Data/BootstrapSiteConfig.yaml`
- include the required TypoScript sets:
  - `Basic Configuration`
  - `Install Basic Viewer`
- write the required template constants:
  - `plugin.tx_dlf.persistence.storagePid`
  - `plugin.tx_dlf.basicViewer.rootPid`
  - `plugin.tx_dlf.basicViewer.viewerPid`
- trigger a backend page tree refresh after creating the default page tree from the module!!
- introduces a new CLI command to run the setup from the command line, which may be useful for scripting and testing purposes: 
  - `kitodo:setup` with default values or 
  - with custom values:
    - `--identifier=<CUSTOM_IDENTIFIER>`
    - `--base=/<CUSTOM_BASE>/`
    - `--root-title="<CUSTOM_ROOT_TITLE>"` \
    - `--root-slug=/<CUSTOM_ROOT_SLUG>/` \
    - `--viewer-slug=/<CUSTOM_VIEWER_SLUG>/` \
