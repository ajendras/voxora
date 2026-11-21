<?php

declare(strict_types=1);

namespace Voxora\Storage;

use PDO;
use PDOException;
use DateTimeImmutable;
use RuntimeException;

/**
 * SqliteRepository
 *
 * Central persistence layer using SQLite. Creates all tables on first boot.
 * Now includes getHistory() that supports pagination and multiple filters
 * (search by text, provider, date range) while respecting user scoping.
 */
final class SqliteRepository
{
    private PDO $pdo;
    private string $dbPath;

    public function __construct(string $dbPath = 'data/voxora.db')
    {
        $this->dbPath = $dbPath;
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON;');

        $this->createTables();
    }

    private function createTables(): void
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
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hash TEXT,
                text TEXT,
                voice_id TEXT,
                provider TEXT,
                format TEXT DEFAULT 'mp3',
                duration REAL,
                metadata TEXT,
                audio_size_bytes INTEGER DEFAULT 0,
                generated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                user_id INTEGER DEFAULT 1,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS voices (
                id TEXT PRIMARY KEY,
                name TEXT,
                description TEXT,
                provider TEXT DEFAULT 'elevenlabs',
                is_cloned INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                user_id INTEGER DEFAULT 1
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS scheduled_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                cron_expression TEXT NOT NULL,
                csv_path TEXT NOT NULL,
                default_options TEXT,
                last_run_at TEXT,
                last_status TEXT,
                last_summary TEXT
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS revoked_tokens (
                token_hash TEXT PRIMARY KEY,
                revoked_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Seed demo users if none exist
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM users');
        if ((int) $stmt->fetchColumn() === 0) {
            $this->pdo->exec("
                INSERT INTO users (username, password_hash, role) VALUES
                ('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
                ('user', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user')
            ");
        }

        // Seed a few demo voices
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM voices');
        if ((int) $stmt->fetchColumn() === 0) {
            $this->pdo->exec("
                INSERT INTO voices (id, name, description, provider, is_cloned) VALUES
                ('21m00Tcm4TlvDq8ikWAM', 'Rachel', 'American • Warm & versatile', 'elevenlabs', 0),
                ('AZnzlkDJ2myd1k8p7Q6X', 'Domi', 'American • Upbeat & clear', 'elevenlabs', 0)
            ");
        }
    }

    public function saveGeneration(array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO generations 
             (hash, text, voice_id, provider, format, duration, metadata, audio_size_bytes, generated_at, user_id)
             VALUES (:hash, :text, :voice_id, :provider, :format, :duration, :metadata, :audio_size_bytes, :generated_at, :user_id)'
        );

        $stmt->execute([
            ':hash' => $data['hash'],
            ':text' => $data['text'],
            ':voice_id' => $data['voice_id'],
            ':provider' => $data['provider'],
            ':format' => $data['format'] ?? 'mp3',
            ':duration' => $data['duration'] ?? null,
            ':metadata' => is_array($data['metadata'] ?? null) ? json_encode($data['metadata']) : ($data['metadata'] ?? null),
            ':audio_size_bytes' => $data['audio_size_bytes'] ?? 0,
            ':generated_at' => $data['generated_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':user_id' => $data['user_id'] ?? 1,
        ]);
    }

    /**
     * Returns generations with pagination and optional filters.
     * Filters: search (text LIKE), provider, date_from, date_to.
     *
     * @return array{items: array, pagination: array, filters: array}
     */
    public function getHistory(array $params = []): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $userId = $params['user_id'] ?? null;
        $provider = isset($params['provider']) && $params['provider'] !== '' ? strtolower(trim($params['provider'])) : null;
        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;
        $search = isset($params['search']) ? trim($params['search']) : null;

        $where = [];
        $bind = [];

        if ($userId !== null) {
            $where[] = 'user_id = :user_id';
            $bind[':user_id'] = $userId;
        }
        if ($provider !== null) {
            $where[] = 'provider = :provider';
            $bind[':provider'] = $provider;
        }
        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 'generated_at >= :date_from';
            $bind[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 'generated_at <= :date_to';
            $bind[':date_to'] = $dateTo . ' 23:59:59';
        }
        if ($search !== null && $search !== '') {
            $where[] = 'text LIKE :search';
            $bind[':search'] = '%' . $search . '%';
        }

        $whereClause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $sql = 'SELECT id, text, voice_id, provider, format, duration, generated_at, user_id 
                FROM generations' . $whereClause . '
                ORDER BY generated_at DESC 
                LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($bind as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Count total for pagination
        $countSql = 'SELECT COUNT(*) FROM generations' . $whereClause;
        $countStmt = $this->pdo->prepare($countSql);
        foreach ($bind as $key => $val) {
            $countStmt->bindValue($key, $val);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $totalPages = (int) ceil($total / $perPage);

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_items' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1,
            ],
            'filters' => [
                'search' => $search,
                'provider' => $provider,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ];
    }

    public function listLibrary(?int $userId = null): array
    {
        $sql = 'SELECT * FROM voices';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :user_id OR user_id IS NULL';
            $params[':user_id'] = $userId;
        }
        $sql .= ' ORDER BY name';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
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
        if (!isset($bundle['version']) || $bundle['version'] !== '1.0' || !isset($bundle['voices'])) {
            throw new RuntimeException('Invalid bundle format');
        }

        $imported = 0;
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO voices (id, name, description, provider, is_cloned, user_id) 
             VALUES (:id, :name, :description, :provider, :is_cloned, :user_id)'
        );

        foreach ($bundle['voices'] as $voice) {
            $stmt->execute([
                ':id' => $voice['id'],
                ':name' => $voice['name'] ?? 'Unnamed',
                ':description' => $voice['description'] ?? '',
                ':provider' => $voice['provider'] ?? 'elevenlabs',
                ':is_cloned' => $voice['is_cloned'] ?? 0,
                ':user_id' => $voice['user_id'] ?? 1,
            ]);
            if ($stmt->rowCount() > 0) {
                $imported++;
            }
        }

        return $imported;
    }

    public function getUsageStats(?int $userId = null, string $period = '30'): array
    {
        // Simplified for demo - returns ready-to-chart data
        $days = (int) $period;
        $data = [];
        $now = new DateTimeImmutable();

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $now->modify("-{$i} days")->format('Y-m-d');
            $data[] = [
                'date' => $date,
                'generations' => rand(5, 25),
                'duration' => round(rand(30, 180) / 10, 1),
                'provider' => rand(0, 1) ? 'elevenlabs' : 'openai',
            ];
        }

        return [
            'daily' => $data,
            'by_provider' => [
                ['provider' => 'elevenlabs', 'count' => 45, 'percent' => 65],
                ['provider' => 'openai', 'count' => 18, 'percent' => 26],
                ['provider' => 'piper', 'count' => 6, 'percent' => 9],
            ],
            'summary' => [
                'total_generations' => 124,
                'total_minutes' => 87.4,
            ],
        ];
    }

    public function listScheduledJobs(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM scheduled_jobs ORDER BY name');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getScheduledJob(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scheduled_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveScheduledJob(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO scheduled_jobs (name, cron_expression, csv_path, default_options) 
             VALUES (:name, :cron, :csv, :options)'
        );
        $stmt->execute([
            ':name' => $data['name'],
            ':cron' => $data['cron_expression'],
            ':csv' => $data['csv_path'],
            ':options' => $data['default_options'] ?? '[]',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateScheduledJob(int $id, array $data): bool
    {
        $sets = [];
        $params = [':id' => $id];
        if (isset($data['name'])) {
            $sets[] = 'name = :name';
            $params[':name'] = $data['name'];
        }
        if (isset($data['cron_expression'])) {
            $sets[] = 'cron_expression = :cron';
            $params[':cron'] = $data['cron_expression'];
        }
        if (isset($data['csv_path'])) {
            $sets[] = 'csv_path = :csv';
            $params[':csv'] = $data['csv_path'];
        }
        if (isset($data['default_options'])) {
            $sets[] = 'default_options = :options';
            $params[':options'] = $data['default_options'];
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
        return $stmt->execute([':id' => $id]);
    }

    public function updateJobLastRun(int $id, string $status, string $summary): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE scheduled_jobs 
             SET last_run_at = CURRENT_TIMESTAMP, last_status = :status, last_summary = :summary 
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':status' => $status,
            ':summary' => $summary,
        ]);
    }

    public function revokeToken(string $tokenHash): void
    {
        $stmt = $this->pdo->prepare('INSERT OR REPLACE INTO revoked_tokens (token_hash) VALUES (:hash)');
        $stmt->execute([':hash' => $tokenHash]);
    }

    public function isTokenRevoked(string $tokenHash): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM revoked_tokens WHERE token_hash = :hash');
        $stmt->execute([':hash' => $tokenHash]);
        return (bool) $stmt->fetchColumn();
    }

    public function getUserByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
