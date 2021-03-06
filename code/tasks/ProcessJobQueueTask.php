<?php

/**
 * Task used to process the job queue
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/bsd-license/
 */
class ProcessJobQueueTask extends BuildTask {

	protected $description = 'Used via a cronjob to execute queued jobs that need running';

    public function run($request) {
		$service = singleton('QueuedJobService');
		/* @var $service QueuedJobService */

		$datestamp = '['.date('Y-m-d H:i:s').']';
		$queue = $request->getVar('queue');
		if (!$queue) {
			$queue = 'Queued';
		}

		switch (strtolower($queue)) {
			case 'immediate': {
				$queue = 1;
				break;
			}
			case 'large': {
				$queue = 3;
				break;
			}
			default: {
				if (!is_numeric($queue)) {
					$queue = 2;
				}
			}
		}

		echo "$datestamp Processing queue $queue\n";

		if ($request->getVar('list')) {
			for ($i = 1; $i  <= 3; $i++) {
				$jobs = $service->getJobList($i);
				$num = $jobs ? $jobs->Count() : 0;
				echo "$datestamp Found $num jobs for mode $i\n";
			}

			return;
		}

		/* @var $service QueuedJobService */
		$nextJob = $service->getNextPendingJob($queue);

		$service->checkJobHealth();

		if ($nextJob) {
			echo "$datestamp Running $nextJob->JobTitle \n";
			$service->runJob($nextJob->ID);
		}

		if (is_null($nextJob)) {
			echo "$datestamp No new jobs\n";
		}
		if ($nextJob === false) {
			echo "$datestamp Job is still running\n";
		}

	}
}