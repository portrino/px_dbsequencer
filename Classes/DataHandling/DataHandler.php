<?php
namespace Portrino\PxDbsequencer\DataHandling;

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
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Versioning\VersionState;


class DataHandler extends \TYPO3\CMS\Core\DataHandling\DataHandler {

    /**
     * Processing the data-array
     * Call this function to process the data-array set by start()
     *
     * @return void
     */
    public function process_datamap() {
        $this->controlActiveElements();

        // Keep versionized(!) relations here locally:
        $registerDBList = array();
        $this->registerElementsToBeDeleted();
        $this->datamap = $this->unsetElementsToBeDeleted($this->datamap);
        // Editing frozen:
        if ($this->BE_USER->workspace !== 0 && $this->BE_USER->workspaceRec['freeze']) {
            $this->newlog('All editing in this workspace has been frozen!', 1);
            return FALSE;
        }
        // First prepare user defined objects (if any) for hooks which extend this function:
        $hookObjectsArr = array();
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'] as $classRef) {
                $hookObject = GeneralUtility::getUserObj($classRef);
                if (method_exists($hookObject, 'processDatamap_beforeStart')) {
                    $hookObject->processDatamap_beforeStart($this);
                }
                $hookObjectsArr[] = $hookObject;
            }
        }
        // Organize tables so that the pages-table is always processed first. This is required if you want to make sure that content pointing to a new page will be created.
        $orderOfTables = array();
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
                $id = 0;
                $this->log($table, $id, 2, 0, 1, 'Attempt to modify table \'%s\' without permission', 1, array($table));
            }
            if (isset($GLOBALS['TCA'][$table]) && !$this->tableReadOnly($table) && is_array($this->datamap[$table]) && $modifyAccessList) {
                if ($this->reverseOrder) {
                    $this->datamap[$table] = array_reverse($this->datamap[$table], 1);
                }
                // For each record from the table, do:
                // $id is the record uid, may be a string if new records...
                // $incomingFieldArray is the array of fields
                foreach ($this->datamap[$table] as $id => $incomingFieldArray) {
                    if (is_array($incomingFieldArray)) {
                        // Handle native date/time fields
                        $dateTimeFormats = $GLOBALS['TYPO3_DB']->getDateTimeFormats($table);
                        foreach ($GLOBALS['TCA'][$table]['columns'] as $column => $config) {
                            if (isset($incomingFieldArray[$column])) {
                                if (isset($config['config']['dbType']) && GeneralUtility::inList('date,datetime', $config['config']['dbType'])) {
                                    $emptyValue = $dateTimeFormats[$config['config']['dbType']]['empty'];
                                    $format = $dateTimeFormats[$config['config']['dbType']]['format'];
                                    $incomingFieldArray[$column] = $incomingFieldArray[$column] ? gmdate($format, $incomingFieldArray[$column]) : $emptyValue;
                                }
                            }
                        }
                        // Hook: processDatamap_preProcessFieldArray
                        foreach ($hookObjectsArr as $hookObj) {
                            if (method_exists($hookObj, 'processDatamap_preProcessFieldArray')) {
                                $hookObj->processDatamap_preProcessFieldArray($incomingFieldArray, $table, $id, $this);
                            }
                        }
                        // ******************************
                        // Checking access to the record
                        // ******************************
                        $createNewVersion = FALSE;
                        $recordAccess = FALSE;
                        $old_pid_value = '';
                        $this->autoVersioningUpdate = FALSE;
                        // Is it a new record? (Then Id is a string)
                        if (!\TYPO3\CMS\Core\Utility\MathUtility::canBeInterpretedAsInteger($id)) {
                            // Get a fieldArray with default values
                            $fieldArray = $this->newFieldArray($table);
                            // A pid must be set for new records.
                            if (isset($incomingFieldArray['pid'])) {
                                // $value = the pid
                                $pid_value = $incomingFieldArray['pid'];
                                // Checking and finding numerical pid, it may be a string-reference to another value
                                $OK = 1;
                                // If a NEW... id
                                if (strstr($pid_value, 'NEW')) {
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
                                        $OK = 0;
                                    }
                                }
                                $pid_value = (int)$pid_value;
                                // The $pid_value is now the numerical pid at this point
                                if ($OK) {
                                    $sortRow = $GLOBALS['TCA'][$table]['ctrl']['sortby'];
                                    // Points to a page on which to insert the element, possibly in the top of the page
                                    if ($pid_value >= 0) {
                                        // If this table is sorted we better find the top sorting number
                                        if ($sortRow) {
                                            $fieldArray[$sortRow] = $this->getSortNumber($table, 0, $pid_value);
                                        }
                                        // The numerical pid is inserted in the data array
                                        $fieldArray['pid'] = $pid_value;
                                    } else {
                                        // points to another record before ifself
                                        // If this table is sorted we better find the top sorting number
                                        if ($sortRow) {
                                            // Because $pid_value is < 0, getSortNumber returns an array
                                            $tempArray = $this->getSortNumber($table, 0, $pid_value);
                                            $fieldArray['pid'] = $tempArray['pid'];
                                            $fieldArray[$sortRow] = $tempArray['sortNumber'];
                                        } else {
                                            // Here we fetch the PID of the record that we point to...
                                            $tempdata = $this->recordInfo($table, abs($pid_value), 'pid');
                                            $fieldArray['pid'] = $tempdata['pid'];
                                        }
                                    }
                                }
                            }
                            $theRealPid = $fieldArray['pid'];
                            // Now, check if we may insert records on this pid.
                            if ($theRealPid >= 0) {
                                // Checks if records can be inserted on this $pid.
                                $recordAccess = $this->checkRecordInsertAccess($table, $theRealPid);
                                if ($recordAccess) {
                                    $this->addDefaultPermittedLanguageIfNotSet($table, $incomingFieldArray);
                                    $recordAccess = $this->BE_USER->recordEditAccessInternals($table, $incomingFieldArray, TRUE);
                                    if (!$recordAccess) {
                                        $this->newlog('recordEditAccessInternals() check failed. [' . $this->BE_USER->errorMsg . ']', 1);
                                    } elseif (!$this->bypassWorkspaceRestrictions) {
                                        // Workspace related processing:
                                        // If LIVE records cannot be created in the current PID due to workspace restrictions, prepare creation of placeholder-record
                                        if ($res = $this->BE_USER->workspaceAllowLiveRecordsInPID($theRealPid, $table)) {
                                            if ($res < 0) {
                                                $recordAccess = FALSE;
                                                $this->newlog('Stage for versioning root point and users access level did not allow for editing', 1);
                                            }
                                        } else {
                                            // So, if no live records were allowed, we have to create a new version of this record:
                                            if ($GLOBALS['TCA'][$table]['ctrl']['versioningWS']) {
                                                $createNewVersion = TRUE;
                                            } else {
                                                $recordAccess = FALSE;
                                                $this->newlog('Record could not be created in this workspace in this branch', 1);
                                            }
                                        }
                                    }
                                }
                            } else {
                                debug('Internal ERROR: pid should not be less than zero!');
                            }
                            // Yes new record, change $record_status to 'insert'
                            $status = 'new';
                        } else {
                            // Nope... $id is a number
                            $fieldArray = array();
                            $recordAccess = $this->checkRecordUpdateAccess($table, $id, $incomingFieldArray, $hookObjectsArr);
                            if (!$recordAccess) {
                                $propArr = $this->getRecordProperties($table, $id);
                                $this->log($table, $id, 2, 0, 1, 'Attempt to modify record \'%s\' (%s) without permission. Or non-existing page.', 2, array($propArr['header'], $table . ':' . $id), $propArr['event_pid']);
                            } else {
                                // Next check of the record permissions (internals)
                                $recordAccess = $this->BE_USER->recordEditAccessInternals($table, $id);
                                if (!$recordAccess) {
                                    $propArr = $this->getRecordProperties($table, $id);
                                    $this->newlog('recordEditAccessInternals() check failed. [' . $this->BE_USER->errorMsg . ']', 1);
                                } else {
                                    // Here we fetch the PID of the record that we point to...
                                    $tempdata = $this->recordInfo($table, $id, 'pid' . ($GLOBALS['TCA'][$table]['ctrl']['versioningWS'] ? ',t3ver_wsid,t3ver_stage' : ''));
                                    $theRealPid = $tempdata['pid'];
                                    // Use the new id of the versionized record we're trying to write to:
                                    // (This record is a child record of a parent and has already been versionized.)
                                    if ($this->autoVersionIdMap[$table][$id]) {
                                        // For the reason that creating a new version of this record, automatically
                                        // created related child records (e.g. "IRRE"), update the accordant field:
                                        $this->getVersionizedIncomingFieldArray($table, $id, $incomingFieldArray, $registerDBList);
                                        // Use the new id of the copied/versionized record:
                                        $id = $this->autoVersionIdMap[$table][$id];
                                        $recordAccess = TRUE;
                                        $this->autoVersioningUpdate = TRUE;
                                    } elseif (!$this->bypassWorkspaceRestrictions && ($errorCode = $this->BE_USER->workspaceCannotEditRecord($table, $tempdata))) {
                                        $recordAccess = FALSE;
                                        // Versioning is required and it must be offline version!
                                        // Check if there already is a workspace version
                                        $WSversion = BackendUtility::getWorkspaceVersionOfRecord($this->BE_USER->workspace, $table, $id, 'uid,t3ver_oid');
                                        if ($WSversion) {
                                            $id = $WSversion['uid'];
                                            $recordAccess = TRUE;
                                        } elseif ($this->BE_USER->workspaceAllowAutoCreation($table, $id, $theRealPid)) {
                                            $tce = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\DataHandling\\DataHandler');
                                            /** @var $tce \TYPO3\CMS\Core\DataHandling\DataHandler */
                                            $tce->stripslashes_values = 0;
                                            // Setting up command for creating a new version of the record:
                                            $cmd = array();
                                            $cmd[$table][$id]['version'] = array(
                                                'action' => 'new',
                                                'treeLevels' => -1,
                                                // Default is to create a version of the individual records... element versioning that is.
                                                'label' => 'Auto-created for WS #' . $this->BE_USER->workspace
                                            );
                                            $tce->start(array(), $cmd);
                                            $tce->process_cmdmap();
                                            $this->errorLog = array_merge($this->errorLog, $tce->errorLog);
                                            // If copying was successful, share the new uids (also of related children):
                                            if ($tce->copyMappingArray[$table][$id]) {
                                                foreach ($tce->copyMappingArray as $origTable => $origIdArray) {
                                                    foreach ($origIdArray as $origId => $newId) {
                                                        $this->uploadedFileArray[$origTable][$newId] = $this->uploadedFileArray[$origTable][$origId];
                                                        $this->autoVersionIdMap[$origTable][$origId] = $newId;
                                                    }
                                                }
                                                \TYPO3\CMS\Core\Utility\ArrayUtility::mergeRecursiveWithOverrule($this->RTEmagic_copyIndex, $tce->RTEmagic_copyIndex);
                                                // See where RTEmagic_copyIndex is used inside fillInFieldArray() for more information...
                                                // Update registerDBList, that holds the copied relations to child records:
                                                $registerDBList = array_merge($registerDBList, $tce->registerDBList);
                                                // For the reason that creating a new version of this record, automatically
                                                // created related child records (e.g. "IRRE"), update the accordant field:
                                                $this->getVersionizedIncomingFieldArray($table, $id, $incomingFieldArray, $registerDBList);
                                                // Use the new id of the copied/versionized record:
                                                $id = $this->autoVersionIdMap[$table][$id];
                                                $recordAccess = TRUE;
                                                $this->autoVersioningUpdate = TRUE;
                                            } else {
                                                $this->newlog('Could not be edited in offline workspace in the branch where found (failure state: \'' . $errorCode . '\'). Auto-creation of version failed!', 1);
                                            }
                                        } else {
                                            $this->newlog('Could not be edited in offline workspace in the branch where found (failure state: \'' . $errorCode . '\'). Auto-creation of version not allowed in workspace!', 1);
                                        }
                                    }
                                }
                            }
                            // The default is 'update'
                            $status = 'update';
                        }
                        // If access was granted above, proceed to create or update record:
                        if ($recordAccess) {
                            // Here the "pid" is set IF NOT the old pid was a string pointing to a place in the subst-id array.
                            list($tscPID) = BackendUtility::getTSCpid($table, $id, $old_pid_value ? $old_pid_value : $fieldArray['pid']);
                            if ($status === 'new' && $table === 'pages') {
                                $TSConfig = $this->getTCEMAIN_TSconfig($tscPID);
                                if (isset($TSConfig['permissions.']) && is_array($TSConfig['permissions.'])) {
                                    $fieldArray = $this->setTSconfigPermissions($fieldArray, $TSConfig['permissions.']);
                                }
                            }
                            // Processing of all fields in incomingFieldArray and setting them in $fieldArray
                            $fieldArray = $this->fillInFieldArray($table, $id, $fieldArray, $incomingFieldArray, $theRealPid, $status, $tscPID);
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
                            if ($status == 'new') {
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
                            if ($GLOBALS['TCA'][$table]['ctrl']['tstamp'] && count($fieldArray)) {
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
                                if ($status == 'new') {
                                    // This creates a new version of the record with online placeholder and offline version
                                    if ($createNewVersion) {
                                        $newVersion_placeholderFieldArray['t3ver_label'] = 'INITIAL PLACEHOLDER';
                                        // Setting placeholder state value for temporary record
                                        $newVersion_placeholderFieldArray['t3ver_state'] = (string)new VersionState(VersionState::NEW_PLACEHOLDER);
                                        // Setting workspace - only so display of place holders can filter out those from other workspaces.
                                        $newVersion_placeholderFieldArray['t3ver_wsid'] = $this->BE_USER->workspace;
                                        $newVersion_placeholderFieldArray[$GLOBALS['TCA'][$table]['ctrl']['label']] = $this->getPlaceholderTitleForTableLabel($table);
                                        // Saving placeholder as 'original'
                                        // PxDbSequencer: Call it with the additional $suggestedUid parameter
                                        $this->insertDB($table, $id, $newVersion_placeholderFieldArray, FALSE, (int)$incomingFieldArray['uid']);
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
                                        $pxDbSequencerHookObj = GeneralUtility::getUserObj($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['tx_pxdbsequencer']);
                                        if (method_exists($pxDbSequencerHookObj, 'processDatamap_preProcessFieldArray')) {
                                            $pxDbSequencerHookObj->processDatamap_preProcessFieldArray($incomingFieldArray, $table, $id, $this);
                                        }

                                        // When inserted, $this->substNEWwithIDs[$id] will be changed to the uid of THIS version and so the interface will pick it up just nice!
                                        // PxDbSequencer: Call it with the additional $suggestedUid parameter
                                        $phShadowId = $this->insertDB($table, $id, $fieldArray, TRUE, (int)$incomingFieldArray['uid'], TRUE);
                                        if ($phShadowId) {
                                            // Processes fields of the placeholder record:
                                            $this->triggerRemapAction($table, $id, array($this, 'placeholderShadowing'), array($table, $phShadowId));
                                            // Hold auto-versionized ids of placeholders:
                                            $this->autoVersionIdMap[$table][$this->substNEWwithIDs[$id]] = $phShadowId;
                                        }
                                    } else {
                                        $this->insertDB($table, $id, $fieldArray, FALSE, $incomingFieldArray['uid']);
                                    }
                                } else {
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
                }
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

}