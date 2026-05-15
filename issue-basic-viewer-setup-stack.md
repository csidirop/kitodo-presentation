# Issue Title

Introduce a standalone Basic Viewer with (semi-) automatic pagetree and new tenant cli setup flows

# Issue Description

## Summary

As some of you know I had worked on some features which we had in mind for quite some time. A few weeks (or months?) ago I was thinking that a working viewer would be a good idea for multiple usecases. Since around v4 we weren't able to get working viewer thats why we relied on the DFG viewer and the slub digitalcollections. While this worked and both extensions are great, that made development and testing way (!!) more difficult for us.
So with the new RBO project (and some prommising testing ideas of @stweil for the future) I tought we should finally get the viewer working. And aftrer some back and forth and with the help of more or less helpfull AI I got it working. But I did not stop there! I also added a CLI command to set up the pagetree and site configuration for the viewer and a CLI command to apply tenant defaults to an existing configuration folder. So now we have a working viewer, a way to set up the pagetree and site configuration for it, and a way to apply tenant defaults from the CLI.

After a longer break I revisited that code, cleaned it up, squashed and split it into meaningful PRs to make it easier to review and to have a more structured review process.

**The tldr;** Kitodo.Presentation should provide a basic standalone viewer setup that can be created and maintained independently from other extensions. The goal of this issue is to introduce a series of PR (or a PR stack) that delivers the following:

1. a standalone Basic Viewer foundation (with manual setup documentation)
2. a full automated root page tree / site setup flow via the backend module and CLI
3. a new tenant setup flow for the CLI

These parts are being delivered as a stacked series of PRs. Hope this is fine. If not I can also try to split them.

## PR "stack"

### PR 1: Basic Viewer foundation

Scope:
- standalone Basic Viewer TypoScript
- viewer shell and optional landing page
- default viewer styling
- manual viewer setup documentation

### PR 2: Root page tree setup

Scope:
- backend UI option and CLI command for root page tree creation
- root page creation
- site configuration creation
- `Viewer` page and `Kitodo Configuration` folder creation
- root `sys_template` creation and constant wiring

### PR 3: Tenant setup

Scope:
- CLI command for tenant setup against an existing configuration folder
- shared tenant defaults service

Disclaimer: For some parts (like the typoscript files) I used AI.