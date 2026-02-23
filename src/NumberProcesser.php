<?php
namespace FBBot;

class NumberProcessor
{
    private $checker;
    private $logger;
    private $jobManager;

    public function __construct(FacebookCheckerInterface $checker)
    {
        $this->checker = $checker;
        $this->logger = Logger::getInstance();
        $this->jobManager = new JobManager();
    }

    public function processJob($jobId)
    {
        $job = $this->jobManager->getJob($jobId);
        if (!$job) { $this->logger->error("Job $jobId not found"); return; }

        $this->jobManager->updateJob($jobId, ['status' => 'processing']);
        $this->logger->info("Started processing job $jobId");

        $sleepBetween = getenv('SLEEP_BETWEEN_NUMBERS') ?: 2;

        foreach ($job['numbers'] as $number) {
            if (isset($job['results'][$number])) continue;

            $this->logger->debug("Checking number: $number");
            try {
                $result = $this->checker->checkNumber($number);
                $this->jobManager->addResult($jobId, $number, $result);
                $this->logger->info("Number $number -> {$result['status']}");
            } catch (\Exception $e) {
                $this->logger->error("Error checking $number: " . $e->getMessage());
                $this->jobManager->addResult($jobId, $number, [
                    'status' => 'error',
                    'message' => 'Exception: ' . $e->getMessage()
                ]);
            }
            sleep($sleepBetween);
        }

        $this->jobManager->completeJob($jobId);
        $this->logger->info("Completed job $jobId");
    }
}