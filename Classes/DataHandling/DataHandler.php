<?php

/*
 * This file is part of the package portrino/px_dbsequencer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Portrino\PxDbsequencer\DataHandling;

use Doctrine\DBAL\Exception as DBALException;
use Portrino\PxDbsequencer\Hook\DataHandlerHook;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\Localization\DataMapProcessor;
use TYPO3\CMS\Core\DataHandling\SlugEnricher;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * DataHandler
 */
class DataHandler extends \TYPO3\CMS\Core\DataHandling\DataHandler
{

    /**
     * if > 0 will be used in insertDB()
     *
     * @var int
     */
    public $currentSuggestUid = 0;

    /*********************************************
     *
     * PROCESSING DATA
     *
     *********************************************/

    /**
     * Processing the data-array
     * Call this function to process the data-array set by start()
     *
     * @return bool|void
     */
    public function process_datamap()
    {
        $this->controlActiveElements();

        // Keep versionized(!) relations here locally:
        $registerDBList = [];
        $this->registerElementsToBeDeleted();
        $this->datamap = $this->unsetElementsToBeDeleted($this->datamap);
        // Editing frozen:
        if ($this->BE_USER->workspace !== 0 && ($this->BE_USER->workspaceRec['freeze'] ?? false)) {
            $this->log('sys_workspace', $this->BE_USER->workspace, SystemLogDatabaseAction::VERSIONIZE, 0, SystemLogErrorClassification::USER_ERROR, 'All editing in this workspace has been frozen');
            return false;
        }
        // First prepare user defined objects (if any) for hooks which extend this function:
        $hookObjectsArr = [];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'] ?? [] as $className) {
            $hookObject = GeneralUtility::makeInstance($className);
            if (method_exists($hookObject, 'processDatamap_beforeStart')) {
                $hookObject->processDatamap_beforeStart($this);
            }
            $hookObjectsArr[] = $hookObject;
        }
        // Pre-process data-map and synchronize localization states
        $this->datamap = GeneralUtility::makeInstance(SlugEnricher::class)->enrichDataMap($this->datamap);
        $this->datamap = DataMapProcessor::instance($this->datamap, $this->BE_USER, $this->referenceIndexUpdater)->process();
        // Organize tables so that the pages-table is always processed first. This is required if you want to make sure that content pointing to a new page will be created.
        $orderOfTables = [];
        // Set pages first.
        if (isset($this->datamap['pages'])) {
            $orderOfTables[] = 'pages';
        }
        $orderOfTables = array_unique(array_merge($orderOfTables, array_keys($this->datamap)));
        // Process the tables...
        foreach ($orderOfTables as $table) {
            // Check if
            //	   - table is set in $GLOBALS['TCA'],
            //	   - table is NOT readOnly
            //	   - the table is set with content in the data-array (if not, there's nothing to process...)
            //	   - permissions for tableaccess OK
            $modifyAccessList = $this->checkModifyAccessList($table);
            if (!$modifyAccessList) {
                $this->log($table, 0, SystemLogDatabaseAction::UPDATE, 0, SystemLogErrorClassification::USER_ERROR, 'Attempt to modify table "{table}" without permission', 1, ['table' => $table]);
            }
            if (!isset($GLOBALS['TCA'][$table]) || $this->tableReadOnly($table) || !is_array($this->datamap[$table]) || !$modifyAccessList) {
                continue;
            }

            if ($this->reverseOrder) {
                $this->datamap[$table] = array_reverse($this->datamap[$table], true);
            }
            // For each record from the table, do:
            // $id is the record uid, may be a string if new records...
            // $incomingFieldArray is the array of fields
            foreach ($this->datamap[$table] as $id => $incomingFieldArray) {
                if (!is_array($incomingFieldArray)) {
                    continue;
                }
                $theRealPid = null;

                // Hook: processDatamap_preProcessFieldArray
                foreach ($hookObjectsArr as $hookObj) {
                    if (method_exists($hookObj, 'processDatamap_preProcessFieldArray')) {
                        $hookObj->processDatamap_preProcessFieldArray($incomingFieldArray, $table, $id, $this);
                    }
                }
                // ******************************
                // Checking access to the record
                // ******************************
                $createNewVersion = false;
                $old_pid_value = '';
                // Is it a new record? (Then Id is a string)
                if (!MathUtility::canBeInterpretedAsInteger($id)) {
                    // Get a fieldArray with tca default values
                    $fieldArray = $this->newFieldArray($table);
                    // A pid must be set for new records.
                    if (isset($incomingFieldArray['pid'])) {
                        $pid_value = $incomingFieldArray['pid'];
                        // Checking and finding numerical pid, it may be a string-reference to another value
                        $canProceed = true;
                        // If a NEW... id
                        if (str_contains($pid_value, 'NEW')) {
                            if ($pid_value[0] === '-') {
                                $negFlag = -1;
                                $pid_value = substr($pid_value, 1);
                            } else {
                                $negFlag = 1;
                            }
                            // Trying to find the correct numerical value as it should be mapped by earlier processing of another new record.
                            if (isset($this->substNEWwithIDs[$pid_value])) {
                                if ($negFlag === 1) {
                                    $old_pid_value = $this->substNEWwithIDs[$pid_value];
                                }
                                $pid_value = (int)($negFlag * $this->substNEWwithIDs[$pid_value]);
                            } else {
                                $canProceed = false;
                            }
                        }
                        $pid_value = (int)$pid_value;
                        if ($canProceed) {
                            $fieldArray = $this->resolveSortingAndPidForNewRecord($table, $pid_value, $fieldArray);
                        }
                    }
                    $theRealPid = $fieldArray['pid'];
                    // Checks if records can be inserted on this $pid.
                    // If this is a page translation, the check needs to be done for the l10n_parent record
                    $languageField = $GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? null;
                    $transOrigPointerField = $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? null;
                    if ($table === 'pages'
                        && $languageField && isset($incomingFieldArray[$languageField]) && $incomingFieldArray[$languageField] > 0
                        && $transOrigPointerField && isset($incomingFieldArray[$transOrigPointerField]) && $incomingFieldArray[$transOrigPointerField] > 0
                    ) {
                        $recordAccess = $this->checkRecordInsertAccess($table, $incomingFieldArray[$transOrigPointerField]);
                    } else {
                        $recordAccess = $this->checkRecordInsertAccess($table, $theRealPid);
                    }
                    if ($recordAccess) {
                        $this->addDefaultPermittedLanguageIfNotSet($table, $incomingFieldArray, $theRealPid);
                        $recordAccess = $this->BE_USER->recordEditAccessInternals($table, $incomingFieldArray, true);
                        if (!$recordAccess) {
                            $this->log($table, 0, SystemLogDatabaseAction::INSERT, 0, SystemLogErrorClassification::USER_ERROR, 'recordEditAccessInternals() check failed [{reason}]', -1, ['reason' => $this->BE_USER->errorMsg]);
                        } elseif (!$this->bypassWorkspaceRestrictions && !$this->BE_USER->workspaceAllowsLiveEditingInTable($table)) {
                            // If LIVE records cannot be created due to workspace restrictions, prepare creation of placeholder-record
                            // So, if no live records were allowed in the current workspace, we have to create a new version of this record
                            if (BackendUtility::isTableWorkspaceEnabled($table)) {
                                $createNewVersion = true;
                            } else {
                                $recordAccess = false;
                                $this->log($table, 0, SystemLogDatabaseAction::VERSIONIZE, 0, SystemLogErrorClassification::USER_ERROR, 'Record could not be created in this workspace');
                            }
                        }
                    }
                    // Yes new record, change $record_status to 'insert'
                    $status = 'new';
                } else {
                    // Nope... $id is a number
                    $id = (int)$id;
                    $fieldArray = [];
                    $recordAccess = $this->checkRecordUpdateAccess($table, $id, $incomingFieldArray, $hookObjectsArr);
                    if (!$recordAccess) {
                        if ($this->enableLogging) {
                            $propArr = $this->getRecordProperties($table, $id);
                            $this->log($table, $id, SystemLogDatabaseAction::UPDATE, 0, SystemLogErrorClassification::USER_ERROR, 'Attempt to modify record "{title}" ({table}:{uid}) without permission or non-existing page', 2, ['title' => $propArr['header'], 'table' => $table, 'uid' => $id], $propArr['event_pid']);
                        }
                        continue;
                    }
                    // Next check of the record permissions (internals)
                    $recordAccess = $this->BE_USER->recordEditAccessInternals($table, $id);
                    if (!$recordAccess) {
                        $this->log($table, $id, SystemLogDatabaseAction::UPDATE, 0, SystemLogErrorClassification::USER_ERROR, 'recordEditAccessInternals() check failed [{reason}]', -1, ['reason' => $this->BE_USER->errorMsg]);
                    } else {
                        // Here we fetch the PID of the record that we point to...
                        $tempdata = $this->recordInfo($table, $id);
                        $theRealPid = $tempdata['pid'] ?? null;
                        // Use the new id of the versionized record we're trying to write to:
                        // (This record is a child record of a parent and has already been versionized.)
                        if (!empty($this->autoVersionIdMap[$table][$id])) {
                            // For the reason that creating a new version of this record, automatically
                            // created related child records (e.g. "IRRE"), update the accordant field:
                            $this->getVersionizedIncomingFieldArray($table, $id, $incomingFieldArray, $registerDBList);
                            // Use the new id of the copied/versionized record:
                            $id = $this->autoVersionIdMap[$table][$id];
                            $recordAccess = true;
                        } elseif (!$this->bypassWorkspaceRestrictions && $tempdata && ($errorCode = $this->workspaceCannotEditRecord($table, $tempdata))) {
                            $recordAccess = false;
                            // Versioning is required and it must be offline version!
                            // Check if there already is a workspace version
                            $workspaceVersion = BackendUtility::getWorkspaceVersionOfRecord($this->BE_USER->workspace, $table, $id, 'uid,t3ver_oid');
                            if ($workspaceVersion) {
                                $id = $workspaceVersion['uid'];
                                $recordAccess = true;
                            } elseif ($this->workspaceAllowAutoCreation($table, $id, $theRealPid)) {
                                // new version of a record created in a workspace - so always refresh pagetree to indicate there is a change in the workspace
                                $this->pagetreeNeedsRefresh = true;

                                $tce = GeneralUtility::makeInstance(self::class, $this->referenceIndexUpdater);
                                $tce->enableLogging = $this->enableLogging;
                                // Setting up command for creating a new version of the record:
                                $cmd = [];
                                $cmd[$table][$id]['version'] = [
                                    'action' => 'new',
                                    // Default is to create a version of the individual records
                                    'label' => 'Auto-created for WS #' . $this->BE_USER->workspace,
                                ];
                                $tce->start([], $cmd, $this->BE_USER);
                                $tce->process_cmdmap();
                                $this->errorLog = array_merge($this->errorLog, $tce->errorLog);
                                // If copying was successful, share the new uids (also of related children):
                                if (!empty($tce->copyMappingArray[$table][$id])) {
                                    foreach ($tce->copyMappingArray as $origTable => $origIdArray) {
                                        foreach ($origIdArray as $origId => $newId) {
                                            $this->autoVersionIdMap[$origTable][$origId] = $newId;
                                        }
                                    }
                                    // Update registerDBList, that holds the copied relations to child records:
                                    $registerDBList = array_merge($registerDBList, $tce->registerDBList);
                                    // For the reason that creating a new version of this record, automatically
                                    // created related child records (e.g. "IRRE"), update the accordant field:
                                    $this->getVersionizedIncomingFieldArray($table, $id, $incomingFieldArray, $registerDBList);
                                    // Use the new id of the copied/versionized record:
                                    $id = $this->autoVersionIdMap[$table][$id];
                                    $recordAccess = true;
                                } else {
                                    $this->log($table, $id, SystemLogDatabaseAction::VERSIONIZE, 0, SystemLogErrorClassification::USER_ERROR, 'Could not be edited in offline workspace in the branch where found ({reason}). Auto-creation of version failed', -1, ['reason' => $errorCode]);
                                }
                            } else {
                                $this->log($table, $id, SystemLogDatabaseAction::VERSIONIZE, 0, SystemLogErrorClassification::USER_ERROR, 'Could not be edited in offline workspace in the branch where found ({reason}). Auto-creation of version not allowed in workspace', -1, ['reason' => $errorCode]);
                            }
                        }
                    }
                    // The default is 'update'
                    $status = 'update';
                }
                // If access was granted above, proceed to create or update record:
                if (!$recordAccess) {
                    continue;
                }

                // Here the "pid" is set IF NOT the old pid was a string pointing to a place in the subst-id array.
                [$tscPID] = BackendUtility::getTSCpid($table, $id, $old_pid_value ?: ($fieldArray['pid'] ?? 0));
                if ($status === 'new') {
                    // Apply TCAdefaults from pageTS
                    $fieldArray = $this->applyDefaultsForFieldArray($table, (int)$tscPID, $fieldArray);
                    // Apply page permissions as well
                    if ($table === 'pages') {
                        $fieldArray = $this->pagePermissionAssembler->applyDefaults(
                            $fieldArray,
                            (int)$tscPID,
                            (int)$this->userid,
                            (int)$this->BE_USER->firstMainGroup
                        );
                    }
                    // Ensure that the default values, that are stored in the $fieldArray (built from internal default values)
                    // Are also placed inside the incomingFieldArray, so this is checked in "fillInFieldArray" and
                    // all default values are also checked for validity
                    // This allows to set TCAdefaults (for example) without having to use FormEngine to have the fields available first.
                    $incomingFieldArray = array_replace_recursive($fieldArray, $incomingFieldArray);
                }
                // Processing of all fields in incomingFieldArray and setting them in $fieldArray
                $fieldArray = $this->fillInFieldArray($table, $id, $fieldArray, $incomingFieldArray, $theRealPid, $status, $tscPID);
                // NOTICE! All manipulation beyond this point bypasses both "excludeFields" AND possible "MM" relations to field!
                // Forcing some values unto field array:
                // NOTICE: This overriding is potentially dangerous; permissions per field is not checked!!!
                $fieldArray = $this->overrideFieldArray($table, $fieldArray);
                // Setting system fields
                if ($status === 'new') {
                    if ($GLOBALS['TCA'][$table]['ctrl']['crdate'] ?? false) {
                        $fieldArray[$GLOBALS['TCA'][$table]['ctrl']['crdate']] = $GLOBALS['EXEC_TIME'];
                    }
                } elseif ($this->checkSimilar) {
                    // Removing fields which are equal to the current value:
                    $fieldArray = $this->compareFieldArrayWithCurrentAndUnset($table, $id, $fieldArray);
                }
                if (($GLOBALS['TCA'][$table]['ctrl']['tstamp'] ?? false) && !empty($fieldArray)) {
                    $fieldArray[$GLOBALS['TCA'][$table]['ctrl']['tstamp']] = $GLOBALS['EXEC_TIME'];
                }
                // Set stage to "Editing" to make sure we restart the workflow
                if (BackendUtility::isTableWorkspaceEnabled($table)) {
                    $fieldArray['t3ver_stage'] = 0;
                }
                // Hook: processDatamap_postProcessFieldArray
                foreach ($hookObjectsArr as $hookObj) {
                    if (method_exists($hookObj, 'processDatamap_postProcessFieldArray')) {
                        $hookObj->processDatamap_postProcessFieldArray($status, $table, $id, $fieldArray, $this);
                    }
                }
                // Performing insert/update. If fieldArray has been unset by some userfunction (see hook above), don't do anything
                // Kasper: Unsetting the fieldArray is dangerous; MM relations might be saved already
                if (is_array($fieldArray)) {
                    if ($status === 'new') {
                        if ($table === 'pages') {
                            // for new pages always a refresh is needed
                            $this->pagetreeNeedsRefresh = true;
                        }

                        // This creates a version of the record, instead of adding it to the live workspace
                        if ($createNewVersion) {
                            // new record created in a workspace - so always refresh pagetree to indicate there is a change in the workspace
                            $this->pagetreeNeedsRefresh = true;
                            $fieldArray['pid'] = $theRealPid;
                            $fieldArray['t3ver_oid'] = 0;
                            // Setting state for version (so it can know it is currently a new version...)
                            $fieldArray['t3ver_state'] = (string)new VersionState(VersionState::NEW_PLACEHOLDER);
                            $fieldArray['t3ver_wsid'] = $this->BE_USER->workspace;

                            ###
                            ### PxDbSequencer: Re-call PxDbsequencer DataHandlerHook to generate a sequenced uid for the placeholder record, if needed
                            ###
                            /** @var DataHandlerHook $pxDbSequencerHookObj */
                            $pxDbSequencerHookObj = GeneralUtility::makeInstance($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['tx_pxdbsequencer']);
                            if (method_exists($pxDbSequencerHookObj, 'processDatamap_postProcessFieldArray')) {
                                $pxDbSequencerHookObj->processDatamap_postProcessFieldArray($status, $table, $id, $fieldArray, $this);
                            }

                            $this->insertDB($table, $id, $fieldArray, true, (int)($incomingFieldArray['uid'] ?? 0));
                            // Hold auto-versionized ids of placeholders
                            $this->autoVersionIdMap[$table][$this->substNEWwithIDs[$id]] = $this->substNEWwithIDs[$id];
                        } else {
                            $this->insertDB($table, $id, $fieldArray, false, (int)($incomingFieldArray['uid'] ?? 0));
                        }
                    } else {
                        if ($table === 'pages') {
                            // only a certain number of fields needs to be checked for updates
                            // if $this->checkSimilar is TRUE, fields with unchanged values are already removed here
                            $fieldsToCheck = array_intersect($this->pagetreeRefreshFieldsFromPages, array_keys($fieldArray));
                            if (!empty($fieldsToCheck)) {
                                $this->pagetreeNeedsRefresh = true;
                            }
                        }
                        $this->updateDB($table, $id, $fieldArray);
                    }
                }
                // Hook: processDatamap_afterDatabaseOperations
                // Note: When using the hook after INSERT operations, you will only get the temporary NEW... id passed to your hook as $id,
                // but you can easily translate it to the real uid of the inserted record using the $this->substNEWwithIDs array.
                $this->hook_processDatamap_afterDatabaseOperations($hookObjectsArr, $status, $table, $id, $fieldArray);
            }
        }
        // Process the stack of relations to remap/correct
        $this->processRemapStack();
        $this->dbAnalysisStoreExec();
        // Hook: processDatamap_afterAllOperations
        // Note: When this hook gets called, all operations on the submitted data have been finished.
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'processDatamap_afterAllOperations')) {
                $hookObj->processDatamap_afterAllOperations($this);
            }
        }

        if ($this->isOuterMostInstance()) {
            $this->referenceIndexUpdater->update();
            $this->processClearCacheQueue();
            $this->resetElementsToBeDeleted();
        }
    }

    /**
     * Insert into database
     * Does not check permissions but expects them to be verified on beforehand
     *
     * @param string $table Record table name
     * @param string $id "NEW...." uid string
     * @param array $fieldArray Array of field=>value pairs to insert. FIELDS MUST MATCH the database FIELDS. No check is done. "pid" must point to the destination of the record!
     * @param bool $newVersion Set to TRUE if new version is created.
     * @param int $suggestedUid Suggested UID value for the inserted record. See the array $this->suggestedInsertUids; Admin-only feature
     * @param bool $dontSetNewIdIndex If TRUE, the ->substNEWwithIDs array is not updated. Only useful in very rare circumstances!
     * @return int|null Returns ID on success.
     * @internal should only be used from within DataHandler
     */
    public function insertDB($table, $id, $fieldArray, $newVersion = false, $suggestedUid = 0, $dontSetNewIdIndex = false)
    {
        if (is_array($fieldArray) && is_array($GLOBALS['TCA'][$table]) && isset($fieldArray['pid'])) {
            // Do NOT insert the UID field, ever!
            unset($fieldArray['uid']);
            if (!empty($fieldArray)) {
                // Check for "suggestedUid".
                // This feature is used by the import functionality to force a new record to have a certain UID value.
                // This is only recommended for use when the destination server is a passive mirror of another server.
                // As a security measure this feature is available only for Admin Users (for now)
                $suggestedUid = (int)$suggestedUid;
                ###
                ### PxDbSequencer: use uid from hook
                ###
                if (!$suggestedUid && $this->currentSuggestUid) {
                    $suggestedUid = (int)$this->currentSuggestUid;
                }
                ###
                ### PxDbSequencer: enable for non admins, to use sequencing for all BE Users: we remove $this->BE_USER->isAdmin()
                ###
                if ($suggestedUid && $this->suggestedInsertUids[$table . ':' . $suggestedUid]) {
                    // When the value of ->suggestedInsertUids[...] is "DELETE" it will try to remove the previous record
                    if ($this->suggestedInsertUids[$table . ':' . $suggestedUid] === 'DELETE') {
                        $this->hardDeleteSingleRecord($table, (int)$suggestedUid);
                    }
                    $fieldArray['uid'] = $suggestedUid;
                }
                $fieldArray = $this->insertUpdateDB_preprocessBasedOnFieldType($table, $fieldArray);
                $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
                $insertErrorMessage = '';
                try {
                    // Execute the INSERT query:
                    $connection->insert($table, $fieldArray);
                } catch (DBALException $e) {
                    $insertErrorMessage = $e->getPrevious()->getMessage();
                }
                // If succees, do...:
                if ($insertErrorMessage === '') {
                    // Set mapping for NEW... -> real uid:
                    // the NEW_id now holds the 'NEW....' -id
                    $NEW_id = $id;
                    $id = $this->postProcessDatabaseInsert($connection, $table, $suggestedUid);

                    if (!$dontSetNewIdIndex) {
                        $this->substNEWwithIDs[$NEW_id] = $id;
                        $this->substNEWwithIDs_table[$NEW_id] = $table;
                    }
                    $newRow = [];
                    if ($this->enableLogging) {
                        // Checking the record is properly saved if configured
                        if ($this->checkStoredRecords) {
                            $newRow = $this->checkStoredRecord($table, $id, $fieldArray, SystemLogDatabaseAction::INSERT) ?? [];
                        } else {
                            $newRow = $fieldArray;
                            $newRow['uid'] = $id;
                        }
                    }
                    // Update reference index:
                    $this->updateRefIndex($table, $id);

                    // Store in history
                    $this->getRecordHistoryStore()->addRecord($table, $id, $newRow, $this->correlationId);

                    if ($newVersion) {
                        if ($this->enableLogging) {
                            $propArr = $this->getRecordPropertiesFromRow($table, $newRow);
                            $this->log($table, $id, SystemLogDatabaseAction::INSERT, 0, SystemLogErrorClassification::MESSAGE, 'New version created "{table}:{uid}". UID of new version is "{offlineUid}"', 10, ['table' => $table, 'uid' => $fieldArray['t3ver_oid'], 'offlineUid' => $id], $propArr['event_pid'], $NEW_id);
                        }
                    } else {
                        if ($this->enableLogging) {
                            $propArr = $this->getRecordPropertiesFromRow($table, $newRow);
                            $page_propArr = $this->getRecordProperties('pages', $propArr['pid']);
                            $this->log($table, $id, SystemLogDatabaseAction::INSERT, 0, SystemLogErrorClassification::MESSAGE, 'Record "{title}" ({table}:{uid}) was inserted on page "{pageTitle}" ({pid})', 10, ['title' => $propArr['header'], 'table' => $table, 'uid' => $id, 'pageTitle' => $page_propArr['header'], 'pid' => $newRow['pid']], $newRow['pid'], $NEW_id);
                        }
                        // Clear cache for relevant pages:
                        $this->registerRecordIdForPageCacheClearing($table, $id);
                    }
                    return $id;
                }
                $this->log($table, 0, SystemLogDatabaseAction::INSERT, 0, SystemLogErrorClassification::SYSTEM_ERROR, 'SQL error: "{reason}" ({table}:{uid})', 12, ['reason' => $insertErrorMessage, 'table' => $table, 'uid' => $id]);
            }
        }
        return null;
    }
}
