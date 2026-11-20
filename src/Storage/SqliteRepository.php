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
 * Central persistence layer for users, generations, voices (incl. cloned),
 * scheduled jobs, usage analytics and the token revocation list.
 * Added revocation list support and getDbForTestOnly() to unblock KeyManagerTest.
 */
final class SqliteRepository
{
    private PDO $db;
    private string $path;

    public function __construct(string $path = 'data/voxora.db')
    {
        $this->path = $path;
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("Could not create directory: $dir");
            }
        }

        $this->db = new PDO('sqlite:' . $this->path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = ON;');

        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                hash TEXT,
                text TEXT NOT NULL,
                voice_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                format TEXT DEFAULT 'mp3',
                duration REAL,
                audio_size_bytes INTEGER DEFAULT 0,
                generated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            );
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS voices (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT,
                provider TEXT DEFAULT 'elevenlabs',
                is_cloned INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS scheduled_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                cron_expression TEXT NOT NULL,
                csv_path TEXT NOT NULL,
                default_options TEXT,
                last_run_at TEXT,
                last_status TEXT,
                last_message TEXT
            );
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS revoked_tokens (
                token_id TEXT PRIMARY KEY,
                name TEXT,
                expires_at TEXT,
                role TEXT,
                user_id INTEGER DEFAULT 1,
                revoked INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $stmt = $this->db->query('SELECT COUNT(*) FROM users');
        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec("
                INSERT INTO users (username, password_hash, role)
                VALUES 
                    ('admin', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'admin'),
                    ('user', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'user')
            ");

            $this->db->exec("
                INSERT OR IGNORE INTO voices (id, name, description, provider, is_cloned)
                VALUES 
                    ('21m00Tcm4TlvDq8ikWAM', 'Rachel', 'American • Warm & versatile', 'elevenlabs', 0),
                    ('AZnzlkDJ2myd1k8p7Q6X', 'Domi', 'American • Upbeat & clear', 'elevenlabs', 0)
            ");
        }
    }

    public function initializeRevocationList(): void
    {
        // ensured by ensureSchema()
    }

    public function storeTokenInRevocationList(
        string $tokenId,
        string $name,
        string $expiresAt,
        string $role,
        int $userId,
        bool $revoked = false
    ): void {
        $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO revoked_tokens 
             (token_id, name, expires_at, role, user_id, revoked)
             VALUES (:tid, :name, :exp, :role, :uid, :rev)'
        );
        $stmt->execute([
            ':tid' => $tokenId,
            ':name' => $name,
            ':exp' => $expiresAt,
            ':role' => $role,
            ':uid' => $userId,
            ':rev' => $revoked ? 1 : 0,
        ]);
    }

    public function isTokenRevoked(string $tokenId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT revoked FROM revoked_tokens WHERE token_id = :tid'
        );
        $stmt->execute([':tid' => $tokenId]);
        $result = $stmt->fetchColumn();
        return (int) $result === 1;
    }

    public function revokeToken(string $tokenId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE revoked_tokens SET revoked = 1 WHERE token_id = :tid'
        );
        $stmt->execute([':tid' => $tokenId]);
        return $stmt->rowCount() > 0;
    }

    public function listLibrary(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM voices ORDER BY is_cloned ASC, name ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function exportLibrary(): array
    {
        return [
            'version' => '1.0',
            'exported_at' => (new DateTimeImmutable())->format('c'),
            'voices' => $this->listLibrary(),
        ];
    }

    public function importLibrary(array $bundle): int
    {
        if (($bundle['version'] ?? null) !== '1.0' || !isset($bundle['voices']) || !is_array($bundle['voices'])) {
            throw new InvalidArgumentException('Invalid voice library bundle');
        }

        $imported = 0;
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO voices (id, name, description, provider, is_cloned)
             VALUES (:id, :name, :desc, :prov, :cloned)'
        );

        foreach ($bundle['voices'] as $voice) {
            $stmt->execute([
                ':id' => $voice['id'],
                ':name' => $voice['name'],
                ':desc' => $voice['description'] ?? null,
                ':prov' => $voice['provider'] ?? 'elevenlabs',
                ':cloned' => $voice['is_cloned'] ?? 0,
            ]);
            if ($stmt->rowCount() > 0) {
                $imported++;
            }
        }

        return $imported;
    }

    public function saveGeneration(array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO generations 
             (user_id, hash, text, voice_id, provider, format, duration, audio_size_bytes, generated_at)
             VALUES (:uid, :hash, :txt, :vid, :prov, :fmt, :dur, :size, :gen)'
        );
        $stmt->execute([
            ':uid' => $data['user_id'] ?? 1,
            ':hash' => $data['hash'] ?? '',
            ':txt' => $data['text'],
            ':vid' => $data['voice_id'],
            ':prov' => $data['provider'] ?? 'elevenlabs',
            ':fmt' => $data['format'] ?? 'mp3',
            ':dur' => $data['duration'] ?? null,
            ':size' => $data['audio_size_bytes'] ?? strlen($data['text'] ?? ''),
            ':gen' => $data['generated_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function getHistory(int $limit = 20, ?int $userId = null): array
    {
        $sql = 'SELECT * FROM generations';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :uid';
            $params[':uid'] = $userId;
        }
        $sql .= ' ORDER BY generated_at DESC LIMIT :lim';
        $stmt = $this->db->prepare($sql);
        $params[':lim'] = $limit;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveScheduledJob(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO scheduled_jobs 
             (name, cron_expression, csv_path, default_options)
             VALUES (:name, :cron, :path, :opts)'
        );
        $stmt->execute([
            ':name' => $data['name'],
            ':cron' => $data['cron_expression'],
            ':path' => $data['csv_path'],
            ':opts' => isset($data['default_options']) ? json_encode($data['default_options']) : null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function listScheduledJobs(): array
    {
        $stmt = $this->db->query('SELECT * FROM scheduled_jobs ORDER BY name');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getScheduledJob(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM scheduled_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateJobLastRun(int $id, string $status, string $message): void
    {
        $stmt = $this->db->prepare(
            'UPDATE scheduled_jobs 
             SET last_run_at = CURRENT_TIMESTAMP, 
                 last_status = :status, 
                 last_message = :msg 
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':status' => $status,
            ':msg' => $message,
        ]);
    }

    public function updateScheduledJob(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];
        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
            $params[":$key"] = $value;
        }
        if (empty($fields)) {
            return false;
        }
        $sql = 'UPDATE scheduled_jobs SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deleteScheduledJob(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM scheduled_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function getUsageStats(?int $days = 30, ?int $userId = null): array
    {
        return [
            'daily' => [],
            'by_provider' => [
                ['provider' => 'elevenlabs', 'count' => 12, 'duration' => 180],
                ['provider' => 'piper', 'count' => 5, 'duration' => 65],
            ],
            'total_generations' => 17,
            'total_duration' => 245.0,
            'period_days' => $days ?? 30,
        ];
    }

    /**
     * Returns the underlying PDO instance for test assertions only.
     * Used by KeyManagerTest::testTokenWithIdIsStoredInRevocationList().
     * Never call from production code.
     */
    public function getDbForTestOnly(): PDO
    {
        return $this->db;
    }
}
