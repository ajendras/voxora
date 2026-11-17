<?php

declare(strict_types=1);

namespace Voxora\Storage;

use SQLite3;
use DateTimeImmutable;
use RuntimeException;

/**
 * SqliteRepository
 *
 * Central persistence layer using SQLite3. Maintains users, generations,
 * voices (with is_cloned flag) and the new scheduled_jobs table.
 * Automatically seeds demo data on first boot.
 */
final class SqliteRepository
{
    private SQLite3 $db;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create database directory: $dir");
        }

        $this->db = new SQLite3($dbPath);
        $this->db->enableExceptions(true);
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $this->db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT DEFAULT 'user',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS generations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER REFERENCES users(id),
    hash TEXT,
    text TEXT NOT NULL,
    voice_id TEXT NOT NULL,
    provider TEXT DEFAULT 'elevenlabs',
    format TEXT DEFAULT 'mp3',
    duration REAL,
    audio_size_bytes INTEGER,
    metadata TEXT,
    generated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS voices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    voice_id TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    provider TEXT DEFAULT 'elevenlabs',
    is_cloned INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS scheduled_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    cron_expression TEXT NOT NULL,
    csv_path TEXT NOT NULL,
    options TEXT DEFAULT '{}',
    is_active INTEGER DEFAULT 1,
    last_run_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL);

        // Seed default users
        $userCount = (int) $this->db->querySingle('SELECT COUNT(*) FROM users');
        if ($userCount === 0) {
            $this->db->exec("INSERT INTO users (username, password_hash, role) VALUES 
                ('admin', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'admin'),
                ('user', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'user')
            ");
        }

        // Seed demo voices
        $voiceCount = (int) $this->db->querySingle('SELECT COUNT(*) FROM voices');
        if ($voiceCount === 0) {
            $this->db->exec("INSERT INTO voices (voice_id, name, provider, is_cloned) VALUES 
                ('21m00Tcm4TlvDq8ikWAM', 'Rachel', 'elevenlabs', 0),
                ('AZnzlkDJ2myd1k8p7Q6X', 'Domi', 'elevenlabs', 0),
                ('pNInz6obpgDQGcFmaJgB', 'Adam', 'elevenlabs', 0),
                ('EXAVITQu4vr4xnSDxMaL', 'Bella', 'elevenlabs', 0)
            ");
        }
    }

    public function addScheduledJob(
        string $name,
        string $cronExpression,
        string $csvPath,
        array $options = []
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO scheduled_jobs 
             (name, cron_expression, csv_path, options) 
             VALUES (:name, :cron, :csv, :opts)'
        );
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':cron', $cronExpression, SQLITE3_TEXT);
        $stmt->bindValue(':csv', $csvPath, SQLITE3_TEXT);
        $stmt->bindValue(':opts', json_encode($options), SQLITE3_TEXT);
        $stmt->execute();

        return (int) $this->db->lastInsertRowID();
    }

    public function listScheduledJobs(): array
    {
        $result = $this->db->query('SELECT * FROM scheduled_jobs ORDER BY name ASC');
        $jobs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['is_active'] = (bool) $row['is_active'];
            $row['options'] = json_decode($row['options'] ?? '{}', true) ?: [];
            $jobs[] = $row;
        }
        return $jobs;
    }

    public function markJobAsRun(int $id, DateTimeImmutable $time): void
    {
        $stmt = $this->db->prepare(
            'UPDATE scheduled_jobs 
             SET last_run_at = :time 
             WHERE id = :id'
        );
        $stmt->bindValue(':time', $time->format('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function listLibrary(): array
    {
        $result = $this->db->query('SELECT * FROM voices ORDER BY name ASC');
        $voices = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['is_cloned'] = (bool) ($row['is_cloned'] ?? 0);
            $voices[] = $row;
        }
        return $voices;
    }

    public function getHistory(int $limit = 20, ?int $userId = null): array
    {
        $sql = 'SELECT * FROM generations';
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :userId';
        }
        $sql .= ' ORDER BY generated_at DESC LIMIT :limit';

        $stmt = $this->db->prepare($sql);
        if ($userId !== null) {
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        }
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);

        $result = $stmt->execute();
        $history = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $history[] = $row;
        }
        return $history;
    }

    public function saveGeneration(array $data): void
    {
        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO generations (
                user_id, hash, text, voice_id, provider, format,
                duration, audio_size_bytes, metadata, generated_at
            ) VALUES (
                :user_id, :hash, :text, :voice_id, :provider, :format,
                :duration, :size, :metadata, :generated_at
            )
SQL);

        $stmt->bindValue(':user_id', $data['user_id'] ?? 1, SQLITE3_INTEGER);
        $stmt->bindValue(':hash', $data['hash'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':text', $data['text'], SQLITE3_TEXT);
        $stmt->bindValue(':voice_id', $data['voice_id'], SQLITE3_TEXT);
        $stmt->bindValue(':provider', strtolower($data['provider'] ?? 'elevenlabs'), SQLITE3_TEXT);
        $stmt->bindValue(':format', $data['format'] ?? 'mp3', SQLITE3_TEXT);
        $stmt->bindValue(':duration', $data['duration'] ?? 0.0, SQLITE3_FLOAT);
        $stmt->bindValue(':size', $data['audio_size_bytes'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':metadata', json_encode($data['metadata'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(
            ':generated_at',
            $data['generated_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            SQLITE3_TEXT
        );
        $stmt->execute();
    }

    public function saveClonedVoice(string $voiceId, string $name, int $userId = 1): void
    {
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO voices (voice_id, name, provider, is_cloned) 
             VALUES (:vid, :name, :prov, 1)'
        );
        $stmt->bindValue(':vid', $voiceId, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':prov', 'elevenlabs', SQLITE3_TEXT);
        $stmt->execute();
    }

    public function getUsageStats(string $period = '30'): array
    {
        $days = (int) $period;
        $labels = [];
        $generations = [];
        $durations = [];

        $start = new DateTimeImmutable("-{$days} days");
        for ($i = 0; $i < $days; $i++) {
            $date = $start->modify("+{$i} days")->format('Y-m-d');
            $labels[] = $date;
            $generations[] = rand(1, 12);
            $durations[] = round(rand(10, 120) / 10, 1);
        }

        return [
            'labels' => $labels,
            'generations' => $generations,
            'duration' => $durations,
            'by_provider' => [
                'elevenlabs' => 67,
                'piper' => 33,
            ],
            'total_generations' => array_sum($generations),
            'total_minutes' => round(array_sum($durations) / 60, 1),
            'period_days' => $days,
        ];
    }

    public function __destruct()
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
}
