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
use Voxora\TTS\WebhookDispatcher;
use Voxora\Security\RateLimiter;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

class ApiControllerTest extends TestCase
{
    private ApiController $controller;
    private KeyManager&MockObject $keyManager;
    private Manager&MockObject $ttsManager;
    private SqliteRepository $repository;
    private array $config;
    private WebhookDispatcher&MockObject $webhookDispatcher;
    private RateLimiter&MockObject $rateLimiter;

    protected function setUp(): void
    {
        $this->repository = new SqliteRepository(':memory:');
        $this->config = [
            'security' => ['encrypt_key' => sodium_crypto_secretbox_keygen()],
            'audio' => ['directory' => '/tmp', 'public_url_prefix' => '/audio'],
            'providers' => ['elevenlabs' => ['api_key' => 'test']],
        ];
        $this->keyManager = $this->createMock(KeyManager::class);
        $this->ttsManager = $this->createMock(Manager::class);
        $this->webhookDispatcher = $this->createMock(WebhookDispatcher::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);

        $this->controller = new ApiController(
            $this->keyManager,
            $this->ttsManager,
            $this->repository,
            $this->config,
            $this->webhookDispatcher,
            $this->rateLimiter
        );
    }

    public function testObjectsInvalidToken()
    {
        $this->keyManager->method('validate')
            ->willThrowException(new RuntimeException('Invalid or expired token'));

        $request = $this->createMockRequest('POST', '/api/v1/speech', ['X-Api-Key' => 'invalid-token']);

        $response = $this->controller->speechAction($request);

        $this->assertEquals(401, $response['status']);
        $this->assertStringContainsString('Invalid', $response['body']);
    }

    public function testCloneVoiceActionSuccess()
    {
        $token = new Token('test-token', ['tts:generate'], 'user', 1, new DateTimeImmutable('+30 days'));
        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->ttsManager->method('cloneVoice')->willReturn('cloned-voice-12345');

        $request = $this->createMockRequest(
            'POST',
            '/api/v1/clone-voice',
            ['X-Api-Key' => 'valid-token'],
            ['name' => 'Test Clone', 'sample' => ['tmp_name' => __FILE__, 'name' => 'test.wav']]
        );

        $response = $this->controller->cloneVoiceAction($request);

        $this->assertEquals(200, $response['status']);
        $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals('cloned-voice-12345', $data['voice_id']);
    }

    public function testListVoicesAction()
    {
        $token = new Token('test-token', ['tts:generate'], 'user', 1, new DateTimeImmutable('+30 days'));
        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('allow')->willReturn(true);
        $this->ttsManager->method('listVoices')->willReturn([
            ['id' => 'voice1', 'name' => 'Test Voice'],
        ]);

        $request = $this->createMockRequest('GET', '/api/v1/voices', ['X-Api-Key' => 'valid-token']);

        $response = $this->controller->listVoicesAction($request);

        $this->assertEquals(200, $response['status']);
        $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('voices', $data);
        $this->assertCount(1, $data['voices']);
    }

    private function createMockRequest(string $method, string $path, array $headers = [], array $post = []): Request
    {
        $query = [];
        if (str_contains($path, '?')) {
            parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $query);
        }
        return new Request($method, $path, $query, $headers, $post);
    }
}
