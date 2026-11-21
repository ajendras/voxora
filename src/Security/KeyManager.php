<?php

declare(strict_types=1);

namespace Voxora\Security;

use Voxora\Storage\SqliteRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use Exception;

/**
 * KeyManager
 *
 * Issues Sodium-encrypted scoped tokens. Validates signature, expiry and
 * revocation status via the SqliteRepository revocation list.
 */
final class KeyManager
{
    private SqliteRepository $repository;
    private string $encryptKey;
    private int $lifetimeDays;

    public function __construct(
        SqliteRepository $repository,
        string $encryptKey,
        int $lifetimeDays = 90
    ) {
        if (strlen($encryptKey) < 32) {
            throw new InvalidArgumentException('encryptKey must be at least 32 bytes');
        }
        $this->repository = $repository;
        $this->encryptKey = $encryptKey;
        $this->lifetimeDays = $lifetimeDays;

        $this->repository->initializeRevocationList();
    }

    public function issueToken(
        string $name,
        array $scopes = ['tts:generate'],
        string $role = 'user'
    ): string {
        if (empty($name)) {
            throw new InvalidArgumentException('Token name cannot be empty');
        }

        $jti = bin2hex(random_bytes(16));
        $payload = [
            'id' => $jti,
            'name' => $name,
            'scopes' => $scopes,
            'role' => $role,
            'user_id' => 1,
            'iat' => time(),
            'exp' => time() + ($this->lifetimeDays * 86400),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->deriveKey();
        $cipher = sodium_crypto_secretbox($json, $nonce, $key);

        return 'vxo_' . bin2hex($nonce . $cipher);
    }

    public function validate(string $tokenStr): ?Token
    {
        if (!str_starts_with($tokenStr, 'vxo_')) {
            return null;
        }

        try {
            $data = hex2bin(substr($tokenStr, 4));
            if ($data === false) {
                return null;
            }

            $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $key = $this->deriveKey();

            $decrypted = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            if ($decrypted === false) {
                return null;
            }

            $payload = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);

            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return null;
            }

            $jti = $payload['id'] ?? null;
            if ($jti !== null && $this->repository->isRevoked($jti)) {
                return null;
            }

            return new Token(
                id: $jti,
                name: $payload['name'] ?? '',
                scopes: $payload['scopes'] ?? [],
                role: $payload['role'] ?? 'user',
                userId: $payload['user_id'] ?? null,
                expiresAt: isset($payload['exp'])
                    ? (new DateTimeImmutable())->setTimestamp($payload['exp'])
                    : null
            );
        } catch (Exception) {
            return null;
        }
    }

    public function revoke(string $tokenStr): bool
    {
        $token = $this->validate($tokenStr);
        if ($token === null || $token->id === null) {
            return false;
        }

        $this->repository->revokeToken($token->id, $token->expiresAt);
        return true;
    }

    private function deriveKey(): string
    {
        return sodium_crypto_generichash(
            $this->encryptKey,
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
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
        public readonly ?string $id,
        public readonly string $name,
        public readonly array $scopes,
        public readonly string $role,
        public readonly ?int $userId,
        public readonly ?DateTimeImmutable $expiresAt
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
