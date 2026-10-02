# Savannah Explorers Hub — notes for Claude Code

Internal web app of Savannah Explorers (Arusha, Tanzania) and Savannah Holidays Ltd
(Mauritius): requests/CRM, bookings in Dropbox, invoices, itineraries (ITI), operations,
Memo Board. PHP + MySQL on BlueHost, no framework. Owner: Roberto De Sibi.

Full overview: `README.md` (modules, conventions, DB tables). Some README notes are
outdated — this file wins where they disagree (PHP version, local lint).

## Where things are
- `modules/leads/` — requests, BackOffice, payments, CK, pricing, **Agent API** (`agent_api.php`,
  entry `api/agent/index.php`). Shared logic in `modules/leads/includes/`
  (`booking_service.php`, `calc_service.php`, `folder_parser.php`, `grp_status.php`, `ck_lib.php`).
- `modules/invoices/` — invoices, credit notes. Shared logic: `includes/invoice_service.php`
  (numbering, recalculation, payments, folder status, PDF, import); `config.php` = bootstrap only.
- `modules/memo/` — Memo Board; logic in `memo_lib.php` (follow-ups, next steps, routines).
- `modules/iti/` — itinerary builder. `includes/` (root) — config (server-only), auth, db, mail.
- `migrations/NNN_name.sql` — not applied by deploy. `docs/` — AGENT_API, FOLDER_NAMING,
  INFRASTRUCTURE, KB_Quotazioni_Safari.

**Rule:** logic used by both a Hub page and the Agent API lives in a shared service file
(`*_service.php` / `memo_lib.php`); pages and `agent_api.php` stay thin. Don't duplicate it.

## Checking changes
- No local server or DB. Lint every changed PHP file: `C:\Utility\PHP\php.exe -l <file>`.
- JS: `node --check` if available, else `python -c "import esprima; esprima.parseScript(open(f).read())"`.
- UI changes: build a static harness (page HTML + JS + mocked XHR) in the scratchpad and look at it
  in the browser pane. Say clearly what could only be tested on the server.

## Server facts
- **PHP 8.3** since 28 Sep 2026 (was 8.0). New code may use PHP 8, but match the file you edit
  (`modules/memo/` and `grp_status.php` are kept PHP-7 style).
- **MySQL, not MariaDB**: no `ADD COLUMN IF NOT EXISTS`. For new columns either a migration or a
  lazy schema function (check `INFORMATION_SCHEMA` once, then `ALTER` in try/catch — see `memo_schema()`).
- Timezone: every entry point sets `Africa/Dar_es_Salaam` (server is US).
- AJAX/JSON handlers: `ob_start()` first so warnings don't break JSON.
- Server-only, never in git: `includes/config.php`, `modules/leads/config.php`, `api.txt` (agent key).
  Constants there: `AGENT_API_KEY`, `AGENT_API_USER`, `AGENT_MEMO_USER`, `MEMO_CRON_TOKEN`, Dropbox/HubSpot keys.

## Git and deploy
- Commit / push / deploy **only when Roberto asks**. Commit message ends with the Co-Authored-By line.
- Push: `git push origin main` (needs sandbox disabled). Deploy: run
  `C:\Dropbox\Exchange\Savannah-Hub\DeployHub.bat` with stdin from `/dev/null`.
  Don't run `bin\hub-push.bat` from a tool — it ends on `pause` and hangs.
- Verify a deploy: `ssh -i ~/.ssh/bluehost_hub -p 2222 savannp5@box2233.bluehost.com` →
  `cd public_html/hub && git diff origin/main --stat -- modules migrations` (empty = deployed).
  BlueHost SSH often refuses repeated connections: wait and retry once.
- Deploy does not delete files on the server; removed files must be deleted by hand.

## Business rules that matter in code
- **Never store or read passport numbers** (guests, CK).
- Invoices `SE-YYYY-NNNN` = Savannah Explorers; `SH-YYYY-NNNN` = Savannah Holidays, paid on the
  **AfrAsia** account (Roberto checks it, not the accountant). Old Zoho invoices keep `INV-…`.
- Dropbox: inquiries in `/2026/<Name>(<Agency>-<Agent>)`, confirmed bookings in `/001_Safari/`
  with date and payment tags (`_DEPOSIT`, `_BALANCE`, `_PAID`, `_CK` last). See `docs/FOLDER_NAMING.md`;
  `folder_parser.php` is the authority.
- Agent API: actions that move folders, send mail or overwrite data are dry-run unless `"confirm": true`;
  every call goes to `agent_audit_log`. Document new actions in `docs/AGENT_API.md`.
