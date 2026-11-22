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
 * Central persistence layer using SQLite. Manages schema, generations, voices, users, scheduled jobs,
 * usage analytics and the token revocation list.
 */
class SqliteRepository
{
    private PDO $db;

    public function __construct(string $path = 'data/voxora.db')
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("Could not create directory $dir");
            }
        }

        $this->db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->createSchema();
    }

    private function createSchema(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "user",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                token_id TEXT,
                text TEXT NOT NULL,
                voice_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                duration REAL DEFAULT 0,
                audio_path TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS voices (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                provider TEXT NOT NULL,
                is_cloned INTEGER DEFAULT 0,
                sample_path TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS scheduled_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                cron_expression TEXT NOT NULL,
                csv_path TEXT NOT NULL,
                default_options TEXT,
                last_run_at DATETIME,
                last_status TEXT,
                last_message TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS revoked_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_id TEXT UNIQUE NOT NULL,
                revoked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME
            )
        ');

        $count = (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count === 0) {
            $this->db->exec("INSERT INTO users (username, password_hash, role) VALUES 
                ('admin', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'admin'),
                ('user', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'user')
            ");
        }

        $count = (int) $this->db->query('SELECT COUNT(*) FROM voices')->fetchColumn();
        if ($count === 0) {
            $this->db->exec("INSERT INTO voices (id, name, provider, is_cloned) VALUES 
                ('21m00Tcm4TlvDq8ikWAM', 'Rachel', 'elevenlabs', 0),
                ('EXAVITQu4vr4xnSDxMaL', 'Bella', 'elevenlabs', 0),
                ('alloy', 'Alloy', 'openai', 0)
            ");
        }
    }

    public function isTokenRevoked(string $tokenId): bool
    {
        $stmt = $this->db->prepare('
            SELECT 1 FROM revoked_tokens 
            WHERE token_id = :token_id 
            AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
        ');
        $stmt->execute([':token_id' => $tokenId]);
        return $stmt->fetch() !== false;
    }

    public function addToRevocationList(string $tokenId, string $expiresAt): void
    {
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO revoked_tokens (token_id, expires_at) 
            VALUES (:token_id, :expires_at)
        ');
        $stmt->execute([
            ':token_id' => $tokenId,
            ':expires_at' => $expiresAt,
        ]);
    }

    public function storeIssuedToken(string $tokenId, string $name, DateTimeImmutable $expiresAt, string $role, int $userId): void
    {
        // persisted via other tables; revocation list is separate
    }

    public function getHistory(array $params = []): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(50, max(5, (int) ($params['per_page'] ?? 10)));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $bindings = [];
        if (!empty($params['search'])) {
            $where[] = 'text LIKE :search';
            $bindings[':search'] = '%' . $params['search'] . '%';
        }
        if (!empty($params['provider'])) {
            $where[] = 'provider = :provider';
            $bindings[':provider'] = $params['provider'];
        }
        if (!empty($params['date_from'])) {
            $where[] = 'created_at >= :date_from';
            $bindings[':date_from'] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $where[] = 'created_at <= :date_to';
            $bindings[':date_to'] = $params['date_to'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT * FROM generations {$whereSql} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        $countSql = "SELECT COUNT(*) FROM generations {$whereSql}";
        $countStmt = $this->db->prepare($countSql);
        foreach ($bindings as $key => $value) {
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
            'filters' => array_filter($params),
        ];
    }

    public function recordGeneration(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO generations (user_id, token_id, text, voice_id, provider, duration, audio_path)
            VALUES (:user_id, :token_id, :text, :voice_id, :provider, :duration, :audio_path)
        ');
        $stmt->execute([
            ':user_id' => $data['user_id'] ?? null,
            ':token_id' => $data['token_id'] ?? null,
            ':text' => $data['text'],
            ':voice_id' => $data['voice_id'],
            ':provider' => $data['provider'] ?? 'elevenlabs',
            ':duration' => $data['duration'] ?? 0.0,
            ':audio_path' => $data['audio_path'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function listLibrary(): array
    {
        $stmt = $this->db->query('SELECT * FROM voices ORDER BY is_cloned DESC, name ASC');
        return $stmt->fetchAll() ?: [];
    }

    public function saveCustomVoice(array $voice): bool
    {
        $stmt = $this->db->prepare('
            INSERT OR REPLACE INTO voices (id, name, provider, is_cloned, sample_path)
            VALUES (:id, :name, :provider, 1, :sample_path)
        ');
        return $stmt->execute([
            ':id' => $voice['id'],
            ':name' => $voice['name'],
            ':provider' => $voice['provider'] ?? 'elevenlabs',
            ':sample_path' => $voice['sample_path'] ?? null,
        ]);
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
            throw new InvalidArgumentException('Invalid bundle format');
        }

        $imported = 0;
        foreach ($bundle['voices'] as $voice) {
            if (!isset($voice['id'], $voice['name'])) {
                continue;
            }

            $existing = $this->db->prepare('SELECT 1 FROM voices WHERE id = :id');
            $existing->execute([':id' => $voice['id']]);
            if ($existing->fetch()) {
                continue;
            }

            $this->saveCustomVoice([
                'id' => $voice['id'],
                'name' => $voice['name'],
                'provider' => $voice['provider'] ?? 'elevenlabs',
                'sample_path' => $voice['sample_path'] ?? null,
            ]);
            $imported++;
        }

        return $imported;
    }

    public function getUsageStats(string $period = '30d'): array
    {
        $days = match ($period) {
            '7d' => 7,
            '60d' => 60,
            default => 30,
        };

        $stmt = $this->db->prepare('
            SELECT 
                date(created_at) as date, 
                provider, 
                COUNT(*) as count, 
                SUM(duration) as total_duration
            FROM generations 
            WHERE created_at >= date("now", :days || " days")
            GROUP BY date(created_at), provider
            ORDER BY date(created_at)
        ');
        $stmt->execute([':days' => -$days]);
        $rows = $stmt->fetchAll();

        $byProvider = [];
        $totalGenerations = 0;
        $totalDuration = 0.0;
        foreach ($rows as $row) {
            $p = $row['provider'];
            $byProvider[$p] = ($byProvider[$p] ?? 0) + (int) $row['count'];
            $totalGenerations += (int) $row['count'];
            $totalDuration += (float) $row['total_duration'];
        }

        return [
            'daily' => $rows,
            'by_provider' => $byProvider,
            'total_generations' => $totalGenerations,
            'total_duration' => round($totalDuration, 2),
        ];
    }

    public function listScheduledJobs(): array
    {
        $stmt = $this->db->query('SELECT * FROM scheduled_jobs ORDER BY created_at DESC');
        return $stmt->fetchAll() ?: [];
    }

    public function saveScheduledJob(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO scheduled_jobs (name, cron_expression, csv_path, default_options)
            VALUES (:name, :cron, :csv, :options)
        ');
        $stmt->execute([
            ':name' => $data['name'],
            ':cron' => $data['cron_expression'],
            ':csv' => $data['csv_path'],
            ':options' => isset($data['default_options']) ? json_encode($data['default_options']) : null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function getScheduledJob(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM scheduled_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function updateScheduledJob(int $id, array $data): bool
    {
        $sets = [];
        $bindings = [':id' => $id];
        if (isset($data['name'])) {
            $sets[] = 'name = :name';
            $bindings[':name'] = $data['name'];
        }
        if (isset($data['cron_expression'])) {
            $sets[] = 'cron_expression = :cron';
            $bindings[':cron'] = $data['cron_expression'];
        }
        if (isset($data['csv_path'])) {
            $sets[] = 'csv_path = :csv';
            $bindings[':csv'] = $data['csv_path'];
        }
        if (isset($data['default_options'])) {
            $sets[] = 'default_options = :options';
            $bindings[':options'] = json_encode($data['default_options']);
        }

        if (empty($sets)) {
            return false;
        }

        $sql = 'UPDATE scheduled_jobs SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($bindings);
    }

    public function deleteScheduledJob(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM scheduled_jobs WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function updateJobLastRun(int $id, string $status, string $message): bool
    {
        $stmt = $this->db->prepare('
            UPDATE scheduled_jobs 
            SET last_run_at = CURRENT_TIMESTAMP, last_status = :status, last_message = :message
            WHERE id = :id
        ');
        return $stmt->execute([
            ':id' => $id,
            ':status' => $status,
            ':message' => $message,
        ]);
    }

    public function findUserByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();
        return $user ?: null;
    }
}
