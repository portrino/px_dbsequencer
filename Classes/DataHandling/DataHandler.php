<?php

namespace Portrino\PxDbsequencer\DataHandling;

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

use Doctrine\DBAL\DBALException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\Localization\DataMapProcessor;
use TYPO3\CMS\Core\DataHandling\SlugEnricher;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Class DataHandler
 *
 * @package Portrino\PxDbsequencer\DataHandling
 */
class DataHandler extends \TYPO3\CMS\Core\DataHandling\DataHandler
{

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
        if ($this->BE_USER->workspace !== 0 && $this->BE_USER->workspaceRec['freeze']) {
            $this->newlog('All editing in this workspace has been frozen!', 1);
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
        $this->datamap = DataMapProcessor::instance($this->datamap, $this->BE_USER)->process();
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
                $this->log($table, 0, 2, 0, 1, 'Attempt to modify table \'%s\' without permission', 1, [$table]);
            }
            if (!isset($GLOBALS['TCA'][$table]) || $this->tableReadOnly($table) || !is_array($this->datamap[$table]) || !$modifyAccessList) {
                continue;
            }

            if ($this->reverseOrder) {
                $this->datamap[$table] = array_reverse($this->datamap[$table], 1);
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
                $recordAccess = false;
                $old_pid_value = '';
                $this->autoVersioningUpdate = false;
                // Is it a new record? (Then Id is a string)
                if (!MathUtility::canBeInterpretedAsInteger($id)) {
                    // Get a fieldArray with default values
                    $fieldArray = $this->newFieldArray($table);
                    // A pid must be set for new records.
                    if (isset($incomingFieldArray['pid'])) {
                        $pid_value = $incomingFieldArray['pid'];
                        // Checking and finding numerical pid, it may be a string-reference to another value
                        $canProceed = true;
                        // If a NEW... id
                        if (strpos($pid_value, 'NEW') !== false) {
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
                    // Now, check if we may insert records on this pid.
                    if ($theRealPid >= 0) {
                        // Checks if records can be inserted on this $pid.
                        // If this is a page translation, the check needs to be done for the l10n_parent record
                        if ($table === 'pages' && $incomingFieldArray[$GLOBALS['TCA'][$table]['ctrl']['languageField']] > 0 && $incomingFieldArray[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']] > 0) {
                            $recordAccess = $this->checkRecordInsertAccess($table, $incomingFieldArray[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']]);
                        } else {
                            $recordAccess = $this->checkRecordInsertAccess($table, $theRealPid);
                        }
                        if ($recordAccess) {
                            $this->addDefaultPermittedLanguageIfNotSet($table, $incomingFieldArray);
                            $recordAccess = $this->BE_USER->recordEditAccessInternals($table, $incomingFieldArray, true);
                            if (!$recordAccess) {
                                $this->newlog('recordEditAccessInternals() check failed. [' . $this->BE_USER->errorMsg . ']', 1);
                            } elseif (!$this->bypassWorkspaceRestrictions) {
                                // Workspace related processing:
                                // If LIVE records cannot be created in the current PID due to workspace restrictions, prepare creation of placeholder-record
                                if ($res = $this->BE_USER->workspaceAllowLiveRecordsInPID($theRealPid, $table)) {
                                    if ($res < 0) {
                                        $recordAccess = false;
                                        $this->newlog('Stage for versioning root point and users access level did not allow for editing', 1);
                                    }
                                } else {
                                    // So, if no live records were allowed, we have to create a new version of this record:
                                    if ($GLOBALS['TCA'][$table]['ctrl']['versioningWS']) {
                                        $createNewVersion = true;
                                    } else {
                                        $recordAccess = false;
                                        $this->newlog('Record could not be created in this workspace in this branch', 1);
                                    }
                                }
                            }
                        }
                    } else {
                        $this->logger->debug('Internal ERROR: pid should not be less than zero!');
                    }
                    // Yes new record, change $record_status to 'insert'
                    $status = 'new';
                } else {
                    // Nope... $id is a number
                    $fieldArray = [];
                    $recordAccess = $this->checkRecordUpdateAccess($table, $id, $incomingFieldArray, $hookObjectsArr);
                    if (!$recordAccess) {
                        if ($this->enableLogging) {
                            $propArr = $this->getRecordProperties($table, $id);
                            $this->log($table, $id, 2, 0, 1, 'Attempt to modify record \'%s\' (%s) without permission. Or non-existing page.', 2, [$propArr['header'], $table . ':' . $id], $propArr['event_pid']);
                        }
                        continue;
                    }
                    // Next check of the record permissions (internals)
                    $recordAccess = $this->BE_USER->recordEditAccessInternals($table, $id);
                    if (!$recordAccess) {
                        $this->newlog('recordEditAccessInternals() check failed. [' . $this->BE_USER->errorMsg . ']', 1);
                    } else {
                        // Here we fetch the PID of the record that we point to...
                        $tempdata = $this->recordInfo($table, $id, 'pid' . (!empty($GLOBALS['TCA'][$table]['ctrl']['versioningWS']) ? ',t3ver_wsid,t3ver_stage' : ''));
                        $theRealPid = $tempdata['pid'];
                        // Use the new id of the versionized record we're trying to write to:
                        // (This record is a child record of a parent and has already been versionized.)
                        if (!empty($this->autoVersionIdMap[$table][$id])) {
                            // For the reason that creating a new version of this record, automatically
                            // created related child records (e.g. "IRRE"), update the accordant field:
                            $this->getVersionizedIncomingFieldArray($table, $id, $incomingFieldArray, $registerDBList);
                            // Use the new id of the copied/versionized record:
                            $id = $this->autoVersionIdMap[$table][$id];
                            $recordAccess = true;
                            $this->autoVersioningUpdate = true;
                        } elseif (!$this->bypassWorkspaceRestrictions && ($errorCode = $this->BE_USER->workspaceCannotEditRecord($table, $tempdata))) {
                            $recordAccess = false;
                            // Versioning is required and it must be offline version!
                            // Check if there already is a workspace version
                            $WSversion = BackendUtility::getWorkspaceVersionOfRecord($this->BE_USER->workspace, $table, $id, 'uid,t3ver_oid');
                            if ($WSversion) {
                                $id = $WSversion['uid'];
                                $recordAccess = true;
                            } elseif ($this->BE_USER->workspaceAllowAutoCreation($table, $id, $theRealPid)) {
                                // new version of a record created in a workspace - so always refresh pagetree to indicate there is a change in the workspace
                                $this->pagetreeNeedsRefresh = true;

                                /** @var DataHandler $tce */
                                $tce = GeneralUtility::makeInstance(__CLASS__);
                                $tce->enableLogging = $this->enableLogging;
                                // Setting up command for creating a new version of the record:
                                $cmd = [];
                                $cmd[$table][$id]['version'] = [
                                    'action' => 'new',
                                    // Default is to create a version of the individual records... element versioning that is.
                                    'label' => 'Auto-created for WS #' . $this->BE_USER->workspace
                                ];
                                $tce->start([], $cmd, $this->BE_USER);
                                $tce->process_cmdmap();
                                $this->errorLog = array_merge($this->errorLog, $tce->errorLog);
                                // If copying was successful, share the new uids (also of related children):
                                if (!empty($tce->copyMappingArray[$table][$id])) {
                                    foreach ($tce->copyMappingArray as $origTable => $origIdArray) {
                                        foreach ($origIdArray as $origId => $newId) {
                                            $this->uploadedFileArray[$origTable][$newId] = $this->uploadedFileArray[$origTable][$origId];
                                            $this->autoVersionIdMap[$origTable][$origId] = $newId;
                                        }
                                    }
                                    ArrayUtility::mergeRecursiveWithOverrule($this->RTEmagic_copyIndex, $tce->RTEmagic_copyIndex);
                                    // See where RTEmagic_copyIndex is used inside fillInFieldArray() for more information...
                                    // Update registerDBList, that holds the copied relations to child records:
                                    $registerDBList = array_merge($registerDBList, $tce->registerDBList);
                                    // For the reason that creating a new version of this record, automatically
                                    // created related child records (e.g. "IRRE"), update the accordant field:
                                    $this->getVersionizedIncomingFieldArray($table, $id, $incomingFieldArray, $registerDBList);
                                    // Use the new id of the copied/versionized record:
                                    $id = $this->autoVersionIdMap[$table][$id];
                                    $recordAccess = true;
                                    $this->autoVersioningUpdate = true;
                                } else {
                                    $this->newlog('Could not be edited in offline workspace in the branch where found (failure state: \'' . $errorCode . '\'). Auto-creation of version failed!', 1);
                                }
                            } else {
                                $this->newlog('Could not be edited in offline workspace in the branch where found (failure state: \'' . $errorCode . '\'). Auto-creation of version not allowed in workspace!', 1);
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
                list($tscPID) = BackendUtility::getTSCpid($table, $id, $old_pid_value ? $old_pid_value : $fieldArray['pid']);
                if ($status === 'new' && $table === 'pages') {
                    $TSConfig = BackendUtility::getPagesTSconfig($tscPID)['TCEMAIN.'] ?? [];
                    if (isset($TSConfig['permissions.']) && is_array($TSConfig['permissions.'])) {
                        $fieldArray = $this->setTSconfigPermissions($fieldArray, $TSConfig['permissions.']);
                    }
                }
                // Processing of all fields in incomingFieldArray and setting them in $fieldArray
                $fieldArray = $this->fillInFieldArray($table, $id, $fieldArray, $incomingFieldArray, $theRealPid, $status, $tscPID);
                $newVersion_placeholderFieldArray = [];
                if ($createNewVersion) {
                    // create a placeholder array with already processed field content
                    $newVersion_placeholderFieldArray = $fieldArray;
                }
                // NOTICE! All manipulation beyond this point bypasses both "excludeFields" AND possible "MM" relations / file uploads to field!
                // Forcing some values unto field array:
                // NOTICE: This overriding is potentially dangerous; permissions per field is not checked!!!
                $fieldArray = $this->overrideFieldArray($table, $fieldArray);
                if ($createNewVersion) {
                    $newVersion_placeholderFieldArray = $this->overrideFieldArray($table, $newVersion_placeholderFieldArray);
                }
                // Setting system fields
                if ($status === 'new') {
                    if ($GLOBALS['TCA'][$table]['ctrl']['crdate']) {
                        $fieldArray[$GLOBALS['TCA'][$table]['ctrl']['crdate']] = $GLOBALS['EXEC_TIME'];
                        if ($createNewVersion) {
                            $newVersion_placeholderFieldArray[$GLOBALS['TCA'][$table]['ctrl']['crdate']] = $GLOBALS['EXEC_TIME'];
                        }
                    }
                    if ($GLOBALS['TCA'][$table]['ctrl']['cruser_id']) {
                        $fieldArray[$GLOBALS['TCA'][$table]['ctrl']['cruser_id']] = $this->userid;
                        if ($createNewVersion) {
                            $newVersion_placeholderFieldArray[$GLOBALS['TCA'][$table]['ctrl']['cruser_id']] = $this->userid;
                        }
                    }
                } elseif ($this->checkSimilar) {
                    // Removing fields which are equal to the current value:
                    $fieldArray = $this->compareFieldArrayWithCurrentAndUnset($table, $id, $fieldArray);
                }
                if ($GLOBALS['TCA'][$table]['ctrl']['tstamp'] && !empty($fieldArray)) {
                    $fieldArray[$GLOBALS['TCA'][$table]['ctrl']['tstamp']] = $GLOBALS['EXEC_TIME'];
                    if ($createNewVersion) {
                        $newVersion_placeholderFieldArray[$GLOBALS['TCA'][$table]['ctrl']['tstamp']] = $GLOBALS['EXEC_TIME'];
                    }
                }
                // Set stage to "Editing" to make sure we restart the workflow
                if ($GLOBALS['TCA'][$table]['ctrl']['versioningWS']) {
                    $fieldArray['t3ver_stage'] = 0;
                }
                // Hook: processDatamap_postProcessFieldArray
                foreach ($hookObjectsArr as $hookObj) {
                    if (method_exists($hookObj, 'processDatamap_postProcessFieldArray')) {
                        $hookObj->processDatamap_postProcessFieldArray($status, $table, $id, $fieldArray, $this);
                    }
                }
                // Performing insert/update. If fieldArray has been unset by some userfunction (see hook above), don't do anything
                // Kasper: Unsetting the fieldArray is dangerous; MM relations might be saved already and files could have been uploaded that are now "lost"
                if (is_array($fieldArray)) {
                    if ($status === 'new') {
                        if ($table === 'pages') {
                            // for new pages always a refresh is needed
                            $this->pagetreeNeedsRefresh = true;
                        }

                        // This creates a new version of the record with online placeholder and offline version
                        if ($createNewVersion) {
                            // new record created in a workspace - so always refresh pagetree to indicate there is a change in the workspace
                            $this->pagetreeNeedsRefresh = true;

                            $newVersion_placeholderFieldArray['t3ver_label'] = 'INITIAL PLACEHOLDER';
                            // Setting placeholder state value for temporary record
                            $newVersion_placeholderFieldArray['t3ver_state'] = (string)new VersionState(VersionState::NEW_PLACEHOLDER);
                            // Setting workspace - only so display of place holders can filter out those from other workspaces.
                            $newVersion_placeholderFieldArray['t3ver_wsid'] = $this->BE_USER->workspace;
                            $newVersion_placeholderFieldArray[$GLOBALS['TCA'][$table]['ctrl']['label']] = $this->getPlaceholderTitleForTableLabel($table);
                            // Saving placeholder as 'original'
                            $this->insertDB($table, $id, $newVersion_placeholderFieldArray, false, $incomingFieldArray['uid'] ?? 0);
                            // For the actual new offline version, set versioning values to point to placeholder:
                            $fieldArray['pid'] = -1;
                            $fieldArray['t3ver_oid'] = $this->substNEWwithIDs[$id];
                            $fieldArray['t3ver_id'] = 1;
                            // Setting placeholder state value for version (so it can know it is currently a new version...)
                            $fieldArray['t3ver_state'] = (string)new VersionState(VersionState::NEW_PLACEHOLDER_VERSION);
                            $fieldArray['t3ver_label'] = 'First draft version';
                            $fieldArray['t3ver_wsid'] = $this->BE_USER->workspace;

                            // PxDbSequencer: Reset $incomingFieldArray['uid']
                            $incomingFieldArray['uid'] = 0;
                            // PxDbSequencer: Re-call PxDbsequencer DataHandlerHook to generate a sequenced uid for the placeholder record, if needed
                            $pxDbSequencerHookObj = GeneralUtility::makeInstance($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['tx_pxdbsequencer']);
                            if (method_exists($pxDbSequencerHookObj, 'processDatamap_preProcessFieldArray')) {
                                $pxDbSequencerHookObj->processDatamap_preProcessFieldArray($incomingFieldArray, $table, $id, $this);
                            }

                            // When inserted, $this->substNEWwithIDs[$id] will be changed to the uid of THIS version and so the interface will pick it up just nice!
                            // PxDbSequencer: Call it with the additional $suggestedUid parameter
                            $phShadowId = $this->insertDB($table, $id, $fieldArray, true, (int)$incomingFieldArray['uid'], true);

                            if ($phShadowId) {
                                // Processes fields of the placeholder record:
                                $this->triggerRemapAction($table, $id, [$this, 'placeholderShadowing'], [$table, $phShadowId]);
                                // Hold auto-versionized ids of placeholders:
                                $this->autoVersionIdMap[$table][$this->substNEWwithIDs[$id]] = $phShadowId;
                            }
                        } else {
                            $this->insertDB($table, $id, $fieldArray, false, $incomingFieldArray['uid']);
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
                        $this->placeholderShadowing($table, $id);
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
        $this->removeRegisteredFiles();
        // Hook: processDatamap_afterAllOperations
        // Note: When this hook gets called, all operations on the submitted data have been finished.
        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'processDatamap_afterAllOperations')) {
                $hookObj->processDatamap_afterAllOperations($this);
            }
        }
        if ($this->isOuterMostInstance()) {
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
                // PxDbSequencer: enable for non admins, to use sequencing for all BE Users (hack: we add !$this->BE_USER->isAdmin())
                if (($this->BE_USER->isAdmin() || !$this->BE_USER->isAdmin()) && $suggestedUid && $this->suggestedInsertUids[$table . ':' . $suggestedUid]) {
                    // When the value of ->suggestedInsertUids[...] is "DELETE" it will try to remove the previous record
                    if ($this->suggestedInsertUids[$table . ':' . $suggestedUid] === 'DELETE') {
                        // DELETE:
                        GeneralUtility::makeInstance(ConnectionPool::class)
                            ->getConnectionForTable($table)
                            ->delete($table, ['uid' => (int)$suggestedUid]);
                    }
                    $fieldArray['uid'] = $suggestedUid;
                }
                $fieldArray = $this->insertUpdateDB_preprocessBasedOnFieldType($table, $fieldArray);
                $typeArray = [];
                if (!empty($GLOBALS['TCA'][$table]['ctrl']['transOrigDiffSourceField'])
                    && array_key_exists($GLOBALS['TCA'][$table]['ctrl']['transOrigDiffSourceField'], $fieldArray)
                ) {
                    $typeArray[$GLOBALS['TCA'][$table]['ctrl']['transOrigDiffSourceField']] = Connection::PARAM_LOB;
                }
                $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
                $insertErrorMessage = '';
                try {
                    // Execute the INSERT query:
                    $connection->insert(
                        $table,
                        $fieldArray,
                        $typeArray
                    );
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
                            $newRow = $this->checkStoredRecord($table, $id, $fieldArray, 1);
                        } else {
                            $newRow = $fieldArray;
                            $newRow['uid'] = $id;
                        }
                    }
                    // Update reference index:
                    $this->updateRefIndex($table, $id);

                    // Store in history
                    $this->getRecordHistoryStore()->addRecord($table, $id, $newRow);

                    if ($newVersion) {
                        if ($this->enableLogging) {
                            $propArr = $this->getRecordPropertiesFromRow($table, $newRow);
                            $this->log($table, $id, 1, 0, 0, 'New version created of table \'%s\', uid \'%s\'. UID of new version is \'%s\'', 10, [$table, $fieldArray['t3ver_oid'], $id], $propArr['event_pid'], $NEW_id);
                        }
                    } else {
                        if ($this->enableLogging) {
                            $propArr = $this->getRecordPropertiesFromRow($table, $newRow);
                            $page_propArr = $this->getRecordProperties('pages', $propArr['pid']);
                            $this->log($table, $id, 1, 0, 0, 'Record \'%s\' (%s) was inserted on page \'%s\' (%s)', 10, [$propArr['header'], $table . ':' . $id, $page_propArr['header'], $newRow['pid']], $newRow['pid'], $NEW_id);
                        }
                        // Clear cache for relevant pages:
                        $this->registerRecordIdForPageCacheClearing($table, $id);
                    }
                    return $id;
                }
                if ($this->enableLogging) {
                    $this->log($table, $id, 1, 0, 2, 'SQL error: \'%s\' (%s)', 12, [$insertErrorMessage, $table . ':' . $id]);
                }
            }
        }
        return null;
    }
}