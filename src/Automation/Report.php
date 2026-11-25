<?php

declare(strict_types=1);

namespace Voxora\Automation;

/**
 * Report
 *
 * Immutable summary of a completed batch run.
 *
 * Lives in its own file so Composer's PSR-4 autoloader can resolve
 * Voxora\Automation\Report without BatchGenerator having been loaded first.
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
            'Batch completed in %.2fs. %d/%d successful (%.1f%%). %d files written (%.1fs total audio).',
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
