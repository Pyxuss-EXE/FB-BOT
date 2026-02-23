<?php
namespace FBBot;

use RuntimeException;

class JobManager
{
    private $storage;
    public function __construct() { $this->storage = new Storage('jobs.json'); }

    public function createJob($userId, array $numbers)
    {
        $this->storage->acquireLock();
        $jobId = uniqid('job_', true);
        $job = [
            'id' => $jobId,
            'user_id' => $userId,
            'status' => 'queued',
            'total' => count($numbers),
            'processed' => 0,
            'valid' => 0,
            'invalid' => 0,
            'multi_account' => 0,
            'errors' => 0,
            'numbers' => $numbers,
            'results' => [],
            'created_at' => time(),
            'updated_at' => time()
        ];
        $jobs = $this->storage->all();
        $jobs[$jobId] = $job;
        $this->storage->set('jobs', $jobs);
        $this->storage->save();
        $this->storage->releaseLock();
        return $jobId;
    }

    public function getJob($jobId)
    {
        $jobs = $this->storage->get('jobs', []);
        return $jobs[$jobId] ?? null;
    }

    public function updateJob($jobId, array $data)
    {
        $this->storage->acquireLock();
        $jobs = $this->storage->get('jobs', []);
        if (!isset($jobs[$jobId])) { $this->storage->releaseLock(); throw new RuntimeException("Job not found"); }
        $jobs[$jobId] = array_merge($jobs[$jobId], $data);
        $jobs[$jobId]['updated_at'] = time();
        $this->storage->set('jobs', $jobs);
        $this->storage->save();
        $this->storage->releaseLock();
    }

    public function getPendingJobs()
    {
        $jobs = $this->storage->get('jobs', []);
        return array_filter($jobs, function($j) { return in_array($j['status'], ['queued', 'processing']); });
    }

    public function addResult($jobId, $number, $result)
    {
        $this->storage->acquireLock();
        $jobs = $this->storage->get('jobs', []);
        if (!isset($jobs[$jobId])) { $this->storage->releaseLock(); return; }
        $jobs[$jobId]['results'][$number] = $result;
        $jobs[$jobId]['processed']++;
        switch ($result['status']) {
            case 'valid': $jobs[$jobId]['valid']++; break;
            case 'invalid': $jobs[$jobId]['invalid']++; break;
            case 'multi': $jobs[$jobId]['multi_account']++; break;
            case 'error': $jobs[$jobId]['errors']++; break;
        }
        $jobs[$jobId]['updated_at'] = time();
        $this->storage->set('jobs', $jobs);
        $this->storage->save();
        $this->storage->releaseLock();
    }

    public function completeJob($jobId)
    {
        $this->updateJob($jobId, ['status' => 'completed']);
        $job = $this->getJob($jobId);
        file_put_contents(storage_path("results/job_{$jobId}.json"), json_encode($job['results'], JSON_PRETTY_PRINT));
    }
}