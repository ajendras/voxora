<?php

declare(strict_types=1);

namespace Voxora\Automation;

use Voxora\TTS\Manager;
use RuntimeException;
use InvalidArgumentException;
use DateTimeImmutable;

/**
 * BatchGenerator
 *
 * Processes CSV files containing synthesis instructions, calls the TTS Manager
 * for each row, writes MP3 files to an output directory and returns a Report.
 * Supports a progress callback for CLI or UI feedback.
 */
final class BatchGenerator
{
    private Manager $manager;
    private string $outputDir;

    /**
     * @param Manager $manager TTS manager used for each generation
     * @param string $outputDir Directory where generated MP3s will be written
     */
    public function __construct(Manager $manager, string $outputDir = 'output')
    {
        $this->manager = $manager;

        if ($outputDir === '') {
            throw new InvalidArgumentException('Output directory cannot be empty.');
        }

        $this->outputDir = $outputDir;

        if (!is_dir($this->outputDir)) {
            if (!mkdir($this->outputDir, 0755, true) && !is_dir($this->outputDir)) {
                throw new RuntimeException('Failed to create output directory: ' . $this->outputDir);
            }
        }
    }

    /**
     * Execute batch from a CSV file.
     *
     * CSV columns (case-insensitive): text, voice_id (or voice), provider, filename (optional)
     *
     * @param string $csvPath Absolute or relative path to CSV
     * @param callable|null $progress Optional callback: fn(int $current, int $total, string $message, ?string $filePath): void
     * @return Report Summary of the batch operation
     */
    public function run(string $csvPath, ?callable $progress = null): Report
    {
        if (!file_exists($csvPath) || !is_readable($csvPath)) {
            throw new InvalidArgumentException("CSV file not found or not readable: {$csvPath}");
        }

        $rows = $this->parseCsv($csvPath);
        $total = count($rows);
        if ($total === 0) {
            throw new InvalidArgumentException('CSV file contains no data rows.');
        }

        $successful = 0;
        $failed = 0;
        $generatedFiles = [];
        $totalDuration = 0.0;
        $startTime = microtime(true);

        foreach ($rows as $index => $row) {
            $current = $index + 1;
            $text = trim($row['text'] ?? '');
            $voiceId = trim($row['voice_id'] ?? $row['voice'] ?? '');
            $provider = $row['provider'] ?? null;
            $suggestedName = trim($row['filename'] ?? '');

            $status = sprintf('(%d/%d) %s', $current, $total, substr($text, 0, 45) . (strlen($text) > 45 ? '...' : ''));

            if ($progress !== null) {
                $progress($current, $total, $status, null);
            }

            if ($text === '' || $voiceId === '') {
                $failed++;
                if ($progress !== null) {
                    $progress($current, $total, 'Skipped: missing text or voice_id', null);
                }
                continue;
            }

            try {
                $result = $this->manager->generate($text, $voiceId, $provider);

                $filename = $suggestedName !== ''
                    ? preg_replace('/[^A-Za-z0-9_.-]/', '_', $suggestedName) . '.mp3'
                    : 'batch_' . str_pad((string) $current, 4, '0', STR_PAD_LEFT) . '.mp3';

                $filepath = rtrim($this->outputDir, '/\\') . '/' . $filename;
                $result->save($filepath);

                $generatedFiles[] = $filepath;
                $successful++;
                $totalDuration += (float) ($result->duration ?? 3.0);

                if ($progress !== null) {
                    $progress($current, $total, "✓ Saved {$filename} (" . round((float) $result->duration, 1) . 's)', $filepath);
                }
            } catch (\Throwable $e) {
                $failed++;
                if ($progress !== null) {
                    $progress($current, $total, '✗ ' . $e->getMessage(), null);
                }
            }
        }

        $durationSeconds = microtime(true) - $startTime;

        $report = new Report(
            total: $total,
            successful: $successful,
            failed: $failed,
            files: $generatedFiles,
            totalDuration: $totalDuration,
            processingTime: $durationSeconds
        );

        if ($progress !== null) {
            $progress($total, $total, $report->getSummary(), null);
        }

        return $report;
    }

    /**
     * Parse CSV into associative rows using first row as headers.
     *
     * @return array<int, array<string, string>>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV file for reading.');
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            return [];
        }

        $headers = array_map(static fn(string $h): string => strtolower(trim($h)), $headers);
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) === 0 || (count($data) === 1 && trim($data[0]) === '')) {
                continue;
            }

            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = $data[$i] ?? '';
            }
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }
}

/**
 * Report
 *
 * Immutable summary of a completed batch run.
 */
final class Report
{
    /**
     * @param int $total Total rows in input
     * @param int $successful Successful generations
     * @param int $failed Failed generations
     * @param string[] $files Absolute paths of written MP3 files
     * @param float $totalDuration Sum of audio durations
     * @param float $processingTime Wall-clock time for the batch
     */
    public function __construct(
        public readonly int $total,
        public readonly int $successful,
        public readonly int $failed,
        public readonly array $files,
        public readonly float $totalDuration,
        public readonly float $processingTime = 0.0
    ) {
    }

    public function getSummary(): string
    {
        $successRate = $this->total > 0 ? round(($this->successful / $this->total) * 100, 1) : 0.0;

        return sprintf(
            'Batch completed in %.2fs. %d/%d successful (%.1f%%). %d MP3 files written (%.1fs total audio).',
            $this->processingTime,
            $this->successful,
            $this->total,
            $successRate,
            count($this->files),
            $this->totalDuration
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'successful' => $this->successful,
            'failed' => $this->failed,
            'files_generated' => count($this->files),
            'files' => $this->files,
            'total_audio_seconds' => round($this->totalDuration, 2),
            'processing_seconds' => round($this->processingTime, 2),
            'summary' => $this->getSummary(),
        ];
    }
}
