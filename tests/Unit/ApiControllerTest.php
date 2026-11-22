<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Http\ApiController;
use Voxora\Http\Request;
use Voxora\Security\KeyManager;
use Voxora\Security\Token;
use Voxora\TTS\Manager;
use Voxora\Storage\SqliteRepository;
use Voxora\Security\RateLimiter;
use Voxora\TTS\WebhookDispatcher;
use RuntimeException;

class ApiControllerTest extends TestCase
{
    private ApiController $controller;
    private KeyManager $keyManager;
    private Manager $ttsManager;
    private SqliteRepository $repository;
    private RateLimiter $rateLimiter;
    private WebhookDispatcher $webhookDispatcher;

    protected function setUp(): void
    {
        $this->keyManager = $this->createMock(KeyManager::class);
        $this->ttsManager = $this->createMock(Manager::class);
        $this->repository = $this->createMock(SqliteRepository::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);
        $this->webhookDispatcher = $this->createMock(WebhookDispatcher::class);

        $config = [
            'audio' => [
                'directory' => '/tmp',
                'public_url_prefix' => '/audio',
            ],
        ];

        $this->controller = new ApiController(
            $this->keyManager,
            $this->ttsManager,
            $this->repository,
            $config,
            $this->webhookDispatcher,
            $this->rateLimiter
        );
    }

    public function testIssueTokenActionRejectsMissingName(): void
    {
        $request = new Request(post: []);

        $response = $this->controller->issueTokenAction($request);

        $this->assertArrayHasKey('error', $response);
        $this->assertStringContainsString('name', strtolower($response['error']));
        $this->assertEquals(400, $response['status'] ?? 400);
    }

    public function testSpeechActionRejectsInvalidToken(): void
    {
        $request = new Request(
            post: ['text' => 'hello', 'voice_id' => 'voice123'],
            headers: ['Authorization' => 'Bearer invalid']
        );

        $this->keyManager->method('validate')
            ->willThrowException(new RuntimeException('Invalid token'));

        $this->rateLimiter->method('isAllowed')->willReturn(true);

        $response = $this->controller->speechAction($request);

        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(401, $response['status'] ?? 0);
    }

    public function testSpeechActionEnforcesRateLimit(): void
    {
        $request = new Request(
            post: ['text' => 'test', 'voice_id' => 'v1'],
            headers: ['X-Api-Key' => 'testkey']
        );

        $this->rateLimiter->method('isAllowed')->willReturn(false);
        $this->rateLimiter->method('getRemaining')->willReturn(0);

        $response = $this->controller->speechAction($request);

        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(429, $response['status'] ?? 0);
        $this->assertArrayHasKey('limit', $response);
    }

    public function testSpeechActionRejectsMissingText(): void
    {
        $request = new Request(
            post: ['voice_id' => 'v1'],
            headers: ['Authorization' => 'Bearer valid']
        );

        $tokenMock = $this->createMock(Token::class);
        $tokenMock->method('hasScope')->willReturn(true);
        $this->keyManager->method('validate')->willReturn($tokenMock);
        $this->rateLimiter->method('isAllowed')->willReturn(true);

        $response = $this->controller->speechAction($request);

        $this->assertArrayHasKey('error', $response);
        $this->assertStringContainsString('text', strtolower($response['error']));
    }

    public function testSpeechActionSuccessPath(): void
    {
        $request = new Request(
            post: [
                'text' => 'Hello world',
                'voice_id' => '21m00Tcm4TlvDq8ikWAM',
                'normalize' => '1',
            ],
            headers: ['X-Api-Key' => 'validkey']
        );

        $tokenMock = $this->createMock(Token::class);
        $tokenMock->method('hasScope')->willReturn(true);
        $tokenMock->method('getUserId')->willReturn(1);

        $this->keyManager->method('validate')->willReturn($tokenMock);
        $this->rateLimiter->method('isAllowed')->willReturn(true);

        $resultMock = $this->createMock(\Voxora\TTS\SynthesisResult::class);
        $resultMock->method('getDuration')->willReturn(2.5);
        $resultMock->method('toDataUri')->willReturn('data:audio/mp3;base64,xxx');

        $this->ttsManager->method('generate')->willReturn($resultMock);
        $this->repository->expects($this->once())->method('recordGeneration');

        $response = $this->controller->speechAction($request);

        $this->assertArrayHasKey('audio', $response);
        $this->assertArrayHasKey('duration', $response);
        $this->assertEquals(2.5, $response['duration']);
    }

    public function testHistoryActionReturnsScopedResults(): void
    {
        $request = new Request(
            query: ['page' => '1', 'per_page' => '10'],
            headers: ['X-Api-Key' => 'key']
        );

        $tokenMock = $this->createMock(Token::class);
        $tokenMock->method('getUserId')->willReturn(1);
        $this->keyManager->method('validate')->willReturn($tokenMock);
        $this->rateLimiter->method('isAllowed')->willReturn(true);

        $this->repository->method('getHistory')->willReturn([
            'items' => [],
            'pagination' => [
                'current_page' => 1,
                'total_pages' => 1,
                'total_items' => 0,
                'per_page' => 10,
                'has_next' => false,
                'has_prev' => false,
            ],
            'filters' => [],
        ]);

        $response = $this->controller->historyAction($request);

        $this->assertArrayHasKey('items', $response);
        $this->assertArrayHasKey('pagination', $response);
    }

    public function testExportLibraryActionReturnsBundleWithDownloadHeader(): void
    {
        $request = new Request(
            headers: ['X-Api-Key' => 'key']
        );

        $tokenMock = $this->createMock(Token::class);
        $this->keyManager->method('validate')->willReturn($tokenMock);
        $this->rateLimiter->method('isAllowed')->willReturn(true);

        $this->repository->method('exportLibrary')->willReturn([
            'version' => '1.0',
            'voices' => [],
        ]);

        $response = $this->controller->exportLibraryAction($request);

        $this->assertArrayHasKey('version', $response);
        $this->assertArrayHasKey('voices', $response);
    }

    public function testCloneVoiceActionRejectsInvalidToken(): void
    {
        $request = new Request(
            post: ['name' => 'Test Voice'],
            headers: []
        );

        $this->keyManager->method('validate')
            ->willThrowException(new RuntimeException('Invalid token'));

        $response = $this->controller->cloneVoiceAction($request);

        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(401, $response['status'] ?? 0);
    }

    public function testCloneVoiceActionSuccess(): void
    {
        $request = new Request(
            post: ['name' => 'Cloned Voice'],
            files: ['sample' => ['tmp_name' => '/tmp/sample.wav', 'name' => 'sample.wav', 'error' => 0]],
            headers: ['X-Api-Key' => 'valid']
        );

        $tokenMock = $this->createMock(Token::class);
        $this->keyManager->method('validate')->willReturn($tokenMock);
        $this->rateLimiter->method('isAllowed')->willReturn(true);

        $this->ttsManager->method('cloneVoice')->willReturn('new-voice-id-123');
        $this->repository->expects($this->once())->method('saveCustomVoice');

        $response = $this->controller->cloneVoiceAction($request);

        $this->assertArrayHasKey('voice_id', $response);
        $this->assertEquals('new-voice-id-123', $response['voice_id']);
    }

    public function testListVoicesAction(): void
    {
        $request = new Request(
            query: ['provider' => 'openai'],
            headers: ['X-Api-Key' => 'valid']
        );

        $this->ttsManager->method('listVoices')
            ->with('openai')
            ->willReturn([
                ['id' => 'alloy', 'name' => 'Alloy'],
                ['id' => 'echo', 'name' => 'Echo'],
            ]);

        $response = $this->controller->listVoicesAction($request);

        $this->assertIsArray($response);
        $this->assertCount(2, $response);
    }
}
