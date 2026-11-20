<?php

declare(strict_types=1);

namespace Voxora\Storage;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * SqliteRepository
 *
 * Central persistence layer using SQLite3. Manages users, generations,
 * voices (including cloned), scheduled jobs and the new revoked_tokens list.
 * Schema is auto-initialised on first use. All dates are stored in Y-m-d H:i:s format.
 */
final class SqliteRepository
{
    private \SQLite3 $db;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create storage directory: $dir");
        }

        $this->db = new \SQLite3($dbPath);
        $this->db->enableExceptions(true);
        $this->initSchema();
        $this->pruneRevokedTokens();
    }

    private function initSchema(): void
    {
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY,
                hash TEXT,
                text TEXT NOT NULL,
                voice_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                format TEXT DEFAULT 'mp3',
                duration REAL,
                metadata TEXT,
                audio_size_bytes INTEGER,
                generated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                user_id INTEGER DEFAULT 1
            );

            CREATE TABLE IF NOT EXISTS voices (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT,
                provider TEXT NOT NULL DEFAULT 'elevenlabs',
                is_cloned INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                user_id INTEGER
            );

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
            );

            CREATE TABLE IF NOT EXISTS revoked_tokens (
                token_id TEXT PRIMARY KEY,
                revoked_at TEXT DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT NOT NULL
            );
SQL);

        $this->seedDemoData();
    }

    private function seedDemoData(): void
    {
        $result = $this->db->query('SELECT COUNT(*) as cnt FROM users');
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if (($row['cnt'] ?? 0) > 0) {
            return;
        }

        $this->db->exec("
            INSERT INTO users (username, password_hash, role) VALUES
            ('admin', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'admin'),
            ('user', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'user');
        ");

        $voices = [
            ['21m00Tcm4TlvDq8ikWAM', 'Rachel', 'American • Warm & versatile', 'elevenlabs', 0],
            ['AZnzlkDJ2myd1k8p7Q6X', 'Domi', 'American • Upbeat & clear', 'elevenlabs', 0],
            ['pNInz6obpgDQGcFmaJgB', 'Adam', 'American • Deep & authoritative', 'elevenlabs', 0],
            ['EXAVITQu4vr4xnSDxMaL', 'Bella', 'American • Friendly & expressive', 'elevenlabs', 0],
        ];

        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO voices (id, name, description, provider, is_cloned) VALUES (:id, :name, :desc, :prov, :cloned)'
        );
        foreach ($voices as $v) {
            $stmt->bindValue(':id', $v[0], SQLITE3_TEXT);
            $stmt->bindValue(':name', $v[1], SQLITE3_TEXT);
            $stmt->bindValue(':desc', $v[2], SQLITE3_TEXT);
            $stmt->bindValue(':prov', $v[3], SQLITE3_TEXT);
            $stmt->bindValue(':cloned', $v[4], SQLITE3_INTEGER);
            $stmt->execute();
        }
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

    public function saveCustomVoice(string $id, string $name, int $userId = 1): void
    {
        $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO voices (id, name, description, provider, is_cloned, user_id)
             VALUES (:id, :name, :desc, "elevenlabs", 1, :uid)'
        );
        $stmt->bindValue(':id', $id, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':desc', 'Cloned voice', SQLITE3_TEXT);
        $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function saveGeneration(array $data): void
    {
        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO generations 
            (hash, text, voice_id, provider, format, duration, metadata, audio_size_bytes, generated_at, user_id)
            VALUES (:hash, :text, :voice_id, :provider, :format, :duration, :metadata, :size, :generated_at, :user_id)
SQL);

        $stmt->bindValue(':hash', $data['hash'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':text', $data['text'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':voice_id', $data['voice_id'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':provider', $data['provider'] ?? 'elevenlabs', SQLITE3_TEXT);
        $stmt->bindValue(':format', $data['format'] ?? 'mp3', SQLITE3_TEXT);
        $stmt->bindValue(':duration', $data['duration'] ?? null, SQLITE3_FLOAT);
        $metadata = is_array($data['metadata'] ?? null) ? json_encode($data['metadata']) : ($data['metadata'] ?? '{}');
        $stmt->bindValue(':metadata', $metadata, SQLITE3_TEXT);
        $stmt->bindValue(':size', $data['audio_size_bytes'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':generated_at', $data['generated_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $data['user_id'] ?? 1, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function getHistory(int $limit = 20, ?int $userId = null): array
    {
        $sql = 'SELECT * FROM generations';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :user_id';
            $params[':user_id'] = $userId;
        }
        $sql .= ' ORDER BY generated_at DESC LIMIT :limit';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);

        $result = $stmt->execute();
        $history = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (isset($row['metadata']) && is_string($row['metadata'])) {
                $row['metadata'] = json_decode($row['metadata'], true) ?: [];
            }
            $history[] = $row;
        }
        return $history;
    }

    public function getUsageStats(string $period = '30', ?int $userId = null): array
    {
        $days = (int) $period;
        $sql = '
            SELECT 
                date(generated_at) as day,
                COUNT(*) as count,
                COALESCE(SUM(duration), 0) as total_duration,
                provider
            FROM generations
            WHERE generated_at >= date("now", :days || " days")
        ';
        if ($userId !== null) {
            $sql .= ' AND user_id = :user_id';
        }
        $sql .= '
            GROUP BY day, provider
            ORDER BY day ASC
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':days', -$days, SQLITE3_TEXT);
        if ($userId !== null) {
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();

        $daily = [];
        $byProvider = [];
        $totalGenerations = 0;
        $totalMinutes = 0.0;

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $day = $row['day'];
            $daily[$day] = [
                'day' => $day,
                'count' => (int) ($row['count'] ?? 0),
                'duration' => round((float) ($row['total_duration'] ?? 0), 2),
            ];
            $provider = $row['provider'] ?? 'unknown';
            $byProvider[$provider] = ($byProvider[$provider] ?? 0) + (int) ($row['count'] ?? 0);
            $totalGenerations += (int) ($row['count'] ?? 0);
            $totalMinutes += (float) ($row['total_duration'] ?? 0) / 60;
        }

        // Fill missing days with zeros (simple last-N-days fill)
        $start = new DateTimeImmutable("-{$days} days");
        for ($i = 0; $i < $days; $i++) {
            $date = $start->modify("+{$i} days")->format('Y-m-d');
            if (!isset($daily[$date])) {
                $daily[$date] = ['day' => $date, 'count' => 0, 'duration' => 0.0];
            }
        }
        ksort($daily);

        return [
            'daily' => array_values($daily),
            'by_provider' => $byProvider,
            'summary' => [
                'total_generations' => $totalGenerations,
                'total_minutes' => round($totalMinutes, 1),
            ],
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
        if (($bundle['version'] ?? '') !== '1.0' || !isset($bundle['voices']) || !is_array($bundle['voices'])) {
            throw new InvalidArgumentException('Invalid voice library bundle format');
        }

        $imported = 0;
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO voices (id, name, description, provider, is_cloned, user_id)
             VALUES (:id, :name, :description, :provider, :is_cloned, NULL)'
        );

        foreach ($bundle['voices'] as $voice) {
            if (empty($voice['id'])) {
                continue;
            }
            $stmt->bindValue(':id', $voice['id'], SQLITE3_TEXT);
            $stmt->bindValue(':name', $voice['name'] ?? 'Unnamed', SQLITE3_TEXT);
            $stmt->bindValue(':description', $voice['description'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':provider', $voice['provider'] ?? 'elevenlabs', SQLITE3_TEXT);
            $stmt->bindValue(':is_cloned', (int) ($voice['is_cloned'] ?? 0), SQLITE3_INTEGER);
            if ($stmt->execute() !== false) {
                $imported++;
            }
        }
        return $imported;
    }

    public function saveScheduledJob(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO scheduled_jobs 
             (name, cron_expression, csv_path, default_options)
             VALUES (:name, :cron, :csv, :opts)'
        );
        $stmt->bindValue(':name', $data['name'], SQLITE3_TEXT);
        $stmt->bindValue(':cron', $data['cron_expression'], SQLITE3_TEXT);
        $stmt->bindValue(':csv', $data['csv_path'], SQLITE3_TEXT);
        $stmt->bindValue(':opts', $data['default_options'] ?? '[]', SQLITE3_TEXT);
        $stmt->execute();
        return (int) $this->db->lastInsertRowID();
    }

    public function listScheduledJobs(): array
    {
        $result = $this->db->query('SELECT * FROM scheduled_jobs ORDER BY created_at DESC');
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
        if (empty($data)) {
            return false;
        }
        $setParts = [];
        foreach (array_keys($data) as $key) {
            $setParts[] = "$key = :$key";
        }
        $sql = 'UPDATE scheduled_jobs SET ' . implode(', ', $setParts) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
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
        $stmt = $this->db->prepare(
            'UPDATE scheduled_jobs 
             SET last_run_at = CURRENT_TIMESTAMP, last_status = :status, last_message = :message 
             WHERE id = :id'
        );
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        return $stmt->execute() !== false;
    }

    public function revokeToken(string $tokenId, string $expiresAt): bool
    {
        $stmt = $this->db->prepare(
            'INSERT OR IGNORE INTO revoked_tokens (token_id, expires_at) 
             VALUES (:token_id, :expires_at)'
        );
        $stmt->bindValue(':token_id', $tokenId, SQLITE3_TEXT);
        $stmt->bindValue(':expires_at', $expiresAt, SQLITE3_TEXT);
        return $stmt->execute() !== false;
    }

    public function isTokenRevoked(string $tokenId): bool
    {
        if (empty($tokenId)) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT 1 FROM revoked_tokens 
             WHERE token_id = :token_id 
             AND expires_at > datetime("now")'
        );
        $stmt->bindValue(':token_id', $tokenId, SQLITE3_TEXT);
        $result = $stmt->execute();
        return $result->fetchArray(SQLITE3_NUM) !== false;
    }

    public function pruneRevokedTokens(): int
    {
        $result = $this->db->query('DELETE FROM revoked_tokens WHERE expires_at <= datetime("now")');
        return $this->db->changes();
    }

    public function __destruct()
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
}
