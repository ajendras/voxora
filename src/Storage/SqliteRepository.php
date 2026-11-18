<?php

declare(strict_types=1);

namespace Voxora\Storage;

use PDO;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * SqliteRepository
 *
 * Central persistence layer using SQLite. Automatically creates required schema
 * (users, voices with is_cloned flag, generations, scheduled_jobs) on first boot.
 * Provides voice library CRUD, usage analytics for Chart.js, import/export of
 * JSON bundles, and helper methods used by Scheduler, Manager and controllers.
 */
final class SqliteRepository
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        if ($dbPath === '') {
            throw new InvalidArgumentException('Database path cannot be empty');
        }

        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create database directory: {$dir}");
        }

        $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT DEFAULT 'user',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS voices (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT,
                provider TEXT DEFAULT 'elevenlabs',
                is_cloned INTEGER DEFAULT 0,
                user_id INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hash TEXT NOT NULL,
                text TEXT NOT NULL,
                voice_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                format TEXT DEFAULT 'mp3',
                duration REAL,
                audio_size_bytes INTEGER,
                metadata TEXT,
                generated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                user_id INTEGER,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS scheduled_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                cron_expression TEXT NOT NULL,
                csv_path TEXT NOT NULL,
                default_options TEXT,
                last_run TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_generations_user ON generations(user_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_generations_date ON generations(generated_at)');

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM users');
        if ((int) $stmt->fetchColumn() === 0) {
            $this->seedDemoData();
        }

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM voices');
        if ((int) $stmt->fetchColumn() === 0) {
            $this->seedDefaultVoices();
        }
    }

    private function seedDemoData(): void
    {
        $users = [
            ['admin', password_hash('demo123', PASSWORD_DEFAULT), 'admin'],
            ['user', password_hash('demo123', PASSWORD_DEFAULT), 'user'],
        ];

        $stmt = $this->pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        foreach ($users as $u) {
            $stmt->execute($u);
        }
    }

    private function seedDefaultVoices(): void
    {
        $voices = [
            ['21m00Tcm4TlvDq8ikWAM', 'Rachel', 'American • Warm & versatile', 'elevenlabs', 0],
            ['AZnzlkDJ2myd1k8p7Q6X', 'Domi', 'American • Upbeat & clear', 'elevenlabs', 0],
            ['pNInz6obpgDQGcFmaJgB', 'Adam', 'American • Deep & authoritative', 'elevenlabs', 0],
            ['EXAVITQu4vr4xnSDxMaL', 'Bella', 'American • Friendly & expressive', 'elevenlabs', 0],
        ];

        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO voices (id, name, description, provider, is_cloned) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($voices as $voice) {
            $stmt->execute($voice);
        }
    }

    public function listLibrary(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, description, provider, (is_cloned = 1) as cloned 
             FROM voices ORDER BY name ASC'
        );
        return $stmt->fetchAll();
    }

    public function exportLibrary(): array
    {
        $voices = $this->listLibrary();
        return [
            'version' => '1.0',
            'exported_at' => (new DateTimeImmutable())->format('c'),
            'generated_by' => 'Voxora',
            'total_voices' => count($voices),
            'voices' => $voices,
        ];
    }

    public function importLibrary(array $bundle): int
    {
        if (($bundle['version'] ?? null) !== '1.0') {
            throw new InvalidArgumentException('Invalid or unsupported bundle version. Only version 1.0 is supported.');
        }

        if (!isset($bundle['voices']) || !is_array($bundle['voices'])) {
            throw new InvalidArgumentException('The bundle must contain a "voices" array.');
        }

        $imported = 0;
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO voices 
             (id, name, description, provider, is_cloned, created_at) 
             VALUES (:id, :name, :description, :provider, :is_cloned, :created_at)'
        );

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($bundle['voices'] as $voice) {
            $id = trim($voice['id'] ?? '');
            $name = trim($voice['name'] ?? '');

            if ($id === '' || $name === '') {
                continue;
            }

            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':description' => $voice['description'] ?? 'Imported voice',
                ':provider' => $voice['provider'] ?? 'elevenlabs',
                ':is_cloned' => !empty($voice['cloned']) || !empty($voice['is_cloned']) ? 1 : 0,
                ':created_at' => $now,
            ]);

            if ($stmt->rowCount() > 0) {
                $imported++;
            }
        }

        return $imported;
    }

    public function saveGeneration(array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO generations 
             (hash, text, voice_id, provider, format, duration, audio_size_bytes, metadata, generated_at, user_id) 
             VALUES (:hash, :text, :voice_id, :provider, :format, :duration, :audio_size_bytes, :metadata, :generated_at, :user_id)'
        );

        $stmt->execute([
            ':hash' => $data['hash'] ?? '',
            ':text' => $data['text'] ?? '',
            ':voice_id' => $data['voice_id'] ?? '',
            ':provider' => $data['provider'] ?? 'elevenlabs',
            ':format' => $data['format'] ?? 'mp3',
            ':duration' => $data['duration'] ?? null,
            ':audio_size_bytes' => $data['audio_size_bytes'] ?? 0,
            ':metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            ':generated_at' => $data['generated_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':user_id' => $data['user_id'] ?? 1,
        ]);
    }

    public function getHistory(int $limit = 20, ?int $userId = null): array
    {
        $sql = 'SELECT id, text, voice_id, provider, format, duration, generated_at 
                FROM generations';
        $params = [];

        if ($userId !== null) {
            $sql .= ' WHERE user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $sql .= ' ORDER BY generated_at DESC LIMIT :limit';
        $params[':limit'] = max(1, min(100, $limit));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function getUsageStats(?string $period = '30', ?int $userId = null): array
    {
        $days = (int) ($period ?? 30);
        if ($days < 1) {
            $days = 30;
        }

        $sql = '
            SELECT 
                date(generated_at) as day,
                COUNT(*) as count,
                SUM(duration) as total_duration
            FROM generations
            WHERE generated_at >= date("now", ?)
        ';

        $params = ['-' . $days . ' days'];

        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }

        $sql .= ' GROUP BY day ORDER BY day ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $labels = [];
        $counts = [];
        $durations = [];

        $start = new DateTimeImmutable('-' . $days . ' days');
        for ($i = 0; $i < $days; $i++) {
            $date = $start->modify('+' . $i . ' days')->format('Y-m-d');
            $labels[] = $date;
            $counts[$date] = 0;
            $durations[$date] = 0.0;
        }

        foreach ($rows as $row) {
            $counts[$row['day']] = (int) $row['count'];
            $durations[$row['day']] = (float) ($row['total_duration'] ?? 0);
        }

        return [
            'labels' => array_values($labels),
            'counts' => array_values($counts),
            'durations' => array_values($durations),
            'total_generations' => array_sum($counts),
            'total_minutes' => round(array_sum($durations) / 60, 1),
        ];
    }

    public function saveVoice(array $voiceData): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO voices 
             (id, name, description, provider, is_cloned, user_id, created_at) 
             VALUES (:id, :name, :description, :provider, :is_cloned, :user_id, :created_at)'
        );

        $stmt->execute([
            ':id' => $voiceData['id'],
            ':name' => $voiceData['name'],
            ':description' => $voiceData['description'] ?? '',
            ':provider' => $voiceData['provider'] ?? 'elevenlabs',
            ':is_cloned' => ($voiceData['is_cloned'] ?? false) ? 1 : 0,
            ':user_id' => $voiceData['user_id'] ?? null,
            ':created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function addJob(array $jobData): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO scheduled_jobs 
             (name, cron_expression, csv_path, default_options, last_run) 
             VALUES (:name, :cron, :csv, :options, NULL)'
        );

        $stmt->execute([
            ':name' => $jobData['name'],
            ':cron' => $jobData['cron_expression'],
            ':csv' => $jobData['csv_path'],
            ':options' => isset($jobData['default_options']) ? json_encode($jobData['default_options']) : null,
        ]);
    }

    public function listJobs(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM scheduled_jobs ORDER BY name ASC'
        );
        return $stmt->fetchAll();
    }

    public function markJobRun(int $jobId, string $runTime): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE scheduled_jobs SET last_run = ? WHERE id = ?'
        );
        $stmt->execute([$runTime, $jobId]);
    }
}
