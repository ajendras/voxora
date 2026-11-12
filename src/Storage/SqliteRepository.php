<?php

declare(strict_types=1);

namespace Voxora\Storage;

use SQLite3;
use RuntimeException;
use DateTimeImmutable;
use Voxora\TTS\SynthesisResult;

/**
 * SqliteRepository
 *
 * Handles all persistence: users, generation history, cache, voice library and now custom cloned voices.
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
    }

    public function initialize(): void
    {
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                username TEXT UNIQUE,
                password_hash TEXT,
                role TEXT DEFAULT 'user',
                created_at TEXT
            );
SQL);

        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS generations (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                hash TEXT,
                text TEXT,
                voice_id TEXT,
                provider TEXT,
                format TEXT,
                duration REAL,
                audio_size_bytes INTEGER,
                generated_at TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id)
            );
SQL);

        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS voices (
                id INTEGER PRIMARY KEY,
                voice_id TEXT UNIQUE,
                name TEXT NOT NULL,
                provider TEXT NOT NULL,
                is_cloned INTEGER DEFAULT 0,
                user_id INTEGER,
                created_at TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id)
            );
SQL);

        $this->ensureDefaultUsers();
        $this->ensureDefaultVoices();
    }

    private function ensureDefaultUsers(): void
    {
        $count = $this->db->querySingle('SELECT COUNT(*) FROM users');
        if ($count == 0) {
            $this->db->exec("INSERT INTO users (username, password_hash, role, created_at) VALUES 
                ('admin', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'admin', '" . date('c') . "'),
                ('user', '" . password_hash('demo123', PASSWORD_DEFAULT) . "', 'user', '" . date('c') . "')
            ");
        }
    }

    private function ensureDefaultVoices(): void
    {
        $count = $this->db->querySingle('SELECT COUNT(*) FROM voices');
        if ($count == 0) {
            $voices = [
                ['21m00Tcm4TlvDq8ikWAM', 'Rachel', 'elevenlabs'],
                ['AZnzlkDJ2myd1k8p7Q6X', 'Domi', 'elevenlabs'],
                ['pNInz6obpgDQGcFmaJgB', 'Adam', 'elevenlabs'],
                ['EXAVITQu4vr4xnSDxMaL', 'Bella', 'elevenlabs'],
            ];
            $stmt = $this->db->prepare('INSERT INTO voices (voice_id, name, provider, is_cloned, created_at) VALUES (:vid, :name, :prov, 0, :cat)');
            foreach ($voices as $v) {
                $stmt->bindValue(':vid', $v[0]);
                $stmt->bindValue(':name', $v[1]);
                $stmt->bindValue(':prov', $v[2]);
                $stmt->bindValue(':cat', (new DateTimeImmutable())->format('c'));
                $stmt->execute();
            }
        }
    }

    public function authenticate(string $username, string $password): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->bindValue(':username', $username);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);
        if ($user && password_verify($password, $user['password_hash'])) {
            return $user;
        }
        return null;
    }

    public function listLibrary(): array
    {
        $result = $this->db->query('SELECT * FROM voices ORDER BY name');
        $voices = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $voices[] = [
                'id' => $row['voice_id'],
                'name' => $row['name'],
                'description' => $row['is_cloned'] ? 'Cloned voice • ' . $row['provider'] : 'Standard voice',
                'provider' => $row['provider'],
                'is_cloned' => (bool) $row['is_cloned'],
            ];
        }
        return $voices;
    }

    public function saveCustomVoice(array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO voices (voice_id, name, provider, is_cloned, user_id, created_at) 
             VALUES (:voice_id, :name, :provider, 1, :user_id, :created_at)'
        );
        $stmt->bindValue(':voice_id', $data['voice_id']);
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':provider', $data['provider']);
        $stmt->bindValue(':user_id', $data['user_id'] ?? 1);
        $stmt->bindValue(':created_at', $data['created_at'] ?? (new DateTimeImmutable())->format('c'));
        $stmt->execute();
    }

    public function getUsageStats(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) as generations, SUM(duration) as hours FROM generations WHERE user_id = :uid');
        $stmt->bindValue(':uid', $userId);
        $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return [
            'generations' => (int) ($res['generations'] ?? 0),
            'cost' => number_format((($res['generations'] ?? 0) * 0.12), 2),
            'hours' => number_format((($res['hours'] ?? 0) / 3600), 1),
        ];
    }

    public function saveGeneration(array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO generations 
             (user_id, hash, text, voice_id, provider, format, duration, audio_size_bytes, generated_at)
             VALUES (:uid, :hash, :text, :vid, :prov, :fmt, :dur, :size, :gat)'
        );
        $stmt->bindValue(':uid', $data['user_id'] ?? 1);
        $stmt->bindValue(':hash', $data['hash']);
        $stmt->bindValue(':text', $data['text']);
        $stmt->bindValue(':vid', $data['voice_id']);
        $stmt->bindValue(':prov', $data['provider']);
        $stmt->bindValue(':fmt', $data['format']);
        $stmt->bindValue(':dur', $data['duration']);
        $stmt->bindValue(':size', $data['audio_size_bytes']);
        $stmt->bindValue(':gat', $data['generated_at']);
        $stmt->execute();
    }

    public function getHistory(int $limit = 20, ?int $userId = null): array
    {
        $sql = 'SELECT * FROM generations';
        if ($userId !== null) {
            $sql .= ' WHERE user_id = :uid';
        }
        $sql .= ' ORDER BY generated_at DESC LIMIT :lim';
        $stmt = $this->db->prepare($sql);
        if ($userId !== null) {
            $stmt->bindValue(':uid', $userId);
        }
        $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $history = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $history[] = $row;
        }
        return $history;
    }

    public function getCachedResult(string $hash): ?SynthesisResult
    {
        return null;
    }

    public function cacheResult(string $hash, SynthesisResult $result): void
    {
    }

    public function getUser(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->bindValue(':id', $id);
        $res = $stmt->execute();
        return $res->fetchArray(SQLITE3_ASSOC) ?: null;
    }
}
