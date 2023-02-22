<?php
defined('TYPO3') || die();

(function () {

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['tx_pxdbsequencer'] =
        \Portrino\PxDbsequencer\Hook\DataHandlerHook::class;

    // xclass the core DataHandler, because within the workspace condition of the process_datamap() method the
    // insertDB() method is called without the $suggestedUid parameter to overrule the UID during record creation
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\TYPO3\CMS\Core\DataHandling\DataHandler::class] = [
        'className' => \Portrino\PxDbsequencer\DataHandling\DataHandler::class
    ];

})();
