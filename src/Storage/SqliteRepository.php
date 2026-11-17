<?php

declare(strict_types=1);

namespace Voxora\Storage;

use PDO;
use PDOException;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * SqliteRepository
 *
 * Handles all persistence using a single SQLite file.
 * Manages users, generations, voices (including cloned), scheduled_jobs and analytics.
 * Now includes exportLibrary() for portable JSON voice bundles.
 */
final class SqliteRepository
{
    private PDO $pdo;
    private string $dbPath;

    public function __construct(string $dbPath = 'data/voxora.db')
    {
        $this->dbPath = $dbPath;
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create data directory: ' . $dir);
            }
        }

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY,
                hash TEXT UNIQUE,
                text TEXT NOT NULL,
                voice_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                format TEXT DEFAULT 'mp3',
                duration REAL,
                metadata TEXT,
                audio_size_bytes INTEGER,
                generated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                user_id INTEGER,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS voices (
                id INTEGER PRIMARY KEY,
                voice_id TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                description TEXT,
                provider TEXT NOT NULL DEFAULT 'elevenlabs',
                is_cloned INTEGER DEFAULT 0,
                sample_hash TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                user_id INTEGER,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS scheduled_jobs (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                cron_expression TEXT NOT NULL,
                csv_path TEXT NOT NULL,
                default_options TEXT,
                last_run TEXT,
                is_active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM users');
        if ((int) $stmt->fetchColumn() === 0) {
            $this->seedDemoData();
        }
    }

    private function seedDemoData(): void
    {
        $this->pdo->exec("INSERT INTO users (username, password_hash, role) VALUES 
            ('admin', '" . password_hash('demo123', PASSWORD_BCRYPT) . "', 'admin'),
            ('user', '" . password_hash('demo123', PASSWORD_BCRYPT) . "', 'user')
        ");

        $this->pdo->exec("INSERT INTO voices (voice_id, name, description, provider, is_cloned) VALUES 
            ('21m00Tcm4TlvDq8ikWAM', 'Rachel', 'American • Warm & versatile', 'elevenlabs', 0),
            ('AZnzlkDJ2myd1k8p7Q6X', 'Domi', 'American • Upbeat & clear', 'elevenlabs', 0),
            ('pNInz6obpgDQGcFmaJgB', 'Adam', 'American • Deep & authoritative', 'elevenlabs', 0),
            ('EXAVITQu4vr4xnSDxMaL', 'Bella', 'American • Friendly & expressive', 'elevenlabs', 0)
        ");
    }

    public function listLibrary(): array
    {
        $stmt = $this->pdo->query('
            SELECT 
                voice_id as id,
                name,
                description,
                provider,
                is_cloned as cloned,
                created_at
            FROM voices 
            ORDER BY name ASC
        ');

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveGeneration(array $data): void
    {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO generations 
            (hash, text, voice_id, provider, format, duration, metadata, audio_size_bytes, generated_at, user_id)
            VALUES (:hash, :text, :voice_id, :provider, :format, :duration, :metadata, :audio_size_bytes, :generated_at, :user_id)
        ');

        $stmt->execute([
            ':hash' => $data['hash'] ?? null,
            ':text' => $data['text'],
            ':voice_id' => $data['voice_id'],
            ':provider' => $data['provider'] ?? 'elevenlabs',
            ':format' => $data['format'] ?? 'mp3',
            ':duration' => $data['duration'] ?? null,
            ':metadata' => json_encode($data['metadata'] ?? []),
            ':audio_size_bytes' => $data['audio_size_bytes'] ?? strlen($data['audio_content'] ?? ''),
            ':generated_at' => $data['generated_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':user_id' => $data['user_id'] ?? 1,
        ]);
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
        $params[':limit'] = min(100, max(1, $limit));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as &$row) {
            $row['metadata'] = json_decode($row['metadata'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    public function getUsageStats(?int $userId = null, string $period = '30'): array
    {
        $days = (int) $period;
        $sql = '
            SELECT 
                COUNT(*) as total,
                COALESCE(SUM(duration), 0) as total_duration,
                provider,
                DATE(generated_at) as day
            FROM generations
        ';

        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $sql .= ' GROUP BY provider, day ORDER BY day DESC LIMIT :limit';
        $params[':limit'] = $days;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveCustomVoice(array $voiceData): void
    {
        $stmt = $this->pdo->prepare('
            INSERT OR IGNORE INTO voices 
            (voice_id, name, description, provider, is_cloned, user_id)
            VALUES (:voice_id, :name, :description, :provider, 1, :user_id)
        ');

        $stmt->execute([
            ':voice_id' => $voiceData['voice_id'],
            ':name' => $voiceData['name'],
            ':description' => $voiceData['description'] ?? 'Cloned voice via API',
            ':provider' => $voiceData['provider'] ?? 'elevenlabs',
            ':user_id' => $voiceData['user_id'] ?? 1,
        ]);
    }

    /**
     * Export the complete voice library as a portable, versioned JSON bundle.
     *
     * The returned array is ready to be json_encoded. It includes metadata for
     * versioning and future import compatibility.
     */
    public function exportLibrary(): array
    {
        $voices = $this->listLibrary();

        return [
            'version' => '1.0',
            'exported_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            'generated_by' => 'Voxora',
            'total_voices' => count($voices),
            'voices' => array_map(static function (array $voice): array {
                return [
                    'id' => $voice['id'],
                    'name' => $voice['name'],
                    'description' => $voice['description'] ?? '',
                    'provider' => $voice['provider'],
                    'cloned' => (bool) $voice['cloned'],
                ];
            }, $voices),
        ];
    }

    /**
     * Import voices from a previously exported JSON bundle.
     * Returns the number of voices successfully imported (skips duplicates).
     */
    public function importLibrary(array $bundle): int
    {
        if (!isset($bundle['voices']) || !is_array($bundle['voices'])) {
            throw new InvalidArgumentException('Invalid voice bundle: missing "voices" array.');
        }

        $imported = 0;

        foreach ($bundle['voices'] as $voice) {
            if (empty($voice['id']) || empty($voice['name'])) {
                continue;
            }

            try {
                $stmt = $this->pdo->prepare('
                    INSERT OR IGNORE INTO voices 
                    (voice_id, name, description, provider, is_cloned)
                    VALUES (:id, :name, :description, :provider, :cloned)
                ');

                $stmt->execute([
                    ':id' => $voice['id'],
                    ':name' => $voice['name'],
                    ':description' => $voice['description'] ?? null,
                    ':provider' => $voice['provider'] ?? 'elevenlabs',
                    ':cloned' => !empty($voice['cloned']) ? 1 : 0,
                ]);

                if ($stmt->rowCount() > 0) {
                    $imported++;
                }
            } catch (PDOException) {
                // silently skip malformed rows
            }
        }

        return $imported;
    }
}
