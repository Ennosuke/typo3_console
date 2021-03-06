<?php
namespace Helhum\Typo3Console\Command;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Helmut Hummel <helmut.hummel@typo3.org>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the text file GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Helhum\Typo3Console\Mvc\Controller\CommandController;

/**
 * Class SchedulerCommandController
 */
class SchedulerCommandController extends CommandController {

	/**
	 * @var \TYPO3\CMS\Scheduler\Scheduler
	 * @inject
	 */
	protected $scheduler;


	/**
	 * Executes tasks that are registered in the scheduler module
	 *
	 * @param int $taskId Uid of the task that should be executed (instead of all scheduled tasks)
	 * @param bool $forceExecution When specifying a single task, the execution can be forced with this flag. The task will then be executed even if it is not scheduled for execution yet.
	 */
	public function runCommand($taskId = NULL, $forceExecution = FALSE) {
		if ($taskId !== NULL) {
			if ($taskId <= 0) {
				$this->outputLine('Task Id must be higher than zero.');
				$this->sendAndExit(1);
			}
			$this->executeSingleTask($taskId, $forceExecution);
		} else {
			if ($forceExecution) {
				$this->outputLine('Execution can only be forced when a single task is specified.');
				$this->sendAndExit(2);
			}
			$this->executeScheduledTasks();
		}
	}

	/**
	 * Execute all scheduled tasks
	 */
	protected function executeScheduledTasks() {
		// Loop as long as there are tasks
		do {
			// Try getting the next task and execute it
			// If there are no more tasks to execute, an exception is thrown by \TYPO3\CMS\Scheduler\Scheduler::fetchTask()
			try {
				/** @var $task \TYPO3\CMS\Scheduler\Task\AbstractTask */
				$task = $this->scheduler->fetchTask();
				$hasTask = TRUE;
				try {
					$this->scheduler->executeTask($task);
				} catch (\Exception $e) {
					// We ignore any exception that may have been thrown during execution,
					// as this is a background process.
					// The exception message has been recorded to the database anyway
					continue;
				}
			} catch (\OutOfBoundsException $e) {
				$hasTask = FALSE;
			} catch (\UnexpectedValueException $e) {
				continue;
			}
		} while ($hasTask);
		// Record the run in the system registry
		$this->scheduler->recordLastRun();
	}

	/**
	 * Execute a single task
	 *
	 * @param int $taskId
	 * @param bool $forceExecution
	 */
	protected function executeSingleTask($taskId, $forceExecution) {
		// Force the execution of the task even if it is disabled or no execution scheduled
		if ($forceExecution) {
			$task = $this->scheduler->fetchTask($taskId);
		} else {
			$whereClause = 'uid = ' . (int)$taskId . ' AND nextexecution != 0 AND nextexecution <= ' . (int)$GLOBALS['EXEC_TIME'];
			list($task) = $this->scheduler->fetchTasksWithCondition($whereClause);
		}
		if ($this->scheduler->isValidTaskObject($task)) {
			try {
				$this->scheduler->executeTask($task);
			} catch (\Exception $e) {

			}
			// Record the run in the system registry
			$this->scheduler->recordLastRun('cli-by-id');
		}
	}
}