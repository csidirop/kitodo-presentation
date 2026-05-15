# PR Title

Add a standalone Basic Viewer

# PR Description

## Summary

This PR adds a first iteration of a minimal standalone "Basic Viewer".

### TODO Add a screenshot of the viewer here

It adds the viewer-facing TypoScript, templates, styling, and documentation needed to render a minimal standalone viewer (and a temporary (?) landing page independently).

## Changes

- adds a dedicated `BasicViewer` TypoScript set
- adds a (temporary?) landing page and viewer shell
  - The landing page is intentionally provisional and should be reviewed for direction. It found it helpful to have a landing page to test the viewer and to have a place to link to from the viewer when no manifest is loaded, but it may not be necessary or desirable in the long or even short term. Maybe thats more something for the DFG-Viewer as thats more of a use case for them? Open for discussion. Easy too remove if we decide we don't need it.
- adds the dedicated default viewer styling
- adds documentation for the manual viewer setup.

### FOR THE FIRST COMMENT:
### Manual viewer setup:

The steps to set up the viewer manually in the backend are as follows:

1. Create a root page (``Create a regular page > Edit page properties > Behavior > Use as root page``).
2. Create a TYPO3 site configuration for that root page in `Site Management > Sites`.
3. Create these children below the root page:
   - `Viewer` -> Type: Standard
   - `Kitodo Configuration` -> Type: Folder
4. Create a TypoScript template record (`sys_template`) on the root page:
   - `Site Management > TypoScript > [select root page] > Create a root TypoScript record`
5. In that record `Edit the whole TypoScript record` and under the Tab `General`:
   - set the title
   - clear any prefilled content in the `Setup` field
   - set the `Constants` field to the required values:
      ```typoscript
      plugin.tx_dlf.persistence.storagePid = <uid of Kitodo Configuration>
      plugin.tx_dlf.basicViewer.rootPid = <uid of root page>
      plugin.tx_dlf.basicViewer.viewerPid = <uid of Viewer page>
      ```
   - Under the the tab `Advanced Options`:
      - enable `Rootlevel`
      - Include TypoScript sets:
        - `Basic Configuration`
        - `Install Basic Viewer`
6. Apply tenant defaults to the configuration folder
