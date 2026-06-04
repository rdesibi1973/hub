# Infrastructure & Deploy

> **No credentials in this file.** Passwords, API keys, tokens, PATs and private keys never go in the repo. Where a secret is referenced, only its **variable/constant name** appears here — the value lives outside version control (see §5). If you find a real secret committed anywhere, rotate it.

---

## 1. Hosting

- **Provider:** BlueHost (shared hosting), account `savannp5`.
- **Document root:** `/home4/savannp5/public_html/hub/`
- **Public URL:** `https://hub.savannahexplorers.com`
- **Database:** MySQL `savannp5_savannah_leads`, managed via phpMyAdmin.

## 2. SSH access

- **Host:** `box2233.bluehost.com`
- **User:** `savannp5`
- **Port:** `2222`
- **Key:** ed25519 private key held locally on Roberto's PC (not in repo). PuTTY requires a `.ppk` conversion via PuTTYgen.

## 3. Repository

- **Repo:** `rdesibi1973/hub` (private).
- **Git identity for commits:** `rdesibi@savannahexplorers.com` / `Roberto De Sibi`.
- **Auth for push/pull:** a GitHub PAT (classic, scope `repo`). The PAT is **not stored** anywhere in the repo or in persistent notes — it is supplied per session and never persisted.

## 4. Deploy workflow

Two paths, both pull from `main`:

1. **`DeployHub.bat`** (Windows, on Roberto's PC) — the usual route. The script lives on the local machine, **not** in the repo.
2. **`git pull` on the server** via SSH — direct alternative.

Notes:
- **MySQL changes take effect immediately** on page reload — no deploy needed for SQL-only fixes.
- **Documentation-only commits** (e.g. files under `docs/`) need no deploy to be "active" — there is nothing to serve.
- **Java GUI** changes require a NetBeans Clean and Build; pushing to git is not sufficient to update the running JAR.

## 5. Secrets (names only — values live outside the repo)

All live secrets sit in server-only config that is **git-ignored** (see `.gitignore`):

- `includes/config.php` (root) — excluded from repo. Holds e.g. `API_IMPORT_KEY` (checked via the `X-Hub-Token` header).
- `modules/leads/config.php` — excluded from repo. Holds live DB, Dropbox and HubSpot credentials, plus `API_KEY` (checked via the `X-Api-Key` header) and any external keys (e.g. `ANTHROPIC_API_KEY` used by `modules/iti/iti_fix_destinations.php`).

To edit a secret: change it **on the server** (outside the repo, no git deploy). The Java GUI sends its API key as `api.key` in `config.properties`, which must sit in the same directory as the JAR.

> Rotation procedure (when a key is rotated): generate with `openssl rand -hex 32`, update the `define()` in the relevant server-side config **and** the matching client value (`config.properties` for the GUI), then test one affected function (e.g. Lookup Leads, or an import/search call) immediately.

## 6. Build / dependencies

- **PHP**: target BlueHost's PHP 7.x — avoid `match()` and arrow functions (`fn()`), which are PHP 8+.
- **Composer**: `dompdf/dompdf ^2.0` (PDF generation), autoload at `vendor/autoload.php`.
- **Git-ignored** (per `.gitignore`): server-only `includes/config.php`, NetBeans build artifacts (`build/`, `dist/`, `nbproject/private/`), `SavannahExplorersGUI/`, PHP `error_log` files, and Dropbox "conflicted copy" PHP files.
- **Java source files** require `git add -f` (NetBeans patterns in `.gitignore` would otherwise exclude them).
