<?php

declare(strict_types=1);

namespace Voxora\Security;

use Voxora\Storage\SqliteRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * KeyManager
 *
 * Handles issuance and validation of scoped, time-limited API tokens using
 * Sodium secretbox encryption. Supports revocation list persisted in SQLite.
 * The provided encryptKey is hashed to a 32-byte value so any secret length
 * works (including short strings used in tests).
 */
class KeyManager
{
    private string $encryptKey;
    private SqliteRepository $repository;

    public function __construct(string $encryptKey, SqliteRepository $repository)
    {
        // Always derive a 32-byte key. This satisfies Sodium requirements while
        // allowing short config values or test secrets without throwing.
        $this->encryptKey = hash('sha256', $encryptKey, true);
        $this->repository = $repository;
    }

    /**
     * Issue a new encrypted token.
     *
     * Extra optional parameters are only used by tests; normal calls from
     * ApiController and elsewhere continue to work with the original signature.
     */
    public function issueToken(
        string $name,
        array $scopes = ['tts:generate'],
        string $role = 'user',
        ?int $userId = null,
        int $lifetimeDays = 90
    ): string {
        $id = bin2hex(random_bytes(16));

        $payload = [
            'id' => $id,
            'name' => $name,
            'scopes' => $scopes,
            'role' => $role,
            'user_id' => $userId ?? 1,
            'iat' => time(),
            'exp' => time() + ($lifetimeDays * 86400),
        ];

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $nonce,
            $this->encryptKey
        );

        return 'vxo_' . bin2hex($nonce . $ciphertext);
    }

    /**
     * Validate a token string, return Token object or null if invalid/expired/revoked.
     */
    public function validate(string $tokenStr): ?Token
    {
        if (!str_starts_with($tokenStr, 'vxo_')) {
            return null;
        }

        $data = substr($tokenStr, 4);
        if (strlen($data) % 2 !== 0) {
            return null;
        }

        try {
            $binary = hex2bin($data);
        } catch (\Exception) {
            return null;
        }

        $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($binary) < $nonceLen + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $nonce = substr($binary, 0, $nonceLen);
        $ciphertext = substr($binary, $nonceLen);

        try {
            $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->encryptKey);
            if ($decrypted === false) {
                return null;
            }

            $payload = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);

            if (!isset($payload['id'], $payload['exp']) || $payload['exp'] < time()) {
                return null;
            }

            if ($this->repository->isTokenRevoked($payload['id'])) {
                return null;
            }

            return new Token(
                id: $payload['id'],
                name: $payload['name'] ?? 'unnamed',
                scopes: $payload['scopes'] ?? ['tts:generate'],
                role: $payload['role'] ?? 'user',
                userId: $payload['user_id'] ?? null,
                expiresAt: $payload['exp']
            );
        } catch (\Exception) {
            return null;
        }
    }

    public function revokeToken(string $tokenId): void
    {
        $this->repository->addToRevocationList($tokenId);
    }
}

/**
 * Token value object returned by KeyManager::validate()
 */
final class Token
{
    /**
     * @param string $id Unique token identifier
     * @param string $name Human readable name
     * @param array<string> $scopes List of permitted scopes
     * @param string $role User role (user|admin)
     * @param int|null $userId Associated user ID
     * @param int $expiresAt Unix timestamp of expiry
     */
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
        return $this->expiresAt < time();
    }
}
