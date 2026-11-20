<?php

declare(strict_types=1);

namespace Voxora\Security;

use DateTimeImmutable;
use InvalidArgumentException;
use SodiumException;

/**
 * Token value object carrying all claims.
 */
final class Token
{
    public function __construct(
        public readonly string $value,
        public readonly string $name,
        public readonly array $scopes,
        public readonly string $role,
        public readonly ?int $userId,
        public readonly DateTimeImmutable $expiresAt
    ) {
    }

    public function hasScope(string $scope): bool
    {
        if (in_array('*', $this->scopes, true)) {
            return true;
        }

        return in_array($scope, $this->scopes, true);
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new DateTimeImmutable();
    }
}

/**
 * KeyManager
 *
 * Issues, encrypts and validates scoped expiring tokens using libsodium secretbox.
 * Supports roles, userId binding, wildcard scopes and revocation-ready design.
 */
final class KeyManager
{
    private string $masterKey;
    private int $defaultLifetimeDays;

    public function __construct(string $masterKey, int $defaultLifetimeDays = 90)
    {
        if (strlen($masterKey) < 16) {
            throw new InvalidArgumentException('Master key must be at least 16 characters long to prevent weak keys.');
        }

        $this->masterKey = $masterKey;
        $this->defaultLifetimeDays = $defaultLifetimeDays;
    }

    public function issueToken(string $name, array $scopes, string $role = 'user'): string
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Token name cannot be empty.');
        }

        if (empty($scopes)) {
            throw new InvalidArgumentException('At least one scope must be provided.');
        }

        $expiresAt = new DateTimeImmutable('+' . $this->defaultLifetimeDays . ' days');
        $value = 'vxo_' . bin2hex(random_bytes(16));

        $token = new Token($value, $name, $scopes, $role, 1, $expiresAt);

        return $this->encryptToken($token);
    }

    public function validate(string $tokenString): ?Token
    {
        try {
            $payload = $this->decryptToken($tokenString);

            if ($payload === null) {
                return null;
            }

            $expiresAt = new DateTimeImmutable($payload['expires_at']);

            $token = new Token(
                $payload['value'],
                $payload['name'],
                $payload['scopes'],
                $payload['role'],
                $payload['user_id'] ?? null,
                $expiresAt
            );

            if ($token->isExpired()) {
                return null;
            }

            return $token;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function encryptToken(Token $token): string
    {
        $data = [
            'value' => $token->value,
            'name' => $token->name,
            'scopes' => $token->scopes,
            'role' => $token->role,
            'user_id' => $token->userId,
            'expires_at' => $token->expiresAt->format(DateTimeImmutable::ATOM),
        ];

        $json = json_encode($data, JSON_THROW_ON_ERROR);

        $key = $this->deriveKey('token-encryption');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($json, $nonce, $key);

        return 'vxo_' . bin2hex($nonce . $cipher);
    }

    private function decryptToken(string $tokenString): ?array
    {
        if (!str_starts_with($tokenString, 'vxo_')) {
            return null;
        }

        $hex = substr($tokenString, 4);
        $binary = hex2bin($hex);

        if ($binary === false) {
            return null;
        }

        $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($binary) < $nonceLen) {
            return null;
        }

        $nonce = substr($binary, 0, $nonceLen);
        $cipher = substr($binary, $nonceLen);

        $key = $this->deriveKey('token-encryption');

        try {
            $decrypted = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            if ($decrypted === false) {
                return null;
            }

            $data = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (SodiumException | \JsonException $e) {
            return null;
        }
    }

    private function deriveKey(string $context): string
    {
        $salt = $context . $this->masterKey;
        return substr(hash('sha256', $salt, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
