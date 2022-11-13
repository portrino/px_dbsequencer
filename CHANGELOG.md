# PxDbsequencer Changelog

0.6.3 - 2022-11-13
------------------
* [TASK] updates composer.json -> fixes replace syntax again and adds typo3/cms-core requirement
* [TASK] adds .gitignore

0.6.2 - 2020-03-30
------------------
* [TASK] updates composer.json to fix replace syntax

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
