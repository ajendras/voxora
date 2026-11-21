<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Security\KeyManager;
use Voxora\Security\Token;
use Voxora\Storage\SqliteRepository;

class KeyManagerTest extends TestCase
{
    private KeyManager $keyManager;
    private SqliteRepository $repositoryMock;

    protected function setUp(): void
    {
        $this->repositoryMock = $this->createMock(SqliteRepository::class);
        // Short key now works because KeyManager derives a 32-byte key internally.
        $this->keyManager = new KeyManager('test-secret', $this->repositoryMock);
    }

    public function testIssueTokenReturnsValidFormat(): void
    {
        $token = $this->keyManager->issueToken('Test App');

        $this->assertIsString($token);
        $this->assertStringStartsWith('vxo_', $token);
        $this->assertGreaterThan(60, strlen($token));
    }

    public function testValidateAcceptsValidToken(): void
    {
        $this->repositoryMock
            ->method('isTokenRevoked')
            ->willReturn(false);

        $tokenStr = $this->keyManager->issueToken(
            name: 'Valid Integration',
            scopes: ['tts:generate', 'library:read'],
            role: 'user'
        );

        $token = $this->keyManager->validate($tokenStr);

        $this->assertInstanceOf(Token::class, $token);
        $this->assertSame('Valid Integration', $token->name);
        $this->assertTrue($token->hasScope('tts:generate'));
        $this->assertFalse($token->isExpired());
        $this->assertSame('user', $token->role);
    }

    public function testValidateRejectsExpiredToken(): void
    {
        $this->repositoryMock
            ->method('isTokenRevoked')
            ->willReturn(false);

        $tokenStr = $this->keyManager->issueToken(
            name: 'Expired Token',
            scopes: ['tts:generate'],
            role: 'user',
            userId: 42,
            lifetimeDays: -30
        );

        $token = $this->keyManager->validate($tokenStr);

        $this->assertNull($token);
    }

    public function testTokenWithIdIsStoredInRevocationList(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('addToRevocationList')
            ->with($this->isType('string'));

        $this->repositoryMock
            ->method('isTokenRevoked')
            ->willReturn(false);

        $tokenStr = $this->keyManager->issueToken('Revocable Token');
        $token = $this->keyManager->validate($tokenStr);

        $this->assertNotNull($token);
        $this->keyManager->revokeToken($token->id);
    }

    public function testRevokedTokenIsRejected(): void
    {
        $this->repositoryMock
            ->method('isTokenRevoked')
            ->willReturn(true);

        $tokenStr = $this->keyManager->issueToken('ToBeRevoked');

        $token = $this->keyManager->validate($tokenStr);

        $this->assertNull($token);
    }

    public function testValidateRejectsInvalidToken(): void
    {
        $this->repositoryMock
            ->expects($this->never())
            ->method('isTokenRevoked');

        $this->assertNull($this->keyManager->validate('vxo_invalid'));
        $this->assertNull($this->keyManager->validate('not-a-token-at-all'));
    }
}
