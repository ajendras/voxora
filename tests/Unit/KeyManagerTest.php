<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use Voxora\Security\KeyManager;
use Voxora\Security\Token;
use Voxora\Storage\SqliteRepository;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

class KeyManagerTest extends TestCase
{
    private array $config = [
        'security' => [
            'encrypt_key' => 'a2a1e3d4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2',
            'token_lifetime_days' => 90,
        ],
    ];

    public function testValidateAcceptsValidToken()
    {
        $repository = $this->createMock(SqliteRepository::class);
        $repository->expects($this->once())
            ->method('isTokenRevoked')
            ->with('testid1234')
            ->willReturn(false);
        $repository->method('storeIssuedToken')->willReturn(null);

        $keyManager = new KeyManager($this->config, $repository);
        $token = $keyManager->issueToken(
            name: 'Test Token',
            scopes: ['tts:generate'],
            role: 'user',
            userId: 1
        );

        $validated = $keyManager->validate($token);
        $this->assertInstanceOf(Token::class, $validated);
        $this->assertSame('Test Token', $validated->name);
        $this->assertTrue($validated->hasScope('tts:generate'));
    }

    public function testValidateRejectsExpiredToken()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('expired');

        $repository = $this->createMock(SqliteRepository::class);
        $repository->method('isTokenRevoked')->willReturn(false);
        $repository->method('storeIssuedToken')->willReturn(null);

        $keyManager = new KeyManager($this->config, $repository);
        $token = $keyManager->issueToken(
            name: 'Expired Token',
            scopes: ['tts:generate'],
            role: 'user',
            userId: 1,
            lifetimeDays: -1
        );

        $keyManager->validate($token);
    }

    public function testTokenWithIdIsStoredInRevocationList()
    {
        $repository = $this->createMock(SqliteRepository::class);
        $repository->expects($this->once())
            ->method('addToRevocationList')
            ->with('testid1234', $this->isType('string'));

        $keyManager = new KeyManager($this->config, $repository);
        $keyManager->addToRevocationList('testid1234');
        $this->assertTrue(true);
    }

    public function testRevokedTokenIsRejected()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('revoked');

        $repository = $this->createMock(SqliteRepository::class);
        $repository->method('isTokenRevoked')->willReturn(true);
        $repository->method('storeIssuedToken')->willReturn(null);

        $keyManager = new KeyManager($this->config, $repository);
        $token = $keyManager->issueToken('Revoked Token', ['tts:generate']);
        $keyManager->validate($token);
    }

    public function testValidateRejectsInvalidToken()
    {
        $this->expectException(\Exception::class);

        $repository = $this->createMock(SqliteRepository::class);
        $keyManager = new KeyManager($this->config, $repository);
        $keyManager->validate('invalid-token-string');
    }
}
