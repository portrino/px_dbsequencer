# PxDbsequencer Change log

0.6.2 - 2020-03-30
------------------
* [TASK] updates composer.json to fix replace syntax

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
