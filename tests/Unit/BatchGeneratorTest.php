<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Automation\BatchGenerator;
use Voxora\Automation\Report;
use Voxora\TTS\Manager;
use Voxora\TTS\SynthesisResult;
use Voxora\TTS\WebhookDispatcher;
use InvalidArgumentException;
use RuntimeException;

final class BatchGeneratorTest extends TestCase
{
    private string $tempDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/voxora_batch_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
        $this->outputDir = $this->tempDir . '/output';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->rmdirRecursive($this->tempDir);
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->rmdirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testConstructorCreatesOutputDirectory(): void
    {
        $manager = $this->createMock(Manager::class);
        new BatchGenerator($manager, $this->outputDir);

        $this->assertDirectoryExists($this->outputDir);
    }

    public function testConstructorThrowsOnEmptyOutputDir(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Output directory cannot be empty.');

        new BatchGenerator($this->createMock(Manager::class), '');
    }

    public function testRunWithValidCsvReturnsReportAndCallsProgressAndWebhook(): void
    {
        $manager = $this->createMock(Manager::class);
        $webhook = $this->createMock(WebhookDispatcher::class);
        $generator = new BatchGenerator($manager, $this->outputDir, $webhook);

        $csvContent = "text,voice_id,provider,filename\n" .
            "Hello from Voxora,voice123,openai,test_hello\n" .
            "Second example,voice456,,test_two\n";
        $csvPath = $this->tempDir . '/test.csv';
        file_put_contents($csvPath, $csvContent);

        $result1 = new SynthesisResult('fake-audio-1', 'mp3', 1.5);
        $result2 = new SynthesisResult('fake-audio-2', 'mp3', 2.0);

        $manager->expects($this->exactly(2))
            ->method('generate')
            ->willReturnOnConsecutiveCalls($result1, $result2);

        $webhook->expects($this->exactly(2))
            ->method('dispatchSuccess');

        $progressCalls = [];
        $progress = function (int $current, int $total, string $msg, ?string $file = null) use (&$progressCalls): void {
            $progressCalls[] = [
                'current' => $current,
                'total' => $total,
                'msg' => $msg,
                'file' => $file,
            ];
        };

        $report = $generator->run($csvPath, $progress);

        $this->assertInstanceOf(Report::class, $report);
        $this->assertSame(2, $report->total);
        $this->assertSame(2, $report->successful);
        $this->assertSame(0, $report->failed);
        $this->assertCount(2, $report->files);
        $this->assertGreaterThan(0.0, $report->totalDuration);
        $this->assertGreaterThan(0.0, $report->processingTime);

        // Progress callback was invoked for every row + final summary
        $this->assertGreaterThanOrEqual(5, count($progressCalls));

        foreach ($report->files as $file) {
            $this->assertFileExists($file);
            $this->assertStringContainsString('test_', basename($file));
        }

        // Cleanup handled by tearDown
    }

    public function testRunHandlesGenerationFailures(): void
    {
        $manager = $this->createMock(Manager::class);
        $generator = new BatchGenerator($manager, $this->outputDir);

        $csvContent = "text,voice_id\nHello,voice123";
        $csvPath = $this->tempDir . '/fail.csv';
        file_put_contents($csvPath, $csvContent);

        $manager->method('generate')
            ->willThrowException(new RuntimeException('Provider unavailable'));

        $report = $generator->run($csvPath);

        $this->assertSame(1, $report->total);
        $this->assertSame(0, $report->successful);
        $this->assertSame(1, $report->failed);
        $this->assertCount(0, $report->files);
    }

    public function testRunThrowsOnMissingCsv(): void
    {
        $generator = new BatchGenerator($this->createMock(Manager::class), $this->outputDir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV file not found or not readable');

        $generator->run('/non/existent/file.csv');
    }

    public function testRunThrowsOnEmptyCsv(): void
    {
        $generator = new BatchGenerator($this->createMock(Manager::class), $this->outputDir);

        $csvPath = $this->tempDir . '/empty.csv';
        file_put_contents($csvPath, "text,voice_id\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV file contains no data rows.');

        $generator->run($csvPath);
    }

    public function testParseCsvSupportsAlternativeColumnNames(): void
    {
        $manager = $this->createMock(Manager::class);
        $generator = new BatchGenerator($manager, $this->outputDir);

        $csvContent = "text,voice,filename,format\nHello world,voiceXYZ,testfile,mp3";
        $csvPath = $this->tempDir . '/alt.csv';
        file_put_contents($csvPath, $csvContent);

        $result = new SynthesisResult('fake-audio', 'mp3', 3.0);
        $manager->method('generate')->willReturn($result);

        $report = $generator->run($csvPath);

        $this->assertSame(1, $report->successful);
        $this->assertCount(1, $report->files);
        $this->assertStringContainsString('testfile.mp3', $report->files[0]);

        unlink($report->files[0]);
        unlink($csvPath);
    }
}
