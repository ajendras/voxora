<?php

declare(strict_types=1);

namespace Voxora\Security;

use DateTimeImmutable;
use InvalidArgumentException;
use Voxora\Storage\SqliteRepository;

/**
 * KeyManager
 *
 * Issues and validates encrypted, scoped, time-limited API tokens using libsodium.
 * Tokens are self-contained (no DB lookup until revocation list is added).
 * Prefix is "vxo_". Supports role and userId for RBAC.
 */
class KeyManager
{
    private const TOKEN_PREFIX = 'vxo_';
    private string $secretKey;

    public function __construct(string $masterKey)
    {
        if (strlen($masterKey) < 8) {
            throw new InvalidArgumentException('Master key must be at least 8 characters long.');
        }
        // Derive 32-byte key for sodium_crypto_secretbox
        $this->secretKey = hash('sha256', $masterKey, true);
    }

    /**
     * Issue a new encrypted token.
     */
    public function issueToken(
        string $name,
        array $scopes = ['tts:generate'],
        string $role = 'user',
        ?int $userId = null,
        ?DateTimeImmutable $expiresAt = null
    ): string {
        $expiresAt = $expiresAt ?? new DateTimeImmutable('+90 days');

        $payload = [
            'id' => bin2hex(random_bytes(16)),
            'name' => $name,
            'scopes' => $scopes,
            'role' => $role,
            'user_id' => $userId,
            'iat' => time(),
            'exp' => $expiresAt->getTimestamp(),
        ];

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $encrypted = sodium_crypto_secretbox($json, $nonce, $this->secretKey);
        $data = base64_encode($nonce . $encrypted);

        return self::TOKEN_PREFIX . $data;
    }

    /**
     * Validate a token string and return a Token object or null.
     */
    public function validate(string $tokenString): ?Token
    {
        if (!str_starts_with($tokenString, self::TOKEN_PREFIX)) {
            return null;
        }

        $data = substr($tokenString, strlen(self::TOKEN_PREFIX));
        $decoded = base64_decode($data, true);

        if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->secretKey);
        if ($decrypted === false) {
            return null;
        }

        $payload = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !isset($payload['exp']) || (int) $payload['exp'] < time()) {
            return null;
        }

        return new Token(
            id: $payload['id'] ?? '',
            name: $payload['name'] ?? '',
            scopes: $payload['scopes'] ?? [],
            role: $payload['role'] ?? 'user',
            userId: $payload['user_id'] ?? null,
            expiresAt: (new DateTimeImmutable())->setTimestamp((int) $payload['exp'])
        );
    }
}

/**
 * Token value object.
 */
class Token
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $scopes,
        public readonly string $role,
        public readonly ?int $userId,
        public readonly DateTimeImmutable $expiresAt
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTimeImmutable();
    }
}
