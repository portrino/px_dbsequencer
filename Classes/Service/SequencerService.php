<?php
namespace Portrino\DbSequencer\Service;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Andre Wuttig <wuttig@portrino.de>, portrino GmbH
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

/**
 * SequenzerService is used to generate system wide independent IDs
 *
 * @package px_dbsequencer
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
class SequencerService {

    /**
     * @var string
     */
    private $sequenceTable = 'tx_pxdbsequencer_sequence';

    /**
     * @var integer
     */
    private $defaultStart = 0;

    /**
     * @var integer
     */
    private $defaultOffset = 1;

    /**
     * @var integer
     */
    private $checkInterval = 120;

    /**
     * Sets the default start
     *
     * @param int $defaultStart
     * @return void
     */
    public function setDefaultStart($defaultStart) {
        $this->defaultStart = $defaultStart;
    }

    /**
     * Returns the default start
     *
     * @return int
     */
    public function getDefaultStart() {
        return $this->defaultStart;
    }

    /**
     * Returns the next free id in the sequence of the table
     *
     * @param string $table
     * @param int $depth
     * @throws Exception
     * @return int
     */
    public function getNextIdForTable($table, $depth = 0) {
        if ($depth > 10) {
            throw new Exception('The sequencer cannot return IDs for this table -' . $table . ' Too many recursions - maybe to much load?' );
        }
        $where = 'table_name = ' . $GLOBALS['TYPO3_DB']->fullQuoteStr($table, $this->sequenceTable);
        $row = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow('*', $this->sequenceTable, $where);

        if (!$row || !isset($row['current'])) {
            $this->initSequencerForTable($table);
            return $this->getNextIdForTable($table, ++$depth);
        } elseif ($row['timestamp'] + $this->checkInterval < $GLOBALS['EXEC_TIME']) {
            $defaultStartValue = $this->getDefaultStartValue($table);
            $isValueOutdated = ($row['current'] < $defaultStartValue);
            $isOffsetChanged = ($row['offset'] != $this->defaultOffset);
            $isStartChanged = ($row['current'] % $this->defaultOffset != $this->defaultStart);
            if ($isValueOutdated || $isOffsetChanged || $isStartChanged) {
                $row['current'] = $defaultStartValue;
            }
        }

        $new = $row['current'] + $row['offset'];
        $updateTimeStamp = $GLOBALS['EXEC_TIME'];

        $where = 'timestamp=' . (int)$row['timestamp'] . ' AND table_name = ' . $GLOBALS['TYPO3_DB']->fullQuoteStr($table, $this->sequenceTable);
        $fieldValues = array(
            'current' => $new,
            'timestamp' => $updateTimeStamp
        );
        $res = $GLOBALS['TYPO3_DB']->exec_UPDATEquery($this->sequenceTable, $where, $fieldValues);
        if ($res && $GLOBALS['TYPO3_DB']->sql_affected_rows() > 0) {
            return $new;
        } else {
            return $this->getNextIdForTable($table, ++$depth);
        }
    }

    /**
     * If no scheduler entry for the table yet exists, this method initializes the sequencer to fit offset, start and current max value in the table
     *
     * @param string $table
     * @return void
     */
    private function initSequencerForTable($table) {
        $start = $this->getDefaultStartValue($table);
        $fieldValues =  array(
            'table_name' => $table,
            'current' => $start,
            'offset' => (int)$this->defaultOffset,
            'timestamp' => $GLOBALS['EXEC_TIME']
        );
        $GLOBALS['TYPO3_DB']->exec_INSERTquery($this->sequenceTable, $fieldValues);
    }

    /**
     * Returns the default start value for the given table
     *
     * @param string $table
     * @param integer
     * @return int
     */
    private function getDefaultStartValue($table) {
        $select = 'max(uid) as max';
        $where = '1=1';
        $row = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow($select, $table, $where);
        $currentMax = $row['max'] + 1;
        $start = $this->defaultStart + ($this->defaultOffset * ceil ($currentMax / $this->defaultOffset));
        return $start;
    }

    /**
     * Sets the default offset
     *
     * @param int $defaultOffset
     * @return void
     */
    public function setDefaultOffset($defaultOffset) {
        $this->defaultOffset = $defaultOffset;
    }

    /**
     * Returns the default offset
     *
     * @return int
     */
    public function getDefaultOffset() {
        return $this->defaultOffset;
    }

}