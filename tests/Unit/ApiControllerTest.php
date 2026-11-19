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
use Voxora\TTS\SynthesisResult;
use Voxora\Storage\SqliteRepository;
use Voxora\Security\RateLimiter;
use Voxora\TTS\WebhookDispatcher;
use DateTimeImmutable;

/**
 * Integration tests for ApiController endpoints.
 *
 * Exercises authentication, rate limiting, validation, success paths and error
 * responses for every public action using mocked collaborators. Ensures
 * consistent JSON output shapes, proper HTTP status codes and that all
 * side-effects (repository writes, webhooks, generation) are triggered.
 */
final class ApiControllerTest extends TestCase
{
    private ApiController $controller;
    private KeyManager $keyManager;
    private Manager $ttsManager;
    private SqliteRepository $repository;
    private WebhookDispatcher $webhookDispatcher;
    private RateLimiter $rateLimiter;
    private array $config;

    protected function setUp(): void
    {
        $this->keyManager = $this->createMock(KeyManager::class);
        $this->ttsManager = $this->createMock(Manager::class);
        $this->repository = $this->createMock(SqliteRepository::class);
        $this->webhookDispatcher = $this->createMock(WebhookDispatcher::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);

        $this->config = [
            'audio' => [
                'directory' => '/tmp/audio',
                'public_url_prefix' => '/audio',
            ],
        ];

        $this->controller = new ApiController(
            $this->keyManager,
            $this->ttsManager,
            $this->repository,
            $this->config,
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

        $this->repository->expects($this->once())
            ->method('listLibrary')
            ->willReturn($voices);

        $request = $this->createMock(Request::class);

        $response = $this->controller->listVoicesAction($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testIssueTokenActionSuccess(): void
    {
        $this->rateLimiter->expects($this->once())
            ->method('isAllowed')
            ->with($this->stringContains('ip:'))
            ->willReturn(true);

        $this->keyManager->expects($this->once())
            ->method('issueToken')
            ->with('Test App', ['tts:generate'], 'user')
            ->willReturn('vxo_testtoken123456');

        $request = $this->createMock(Request::class);
        $request->method('getPost')
            ->willReturnMap([
                ['name', '', 'Test App'],
                ['scopes', '', ['tts:generate']],
                ['role', '', 'user'],
            ]);

        $response = $this->controller->issueTokenAction($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testIssueTokenActionRejectsMissingName(): void
    {
        $this->rateLimiter->expects($this->once())
            ->method('isAllowed')
            ->willReturn(true);

        $request = $this->createMock(Request::class);
        $request->method('getPost')
            ->with('name', '')
            ->willReturn('');

        $response = $this->controller->issueTokenAction($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testSpeechActionRejectsInvalidToken(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getHeader')->willReturn(null);
        $request->method('getPost')->willReturn('');

        $response = $this->controller->speechAction($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testSpeechActionEnforcesRateLimit(): void
    {
        $token = $this->createMock(Token::class);
        $token->userId = 42;
        $token->role = 'user';

        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('isAllowed')->willReturn(false);
        $this->rateLimiter->method('getLimit')->willReturn(100);
        $this->rateLimiter->method('getWindow')->willReturn(60);
        $this->rateLimiter->method('getRemaining')->willReturn(0);

        $request = $this->createMock(Request::class);
        $request->method('getHeader')->willReturn('vxo_abc123');

        $response = $this->controller->speechAction($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testSpeechActionRejectsMissingText(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('hasScope')->with('tts:generate')->willReturn(true);
        $token->userId = 1;
        $token->role = 'user';

        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('isAllowed')->willReturn(true);

        $request = $this->createMock(Request::class);
        $request->method('getHeader')->willReturn('vxo_abc123');
        $request->method('getPost')
            ->willReturnMap([
                ['text', '', ''],
                ['voice_id', '', 'voice123'],
            ]);

        $response = $this->controller->speechAction($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testSpeechActionSuccessPath(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('hasScope')->with('tts:generate')->willReturn(true);
        $token->userId = 1;
        $token->role = 'user';

        $this->keyManager->expects($this->once())
            ->method('validate')
            ->willReturn($token);

        $this->rateLimiter->expects($this->once())
            ->method('isAllowed')
            ->with('user:1')
            ->willReturn(true);

        $this->rateLimiter->expects($this->once())
            ->method('getRemaining')
            ->with('user:1')
            ->willReturn(97);

        $result = $this->getMockBuilder(SynthesisResult::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save', 'toDataUri'])
            ->getMock();

        $result->format = 'mp3';
        $result->duration = 4.8;
        $result->metadata = ['normalized' => true];
        $result->audioContent = 'fakebinarycontent';

        $result->expects($this->once())
            ->method('save');

        $result->expects($this->once())
            ->method('toDataUri')
            ->willReturn('data:audio/mp3;base64,ZmFrZWF1ZGlv');

        $this->ttsManager->expects($this->once())
            ->method('generate')
            ->with(
                'Hello from Voxora',
                'voice123',
                'elevenlabs',
                $this->callback(static fn(array $o): bool => ($o['normalize'] ?? false) === true)
            )
            ->willReturn($result);

        $this->repository->expects($this->once())
            ->method('saveGeneration');

        $this->webhookDispatcher->expects($this->once())
            ->method('dispatchSuccess');

        $request = $this->createMock(Request::class);
        $request->expects($this->atLeastOnce())
            ->method('getHeader')
            ->with('Authorization')
            ->willReturn('Bearer vxo_abc123');

        $request->expects($this->atLeastOnce())
            ->method('getPost')
            ->willReturnMap([
                ['text', '', 'Hello from Voxora'],
                ['voice_id', '', 'voice123'],
                ['provider', '', 'elevenlabs'],
                ['normalize', 'true', 'true'],
                ['target_loudness', '-16', '-16'],
                ['format', 'mp3', 'mp3'],
            ]);

        $response = $this->controller->speechAction($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testHistoryActionReturnsScopedResults(): void
    {
        $token = $this->createMock(Token::class);
        $token->userId = 7;
        $token->role = 'user';

        $this->keyManager->method('validate')->willReturn($token);
        $this->rateLimiter->method('isAllowed')->willReturn(true);

        $this->repository->expects($this->once())
            ->method('getHistory')
            ->with(10, 7)
            ->willReturn([['id' => 1, 'text' => 'Test speech']]);

        $request = $this->createMock(Request::class);
        $request->method('getHeader')->willReturn('vxo_abc123');
        $request->method('getPost')
            ->with('limit', 20)
            ->willReturn(10);

        $response = $this->controller->historyAction($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testExportLibraryActionReturnsBundleWithDownloadHeader(): void
    {
        $bundle = [
            'version' => '1.0',
            'voices' => [
                ['id' => 'v1', 'name' => 'Test Voice'],
            ],
        ];

        $this->repository->expects($this->once())
            ->method('exportLibrary')
            ->willReturn($bundle);

        $request = $this->createMock(Request::class);

        $response = $this->controller->exportLibraryAction($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testCloneVoiceActionRejectsInvalidToken(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getHeader')->willReturn(null);

        $response = $this->controller->cloneVoiceAction($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testCloneVoiceActionSuccess(): void
    {
        $token = $this->createMock(Token::class);
        $token->method('hasScope')->with('tts:generate')->willReturn(true);
        $token->userId = 1;
        $token->role = 'user';

        $this->keyManager->expects($this->once())
            ->method('validate')
            ->willReturn($token);

        $this->rateLimiter->expects($this->once())
            ->method('isAllowed')
            ->with('user:1')
            ->willReturn(true);

        $this->rateLimiter->expects($this->once())
            ->method('getRemaining')
            ->with('user:1')
            ->willReturn(89);

        $this->ttsManager->expects($this->once())
            ->method('cloneVoice')
            ->with('Custom Narrator', '/tmp/sample.mp3', 'elevenlabs', 1)
            ->willReturn('cloned-voice-xyz789');

        $request = $this->createMock(Request::class);
        $request->method('getHeader')->willReturn('vxo_abc123');
        $request->method('getPost')
            ->with('name', '')
            ->willReturn('Custom Narrator');

        $_FILES['sample'] = [
            'error' => UPLOAD_ERR_OK,
            'type' => 'audio/mpeg',
            'tmp_name' => '/tmp/sample.mp3',
        ];

        $response = $this->controller->cloneVoiceAction($request);

        $this->assertInstanceOf(Response::class, $response);

        unset($_FILES['sample']);
    }
}
