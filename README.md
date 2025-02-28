# TYPO3 extension `px_dbsequencer`

[![Latest Stable Version](https://poser.pugx.org/portrino/px_dbsequencer/v/stable)](https://packagist.org/packages/portrino/px_dbsequencer)
[![TYPO3 13](https://img.shields.io/badge/TYPO3-13-orange.svg)](https://get.typo3.org/version/13)
[![Total Downloads](https://poser.pugx.org/portrino/px_dbsequencer/downloads)](https://packagist.org/packages/portrino/px_dbsequencer)
[![Monthly Downloads](https://poser.pugx.org/portrino/px_dbsequencer/d/monthly)](https://packagist.org/packages/portrino/px_dbsequencer)
[![CI](https://github.com/portrino/px_dbsequencer/actions/workflows/ci.yml/badge.svg)](https://github.com/portrino/px_dbsequencer/actions/workflows/ci.yml)

> Database Sequencer for TYPO3

## 1 Features

The **PxDbsequencer** extension enables the possibility to define different unique keys for the configured tables 
(e.g.: pages, pages_language_overlay, tt_content).

That means, you define a global identifier, e.g. per environment, and every identifier/ primary key of the configured 
table(s) will be sequenced in steps of a defined offset (default: 10). 

So, if configure the global identifier "1" for your production system, then every configured table will have 
identifiers/ primary keys like 1, 11, 21, 31 and so on. For your staging system, you may define the global identifier 
"2", which than results in table identifiers like 2, 12, 22, 32 and so on.

In addition, every developer of the project can have his own global identifier as well. Therefor the risk of overriding 
data, that has to be migrated between systems (e.g. pages and content elements for a new feature), will be minimized.

## 2 Usage

### 2.1 Installation

#### Installation using Composer

The **recommended** way to install the extension is using [composer](https://getcomposer.org/).

Run the following command within your Composer based TYPO3 project:

```
composer require portrino/px_dbsequencer
```

#### Installation as extension from TYPO3 Extension Repository (TER)

Download and install the [extension](https://extensions.typo3.org/extension/px_dbsequencer) with the extension manager 
module.

### 2.2 Setup

After finishing the installation, head over to the extension settings and set the system identifier, the offset and the
tables you'd like to sequence.

The extension settings, like the system identifier, can also be configured depending on the current `TYPO3_CONTEXT` via 
`config/system/additional.php`

SO, a possible configuration in `config/system/settings.php` could look like:

```
return [

    ...
    
    'EXTENSIONS' => [
    
        ...
        
        'px_dbsequencer' => [
            'offset' => 10,
            'system' => 1,
            'tables' => 'be_groups,be_users,pages,sys_category,sys_template,tt_content',
        ],
        
        ...
        
    ],
    
    ...
    
];
```

and in `config/system/additional.php` could be something like:

```
// contextual environment settings
$applicationContext = \TYPO3\CMS\Core\Utility\GeneralUtility::trimExplode(
    '/',
    \TYPO3\CMS\Core\Core\Environment::getContext(),
    true
);

switch ($applicationContext[0]) {
    case 'Development':
        switch ($applicationContext[1]) {
            case 'Staging':
                // TYPO3_CONTEXT: Development/Staging
                $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['px_dbsequencer']['system'] = 2;
                break;
            case 'Local':
                // TYPO3_CONTEXT: Development/Local
                $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['px_dbsequencer']['system'] = 3;
                break;
        }
        break;
    case 'Production':
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['px_dbsequencer']['system'] = 1;
        break;
}
```

## 3 Compatibility

| PxDbsequencer | TYPO3 | PHP       | Support / Development                |
|---------------|-------|-----------|--------------------------------------|
| 13.x          | 13.4  | 8.2 - 8.3 | features, bugfixes, security updates | 
| 0.12.x        | 12.4  | 8.1 - 8.2 | features, bugfixes, security updates | 
| 0.11.x        | 11.5  | 7.4 - 8.1 | bugfixes, security updates           |
| 0.10.x        | 10.4  | 7.2 - 7.4 | none                                 |
| 0.9.x         | 9.5   | 7.2 - 7.4 | none                                 |
| 0.7.x         | 8.7   | 7.0 - 7.4 | none                                 |
| 0.6.x         | 7.6   | 5.5 - 7.3 | none                                 |

## 4 Authors

* See the list of [contributors](https://github.com/portrino/px_dbsequencer/graphs/contributors) who participated in this project.
