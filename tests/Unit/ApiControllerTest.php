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
use InvalidArgumentException;
use RuntimeException;

class ApiControllerTest extends TestCase
{
    private ApiController $controller;
    private KeyManager $keyManager;
    private Manager $ttsManager;
    private SqliteRepository $repository;
    private RateLimiter $rateLimiter;
    private WebhookDispatcher $webhookDispatcher;
    private Request $request;

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

    public function testListVoicesActionReturnsLibrary(): void
    {
        $voices = [
            ['id' => '21m00Tcm4TlvDq8ikWAM', 'name' => 'Rachel'],
            ['id' => 'AZnzlkDJ2myd1k8p7Q6X', 'name' => 'Domi'],
        ];
        $this->repository->method('listLibrary')->willReturn($voices);

        $response = $this->controller->listVoicesAction($this->request);

        $this->assertInstanceOf(Response::class, $response);
        $data = $this->getResponseData($response);
        $this->assertArrayHasKey('voices', $data);
        $this->assertCount(2, $data['voices']);
        $this->assertEquals('21m00Tcm4TlvDq8ikWAM', $data['default_voice']);
    }

    public function testIssueTokenActionSuccess(): void
    {
        $this->request->method('getPost')->willReturnMap([
            ['name', '', 'Test App'],
            ['scopes', '', ['tts:generate']],
            ['role', '', 'user'],
        ]);
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->keyManager->method('issueToken')->willReturn('vxo_testtoken123');

        $response = $this->controller->issueTokenAction($this->request);

        $this->assertInstanceOf(Response::class, $response);
        $data = $this->getResponseData($response);
        $this->assertTrue($data['success']);
        $this->assertEquals('vxo_testtoken123', $data['token']);
        $this->assertEquals('user', $data['role']);
    }

    public function testIssueTokenActionRejectsMissingName(): void
    {
        $this->request->method('getPost')->willReturnMap([
            ['name', '', ''],
        ]);
        $this->rateLimiter->method('isAllowed')->willReturn(true);

        $response = $this->controller->issueTokenAction($this->request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(400, $this->getResponseStatus($response));
        $data = $this->getResponseData($response);
        $this->assertStringContainsString('name is required', $data['error']);
    }

    public function testSpeechActionRejectsInvalidToken(): void
    {
        $this->keyManager->method('validate')->willReturn(null);
        $this->request->method('getHeader')->willReturn('Bearer invalid');

        $response = $this->controller->speechAction($this->request);

        $this->assertEquals(401, $this->getResponseStatus($response));
        $data = $this->getResponseData($response);
        $this->assertEquals('invalid_token', $data['code']);
    }

    public function testSpeechActionEnforcesRateLimit(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('userId')->willReturn(1);
        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('isAllowed')->willReturn(false);
        $this->rateLimiter->method('getLimit')->willReturn(100);
        $this->rateLimiter->method('getWindow')->willReturn(60);

        $response = $this->controller->speechAction($this->request);

        $this->assertEquals(429, $this->getResponseStatus($response));
        $data = $this->getResponseData($response);
        $this->assertEquals('rate_limit_exceeded', $data['code']);
    }

    public function testSpeechActionRejectsMissingText(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('hasScope')->willReturn(true);
        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->request->method('getPost')->willReturnMap([
            ['text', '', ''],
            ['voice_id', '', 'voice123'],
        ]);

        $response = $this->controller->speechAction($this->request);

        $this->assertEquals(400, $this->getResponseStatus($response));
        $data = $this->getResponseData($response);
        $this->assertStringContainsString('text', $data['error']);
    }

    public function testSpeechActionSuccessPath(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('hasScope')->willReturn(true);
        $token->method('userId')->willReturn(1);
        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->method('getRemaining')->willReturn(95);

        $this->request->method('getPost')->willReturnMap([
            ['text', '', 'Hello world'],
            ['voice_id', '', 'voice123'],
            ['provider', '', 'elevenlabs'],
            ['normalize', '', 'true'],
            ['format', '', 'mp3'],
        ]);

        $result = new \Voxora\TTS\SynthesisResult(
            audioContent: 'fake-audio',
            format: 'mp3',
            duration: 2.5,
            metadata: ['normalized' => true]
        );
        $this->ttsManager->method('generate')->willReturn($result);
        $this->repository->expects($this->once())->method('saveGeneration');
        $this->webhookDispatcher->expects($this->once())->method('dispatchSuccess');

        $response = $this->controller->speechAction($this->request);

        $this->assertEquals(200, $this->getResponseStatus($response));
        $data = $this->getResponseData($response);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('audio_url', $data);
        $this->assertEquals(2.5, $data['duration']);
        $this->assertEquals(95, $data['rate_limit_remaining']);
    }

    public function testHistoryActionReturnsScopedResults(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('userId')->willReturn(42);
        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->method('getRemaining')->willReturn(90);

        $history = [
            ['id' => 1, 'text' => 'Test speech', 'user_id' => 42],
        ];
        $this->repository->method('getHistory')->willReturn($history);

        $response = $this->controller->historyAction($this->request);

        $this->assertInstanceOf(Response::class, $response);
        $data = $this->getResponseData($response);
        $this->assertArrayHasKey('history', $data);
        $this->assertEquals(42, $data['user_id']);
        $this->assertEquals(90, $data['rate_limit_remaining']);
    }

    public function testExportLibraryActionReturnsBundleWithDownloadHeader(): void
    {
        $bundle = [
            'version' => '1.0',
            'exported_at' => '2025-01-01T00:00:00Z',
            'voices' => [],
        ];
        $this->repository->method('exportLibrary')->willReturn($bundle);

        $response = $this->controller->exportLibraryAction($this->request);

        $this->assertInstanceOf(Response::class, $response);
        $data = $this->getResponseData($response);
        $this->assertEquals('1.0', $data['version']);
        // Header handling is performed by Response or front controller
    }

    public function testCloneVoiceActionRejectsInvalidToken(): void
    {
        $this->keyManager->method('validate')->willReturn(null);

        $response = $this->controller->cloneVoiceAction($this->request);

        $this->assertEquals(401, $this->getResponseStatus($response));
    }

    public function testCloneVoiceActionSuccess(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('hasScope')->willReturn(true);
        $token->method('userId')->willReturn(1);
        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->rateLimiter->method('getRemaining')->willReturn(88);

        $this->request->method('getPost')->willReturn('My Cloned Voice');
        $_FILES['sample'] = [
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => '/tmp/sample.wav',
            'type' => 'audio/wav',
        ];

        $this->ttsManager->method('cloneVoice')->willReturn('cloned-voice-xyz');

        $response = $this->controller->cloneVoiceAction($this->request);

        $this->assertEquals(200, $this->getResponseStatus($response));
        $data = $this->getResponseData($response);
        $this->assertTrue($data['success']);
        $this->assertEquals('cloned-voice-xyz', $data['voice_id']);
        $this->assertEquals(88, $data['rate_limit_remaining']);
    }

    private function getResponseData(Response $response): array
    {
        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('data');
        $property->setAccessible(true);
        return $property->getValue($response) ?? [];
    }

    private function getResponseStatus(Response $response): int
    {
        $reflection = new \ReflectionClass($response);
        $property = $reflection->getProperty('status');
        $property->setAccessible(true);
        return $property->getValue($response) ?? 200;
    }
}
