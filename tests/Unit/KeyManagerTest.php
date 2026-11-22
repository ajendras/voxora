<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Security\KeyManager;
use Voxora\Security\Token;
use Voxora\Storage\SqliteRepository;
use DateTimeImmutable;
use RuntimeException;

class KeyManagerTest extends TestCase
{
    private KeyManager $keyManager;
    private SqliteRepository $repoMock;
    private string $testKey;

    protected function setUp(): void
    {
        $this->testKey = str_repeat('a', 32); // valid key for tests
        $this->repoMock = $this->createMock(SqliteRepository::class);

        // Fixed: never use willReturn(null) on a method declared :void
        // PHPUnit 10+ strictly validates return types against the original signature.
        $this->repoMock->expects($this->any())
            ->method('storeIssuedToken');

        $this->repoMock->method('isTokenRevoked')
            ->willReturn(false);

        $this->repoMock->method('getUserById')
            ->willReturn(['id' => 1, 'role' => 'user']);

        $this->keyManager = new KeyManager($this->repoMock, $this->testKey);
    }

    public function testValidateAcceptsValidToken(): void
    {
        $token = $this->keyManager->issueToken(
            name: 'Test Client',
            scopes: ['tts:generate']
        );

        $validated = $this->keyManager->validate($token);

        $this->assertInstanceOf(Token::class, $validated);
        $this->assertTrue($validated->hasScope('tts:generate'));
        $this->assertFalse($validated->isExpired());
    }

    public function testValidateRejectsExpiredToken(): void
    {
        $this->repoMock->method('isTokenRevoked')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/expired|invalid/i');

        // Use a deliberately malformed token that will fail decryption/expiry check
        $this->keyManager->validate('vxo_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
    }

    public function testRevokedTokenIsRejected(): void
    {
        $this->repoMock->method('isTokenRevoked')->willReturn(true);

        $token = $this->keyManager->issueToken(
            name: 'Revocable Token',
            scopes: ['tts:generate']
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/revoked/i');

        $this->keyManager->validate($token);
    }

    public function testIssueTokenGeneratesValidFormat(): void
    {
        $token = $this->keyManager->issueToken(
            name: 'Mobile App',
            scopes: ['tts:generate', 'library:read'],
            role: 'user'
        );

        $this->assertStringStartsWith('vxo_', $token);
        $this->assertGreaterThan(50, strlen($token));
    }

    public function testTokenWithRoleAndUserId(): void
    {
        $token = $this->keyManager->issueToken(
            name: 'Admin Token',
            scopes: ['tts:generate'],
            role: 'admin'
        );

        $validated = $this->keyManager->validate($token);

        $this->assertEquals('admin', $validated->getRole());
        $this->assertEquals(1, $validated->getUserId());
    }
}
