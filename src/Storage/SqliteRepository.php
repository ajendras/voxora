<?php

declare(strict_types=1);

namespace Voxora\Storage;

use DateTimeImmutable;
use SQLite3;

/**
 * SqliteRepository
 *
 * All persistence for users, voices, generations. Provides rich analytics
 * data shaped for Chart.js (daily trends, provider breakdown, summary KPIs).
 * Demo data is seeded on first boot so the analytics dashboard is immediately useful.
 */
final class SqliteRepository
{
    private SQLite3 $db;
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->db = new SQLite3($path);
        $this->db->enableExceptions(true);
        $this->db->exec('PRAGMA foreign_keys = ON;');
    }

    public function initialize(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT DEFAULT 'user',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS voices (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                voice_id TEXT UNIQUE NOT NULL,
                provider TEXT DEFAULT 'elevenlabs',
                is_cloned INTEGER DEFAULT 0,
                sample_hash TEXT,
                user_id INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                hash TEXT NOT NULL,
                text TEXT NOT NULL,
                voice_id TEXT NOT NULL,
                provider TEXT DEFAULT 'elevenlabs',
                format TEXT DEFAULT 'mp3',
                duration REAL,
                audio_size_bytes INTEGER,
                metadata TEXT,
                generated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        $countUsers = $this->db->querySingle('SELECT COUNT(*) FROM users');
        if ($countUsers === 0) {
            $this->db->exec("
                INSERT INTO users (username, password_hash, role) VALUES 
                ('admin', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'admin'),
                ('user', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'user')
            ");
        }

        $countVoices = $this->db->querySingle('SELECT COUNT(*) FROM voices');
        if ($countVoices === 0) {
            $this->db->exec("
                INSERT INTO voices (name, voice_id, provider, is_cloned) VALUES 
                ('Rachel', '21m00Tcm4TlvDq8ikWAM', 'elevenlabs', 0),
                ('Domi', 'AZnzlkDJ2myd1k8p7Q6X', 'elevenlabs', 0),
                ('My Cloned Narrator', 'cloned-voice-123xyz', 'elevenlabs', 1)
            ");
        }

        $countGenerations = $this->db->querySingle('SELECT COUNT(*) FROM generations');
        if ($countGenerations === 0) {
            $stmt = $this->db->prepare("
                INSERT INTO generations 
                (user_id, hash, text, voice_id, provider, duration, audio_size_bytes, generated_at, metadata)
                VALUES (:user_id, :hash, :text, :voice_id, :provider, :duration, :audio_size_bytes, :generated_at, :metadata)
            ");

            $samples = [
                [1, 'h1', 'Welcome to Voxora analytics demo.', '21m00Tcm4TlvDq8ikWAM', 'elevenlabs', 4.2, 52000, date('Y-m-d H:i:s', strtotime('-1 day')), '{"normalized":true}'],
                [1, 'h2', 'Local Piper synthesis is fast.', 'default-piper', 'piper', 5.8, 98000, date('Y-m-d H:i:s', strtotime('-3 days')), '{"normalized":false}'],
                [1, 'h3', 'Batch processed voiceover.', 'AZnzlkDJ2myd1k8p7Q6X', 'elevenlabs', 12.4, 145000, date('Y-m-d H:i:s', strtotime('-5 days')), '{"normalized":true}'],
            ];

            foreach ($samples as $s) {
                $stmt->bindValue(':user_id', $s[0], SQLITE3_INTEGER);
                $stmt->bindValue(':hash', $s[1], SQLITE3_TEXT);
                $stmt->bindValue(':text', $s[2], SQLITE3_TEXT);
                $stmt->bindValue(':voice_id', $s[3], SQLITE3_TEXT);
                $stmt->bindValue(':provider', $s[4], SQLITE3_TEXT);
                $stmt->bindValue(':duration', $s[5], SQLITE3_FLOAT);
                $stmt->bindValue(':audio_size_bytes', $s[6], SQLITE3_INTEGER);
                $stmt->bindValue(':generated_at', $s[7], SQLITE3_TEXT);
                $stmt->bindValue(':metadata', $s[8], SQLITE3_TEXT);
                $stmt->execute();
                $stmt->reset();
            }

            // Seed ~30 more entries across the last 60 days for realistic charts
            for ($i = 0; $i < 32; $i++) {
                $daysAgo = rand(0, 58);
                $dur = rand(25, 185) / 10.0;
                $provider = (rand(0, 1) === 0) ? 'elevenlabs' : 'piper';
                $hash = 'demo-seed-' . $i;
                $text = 'Demo generation #' . ($i + 1);

                $stmt->bindValue(':user_id', 1, SQLITE3_INTEGER);
                $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
                $stmt->bindValue(':text', $text, SQLITE3_TEXT);
                $stmt->bindValue(':voice_id', '21m00Tcm4TlvDq8ikWAM', SQLITE3_TEXT);
                $stmt->bindValue(':provider', $provider, SQLITE3_TEXT);
                $stmt->bindValue(':duration', $dur, SQLITE3_FLOAT);
                $stmt->bindValue(':audio_size_bytes', rand(42000, 185000), SQLITE3_INTEGER);
                $stmt->bindValue(':generated_at', date('Y-m-d H:i:s', strtotime("-{$daysAgo} days")), SQLITE3_TEXT);
                $stmt->bindValue(':metadata', '{"normalized":true}', SQLITE3_TEXT);
                $stmt->execute();
                $stmt->reset();
            }
        }
    }

    public function authenticate(string $username, string $password): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            return $user;
        }

        return null;
    }

    /**
     * @return array<array<string,mixed>>
     */
    public function listLibrary(): array
    {
        $result = $this->db->query('SELECT * FROM voices ORDER BY is_cloned DESC, name ASC');
        $voices = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $voices[] = $row;
        }
        return $voices;
    }

    /**
     * @return array<array<string,mixed>>
     */
    public function getHistory(int $limit = 10, ?int $userId = null): array
    {
        $sql = 'SELECT id, text, voice_id, provider, duration, generated_at 
                FROM generations';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :user_id';
            $params[':user_id'] = $userId;
        }
        $sql .= ' ORDER BY generated_at DESC LIMIT :limit';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, SQLITE3_INTEGER);
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
        $stmt = $this->db->prepare("
            INSERT INTO generations 
            (user_id, hash, text, voice_id, provider, format, duration, 
             audio_size_bytes, metadata, generated_at)
            VALUES (:user_id, :hash, :text, :voice_id, :provider, :format, 
                    :duration, :audio_size_bytes, :metadata, :generated_at)
        ");

        $stmt->bindValue(':user_id', $data['user_id'] ?? 1, SQLITE3_INTEGER);
        $stmt->bindValue(':hash', $data['hash'], SQLITE3_TEXT);
        $stmt->bindValue(':text', $data['text'], SQLITE3_TEXT);
        $stmt->bindValue(':voice_id', $data['voice_id'], SQLITE3_TEXT);
        $stmt->bindValue(':provider', $data['provider'] ?? 'elevenlabs', SQLITE3_TEXT);
        $stmt->bindValue(':format', $data['format'] ?? 'mp3', SQLITE3_TEXT);
        $stmt->bindValue(':duration', $data['duration'] ?? 0.0, SQLITE3_FLOAT);
        $stmt->bindValue(':audio_size_bytes', $data['audio_size_bytes'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':metadata', json_encode($data['metadata'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(':generated_at', $data['generated_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'), SQLITE3_TEXT);

        $stmt->execute();
    }

    public function saveCustomVoice(array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT OR IGNORE INTO voices 
            (name, voice_id, provider, is_cloned, user_id)
            VALUES (:name, :voice_id, :provider, 1, :user_id)
        ");

        $stmt->bindValue(':name', $data['name'], SQLITE3_TEXT);
        $stmt->bindValue(':voice_id', $data['voice_id'], SQLITE3_TEXT);
        $stmt->bindValue(':provider', $data['provider'] ?? 'elevenlabs', SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $data['user_id'] ?? 1, SQLITE3_INTEGER);
        $stmt->execute();
    }

    /**
     * Rich analytics data shaped for the interactive Chart.js dashboard.
     *
     * @return array<string, mixed>
     */
    public function getUsageStats(?int $userId = null, string $period = '30'): array
    {
        $where = $userId !== null ? 'WHERE user_id = :user_id' : '';
        $params = $userId !== null ? [':user_id' => $userId] : [];

        // Totals
        $sqlTotals = "
            SELECT 
                COUNT(*) as total_generations,
                COALESCE(SUM(duration), 0.0) as total_duration,
                COUNT(CASE WHEN provider = 'elevenlabs' THEN 1 END) as cloud_count,
                COUNT(CASE WHEN provider = 'piper' THEN 1 END) as local_count
            FROM generations 
            {$where}
        ";
        $stmt = $this->db->prepare($sqlTotals);
        if ($userId !== null) {
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();
        $totals = $result->fetchArray(SQLITE3_ASSOC) ?: [
            'total_generations' => 0,
            'total_duration' => 0.0,
            'cloud_count' => 0,
            'local_count' => 0,
        ];

        // Daily usage (last N days)
        $days = (int) $period;
        $sqlDaily = "
            SELECT 
                date(generated_at) as day,
                COUNT(*) as count,
                COALESCE(SUM(duration), 0.0) as duration
            FROM generations 
            {$where}
                AND generated_at >= date('now', '-{$days} days')
            GROUP BY day 
            ORDER BY day ASC
        ";
        $stmt = $this->db->prepare($sqlDaily);
        if ($userId !== null) {
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();
        $daily = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $daily[] = [
                'day' => $row['day'],
                'count' => (int) $row['count'],
                'duration' => round((float) $row['duration'], 2),
            ];
        }
        $daily = $this->fillDateGaps($daily, $days);

        // Provider breakdown
        $sqlProvider = "
            SELECT 
                provider,
                COUNT(*) as count,
                COALESCE(SUM(duration), 0.0) as total_duration
            FROM generations 
            {$where}
            GROUP BY provider
            ORDER BY count DESC
        ";
        $stmt = $this->db->prepare($sqlProvider);
        if ($userId !== null) {
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();
        $providers = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $providers[] = [
                'provider' => $row['provider'],
                'count' => (int) $row['count'],
                'total_duration' => round((float) $row['total_duration'], 2),
            ];
        }

        return [
            'total_generations' => (int) $totals['total_generations'],
            'total_duration' => round((float) $totals['total_duration'], 2),
            'cloud_count' => (int) $totals['cloud_count'],
            'local_count' => (int) $totals['local_count'],
            'daily_usage' => $daily,
            'provider_breakdown' => $providers,
            'period_days' => $days,
        ];
    }

    /**
     * Ensure every day in the range has an entry (zero-filled) for clean charts.
     *
     * @param array<array<string,mixed>> $data
     * @return array<array<string,mixed>>
     */
    private function fillDateGaps(array $data, int $days): array
    {
        $filled = [];
        $dataMap = [];
        foreach ($data as $row) {
            $dataMap[$row['day']] = $row;
        }

        $end = new DateTimeImmutable();
        $start = $end->modify("-{$days} days");

        $current = $start;
        while ($current <= $end) {
            $dayStr = $current->format('Y-m-d');
            if (isset($dataMap[$dayStr])) {
                $filled[] = $dataMap[$dayStr];
            } else {
                $filled[] = [
                    'day' => $dayStr,
                    'count' => 0,
                    'duration' => 0.0,
                ];
            }
            $current = $current->modify('+1 day');
        }

        return $filled;
    }
}
