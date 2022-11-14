# PxDbsequencer Changelog

0.10.2 - 2022-11-13
------------------
* [TASK] adds LICENSE and README

0.9.2 - 2022-11-13
------------------
* [TASK] updates composer.json -> updates typo3/cms-core version requirement

0.7.1 - 2022-11-13
------------------
* [TASK] updates composer.json -> updates typo3/cms-core version requirement

0.6.3 - 2022-11-13
------------------
* [TASK] updates composer.json -> fixes replace syntax again and adds typo3/cms-core requirement
* [TASK] adds .gitignore

0.9.1 - 2021-05-04
------------------
* [TASK] updates composer.json

0.10.1 - 2021-04-27
-------------------
* [BUGFIX] updates TYPO3Service constructor to use new $GLOBALS['TYPO3_CONF_VARS'] structure

#### 2021-03-09
* [CLEANUP] updates composer.json

0.10.0 - 2020-06-16
-------------------
* [BUGFIX] changes DataHandler->currentSuggestUid to public to prevent exceptions in DataHandlerHook
* [TASK] updates extension assets to v10 extension schema

0.6.2 - 2020-03-30
------------------
* [TASK] updates composer.json to fix replace syntax

0.9.0 - 2020-03-27
------------------
* [TASK] updates DataHandler for changes in TYPO3 9.5 and compatibility with fluidtypo3/flux
* [TASK] updates composer.json to fix replace syntax

0.8.0 - 2019-01-02
------------------
* [TASK] updates DataHandler to TYPO3 9.5
* [TASK] replace $GLOBALS['TYPO3_DB'] with doctrine connections
* [TASK] updates ext_emconf with TYPO3 9.5 dependency

0.7.0 - 2018-01-18
------------------
* [TASK] updates DataHandler to TYPO3 8.7
* [TASK] updates ext_emconf with TYPO3 8.7 dependency
* [TASK] updates license in composer.json for packagist compatibility

0.6.1 - 2017-02-24
------------------
* [BUGFIX] add use statement for ArrayUtility in DataHandler

0.6.0 - 2016-05-25
------------------
* adds boot method to `ext_localconf.php`
* replaces className syntax with new `::class` syntax
* adds 

0.5.2 - 2015-12-15
------------------
* fix dependencies in ext_emconf

0.5.1 - 2015-12-14
------------------
* adds composer.json

0.5.0 - 2015-10-12
------------------
* overwrite \TYPO3\CMS\Core\DataHandling\DataHandler -> insertDB() as well:
** allow $suggestedUid db-sequencing for non-admin BE Users
