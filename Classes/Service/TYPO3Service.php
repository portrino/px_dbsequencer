<?php

/*
 * This file is part of the package portrino/px_dbsequencer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Portrino\PxDbsequencer\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * TYPO3Service
 */
class TYPO3Service
{

    /**
     * @var SequencerService
     */
    private $sequencerService;

    /**
     * @var array Array of configured tables that should call the sequencer
     */
    private $supportedTables;

    /**
     * Constructor
     *
     * @param SequencerService $sequencer
     * @param array|null $conf
     */
    public function __construct(SequencerService $sequencer, ?array $conf = null)
    {
        $this->sequencerService = $sequencer;
        if (is_null($conf)) {
            $conf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['px_dbsequencer'];
        }

        $this->sequencerService->setDefaultOffset((int)$conf['offset']);
        $this->sequencerService->setDefaultStart((int)$conf['system']);
        $this->supportedTables = GeneralUtility::trimExplode(',', $conf['tables']);
    }

    /**
     * Returns, whether a table is configured to use the sequencer
     *
     * @param string $tableName
     * @return boolean
     */
    public function needsSequencer(string $tableName): bool
    {
        return in_array($tableName, $this->supportedTables, true);
    }

    /**
     * Sets sequencer service
     *
     * @param SequencerService $sequencerService
     * @return void
     */
    public function setSequencerService(SequencerService $sequencerService): void
    {
        $this->sequencerService = $sequencerService;
    }

    /**
     * Returns sequencer service
     *
     * @return SequencerService
     */
    public function getSequencerService(): SequencerService
    {
        return $this->sequencerService;
    }
}
