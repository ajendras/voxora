<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Security\KeyManager;
use Voxora\Storage\SqliteRepository;
use DateTimeImmutable;

class KeyManagerTest extends TestCase
{
    private KeyManager $keyManager;
    private SqliteRepository $repository;
    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'security' => [
                'encrypt_key' => str_repeat('k', 32),
                'token_lifetime_days' => 90,
            ],
        ];

        $this->repository = new SqliteRepository(':memory:');
        $this->keyManager = new KeyManager($this->config, $this->repository);
    }

    public function testValidateAcceptsValidToken(): void
    {
        $token = $this->keyManager->issueToken('test-valid', ['tts:generate']);
        $validated = $this->keyManager->validate($token);

        $this->assertTrue($validated->hasScope('tts:generate'));
    }

    public function testValidateRejectsExpiredToken(): void
    {
        $this->expectException(\RuntimeException::class);

        $expiredToken = $this->keyManager->issueToken(
            'test-expired',
            ['tts:generate'],
            'user',
            null,
            (new DateTimeImmutable())->modify('-10 days')
        );

        $this->keyManager->validate($expiredToken);
    }

    public function testRevokedTokenIsRejected(): void
    {
        $token = $this->keyManager->issueToken('test-revoked', ['tts:generate']);
        $this->keyManager->revokeToken($token);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/revoked/');

        $this->keyManager->validate($token);
    }

    public function testIssueTokenGeneratesValidFormat(): void
    {
        $token = $this->keyManager->issueToken('test-format', ['tts:generate']);

        $this->assertStringStartsWith('vxo_', $token);
        $this->assertGreaterThan(30, strlen($token));
    }

    public function testTokenWithRoleAndUserId(): void
    {
        $token = $this->keyManager->issueToken(
            'test-role',
            ['tts:generate'],
            'admin',
            42
        );

        $validated = $this->keyManager->validate($token);

        $this->assertEquals('admin', $validated->getRole());
        $this->assertEquals(42, $validated->getUserId());
    }
}
