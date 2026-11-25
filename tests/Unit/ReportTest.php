<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Automation\Report;

final class ReportTest extends TestCase
{
    public function testPropertiesAreReadable(): void
    {
        $report = new Report(
            total: 5,
            successful: 4,
            failed: 1,
            files: ['/tmp/output1.mp3'],
            totalDuration: 12.5,
            processingTime: 3.2
        );

        $this->assertSame(5, $report->total);
        $this->assertSame(4, $report->successful);
        $this->assertSame(1, $report->failed);
        $this->assertSame(['/tmp/output1.mp3'], $report->files);
        $this->assertSame(12.5, $report->totalDuration);
        $this->assertSame(3.2, $report->processingTime);
    }

    public function testGetSummaryFormatsCorrectly(): void
    {
        $report = new Report(10, 9, 1, ['file1.mp3', 'file2.mp3'], 25.75, 8.5);
        $summary = $report->getSummary();

        $this->assertStringStartsWith('Batch completed in 8.50s.', $summary);
        $this->assertStringContainsString('9/10 successful (90.0%)', $summary);
        $this->assertStringContainsString('2 files written (25.8s total audio)', $summary);
    }

    public function testToArrayReturnsCompleteData(): void
    {
        $files = ['/path/to/file1.mp3'];
        $report = new Report(3, 2, 1, $files, 8.0, 1.5);
        $data = $report->toArray();

        $expectedKeys = [
            'total',
            'successful',
            'failed',
            'files_generated',
            'files',
            'total_audio_seconds',
            'processing_seconds',
            'summary',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        $this->assertSame(3, $data['total']);
        $this->assertSame(2, $data['successful']);
        $this->assertSame(1, $data['failed']);
        $this->assertSame(1, $data['files_generated']);
        $this->assertSame($files, $data['files']);
        $this->assertSame(8.0, $data['total_audio_seconds']);
        $this->assertSame(1.5, $data['processing_seconds']);
        $this->assertIsString($data['summary']);
    }

    public function testZeroTotalAvoidsDivisionByZero(): void
    {
        $report = new Report(0, 0, 0, [], 0.0, 0.0);
        $summary = $report->getSummary();

        $this->assertStringContainsString('0/0 successful (0.0%)', $summary);
    }
}
