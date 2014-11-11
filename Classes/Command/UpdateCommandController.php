<?php
/**
 * Command Controller for update methods
 *
 * Methods via CLI are:
 * - update:update to update the database after a core update
 * - update:checkupdate to chek whenether update for db is needed
 * - update:updateextensions updates all extenions who have a know update
 * - update:updatecore updates the core files and database
 *
 * @author  Dennis Hillmann <dh@udmedia.de>
 * @version  1.0.0
 */
namespace Helhum\Typo3Console\Command;

use Helhum\Typo3Console\Mvc\Controller\CommandController;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Command Controller for update methods
 *
 * Methods via CLI are:
 * - update:update to update the database after a core update
 * - update:checkupdate to chek whenether update for db is needed
 * - update:updateextensions updates all extenions who have a know update
 * - update:updatecore updates the core files and database
 *
 * @author  Dennis Hillmann <dh@udmedia.de>
 * @version  1.0.0
 */
class UpdateCommandController extends CommandController {

	/**
	 * Schema Migration Service
	 * @var \TYPO3\CMS\Install\Service\SqlSchemaMigrationService
	 */
	protected $schemaMigrationService;
	/**
	 * Expected Schema Service
	 * @var \TYPO3\CMS\Install\Service\SqlExpectedSchemaService
	 */
	protected $expectedSchemaService;
	/**
	 * List Utility used to get all installed Extensions
	 * @var \TYPO3\CMS\Extensionmanager\Utility\ListUtility
	 */
	protected $listUtility;
	/**
	 * Extension Management Service
	 * @var \TYPO3\CMS\Extensionmanager\Service\ExtensionManagementService
	 */
	protected $managementService;
	/**
	 * Extension Repository
	 * @var \TYPO3\CMS\Extensionmanager\Domain\Repository\ExtensionRepository
	 */
	protected $extensionsRepository;
	/**
	 * Update Script Utility
	 * @var \TYPO3\CMS\Extensionmanager\Utility\UpdateScriptUtility
	 */
	protected $updateScriptUtility;
	/**
	 * Updates the database tables
	 *
	 * Updates all database tables that are not the way that we expect it from the core version and such
	 * Outputs each and every SQL Query and ends when one error occurs
	 */
	public function updateCommand() {
		$this->schemaMigrationService	= $this->objectManager->get('TYPO3\\CMS\\Install\\Service\\SqlSchemaMigrationService');
		$this->expectedSchemaService 	= $this->objectManager->get('TYPO3\\CMS\\Install\\Service\\SqlExpectedSchemaService');
		$expectedSchema					= $this->expectedSchemaService->getExpectedDatabaseSchema();
		$currentSchema					= $this->schemaMigrationService->getFieldDefinitions_database();

		$schemaDifferences = $this->schemaMigrationService->getDatabaseExtra($expectedSchema, $currentSchema);
		$updateStatements  = $this->schemaMigrationService->getUpdateSuggestions($schemaDifferences);

		$dbQueries = [];
		$count = count((array)$updateStatements['create_table']) + count((array)$updateStatements['add']) + count((array)$updateStatements['change']);
		if($count > 0) $this->progressStart($count);
		foreach((array)$updateStatements['create_table'] as $query) {
			$GLOBALS['TYPO3_DB']->admin_query($query);
			$dbQueries[] = $query;
			$this->progressAdvance(1, true);
			if($GLOBALS['TYPO3_DB']->sql_error()) {
				$this->progressFinish();
				$this->outputLine('SQL-ERROR: '.$GLOBALS['TYPO3_DB']->sql_error());
				return 1;
			}
		}

		foreach((array)$updateStatements['add'] as $query) {
			$GLOBALS['TYPO3_DB']->admin_query($query);
			$dbQueries[] = $query;
			$this->progressAdvance(1, true);
			if($GLOBALS['TYPO3_DB']->sql_error()) {
				$this->progressFinish();
				$this->outputLine('SQL-ERROR: '.$GLOBALS['TYPO3_DB']->sql_error());
				return 1;
			}
		}

		foreach((array)$updateStatements['change'] as $query) {
			$GLOBALS['TYPO3_DB']->admin_query($query);
			$dbQueries[] = $query;
			$this->progressAdvance(1, true);
			if($GLOBALS['TYPO3_DB']->sql_error()) {
				$this->progressFinish();
				$this->outputLine('SQL-ERROR: '.$GLOBALS['TYPO3_DB']->sql_error());
				return 1;
			}
		}
		if($count > 0) $this->progressFinish();
		foreach($dbQueries as $query) {
			$this->outputLine($query);
		}
		if(count($dbQueries) < 1)
			$this->outputLine('No update needed');
		else
			$this->outputLine('Update success');
		return 0;
	}

	/**
	 * Checks if there are any differences between the current and the expected database state
	 *
	 * Outputs 'No updates needed' if no differences are found else the output is "Updates needed, execute update:update"
	 */
	public function checkUpdateCommand() {
		$this->schemaMigrationService	= $this->objectManager->get('TYPO3\\CMS\\Install\\Service\\SqlSchemaMigrationService');
		$this->expectedSchemaService	= $this->objectManager->get('TYPO3\\CMS\\Install\\Service\\SqlExpectedSchemaService');
		$expectedSchema					= $this->expectedSchemaService->getExpectedDatabaseSchema();
		$currentSchema					= $this->schemaMigrationService->getFieldDefinitions_database();

		$schemaDifferences				= $this->schemaMigrationService->getDatabaseExtra($expectedSchema, $currentSchema);
		$updateStatements 				= $this->schemaMigrationService->getUpdateSuggestions($schemaDifferences);
		if(count($updateStatements) < 1)
			$this->outputLine('No updates needed');
		else
			$this->outputLine('Updates needed, execute update:update');
	}

	/**
	 * Updates all extensions who have an update available
	 */
	public function updateExtensionsCommand() {
		$this->listUtility			= $this->objectManager->get('TYPO3\\CMS\\Extensionmanager\\Utility\\ListUtility');
		$this->managementService	= $this->objectManager->get('TYPO3\\CMS\\Extensionmanager\\Service\\ExtensionManagementService');
		$this->extensionRepository	= $this->objectManager->get('TYPO3\\CMS\\Extensionmanager\\Domain\\Repository\ExtensionRepository');
		$this->updateScriptUtility	= $this->objectManager->get('TYPO3\\CMS\\Extensionmanager\\Utility\\UpdateScriptUtility');
		$extensions					= $this->listUtility->getAvailableAndInstalledExtensionsWithAdditionalInformation();

		$updates = [];
		$this->progressStart(count($extensions));
		foreach($extensions as $extKey => $properties) {
			$this->progressAdvance(1, true);
			if($properties['updateAvailable'] == true) {
				$highestTerVersionExtension = $this->extensionRepository->findHighestAvailableVersion($extKey);
				try {
					$this->managementService->downloadMainExtension($highestTerVersionExtension);
					$updateScriptResult = $this->updateScriptUtility->executeUpdateIfNeeded($extKey);
				} catch (\Exception $e) {
					$hasErrors = TRUE;
					$errorMessage = $e->getMessage();
					$this->outputLine($errorMessage);
				}
			}
		}
		$this->progressFinish(); 
	}
	/**
	 * Updates the core of typo3 to the newest version including file system changes
	 * returns error code 1 if an error occured or 0 if no error
	 */
	public function updateCoreCommand() {
		$coreVersionService = $this->objectManager->get('TYPO3\\CMS\\Install\\Service\\CoreVersionService');
		$coreUpdateService = $this->objectManager->get('TYPO3\\CMS\\Install\\Service\\CoreUpdateService');
		if(!$coreUpdateService->updateVersionMatrix()) {
			$this->outputLine('The version matrix could not be updated');
			return 1;
		}
		if(!$coreVersionService->isAReleaseVersion()) {
			$this->outputLine('This is a dev version and can\'t be updated');
			return 1;
		}
		if(!$coreVersionService->isYoungerPatchReleaseAvailable()) {
			$this->outputLine('No new patch available');
			return 0;
		}
		$youngestPatchRelease = $coreVersionService->getYoungestPatchRelease();
		if(!$coreUpdateService->checkPreConditions()) {
			$this->outputLine('The pre conditions are not met');
			return 1;
		}
		if(!$coreUpdateService->downloadVersion($youngestPatchRelease)) {
			$this->outputLine('The patch could not be downloaded');
			return 1;
		}
		if(!$coreUpdateService->verifyFileChecksum($youngestPatchRelease)) {
			$this->outputLine('The file could not be verified');
			return 1;
		}
		if(!$coreUpdateService->unpackVersion($youngestPatchRelease)) {
			$this->outputLine('The file could not be unpacked');
			return 1;
		}
		if(!$coreUpdateService->moveVersion($youngestPatchRelease)) {
			$this->outputLine('The update could not be moved');
			return 1;
		}
		if(!$coreUpdateService->activateVersion($youngestPatchRelease)) {
			$this->outputLine('The update could not be activated');
			return 1;
		}
		return $this->updateCommand();
	}
}