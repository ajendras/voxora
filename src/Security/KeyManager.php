<?php

declare(strict_types=1);

namespace Voxora\Security;

use Voxora\Storage\SqliteRepository;
use DateTimeImmutable;
use RuntimeException;

/**
 * KeyManager
 *
 * Handles issuance and validation of scoped, encrypted, time-limited API tokens using Sodium.
 * Supports revocation list persisted in SQLite. Tokens carry role and userId for RBAC.
 */
final class KeyManager
{
    private string $encryptKey;
    private int $tokenLifetimeDays;
    private SqliteRepository $repository;

    public function __construct(array $config, SqliteRepository $repository)
    {
        if (empty($config['security']['encrypt_key'] ?? '')) {
            throw new RuntimeException('security.encrypt_key is required');
        }
        $this->encryptKey = $config['security']['encrypt_key'];
        $this->tokenLifetimeDays = $config['security']['token_lifetime_days'] ?? 90;
        $this->repository = $repository;
    }

    public function issueToken(
        string $name,
        array $scopes = ['tts:generate'],
        string $role = 'user',
        ?int $userId = null,
        ?int $expiresInDays = null
    ): string {
        $expiresAt = (new DateTimeImmutable())->modify('+' . ($expiresInDays ?? $this->tokenLifetimeDays) . ' days');
        $payload = [
            'id' => bin2hex(random_bytes(8)),
            'name' => $name,
            'scopes' => $scopes,
            'role' => $role,
            'user_id' => $userId,
            'iat' => time(),
            'exp' => $expiresAt->getTimestamp(),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = sodium_crypto_generichash($this->encryptKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $ciphertext = sodium_crypto_secretbox($json, $nonce, $key);
        $token = 'vxo_' . bin2hex($nonce . $ciphertext);

        $this->repository->saveToken(
            $payload['id'],
            $name,
            $role,
            $userId,
            $expiresAt,
            $scopes
        );

        return $token;
    }

    public function validate(string $tokenStr): ?Token
    {
        if (!str_starts_with($tokenStr, 'vxo_')) {
            return null;
        }

        $data = hex2bin(substr($tokenStr, 4));
        if ($data === false || strlen($data) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = sodium_crypto_generichash($this->encryptKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if ($decrypted === false) {
            return null;
        }

        $payload = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
            return null;
        }

        $tokenId = $payload['id'] ?? '';
        if ($this->repository->isTokenRevoked($tokenId)) {
            return null;
        }

        $user = null;
        if (!empty($payload['user_id'])) {
            $user = $this->repository->getUserById((int) $payload['user_id']);
        }

        return new Token(
            id: $payload['id'] ?? '',
            name: $payload['name'] ?? 'unnamed',
            scopes: $payload['scopes'] ?? [],
            role: $payload['role'] ?? 'user',
            userId: $payload['user_id'] ?? null,
            expiresAt: (new DateTimeImmutable())->setTimestamp((int) $payload['exp']),
            userData: $user
        );
    }

    public function revokeToken(string $tokenId): bool
    {
        return $this->repository->revokeToken($tokenId);
    }
}

/**
 * Token value object (no longer final to allow test doubles).
 */
class Token
{
    /**
     * @param string[] $scopes
     * @param array<string, mixed>|null $userData
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $scopes,
        public readonly string $role,
        public readonly ?int $userId,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?array $userData = null
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true) || in_array('*', $this->scopes, true);
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTimeImmutable();
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getRole(): string
    {
        return $this->role;
    }
}
