<?php
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
 *
 * @author  Dennis Hillmann <dh@udmedia.de>
 * @version  0.5.0
 */
class UpdateCommandController extends CommandController {

	/**
	 * @var \TYPO3\CMS\Install\Service\SqlSchemaMigrationService
	 */
	protected $schemaMigrationService;
	/**
	 * @var \TYPO3\CMS\Install\Service\SqlExpectedSchemaService
	 */
	protected $expectedSchemaService;

	/**
	 * Updates the database tables
	 *
	 * Updates all database tables that are not the way that we expect it from the core version and such
	 * Outputs each and every SQL Query and ends when one error occurs
	 */
	public function updateCommand() {
		$this->schemaMigrationService = $this->objectManager->get('TYPO3\\CMS\\Install\\Service\\SqlSchemaMigrationService');
		$this->expectedSchemaService  = $this->objectManager->get('TYPO3\\CMS\\Install\\Service\\SqlExpectedSchemaService');
		$expectedSchema = $this->expectedSchemaService->getExpectedDatabaseSchema();
		$currentSchema 	= $this->schemaMigrationService->getFieldDefinitions_database();

		$schemaDifferences = $this->schemaMigrationService->getDatabaseExtra($expectedSchema, $currentSchema);
		$updateStatements  = $this->schemaMigrationService->getUpdateSuggestions($schemaDifferences);

		$dbQueries = [];

		foreach((array)$updateStatements['create_table'] as $query) {
			$GLOBALS['TYPO3_DB']->admin_query($query);
			$dbQueries[] = $query;
			if($GLOBALS['TYPO3_DB']->sql_error()) {
				$this->outputLine('SQL-ERROR: '.$GLOBALS['TYPO3_DB']->sql_error());
				return;
			}
		}

		foreach((array)$updateStatements['add'] as $query) {
			$GLOBALS['TYPO3_DB']->admin_query($query);
			$dbQueries[] = $query;
			if($GLOBALS['TYPO3_DB']->sql_error()) {
				$this->outputLine('SQL-ERROR: '.$GLOBALS['TYPO3_DB']->sql_error());
				return;
			}
		}

		foreach((array)$updateStatements['change'] as $query) {
			$GLOBALS['TYPO3_DB']->admin_query($query);
			$dbQueries[] = $query;
			if($GLOBALS['TYPO3_DB']->sql_error()) {
				$this->outputLine('SQL-ERROR: '.$GLOBALS['TYPO3_DB']->sql_error());
				return;
			}
		}
		foreach($dbQueries as $query) {
			$this->outputLine($query);
		} 
		if(count($dbQueries) < 1)
			$this->outputLine('No update needed');
		else
			$this->outputLine('Update success');
		return;
	}

	/**
	 * Checks if there are any differences between the current and the expected database state
	 *
	 * Outputs 'No updates needed' if no differences are found else the output is "Updates needed, execute update:update"
	 */
	public function checkUpdateCommand() {
		$this->schemaMigrationService = $this->objectManager->get('TYPO3\\CMS\\Install\\Service\\SqlSchemaMigrationService');
		$this->expectedSchemaService  = $this->objectManager->get('TYPO3\\CMS\\Install\\Service\\SqlExpectedSchemaService');
		$expectedSchema = $this->expectedSchemaService->getExpectedDatabaseSchema();
		$currentSchema 	= $this->schemaMigrationService->getFieldDefinitions_database();

		$schemaDifferences = $this->schemaMigrationService->getDatabaseExtra($expectedSchema, $currentSchema);
		$updateStatements  = $this->schemaMigrationService->getUpdateSuggestions($schemaDifferences);
		if(count($updateStatements) < 1)
			$this->outputLine('No updates needed');
		else
			$this->outputLine('Updates needed, execute update:update');
	}

	/**
	 * Updates all extensions who have an update aviable
	 *
	 */
	public function updateExtensionsCommand() {
		$listUtility			= $this->objectManager->get('TYPO3\\CMS\\Extensionmanager\\Utility\\ListUtility');
		$managementService		= $this->objectManager->get('TYPO3\\CMS\\Extensionmanager\\Service\\ExtensionManagementService');
		$ExtensionRepository	= $this->objectManager->get('TYPO3\\CMS\\Extensionmanager\\Domain\\Repository\ExtensionRepository');
		$updateScriptUtility	= $this->objectManager->get('TYPO3\\CMS\\Extensionmanager\\Utility\\UpdateScriptUtility');
		$extensions				= $listUtility->getAvailableAndInstalledExtensionsWithAdditionalInformation();

		$updates = [];
		foreach($extensions as $extKey => $properties) {
			if($properties['updateAvailable'] == true) {
				$this->outputLine('Updating extension '.$extKey);
				$highestTerVersionExtension = $extensionRepository->findHighestAvailableVersion($extKey);
				try {
					$managementService->downloadMainExtension($highestTerVersionExtension);
					$updateScriptResult = $updateScriptUtility->executeUpdateIfNeeded($extKey);
				} catch (\Exception $e) {
					$hasErrors = TRUE;
					$errorMessage = $e->getMessage();
					$this->outputLine($errorMessage);
				}
			}
		} 
	}
}