<?php

$config = \TYPO3\CodingStandards\CsFixerConfig::create();
$config->getFinder()->exclude(['node_modules', 'var'])->in(__DIR__ . '/..');
return $config;
