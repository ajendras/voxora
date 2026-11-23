<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Http\ApiController;
use Voxora\Security\KeyManager;
use Voxora\TTS\Manager;
use Voxora\Storage\SqliteRepository;
use Voxora\Security\RateLimiter;
use Voxora\TTS\WebhookDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use Voxora\Http\Request;

class ApiControllerTest extends TestCase
{
    private ApiController $controller;
    private SqliteRepository $repository;
    private KeyManager $keyManager;
    private Manager|MockObject $ttsManager;
    private RateLimiter|MockObject $rateLimiter;
    private WebhookDispatcher|MockObject $webhookDispatcher;
    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'security' => [
                'encrypt_key' => str_repeat('k', 32),
                'token_lifetime_days' => 90,
            ],
            'audio' => [
                'directory' => sys_get_temp_dir(),
                'public_url_prefix' => '/audio',
            ],
            'rate_limit' => [
                'requests' => 100,
                'window' => 60,
            ],
        ];

        $this->repository = new SqliteRepository(':memory:');
        $this->keyManager = new KeyManager($this->config, $this->repository);
        $this->ttsManager = $this->createMock(Manager::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);
        $this->webhookDispatcher = $this->createMock(WebhookDispatcher::class);

        $this->controller = new ApiController(
            $this->keyManager,
            $this->ttsManager,
            $this->repository,
            $this->config,
            $this->webhookDispatcher,
            $this->rateLimiter
        );
    }

    public function testObjectsInvalidToken(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('X-Api-Key')
            ->willReturn('vxo_invalidtoken123');

        $this->rateLimiter->method('allow')->willReturn(true);

        $response = $this->controller->speechAction($request);

        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(401, $response['status'] ?? 401);
    }

    public function testCloneVoiceActionSuccess(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->ttsManager->method('cloneVoice')
            ->willReturn('cloned-voice-12345');

        $validToken = $this->keyManager->issueToken('test-clone', ['tts:clone']);
        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('X-Api-Key')
            ->willReturn($validToken);
        $request->method('getUploadedFile')->willReturn([
            'name' => 'sample.wav',
            'tmp_name' => __DIR__ . '/fixtures/sample.wav',
        ]);
        $request->method('getPost')->willReturn(['name' => 'Test Clone']);

        $response = $this->controller->cloneVoiceAction($request);

        $this->assertArrayHasKey('voice_id', $response);
        $this->assertEquals('cloned-voice-12345', $response['voice_id']);
    }

    public function testListVoicesAction(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->ttsManager->method('listVoices')
            ->willReturn([
                ['id' => 'voice1', 'name' => 'Voice One'],
                ['id' => 'voice2', 'name' => 'Voice Two'],
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getHeader')->willReturn(
            $this->keyManager->issueToken('test-list', ['tts:generate'])
        );
        $request->method('getQuery')->willReturn([]);

        $response = $this->controller->listVoicesAction($request);

        $this->assertIsArray($response);
        $this->assertCount(2, $response);
        $this->assertArrayHasKey('id', $response[0]);
    }
}
