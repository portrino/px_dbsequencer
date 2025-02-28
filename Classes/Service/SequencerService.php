<?php

/*
 * This file is part of the package portrino/px_dbsequencer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Portrino\PxDbsequencer\Service;

use Doctrine\DBAL\Driver\Exception;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * SequencerService
 */
class SequencerService
{
    /**
     * @var string
     */
    private string $sequenceTable = 'tx_pxdbsequencer_sequence';

    /**
     * @var int
     */
    private int $defaultStart = 0;

    /**
     * @var int
     */
    private int $defaultOffset = 1;

    /**
     * @var ConnectionPool
     */
    private ConnectionPool $connectionPool;

    /**
     * @var Context
     */
    private Context $context;

    /**
     * SequencerService constructor.
     */
    public function __construct()
    {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->context = GeneralUtility::makeInstance(Context::class);
    }

    /**
     * Sets the default start
     *
     * @param int $defaultStart
     */
    public function setDefaultStart(int $defaultStart): void
    {
        $this->defaultStart = $defaultStart;
    }

    /**
     * Returns the default start
     *
     * @return int
     */
    public function getDefaultStart(): int
    {
        return $this->defaultStart;
    }

    /**
     * Returns the next free id in the sequence of the table
     *
     * @param string $table
     * @param int $depth
     * @return int
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public function getNextIdForTable(string $table, int $depth = 0): int
    {
        if ($depth > 10) {
            throw new \RuntimeException(
                'The sequencer cannot return IDs for this table -' . $table . ' Too many recursions - maybe to much load?',
                1740753828
            );
        }

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($this->sequenceTable);
        $row = $queryBuilder->select('*')
                            ->from($this->sequenceTable)
                            ->where(
                                $queryBuilder->expr()->eq('table_name', $queryBuilder->createNamedParameter($table))
                            )
                            ->executeQuery()
                            ->fetchAssociative();

        if ($row === false || !isset($row['current'])) {
            $this->initSequencerForTable($table);
            return $this->getNextIdForTable($table, ++$depth);
        }

        $sequencedStartValue = $this->getSequencedStartValue($table);
        $isValueOutdated = ($row['current'] < $sequencedStartValue);
        if ($isValueOutdated) {
            $row['current'] = $sequencedStartValue;

            $fieldValues = [
                'current' => $row['current'],
                'timestamp' => $this->context->getPropertyFromAspect('date', 'timestamp'),
            ];

            $this->connectionPool->getConnectionForTable($this->sequenceTable)->update(
                $this->sequenceTable,
                $fieldValues,
                [
                    'table_name' => $table,
                    'timestamp' => (int)$row['timestamp'],
                ]
            );
            return $this->getNextIdForTable($table, ++$depth);
        }
        return $row['current'];
    }

    /**
     * If no scheduler entry for the table yet exists, this method initializes the sequencer to fit offset, start and current max value in the table
     *
     * @param string $table
     * @throws Exception
     */
    private function initSequencerForTable(string $table): void
    {
        $start = $this->getSequencedStartValue($table);
        $fieldValues = [
            'table_name' => $table,
            'current' => $start,
            'offset' => $this->defaultOffset,
            'timestamp' => $this->context->getPropertyFromAspect('date', 'timestamp'),
        ];

        $databaseConnectionForPages = $this->connectionPool->getConnectionForTable($this->sequenceTable);
        $databaseConnectionForPages->insert($this->sequenceTable, $fieldValues);
    }

    /**
     * Returns the default start value for the given table
     *
     * @param string $table
     * @return int
     * @throws \Doctrine\DBAL\Exception
     */
    private function getSequencedStartValue(string $table): int
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $currentMax = $queryBuilder->select('uid')
                                   ->from($table)
                                   ->orderBy('uid', 'DESC')
                                   ->setMaxResults(1)
                                   ->executeQuery()
                                   ->fetchOne();

        return (int)($this->defaultStart + ($this->defaultOffset * ceil($currentMax / $this->defaultOffset)));
    }

    /**
     * Sets the default offset
     *
     * @param int $defaultOffset
     */
    public function setDefaultOffset(int $defaultOffset): void
    {
        $this->defaultOffset = $defaultOffset;
    }

    /**
     * Returns the default offset
     *
     * @return int
     */
    public function getDefaultOffset(): int
    {
        return $this->defaultOffset;
    }
}
