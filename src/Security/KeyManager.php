<?php

declare(strict_types=1);

namespace Voxora\Security;

use Voxora\Storage\SqliteRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use Exception;

/**
 * Token value object returned by validate().
 */
final class Token
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

/**
 * KeyManager
 *
 * Handles issuance and validation of encrypted API tokens using Sodium.
 * Supports scoped access, roles, user binding and revocation list backed by SQLite.
 */
class KeyManager
{
    private string $encryptKey;
    private int $defaultLifetimeDays;
    private SqliteRepository $repository;

    public function __construct(array $config, SqliteRepository $repository)
    {
        if (empty($config['security']['encrypt_key'])) {
            throw new InvalidArgumentException('security.encrypt_key is required');
        }

        $this->encryptKey = $config['security']['encrypt_key'];
        $this->defaultLifetimeDays = $config['security']['token_lifetime_days'] ?? 90;
        $this->repository = $repository;
    }

    public function issueToken(
        string $name,
        array $scopes = ['tts:generate'],
        string $role = 'user',
        ?int $userId = null,
        ?int $lifetimeDays = null
    ): string {
        $id = bin2hex(random_bytes(8));
        $days = $lifetimeDays ?? $this->defaultLifetimeDays;
        $expiresAt = (new DateTimeImmutable())->modify("+{$days} days");

        $payload = [
            'id' => $id,
            'name' => $name,
            'scopes' => $scopes,
            'role' => $role,
            'user_id' => $userId,
            'exp' => $expiresAt->getTimestamp(),
        ];

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->deriveKey();
        $encrypted = sodium_crypto_secretbox($jsonPayload, $nonce, $key);
        $token = 'vxo_' . bin2hex($nonce . $encrypted);

        $this->repository->storeIssuedToken($id, $name, $expiresAt, $role, $userId ?? 0);

        return $token;
    }

    public function validate(string $tokenString): Token
    {
        if (!str_starts_with($tokenString, 'vxo_')) {
            throw new Exception('Invalid token prefix');
        }

        $hex = substr($tokenString, 4);
        if (strlen($hex) % 2 !== 0) {
            throw new Exception('Invalid token');
        }

        $binary = hex2bin($hex);
        $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($binary) < $nonceLen) {
            throw new Exception('Invalid token length');
        }

        $nonce = substr($binary, 0, $nonceLen);
        $ciphertext = substr($binary, $nonceLen);
        $key = $this->deriveKey();
        $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if ($decrypted === false) {
            throw new Exception('Token decryption failed');
        }

        $payload = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($payload['id'], $payload['exp'])) {
            throw new Exception('Malformed token payload');
        }

        $expiresAt = (new DateTimeImmutable())->setTimestamp((int) $payload['exp']);

        if ($expiresAt < new DateTimeImmutable()) {
            throw new Exception('Token has expired');
        }

        if ($this->isTokenRevoked($payload['id'])) {
            throw new Exception('Token has been revoked');
        }

        return new Token(
            id: $payload['id'],
            name: $payload['name'] ?? 'default',
            scopes: $payload['scopes'] ?? ['tts:generate'],
            role: $payload['role'] ?? 'user',
            userId: $payload['user_id'] ?? null,
            expiresAt: $expiresAt
        );
    }

    public function isTokenRevoked(string $tokenId): bool
    {
        return $this->repository->isTokenRevoked($tokenId);
    }

    public function addToRevocationList(string $tokenId): void
    {
        $expiresAt = (new DateTimeImmutable('+90 days'))->format('Y-m-d H:i:s');
        $this->repository->addToRevocationList($tokenId, $expiresAt);
    }

    public function revoke(string $tokenId): void
    {
        $this->addToRevocationList($tokenId);
    }

    private function deriveKey(): string
    {
        return sodium_crypto_generichash($this->encryptKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
