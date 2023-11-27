<?php

/*
 * This file is part of the package portrino/px_dbsequencer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Portrino\PxDbsequencer\Hook;

use Portrino\PxDbsequencer\DataHandling\DataHandler;
use Portrino\PxDbsequencer\Service;

/**
 * DataHandlerHook
 */
class DataHandlerHook
{
    /**
     * @var Service\TYPO3Service
     */
    private $TYPO3Service;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->TYPO3Service = new Service\TYPO3Service(new Service\SequencerService());
    }

    /**
     * Hook: processDatamap_preProcessFieldArray
     *
     * @param string $status
     * @param string $table
     * @param mixed $id
     * @param array<mixed> $fieldArray
     * @param DataHandler $pObj
     * @throws \Exception
     */
    public function processDatamap_postProcessFieldArray($status, $table, $id, &$fieldArray, &$pObj): void
    {
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
