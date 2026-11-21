<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Http\ApiController;
use Voxora\Http\Request;
use Voxora\Http\Response;
use Voxora\Security\KeyManager;
use Voxora\Security\Token;
use Voxora\TTS\Manager;
use Voxora\Storage\SqliteRepository;
use Voxora\Security\RateLimiter;
use Voxora\TTS\WebhookDispatcher;
use PHPUnit\Framework\MockObject\MockObject;

class ApiControllerTest extends TestCase
{
    private ApiController $controller;
    private KeyManager&MockObject $keyManager;
    private Manager&MockObject $ttsManager;
    private SqliteRepository&MockObject $repository;
    private RateLimiter&MockObject $rateLimiter;
    private WebhookDispatcher&MockObject $webhookDispatcher;
    private Request&MockObject $request;

    protected function setUp(): void
    {
        $this->keyManager = $this->createMock(KeyManager::class);
        $this->ttsManager = $this->createMock(Manager::class);
        $this->repository = $this->createMock(SqliteRepository::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);
        $this->webhookDispatcher = $this->createMock(WebhookDispatcher::class);
        $this->request = $this->createMock(Request::class);

        $config = [
            'audio' => [
                'directory' => '/tmp/audio',
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
        $this->request->method('getPost')->willReturn('');
        $this->rateLimiter->method('isAllowed')->willReturn(true);

        $response = $this->controller->issueTokenAction($this->request);

        $this->assertSame(400, $response->getStatus());
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testSpeechActionRejectsInvalidToken(): void
    {
        $this->keyManager->method('validate')->willReturn(null);
        $this->request->method('getHeader')->willReturn('Bearer invalid');

        $response = $this->controller->speechAction($this->request);

        $this->assertSame(401, $response->getStatus());
    }

    public function testSpeechActionEnforcesRateLimit(): void
    {
        $token = new Token('id1', 'test', ['tts:generate'], 'user', 1, time() + 3600);
        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('isAllowed')->willReturn(false);
        $this->rateLimiter->method('getLimit')->willReturn(100);
        $this->rateLimiter->method('getWindow')->willReturn(60);
        $this->rateLimiter->method('getRemaining')->willReturn(0);

        $response = $this->controller->speechAction($this->request);

        $this->assertSame(429, $response->getStatus());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('rate_limit_exceeded', $data['code']);
    }

    public function testSpeechActionRejectsMissingText(): void
    {
        $token = new Token('id1', 'test', ['tts:generate'], 'user', 1, time() + 3600);
        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->request->method('getPost')->willReturnMap([
            ['text', '', ''],
            ['voice_id', '', 'valid-voice'],
        ]);

        $response = $this->controller->speechAction($this->request);

        $this->assertSame(400, $response->getStatus());
    }

    public function testSpeechActionSuccessPath(): void
    {
        $token = new Token('id1', 'test', ['tts:generate'], 'user', 1, time() + 3600);
        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->method('getRemaining')->willReturn(99);

        $this->request->method('getPost')->willReturnMap([
            ['text', '', 'Hello world'],
            ['voice_id', '', 'voice-123'],
            ['provider', '', 'elevenlabs'],
            ['normalize', 'true', 'true'],
            ['format', 'mp3', 'mp3'],
        ]);
        $this->request->method('getHeader')->willReturn('Bearer vxo_abc123');

        $resultMock = $this->createMock(\Voxora\TTS\SynthesisResult::class);
        $resultMock->method('save')->willReturn(true);
        $resultMock->format = 'mp3';
        $resultMock->duration = 5.2;
        $resultMock->metadata = ['normalized' => true];
        $resultMock->audioContent = 'fake-audio';
        $resultMock->method('toDataUri')->willReturn('data:audio/mp3;base64,ZmFrZQ==');

        $this->ttsManager->method('generate')->willReturn($resultMock);
        $this->repository->method('saveGeneration')->willReturn(1);
        $this->webhookDispatcher->method('dispatchSuccess');

        $response = $this->controller->speechAction($this->request);

        $this->assertSame(200, $response->getStatus());
        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('audio_url', $data);
    }

    public function testHistoryActionReturnsScopedResults(): void
    {
        $token = new Token('id1', 'test', ['tts:generate'], 'user', 1, time() + 3600);
        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->method('getRemaining')->willReturn(50);

        $this->repository->method('getHistory')->willReturn([
            ['id' => 1, 'text' => 'Hello'],
        ]);

        $response = $this->controller->historyAction($this->request);

        $this->assertSame(200, $response->getStatus());
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('history', $data);
        $this->assertArrayHasKey('user_id', $data);
    }

    public function testExportLibraryActionReturnsBundleWithDownloadHeader(): void
    {
        $this->repository->method('exportLibrary')->willReturn([
            'version' => '1.0',
            'voices' => [],
        ]);

        $response = $this->controller->exportLibraryAction($this->request);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('application/json', $response->getHeader('Content-Type'));
    }

    public function testCloneVoiceActionRejectsInvalidToken(): void
    {
        $this->keyManager->method('validate')->willReturn(null);
        $this->request->method('getHeader')->willReturn('vxo_invalid');

        $response = $this->controller->cloneVoiceAction($this->request);

        $this->assertSame(401, $response->getStatus());
    }

    public function testCloneVoiceActionSuccess(): void
    {
        $token = new Token('id1', 'test', ['tts:generate'], 'user', 1, time() + 3600);
        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->method('getRemaining')->willReturn(80);

        $this->request->method('getPost')->with('name')->willReturn('My Cloned Voice');
        $_FILES['sample'] = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '/tmp/sample.wav',
            'type' => 'audio/wav',
        ];

        $this->ttsManager->method('cloneVoice')->willReturn('cloned-voice-xyz');

        $response = $this->controller->cloneVoiceAction($this->request);

        $this->assertSame(200, $response->getStatus());
        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('cloned-voice-xyz', $data['voice_id']);
    }

    public function testListVoicesAction(): void
    {
        $this->repository->method('listLibrary')->willReturn([['id' => 'v1', 'name' => 'Voice One']]);

        $response = $this->controller->listVoicesAction($this->request);

        $this->assertSame(200, $response->getStatus());
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('voices', $data);
    }
}
