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
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\Localization\DataMapProcessor;
use TYPO3\CMS\Core\DataHandling\PagePermissionAssembler;
use TYPO3\CMS\Core\Schema\Capability\LanguageAwareSchemaCapability;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * The main data handler class which takes care of correctly updating and inserting records.
 * This class was formerly known as TCEmain.
 *
 * This is the TYPO3 Core Engine class for manipulation of the database
 * This class is used by eg. the tce_db BE route (SimpleDataHandlerController) which provides an interface for POST forms to this class.
 *
 * Dependencies:
 * - $GLOBALS['TCA'] must exist
 * - $GLOBALS['LANG'] must exist
 *
 * Also see document 'TYPO3 Core API' for details.
 */
#[Autoconfigure(public: true, shared: false)]
class DataHandler extends \TYPO3\CMS\Core\DataHandling\DataHandler
{
    /**
     * if > 0 will be used in insertDB()
     *
     * @var int
     */
    public int $currentSuggestUid = 0;

    /**
     * Processing the data-array
     * Call this function to process the data-array set by start()
     */
    public function process_datamap(): void
    {
        $context = GeneralUtility::makeInstance(Context::class);

        $this->controlActiveElements();
        $this->registerElementsToBeDeleted();
        $this->datamap = $this->unsetElementsToBeDeleted($this->datamap);

        if ($this->BE_USER->workspace !== 0 && ($this->BE_USER->workspaceRec['freeze'] ?? false)) {
            // Workspace is frozen
            $this->log('sys_workspace', $this->BE_USER->workspace, SystemLogDatabaseAction::VERSIONIZE, null, SystemLogErrorClassification::USER_ERROR, 'All editing in this workspace has been frozen');
            return;
        }

        $hookObjectsArr = [];
        foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'] ?? [] as $className) {
            // Instantiate hooks and call first hook method
            $hookObject = GeneralUtility::makeInstance($className);
            if (method_exists($hookObject, 'processDatamap_beforeStart')) {
                $hookObject->processDatamap_beforeStart($this);
            }
            $hookObjectsArr[] = $hookObject;
        }

        foreach ($this->datamap as $tableName => $tableDataMap) {
            foreach ($tableDataMap as $identifier => $fieldValues) {
                if (!MathUtility::canBeInterpretedAsInteger($identifier)) {
                    $this->datamap[$tableName][$identifier] = $this->initializeSlugFieldsToEmptyString($tableName, $fieldValues);
                    $this->datamap[$tableName][$identifier] = $this->initializeUuidFieldsToEmptyString($tableName, $fieldValues);
                }
            }
        }

        $this->datamap = DataMapProcessor::instance($this->datamap, $this->BE_USER, $this->referenceIndexUpdater)->process();
        $registerDBList = [];
        $orderOfTables = [];
        if (isset($this->datamap['pages'])) {
            // Handling pages table is always the first task to make sure they are done if other records are added to them.
            $orderOfTables[] = 'pages';
        }
        $orderOfTables = array_unique(array_merge($orderOfTables, array_keys($this->datamap)));
        $tcaSchemaFactory = GeneralUtility::makeInstance(TcaSchemaFactory::class);
        foreach ($orderOfTables as $table) {
            if (!$this->checkModifyAccessList($table)) {
                // User is not allowed to modify
                $this->log($table, 0, SystemLogDatabaseAction::UPDATE, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to modify table "{table}" without permission', null, ['table' => $table]);
                continue;
            }
            if (!$tcaSchemaFactory->has($table)) {
                // Table not set in TCA
                continue;
            }
            $schema = $tcaSchemaFactory->get($table);
            if ($schema->hasCapability(TcaSchemaCapability::AccessReadOnly)) {
                // Table is readonly
                continue;
            }

            if ($this->reverseOrder) {
                $this->datamap[$table] = array_reverse($this->datamap[$table], true);
            }

            foreach ($this->datamap[$table] as $id => $incomingFieldArray) {
                if (!is_array($incomingFieldArray)) {
                    continue;
                }
                foreach ($hookObjectsArr as $hookObj) {
                    if (method_exists($hookObj, 'processDatamap_preProcessFieldArray')) {
                        $hookObj->processDatamap_preProcessFieldArray($incomingFieldArray, $table, $id, $this);
                        // If a hook invalidated $incomingFieldArray, skip the record completely
                        if (!is_array($incomingFieldArray)) {
                            continue 2;
                        }
                    }
                }

                $theRealPid = null;
                $createNewVersion = false;
                $old_pid_value = '';
                if (!MathUtility::canBeInterpretedAsInteger($id)) {
                    // $id is not an integer. We're creating a new record.
                    // Get a fieldArray with tca default values
                    $fieldArray = $this->newFieldArray($table);
                    if (isset($incomingFieldArray['pid'])) {
                        // A pid must be set for new records.
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
                    $languageField = null;
                    $transOrigPointerField = null;
                    if ($schema->isLanguageAware()) {
                        /** @var LanguageAwareSchemaCapability $languageCapability */
                        $languageCapability = $schema->getCapability(TcaSchemaCapability::Language);
                        $languageField = $languageCapability->getLanguageField()->getName();
                        $transOrigPointerField = $languageCapability->getTranslationOriginPointerField()->getName();
                    }
                    if ($table === 'pages'
                        && $languageField && isset($incomingFieldArray[$languageField]) && $incomingFieldArray[$languageField] > 0
                        && $transOrigPointerField && isset($incomingFieldArray[$transOrigPointerField]) && $incomingFieldArray[$transOrigPointerField] > 0
                    ) {
                        $pageRecord = BackendUtility::getRecord('pages', $incomingFieldArray[$transOrigPointerField]) ?? [];
                        if (!$this->hasPermissionToInsert($table, $incomingFieldArray[$transOrigPointerField], $pageRecord)) {
                            $this->log($table, $incomingFieldArray[$transOrigPointerField], SystemLogDatabaseAction::INSERT, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to insert record on pages:{pid} where table "{table}" is not allowed', null, ['pid' => $incomingFieldArray[$transOrigPointerField], 'table' => $table], $incomingFieldArray[$transOrigPointerField]);
                            continue;
                        }
                    } else {
                        $pageRecord = BackendUtility::getRecord('pages', $theRealPid) ?? [];
                        if (!$this->hasPermissionToInsert($table, $theRealPid, $pageRecord)) {
                            $this->log($table, $theRealPid, SystemLogDatabaseAction::INSERT, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to insert record on pages:{pid} where table "{table}" is not allowed', null, ['pid' => $theRealPid, 'table' => $table], $theRealPid);
                            continue;
                        }
                    }
                    $incomingFieldArray = $this->addDefaultPermittedLanguageIfNotSet($table, $incomingFieldArray, $theRealPid);
                    if (!$this->BE_USER->recordEditAccessInternals($table, $incomingFieldArray, true)) {
                        $this->log($table, 0, SystemLogDatabaseAction::INSERT, null, SystemLogErrorClassification::USER_ERROR, 'recordEditAccessInternals() check failed [{reason}]', null, ['reason' => $this->BE_USER->errorMsg]);
                        continue;
                    }
                    if (!$this->bypassWorkspaceRestrictions && !$this->BE_USER->workspaceAllowsLiveEditingInTable($table)) {
                        // If LIVE records cannot be created due to workspace restrictions, prepare creation of placeholder-record
                        // So, if no live records were allowed in the current workspace, we have to create a new version of this record
                        if ($schema->isWorkspaceAware()) {
                            $createNewVersion = true;
                        } else {
                            $this->log($table, 0, SystemLogDatabaseAction::VERSIONIZE, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to insert version record "{table}:{uid}" to this workspace failed. "Live" edit permissions of records from tables without versioning required', null, ['table' => $table, 'uid' => $id]);
                            continue;
                        }
                    }
                    // Here the "pid" is set IF NOT the old pid was a string pointing to a place in the subst-id array.
                    [$tscPID] = BackendUtility::getTSCpid($table, $id, $old_pid_value ?: ($fieldArray['pid'] ?? 0));
                    // Apply TCA defaults from pageTS
                    $fieldArray = $this->applyDefaultsForFieldArray($table, (int)$tscPID, $fieldArray);
                    // Apply page permissions as well
                    if ($table === 'pages') {
                        $fieldArray = GeneralUtility::makeInstance(PagePermissionAssembler::class)->applyDefaults(
                            $fieldArray,
                            (int)$tscPID,
                            $this->userid,
                            (int)$this->BE_USER->firstMainGroup
                        );
                    }
                    // Ensure that the default values, that are stored in the $fieldArray (built from internal default values)
                    // Are also placed inside the incomingFieldArray, so this is checked in "fillInFieldArray" and
                    // all default values are also checked for validity
                    // This allows to set TCA defaults (for example) without having to use FormEngine to have the fields available first.
                    $incomingFieldArray = array_replace_recursive($fieldArray, $incomingFieldArray);
                    // Processing of all fields in incomingFieldArray and setting them in $fieldArray
                    $fieldArray = $this->fillInFieldArray($table, $id, $fieldArray, $incomingFieldArray, $theRealPid, 'new', $tscPID);
                    // Setting system fields
                    if ($schema->hasCapability(TcaSchemaCapability::CreatedAt)) {
                        $fieldArray[$schema->getCapability(TcaSchemaCapability::CreatedAt)->getFieldName()] = $context->getPropertyFromAspect('date', 'timestamp');
                    }
                    // Set stage to "Editing" to make sure we restart the workflow
                    if ($schema->isWorkspaceAware()) {
                        $fieldArray['t3ver_stage'] = 0;
                    }
                    if ($schema->hasCapability(TcaSchemaCapability::UpdatedAt) && !empty($fieldArray)) {
                        $fieldArray[$schema->getCapability(TcaSchemaCapability::UpdatedAt)->getFieldName()] = $context->getPropertyFromAspect('date', 'timestamp');
                    }
                    foreach ($hookObjectsArr as $hookObj) {
                        if (method_exists($hookObj, 'processDatamap_postProcessFieldArray')) {
                            $hookObj->processDatamap_postProcessFieldArray('new', $table, $id, $fieldArray, $this);
                        }
                    }
                    // Performing insert/update. If fieldArray has been unset by some userfunction (see hook above), don't do anything
                    // Kasper: Unsetting the fieldArray is dangerous; MM relations might be saved already
                    if (is_array($fieldArray)) {
                        if ($table === 'pages') {
                            // for new pages always a refresh is needed
                            $this->pagetreeNeedsRefresh = true;
                        }
                        // This creates a version of the record, instead of adding it to the live workspace
                        if ($createNewVersion) {
                            // new record created in a workspace - so always refresh page tree to indicate there is a change in the workspace
                            $this->pagetreeNeedsRefresh = true;
                            $fieldArray['pid'] = $theRealPid;
                            $fieldArray['t3ver_oid'] = 0;
                            // Setting state for version (so it can know it is currently a new version...)
                            $fieldArray['t3ver_state'] = VersionState::NEW_PLACEHOLDER->value;
                            $fieldArray['t3ver_wsid'] = $this->BE_USER->workspace;

                            //
                            // PxDbSequencer: Re-call PxDbsequencer DataHandlerHook to generate a sequenced uid for the placeholder record, if needed
                            //
                            /** @var DataHandlerHook $pxDbSequencerHookObj */
                            $pxDbSequencerHookObj = GeneralUtility::makeInstance($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['tx_pxdbsequencer']);
                            if (method_exists($pxDbSequencerHookObj, 'processDatamap_postProcessFieldArray')) {
                                $pxDbSequencerHookObj->processDatamap_postProcessFieldArray('new', $table, $id, $fieldArray, $this);
                            }

                            $this->insertDB($table, $id, $fieldArray, null, (int)($incomingFieldArray['uid'] ?? 0));
                            // Hold auto-versioned ids of placeholders
                            $this->autoVersionIdMap[$table][$this->substNEWwithIDs[$id]] = $this->substNEWwithIDs[$id];
                        } else {
                            $this->insertDB($table, $id, $fieldArray, null, (int)($incomingFieldArray['uid'] ?? 0));
                        }
                    }
                    // Note: When using the hook after INSERT operations, you will only get the temporary NEW... id passed to your hook as $id,
                    // but you can easily translate it to the real uid of the inserted record using the $this->substNEWwithIDs array.
                    $this->hook_processDatamap_afterDatabaseOperations($hookObjectsArr, 'new', $table, $id, $fieldArray);
                } else {
                    // $id is an integer. We're updating an existing record or creating a workspace version.
                    $id = (int)$id;
                    $fieldArray = [];
                    $recordAccess = null;
                    $currentRecord = BackendUtility::getRecord($table, $id, '*', '', false);
                    if (empty($currentRecord) || ($currentRecord['pid'] ?? null) === null) {
                        // Skip if there is no record. Skip if record has no pid column indicating incomplete DB.
                        continue;
                    }
                    $pageRecord = [];
                    if ($table === 'pages') {
                        $pageRecord = $currentRecord;
                    } elseif ((int)$currentRecord['pid'] > 0) {
                        $pageRecord = BackendUtility::getRecord('pages', $currentRecord['pid']) ?? [];
                    }
                    foreach ($hookObjectsArr as $hookObj) {
                        if (method_exists($hookObj, 'checkRecordUpdateAccess')) {
                            $recordAccess = $hookObj->checkRecordUpdateAccess($table, $id, $incomingFieldArray, $recordAccess, $this);
                        }
                    }
                    if ($recordAccess !== null) {
                        if (!$recordAccess) {
                            $this->log($table, $id, SystemLogDatabaseAction::UPDATE, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to modify record {table}:{uid} denied by checkRecordUpdateAccess hook', null, ['table' => $table, 'uid' => $id], (int)$currentRecord['pid']);
                            continue;
                        }
                    } elseif ($pageRecord === [] && $currentRecord['pid'] === 0 && !($this->admin || BackendUtility::isRootLevelRestrictionIgnored($table))
                        || (($pageRecord !== [] || $currentRecord['pid'] !== 0) && !$this->hasPermissionToUpdate($table, $pageRecord))
                    ) {
                        $this->log($table, $id, SystemLogDatabaseAction::UPDATE, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to modify record {table}:{uid} without permission or non-existing page', null, ['table' => $table, 'uid' => $id], (int)$currentRecord['pid']);
                        continue;
                    }
                    if (!$this->BE_USER->recordEditAccessInternals($table, $currentRecord)) {
                        $this->log($table, $id, SystemLogDatabaseAction::UPDATE, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to modify record {table}:{uid} failed with: {reason}', null, ['table' => $table, 'uid' => $id, 'reason' => $this->BE_USER->errorMsg]);
                        continue;
                    }
                    // Use the new id of the versioned record we're trying to write to.
                    // This record is a child record of a parent and has already been versioned.
                    if (!empty($this->autoVersionIdMap[$table][$id])) {
                        // For the reason that creating a new version of this record, automatically
                        // created related child records (e.g. "IRRE"), update the accordant field:
                        $this->getVersionizedIncomingFieldArray($table, $id, $incomingFieldArray, $registerDBList);
                        // Use the new id of the copied/versioned record:
                        $id = $this->autoVersionIdMap[$table][$id];
                    } elseif (!$this->bypassWorkspaceRestrictions && ($errorCode = $this->workspaceCannotEditRecord($table, $currentRecord))) {
                        // Versioning is required and it must be offline version!
                        // Check if there already is a workspace version
                        $workspaceVersion = BackendUtility::getWorkspaceVersionOfRecord($this->BE_USER->workspace, $table, $id, 'uid,t3ver_oid');
                        if ($workspaceVersion) {
                            $id = $workspaceVersion['uid'];
                        } elseif ($this->workspaceAllowAutoCreation($table, $id, (int)$currentRecord['pid'])) {
                            // new version of a record created in a workspace - so always refresh page tree to indicate there is a change in the workspace
                            $this->pagetreeNeedsRefresh = true;
                            /** @var DataHandler $tce */
                            $tce = GeneralUtility::makeInstance(self::class);
                            $tce->enableLogging = $this->enableLogging;
                            // Setting up command for creating a new version of the record:
                            $cmd = [];
                            $cmd[$table][$id]['version'] = [
                                'action' => 'new',
                                // Default is to create a version of the individual records
                                'label' => 'Auto-created for WS #' . $this->BE_USER->workspace,
                            ];
                            $tce->start([], $cmd, $this->BE_USER, $this->referenceIndexUpdater);
                            $tce->process_cmdmap();
                            $this->errorLog = array_merge($this->errorLog, $tce->errorLog);
                            // If copying was successful, share the new uids (also of related children):
                            if (empty($tce->copyMappingArray[$table][$id])) {
                                $this->log($table, $id, SystemLogDatabaseAction::VERSIONIZE, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to version record "{table}:{uid}" failed [{reason}]', null, ['reason' => $errorCode, 'table' => $table, 'uid' => $id]);
                                continue;
                            }
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
                            // Use the new id of the copied/versioned record:
                            $id = $this->autoVersionIdMap[$table][$id];
                        } else {
                            $this->log($table, $id, SystemLogDatabaseAction::VERSIONIZE, null, SystemLogErrorClassification::USER_ERROR, 'Attempt to version record "{table}:{uid}" failed [{reason}]. "Live" edit permissions of records from tables without versioning required', null, ['reason' => $errorCode, 'table' => $table, 'uid' => $id]);
                            continue;
                        }
                    }
                    // Here the "pid" is set IF NOT the old pid was a string pointing to a place in the subst-id array.
                    [$tscPID] = BackendUtility::getTSCpid($table, $id, 0);
                    // Processing of all fields in incomingFieldArray and setting them in $fieldArray
                    $fieldArray = $this->fillInFieldArray($table, $id, $fieldArray, $incomingFieldArray, (int)$currentRecord['pid'], 'update', $tscPID);
                    // Set stage to "Editing" to make sure we restart the workflow
                    if ($schema->isWorkspaceAware()) {
                        $fieldArray['t3ver_stage'] = 0;
                    }
                    // Removing fields which are equal to the current value:
                    $fieldArray = $this->compareFieldArrayWithCurrentAndUnset($table, $id, $fieldArray);
                    if ($schema->hasCapability(TcaSchemaCapability::UpdatedAt) && !empty($fieldArray)) {
                        $fieldArray[$schema->getCapability(TcaSchemaCapability::UpdatedAt)->getFieldName()] = $context->getPropertyFromAspect('date', 'timestamp');
                    }
                    foreach ($hookObjectsArr as $hookObj) {
                        if (method_exists($hookObj, 'processDatamap_postProcessFieldArray')) {
                            $hookObj->processDatamap_postProcessFieldArray('update', $table, $id, $fieldArray, $this);
                        }
                    }
                    // Performing insert/update. If fieldArray has been unset by some userfunction (see hook above), don't do anything
                    // Kasper: Unsetting the fieldArray is dangerous; MM relations might be saved already
                    if (!empty($fieldArray)) {
                        if ($table === 'pages') {
                            // Only a certain number of fields needs to be checked for updates,
                            // fields with unchanged values are already removed here.
                            $fieldsToCheck = array_intersect($this->pagetreeRefreshFieldsFromPages, array_keys($fieldArray));
                            if (!empty($fieldsToCheck)) {
                                $this->pagetreeNeedsRefresh = true;
                            }
                        }
                        $this->updateDB($table, $id, $fieldArray, (int)$currentRecord['pid']);
                    }
                    // Note: When using the hook after INSERT operations, you will only get the temporary NEW... id passed to your hook as $id,
                    // but you can easily translate it to the real uid of the inserted record using the $this->substNEWwithIDs array.
                    $this->hook_processDatamap_afterDatabaseOperations($hookObjectsArr, 'update', $table, $id, $fieldArray);
                }
            }
        }

        // Process the stack of relations to remap/correct
        $this->processRemapStack();
        $this->dbAnalysisStoreExec();

        foreach ($hookObjectsArr as $hookObj) {
            if (method_exists($hookObj, 'processDatamap_afterAllOperations')) {
                // When this hook gets called, all operations on the submitted data have been finished.
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
     * @param null $_ unused
     * @return int|null Returns ID on success.
     * @internal should only be used from within DataHandler
     */
    public function insertDB($table, $id, $fieldArray, $_ = null, $suggestedUid = 0): ?int
    {
        $tcaSchemaFactory = GeneralUtility::makeInstance(TcaSchemaFactory::class);
        if (!is_array($fieldArray) || !$tcaSchemaFactory->has($table) || !isset($fieldArray['pid'])) {
            return null;
        }
        // Do NOT insert the UID field, ever!
        unset($fieldArray['uid']);
        // Check for "suggestedUid".
        // This feature is used by the import functionality to force a new record to have a certain UID value.
        // This is only recommended for use when the destination server is a passive mirror of another server.
        // As a security measure this feature is available only for Admin Users (for now)
        // The value of $this->suggestedInsertUids["table":"uid"] is either string 'DELETE' (ext:impexp) to trigger
        // a blind delete of any possibly existing row before insert with forced uid, or boolean true (testing-framework)
        // to only force the uid insert and skipping deletion of an existing row.
        $suggestedUid = (int)$suggestedUid;
        //
        // PxDbSequencer: use uid from hook
        //
        if (!$suggestedUid && $this->currentSuggestUid) {
            $suggestedUid = (int)$this->currentSuggestUid;
        }
        //
        // PxDbSequencer: enable for non admins, to use sequencing for all BE Users: we remove $this->BE_USER->isAdmin()
        //
        if ($suggestedUid && ($this->suggestedInsertUids[$table . ':' . $suggestedUid] ?? false)) {
            // When the value of ->suggestedInsertUids[...] is "DELETE" it will try to remove the previous record
            if ($this->suggestedInsertUids[$table . ':' . $suggestedUid] === 'DELETE') {
                $this->hardDeleteSingleRecord($table, (int)$suggestedUid);
            }
            $fieldArray['uid'] = $suggestedUid;
        }
        $fieldArray = $this->insertUpdateDB_preprocessBasedOnFieldType($table, $fieldArray);
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
        try {
            // Execute the INSERT query:
            $connection->insert($table, $fieldArray);
        } catch (DBALException $e) {
            $this->log($table, 0, SystemLogDatabaseAction::INSERT, null, SystemLogErrorClassification::SYSTEM_ERROR, 'SQL error: "{reason}" ({table}:{uid})', null, ['reason' => $e->getMessage(), 'table' => $table, 'uid' => $id]);
            return null;
        }
        // Set mapping for NEW... -> real uid:
        // the NEW_id now holds the 'NEW....' -id
        $NEW_id = $id;
        $id = $this->postProcessDatabaseInsert($connection, $table, $suggestedUid);
        $this->substNEWwithIDs[$NEW_id] = $id;
        $this->substNEWwithIDs_table[$NEW_id] = $table;
        $newRow = $fieldArray;
        $newRow['uid'] = $id;
        // Update reference index:
        $this->updateRefIndex($table, $id);
        // Store in history
        $this->getRecordHistoryStore()->addRecord($table, $id, $newRow, $this->correlationId);
        if ($tcaSchemaFactory->get($table)->isWorkspaceAware() && (int)($newRow['t3ver_wsid'] ?? 0) > 0) {
            $this->log($table, $id, SystemLogDatabaseAction::INSERT, null, SystemLogErrorClassification::MESSAGE, 'New version created "{table}:{uid}". UID of new version is "{offlineUid}"', null, ['table' => $table, 'uid' => $newRow['uid'], 'offlineUid' => $id], $table === 'pages' ? $newRow['uid'] : $newRow['pid']);
        } else {
            $this->log($table, $id, SystemLogDatabaseAction::INSERT, null, SystemLogErrorClassification::MESSAGE, 'Record {table}:{uid} was inserted on page {pid}', null, ['table' => $table, 'uid' => $id, 'pid' => $newRow['pid']], $newRow['pid']);
            // Clear cache of relevant pages
            $this->registerRecordIdForPageCacheClearing($table, $id);
        }
        return $id;
    }
}
