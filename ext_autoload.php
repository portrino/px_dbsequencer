<?php
$extensionPath = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('px_dbsequencer');
return array(
    'Portrino\DbSequencer\Service\TYPO3Service' => $extensionPath . 'Classes/Service/TYPO3Service.php',
    'Portrino\DbSequencer\Service\SequencerService' => $extensionPath . 'Classes/Service/SequencerService.php'
);
?>