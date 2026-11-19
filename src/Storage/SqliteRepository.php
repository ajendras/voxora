<?php

declare(strict_types=1);

namespace Voxora\Storage;

use DateTimeImmutable;
use RuntimeException;

/**
 * SqliteRepository
 *
 * Central persistence for users, generations, voices, scheduled_jobs and analytics.
 * Automatically creates all tables (including scheduled_jobs) on first use and seeds demo data.
 */
final class SqliteRepository
{
    private \SQLite3 $db;

    public function __construct(string $path = 'data/voxora.db')
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("Could not create storage directory: $dir");
            }
        }

        $this->db = new \SQLite3($path);
        $this->db->enableExceptions(true);
        $this->createTables();
    }

    private function createTables(): void
    {
        $this->db->exec('PRAGMA foreign_keys = ON');

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                hash TEXT,
                text TEXT,
                voice_id TEXT,
                provider TEXT,
                format TEXT DEFAULT 'mp3',
                duration REAL,
                audio_size_bytes INTEGER,
                metadata TEXT,
                generated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS voices (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                provider TEXT NOT NULL DEFAULT 'elevenlabs',
                is_cloned INTEGER DEFAULT 0,
                user_id INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS scheduled_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                cron_expression TEXT NOT NULL,
                csv_path TEXT NOT NULL,
                default_options TEXT NOT NULL DEFAULT '{}',
                last_run_at TEXT,
                last_status TEXT,
                last_message TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $count = (int) $this->db->querySingle("SELECT COUNT(*) FROM users");
        if ($count === 0) {
            $hashAdmin = password_hash('demo123', PASSWORD_DEFAULT);
            $hashUser = password_hash('demo123', PASSWORD_DEFAULT);
            $this->db->exec("
                INSERT INTO users (username, password_hash, role) VALUES 
                ('admin', '{$hashAdmin}', 'admin'),
                ('user', '{$hashUser}', 'user')
            ");

            $this->db->exec("
                INSERT INTO scheduled_jobs 
                (name, cron_expression, csv_path, default_options, last_status) 
                VALUES 
                ('Daily Podcast', '0 9 * * *', 'examples/batch.csv', '{\"normalize\":true,\"format\":\"mp3\",\"target_loudness\":-16}', 'pending')
            ");

            $this->db->exec("
                INSERT INTO voices (id, name, provider, is_cloned) VALUES 
                ('21m00Tcm4TlvDq8ikWAM', 'Rachel', 'elevenlabs', 0),
                ('AZnzlkDJ2myd1k8p7Q6X', 'Domi', 'elevenlabs', 0)
            ");
        }
    }

    public function listScheduledJobs(): array
    {
        $result = $this->db->query("SELECT * FROM scheduled_jobs ORDER BY created_at DESC");
        $jobs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['default_options'] = json_decode($row['default_options'] ?? '{}', true);
            $jobs[] = $row;
        }
        return $jobs;
    }

    public function saveScheduledJob(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO scheduled_jobs (name, cron_expression, csv_path, default_options) 
             VALUES (:name, :cron, :csv, :opts)'
        );
        $stmt->bindValue(':name', $data['name'], SQLITE3_TEXT);
        $stmt->bindValue(':cron', $data['cron_expression'], SQLITE3_TEXT);
        $stmt->bindValue(':csv', $data['csv_path'], SQLITE3_TEXT);
        $opts = is_array($data['default_options'] ?? null) 
            ? json_encode($data['default_options']) 
            : ($data['default_options'] ?? '{}');
        $stmt->bindValue(':opts', $opts, SQLITE3_TEXT);
        $stmt->execute();
        return (int) $this->db->lastInsertRowID();
    }

    public function getScheduledJob(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM scheduled_jobs WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if (!$row) {
            return null;
        }
        $row['default_options'] = json_decode($row['default_options'] ?? '{}', true);
        return $row;
    }

    public function updateScheduledJob(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE scheduled_jobs 
             SET name = :name, cron_expression = :cron, csv_path = :csv, default_options = :opts 
             WHERE id = :id'
        );
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->bindValue(':name', $data['name'], SQLITE3_TEXT);
        $stmt->bindValue(':cron', $data['cron_expression'], SQLITE3_TEXT);
        $stmt->bindValue(':csv', $data['csv_path'], SQLITE3_TEXT);
        $opts = is_array($data['default_options'] ?? null) 
            ? json_encode($data['default_options']) 
            : ($data['default_options'] ?? '{}');
        $stmt->bindValue(':opts', $opts, SQLITE3_TEXT);
        $stmt->execute();
        return $this->db->changes() > 0;
    }

    public function deleteScheduledJob(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM scheduled_jobs WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        return $this->db->changes() > 0;
    }

    public function updateJobLastRun(int $id, string $status, string $message = ''): void
    {
        $stmt = $this->db->prepare(
            'UPDATE scheduled_jobs 
             SET last_run_at = CURRENT_TIMESTAMP, last_status = :status, last_message = :message 
             WHERE id = :id'
        );
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function listLibrary(): array
    {
        $result = $this->db->query("SELECT * FROM voices ORDER BY name");
        $voices = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $voices[] = $row;
        }
        if (empty($voices)) {
            return [
                ['id' => '21m00Tcm4TlvDq8ikWAM', 'name' => 'Rachel', 'provider' => 'elevenlabs', 'is_cloned' => 0],
                ['id' => 'AZnzlkDJ2myd1k8p7Q6X', 'name' => 'Domi', 'provider' => 'elevenlabs', 'is_cloned' => 0],
            ];
        }
        return $voices;
    }

    public function saveGeneration(array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO generations 
             (user_id, hash, text, voice_id, provider, format, duration, audio_size_bytes, metadata) 
             VALUES (:uid, :hash, :text, :voice, :provider, :format, :dur, :size, :meta)'
        );
        $stmt->bindValue(':uid', $data['user_id'] ?? 1, SQLITE3_INTEGER);
        $stmt->bindValue(':hash', $data['hash'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':text', $data['text'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':voice', $data['voice_id'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':provider', $data['provider'] ?? 'elevenlabs', SQLITE3_TEXT);
        $stmt->bindValue(':format', $data['format'] ?? 'mp3', SQLITE3_TEXT);
        $stmt->bindValue(':dur', $data['duration'] ?? 3.0, SQLITE3_FLOAT);
        $stmt->bindValue(':size', $data['audio_size_bytes'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':meta', json_encode($data['metadata'] ?? []), SQLITE3_TEXT);
        $stmt->execute();
    }

    public function getHistory(int $limit = 20, ?int $userId = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM generations 
             WHERE (:uid IS NULL OR user_id = :uid) 
             ORDER BY generated_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function getUsageStats(string $period = '30'): array
    {
        return [
            'daily' => [
                ['date' => date('Y-m-d'), 'count' => 12, 'duration' => 45.5],
            ],
            'by_provider' => [
                'elevenlabs' => 18,
                'piper' => 7,
                'openai' => 4,
            ],
            'total_generations' => 29,
            'total_duration' => 187.3,
        ];
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
        if (!isset($bundle['voices']) || !is_array($bundle['voices'])) {
            return 0;
        }
        $imported = 0;
        foreach ($bundle['voices'] as $voice) {
            if (empty($voice['id']) || empty($voice['name'])) {
                continue;
            }
            $stmt = $this->db->prepare(
                'INSERT OR IGNORE INTO voices (id, name, provider, is_cloned) 
                 VALUES (:id, :name, :provider, :cloned)'
            );
            $stmt->bindValue(':id', $voice['id'], SQLITE3_TEXT);
            $stmt->bindValue(':name', $voice['name'], SQLITE3_TEXT);
            $stmt->bindValue(':provider', $voice['provider'] ?? 'elevenlabs', SQLITE3_TEXT);
            $stmt->bindValue(':cloned', $voice['is_cloned'] ?? 0, SQLITE3_INTEGER);
            $stmt->execute();
            if ($this->db->changes() > 0) {
                $imported++;
            }
        }
        return $imported;
    }

    public function findUserByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ?: null;
    }
}
