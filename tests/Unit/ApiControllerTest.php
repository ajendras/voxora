<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Http\ApiController;
use Voxora\Security\KeyManager;
use Voxora\Security\Token;
use Voxora\TTS\Manager;
use Voxora\Storage\SqliteRepository;
use Voxora\Security\RateLimiter;
use Voxora\TTS\WebhookDispatcher;
use Voxora\Http\Request;
use PHPUnit\Framework\MockObject\MockObject;

class ApiControllerTest extends TestCase
{
    private ApiController $controller;
    private KeyManager $keyManager;
    private Manager|MockObject $ttsManager;
    private SqliteRepository|MockObject $repository;
    private RateLimiter|MockObject $rateLimiter;
    private WebhookDispatcher|MockObject $webhookDispatcher;
    private Request|MockObject $request;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SqliteRepository::class);
        $config = [
            'security' => [
                'encrypt_key' => 'this_is_a_32_byte_test_key_xxx',
                'token_lifetime_days' => 90,
            ],
            'audio' => [
                'directory' => '/tmp',
                'public_url_prefix' => '/audio',
            ],
        ];
        $this->keyManager = new KeyManager($this->repository, $config);

        $this->ttsManager = $this->createMock(Manager::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);
        $this->webhookDispatcher = $this->createMock(WebhookDispatcher::class);
        $this->request = $this->createMock(Request::class);

        $this->controller = new ApiController(
            $this->keyManager,
            $this->ttsManager,
            $this->repository,
            $config,
            $this->webhookDispatcher,
            $this->rateLimiter
        );
    }

    public function testExportLibraryActionReturnsBundleWithDownloadHeader(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->repository->method('exportLibrary')->willReturn([
            'version' => '1.0',
            'voices' => [],
        ]);

        $this->request->method('getHeader')->willReturn('vxo_validtoken123');

        $response = $this->controller->exportLibraryAction($this->request);

        $this->assertArrayHasKey('Content-Disposition', $response['headers']);
        $this->assertStringContainsString('voices-bundle.json', $response['headers']['Content-Disposition']);
    }

    public function testCloneVoiceActionRejectsInvalidToken(): void
    {
        $this->request->method('getHeader')->willReturn('invalid-token');

        $response = $this->controller->cloneVoiceAction($this->request);

        $this->assertSame(401, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    public function testCloneVoiceActionSuccess(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->request->method('getHeader')->willReturn('vxo_validtoken123');
        $this->request->method('getUploadedFile')->willReturn(['tmp_name' => __DIR__ . '/fixtures/sample.wav', 'name' => 'sample.wav']);
        $this->request->method('getPost')->willReturn(['name' => 'Test Voice']);

        $this->ttsManager->method('cloneVoice')->willReturn('voice-clone-123');
        $this->repository->method('saveCustomVoice');

        $response = $this->controller->cloneVoiceAction($this->request);

        $this->assertSame(200, $response['status']);
        $this->assertArrayHasKey('voice_id', $response['body']);
    }

    public function testListVoicesAction(): void
    {
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->request->method('getHeader')->willReturn('vxo_validtoken123');
        $this->ttsManager->method('listVoices')->willReturn([['id' => 'v1', 'name' => 'Voice One']]);

        $response = $this->controller->listVoicesAction($this->request);

        $this->assertSame(200, $response['status']);
        $this->assertIsArray($response['body']);
        $this->assertCount(1, $response['body']);
    }
}
