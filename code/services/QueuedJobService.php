<?php

/**
 * A service that can be used for starting, stopping and listing queued jobs.
 *
 * When a job is first added, it is initialised, its job type determined, then persisted to the database
 *
 * When the queues are scanned, a job is reloaded and processed. Ignoring the persistence and reloading, it looks
 * something like
 *
 
 * job->getJobType();
 * job->getJobData();
 * data->write();
 * job->setup();
 * while !job->isComplete
 *	job->process();
 *	job->getJobData();
 *  data->write();
 * 
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/bsd-license/
 */
class QueuedJobService
{
	public static $stall_threshold = 3;

	/**
	 * how many meg of ram will we allow before pausing and releasing the memory?
	 *
	 * This is set to a somewhat low default as some people may not be able to run
	 * on systems with a lot of ram (128MB by default)
	 *
	 * @var int
	 */
	public static $memory_limit = 134217728;

	/**
	 * Register our shutdown handler
	 */
	public function __construct() {
		register_shutdown_function(array($this, 'onShutdown'));
	}
	
    /**
	 * Adds a job to the queue to be started
	 * 
	 * Relevant data about the job will be persisted using a QueuedJobDescriptor
	 *
	 * @param QueuedJob $job 
	 *			The job to start.
	 * @param $startAfter
	 *			The date (in Y-m-d H:i:s format) to start execution after
	 * @param int $userId
	 *			The ID of a user to execute the job as. Defaults to the current user
	 */
	public function queueJob(QueuedJob $job, $startAfter = null, $userId = null) {

		$signature = $job->getSignature();

		// see if we already have this job in a queue
		$filter = array(
			'Signature =' => $signature,
			'JobStatus =' => QueuedJob::STATUS_NEW,
		);

		$existing = DataObject::get('QueuedJobDescriptor', singleton('QJUtils')->quote($filter));

		if ($existing && $existing->Count()) {
			return $existing->First()->ID;
		}

		$jobDescriptor = new QueuedJobDescriptor();
		$jobDescriptor->JobTitle = $job->getTitle();
		$jobDescriptor->JobType = $job->getJobType();
		$jobDescriptor->Signature = $signature;
		$jobDescriptor->Implementation = get_class($job);
		$jobDescriptor->StartAfter = $startAfter;

		$jobDescriptor->RunAsID = $userId ? $userId : Member::currentUserID();

		// copy data
		$this->copyJobToDescriptor($job, $jobDescriptor);

		$jobDescriptor->write();
		
		return $jobDescriptor->ID;
	}

	/**
	 * Copies data from a job into a descriptor for persisting
	 *
	 * @param QueuedJob $job
	 * @param JobDescriptor $jobDescriptor
	 */
	protected function copyJobToDescriptor($job, $jobDescriptor) {
		$data = $job->getJobData();

		$jobDescriptor->TotalSteps = $data->totalSteps;
		$jobDescriptor->StepsProcessed = $data->currentStep;
		if ($data->isComplete) {
			$jobDescriptor->JobStatus = QueuedJob::STATUS_COMPLETE;
			$jobDescriptor->JobFinished = date('Y-m-d H:i:s');
		}

		$jobDescriptor->SavedJobData = serialize($data->jobData);
		$jobDescriptor->SavedJobMessages = serialize($data->messages);
	}

	/**
	 * @param QueuedJobDescriptor $jobDescriptor
	 * @param QueuedJob $job
	 */
	protected function copyDescriptorToJob($jobDescriptor, $job) {
		$jobData = null;
		$messages = null;
		
		// switching to php's serialize methods... not sure why this wasn't done from the start!
		$jobData = @unserialize($jobDescriptor->SavedJobData);
		$messages = @unserialize($jobDescriptor->SavedJobMessages);
		
		if (!$jobData) {
			// SS's convert:: function doesn't do this detection for us!!
			if (function_exists('json_decode')) {
				$jobData = json_decode($jobDescriptor->SavedJobData);
				$messages = json_decode($jobDescriptor->SavedJobMessages);
			} else {
				$jobData = Convert::json2obj($jobDescriptor->SavedJobData);
				$messages = Convert::json2obj($jobDescriptor->SavedJobMessages);
			}
		}
		
		

		$job->setJobData($jobDescriptor->TotalSteps, $jobDescriptor->StepsProcessed, $jobDescriptor->JobStatus == QueuedJob::STATUS_COMPLETE, $jobData, $messages);
	}

	/**
	 * Check the current job queues and see if any of the jobs currently in there should be started. If so,
	 * return the next job that should be executed
	 *
	 * @return QueuedJobDescriptor
	 */
	public function getNextPendingJob($type=null) {
		$type = $type ? $type : QueuedJob::QUEUED;

		// see if there's any blocked jobs that need to be resumed
		$filter = singleton('QJUtils')->quote(array('JobStatus =' => QueuedJob::STATUS_WAIT, 'JobType =' => $type));
		$existingJob = DataObject::get_one('QueuedJobDescriptor', $filter);
		if ($existingJob && $existingJob->exists()) {
			return $existingJob;
		}

		// lets see if we have a currently running job
		$filter = singleton('QJUtils')->quote(array('JobStatus =' => QueuedJob::STATUS_INIT)) .' OR '. singleton('QJUtils')->quote(array('JobStatus =' => QueuedJob::STATUS_RUN));

		$filter = '('.$filter.') AND '.singleton('QJUtils')->quote(array('JobType =' => $type));

		$existingJob = DataObject::get_one('QueuedJobDescriptor', $filter);

		// if there's an existing job either running or pending, the lets just return false to indicate
		// that we're still executing
		if ($existingJob && $existingJob->exists()) {
			return false;
		}

		// otherwise, lets find any 'new' jobs that are waiting to execute
		$filter = array(
			'JobStatus =' => 'New',
			'JobType =' => $type ? $type : QueuedJob::QUEUED,
		);

		$filter = singleton('QJUtils')->quote($filter) . ' AND ('. singleton('QJUtils')->quote(array('StartAfter <' => date('Y-m-d H:i:s'), 'StartAfter IS' => null), ' OR ').')';

		$jobs = DataObject::get('QueuedJobDescriptor', $filter, 'ID ASC');

		if ($jobs && $jobs->Count()) {
			return $jobs->First();
		}
	}

	/**
	 * Runs an explicit check on all currently running jobs to make sure their "processed" count is incrementing
	 * between each run. If it's not, then we need to flag it as paused due to an error.
	 *
	 * This typically happens when a PHP fatal error is thrown, which can't be picked up by the error
	 * handler or exception checker; in this case, we detect these stalled jobs later and fix (try) to
	 * fix them
	 */
	public function checkJobHealth() {
		// first off, we want to find jobs that haven't changed since they were last checked (assuming they've actually
		// processed a few steps...)
		$filter = singleton('QJUtils')->quote(array('JobStatus =' => QueuedJob::STATUS_RUN, 'StepsProcessed >' => 0));
		$filter = $filter . ' AND "StepsProcessed"="LastProcessedCount"';

		$stalledJobs = DataObject::get('QueuedJobDescriptor', $filter);
		if ($stalledJobs) {
			foreach ($stalledJobs as $stalledJob) {
				if ($stalledJob->ResumeCount <= self::$stall_threshold) {
					$stalledJob->ResumeCount++;
					$stalledJob->pause();
					$stalledJob->resume();
					$msg = sprintf(_t('QueuedJobs.STALLED_JOB_MSG', 'A job named %s appears to have stalled. It will be stopped and restarted, please login to make sure it has continued'), $stalledJob->JobTitle);
				} else {
					$stalledJob->pause();
					$msg = sprintf(_t('QueuedJobs.STALLED_JOB_MSG', 'A job named %s appears to have stalled. It has been paused, please login to check it'), $stalledJob->JobTitle);
				}

				$mail = new Email(Email::getAdminEmail(), Email::getAdminEmail(), _t('QueuedJobs.STALLED_JOB', 'Stalled job'), $msg);
				$mail->send();
			}
		}
		
		// now, find those that need to be marked before the next check
		$filter = singleton('QJUtils')->quote(array('JobStatus =' => QueuedJob::STATUS_RUN));
		$runningJobs = DataObject::get('QueuedJobDescriptor', $filter);
		
		if ($runningJobs) {
			// foreach job, mark it as having been incremented
			foreach ($runningJobs as $job) {
				$job->LastProcessedCount = $job->StepsProcessed;
				$job->write();
			}
		}
	}

	/**
	 * Prepares the given jobDescriptor for execution. Returns the job that
	 * will actually be run in a state ready for executing.
	 *
	 * Note that this is called each time a job is picked up to be executed from the cron
	 * job - meaning that jobs that are paused and restarted will have 'setup()' called on them again,
	 * so your job MUST detect that and act accordingly. 
	 *
	 * @param QueuedJobDescriptor $jobDescriptor
	 *			The Job descriptor of a job to prepare for execution
	 *
	 * @return QueuedJob
	 */
	protected function initialiseJob(QueuedJobDescriptor $jobDescriptor) {
		// create the job class
		$impl = $jobDescriptor->Implementation;
		$job = new $impl;
		/* @var $job QueuedJob */
		if (!$job) {
			throw new Exception("Implementation $impl no longer exists");
		}

		// start the init process
		$jobDescriptor->JobStatus = QueuedJob::STATUS_INIT;
		$jobDescriptor->write();

		// make sure the data is there
		$this->copyDescriptorToJob($jobDescriptor, $job);

		// see if it needs 'setup' or 'restart' called
		if (!$jobDescriptor->StepsProcessed) {
			$job->setup();
		} else {
			$job->prepareForRestart();
		}
		
		// make sure the descriptor is up to date with anything changed
		$this->copyJobToDescriptor($job, $jobDescriptor);

		return $job;
	}

	/**
	 * Start the actual execution of a job
	 *
	 * This method will continue executing until the job says it's completed
	 *
	 * @param int $jobId
	 *			The ID of the job to start executing
	 */
	public function runJob($jobId) {
		// first retrieve the descriptor
		$jobDescriptor = DataObject::get_by_id('QueuedJobDescriptor', (int) $jobId);
		if (!$jobDescriptor) {
			throw new Exception("$jobId is invalid");
		}

		// now lets see whether we have a current user to run as. Typically, if the job is executing via the CLI,
		// we want it to actually execute as the RunAs user - however, if running via the web (which is rare...), we
		// want to ensure that the current user has admin privileges before switching. Otherwise, we just run it
		// as the currently logged in user and hope for the best
		$originalUser = Member::currentUser();
		$runAsUser = null;
		if (Director::is_cli() || !Member::currentUser() || Member::currentUser()->isAdmin()) {
			$runAsUser = $jobDescriptor->RunAs();
			if ($runAsUser && $runAsUser->exists()) {
				// the job runner outputs content way early in the piece, meaning there'll be cooking errors
				// if we try and do a normal login, and we only want it temporarily...
				Session::set("loggedInAs", $runAsUser->ID);
			}
		}

		// set up a custom error handler for this processing
		$errorHandler = new JobErrorHandler();

		$job = null;

		try {
			$job = $this->initialiseJob($jobDescriptor);

			// get the job ready to begin.
			if (!$jobDescriptor->JobStarted) {
				$jobDescriptor->JobStarted = date('Y-m-d H:i:s');
			} else {
				$jobDescriptor->JobRestarted = date('Y-m-d H:i:s');
			}
			
			$jobDescriptor->JobStatus = QueuedJob::STATUS_RUN;
			$jobDescriptor->write();

			$lastStepProcessed = 0;
			// have we stalled at all?
			$stallCount = 0;
			$broken = false;

			// while not finished
			while (!$job->jobFinished() && !$broken) {
				// see that we haven't been set to 'paused' or otherwise by another process
				$jobDescriptor = DataObject::get_by_id('QueuedJobDescriptor', (int) $jobId);
				if ($jobDescriptor->JobStatus != QueuedJob::STATUS_RUN) {
					// we've been paused by something, so we'll just exit
					$job->addMessage(sprintf(_t('QueuedJobs.JOB_PAUSED', "Job paused at %s"), date('Y-m-d H:i:s')));
					$broken = true;
				}

				if (!$broken) {
					try {
						$job->process();
					} catch (Exception $e) {
						// okay, we'll just catch this exception for now
						$job->addMessage(sprintf(_t('QueuedJobs.JOB_EXCEPT', 'Job caused exception %s in %s at line %s'), $e->getMessage(), $e->getFile(), $e->getLine()), 'ERROR');
						SS_Log::log($e, SS_Log::ERR);
						$jobDescriptor->JobStatus =  QueuedJob::STATUS_BROKEN;
					}

					// now check the job state
					$data = $job->getJobData();
					if ($data->currentStep == $lastStepProcessed) {
						$stallCount++;
					}

					if ($stallCount > self::$stall_threshold) {
						$broken = true;
						$job->addMessage(sprintf(_t('QueuedJobs.JOB_STALLED', "Job stalled after %s attempts - please check"), $stallCount), 'ERROR');
						$jobDescriptor->JobStatus =  QueuedJob::STATUS_BROKEN;
					}

					// now we'll be good and check our memory usage. If it is too high, we'll set the job to
					// a 'Waiting' state, and let the next processing run pick up the job.
					if ($this->isMemoryTooHigh()) {
						$job->addMessage(sprintf(_t('QueuedJobs.MEMORY_RELEASE', 'Job releasing memory and waiting (%s used)'), $this->humanReadable(memory_get_usage())));
						$jobDescriptor->JobStatus = QueuedJob::STATUS_WAIT;
						$broken = true;
					}
				}

				$this->copyJobToDescriptor($job, $jobDescriptor);
				$jobDescriptor->write();
			}
			// a last final save
			$jobDescriptor->write();
		} catch (Exception $e) {
			// okay, we'll just catch this exception for now
			SS_Log::log($e, SS_Log::ERR);
			$jobDescriptor->JobStatus =  QueuedJob::STATUS_BROKEN;
			$jobDescriptor->write();
		}

		$errorHandler->clear();

		// okay lets reset our user if we've got an original
		if ($runAsUser && $originalUser) {
			Session::clear("loggedInAs");
			if ($originalUser) {
				Session::set("loggedInAs", $originalUser->ID);
			}
		}
	}

	/**
	 * Is memory usage too high? 
	 */
	protected function isMemoryTooHigh() {
		if (function_exists('memory_get_usage')) {
			$memory = memory_get_usage();
			return memory_get_usage() > self::$memory_limit;
		}
	}

	protected function humanReadable($size) {
		$filesizename = array(" Bytes", " KB", " MB", " GB", " TB", " PB", " EB", " ZB", " YB");
		return $size ? round($size/pow(1024, ($i = floor(log($size, 1024)))), 2) . $filesizename[$i] : '0 Bytes';
	}


	/**
	 * Gets a list of all the current jobs (or jobs that have recently finished)
	 *
	 * @param string $type
	 *			if we're after a particular job list
	 * @param int $includeUpUntil
	 *			The number of seconds to include jobs that have just finished, allowing a job list to be built that
	 *			includes recently finished jobs 
	 */
	public function getJobList($type = null, $includeUpUntil = 0) {
		$jobs = DataObject::get('QueuedJobDescriptor', $this->getJobListFilter($type, $includeUpUntil));
		return $jobs;
	}

	/**
	 * Return the SQL filter used to get the job list - this is used by the UI for displaying the job list...
	 *
	 * @param string $type
	 *			if we're after a particular job list
	 * @param int $includeUpUntil
	 *			The number of seconds to include jobs that have just finished, allowing a job list to be built that
	 *			includes recently finished jobs
	 * @return String
	 */
	public function getJobListFilter($type = null, $includeUpUntil = 0) {
		$filter = array('JobStatus <>' => QueuedJob::STATUS_COMPLETE);
		if ($includeUpUntil) {
			$filter['JobFinished > '] = date('Y-m-d H:i:s', time() - $includeUpUntil);
		}

		$filter = singleton('QJUtils')->quote($filter, ' OR ');

		if ($type) {
			$filter = singleton('QJUtils')->quote(array('JobType =' => $type)) . ' AND ('.$filter.')';
		}

		return $filter;
	}

	/**
	 * When PHP shuts down, we want to process all of the immediate queue items
	 *
	 * We use the 'getNextPendingJob' method, instead of just iterating the queue, to ensure
	 * we ignore paused or stalled jobs. 
	 */
	public function onShutdown() {
		$job = $this->getNextPendingJob(QueuedJob::IMMEDIATE);
		do {
			$job = $this->getNextPendingJob(QueuedJob::IMMEDIATE);
			if ($job) {
				$this->runJob($job->ID);
			}
		} while($job);
	}
}

/**
 * Class used to handle errors for a single job
 */
class JobErrorHandler {
	public function __construct() {
		set_error_handler(array($this, 'handleError'));
	}

	public function clear() {
		restore_error_handler();
	}

	public function handleError($errno, $errstr, $errfile, $errline) {
		if (error_reporting()) {
			// Don't throw E_DEPRECATED in PHP 5.3+
			if (defined('E_DEPRECATED')) {
				if ($errno == E_DEPRECATED || $errno = E_USER_DEPRECATED) {
					return;
				}
			}

			switch ($errno) {
				case E_NOTICE:
				case E_USER_NOTICE:
				case E_STRICT: {
					break;
				}
				default: {
					throw new Exception($errstr . " in $errfile at line $errline", $errno);
					break;
				}
			}
		}
	}
}