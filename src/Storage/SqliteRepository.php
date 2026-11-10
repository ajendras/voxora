<?php

declare(strict_types=1);

namespace Voxora\Storage;

use DateTimeImmutable;
use SQLite3;
use RuntimeException;

/**
 * SqliteRepository
 *
 * SQLite-backed storage for generations, voice library and usage analytics.
 * Creates schema on first use and provides clean query methods with input
 * sanitization via prepared statements.
 */
final class SqliteRepository
{
    private SQLite3 $db;
    private string $path;

    public function __construct(string $path)
    {
        if ($path === '') {
            throw new RuntimeException('Database path cannot be empty.');
        }
        $this->path = $path;
    }

    /**
     * Initialize the database and schema. Idempotent.
     */
    public function initialize(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create database directory: ' . $dir);
            }
        }

        $this->db = new SQLite3($this->path);
        $this->db->enableExceptions(true);
        $this->db->exec('PRAGMA journal_mode=WAL;');
        $this->db->exec('PRAGMA foreign_keys=ON;');

        $this->createTables();
    }

    private function createTables(): void
    {
        // Generations / history
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hash TEXT UNIQUE NOT NULL,
                text TEXT NOT NULL,
                voice_id TEXT NOT NULL,
                provider TEXT NOT NULL DEFAULT 'elevenlabs',
                format TEXT NOT NULL DEFAULT 'mp3',
                duration REAL,
                metadata TEXT,
                audio_size_bytes INTEGER DEFAULT 0,
                generated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Voice library
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS voices (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT,
                provider TEXT NOT NULL DEFAULT 'elevenlabs',
                tags TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Seed demo voices if table is empty
        $count = (int) $this->db->querySingle('SELECT COUNT(*) FROM voices');
        if ($count === 0) {
            $this->seedDemoVoices();
        }

        // Index for fast lookups
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_generations_hash ON generations(hash);');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_generations_date ON generations(generated_at DESC);');
    }

    private function seedDemoVoices(): void
    {
        $voices = [
            [
                'id' => '21m00Tcm4TlvDq8ikWAM',
                'name' => 'Rachel',
                'description' => 'American • Warm & versatile',
                'provider' => 'elevenlabs',
                'tags' => json_encode(['warm', 'female', 'english']),
            ],
            [
                'id' => 'AZnzlkDJ2myd1k8p7Q6X',
                'name' => 'Domi',
                'description' => 'American • Upbeat & clear',
                'provider' => 'elevenlabs',
                'tags' => json_encode(['upbeat', 'female', 'english']),
            ],
            [
                'id' => 'pNInz6obpgDQGcFmaJgB',
                'name' => 'Adam',
                'description' => 'American • Deep & authoritative',
                'provider' => 'elevenlabs',
                'tags' => json_encode(['deep', 'male', 'english']),
            ],
            [
                'id' => 'EXAVITQu4vr4xnSDxMaL',
                'name' => 'Bella',
                'description' => 'American • Friendly & expressive',
                'provider' => 'elevenlabs',
                'tags' => json_encode(['friendly', 'female', 'english']),
            ],
        ];

        $stmt = $this->db->prepare(
            'INSERT INTO voices (id, name, description, provider, tags) VALUES (:id, :name, :description, :provider, :tags)'
        );

        foreach ($voices as $voice) {
            $stmt->bindValue(':id', $voice['id'], SQLITE3_TEXT);
            $stmt->bindValue(':name', $voice['name'], SQLITE3_TEXT);
            $stmt->bindValue(':description', $voice['description'], SQLITE3_TEXT);
            $stmt->bindValue(':provider', $voice['provider'], SQLITE3_TEXT);
            $stmt->bindValue(':tags', $voice['tags'], SQLITE3_TEXT);
            $stmt->execute();
        }
    }

    /**
     * Persist a generation record for analytics, caching and audit.
     */
    public function saveGeneration(array $data): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO generations (
                hash, text, voice_id, provider, format, duration,
                metadata, audio_size_bytes, generated_at
            ) VALUES (
                :hash, :text, :voice_id, :provider, :format, :duration,
                :metadata, :audio_size_bytes, :generated_at
            )
        ');

        $stmt->bindValue(':hash', $data['hash'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':text', $data['text'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':voice_id', $data['voice_id'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':provider', $data['provider'] ?? 'elevenlabs', SQLITE3_TEXT);
        $stmt->bindValue(':format', $data['format'] ?? 'mp3', SQLITE3_TEXT);
        $stmt->bindValue(':duration', $data['duration'] ?? null, SQLITE3_FLOAT);
        $metadata = $data['metadata'] ?? [];
        $stmt->bindValue(':metadata', is_array($metadata) ? json_encode($metadata) : (string) $metadata, SQLITE3_TEXT);
        $stmt->bindValue(':audio_size_bytes', $data['audio_size_bytes'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':generated_at', $data['generated_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'), SQLITE3_TEXT);

        $stmt->execute();
    }

    /**
     * Retrieve cached generation by content hash.
     */
    public function findByHash(string $hash): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM generations WHERE hash = :hash LIMIT 1');
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row === false) {
            return null;
        }

        if (isset($row['metadata']) && is_string($row['metadata'])) {
            $row['metadata'] = json_decode($row['metadata'], true) ?? [];
        }

        return $row;
    }

    /**
     * Return all voices from the library.
     *
     * @return array<array<string, mixed>>
     */
    public function listLibrary(): array
    {
        $result = $this->db->query('SELECT * FROM voices ORDER BY name ASC');
        $voices = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (isset($row['tags']) && is_string($row['tags'])) {
                $row['tags'] = json_decode($row['tags'], true) ?? [];
            }
            $voices[] = $row;
        }

        return $voices;
    }

    /**
     * Return usage statistics for the dashboard.
     */
    public function getUsageStats(): array
    {
        $generations = (int) $this->db->querySingle('SELECT COUNT(*) FROM generations');

        $agg = $this->db->query('
            SELECT 
                COALESCE(SUM(duration), 0) as total_duration,
                COALESCE(SUM(audio_size_bytes), 0) as total_bytes
            FROM generations
        ')->fetchArray(SQLITE3_ASSOC);

        $hours = (float) ($agg['total_duration'] ?? 0) / 3600.0;
        // Mock cost based on character usage (real implementation would parse metadata)
        $estCost = round($generations * 0.018, 2);

        return [
            'generations' => $generations,
            'cost' => number_format($estCost, 2),
            'hours' => round($hours, 2),
            'total_bytes' => (int) ($agg['total_bytes'] ?? 0),
        ];
    }

    /**
     * Return recent generation history (used by API and future UI).
     *
     * @return array<array<string, mixed>>
     */
    public function getHistory(int $limit = 20): array
    {
        $stmt = $this->db->prepare('
            SELECT 
                id,
                text,
                voice_id,
                provider,
                duration,
                metadata,
                generated_at
            FROM generations 
            ORDER BY generated_at DESC 
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', max(1, min(200, $limit)), SQLITE3_INTEGER);
        $result = $stmt->execute();

        $history = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (isset($row['metadata']) && is_string($row['metadata'])) {
                $row['metadata'] = json_decode($row['metadata'], true) ?? [];
            }
            $history[] = $row;
        }

        return $history;
    }

    public function __destruct()
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
}
