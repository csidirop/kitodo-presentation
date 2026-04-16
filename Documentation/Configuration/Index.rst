.. ==================================================
.. FOR YOUR INFORMATION
.. --------------------------------------------------
.. -*- coding: utf-8 -*- with BOM.

.. include:: ../Includes.txt


.. _system_setup:

############
System Setup
############

.. contents::
    :local:
    :depth: 2



**********
Web Server
**********

Content Security Policy
=======================

In case a Content Security Policy is set on the Kitodo.Presentation instance, make sure that ``blob:`` URLs are allowed as ``img-src``.
Otherwise, the page view may remain blank.


***********
TYPO3 Setup
***********

Extension Configuration
=======================

You should check the extension configuration!

* go to the Extension Configuration (:file:`ADMIN TOOLS -> Settings -> Extension Configuration`).
* open dlf
* check and save the configuration

Tenant Configuration
=======================

You must create a data folder for some Kitodo.Presentation configuration records like metadata,
structures, solrCore and formats (namespaces). This can be achieved easily with the 'New Tenant'
backend module on the left side in section 'Tools'.

Make sure, all fields are green. Then all necessary records are created.

TYPO3 Configuration
===================

Disable caching in certain situations
-------------------------------------

Navigation Plugin
~~~~~~~~~~~~~~~~~

The *navigation plugin* provides a page selection dropdown input field. The
resulting action url cannot contain a valid cHash value.

The default behaviour of TYPO3 is to call the pageNotFound handler and/or
to show an exception:

.. figure:: ../Images/Configuration/typo3_pagenotfoundonchasherror.png
   :width: 820px
   :alt: TYPO3 Error-Message "Reason: Request parameters could not be validated (&cHash empty)"

   TYPO3 Error-Message "Reason: Request parameters could not be validated (&cHash empty)"

This is not the desired behaviour. You should disable
:code:`$TYPO3_CONF_VARS['FE']['pageNotFoundOnCHashError'] = 0` to show the
requested page instead. The caching will be disabled in this case. This was
the default behaviour before TYPO3 6.x.

.. figure:: ../Images/Configuration/New_TYPO3_site.png
   :width: 820px
   :alt: TYPO3 Configuration of pageNotFoundOnCHashError in Install Tool

   TYPO3 Configuration of pageNotFoundOnCHashError in Settings Module

This configuration is written to *typo3conf/LocalConfiguration.php*::

    'FE' => [
            'pageNotFoundOnCHashError' => '0',
        ],


Avoid empty Workview
~~~~~~~~~~~~~~~~~~~~

You may notice from time to time, the viewer page stays empty even though you
pass the :code:`tx_dlf[id]` parameter.

This happens, if someone called the viewer page without any parameters or with parameters
without a valid cHash. In this case, TYPO3 saves the page to its cache. If you call the
viewer page again with any parameter and without a cHash, the cached page is
delivered.

With the search plugin or the searchInDocument tool this may disable the search functionality.

To avoid this, you must configure :code:`tx_dlf[id]` to require a cHash. Of
course this is impossible to achieve so the system will process the page uncached.

Add this setting to your *typo3conf/LocalConfiguration.php*::

    'FE' => [
        'cacheHash' => [
            'requireCacheHashPresenceParameters' => [
                'tx_dlf[id]',
            ],
        ],
    ]

Tip: Use the admin backend module: Settings -> Configure Installation-Wide Options


TypoScript Basic Configuration
------------------------------

Please include the Template "Basic Configuration". This template adds
jQuery to your page by setting the following typoscript:

:typoscript:`page.includeJSlibs.jQuery`


Manual Viewer Setup
----------------------------

The regular viewer is a TYPO3 page built from the ``Page View`` plugin together with additional
plugins such as ``Navigation``, ``Toolbox``, ``Metadata`` and ``Table Of Contents``.

Recommended minimum setup
~~~~~~~~~~~~~~~~~~~~~~~~~

1. Create a dedicated TYPO3 data folder, for example ``Kitodo Configuration``.
2. Create a dedicated TYPO3 page for the viewer, for example ``/viewer`` or ``/show``.
3. Create or edit a TYPO3 template record on that page and include the
   TypoScript template ``Basic Configuration``.
   Do this in the template record itself, for example via the TYPO3
   ``Template`` module or by editing the ``Site Set/TypoScript Template``
   record. The field shown in the page properties under ``Resources`` is for
   static Page TSconfig and is not the correct place for this step.
4. Set the TypoScript constant ``plugin.tx_dlf.persistence.storagePid`` to the Kitodo Configuration data folder.
   Set this constant in the Constant Editor or in your site package TypoScript
   constants to the page UID of the data folder created in step 1.
5. Place the plugin ``Page View`` on the viewer page.
6. Place the plugin ``Navigation`` on the same page.
7. Optionally place ``Toolbox``, ``Metadata`` and ``Table Of Contents`` on the same page as well.

The ``Page View`` plugin renders the actual image viewer. The surrounding
viewer functionality is then added by the other plugins.

Viewer page plugins
~~~~~~~~~~~~~~~~~~~

The following combination is a good starting point for a normal document
viewer page:

* ``Page View`` for the OpenLayers based image viewer
* ``Navigation`` for paging, page selection and double-page mode
* ``Toolbox`` for tools such as fulltext, image download, rotation or
  search-in-document
* ``Metadata`` for descriptive metadata
* ``Table Of Contents`` for the document structure

If you want only a minimal page, ``Page View`` and ``Navigation`` are usually
enough.

Important plugin settings
~~~~~~~~~~~~~~~~~~~~~~~~~

``Page View``
   Keep the default ``elementId`` unless you also adjust your template.
   ``useInternalProxy`` is optional and mainly relevant when remote files need
   to be proxied through TYPO3.

``Navigation``
   Configure the features you want to expose. If you activate the ``listView``
   feature, set ``targetPid`` to the page that contains the ``List View``
   plugin.

``Toolbox``
   Add the tools you want to offer. Some tools need additional data:

   * ``searchInDocumentTool`` requires a Solr core configuration.
   * ``fulltextTool`` and ``fulltextDownloadTool`` only work if the document
     provides fulltext files.
   * ``imageDownloadTool`` and ``pdfDownloadTool`` depend on matching METS file
     groups.

``Metadata`` and ``Table Of Contents``
   If these plugins should link back to the viewer page, set their
   ``targetPid`` to the UID of the viewer page.

Corresponding pages
~~~~~~~~~~~~~~~~~~~

The viewer page is usually only one part of the complete frontend setup.
Related pages should point to it explicitly:

* ``Search`` should set ``targetPidPageView`` to the viewer page.
* ``Collection`` and ``List View`` should also link to the viewer page where
  appropriate.
* ``Navigation`` may point back to a separate result list page via
  ``targetPid`` when the ``listView`` feature is enabled.

This way, search results, metadata links and structure links all resolve to the
same document viewer page.

Request parameters
~~~~~~~~~~~~~~~~~~

The regular viewer is driven by ``tx_dlf`` request parameters. The most common
ones are:

* ``tx_dlf[id]``: document identifier or document URL
* ``tx_dlf[page]``: physical page number
* ``tx_dlf[double]``: enable double-page mode with ``1``
* ``tx_dlf[highlight_word]``: highlight a word in the page view

Example URLs:

.. code-block:: text

   /viewer?tx_dlf[id]=https%3A%2F%2Fexample.org%2Fmets.xml
   /viewer?tx_dlf[id]=https%3A%2F%2Fexample.org%2Fmets.xml&tx_dlf[page]=12
   /viewer?tx_dlf[id]=https%3A%2F%2Fexample.org%2Fmets.xml&tx_dlf[page]=12&tx_dlf[double]=1

Manual test checklist
~~~~~~~~~~~~~~~~~~~~~

After finishing the setup, verify the following:

* Opening the viewer page with ``tx_dlf[id]`` loads the document.
* Changing ``tx_dlf[page]`` changes the displayed page.
* The navigation buttons keep the user on the intended viewer page.
* Metadata and table-of-contents links point to the same viewer page.
* Fulltext and toolbox functions only appear when the current document
  provides the required files.


Slug Configuration
------------------

With TYPO3 9.5 it is possible to make speaking urls with the builtin advanced
routing feature ("Slug"). This may be used for extensions too.

TYPO3 documentation about `Advanced Routing Configuration <https://docs.typo3.org/m/typo3/reference-coreapi/9.5/en-us/ApiOverview/Routing/AdvancedRoutingConfiguration.html>`_.

The following code is an example of an routeEnhancer for the workview page on uid=14.

.. code-block:: yaml
   :linenos:

   routeEnhancers:
     KitodoWorkview:
       type: Plugin
       namespace: tx_dlf
       limitToPages:
         - 14
       routePath: '/{id}/{page}'
       requirements:
         id: '(\d+)|(http.*xml)'
         page: \d+
     KitodoWorkviewDouble:
       type: Plugin
       namespace: tx_dlf
       limitToPages:
         - 14
       routePath: '/{id}/{page}/{double}'
       requirements:
         id: '(\d+)|(http.*xml)'
         page: \d+
         double: '[0-1]'


.. _configuration-solr:

*****************
Solr Installation
*****************

This extension doesn't include Solr, but just a prepared configuration set.
To setup Apache Solr, perform the following steps:

1. Make sure you have Apache Solr 8.11 and running.

   Download Solr from https://solr.apache.org/downloads.html.
   Other versions may work but are not tested.

2. Copy the config set to your solr home

.. code-block:: bash

      cp -r dlf/Configuration/ApacheSolr/configsets/dlf to $SOLR_HOME/configsets/

3. Get the Solr OCR Highlighting plugin and put it into contrib-directory.

   The plugin is available on GitHub: https://github.com/dbmdz/solr-ocrhighlighting/releases.
   The documentation can be found here: https://dbmdz.github.io/solr-ocrhighlighting/.

   The Solr OCR Highlighting plugin is required for full text search as of Kitodo.Presentation 3.3.

.. code-block:: bash

      cp solr-ocrhighlighting-0.7.1.jar to contrib/ocrsearch/lib/

4. Using basic authentication is optional but recommended.

   The documentation is available here:
   https://solr.apache.org/guide/8_8/basic-authentication-plugin.html


.. _configuration-typoscript:
