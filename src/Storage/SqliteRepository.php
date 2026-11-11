<?php

declare(strict_types=1);

namespace Voxora\Storage;

use PDO;
use PDOException;
use RuntimeException;
use InvalidArgumentException;
use DateTimeImmutable;

/**
 * SqliteRepository
 *
 * Handles all persistence using a single SQLite file.
 * Supports users with roles, generation history (with audio caching for fallback),
 * voice library, usage stats and Piper voice metadata.
 */
final class SqliteRepository
{
    private PDO $pdo;
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Could not create storage directory.');
            }
        }

        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function initialize(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY,
                hash TEXT UNIQUE NOT NULL,
                user_id INTEGER NOT NULL,
                text TEXT NOT NULL,
                voice_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                format TEXT NOT NULL,
                duration REAL,
                metadata TEXT,
                audio_content BLOB,
                audio_size_bytes INTEGER,
                generated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            );
        ");

        // Insert default users if none exist
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count === 0) {
            $this->pdo->exec("
                INSERT INTO users (username, password_hash, role) VALUES 
                ('admin', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'admin'),
                ('user', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'user');
            ");
        }
    }

    public function authenticate(string $username, string $password): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, role, password_hash FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            unset($user['password_hash']);
            return $user;
        }

        return null;
    }

    public function listLibrary(): array
    {
        return [
            ['id' => '21m00Tcm4TlvDq8ikWAM', 'name' => 'Rachel', 'description' => 'American • Warm & versatile', 'provider' => 'elevenlabs'],
            ['id' => 'AZnzlkDJ2myd1k8p7Q6X', 'name' => 'Domi', 'description' => 'American • Upbeat & clear', 'provider' => 'elevenlabs'],
            ['id' => 'pNInz6obpgDQGcFmaJgB', 'name' => 'Adam', 'description' => 'American • Deep & authoritative', 'provider' => 'elevenlabs'],
            ['id' => 'EXAVITQu4vr4xnSDxMaL', 'name' => 'Bella', 'description' => 'American • Friendly & expressive', 'provider' => 'elevenlabs'],
            ['id' => 'local-lessac', 'name' => 'Lessac (Local)', 'description' => 'Piper • Offline high-quality', 'provider' => 'piper'],
        ];
    }

    public function getHistory(int $limit = 20, ?int $userId = null): array
    {
        $sql = 'SELECT * FROM generations WHERE 1=1';
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY generated_at DESC LIMIT ?';
        $params[] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUsageStats(?int $userId = null): array
    {
        $sql = 'SELECT COUNT(*) as generations, SUM(audio_size_bytes) as bytes, SUM(duration) as hours FROM generations';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE user_id = ?';
            $params[] = $userId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'generations' => (int) ($stats['generations'] ?? 0),
            'cost' => round(((int) ($stats['bytes'] ?? 0)) / 100000, 2),
            'hours' => round(((float) ($stats['hours'] ?? 0)) / 3600, 1),
        ];
    }

    public function saveGeneration(array $data): void
    {
        $sql = 'INSERT INTO generations (
                    hash, user_id, text, voice_id, provider, format, 
                    duration, metadata, audio_content, audio_size_bytes, generated_at
                ) VALUES (
                    :hash, :user_id, :text, :voice_id, :provider, :format, 
                    :duration, :metadata, :audio_content, :audio_size_bytes, :generated_at
                )';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'hash' => $data['hash'] ?? hash('sha256', ($data['text'] ?? '') . ($data['voice_id'] ?? '')),
            'user_id' => $data['user_id'] ?? 1,
            'text' => $data['text'] ?? '',
            'voice_id' => $data['voice_id'] ?? '',
            'provider' => $data['provider'] ?? 'elevenlabs',
            'format' => $data['format'] ?? 'mp3',
            'duration' => $data['duration'] ?? null,
            'metadata' => is_array($data['metadata'] ?? null) ? json_encode($data['metadata']) : ($data['metadata'] ?? null),
            'audio_content' => $data['audio_content'] ?? null,
            'audio_size_bytes' => $data['audio_size_bytes'] ?? strlen($data['audio_content'] ?? ''),
            'generated_at' => $data['generated_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function getCachedSynthesis(string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT audio_content, format, duration, metadata 
             FROM generations 
             WHERE hash = ? AND audio_content IS NOT NULL 
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['audio_content'] !== null) {
            $row['metadata'] = $row['metadata'] ? json_decode($row['metadata'], true) : [];
            return $row;
        }

        return null;
    }
}
