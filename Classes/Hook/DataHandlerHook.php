<?php

/*
 * This file is part of the package portrino/px_dbsequencer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Portrino\PxDbsequencer\Hook;

use Doctrine\DBAL\Driver\Exception;
use Portrino\PxDbsequencer\DataHandling\DataHandler;
use Portrino\PxDbsequencer\Service\SequencerService;
use Portrino\PxDbsequencer\Service\TYPO3Service;

/**
 * DataHandlerHook
 */
class DataHandlerHook
{
    private TYPO3Service $TYPO3Service;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->TYPO3Service = new TYPO3Service(new SequencerService());
    }

    /**
     * Hook: processDatamap_preProcessFieldArray
     *
     * @param string $status
     * @param string $table
     * @param mixed $id
     * @param array<string, mixed> $fieldArray
     * @param DataHandler $pObj
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        mixed $id,
        array &$fieldArray,
        DataHandler &$pObj
    ): void {
        if (str_contains($id, 'NEW') && $this->TYPO3Service->needsSequencer($table)) {
            $newId = $this->TYPO3Service->getSequencerService()->getNextIdForTable($table);
            if ($newId > 0) {
                $pObj->currentSuggestUid = $newId;
                $pObj->suggestedInsertUids[$table . ':' . $newId] = true;
            } else {
                $pObj->currentSuggestUid = 0;
            }
        }
    }
}
