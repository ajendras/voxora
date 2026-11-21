<?php

declare(strict_types=1);

namespace Voxora\Storage;

use DateTimeImmutable;
use InvalidArgumentException;
use SQLite3;

/**
 * SqliteRepository
 *
 * Central persistence layer using SQLite3. Creates and migrates schema automatically.
 * Stores users, generations, voices (curated + cloned), and scheduled_jobs.
 * Powers analytics, library export/import, job CRUD and history queries.
 * No longer marked final so it can be mocked in unit tests (ApiControllerTest).
 */
class SqliteRepository
{
    private SQLite3 $db;
    private string $path;

    public function __construct(string $path = 'data/voxora.db')
    {
        $this->path = $path;
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Could not create storage directory: $dir");
            }
        }

        $this->db = new SQLite3($this->path);
        $this->db->enableExceptions(true);
        $this->createSchema();
    }

    private function createSchema(): void
    {
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT DEFAULT 'user',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
SQL);

        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hash TEXT NOT NULL,
                text TEXT NOT NULL,
                voice_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                format TEXT DEFAULT 'mp3',
                duration REAL,
                audio_size_bytes INTEGER DEFAULT 0,
                metadata TEXT,
                generated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                user_id INTEGER,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
SQL);

        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS voices (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT,
                provider TEXT DEFAULT 'elevenlabs',
                is_cloned INTEGER DEFAULT 0,
                sample_path TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                user_id INTEGER DEFAULT 1
            )
SQL);

        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS scheduled_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                cron_expression TEXT NOT NULL,
                csv_path TEXT NOT NULL,
                default_options TEXT,
                last_run_at TEXT,
                last_status TEXT,
                last_message TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
SQL);

        // Seed demo data if tables are empty
        $userCount = (int) $this->db->querySingle('SELECT COUNT(*) FROM users');
        if ($userCount === 0) {
            $this->db->exec(<<<SQL
                INSERT INTO users (username, password_hash, role) VALUES
                ('admin', '{$this->hashDemoPassword()}', 'admin'),
                ('user', '{$this->hashDemoPassword()}', 'user')
SQL);

            $this->db->exec(<<<SQL
                INSERT INTO voices (id, name, description, provider, is_cloned) VALUES
                ('21m00Tcm4TlvDq8ikWAM', 'Rachel', 'American • Warm & versatile', 'elevenlabs', 0),
                ('AZnzlkDJ2myd1k8p7Q6X', 'Domi', 'American • Upbeat & clear', 'elevenlabs', 0),
                ('pNInz6obpgDQGcFmaJgB', 'Adam', 'American • Deep & authoritative', 'elevenlabs', 0),
                ('EXAVITQu4vr4xnSDxMaL', 'Bella', 'American • Friendly & expressive', 'elevenlabs', 0)
SQL);
        }

        // Index for performance on common queries
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_generations_user ON generations(user_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_generations_date ON generations(generated_at)');
    }

    private function hashDemoPassword(): string
    {
        return password_hash('demo123', PASSWORD_DEFAULT);
    }

    public function listLibrary(): array
    {
        $result = $this->db->query('SELECT * FROM voices ORDER BY is_cloned ASC, name ASC');
        $voices = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $voices[] = $row;
        }
        return $voices;
    }

    public function saveGeneration(array $data): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO generations 
            (hash, text, voice_id, provider, format, duration, audio_size_bytes, metadata, generated_at, user_id)
            VALUES (:hash, :text, :voice_id, :provider, :format, :duration, :size, :metadata, :generated_at, :user_id)
        ');

        $stmt->bindValue(':hash', $data['hash'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':text', $data['text'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':voice_id', $data['voice_id'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':provider', $data['provider'] ?? 'elevenlabs', SQLITE3_TEXT);
        $stmt->bindValue(':format', $data['format'] ?? 'mp3', SQLITE3_TEXT);
        $stmt->bindValue(':duration', $data['duration'] ?? 0.0, SQLITE3_FLOAT);
        $stmt->bindValue(':size', $data['audio_size_bytes'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':metadata', json_encode($data['metadata'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(':generated_at', $data['generated_at'] ?? date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $data['user_id'] ?? 1, SQLITE3_INTEGER);

        $stmt->execute();
    }

    public function getHistory(int $limit = 20, ?int $userId = null): array
    {
        $sql = 'SELECT * FROM generations';
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :user_id';
        }
        $sql .= ' ORDER BY generated_at DESC LIMIT :limit';

        $stmt = $this->db->prepare($sql);
        if ($userId !== null) {
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        }
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);

        $result = $stmt->execute();
        $history = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (isset($row['metadata'])) {
                $row['metadata'] = json_decode($row['metadata'], true) ?? [];
            }
            $history[] = $row;
        }
        return $history;
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
        if (!isset($bundle['version']) || $bundle['version'] !== '1.0' || !isset($bundle['voices']) || !is_array($bundle['voices'])) {
            throw new InvalidArgumentException('Invalid bundle format. Version 1.0 with voices array required.');
        }

        $imported = 0;
        foreach ($bundle['voices'] as $voice) {
            if (empty($voice['id']) || empty($voice['name'])) {
                continue;
            }

            $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM voices WHERE id = :id');
            $stmt->bindValue(':id', $voice['id'], SQLITE3_TEXT);
            $res = $stmt->execute();
            $row = $res->fetchArray(SQLITE3_ASSOC);
            if ((int) $row['cnt'] > 0) {
                continue;
            }

            $stmt = $this->db->prepare('
                INSERT INTO voices (id, name, description, provider, is_cloned, sample_path, user_id)
                VALUES (:id, :name, :description, :provider, :is_cloned, :sample_path, :user_id)
            ');
            $stmt->bindValue(':id', $voice['id'], SQLITE3_TEXT);
            $stmt->bindValue(':name', $voice['name'], SQLITE3_TEXT);
            $stmt->bindValue(':description', $voice['description'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':provider', $voice['provider'] ?? 'elevenlabs', SQLITE3_TEXT);
            $stmt->bindValue(':is_cloned', (int) ($voice['is_cloned'] ?? 0), SQLITE3_INTEGER);
            $stmt->bindValue(':sample_path', $voice['sample_path'] ?? null, SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $voice['user_id'] ?? 1, SQLITE3_INTEGER);
            $stmt->execute();
            $imported++;
        }

        return $imported;
    }

    public function saveScheduledJob(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO scheduled_jobs 
            (name, cron_expression, csv_path, default_options, created_at)
            VALUES (:name, :cron_expression, :csv_path, :default_options, CURRENT_TIMESTAMP)
        ');
        $stmt->bindValue(':name', $data['name'], SQLITE3_TEXT);
        $stmt->bindValue(':cron_expression', $data['cron_expression'], SQLITE3_TEXT);
        $stmt->bindValue(':csv_path', $data['csv_path'], SQLITE3_TEXT);
        $stmt->bindValue(':default_options', $data['default_options'] ?? '[]', SQLITE3_TEXT);
        $stmt->execute();

        return (int) $this->db->lastInsertRowID();
    }

    public function listScheduledJobs(): array
    {
        $result = $this->db->query('SELECT * FROM scheduled_jobs ORDER BY name ASC');
        $jobs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $jobs[] = $row;
        }
        return $jobs;
    }

    public function getScheduledJob(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM scheduled_jobs WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ?: null;
    }

    public function updateScheduledJob(int $id, array $data): bool
    {
        $updates = [];
        $params = [':id' => $id];

        if (isset($data['name'])) {
            $updates[] = 'name = :name';
            $params[':name'] = $data['name'];
        }
        if (isset($data['cron_expression'])) {
            $updates[] = 'cron_expression = :cron_expression';
            $params[':cron_expression'] = $data['cron_expression'];
        }
        if (isset($data['csv_path'])) {
            $updates[] = 'csv_path = :csv_path';
            $params[':csv_path'] = $data['csv_path'];
        }
        if (isset($data['default_options'])) {
            $updates[] = 'default_options = :default_options';
            $params[':default_options'] = $data['default_options'];
        }

        if (empty($updates)) {
            return false;
        }

        $sql = 'UPDATE scheduled_jobs SET ' . implode(', ', $updates) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        return $stmt->execute() !== false;
    }

    public function deleteScheduledJob(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM scheduled_jobs WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        return $stmt->execute() !== false;
    }

    public function updateJobLastRun(int $id, string $status, string $message): bool
    {
        $stmt = $this->db->prepare('
            UPDATE scheduled_jobs 
            SET last_run_at = CURRENT_TIMESTAMP, 
                last_status = :status, 
                last_message = :message 
            WHERE id = :id
        ');
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        return $stmt->execute() !== false;
    }

    public function getUsageStats(?int $userId = null, string $period = '30'): array
    {
        $days = (int) $period;
        $sql = '
            SELECT 
                DATE(generated_at) as date,
                COUNT(*) as count,
                ROUND(SUM(duration), 2) as total_duration,
                provider
            FROM generations
        ';
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :user_id';
        }
        $sql .= ' GROUP BY DATE(generated_at), provider ORDER BY date ASC';

        $stmt = $this->db->prepare($sql);
        if ($userId !== null) {
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();

        $daily = [];
        $byProvider = [];
        $totalGenerations = 0;
        $totalDuration = 0.0;

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $daily[] = $row;
            $totalGenerations += (int) $row['count'];
            $totalDuration += (float) $row['total_duration'];

            $provider = $row['provider'];
            if (!isset($byProvider[$provider])) {
                $byProvider[$provider] = 0;
            }
            $byProvider[$provider] += (int) $row['count'];
        }

        $providerBreakdown = [];
        foreach ($byProvider as $provider => $count) {
            $providerBreakdown[] = [
                'provider' => $provider,
                'count' => $count,
            ];
        }

        return [
            'daily' => $daily,
            'by_provider' => $providerBreakdown,
            'summary' => [
                'total_generations' => $totalGenerations,
                'total_duration' => round($totalDuration, 2),
                'period_days' => $days,
            ],
        ];
    }

    public function findUserByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ?: null;
    }

    public function revokeToken(string $tokenHash): void
    {
        // Revocation list stub - expanded in future roadmap item
    }

    public function isTokenRevoked(string $tokenHash): bool
    {
        // Revocation list stub - expanded in future roadmap item
        return false;
    }

    public function __destruct()
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
}
