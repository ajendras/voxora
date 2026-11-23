<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Security\KeyManager;
use Voxora\Storage\SqliteRepository;
use Voxora\Security\Token;
use DateTimeImmutable;

class KeyManagerTest extends TestCase
{
    private SqliteRepository $repository;
    private KeyManager $keyManager;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SqliteRepository::class);
        $this->repository->method('saveToken')->willReturn(null);
        $this->repository->method('isTokenRevoked')->willReturn(false);
        $this->repository->method('getUserById')->willReturn(null);

        $config = [
            'security' => [
                'encrypt_key' => str_repeat('a', 32),
                'token_lifetime_days' => 30,
            ],
        ];

        $this->keyManager = new KeyManager($config, $this->repository);
    }

    public function testValidateAcceptsValidToken(): void
    {
        $tokenStr = $this->keyManager->issueToken(
            name: 'Test Token',
            scopes: ['tts:generate'],
            role: 'user',
            userId: 42
        );

        $token = $this->keyManager->validate($tokenStr);

        $this->assertInstanceOf(Token::class, $token);
        $this->assertTrue($token->hasScope('tts:generate'));
        $this->assertEquals('user', $token->getRole());
        $this->assertEquals(42, $token->getUserId());
    }

    public function testValidateRejectsExpiredToken(): void
    {
        $this->repository->method('isTokenRevoked')->willReturn(false);
        $this->repository->method('getUserById')->willReturn(null);

        $tokenStr = $this->keyManager->issueToken('Expired', ['tts:generate'], 'user', null, -1);
        $token = $this->keyManager->validate($tokenStr);

        $this->assertNull($token);
    }

    public function testRevokedTokenIsRejected(): void
    {
        $this->repository->method('isTokenRevoked')->willReturn(true);

        $tokenStr = $this->keyManager->issueToken('Revoked', ['tts:generate']);
        $token = $this->keyManager->validate($tokenStr);

        $this->assertNull($token);
    }

    public function testIssueTokenGeneratesValidFormat(): void
    {
        $token = $this->keyManager->issueToken('Format Test', ['tts:generate']);

        $this->assertStringStartsWith('vxo_', $token);
        $this->assertGreaterThan(20, strlen($token));
    }

    public function testTokenWithRoleAndUserId(): void
    {
        $this->repository->method('getUserById')->willReturn([
            'id' => 99,
            'username' => 'testuser',
            'role' => 'admin',
        ]);

        $tokenStr = $this->keyManager->issueToken(
            name: 'Admin Token',
            scopes: ['*'],
            role: 'admin',
            userId: 99
        );

        $token = $this->keyManager->validate($tokenStr);

        $this->assertInstanceOf(Token::class, $token);
        $this->assertEquals('admin', $token->getRole());
        $this->assertEquals(99, $token->getUserId());
        $this->assertTrue($token->hasScope('any:scope'));
    }
}
