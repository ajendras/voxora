<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Security\KeyManager;
use Voxora\Security\Token;
use Voxora\Storage\SqliteRepository;

class KeyManagerTest extends TestCase
{
    private string $tempDb;
    private SqliteRepository $repository;
    private KeyManager $keyManager;

    protected function setUp(): void
    {
        $this->tempDb = tempnam(sys_get_temp_dir(), 'voxora_test_') . '.db';
        $config = [
            'security' => [
                'encrypt_key' => 'super-secret-master-key-32-chars-long!!!',
                'token_lifetime_days' => 90,
            ],
        ];
        $this->repository = new SqliteRepository($this->tempDb);
        $this->keyManager = new KeyManager($config, $this->repository);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDb)) {
            unlink($this->tempDb);
        }
    }

    public function testIssueTokenReturnsPrefixedEncryptedString(): void
    {
        $token = $this->keyManager->issueToken('Test App', ['tts:generate'], 'user');
        $this->assertStringStartsWith('vxo_', $token);
        $this->assertGreaterThan(40, strlen($token));
    }

    public function testValidateReturnsValidTokenObject(): void
    {
        $tokenStr = $this->keyManager->issueToken(
            name: 'Mobile Client',
            scopes: ['tts:generate', 'library:read'],
            role: 'user',
            userId: 42
        );

        $token = $this->keyManager->validate($tokenStr);

        $this->assertInstanceOf(Token::class, $token);
        $this->assertNotEmpty($token->id);
        $this->assertSame('Mobile Client', $token->name);
        $this->assertContains('tts:generate', $token->scopes);
        $this->assertSame('user', $token->role);
        $this->assertSame(42, $token->userId);
        $this->assertFalse($token->isExpired());
    }

    public function testExpiredTokenReturnsNull(): void
    {
        $config = [
            'security' => [
                'encrypt_key' => 'super-secret-master-key-32-chars-long!!!',
                'token_lifetime_days' => -1,
            ],
        ];
        $km = new KeyManager($config, $this->repository);
        $tokenStr = $km->issueToken('Expired Test');

        $this->assertNull($km->validate($tokenStr));
    }

    public function testInvalidTokenReturnsNull(): void
    {
        $this->assertNull($this->keyManager->validate('vxo_invalidbase64'));
        $this->assertNull($this->keyManager->validate('not-a-token'));
    }

    public function testTokenWithoutRequiredScopeIsStillValidButHasScopeCheckFails(): void
    {
        $tokenStr = $this->keyManager->issueToken('No Scope Token', []);
        $token = $this->keyManager->validate($tokenStr);

        $this->assertInstanceOf(Token::class, $token);
        $this->assertFalse($token->hasScope('tts:generate'));
    }

    public function testRevokeTokenMakesValidateReturnNull(): void
    {
        $tokenStr = $this->keyManager->issueToken('Revocable Token', ['tts:generate'], 'user', 1);
        $initialToken = $this->keyManager->validate($tokenStr);
        $this->assertInstanceOf(Token::class, $initialToken);

        $revoked = $this->keyManager->revokeToken($tokenStr);
        $this->assertTrue($revoked);

        $afterRevoke = $this->keyManager->validate($tokenStr);
        $this->assertNull($afterRevoke);
    }

    public function testRevokeNonExistentOrInvalidTokenReturnsFalse(): void
    {
        $this->assertFalse($this->keyManager->revokeToken('vxo_invalid'));
        $this->assertFalse($this->keyManager->revokeToken(''));
    }

    public function testRevocationIsPersistentAcrossInstances(): void
    {
        $tokenStr = $this->keyManager->issueToken('Persistent Revoke Test');

        $this->keyManager->revokeToken($tokenStr);

        $config = [
            'security' => [
                'encrypt_key' => 'super-secret-master-key-32-chars-long!!!',
                'token_lifetime_days' => 90,
            ],
        ];
        $newRepo = new SqliteRepository($this->tempDb);
        $newKm = new KeyManager($config, $newRepo);

        $this->assertNull($newKm->validate($tokenStr));
    }

    public function testTokenWithIdIsStoredInRevocationList(): void
    {
        $tokenStr = $this->keyManager->issueToken('ID Test');
        $token = $this->keyManager->validate($tokenStr);
        $this->assertNotEmpty($token->id);

        $this->keyManager->revokeToken($tokenStr);

        $stmt = $this->repository->getDbForTestOnly()->prepare('SELECT token_id FROM revoked_tokens WHERE token_id = ?');
        $stmt->bindValue(1, $token->id, SQLITE3_TEXT);
        $result = $stmt->execute();
        $this->assertNotFalse($result->fetchArray());
    }
}
