#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

use FBBot\JobManager;
use FBBot\NumberProcessor;
use FBBot\Logger;
use FBBot\RealFacebookChecker;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

date_default_timezone_set('UTC');

$logger = Logger::getInstance();
$logger->info("Worker started");

try {
    $jobManager = new JobManager();
    $checker = new RealFacebookChecker();
    $processor = new NumberProcessor($checker);
    
    while (true) {
        try {
            $pendingJobs = $jobManager->getPendingJobs();
            foreach ($pendingJobs as $jobId => $job) {
                if ($job['status'] === 'queued') {
                    $logger->info("Processing job $jobId");
                    $processor->processJob($jobId);
                }
            }
            sleep(5);
        } catch (\Exception $e) {
            $logger->error("Worker loop error: " . $e->getMessage());
            sleep(10);
        }
    }
} catch (\Exception $e) {
    $logger->error("Worker fatal error: " . $e->getMessage());
    exit(1);
}