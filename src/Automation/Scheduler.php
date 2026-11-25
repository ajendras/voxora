<?php

declare(strict_types=1);

namespace Voxora\Automation;

use Voxora\Storage\SqliteRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Cron\CronExpression;

/**
 * Scheduler
 *
 * Manages persistent scheduled batch jobs using the dragonmantank/cron-expression
 * library for full cron specification compliance (including ranges, steps, lists,
 * named values, and special strings like @daily). Jobs are stored in SQLite.
 * runDue() is meant to be called frequently (e.g. * * * * * from system cron).
 * The last-run timestamp prevents duplicate execution in the same minute.
 */
final class Scheduler
{
    private SqliteRepository $repository;
    private BatchGenerator $batchGenerator;
    private string $logPath;

    public function __construct(
        SqliteRepository $repository,
        BatchGenerator $batchGenerator,
        string $logPath = 'data/scheduler.log'
    ) {
        $this->repository = $repository;
        $this->batchGenerator = $batchGenerator;
        $this->logPath = $logPath;

        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function addJob(
        string $name,
        string $cronExpression,
        string $csvPath,
        array $defaultOptions = []
    ): int {
        if (!file_exists($csvPath)) {
            throw new InvalidArgumentException("CSV path does not exist: $csvPath");
        }

        $optionsJson = json_encode($defaultOptions, JSON_THROW_ON_ERROR);

        return $this->repository->saveScheduledJob([
            'name' => $name,
            'cron_expression' => $cronExpression,
            'csv_path' => $csvPath,
            'default_options' => $optionsJson,
        ]);
    }

    public function listJobs(): array
    {
        $jobs = $this->repository->listScheduledJobs();

        foreach ($jobs as &$job) {
            $job['next_run'] = $this->getNextRunTime($job['cron_expression'] ?? '* * * * *');
            $job['status'] = $this->determineStatus($job);
        }
        unset($job);

        return $jobs;
    }

    public function updateJob(int $id, array $data): bool
    {
        return $this->repository->updateScheduledJob($id, $data);
    }

    public function deleteJob(int $id): bool
    {
        return $this->repository->deleteScheduledJob($id);
    }

    public function runJob(int $id, ?callable $progress = null): ?Report
    {
        $job = $this->repository->getScheduledJob($id);

        if (!$job) {
            return null;
        }

        $options = $job['default_options'] ?? [];
        if (is_string($options)) {
            $options = json_decode($options, true) ?? [];
        }
        $options['scheduled'] = true;

        $this->log("Running job #{$id} '{$job['name']}'");

        try {
            $report = $this->batchGenerator->run($job['csv_path'], $progress);

            $this->repository->updateJobLastRun($id, 'success', $report->getSummary());

            $this->log("Job #{$id} completed successfully: " . $report->getSummary());

            return $report;
        } catch (\Throwable $e) {
            $this->repository->updateJobLastRun($id, 'failed', $e->getMessage());

            $this->log("Job #{$id} failed: " . $e->getMessage());

            return null;
        }
    }

    public function runDue(?callable $progress = null): array
    {
        $jobs = $this->repository->listScheduledJobs();
        $results = [];

        foreach ($jobs as $job) {
            if ($this->isDue($job['cron_expression'] ?? '* * * * *', $job['last_run_at'] ?? null)) {
                $report = $this->runJob((int) $job['id'], $progress);
                if ($report !== null) {
                    $results[] = [
                        'job_id' => $job['id'],
                        'name' => $job['name'],
                        'report' => $report->toArray(),
                    ];
                }
            }
        }

        return $results;
    }

    private function isDue(string $cron, ?string $lastRun): bool
    {
        $expression = new CronExpression($cron);
        $now = new DateTimeImmutable();

        if ($lastRun !== null) {
            try {
                $last = new DateTimeImmutable($lastRun);
                // prevent duplicate execution in the same minute using last-run timestamp
                if ($last->format('Y-m-d H:i') === $now->format('Y-m-d H:i')) {
                    return false;
                }
            } catch (\Exception $e) {
                // invalid last-run timestamp; fall back to normal due check
            }
        }

        return $expression->isDue($now);
    }

    private function getNextRunTime(string $cron): string
    {
        $expression = new CronExpression($cron);
        $next = $expression->getNextRunDate(new DateTimeImmutable());
        return $next->format('Y-m-d H:i');
    }

    private function determineStatus(array $job): string
    {
        if (empty($job['last_run_at'])) {
            return 'pending';
        }

        return $job['last_status'] ?? 'unknown';
    }

    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents(
            $this->logPath,
            "[$timestamp] $message\n",
            FILE_APPEND
        );
    }
}
