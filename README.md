<!-- header:start -->
<div align="center">

<img src="./assets/banner.svg" alt="Voxora" width="100%">

<a href="#-quick-start"><img src="https://readme-typing-svg.demolab.com?font=Fira+Code&weight=600&size=20&pause=1000&color=6366f1&center=true&vCenter=true&width=640&lines=Text+to+voice+in+one+click;Enterprise+security+for+AI+APIs;Automate+voice+workflows" alt="Text to voice in one click · Enterprise security for AI APIs · Automate voice workflows"></a>

<a href="LICENSE"><img src="https://img.shields.io/github/license/ajendras/voxora?style=for-the-badge&color=14b8a6" alt="License"></a>
<img src="https://img.shields.io/badge/PHP-14b8a6?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
<a href="https://github.com/ajendras/voxora/stargazers"><img src="https://img.shields.io/github/stars/ajendras/voxora?style=for-the-badge&logo=github&color=6366f1" alt="Stars"></a>
<a href="https://github.com/ajendras/voxora/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/ajendras/voxora/ci.yml?style=for-the-badge&label=CI" alt="CI"></a>
<img src="https://img.shields.io/github/last-commit/ajendras/voxora?style=for-the-badge&color=14b8a6" alt="Last commit">
<a href="CONTRIBUTING.md"><img src="https://img.shields.io/badge/PRs-welcome-22c55e?style=for-the-badge" alt="PRs welcome"></a>

**Self-hosted PHP platform for secure AI text-to-speech, voice management and workflow automation**

[✨ Features](#-features) • [⚡ Quick start](#-quick-start) • [🧰 Usage](#-usage) • [🧩 Architecture](#-architecture) • [📍 Roadmap](#-roadmap) • [🤝 Contributing](#-contributing)

</div>
<!-- header:end -->

## ✨ Why Voxora?

Developers integrating high-quality voices face fragmented provider APIs, exposed keys and repetitive manual tasks. Voxora is a unified self-hosted PHP control plane that adds strong security, token scoping, batch automation and a clean dashboard, bridging raw TTS services and production apps.

- **Secure by default** — `KeyManager` issues encrypted, scoped, auto-expiring tokens using Sodium; no keys are ever exposed in your frontend or logs.
- **Single unified interface** — `TTS\Manager::generate()` routes through `ProviderInterface` implementations (currently `ElevenLabsProvider`) with automatic SQLite caching.
- **Zero-copy automation** — `BatchGenerator::run()` turns a CSV into dozens of MP3s in one call, with optional progress callbacks and a full `Report`.
- **Instant UI + REST** — Responsive HTMX/Tailwind dashboard and a token-protected JSON API let you preview, download or embed speech from any app.

## 🎬 Demo

<p align="center"><img src="./assets/demo.svg" alt="Voxora demo" width="760"></p>

## 🚀 Features

<table>
<tr>
<td valign="top" width="33%">
🔐 <b>Secure Vault</b><br>
Encrypted scoped tokens with <code>KeyManager::issueToken()</code> and <code>validate()</code>
</td>
<td valign="top" width="33%">
🌐 <b>Unified Engine</b><br>
<code>TTS\Manager</code> + <code>ProviderInterface</code> for ElevenLabs (and future providers)
</td>
<td valign="top" width="33%">
🎙️ <b>Instant Synth</b><br>
HTMX form in <code>DashboardController::generateAction()</code> with live preview
</td>
</tr>
<tr>
<td valign="top">
📦 <b>Batch Runner</b><br>
<code>BatchGenerator::run()</code> parses CSV, writes MP3s and returns a <code>Report</code>
</td>
<td valign="top">
📚 <b>Voice Library</b><br>
Curated voices stored and retrieved via <code>SqliteRepository::listLibrary()</code>
</td>
<td valign="top">
📊 <b>Analytics Hub</b><br>
Usage stats, history and cost tracking through <code>SqliteRepository::getUsageStats()</code>
</td>
</tr>
<tr>
<td valign="top">
🔌 <b>REST API</b><br>
<code>ApiController</code> endpoints (<code>speechAction</code>, <code>historyAction</code>, <code>issueTokenAction</code>)
</td>
<td valign="top">
🛡️ <b>Token Scoping</b><br>
<code>Token::hasScope()</code> enforces <code>tts:generate</code> on protected routes
</td>
<td valign="top">
👤 <b>Multi-User Auth</b><br>
Session-based login, roles (admin/user), per-user history &amp; stats via SQLite
</td>
</tr>
</table>

## 📦 Installation

**Requirements**

- PHP 8.2+ with `ext-sqlite3`, `ext-sodium`, `ext-json`
- Composer

```bash
composer create-project ajendras/voxora voxora-app
cd voxora-app

# Copy and edit configuration (add your ElevenLabs key)
cp config/app.example.php config/app.php

# Start the built-in server
composer run serve
```

The app will be available at http://localhost:8080/login (default admin/demo123).

## ⚡ Quick start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Voxora\Kernel;

$config = require __DIR__ . '/config/app.php';
$kernel = new Kernel($config);
$kernel->boot();

$manager = $kernel->getTtsManager();

$result = $manager->generate(
    text: "Hello from Voxora — self-hosted AI speech.",
    voiceId: "21m00Tcm4TlvDq8ikWAM",
    provider: "elevenlabs"
);

$result->save('output/hello.mp3');
echo "Saved! Duration: " . ($result->duration ?? 'unknown') . "s\n";
```

## 🧰 Usage

### Authentication

Default credentials (change immediately in production by editing the database or extending user management):

- **Administrator**: `admin` / `demo123` (full access, can issue tokens with elevated roles)
- **Regular user**: `user` / `demo123` (standard tts:generate access, own history only)

After login the top bar reflects the current user and role. Admins see additional navigation options. All dashboard actions require an active session. API tokens can now carry a `role` for RBAC.

### Dashboard synthesis (HTMX)

Visit `/dashboard` (after login), fill the form and click **GENERATE SPEECH**. The `DashboardController::generateAction()` returns a live audio fragment with player, download button and metadata. Stats and history are scoped to the logged-in user.

### Issue a scoped API token

```php
$keyManager = $kernel->getKeyManager();
$token = $keyManager->issueToken(
    name: "Mobile App Integration",
    scopes: ["tts:generate"],
    role: "user"
);
echo "Token: $token\n";
```

Use the token in the `Authorization: Bearer` header or `X-Api-Key` for `/api/v1/speech`. The `Token` object now exposes `role` and `userId` for role-based decisions.

### Batch processing from CSV

```php
use Voxora\Automation\BatchGenerator;

$generator = new BatchGenerator($manager, outputDir: 'output');
$report = $generator->run('scripts/podcast.csv', function (int $cur, int $tot, string $msg) {
    echo "[$cur/$tot] $msg\n";
});

echo $report->getSummary() . "\n";
print_r($report->toArray());
```

<details><summary>Advanced: custom Report handling + progress with file links</summary>

```php
$report = $generator->run('large-batch.csv', $progressCallback);

if ($report->failed > 0) {
    echo "Failures: " . $report->failed . "\n";
    foreach ($report->files as $file) {
        echo "→ $file\n";
    }
}
```
</details>

### Call the JSON API directly

```bash
curl -X POST http://localhost:8080/api/v1/speech \
  -H "X-Api-Key: vxo_..." \
  -d 'text=Welcome to the control plane.&voice_id=21m00Tcm4TlvDq8ikWAM'
```

## 🔧 Configuration

| Option                        | Default               | Description |
|-------------------------------|-----------------------|-------------|
| `security.encrypt_key`        | (required)            | Master key used by `KeyManager` to derive the Sodium secretbox key |
| `security.token_lifetime_days`| 90                    | Default expiry for tokens issued by `KeyManager::issueToken()` |
| `storage.path`                | `data/voxora.db`      | SQLite database path passed to `SqliteRepository` |
| `providers.eleven.api_key`    | (required)            | ElevenLabs API key used by `ElevenLabsProvider` |
| `providers.eleven.base_uri`   | `https://api.elevenlabs.io/v1` | Base URL for the ElevenLabs API |
| `audio.directory`             | `storage/audio`       | Directory where `ApiController::speechAction()` saves generated MP3s |
| `audio.public_url_prefix`     | `/audio`              | Public URL prefix returned in JSON responses |

All values are defined in `config/app.php` and loaded by `Kernel`.

## 🧩 Architecture

```mermaid
flowchart TD
    Request[HTTP Request] --> Index[public/index.php]
    Index --> Kernel[Kernel::boot + handle]
    Kernel --> Session[session_start + Auth]
    Kernel --> Router[Router]
    Router --> Dashboard[DashboardController<br>(login, index, generate)]
    Router --> Api[ApiController]
    Api --> KeyManager[KeyManager::validate<br>(role + userId)]
    Api --> Manager[TTS\Manager::generate]
    Dashboard --> Manager
    Manager --> Provider[ElevenLabsProvider::synthesize]
    Manager --> Repo[SqliteRepository<br>(users, generations with user_id)]
    Batch[BatchGenerator::run] --> Manager
    Manager --> Synthesis[SynthesisResult<br>(save, toDataUri)]
    Users[(users table<br>role-based)]
```

`Kernel` wires together `KeyManager`, `TTS\Manager`, `SqliteRepository` and the two controllers. All synthesis paths converge on `Manager::generate()`, which selects a `ProviderInterface` implementation, caches results and records usage. The dashboard uses HTMX fragments while the API returns JSON with public audio URLs. Sessions + `users` table enable multi-user isolation and role checks (`admin` vs `user`).

## 📁 Project structure

```text
.
├── composer.json                 # Dependencies, scripts (serve, test), PSR-4 autoload
├── config/app.php                # providers, security.encrypt_key, storage.path
├── public/
│   └── index.php                 # Front controller — registers Router & Kernel
├── src/
│   ├── Kernel.php                # Bootstraps services, creates Manager + KeyManager, starts session
│   ├── Security/
│   │   └── KeyManager.php        # issueToken(), validate(), Token (now with role/userId), User concepts
│   ├── TTS/
│   │   ├── ProviderInterface.php # synthesize(text, voice, options)
│   │   ├── ElevenLabsProvider.php
│   │   └── Manager.php           # generate() with caching & repository
│   ├── Http/
│   │   ├── DashboardController.php # HTMX pages + generateAction + login/logout
│   │   └── ApiController.php     # speechAction, historyAction, issueTokenAction (role-aware)
│   ├── Storage/
│   │   └── SqliteRepository.php  # schema (users + user_id), authenticate(), getUser(), scoped queries
│   └── Automation/
│       └── BatchGenerator.php    # run(csvPath, progress), Report value object
├── examples/
│   └── batch.php                 # CLI demo using Manager + BatchGenerator
├── tests/
│   └── Unit/
│       └── KeyManagerTest.php    # token issuance, validation, expiry, scopes, roles
└── data/                         # SQLite DB and generated audio (gitignored)
```

## 🧪 Testing

```bash
composer test
# or
vendor/bin/phpunit
```

The test suite covers `KeyManager` token issuance, validation, expiry checks, encryption round-trips, scope enforcement and the new role/userId fields.

## 📍 Roadmap

<!-- roadmap:start -->
- [x] **v0.1.0** — first release: Secure Vault, Unified Engine, Instant Synth, Batch Runner
- [x] Add user authentication and role-based multi-user support
- [ ] Local TTS fallback via Piper using Symfony Process component
- [ ] Voice cloning workflow with sample upload to provider APIs
- [ ] Rich SSML editor with live preview inside dashboard
- [ ] FFmpeg integration for normalization, loudness and format conversion
- [ ] Interactive usage analytics dashboard with Chart.js charts
- [ ] Configurable webhooks on successful generation
- [ ] Redis-backed rate limiter and request throttling
- [ ] Plugin system for registering new TTS providers at runtime
- [ ] Scheduled batch jobs using native cron expressions
- [ ] Dark/light mode persistence and improved accessibility
- [ ] Exportable voice library as portable JSON bundles

> 🤖 This roadmap is worked through continuously — ideas and PRs welcome!
<!-- roadmap:end -->

## 🤝 Contributing

We love community contributions! Whether it's a new TTS provider, improved UI fragment, or a documentation fix, please open a PR.

Read our [CONTRIBUTING.md](https://github.com/ajendras/voxora/blob/main/CONTRIBUTING.md) and follow the [CODE_OF_CONDUCT.md](https://github.com/ajendras/voxora/blob/main/CODE_OF_CONDUCT.md). Good-first-issues are tagged and welcome — come say hi in the discussions!

## 📄 License

This project is licensed under the MIT License — see the [LICENSE](https://github.com/ajendras/voxora/blob/main/LICENSE) file for details.

<!-- footer:start -->
<div align="center">

### ⭐ Star history

<a href="https://star-history.com/#ajendras/voxora&Date"><img src="https://api.star-history.com/svg?repos=ajendras/voxora&type=Date" alt="Star history" width="600"></a>

If **Voxora** saves you time, a ⭐ helps others find it.

Made with ❤️ by [Ajendra](https://ajendra.info)

<img src="https://capsule-render.vercel.app/api?type=waving&color=0:14b8a6,100:6366f1&height=120&section=footer" width="100%" alt="">

</div>
<!-- footer:end -->
