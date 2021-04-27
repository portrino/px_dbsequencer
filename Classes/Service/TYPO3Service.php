<?php
namespace Portrino\PxDbsequencer\Service;

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

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class TYPO3Service
 *
 * @package Portrino\PxDbsequencer\Service
 */
class TYPO3Service {

	/**
	 * @var SequencerService
	 */
	private $sequencerService;

	/**
	 * @var array
	 */
	private $conf;

	/**
	 * @var array Array of configured tables that should call the sequencer
	 */
	private $supportedTables;

    /**
     * Constructor
     *
     * @param SequencerService $sequencer
     * @param array|NULL $conf
     * @return TYPO3Service
     */
    public function __construct(SequencerService $sequencer, $conf = NULL) {
		$this->sequencerService = $sequencer;
		if (is_null($conf)) {
			$this->conf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['px_dbsequencer'];
		} else {
			$this->conf = $conf;
		}

		$this->sequencerService->setDefaultOffset((int)$this->conf['offset']);
		$this->sequencerService->setDefaultStart((int)$this->conf['system']);
		$this->supportedTables = GeneralUtility::trimExplode(',', $this->conf['tables']);
	}

	/**
	 * Returns, whether a table is configured to use the sequencer
	 *
	 * @param string $tableName
	 * @return boolean
	 */
	public function needsSequencer($tableName) {
		return in_array($tableName, $this->supportedTables);
	}

    /**
     * Sets sequencer service
     *
     * @param SequencerService $sequencerService
     * @return void
     */
    public function setSequencerService($sequencerService) {
        $this->sequencerService = $sequencerService;
    }

    /**
     * Returns sequencer service
     *
     * @return SequencerService
     */
    public function getSequencerService() {
        return $this->sequencerService;
    }

}
