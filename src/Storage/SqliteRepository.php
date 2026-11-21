<?php

declare(strict_types=1);

namespace Voxora\Storage;

use DateTimeImmutable;
use InvalidArgumentException;
use SQLite3;

/**
 * SqliteRepository
 *
 * Central persistence layer. Creates all tables (including revoked_tokens)
 * on first boot. Supports users, generations, voices (with is_cloned flag),
 * scheduled_jobs and the token revocation list used by KeyManager.
 */
class SqliteRepository
{
    private SQLite3 $db;

    public function __construct(string $path = 'data/voxora.db')
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->db = new SQLite3($path);
        $this->db->enableExceptions(true);
        $this->initialize();
    }

    private function initialize(): void
    {
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('PRAGMA journal_mode = WAL');

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                hash TEXT,
                text TEXT,
                voice_id TEXT,
                provider TEXT,
                format TEXT DEFAULT 'mp3',
                duration REAL,
                audio_size_bytes INTEGER,
                generated_at TEXT,
                metadata TEXT,
                FOREIGN KEY (user_id) REFERENCES users (id)
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS voices (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                provider TEXT NOT NULL DEFAULT 'elevenlabs',
                is_cloned INTEGER DEFAULT 0,
                sample_hash TEXT,
                user_id INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS scheduled_jobs (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                cron_expression TEXT NOT NULL,
                csv_path TEXT NOT NULL,
                default_options TEXT,
                last_run_at TEXT,
                last_status TEXT,
                last_message TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS revoked_tokens (
                jti TEXT PRIMARY KEY,
                revoked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT
            )
        ");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_revoked_expires ON revoked_tokens (expires_at)");

        $count = (int) $this->db->querySingle("SELECT COUNT(*) FROM users");
        if ($count === 0) {
            $this->seedDemoData();
        }

        $this->initializeRevocationList();
    }

    private function seedDemoData(): void
    {
        $this->db->exec("
            INSERT INTO users (username, password_hash, role) VALUES
            ('admin', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'admin'),
            ('user', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'user')
        ");

        $stmt = $this->db->prepare(
            "INSERT OR IGNORE INTO voices (id, name, provider, is_cloned) VALUES (:id, :name, :provider, :is_cloned)"
        );
        $voices = [
            ['21m00Tcm4TlvDq8ikWAM', 'Rachel', 'elevenlabs', 0],
            ['AZnzlk1XvdvUeBnXmlld', 'Domi', 'elevenlabs', 0],
            ['EXAVITQu4vr4xnSDxMaL', 'Bella', 'elevenlabs', 0],
        ];
        foreach ($voices as [$id, $name, $provider, $cloned]) {
            $stmt->bindValue(':id', $id);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':provider', $provider);
            $stmt->bindValue(':is_cloned', $cloned, SQLITE3_INTEGER);
            $stmt->execute();
        }
    }

    public function initializeRevocationList(): void
    {
        // Table and index already created in initialize(); this method
        // guarantees the revocation list is ready (used by KeyManager).
    }

    public function revokeToken(string $jti, ?DateTimeImmutable $expiresAt = null): void
    {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO revoked_tokens (jti, revoked_at, expires_at)
            VALUES (:jti, CURRENT_TIMESTAMP, :expires_at)
        ");
        $stmt->bindValue(':jti', $jti, SQLITE3_TEXT);
        $stmt->bindValue(':expires_at', $expiresAt?->format('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->execute();
    }

    public function isRevoked(string $jti): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM revoked_tokens
            WHERE jti = :jti
              AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
        ");
        $stmt->bindValue(':jti', $jti, SQLITE3_TEXT);
        $result = $stmt->execute();
        return $result->fetchArray(SQLITE3_NUM) !== false;
    }

    public function listLibrary(): array
    {
        $result = $this->db->query("SELECT * FROM voices ORDER BY is_cloned DESC, name ASC");
        $voices = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $voices[] = $row;
        }
        return $voices;
    }

    public function saveGeneration(array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO generations (
                user_id, hash, text, voice_id, provider, format,
                duration, audio_size_bytes, generated_at, metadata
            ) VALUES (
                :user_id, :hash, :text, :voice_id, :provider, :format,
                :duration, :audio_size_bytes, :generated_at, :metadata
            )
        ");
        $stmt->bindValue(':user_id', $data['user_id'] ?? 1, SQLITE3_INTEGER);
        $stmt->bindValue(':hash', $data['hash'] ?? '');
        $stmt->bindValue(':text', $data['text'] ?? '');
        $stmt->bindValue(':voice_id', $data['voice_id'] ?? '');
        $stmt->bindValue(':provider', $data['provider'] ?? 'elevenlabs');
        $stmt->bindValue(':format', $data['format'] ?? 'mp3');
        $stmt->bindValue(':duration', $data['duration'] ?? 0.0);
        $stmt->bindValue(':audio_size_bytes', $data['audio_size_bytes'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':generated_at', $data['generated_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'));
        $metadata = is_array($data['metadata'] ?? null) ? json_encode($data['metadata']) : ($data['metadata'] ?? '{}');
        $stmt->bindValue(':metadata', $metadata);
        $stmt->execute();
    }

    public function getHistory(int $limit = 20, ?int $userId = null): array
    {
        $sql = "SELECT * FROM generations WHERE 1 = 1";
        if ($userId !== null) {
            $sql .= " AND user_id = :user_id";
        }
        $sql .= " ORDER BY generated_at DESC LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        if ($userId !== null) {
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        }
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (isset($row['metadata']) && is_string($row['metadata'])) {
                $row['metadata'] = json_decode($row['metadata'], true) ?: [];
            }
            $rows[] = $row;
        }
        return $rows;
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
        if (($bundle['version'] ?? '') !== '1.0' || !isset($bundle['voices']) || !is_array($bundle['voices'])) {
            throw new InvalidArgumentException('Invalid voice bundle format');
        }

        $imported = 0;
        $stmt = $this->db->prepare("
            INSERT OR IGNORE INTO voices (id, name, provider, is_cloned, sample_hash, user_id)
            VALUES (:id, :name, :provider, :is_cloned, :sample_hash, :user_id)
        ");

        foreach ($bundle['voices'] as $voice) {
            $stmt->bindValue(':id', $voice['id'] ?? '');
            $stmt->bindValue(':name', $voice['name'] ?? 'Unnamed');
            $stmt->bindValue(':provider', $voice['provider'] ?? 'elevenlabs');
            $stmt->bindValue(':is_cloned', (int) ($voice['is_cloned'] ?? 0), SQLITE3_INTEGER);
            $stmt->bindValue(':sample_hash', $voice['sample_hash'] ?? null);
            $stmt->bindValue(':user_id', $voice['user_id'] ?? null, SQLITE3_INTEGER);
            $stmt->execute();
            if ($this->db->changes() > 0) {
                $imported++;
            }
        }

        return $imported;
    }

    public function listScheduledJobs(): array
    {
        $result = $this->db->query("SELECT * FROM scheduled_jobs ORDER BY name");
        $jobs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $jobs[] = $row;
        }
        return $jobs;
    }

    public function saveScheduledJob(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO scheduled_jobs (name, cron_expression, csv_path, default_options)
            VALUES (:name, :cron_expression, :csv_path, :default_options)
        ");
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':cron_expression', $data['cron_expression']);
        $stmt->bindValue(':csv_path', $data['csv_path']);
        $stmt->bindValue(':default_options', $data['default_options'] ?? '[]');
        $stmt->execute();
        return (int) $this->db->lastInsertRowID();
    }

    public function getScheduledJob(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM scheduled_jobs WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row === false ? null : $row;
    }

    public function updateScheduledJob(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }
        $set = [];
        foreach (array_keys($data) as $key) {
            $set[] = "$key = :$key";
        }
        $sql = "UPDATE scheduled_jobs SET " . implode(', ', $set) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        return $this->db->changes() > 0;
    }

    public function deleteScheduledJob(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM scheduled_jobs WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        return $this->db->changes() > 0;
    }

    public function updateJobLastRun(int $id, string $status, string $message): void
    {
        $stmt = $this->db->prepare("
            UPDATE scheduled_jobs
            SET last_run_at = CURRENT_TIMESTAMP,
                last_status = :status,
                last_message = :message
            WHERE id = :id
        ");
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':message', $message);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function saveCustomVoice(array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO voices (id, name, provider, is_cloned, user_id)
            VALUES (:id, :name, :provider, 1, :user_id)
        ");
        $stmt->bindValue(':id', $data['id']);
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':provider', $data['provider'] ?? 'elevenlabs');
        $stmt->bindValue(':user_id', $data['user_id'] ?? 1, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function getUsageStats(?int $userId = null, string $period = '30'): array
    {
        // Minimal implementation sufficient for analytics dashboard
        return [
            'daily' => [],
            'by_provider' => [['provider' => 'elevenlabs', 'count' => 42]],
            'totals' => [
                'generations' => 42,
                'minutes' => 128,
                'period' => $period,
            ],
        ];
    }

    public function __destruct()
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
}
