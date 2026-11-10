<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\Security\KeyManager;
use Voxora\Security\Token;
use DateTimeImmutable;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Unit tests for KeyManager.
 *
 * Covers token issuance, validation, expiry checks, encryption round-trips,
 * scope enforcement, and error conditions.
 */
final class KeyManagerTest extends TestCase
{
    private string $testEncryptionKey = 'this-is-a-strong-test-master-key-32bytes-long!!';

    public function testCanIssueAndValidateToken(): void
    {
        $manager = new KeyManager($this->testEncryptionKey, 90);
        $name = 'Test Dashboard Token';
        $scopes = ['tts:generate', 'voice:read'];

        $tokenString = $manager->issueToken($name, $scopes);

        $this->assertIsString($tokenString);
        $this->assertStringStartsWith('vxo_', $tokenString);
        $this->assertGreaterThan(60, strlen($tokenString));

        $token = $manager->validate($tokenString);

        $this->assertInstanceOf(Token::class, $token);
        $this->assertSame($name, $token->name);
        $this->assertSame($scopes, $token->scopes);
        $this->assertFalse($token->isExpired());
        $this->assertTrue($token->hasScope('tts:generate'));
    }

    public function testEncryptionRoundTripPreservesAllFields(): void
    {
        $manager = new KeyManager($this->testEncryptionKey, 30);
        $originalScopes = ['tts:generate', 'tts:batch', '*'];
        $tokenString = $manager->issueToken('RoundTrip Test', $originalScopes);

        $validated = $manager->validate($tokenString);

        $this->assertNotNull($validated);
        $this->assertSame('RoundTrip Test', $validated->name);
        $this->assertSame($originalScopes, $validated->scopes);
        $this->assertInstanceOf(DateTimeImmutable::class, $validated->issuedAt);
        $this->assertInstanceOf(DateTimeImmutable::class, $validated->expiresAt);
    }

    public function testExpiredTokenReturnsNull(): void
    {
        $manager = new KeyManager($this->testEncryptionKey, 1);
        $encryptionKey = $this->getPrivateEncryptionKey($manager);

        // Manually craft an expired payload (simulates a token issued in the past)
        $payload = [
            'id' => bin2hex(random_bytes(12)),
            'name' => 'Expired Token',
            'scopes' => ['tts:generate'],
            'iat' => time() - 86400,
            'exp' => time() - 3600, // expired one hour ago
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($json, $nonce, $encryptionKey);
        $tokenBinary = $nonce . $ciphertext;
        $encoded = base64_encode($tokenBinary);
        $expiredTokenString = 'vxo_' . $encoded;

        $result = $manager->validate($expiredTokenString);
        $this->assertNull($result);
    }

    public function testMalformedTokenReturnsNull(): void
    {
        $manager = new KeyManager($this->testEncryptionKey, 90);

        $this->assertNull($manager->validate('not-a-token'));
        $this->assertNull($manager->validate('vxo_'));
        $this->assertNull($manager->validate('vxo_invalidbase64!@#'));
        $this->assertNull($manager->validate('vxo_' . base64_encode('too-short')));
    }

    public function testTamperedTokenReturnsNull(): void
    {
        $manager = new KeyManager($this->testEncryptionKey, 90);
        $tokenString = $manager->issueToken('Tamper Test', ['tts:generate']);

        // Alter the token slightly to simulate tampering
        $tampered = substr($tokenString, 0, -4) . 'XXXX';
        $result = $manager->validate($tampered);

        $this->assertNull($result);
    }

    public function testTokenWithWrongKeyReturnsNull(): void
    {
        $manager1 = new KeyManager($this->testEncryptionKey, 90);
        $tokenString = $manager1->issueToken('CrossKey Test', ['*']);

        $manager2 = new KeyManager('completely-different-master-key-for-testing-123', 90);
        $result = $manager2->validate($tokenString);

        $this->assertNull($result);
    }

    public function testScopeEnforcement(): void
    {
        $manager = new KeyManager($this->testEncryptionKey, 1);
        $tokenString = $manager->issueToken('Scoped Token', ['tts:generate', 'voice:list']);

        $token = $manager->validate($tokenString);
        $this->assertNotNull($token);

        $this->assertTrue($token->hasScope('tts:generate'));
        $this->assertTrue($token->hasScope('voice:list'));
        $this->assertFalse($token->hasScope('admin:delete'));
        $this->assertFalse($token->hasScope('tts:batch'));
    }

    public function testWildcardScopeGrantsEverything(): void
    {
        $manager = new KeyManager($this->testEncryptionKey, 1);
        $tokenString = $manager->issueToken('Admin Token', ['*']);

        $token = $manager->validate($tokenString);
        $this->assertNotNull($token);

        $this->assertTrue($token->hasScope('tts:generate'));
        $this->assertTrue($token->hasScope('voice:manage'));
        $this->assertTrue($token->hasScope('any:permission'));
        $this->assertTrue($token->hasScope('*'));
    }

    public function testIssueTokenThrowsOnEmptyName(): void
    {
        $manager = new KeyManager($this->testEncryptionKey, 90);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Token name cannot be empty');

        $manager->issueToken('');
    }

    public function testIssueTokenThrowsOnEmptyScopes(): void
    {
        $manager = new KeyManager($this->testEncryptionKey, 90);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one scope must be provided');

        $manager->issueToken('No Scope Token', []);
    }

    public function testConstructorRejectsWeakKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Encryption key must be at least 16 characters');

        new KeyManager('too-short');
    }

    public function testTokenValueObject(): void
    {
        $issued = new DateTimeImmutable('-1 hour');
        $expires = new DateTimeImmutable('+30 days');

        $token = new Token(
            'abc123',
            'Test Token Object',
            ['tts:generate', 'read'],
            $issued,
            $expires
        );

        $this->assertSame('abc123', $token->id);
        $this->assertSame('Test Token Object', $token->name);
        $this->assertSame(['tts:generate', 'read'], $token->scopes);
        $this->assertSame($issued, $token->issuedAt);
        $this->assertSame($expires, $token->expiresAt);
        $this->assertFalse($token->isExpired());
        $this->assertTrue($token->hasScope('tts:generate'));
        $this->assertFalse($token->hasScope('write'));
    }

    public function testTokenIsExpired(): void
    {
        $issued = new DateTimeImmutable('-2 hours');
        $expired = new DateTimeImmutable('-1 hour');

        $token = new Token(
            'xyz789',
            'Expired Token',
            ['*'],
            $issued,
            $expired
        );

        $this->assertTrue($token->isExpired());
    }

    /**
     * Helper to access the derived encryption key for test purposes only.
     */
    private function getPrivateEncryptionKey(KeyManager $manager): string
    {
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('deriveKey');
        $method->setAccessible(true);

        return $method->invoke($manager, $this->testEncryptionKey);
    }
}
