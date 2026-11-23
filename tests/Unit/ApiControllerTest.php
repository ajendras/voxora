<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Http\ApiController;
use Voxora\Http\Request;
use Voxora\Security\KeyManager;
use Voxora\Security\Token;
use Voxora\TTS\Manager;
use Voxora\TTS\WebhookDispatcher;
use Voxora\Storage\SqliteRepository;
use Voxora\Security\RateLimiter;
use Voxora\TTS\SynthesisResult;

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

        $config = [
            'audio' => [
                'directory' => 'storage/audio',
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

        $this->request = $this->createMock(Request::class);
    }

    public function testExportLibraryActionReturnsBundleWithDownloadHeader(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('hasScope')->willReturn(true);
        $token->method('getUserId')->willReturn(1);
        $token->method('getRole')->willReturn('user');

        $this->keyManager->method('validate')->willReturn($token);

        $bundle = ['version' => '1.0', 'voices' => []];
        $this->repository->method('exportLibrary')->willReturn($bundle);

        $this->expectOutputString(json_encode([
            'success' => true,
            'bundle' => $bundle,
            'version' => '1.0',
            'exported_at' => '2025-01-01T00:00:00+00:00',
        ], JSON_THROW_ON_ERROR));

        $this->request->method('getHeader')->willReturn('vxo_testkey');

        $this->controller->exportLibraryAction($this->request);
        $this->assertStringContainsString('Content-Disposition:', implode(' ', headers_list()));
    }

    public function testCloneVoiceActionRejectsInvalidToken(): void
    {
        $this->keyManager->method('validate')->willReturn(null);

        $this->expectOutputString(json_encode(['error' => 'Invalid token or insufficient scope'], JSON_THROW_ON_ERROR));
        $this->request->method('getHeader')->willReturn('invalid-token');

        $this->controller->cloneVoiceAction($this->request);
    }

    public function testCloneVoiceActionSuccess(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('hasScope')->willReturn(true);
        $token->method('getUserId')->willReturn(1);
        $token->method('getRole')->willReturn('user');

        $this->keyManager->method('validate')->willReturn($token);
        $this->ttsManager->method('cloneVoice')->willReturn('new-voice-123');
        $this->repository->method('saveCustomVoice')->willReturn(null);

        $this->request->method('getHeader')->willReturn('vxo_testkey');
        $this->request->method('getPost')->willReturn(['name' => 'Test Voice', 'provider' => 'elevenlabs']);
        $this->request->method('getUploadedFile')->willReturn([
            'tmp_name' => '/tmp/test.wav',
            'error' => 0,
        ]);

        $this->expectOutputString(json_encode([
            'success' => true,
            'voice_id' => 'new-voice-123',
            'name' => 'Test Voice',
            'provider' => 'elevenlabs',
        ], JSON_THROW_ON_ERROR));

        $this->controller->cloneVoiceAction($this->request);
    }

    public function testListVoicesAction(): void
    {
        $voices = [
            ['id' => 'voice1', 'name' => 'Voice One'],
            ['id' => 'voice2', 'name' => 'Voice Two'],
        ];

        $this->ttsManager->method('listVoices')->willReturn($voices);
        $this->request->method('getQueryParam')->willReturn(null);

        $this->expectOutputString(json_encode([
            'success' => true,
            'voices' => $voices,
            'count' => 2,
        ], JSON_THROW_ON_ERROR));

        $this->controller->listVoicesAction($this->request);
    }
}
