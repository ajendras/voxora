<?php

declare(strict_types=1);

namespace Voxora\Automation;

use Voxora\Storage\SqliteRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Scheduler
 *
 * Manages persistent scheduled batch jobs using cron expressions.
 * Jobs are stored in SQLite. runDue() is meant to be called frequently (e.g. * * * * * from system cron).
 * Supports basic 5-field cron expressions. Added runJob(), updateJob() and deleteJob() to support the web UI.
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
        $now = new DateTimeImmutable();

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
        return $expression->isDue();
    }

    private function getNextRunTime(string $cron): string
    {
        $expression = new CronExpression($cron);
        return $expression->getNextRunDate()->format('Y-m-d H:i');
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

/**
 * Lightweight CronExpression parser supporting 5 fields.
 * Very basic implementation for common cases (*, numbers, ranges).
 */
final class CronExpression
{
    private array $parts;

    public function __construct(string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            throw new InvalidArgumentException('Cron expression must have 5 fields');
        }
        $this->parts = $parts;
    }

    public function isDue(?DateTimeImmutable $date = null): bool
    {
        $date = $date ?? new DateTimeImmutable();
        $minute = (int) $date->format('i');
        $hour = (int) $date->format('G');
        $day = (int) $date->format('j');
        $month = (int) $date->format('n');
        $dow = (int) $date->format('w');

        return $this->matches($this->parts[0], $minute, 0, 59)
            && $this->matches($this->parts[1], $hour, 0, 23)
            && $this->matches($this->parts[2], $day, 1, 31)
            && $this->matches($this->parts[3], $month, 1, 12)
            && $this->matches($this->parts[4], $dow, 0, 6);
    }

    public function getNextRunDate(?DateTimeImmutable $from = null): DateTimeImmutable
    {
        $from = $from ?? new DateTimeImmutable();
        $next = $from->modify('+1 minute');

        for ($i = 0; $i < 1440; $i++) {
            if ($this->isDue($next)) {
                return $next;
            }
            $next = $next->modify('+1 minute');
        }

        return $from->modify('+1 day');
    }

    private function matches(string $field, int $value, int $min, int $max): bool
    {
        if ($field === '*') {
            return true;
        }

        if (is_numeric($field)) {
            return (int)$field === $value;
        }

        if (str_contains($field, '*/')) {
            $step = (int) substr($field, 2);
            return $value % $step === 0;
        }

        if (str_contains($field, '-')) {
            [$start, $end] = array_map('intval', explode('-', $field));
            return $value >= $start && $value <= $end;
        }

        if (str_contains($field, ',')) {
            $list = array_map('intval', explode(',', $field));
            return in_array($value, $list, true);
        }

        return false;
    }
}
