<?php

declare(strict_types=1);

namespace Voxora\Storage;

use PDO;
use PDOException;
use RuntimeException;
use DateTimeImmutable;

/**
 * SqliteRepository
 *
 * Handles all persistence for users, tokens (with revocation), generations,
 * voices (including cloned), scheduled jobs, caching and analytics.
 * Uses in-memory :memory: DB for tests.
 */
final class SqliteRepository
{
    private PDO $pdo;

    public function __construct(string $dsn = 'sqlite::memory:')
    {
        $this->pdo = new PDO($dsn);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT DEFAULT "user",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS tokens (
                id INTEGER PRIMARY KEY,
                token_hash TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                scopes TEXT NOT NULL,
                role TEXT DEFAULT "user",
                user_id INTEGER,
                issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME,
                revoked_at DATETIME DEFAULT NULL
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                token_id INTEGER,
                text TEXT NOT NULL,
                voice_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                duration REAL,
                audio_path TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS voices (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                provider TEXT NOT NULL,
                is_cloned INTEGER DEFAULT 0,
                metadata TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS scheduled_jobs (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                cron_expression TEXT NOT NULL,
                csv_path TEXT NOT NULL,
                default_options TEXT,
                last_run_at DATETIME,
                last_status TEXT,
                last_message TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS synthesis_cache (
                cache_key TEXT PRIMARY KEY,
                audio BLOB NOT NULL,
                format TEXT NOT NULL,
                duration REAL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    public function saveToken(array $data): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO tokens (token_hash, name, scopes, role, user_id, expires_at)
            VALUES (:hash, :name, :scopes, :role, :user_id, :expires_at)
        ');
        $stmt->execute([
            'hash' => $data['hash'],
            'name' => $data['name'],
            'scopes' => json_encode($data['scopes']),
            'role' => $data['role'] ?? 'user',
            'user_id' => $data['user_id'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);
    }

    public function findTokenByHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM tokens WHERE token_hash = :hash
        ');
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function isTokenRevoked(string $hash): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT revoked_at FROM tokens WHERE token_hash = :hash
        ');
        $stmt->execute(['hash' => $hash]);
        $revokedAt = $stmt->fetchColumn();
        return $revokedAt !== false && $revokedAt !== null;
    }

    public function revokeToken(string $hash): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE tokens SET revoked_at = CURRENT_TIMESTAMP WHERE token_hash = :hash
        ');
        return $stmt->execute(['hash' => $hash]);
    }

    public function recordUsage(string $text, string $voiceId, string $provider, float $duration): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO generations (text, voice_id, provider, duration)
            VALUES (:text, :voice, :provider, :duration)
        ');
        $stmt->execute([
            'text' => $text,
            'voice' => $voiceId,
            'provider' => $provider,
            'duration' => $duration,
        ]);
    }

    public function getHistory(array $params = []): array
    {
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 15);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare('
            SELECT * FROM generations
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ');
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cacheSynthesis(string $key, SynthesisResult $result): void
    {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO synthesis_cache (cache_key, audio, format, duration)
            VALUES (:key, :audio, :format, :duration)
        ');
        $stmt->execute([
            'key' => $key,
            'audio' => $result->audio,
            'format' => $result->format,
            'duration' => $result->duration,
        ]);
    }

    public function getCachedSynthesis(string $key): ?SynthesisResult
    {
        $stmt = $this->pdo->prepare('
            SELECT audio, format, duration FROM synthesis_cache WHERE cache_key = :key
        ');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return new SynthesisResult($row['audio'], $row['format'], (float) $row['duration']);
    }

    public function saveCustomVoice(string $name, string $voiceId, bool $isCloned, string $provider): void
    {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO voices (id, name, provider, is_cloned, metadata)
            VALUES (:id, :name, :provider, :cloned, :meta)
        ');
        $stmt->execute([
            'id' => $voiceId,
            'name' => $name,
            'provider' => $provider,
            'cloned' => $isCloned ? 1 : 0,
            'meta' => json_encode([]),
        ]);
    }

    public function listLibrary(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM voices ORDER BY created_at DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listScheduledJobs(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM scheduled_jobs ORDER BY created_at DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveScheduledJob(array $data): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO scheduled_jobs (name, cron_expression, csv_path, default_options)
            VALUES (:name, :cron, :csv, :options)
        ');
        $stmt->execute([
            'name' => $data['name'],
            'cron' => $data['cron_expression'],
            'csv' => $data['csv_path'],
            'options' => $data['default_options'] ?? '[]',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateJobLastRun(int $id, string $status, string $message): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE scheduled_jobs
            SET last_run_at = CURRENT_TIMESTAMP, last_status = :status, last_message = :message
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'message' => $message,
        ]);
    }

    public function getUsageStats(): array
    {
        $stmt = $this->pdo->query('
            SELECT provider, COUNT(*) as count, SUM(duration) as total_duration
            FROM generations GROUP BY provider
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getScheduledJob(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scheduled_jobs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateScheduledJob(int $id, array $data): bool
    {
        $sets = [];
        $params = ['id' => $id];
        foreach ($data as $key => $value) {
            $sets[] = "$key = :$key";
            $params[$key] = $value;
        }
        if (empty($sets)) {
            return false;
        }
        $sql = 'UPDATE scheduled_jobs SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function deleteScheduledJob(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM scheduled_jobs WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public function importLibrary(array $voices): int
    {
        $imported = 0;
        foreach ($voices as $voice) {
            try {
                $this->saveCustomVoice(
                    $voice['name'] ?? 'Imported',
                    $voice['id'],
                    $voice['is_cloned'] ?? false,
                    $voice['provider'] ?? 'elevenlabs'
                );
                $imported++;
            } catch (PDOException $e) {
                // duplicate, skip
            }
        }
        return $imported;
    }
}
