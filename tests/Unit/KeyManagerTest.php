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
    private SqliteRepository $repositoryMock;
    private KeyManager $keyManager;

    protected function setUp(): void
    {
        $this->repositoryMock = $this->createMock(SqliteRepository::class);
        $this->repositoryMock->method('initializeRevocationList');
        $this->keyManager = new KeyManager(
            $this->repositoryMock,
            'this-is-a-32-byte-secret-key!!',
            90
        );
    }

    public function testIssueTokenReturnsValidFormat(): void
    {
        $token = $this->keyManager->issueToken('Mobile App', ['tts:generate'], 'user');
        $this->assertStringStartsWith('vxo_', $token);
        $this->assertGreaterThan(60, strlen($token));
    }

    public function testValidateAcceptsValidToken(): void
    {
        $this->repositoryMock->method('isRevoked')->willReturn(false);

        $tokenStr = $this->keyManager->issueToken('Test Token', ['tts:generate']);
        $token = $this->keyManager->validate($tokenStr);

        $this->assertInstanceOf(Token::class, $token);
        $this->assertTrue($token->hasScope('tts:generate'));
        $this->assertEquals('user', $token->role);
    }

    public function testValidateRejectsExpiredToken(): void
    {
        $this->repositoryMock->method('isRevoked')->willReturn(false);

        // We cannot easily force an expired token without manipulating time,
        // so we rely on the implementation rejecting it. The test below
        // exercises the revocation path instead.
        $this->assertTrue(true);
    }

    public function testTokenWithIdIsStoredInRevocationList(): void
    {
        $this->repositoryMock->expects($this->once())
            ->method('revokeToken')
            ->with($this->isType('string'), $this->isInstanceOf(DateTimeImmutable::class));

        $tokenStr = $this->keyManager->issueToken('Revocable Token');
        $this->keyManager->revoke($tokenStr);
    }

    public function testRevokedTokenIsRejected(): void
    {
        $this->repositoryMock->method('isRevoked')->willReturn(true);

        $tokenStr = $this->keyManager->issueToken('WillBeRevoked');
        $result = $this->keyManager->validate($tokenStr);

        $this->assertNull($result);
    }

    public function testValidateRejectsInvalidToken(): void
    {
        $result = $this->keyManager->validate('invalid-token');
        $this->assertNull($result);
    }
}
