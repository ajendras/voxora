<?php

declare(strict_types=1);

namespace Voxora\Http;

use Voxora\TTS\Manager;
use Voxora\Storage\SqliteRepository;
use RuntimeException;
use DateTimeImmutable;

/**
 * DashboardController
 *
 * HTMX-aware controller responsible for the responsive UI.
 * Renders Tailwind-powered dashboard and library pages (full HTML) and
 * returns HTML fragments for instant synthesis results.
 * Now includes session-based authentication and role-based UI.
 */
final class DashboardController
{
    private Manager $ttsManager;
    private SqliteRepository $repository;
    private array $config;
    private array $demoVoices = [
        ['id' => '21m00Tcm4TlvDq8ikWAM', 'name' => 'Rachel', 'description' => 'American • Warm & versatile'],
        ['id' => 'AZnzlkDJ2myd1k8p7Q6X', 'name' => 'Domi', 'description' => 'American • Upbeat & clear'],
        ['id' => 'pNInz6obpgDQGcFmaJgB', 'name' => 'Adam', 'description' => 'American • Deep & authoritative'],
        ['id' => 'EXAVITQu4vr4xnSDxMaL', 'name' => 'Bella', 'description' => 'American • Friendly & expressive'],
    ];

    public function __construct(Manager $ttsManager, SqliteRepository $repository, array $config)
    {
        $this->ttsManager = $ttsManager;
        $this->repository = $repository;
        $this->config = $config;
    }

    public function loginAction(): void
    {
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            if ($username !== '' && $password !== '') {
                $user = $this->repository->authenticate($username, $password);
                if ($user !== null) {
                    session_regenerate_id(true);
                    $_SESSION['user'] = $user;
                    header('Location: /dashboard');
                    exit;
                }
            }
            $error = 'Invalid username or password.';
        }
        echo $this->renderLoginPage($error);
    }

    public function logoutAction(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        header('Location: /login');
        exit;
    }

    public function indexAction(): void
    {
        $this->ensureAuthenticated();
        $user = $_SESSION['user'];
        $voices = array_merge($this->demoVoices, $this->repository->listLibrary());
        $history = $this->repository->getHistory(10, $user['id'] ?? 1);
        $stats = $this->repository->getUsageStats($user['id'] ?? 1);
        echo $this->renderFullDashboard($voices, $history, $stats, $user);
    }

    public function generateAction(): string
    {
        $this->ensureAuthenticated();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return '<div class="p-4 text-red-500">Invalid request method.</div>';
        }

        $text = trim($_POST['text'] ?? '');
        $voiceId = trim($_POST['voice_id'] ?? '');
        $provider = $_POST['provider'] ?? null;

        if ($text === '' || $voiceId === '') {
            return '<div class="p-4 bg-red-100 text-red-700 rounded">Text and Voice ID are required.</div>';
        }

        try {
            $result = $this->ttsManager->generate($text, $voiceId, $provider);

            $filename = 'synth-' . bin2hex(random_bytes(6)) . '.mp3';
            $audioDir = $this->config['audio']['directory'] ?? __DIR__ . '/../../storage/audio';
            if (!is_dir($audioDir)) {
                mkdir($audioDir, 0755, true);
            }
            $path = $audioDir . '/' . $filename;
            $result->save($path);

            $urlPrefix = $this->config['audio']['public_url_prefix'] ?? '/audio';
            $url = $urlPrefix . '/' . $filename;

            $this->repository->saveGeneration([
                'hash' => $this->computeHash($text, $voiceId),
                'text' => $text,
                'voice_id' => $voiceId,
                'provider' => $provider ?? 'elevenlabs',
                'format' => $result->format ?? 'mp3',
                'duration' => $result->duration ?? 0.0,
                'metadata' => $result->metadata ?? [],
                'audio_size_bytes' => strlen($result->audioContent ?? ''),
                'generated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'user_id' => $_SESSION['user']['id'] ?? 1,
            ]);

            return $this->renderGenerationResult($result, $url, $text, $voiceId);
        } catch (\Throwable $e) {
            return '<div class="p-4 bg-red-100 text-red-700 rounded">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }

    public function libraryAction(): void
    {
        $this->ensureAuthenticated();
        $user = $_SESSION['user'];
        $voices = $this->repository->listLibrary();
        echo $this->renderLibraryPage($voices, $user);
    }

    private function ensureAuthenticated(): void
    {
        if (empty($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }
    }

    private function computeHash(string $text, string $voiceId): string
    {
        $normalized = mb_strtolower(trim($text)) . '::' . trim($voiceId);
        return hash('sha256', $normalized);
    }

    private function renderLoginPage(?string $error = null): string
    {
        $errorHtml = $error !== null
            ? '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">' . htmlspecialchars($error) . '</div>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voxora - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-zinc-950 text-zinc-200">
    <div class="min-h-screen flex items-center justify-center">
        <div class="bg-zinc-900 p-8 rounded-2xl shadow-xl w-full max-w-md">
            <div class="flex justify-center mb-8">
                <img src="/assets/banner.svg" alt="Voxora" class="h-12">
            </div>
            <h1 class="text-3xl font-bold text-center mb-2">Welcome back</h1>
            <p class="text-zinc-400 text-center mb-8">Sign in to access the dashboard</p>
            {$errorHtml}
            <form method="post" class="space-y-6">
                <div>
                    <label class="block text-sm font-medium mb-2">Username</label>
                    <input type="text" name="username" value="admin" 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-4 py-3 focus:outline-none focus:border-violet-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Password</label>
                    <input type="password" name="password" value="demo123" 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-4 py-3 focus:outline-none focus:border-violet-500">
                </div>
                <button type="submit" 
                        class="w-full bg-violet-600 hover:bg-violet-700 transition py-3 rounded-lg font-semibold">
                    SIGN IN
                </button>
            </form>
            <div class="mt-6 text-center text-xs text-zinc-500">
                Default: admin / demo123 • Change in production!
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function renderFullDashboard(array $voices, array $history, array $stats, array $user): string
    {
        $isAdmin = ($user['role'] ?? 'user') === 'admin';
        $username = $user['username'] ?? 'user';
        $role = $user['role'] ?? 'user';

        $voiceOptions = '';
        foreach ($voices as $voice) {
            $id = $voice['id'] ?? ($voice['voice_id'] ?? '');
            $name = $voice['name'] ?? 'Unnamed';
            $desc = $voice['description'] ?? '';
            $cloned = !empty($voice['is_cloned']) ? ' <span class="text-amber-400 text-xs">(CLONED)</span>' : '';
            $voiceOptions .= "<option value=\"{$id}\">{$name}{$cloned} - {$desc}</option>";
        }

        $historyHtml = '';
        foreach ($history as $item) {
            $historyHtml .= '<div class="flex justify-between py-2 border-b border-zinc-700 text-sm">' .
                '<div class="truncate max-w-xs">' . htmlspecialchars($item['text'] ?? '') . '</div>' .
                '<div class="text-zinc-400">' . ($item['duration'] ?? '') . 's</div>' .
                '</div>';
        }

        $initial = strtoupper(substr($user['username'] ?? 'U', 0, 1));

        $ssmlToolbar = '
        <div class="flex gap-1 mb-2 flex-wrap">
            <button onclick="insertSSML(\'<break time=\"500ms\"/>\')" 
                    class="px-3 py-1 bg-zinc-800 hover:bg-zinc-700 rounded text-xs">Break</button>
            <button onclick="wrapSSML(\'prosody\', \'rate=&quot;slow&quot;\')" 
                    class="px-3 py-1 bg-zinc-800 hover:bg-zinc-700 rounded text-xs">Slow</button>
            <button onclick="wrapSSML(\'emphasis\', \'level=&quot;strong&quot;\')" 
                    class="px-3 py-1 bg-zinc-800 hover:bg-zinc-700 rounded text-xs">Emphasis</button>
            <button onclick="insertSSML(\'<say-as interpret-as=&quot;date&quot;&gt;2025-01-01&lt;/say-as&gt;\')" 
                    class="px-3 py-1 bg-zinc-800 hover:bg-zinc-700 rounded text-xs">Date</button>
            <button onclick="insertSSML(\'<prosody pitch=&quot;+10%&quot;&gt;text&lt;/prosody&gt;\')" 
                    class="px-3 py-1 bg-zinc-800 hover:bg-zinc-700 rounded text-xs">Pitch</button>
        </div>';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voxora Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/htmx.org@1.9.12/dist/htmx.min.js"></script>
    <style>
        .tail-container { @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap'); }
        textarea { font-family: monospace; }
    </style>
</head>
<body class="bg-zinc-950 text-zinc-200 font-sans">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <div class="w-64 bg-zinc-900 border-r border-zinc-800 flex flex-col">
            <div class="p-6 border-b border-zinc-800">
                <div class="flex items-center gap-3">
                    <span class="text-2xl">🎙️</span>
                    <span class="font-bold tracking-tighter text-3xl text-white">voxora</span>
                </div>
            </div>
            <div class="p-3">
                <a href="/dashboard" class="flex items-center gap-3 px-4 py-3 hover:bg-zinc-800 rounded-xl text-violet-400">
                    <span>✦</span>
                    <span class="font-medium">Synthesize</span>
                </a>
                <a href="/library" class="flex items-center gap-3 px-4 py-3 hover:bg-zinc-800 rounded-xl">
                    <span>📚</span>
                    <span class="font-medium">Voice Library</span>
                </a>
                <a href="/history" class="flex items-center gap-3 px-4 py-3 hover:bg-zinc-800 rounded-xl">
                    <span>📖</span>
                    <span class="font-medium">History</span>
                </a>
            </div>
            <div class="mt-auto p-4 border-t border-zinc-800">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-violet-500 rounded-full flex items-center justify-center text-xs font-bold">{$initial}</div>
                    <div>
                        <div class="font-medium text-sm">{$username}</div>
                        <div class="text-xs text-zinc-500">{$role}</div>
                    </div>
                </div>
                <a href="/logout" class="block mt-6 text-xs text-red-400 hover:text-red-500">Logout</a>
            </div>
        </div>
        <!-- Main content -->
        <div class="flex-1 overflow-auto">
            <div class="max-w-5xl mx-auto p-8">
                <div class="flex justify-between items-center mb-8">
                    <h1 class="text-4xl font-semibold tracking-tight">Synthesis Studio</h1>
                    <div class="flex items-center gap-4 text-sm">
                        <div class="bg-emerald-900 text-emerald-300 px-3 py-1 rounded-full text-xs font-medium">ONLINE</div>
                        <div>{$stats['total_generations'] ?? 0} generations</div>
                    </div>
                </div>
                <!-- SSML Editor Panel -->
                <div class="grid grid-cols-12 gap-6">
                    <div class="col-span-7 bg-zinc-900 rounded-3xl p-8">
                        <div class="flex items-center justify-between mb-6">
                            <div class="font-semibold">Text to Speech</div>
                            <select id="provider-select" name="provider" class="bg-zinc-800 border-0 rounded-2xl px-4 py-2 text-sm">
                                <option value="elevenlabs">ElevenLabs (Cloud)</option>
                                <option value="piper">Piper (Local)</option>
                            </select>
                        </div>
                        <form hx-post="/dashboard/generate" hx-target="#generation-result" hx-swap="innerHTML" class="space-y-6">
                            <div>
                                <label class="block text-xs uppercase tracking-widest text-zinc-400 mb-3">Voice</label>
                                <select name="voice_id" class="w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 text-lg focus:outline-none focus:border-violet-400">
                                    {$voiceOptions}
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs uppercase tracking-widest text-zinc-400 mb-3">SSML Content</label>
                                {$ssmlToolbar}
                                <textarea id="ssml-editor" name="text" rows="6" 
                                    class="w-full bg-zinc-950 border border-zinc-700 rounded-3xl p-6 font-mono text-sm resize-y focus:outline-none focus:border-violet-400">Hello from <prosody rate="slow">Voxora</prosody>.</textarea>
                            </div>
                            <button type="submit" 
                                    class="bg-white text-zinc-900 px-10 py-4 rounded-2xl font-semibold flex items-center gap-2 hover:scale-105 transition">
                                <span>GENERATE SPEECH</span>
                                <span class="text-xl">→</span>
                            </button>
                        </form>
                    </div>
                    <!-- Preview / Result panel -->
                    <div class="col-span-5">
                        <div id="generation-result" class="bg-zinc-900 rounded-3xl p-8 min-h-[420px]">
                            <div class="flex flex-col items-center justify-center h-full text-center text-zinc-400">
                                <div class="text-6xl mb-6 opacity-30">🎤</div>
                                <p class="font-medium">Your audio will appear here</p>
                                <p class="text-xs mt-2 max-w-[180px]">Live preview with SSML support and metadata</p>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Recent History -->
                <div class="mt-12">
                    <div class="flex justify-between items-baseline mb-4">
                        <div class="uppercase text-xs tracking-widest font-medium text-zinc-400">Recent Generations</div>
                        <a href="/history" class="text-xs text-violet-400 hover:underline">View all →</a>
                    </div>
                    <div class="bg-zinc-900 rounded-3xl p-6">
                        {$historyHtml}
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        function insertSSML(tag) {
            const editor = document.getElementById('ssml-editor');
            const start = editor.selectionStart;
            const end = editor.selectionEnd;
            editor.value = editor.value.substring(0, start) 
                + tag 
                + editor.value.substring(end);
            editor.focus();
            editor.selectionStart = editor.selectionEnd = start + tag.length;
        }
        function wrapSSML(tag, attrs = '') {
            const editor = document.getElementById('ssml-editor');
            let openTag = `<${tag}>`;
            if (attrs) openTag = `<${tag} ${attrs}>`;
            const closeTag = `</${tag}>`;
            const start = editor.selectionStart;
            const end = editor.selectionEnd;
            const selected = editor.value.substring(start, end);
            const replacement = openTag + selected + closeTag;
            editor.value = editor.value.substring(0, start) 
                + replacement 
                + editor.value.substring(end);
            editor.focus();
            editor.selectionStart = start + openTag.length;
            editor.selectionEnd = start + openTag.length + selected.length;
        }
        document.addEventListener('htmx:afterSettle', function() {
            console.log('Generation complete, audio ready');
        });
    </script>
</body>
</html>
HTML;
    }

    private function renderGenerationResult($result, string $url, string $text, string $voiceId): string
    {
        $duration = $result->duration ?? 0;
        $previewUri = $result->toDataUri();

        return <<<HTML
<div class="rounded-3xl bg-gradient-to-br from-violet-500/10 to-fuchsia-500/10 border border-violet-500/30 p-6">
    <audio controls class="w-full mb-6">
        <source src="{$url}" type="audio/mpeg">
    </audio>
    <a href="{$url}" download class="inline-flex items-center gap-2 bg-white text-zinc-900 px-6 py-3 rounded-2xl text-sm font-semibold hover:shadow">
        ⬇ Download MP3
    </a>
    <div class="mt-8 grid grid-cols-2 gap-6 text-xs">
        <div>
            <div class="text-zinc-400">DURATION</div>
            <div class="text-4xl font-mono text-white">{$duration}s</div>
        </div>
        <div>
            <div class="text-zinc-400">VOICE</div>
            <div class="font-medium">{$voiceId}</div>
        </div>
    </div>
    <div class="mt-6 pt-6 border-t border-white/10 text-xs text-zinc-400">
        {$text}
    </div>
    <div class="mt-4">
        <a href="#" onclick="this.innerHTML='Added to library!'; return false;" 
           class="text-violet-400 hover:text-violet-300 text-xs">+ Save to Voice Library</a>
    </div>
</div>
HTML;
    }

    private function renderLibraryPage(array $voices, array $user): string
    {
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-zinc-950 text-zinc-200 p-8">';
        $html .= '<h1 class="text-3xl font-semibold mb-8">Voice Library</h1>';
        $html .= '<div class="bg-zinc-900 rounded-3xl p-8">';
        $html .= '<form action="/api/v1/clone-voice" method="post" enctype="multipart/form-data" class="mb-12 border border-dashed border-zinc-700 rounded-3xl p-8">';
        $html .= '<input type="hidden" name="token" value="demo-token-for-ui">';
        $html .= '<div class="text-sm mb-2">Clone New Voice</div>';
        $html .= '<input type="text" name="name" placeholder="Voice name" class="block w-full mb-4 bg-zinc-800 border-0 p-4 rounded-2xl">';
        $html .= '<input type="file" name="sample" accept="audio/*" class="block w-full mb-6 text-sm">';
        $html .= '<button type="submit" class="bg-amber-500 text-black px-8 py-3.5 rounded-2xl text-sm font-medium">UPLOAD SAMPLE &amp; CLONE</button>';
        $html .= '</form>';
        $html .= '<div class="grid grid-cols-2 gap-4">';
        foreach ($voices as $v) {
            $isCloned = !empty($v['is_cloned']) ? '<span class="ml-2 text-[10px] bg-amber-400 text-black px-2 py-px rounded">CLONED</span>' : '';
            $html .= '<div class="bg-zinc-800 p-5 rounded-2xl">';
            $html .= '<div class="font-medium">' . htmlspecialchars($v['name'] ?? ($v['id'] ?? 'Voice')) . $isCloned . '</div>';
            $html .= '<div class="text-xs text-zinc-500 mt-1">' . htmlspecialchars($v['id'] ?? '') . '</div>';
            $html .= '</div>';
        }
        $html .= '</div></div>';
        $html .= '</body></html>';
        return $html;
    }
}
