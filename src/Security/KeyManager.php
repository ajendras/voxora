<?php

declare(strict_types=1);

namespace Voxora\Security;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * KeyManager
 *
 * Handles issuance and validation of scoped, auto-expiring API tokens.
 * Tokens are self-contained encrypted payloads using Libsodium (sodium_crypto_secretbox).
 * This provides strong cryptographic security without requiring a DB lookup on every request.
 * Revocation can be added later via a token blacklist in the repository.
 */
final class KeyManager
{
    private string $encryptionKey;
    private int $tokenLifetimeDays;

    /**
     * @param string $encryptionKey Master key used to derive the 32-byte secretbox key.
     *                              Should be a strong random secret (32+ characters).
     * @param int $tokenLifetimeDays Default lifetime for issued tokens.
     */
    public function __construct(string $encryptionKey, int $tokenLifetimeDays = 90)
    {
        if (strlen($encryptionKey) < 16) {
            throw new InvalidArgumentException('Encryption key must be at least 16 characters for security.');
        }

        $this->encryptionKey = $this->deriveKey($encryptionKey);
        $this->tokenLifetimeDays = max(1, $tokenLifetimeDays);
    }

    /**
     * Issue a new scoped API token.
     *
     * The token is returned only once. Store it securely on the client side.
     * Audit logging of issuance should be performed by the caller.
     *
     * @param string $name Human-readable name for this token (e.g. "Mobile App Integration")
     * @param array $scopes Permissions granted to this token. Use ['*'] for full access.
     * @return string The token string (prefixed with "vxo_") to be distributed to the user.
     */
    public function issueToken(string $name, array $scopes = ['tts:generate']): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Token name cannot be empty.');
        }

        if (empty($scopes) || !is_array($scopes)) {
            throw new InvalidArgumentException('At least one scope must be provided.');
        }

        $id = bin2hex(random_bytes(12));
        $now = new DateTimeImmutable();
        $expiresAt = $now->modify('+' . $this->tokenLifetimeDays . ' days');

        $payload = [
            'id' => $id,
            'name' => $name,
            'scopes' => array_values($scopes),
            'iat' => $now->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($json, $nonce, $this->encryptionKey);

        $tokenBinary = $nonce . $ciphertext;
        $encoded = base64_encode($tokenBinary);

        return 'vxo_' . $encoded;
    }

    /**
     * Validate a presented token.
     *
     * Decrypts the payload, checks expiry, and returns a Token value object on success.
     *
     * @param string $token The full token (including "vxo_" prefix) from Authorization header.
     * @return Token|null Null if token is malformed, tampered with, or expired.
     */
    public function validate(string $token): ?Token
    {
        if (!is_string($token) || !str_starts_with($token, 'vxo_')) {
            return null;
        }

        $payload = substr($token, 4);
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            return null;
        }

        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($decoded) < $nonceLength + 16) { // minimum ciphertext size
            return null;
        }

        $nonce = substr($decoded, 0, $nonceLength);
        $ciphertext = substr($decoded, $nonceLength);

        $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->encryptionKey);

        if ($decrypted === false) {
            return null; // decryption failure = tampering or wrong key
        }

        try {
            $data = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!isset($data['id'], $data['name'], $data['scopes'], $data['exp'], $data['iat'])) {
            return null;
        }

        $expiresAt = DateTimeImmutable::createFromFormat('U', (string) $data['exp']);
        if ($expiresAt === false || $expiresAt < new DateTimeImmutable()) {
            return null;
        }

        $issuedAt = DateTimeImmutable::createFromFormat('U', (string) $data['iat']) ?: new DateTimeImmutable();

        return new Token(
            $data['id'],
            $data['name'],
            (array) $data['scopes'],
            $issuedAt,
            $expiresAt
        );
    }

    /**
     * Derives a 32-byte key suitable for sodium_crypto_secretbox from the user-provided key.
     */
    private function deriveKey(string $rawKey): string
    {
        if (strlen($rawKey) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $rawKey;
        }

        return sodium_crypto_generichash($rawKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}

/**
 * Token
 *
 * Immutable value object representing a validated API token.
 */
final class Token
{
    /**
     * @param string[] $scopes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $scopes,
        public readonly DateTimeImmutable $issuedAt,
        public readonly DateTimeImmutable $expiresAt
    ) {
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTimeImmutable();
    }

    public function hasScope(string $scope): bool
    {
        if (in_array('*', $this->scopes, true)) {
            return true;
        }

        foreach ($this->scopes as $granted) {
            if ($granted === $scope || str_starts_with($scope . ':', $granted . ':')) {
                return true;
            }
        }

        return false;
    }
}
