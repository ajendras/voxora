<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Security\KeyManager;
use Voxora\Storage\SqliteRepository;
use RuntimeException;
use DateTimeImmutable;

class KeyManagerTest extends TestCase
{
    private KeyManager $keyManager;
    private SqliteRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new SqliteRepository(':memory:');
        $config = [
            'security' => [
                'encrypt_key' => sodium_crypto_secretbox_keygen(),
                'token_lifetime_days' => 90,
            ],
        ];
        $this->keyManager = new KeyManager($this->repository, $config);
    }

    public function testIssueTokenAndValidateSuccess()
    {
        $token = $this->keyManager->issueToken(
            name: 'Test Token',
            scopes: ['tts:generate'],
            role: 'user',
            userId: null,
            expiresInDays: 30
        );

        $this->assertIsString($token);
        $this->assertStringStartsWith('vxo_', $token);

        $validated = $this->keyManager->validate($token);
        $this->assertInstanceOf(\Voxora\Security\Token::class, $validated);
        $this->assertTrue($validated->hasScope('tts:generate'));
    }

    public function testValidateRejectsExpiredToken()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token has expired');

        $token = $this->keyManager->issueToken(
            name: 'Expired Token',
            scopes: ['tts:generate'],
            role: 'user',
            userId: null,
            expiresInDays: 1
        );

        $this->repository->pdo->exec("
            UPDATE tokens 
            SET expires_at = datetime('now', '-2 days')
            WHERE token_hash = (SELECT token_hash FROM tokens ORDER BY id DESC LIMIT 1)
        ");

        $this->keyManager->validate($token);
    }

    public function testRevokedTokenIsRejected()
    {
        $tokenStr = $this->keyManager->issueToken(
            name: 'Revocable Token',
            scopes: ['tts:generate'],
            role: 'user',
            userId: null,
            expiresInDays: 30
        );

        $token = $this->keyManager->validate($tokenStr);
        $hash = hash('sha256', $tokenStr);

        $this->repository->revokeToken($hash);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token has been revoked');

        $this->keyManager->validate($tokenStr);
    }

    public function testTokenWithInvalidScopeIsRejected()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insufficient scope');

        $token = $this->keyManager->issueToken(
            name: 'Limited Token',
            scopes: ['tts:generate'],
            role: 'user',
            userId: null,
            expiresInDays: 30
        );

        $validated = $this->keyManager->validate($token);
        $validated->requireScope('admin:full');
    }
}
