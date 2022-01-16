<?php

namespace Portrino\PxDbsequencer\Hook;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2016 Andre Wuttig <wuttig@portrino.de>, portrino GmbH
 *           Axel Boeswetter <boeswetter@portrino.de>, portrino GmbH
 *           Thomas Griessbach <griessbach@portrino.de>, portrino GmbH
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Portrino\PxDbsequencer\DataHandling\DataHandler;
use Portrino\PxDbsequencer\Service;

/**
 * Class DataHandlerHook
 *
 * @package Portrino\PxDbsequencer\Hook
 */
class DataHandlerHook
{
    /**
     * @var Service\TYPO3Service
     */
    private $TYPO3Service;

    /**
     * Constructor
     *
     * @return void
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
     * @param array $fieldArray
     * @param DataHandler $pObj
     * @return void
     * @throws \Exception
     */
    public function processDatamap_postProcessFieldArray($status, $table, $id, &$fieldArray, &$pObj)
    {
        if (strpos($id, 'NEW') !== false && $this->TYPO3Service->needsSequencer($table)) {
            $newId = $this->TYPO3Service->getSequencerService()->getNextIdForTable($table);
            if ($newId) {
                $pObj->currentSuggestUid = $newId;
                $pObj->suggestedInsertUids[$table . ':' . $newId] = true;
            } else {
                $pObj->currentSuggestUid = 0;
            }
        }
    }

}
