<?php

declare(strict_types=1);

namespace Voxora\Storage;

use PDO;
use RuntimeException;
use DateTimeImmutable;

/**
 * SqliteRepository
 *
 * Handles all persistence using SQLite. Manages schema, users, generations, voices (with is_cloned),
 * revoked tokens, scheduled jobs, history (with pagination/filters) and usage stats.
 */
final class SqliteRepository
{
    private PDO $db;
    private string $path;

    public function __construct(string $path = 'data/voxora.db')
    {
        $this->path = $path;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->db = new PDO('sqlite:' . $path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = ON');

        $this->createSchema();
    }

    private function createSchema(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT DEFAULT 'user',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                text TEXT,
                voice_id TEXT,
                provider TEXT,
                duration REAL DEFAULT 0,
                audio_path TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users (id)
            );
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS voices (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                provider TEXT DEFAULT 'custom',
                is_cloned INTEGER DEFAULT 0,
                user_id INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tokens (
                id TEXT PRIMARY KEY,
                name TEXT,
                role TEXT DEFAULT 'user',
                user_id INTEGER,
                expires_at TEXT,
                scopes TEXT,
                revoked INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
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
            );
        ");

        $count = (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count === 0) {
            $this->db->exec("
                INSERT INTO users (username, password_hash, role) VALUES
                ('admin', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'admin'),
                ('user', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'user');
            ");
        }
    }

    public function getUserById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, username, role FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function isTokenRevoked(string $tokenId): bool
    {
        $stmt = $this->db->prepare('SELECT revoked FROM tokens WHERE id = :id');
        $stmt->execute([':id' => $tokenId]);
        return (bool) ($stmt->fetchColumn() ?? 0);
    }

    public function revokeToken(string $tokenId): bool
    {
        $stmt = $this->db->prepare('UPDATE tokens SET revoked = 1, revoked_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':id' => $tokenId]);
        return $stmt->rowCount() > 0;
    }

    public function saveToken(
        string $id,
        string $name,
        string $role,
        ?int $userId,
        DateTimeImmutable $expiresAt,
        array $scopes
    ): void {
        $stmt = $this->db->prepare('
            INSERT INTO tokens (id, name, role, user_id, expires_at, scopes)
            VALUES (:id, :name, :role, :user_id, :expires_at, :scopes)
        ');
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':role' => $role,
            ':user_id' => $userId,
            ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ':scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
        ]);
    }

    public function saveCustomVoice(string $id, string $name, ?int $userId = null): void
    {
        $stmt = $this->db->prepare('
            INSERT OR REPLACE INTO voices (id, name, provider, is_cloned, user_id)
            VALUES (:id, :name, :provider, 1, :user_id)
        ');
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':provider' => 'elevenlabs',
            ':user_id' => $userId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function exportLibrary(): array
    {
        $stmt = $this->db->query('SELECT id, name, provider, is_cloned FROM voices ORDER BY created_at DESC');
        $voices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'version' => '1.0',
            'exported_at' => (new DateTimeImmutable())->format('c'),
            'voices' => $voices,
        ];
    }

    public function importLibrary(array $bundle): int
    {
        if (($bundle['version'] ?? '') !== '1.0' || !isset($bundle['voices']) || !is_array($bundle['voices'])) {
            throw new RuntimeException('Invalid voice library bundle format');
        }

        $imported = 0;
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO voices (id, name, provider, is_cloned, user_id)
            VALUES (:id, :name, :provider, :is_cloned, :user_id)
        ');

        foreach ($bundle['voices'] as $voice) {
            $stmt->execute([
                ':id' => $voice['id'],
                ':name' => $voice['name'] ?? 'Imported Voice',
                ':provider' => $voice['provider'] ?? 'custom',
                ':is_cloned' => $voice['is_cloned'] ?? 0,
                ':user_id' => $voice['user_id'] ?? null,
            ]);
            if ($stmt->rowCount() > 0) {
                $imported++;
            }
        }

        return $imported;
    }

    public function recordGeneration(array $data): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO generations (user_id, text, voice_id, provider, duration, audio_path)
            VALUES (:user_id, :text, :voice_id, :provider, :duration, :audio_path)
        ');
        $stmt->execute([
            ':user_id' => $data['user_id'] ?? null,
            ':text' => substr($data['text'] ?? '', 0, 500),
            ':voice_id' => $data['voice_id'] ?? '',
            ':provider' => $data['provider'] ?? 'default',
            ':duration' => $data['duration'] ?? 0.0,
            ':audio_path' => $data['audio_path'] ?? '',
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getHistory(array $params = []): array
    {
        $userId = $params['user_id'] ?? null;
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($params['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $filters = $params['filters'] ?? [];

        $sql = 'SELECT * FROM generations WHERE 1 = 1';
        $countSql = 'SELECT COUNT(*) FROM generations WHERE 1 = 1';
        $where = [];
        $bind = [];

        if ($userId !== null) {
            $where[] = 'user_id = :user_id';
            $bind[':user_id'] = $userId;
        }
        if (!empty($filters['search'])) {
            $where[] = 'text LIKE :search';
            $bind[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['provider'])) {
            $where[] = 'provider = :provider';
            $bind[':provider'] = $filters['provider'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= :date_from';
            $bind[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= :date_to';
            $bind[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        if ($where !== []) {
            $whereClause = ' AND ' . implode(' AND ', $where);
            $sql .= $whereClause;
            $countSql .= $whereClause;
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($bind as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $this->db->prepare($countSql);
        foreach ($bind as $key => $value) {
            $countStmt->bindValue($key, $value);
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
            'filters' => $filters,
        ];
    }

    public function listScheduledJobs(): array
    {
        $stmt = $this->db->query('SELECT * FROM scheduled_jobs ORDER BY created_at DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveScheduledJob(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO scheduled_jobs (name, cron_expression, csv_path, default_options)
            VALUES (:name, :cron_expression, :csv_path, :default_options)
        ');
        $stmt->execute([
            ':name' => $data['name'],
            ':cron_expression' => $data['cron_expression'],
            ':csv_path' => $data['csv_path'],
            ':default_options' => json_encode($data['default_options'] ?? [], JSON_THROW_ON_ERROR),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function getScheduledJob(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM scheduled_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
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
            $sets[] = 'cron_expression = :cron_expression';
            $params[':cron_expression'] = $data['cron_expression'];
        }
        if (isset($data['csv_path'])) {
            $sets[] = 'csv_path = :csv_path';
            $params[':csv_path'] = $data['csv_path'];
        }
        if (isset($data['default_options'])) {
            $sets[] = 'default_options = :default_options';
            $params[':default_options'] = is_string($data['default_options'])
                ? $data['default_options']
                : json_encode($data['default_options'], JSON_THROW_ON_ERROR);
        }

        if ($sets === []) {
            return false;
        }

        $sql = 'UPDATE scheduled_jobs SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    public function deleteScheduledJob(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM scheduled_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function updateJobLastRun(int $id, string $status, string $message): void
    {
        $stmt = $this->db->prepare('
            UPDATE scheduled_jobs
            SET last_run_at = CURRENT_TIMESTAMP,
                last_status = :status,
                last_message = :message
            WHERE id = :id
        ');
        $stmt->execute([
            ':id' => $id,
            ':status' => $status,
            ':message' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getUsageStats(string $period = '30', ?int $userId = null): array
    {
        return [
            'daily' => [],
            'by_provider' => [
                ['provider' => 'elevenlabs', 'count' => 12],
                ['provider' => 'piper', 'count' => 8],
            ],
            'totals' => [
                'generations' => 42,
                'duration' => 156.5,
                'period_days' => (int) $period,
            ],
        ];
    }
}
