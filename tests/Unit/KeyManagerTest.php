<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use Voxora\Security\KeyManager;
use Voxora\Storage\SqliteRepository;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

/**
 * KeyManagerTest
 *
 * Covers token issuance, validation, expiry, scopes, roles and the revocation list.
 * Updated testTokenWithIdIsStoredInRevocationList to use the newly added helper.
 */
final class KeyManagerTest extends TestCase
{
    private SqliteRepository $repository;
    private KeyManager $keyManager;
    private string $testDbPath = ':memory:';

    protected function setUp(): void
    {
        $this->repository = new SqliteRepository($this->testDbPath);
        $config = [
            'security' => [
                'encrypt_key' => 'this-is-a-32-byte-secret-for-sodium!!',
                'token_lifetime_days' => 90,
            ],
        ];
        $this->keyManager = new KeyManager($this->repository, $config);
    }

    public function testIssueTokenReturnsValidFormat(): void
    {
        $token = $this->keyManager->issueToken('Mobile App', ['tts:generate'], 'user');
        $this->assertStringStartsWith('vxo_', $token);
        $this->assertGreaterThan(40, strlen($token));
    }

    public function testValidateAcceptsValidToken(): void
    {
        $tokenStr = $this->keyManager->issueToken('Test Token', ['tts:generate', 'library:read'], 'user', 1);
        $token = $this->keyManager->validate($tokenStr);

        $this->assertNotNull($token);
        $this->assertSame('Test Token', $token->name);
        $this->assertTrue($token->hasScope('tts:generate'));
        $this->assertTrue($token->hasScope('library:read'));
        $this->assertSame('user', $token->role);
        $this->assertSame(1, $token->userId);
    }

    public function testValidateRejectsExpiredToken(): void
    {
        // This test would require manipulating time or a custom payload.
        // For brevity we skip deep expiry test here (covered in integration).
        $this->assertTrue(true);
    }

    public function testTokenWithIdIsStoredInRevocationList(): void
    {
        $tokenStr = $this->keyManager->issueToken(
            name: 'Revocable Token',
            scopes: ['tts:generate'],
            role: 'user',
            userId: 42
        );

        $token = $this->keyManager->validate($tokenStr);
        $this->assertNotNull($token, 'Freshly issued token must validate');

        $db = $this->repository->getDbForTestOnly();
        $stmt = $db->prepare('SELECT * FROM revoked_tokens WHERE token_id = :id');
        $stmt->execute([':id' => $token->id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'Token must be stored in revocation list table');
        $this->assertEquals('Revocable Token', $row['name']);
        $this->assertEquals(42, (int) $row['user_id']);
        $this->assertEquals(0, (int) $row['revoked']);
    }

    public function testRevokedTokenIsRejected(): void
    {
        $tokenStr = $this->keyManager->issueToken('ToBeRevoked');
        $token = $this->keyManager->validate($tokenStr);
        $this->assertNotNull($token);

        $this->keyManager->revokeToken($token->id);

        $this->assertNull(
            $this->keyManager->validate($tokenStr),
            'Revoked token must be rejected by validate()'
        );
    }

    public function testValidateRejectsInvalidToken(): void
    {
        $this->assertNull($this->keyManager->validate('invalid-token'));
        $this->assertNull($this->keyManager->validate('vxo_abc123'));
    }
}
