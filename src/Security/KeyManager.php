<?php

declare(strict_types=1);

namespace Voxora\Security;

use Voxora\Storage\SqliteRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Token value object returned by KeyManager::validate().
 */
final class Token
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $scopes,
        public readonly string $role,
        public readonly ?int $userId,
        public readonly DateTimeImmutable $expiresAt,
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}

/**
 * KeyManager
 *
 * Issues and validates encrypted, scoped, time-limited tokens using libsodium.
 * Maintains a revocation list persisted via SqliteRepository.
 * No longer final (was blocking PHPUnit mocks in ApiControllerTest).
 */
class KeyManager
{
    private SqliteRepository $repository;
    private string $encryptKey;
    private int $defaultLifetimeDays;

    public function __construct(SqliteRepository $repository, array $config)
    {
        $this->repository = $repository;

        if (empty($config['security']['encrypt_key'])) {
            throw new InvalidArgumentException('security.encrypt_key is required');
        }

        $this->encryptKey = $config['security']['encrypt_key'];
        $this->defaultLifetimeDays = (int) ($config['security']['token_lifetime_days'] ?? 90);

        $this->repository->initializeRevocationList();
    }

    public function issueToken(
        string $name,
        array $scopes = ['tts:generate'],
        string $role = 'user',
        ?int $userId = null
    ): string {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Token name cannot be empty');
        }

        $id = bin2hex(random_bytes(8));
        $expiresAt = (new DateTimeImmutable())->modify("+{$this->defaultLifetimeDays} days");

        $payload = [
            'id' => $id,
            'name' => $name,
            'scopes' => $scopes,
            'role' => $role,
            'user_id' => $userId,
            'exp' => $expiresAt->getTimestamp(),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->deriveKey();
        $encrypted = sodium_crypto_secretbox($json, $nonce, $key);
        $token = 'vxo_' . bin2hex($nonce . $encrypted);

        $this->repository->storeTokenInRevocationList(
            $id,
            $name,
            $expiresAt->format('Y-m-d H:i:s'),
            $role,
            $userId ?? 1,
            false
        );

        return $token;
    }

    public function validate(string $tokenStr): ?Token
    {
        if (!str_starts_with($tokenStr, 'vxo_')) {
            return null;
        }

        try {
            $data = hex2bin(substr($tokenStr, 4));
            if ($data === false || strlen($data) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
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
            if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
                return null;
            }

            $id = $payload['id'] ?? '';
            if ($this->repository->isTokenRevoked($id)) {
                return null;
            }

            return new Token(
                id: $id,
                name: $payload['name'] ?? 'unknown',
                scopes: $payload['scopes'] ?? ['tts:generate'],
                role: $payload['role'] ?? 'user',
                userId: $payload['user_id'] ?? null,
                expiresAt: (new DateTimeImmutable())->setTimestamp((int) $payload['exp'])
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function deriveKey(): string
    {
        return sodium_crypto_generichash($this->encryptKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function revokeToken(string $id): void
    {
        $this->repository->revokeToken($id);
    }
}
