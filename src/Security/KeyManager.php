<?php

declare(strict_types=1);

namespace Voxora\Security;

use Voxora\Storage\SqliteRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * KeyManager
 *
 * Handles secure token issuance and validation using Sodium for encryption.
 * Tokens are scoped, role-aware, auto-expiring. Now includes a revocation
 * list persisted in SQLite. Revoked tokens are immediately rejected by validate()
 * even if they have not yet expired.
 */
final class KeyManager
{
    private string $masterKey;
    private int $defaultLifetimeDays;
    private SqliteRepository $repository;

    public function __construct(
        array $config,
        SqliteRepository $repository
    ) {
        $this->masterKey = $config['security']['encrypt_key'] ?? throw new InvalidArgumentException('security.encrypt_key is required');
        if (strlen($this->masterKey) < 16) {
            throw new InvalidArgumentException('Master key must be at least 16 characters.');
        }
        $this->defaultLifetimeDays = (int) ($config['security']['token_lifetime_days'] ?? 90);
        $this->repository = $repository;
    }

    public function issueToken(
        string $name,
        array $scopes = ['tts:generate'],
        string $role = 'user',
        ?int $userId = 1
    ): string {
        if (empty($name)) {
            throw new InvalidArgumentException('Token name cannot be empty');
        }

        $tokenId = 'tk_' . bin2hex(random_bytes(16));
        $expiresAt = (new DateTimeImmutable())->modify("+{$this->defaultLifetimeDays} days");

        $payload = [
            'id' => $tokenId,
            'name' => $name,
            'scopes' => $scopes,
            'role' => $role,
            'user_id' => $userId,
            'iat' => time(),
            'exp' => $expiresAt->getTimestamp(),
        ];

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->deriveKey();
        $ciphertext = sodium_crypto_secretbox($jsonPayload, $nonce, $key);
        $tokenData = $nonce . $ciphertext;
        $tokenString = 'vxo_' . base64_encode($tokenData);

        sodium_memzero($key);
        return $tokenString;
    }

    public function validate(string $tokenString): ?Token
    {
        $payload = $this->decryptToken($tokenString);
        if ($payload === null) {
            return null;
        }

        if (isset($payload['exp']) && time() > $payload['exp']) {
            return null;
        }

        $tokenId = $payload['id'] ?? '';
        if ($this->repository->isTokenRevoked($tokenId)) {
            return null;
        }

        return new Token(
            id: $tokenId,
            name: $payload['name'] ?? '',
            scopes: $payload['scopes'] ?? [],
            role: $payload['role'] ?? 'user',
            userId: $payload['user_id'] ?? null,
            expiresAt: $payload['exp'] ?? 0
        );
    }

    public function revokeToken(string $tokenString): bool
    {
        $payload = $this->decryptToken($tokenString);
        if ($payload === null || !isset($payload['id']) || !isset($payload['exp'])) {
            return false;
        }

        $expiresAt = (new DateTimeImmutable('@' . $payload['exp']))->format('Y-m-d H:i:s');
        return $this->repository->revokeToken($payload['id'], $expiresAt);
    }

    private function decryptToken(string $tokenString): ?array
    {
        if (!str_starts_with($tokenString, 'vxo_')) {
            return null;
        }

        $encoded = substr($tokenString, 4);
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->deriveKey();
        $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        sodium_memzero($key);

        if ($decrypted === false) {
            return null;
        }

        try {
            $payload = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                return null;
            }
            return $payload;
        } catch (\JsonException) {
            return null;
        }
    }

    private function deriveKey(): string
    {
        return sodium_crypto_generichash($this->masterKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}

/**
 * Token
 *
 * Immutable value object returned by KeyManager::validate().
 */
final class Token
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $scopes,
        public readonly string $role,
        public readonly ?int $userId,
        public readonly int $expiresAt
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function isExpired(): bool
    {
        return time() > $this->expiresAt;
    }
}
