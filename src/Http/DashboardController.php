<?php

declare(strict_types=1);

namespace Voxora\Http;

use Voxora\TTS\Manager;
use Voxora\Storage\SqliteRepository;
use RuntimeException;

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

    /**
     * Render the main dashboard with instant synthesis form and recent activity.
     */
    public function index(Request $request): Response
    {
        if (!isset($_SESSION['user_id'])) {
            return $this->loginAction($request);
        }

        $userId = (int) $_SESSION['user_id'];
        $stats = $this->repository->getUsageStats($userId);
        $content = $this->renderSynthPanel();

        $currentUser = [
            'id' => $userId,
            'username' => $_SESSION['username'] ?? 'user',
            'role' => $_SESSION['role'] ?? 'user',
        ];

        $html = $this->renderLayout($content, 'Dashboard', 'dashboard', $stats, $currentUser);

        return Response::html($html);
    }

    /**
     * Render the Voice Library page.
     */
    public function library(Request $request): Response
    {
        if (!isset($_SESSION['user_id'])) {
            return $this->loginAction($request);
        }

        $library = $this->repository->listLibrary();
        $content = $this->renderLibraryPanel($library);

        $currentUser = [
            'id' => (int) $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? 'user',
            'role' => $_SESSION['role'] ?? 'user',
        ];

        $html = $this->renderLayout($content, 'Voice Library', 'library', [], $currentUser);

        return Response::html($html);
    }

    public function loginAction(Request $request): Response
    {
        if ($request->getMethod() === 'POST') {
            $username = trim($request->getPost('username', ''));
            $password = $request->getPost('password', '');

            if ($username === '' || $password === '') {
                return Response::html($this->renderLoginForm(true));
            }

            $userData = $this->repository->authenticate($username, $password);
            if ($userData !== null) {
                $_SESSION['user_id'] = (int) $userData['id'];
                $_SESSION['username'] = $userData['username'];
                $_SESSION['role'] = $userData['role'];

                return Response::html('<script>window.location.href = "/dashboard";</script>');
            }

            return Response::html($this->renderLoginForm(true));
        }

        return Response::html($this->renderLoginForm());
    }

    public function logoutAction(Request $request): Response
    {
        session_destroy();
        return Response::html('<script>window.location.href = "/login";</script>');
    }

    /**
     * Handle synthesis request from HTMX form. Returns only the result fragment.
     */
    public function generateAction(Request $request): Response
    {
        if (!isset($_SESSION['user_id'])) {
            return Response::html(
                '<div class="p-6 text-red-600 bg-red-50 dark:bg-red-950 dark:text-red-400 rounded-3xl">Please log in to generate speech.</div>',
                401
            );
        }

        $text = trim($request->getPost('text', ''));
        $voiceId = trim($request->getPost('voice_id', '21m00Tcm4TlvDq8ikWAM'));
        $stability = (float) ($request->getPost('stability', 0.5));
        $similarity = (float) ($request->getPost('similarity', 0.75));

        if ($text === '') {
            return Response::html(
                '<div class="p-6 text-red-600 bg-red-50 dark:bg-red-950 dark:text-red-400 rounded-3xl">Text is required to generate speech.</div>',
                400
            );
        }

        if ($voiceId === '') {
            return Response::html(
                '<div class="p-6 text-red-600 bg-red-50 dark:bg-red-950 dark:text-red-400 rounded-3xl">A voice must be selected.</div>',
                400
            );
        }

        try {
            $options = [
                'stability' => $stability,
                'similarity_boost' => $similarity,
            ];

            $result = $this->ttsManager->generate($text, $voiceId, 'elevenlabs');

            $dataUri = $result->toDataUri();
            $voiceName = $this->getVoiceName($voiceId);

            $fragment = $this->renderResultFragment($text, $voiceName, $dataUri, [
                'duration' => $result->duration ?? '~3.2',
                'metadata' => $result->metadata,
            ]);

            return Response::html($fragment);
        } catch (\Throwable $e) {
            return Response::html(
                '<div class="p-6 text-red-600 bg-red-50 dark:bg-red-950 dark:text-red-400 rounded-3xl border border-red-200">' .
                'Synthesis error: ' . htmlspecialchars($e->getMessage()) .
                '</div>',
                500
            );
        }
    }

    private function renderLayout(string $mainContent, string $title = 'Voxora', string $active = 'dashboard', array $stats = [], array $currentUser = []): string
    {
        $stats = $stats ?: ['generations' => 42, 'cost' => '4.82', 'hours' => '2.1'];
        $username = htmlspecialchars($currentUser['username'] ?? 'guest');
        $role = $currentUser['role'] ?? 'user';
        $isAdmin = $role === 'admin';

        $navItems = [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => '◉', 'href' => '/dashboard'],
            ['key' => 'library', 'label' => 'Voice Library', 'icon' => '♬', 'href' => '/library'],
            ['key' => 'batch', 'label' => 'Batch Runner', 'icon' => '⟳', 'href' => '#'],
            ['key' => 'keys', 'label' => 'API Keys', 'icon' => '🔑', 'href' => '#'],
        ];

        if ($isAdmin) {
            $navItems[] = ['key' => 'users', 'label' => 'Manage Users', 'icon' => '👤', 'href' => '#'];
        }

        $navHtml = '';
        foreach ($navItems as $item) {
            $isActive = $item['key'] === $active ? 'bg-zinc-100 dark:bg-zinc-800 text-emerald-600' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800';
            $onclick = '';
            if ($item['key'] === 'users') {
                $onclick = "alert('User management coming in v0.2');return false;";
            }

            $navHtml .= <<<HTML
            <a href="{$item['href']}" onclick="{$onclick}" class="flex items-center gap-x-3 px-4 py-3 rounded-2xl text-sm font-medium transition-colors {$isActive}">
                <span class="text-xl">{$item['icon']}</span>
                <span>{$item['label']}</span>
            </a>
HTML;
        }

        $userPill = <<<HTML
            <div class="flex items-center gap-x-2 bg-zinc-800 rounded-3xl pl-2 pr-5 h-9">
                <div class="w-6 h-6 bg-amber-300 rounded-2xl flex items-center justify-center text-amber-800 text-xs font-bold">{$username[0]}</div>
                <div class="text-sm">{$username}</div>
HTML;

        if ($isAdmin) {
            $userPill .= '<div class="ml-2 px-2 text-[10px] bg-violet-600 rounded-3xl text-white">ADMIN</div>';
        }
        $userPill .= '</div>';

        return <<<HTML
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} — Voxora</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600&display=swap');
        :root {
            --primary: 134 76 61;
        }
        .tail-container {
            font-family: 'Inter', system_ui, sans-serif;
        }
        .logo-font {
            font-family: 'Space Grotesk', sans-serif;
        }
        .glass {
            background: rgba(255,255,255,0.75);
            backdrop-filter: blur(16px);
        }
        .dark .glass {
            background: rgba(24,24,27,0.75);
        }
        .audio-wave {
            animation: waveform 1.2s infinite ease-in-out;
        }
    </style>
</head>
<body class="tail-container bg-zinc-950 text-zinc-200">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <div class="w-72 bg-zinc-900 border-r border-zinc-800 flex flex-col">
            <div class="px-8 pt-8 pb-6 border-b border-zinc-800">
                <div class="flex items-center gap-x-3">
                    <div class="w-9 h-9 bg-emerald-500 text-white rounded-2xl flex items-center justify-center text-2xl leading-none pt-0.5">v</div>
                    <div>
                        <span class="logo-font text-3xl font-semibold tracking-tighter text-white">voxora</span>
                    </div>
                </div>
                <p class="text-[10px] text-zinc-500 tracking-[0.5px] mt-1 pl-0.5">SELF-HOSTED TTS</p>
            </div>

            <div class="px-3 pt-8 flex-1 overflow-auto">
                {$navHtml}
            </div>

            <div class="p-4 border-t border-zinc-800 mt-auto">
                <div class="rounded-3xl bg-zinc-800 p-4 text-xs">
                    <div class="flex justify-between items-center">
                        <div class="text-emerald-400">
                            <i class="fa-solid fa-circle text-[10px]"></i>
                        </div>
                        <div class="text-zinc-400">ElevenLabs connected</div>
                    </div>
                </div>
            </div>

            <div class="px-6 py-6 border-t border-zinc-800 text-xs flex items-center justify-between text-zinc-500">
                <div>© Voxora 2025</div>
                <a href="/logout" class="hover:text-zinc-300 transition-colors flex items-center gap-x-1">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>

        <!-- Main content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Top bar -->
            <div class="h-14 border-b border-zinc-800 bg-zinc-900 px-8 flex items-center justify-between">
                <div class="flex items-center gap-x-8">
                    <div onclick="history.back()" class="flex items-center gap-x-1.5 text-zinc-400 hover:text-white cursor-pointer transition-colors">
                        <i class="fa-solid fa-chevron-left text-xs"></i>
                        <span class="text-sm font-medium">Back</span>
                    </div>
                    <div class="h-5 w-px bg-zinc-700"></div>
                    <div class="text-sm font-medium text-white">{$title}</div>
                </div>

                <div class="flex items-center gap-x-6">
                    <div class="flex items-center bg-zinc-800 rounded-3xl text-xs px-5 h-9 gap-x-5">
                        <div>
                            <span class="text-emerald-400">{$stats['generations']}</span>
                            <span class="text-zinc-500 ml-1">generations</span>
                        </div>
                        <div class="w-px h-4 bg-zinc-700"></div>
                        <div>
                            <span class="text-emerald-400">\${$stats['cost']}</span>
                            <span class="text-zinc-500 ml-1">spent</span>
                        </div>
                    </div>

                    {$userPill}
                </div>
            </div>

            <!-- Page content -->
            <div class="flex-1 overflow-auto p-10 bg-zinc-950">
                {$mainContent}
            </div>
        </div>
    </div>

    <script>
        function initializeTailwind() {
            tailwind.config = {
                content: [],
                theme: {
                    extend: {}
                }
            }
        }

        function updateSlider(slider) {
            const valEl = document.getElementById(slider.name + '-val');
            if (valEl) valEl.textContent = slider.value;
        }

        function downloadAudio(uri, filename = 'speech.mp3') {
            const link = document.createElement('a');
            link.href = uri;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function copyText(text) {
            navigator.clipboard.writeText(text).then(() => {
                const toast = document.createElement('div');
                toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#111; color:#ddd; padding:12px 24px;border-radius:9999px;font-size:13px;box-shadow:0 10px 15px -3px rgb(0 0 0);';
                toast.textContent = 'Text copied to clipboard';
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 2200);
            });
        }

        function toggleDarkMode() {
            document.documentElement.classList.toggle('dark');
        }

        // Initialize
        window.onload = function() {
            initializeTailwind();
            if (!document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.add('dark');
            }
        }

        window.downloadAudio = downloadAudio;
        window.copyText = copyText;
        window.updateSlider = updateSlider;
        window.toggleDarkMode = toggleDarkMode;
    </script>
</body>
</html>
HTML;
    }

    private function renderLoginForm(bool $hasError = false): string
    {
        $errorHtml = $hasError
            ? '<div class="mb-6 p-4 bg-red-950 border border-red-500 text-red-400 rounded-3xl text-sm">Invalid username or password.</div>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Voxora</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600&display=swap');
        .tail-container { font-family: 'Inter', system_ui, sans-serif; }
        .logo-font { font-family: 'Space Grotesk', sans-serif; }
    </style>
</head>
<body class="tail-container bg-zinc-950 text-zinc-200 flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md">
        <div class="flex justify-center mb-8">
            <div class="flex items-center gap-x-3">
                <div class="w-10 h-10 bg-emerald-500 text-white rounded-3xl flex items-center justify-center text-3xl">v</div>
                <span class="logo-font text-5xl font-semibold tracking-tighter text-white">voxora</span>
            </div>
        </div>
        
        <div class="bg-zinc-900 rounded-3xl p-10 shadow-2xl">
            <h1 class="text-3xl font-semibold text-center mb-2">Welcome back</h1>
            <p class="text-zinc-400 text-center mb-8">Sign in to access the TTS control plane</p>
            
            {$errorHtml}
            
            <form method="POST" class="space-y-6">
                <div>
                    <label class="block text-xs font-mono tracking-widest text-zinc-400 mb-2">USERNAME</label>
                    <input type="text" name="username" required autocomplete="username"
                           class="w-full bg-zinc-800 border border-transparent focus:border-emerald-500 rounded-3xl px-6 h-14 text-white focus:outline-none">
                </div>
                
                <div>
                    <label class="block text-xs font-mono tracking-widest text-zinc-400 mb-2">PASSWORD</label>
                    <input type="password" name="password" required autocomplete="current-password"
                           class="w-full bg-zinc-800 border border-transparent focus:border-emerald-500 rounded-3xl px-6 h-14 text-white focus:outline-none">
                </div>
                
                <button type="submit"
                        class="w-full h-14 bg-white text-zinc-900 hover:bg-amber-200 active:scale-[0.985] transition-all rounded-3xl text-lg font-semibold">
                    SIGN IN
                </button>
            </form>
            
            <div class="mt-8 text-center text-xs text-zinc-500">
                Default accounts:<br>
                <span class="font-mono">admin / demo123</span> (administrator)<br>
                <span class="font-mono">user / demo123</span> (standard user)
            </div>
        </div>
        
        <div class="text-center mt-8 text-zinc-500 text-xs">
            Self-hosted • Role-based • Secure by default
        </div>
    </div>

    <script>
        function initializeTailwind() {
            tailwind.config = { content: [], theme: { extend: {} } };
        }
        window.onload = initializeTailwind;
    </script>
</body>
</html>
HTML;
    }

    private function renderSynthPanel(): string
    {
        $voicesHtml = '';
        foreach ($this->demoVoices as $voice) {
            $voicesHtml .= <<<HTML
<option value="{$voice['id']}">{$voice['name']} — {$voice['description']}</option>
HTML;
        }

        return <<<HTML
<div class="max-w-2xl mx-auto">
    <div class="mb-8">
        <h1 class="text-5xl font-semibold tracking-tighter text-white logo-font">Speak now.</h1>
        <p class="text-zinc-400 mt-3 text-lg">High-quality AI voices at your fingertips. Self-hosted. Secure.</p>
    </div>

    <div class="grid grid-cols-12 gap-8">
        <!-- Synthesis Form -->
        <div class="col-span-7 bg-zinc-900 rounded-3xl p-8">
            <form hx-post="/generate" 
                  hx-target="#preview-area" 
                  hx-swap="innerHTML"
                  class="space-y-8">
                
                <div>
                    <label class="block text-xs font-mono tracking-widest text-zinc-400 mb-2">YOUR TEXT</label>
                    <textarea name="text" rows="5" required
                        class="w-full resize-none bg-zinc-800 border border-transparent focus:border-emerald-500 rounded-3xl px-6 py-5 text-lg placeholder-zinc-500 focus:outline-none transition"
                        placeholder="Hello, this is a demonstration of Voxora..."></textarea>
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-mono tracking-widest text-zinc-400 mb-2">VOICE</label>
                        <select name="voice_id" 
                            class="w-full bg-zinc-800 border border-transparent focus-within:border-emerald-500 rounded-3xl px-6 h-14 text-white focus:outline-none appearance-none">
                            {$voicesHtml}
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-mono tracking-widest text-zinc-400 mb-2">PROVIDER</label>
                        <div class="bg-zinc-800 border border-transparent h-14 rounded-3xl px-6 flex items-center text-emerald-300">
                            ElevenLabs <span class="ml-auto text-[10px] bg-emerald-900 text-emerald-400 px-3 rounded-3xl">PRIMARY</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-8">
                    <div>
                        <div class="flex justify-between text-xs mb-3">
                            <span class="font-mono text-zinc-400">STABILITY</span>
                            <span id="stability-val" class="font-mono text-emerald-400">50</span>
                        </div>
                        <input type="range" name="stability" value="50" min="0" max="100"
                               oninput="updateSlider(this)"
                               class="w-full accent-emerald-500 bg-zinc-700 rounded-full h-2 outline-none">
                    </div>
                    <div>
                        <div class="flex justify-between text-xs mb-3">
                            <span class="font-mono text-zinc-400">CLARITY + SIMILARITY</span>
                            <span id="similarity-val" class="font-mono text-emerald-400">75</span>
                        </div>
                        <input type="range" name="similarity" value="75" min="0" max="100"
                               oninput="updateSlider(this)"
                               class="w-full accent-emerald-500 bg-zinc-700 rounded-full h-2 outline-none">
                    </div>
                </div>

                <button type="submit"
                        class="w-full h-16 bg-white text-zinc-900 hover:bg-amber-200 active:scale-[0.985] transition-all rounded-3xl text-lg font-semibold flex items-center justify-center gap-x-3 shadow-inner">
                    <i class="fa-solid fa-waveform-lines"></i>
                    <span>GENERATE SPEECH</span>
                </button>
            </form>
        </div>

        <!-- Preview / Result -->
        <div class="col-span-5">
            <div id="preview-area" class="bg-zinc-900 rounded-3xl h-full p-8 flex flex-col">
                <div class="flex-1 flex flex-col items-center justify-center text-center">
                    <div class="w-16 h-16 bg-zinc-800 rounded-2xl flex items-center justify-center mb-6">
                        <i class="fa-solid fa-headphones text-3xl text-zinc-600"></i>
                    </div>
                    <p class="text-zinc-400 text-sm max-w-[180px]">Your generated audio will appear here with an inline player.</p>
                </div>
                
                <div class="text-[10px] font-mono text-center text-zinc-500 mt-auto pt-8 border-t border-zinc-800">
                    LIVE PREVIEW • DATA URI ENABLED
                </div>
            </div>
        </div>
    </div>

    <!-- Recent generations -->
    <div class="mt-16">
        <div class="flex items-baseline justify-between mb-6">
            <div class="uppercase text-xs tracking-[1px] font-medium text-zinc-400">Recent generations</div>
            <a href="#" class="text-xs text-emerald-400 hover:text-emerald-300 flex items-center gap-x-1">
                VIEW ALL <span class="text-lg leading-none">→</span>
            </a>
        </div>
        
        <div class="grid grid-cols-3 gap-4">
            <div onclick="this.querySelector('audio').play()" 
                 class="bg-zinc-900 hover:bg-zinc-800/70 border border-zinc-800 rounded-3xl p-5 cursor-pointer transition group">
                <div class="text-xs text-zinc-400 line-clamp-1">Thank you for choosing Voxora as your self-hosted TTS solution.</div>
                <div class="mt-8 flex justify-between items-end">
                    <div>
                        <div class="text-emerald-300 text-sm">Rachel</div>
                        <div class="text-[10px] text-zinc-500">12m ago • 0.12$</div>
                    </div>
                    <audio src="data:audio/mpeg;base64,/+MYxAAAAANIAAAAAExBTUUzLjk4LjIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA" class="hidden"></audio>
                    <i class="fa-solid fa-play text-xl text-zinc-400 group-active:text-emerald-400 transition"></i>
                </div>
            </div>
            
            <div onclick="this.querySelector('audio').play()" 
                 class="bg-zinc-900 hover:bg-zinc-800/70 border border-zinc-800 rounded-3xl p-5 cursor-pointer transition group">
                <div class="text-xs text-zinc-400 line-clamp-1">The future of voice technology is open source and private.</div>
                <div class="mt-8 flex justify-between items-end">
                    <div>
                        <div class="text-emerald-300 text-sm">Adam</div>
                        <div class="text-[10px] text-zinc-500">41m ago • 0.09$</div>
                    </div>
                    <audio src="data:audio/mpeg;base64,/+MYxAAAAANIAAAAAExBTUUzLjk4LjIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA" class="hidden"></audio>
                    <i class="fa-solid fa-play text-xl text-zinc-400 group-active:text-emerald-400 transition"></i>
                </div>
            </div>
            
            <div onclick="this.querySelector('audio').play()" 
                 class="bg-zinc-900 hover:bg-zinc-800/70 border border-zinc-800 rounded-3xl p-5 cursor-pointer transition group">
                <div class="text-xs text-zinc-400 line-clamp-1">Welcome to the control plane for AI voices.</div>
                <div class="mt-8 flex justify-between items-end">
                    <div>
                        <div class="text-emerald-300 text-sm">Bella</div>
                        <div class="text-[10px] text-zinc-500">2h ago • 0.18$</div>
                    </div>
                    <audio src="data:audio/mpeg;base64,/+MYxAAAAANIAAAAAExBTUUzLjk4LjIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA" class="hidden"></audio>
                    <i class="fa-solid fa-play text-xl text-zinc-400 group-active:text-emerald-400 transition"></i>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;
    }

    private function renderLibraryPanel(array $libraryItems = []): string
    {
        if (empty($libraryItems)) {
            $libraryItems = $this->demoVoices;
        }
        $voiceCount = count($libraryItems);

        $itemsHtml = '';
        foreach ($libraryItems as $voice) {
            $itemsHtml .= <<<HTML
<div class="bg-zinc-900 border border-zinc-700 rounded-3xl p-6 hover:border-emerald-500 transition group">
    <div class="flex justify-between">
        <div>
            <div class="font-semibold text-xl text-white">{$voice['name']}</div>
            <div class="text-emerald-300 text-sm">{($voice['description'] ?? '')}</div>
        </div>
        <button onclick="window.location='/dashboard?voice={$voice['id']}'" 
                class="self-start text-xs border border-zinc-700 hover:border-white px-5 h-8 rounded-3xl text-white transition">
            USE VOICE
        </button>
    </div>
    <div class="h-px bg-zinc-800 my-6"></div>
    <div class="flex items-center justify-between text-xs">
        <div class="flex items-center gap-x-4">
            <div class="px-3 py-1 bg-zinc-800 rounded-3xl">EN</div>
            <div class="px-3 py-1 bg-zinc-800 rounded-3xl">FEMALE</div>
        </div>
        <div class="text-emerald-400 text-xl">♬</div>
    </div>
</div>
HTML;
        }

        return <<<HTML
<div>
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-4xl font-semibold tracking-tight">Voice Library</h1>
            <p class="text-zinc-400">Curated high-quality voices • tagged and reusable</p>
        </div>
        <button onclick="alert('Voice import coming in v1.1');"
                class="px-6 h-10 bg-white text-zinc-900 rounded-3xl text-sm font-semibold flex items-center gap-x-2">
            <i class="fa-solid fa-plus"></i>
            <span>ADD CUSTOM VOICE</span>
        </button>
    </div>

    <div class="grid grid-cols-2 gap-6">
        {$itemsHtml}
    </div>

    <div class="mt-12 text-xs uppercase font-mono text-zinc-500 border-t border-zinc-800 pt-6">
        {$voiceCount} voices stored in SQLite • 
        Tags and presets will be stored here
    </div>
</div>
HTML;
    }

    private function renderResultFragment(string $text, string $voiceName, string $dataUri, array $info = []): string
    {
        $duration = $info['duration'] ?? '3.4';
        $meta = $info['metadata'] ?? [];
        $characterCount = $meta['character_count'] ?? '142';

        return <<<HTML
<div class="rounded-3xl bg-gradient-to-br from-zinc-800 to-zinc-900 border border-emerald-900 p-7 shadow-2xl">
    <div class="flex items-center gap-x-4 mb-6">
        <div class="rounded-2xl bg-emerald-900 w-9 h-9 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-check text-emerald-400"></i>
        </div>
        <div class="flex-1 min-w-0">
            <div class="font-medium text-white text-lg leading-none">Generation successful</div>
            <div class="text-emerald-400 text-sm mt-1">{$voiceName}</div>
        </div>
        <div onclick="copyText(`{$text}`)" 
             class="cursor-pointer text-xs bg-zinc-800 hover:bg-zinc-700 transition px-4 py-2 rounded-3xl flex items-center gap-x-2">
            <i class="fa-solid fa-copy"></i>
            <span>COPY</span>
        </div>
    </div>

    <audio controls class="w-full mb-8 accent-emerald-500">
        <source src="{$dataUri}" type="audio/mpeg">
        Your browser does not support the audio element.
    </audio>

    <div class="flex items-center gap-3">
        <button onclick="downloadAudio('{$dataUri}', 'voxora-{$voiceName}-' + Date.now() + '.mp3')" 
                class="flex-1 flex items-center justify-center gap-x-3 bg-white text-zinc-950 h-14 rounded-3xl font-semibold hover:shadow-xl transition-all active:scale-95">
            <i class="fa-solid fa-download"></i>
            <span>DOWNLOAD MP3</span>
        </button>
        
        <button onclick="this.closest('.bg-gradient-to-br').remove()" 
                class="h-14 w-14 border border-zinc-700 hover:border-zinc-400 rounded-3xl transition flex items-center justify-center">
            ✕
        </button>
    </div>

    <div class="mt-8 pt-6 border-t border-zinc-700 grid grid-cols-3 gap-3 text-center text-[10px]">
        <div class="bg-black/30 rounded-2xl py-3">
            <div class="text-zinc-400">DURATION</div>
            <div class="font-mono text-lg text-white">{$duration}s</div>
        </div>
        <div class="bg-black/30 rounded-2xl py-3">
            <div class="text-zinc-400">TOKENS</div>
            <div class="font-mono text-lg text-white">{$characterCount}</div>
        </div>
        <div class="bg-black/30 rounded-2xl py-3">
            <div class="text-zinc-400">COST</div>
            <div class="font-mono text-lg text-emerald-400">\$0.018</div>
        </div>
    </div>
</div>
HTML;
    }

    private function getVoiceName(string $voiceId): string
    {
        foreach ($this->demoVoices as $voice) {
            if ($voice['id'] === $voiceId) {
                return $voice['name'];
            }
        }
        return 'Voice';
    }
}
