# Savannah Explorers Hub

Operational management platform for **Savannah Explorers Ltd** (Arusha-based safari DMC) and **The Orangi Collection**. Centralises leads/CRM, invoicing, itinerary building, operations, and integrations into a single back-office web application.

---

## 1. Project identity

| Item | Value |
|---|---|
| Application | Savannah Explorers Hub |
| URL | `hub.savannahexplorers.com` |
| Repository | `rdesibi1973/hub` (private, GitHub) |
| Hosting | BlueHost shared hosting (`box2233.bluehost.com`, account `savannp5`) |
| Server path | `/home4/savannp5/public_html/hub/` |
| Database | `savannp5_savannah_leads` (collation `utf8_unicode_ci`) |
| Stack | PHP 8 (BlueHost-compatible subset), MySQL, vanilla JS, HTML/CSS |
| Config | `includes/config.php` and `modules/leads/config.php` — **kept off the repo** |

All UI, messages, and documentation are in **English**.

---

## 2. Purpose

The Hub supports Savannah staff in daily operations and duties and assists management with data analysis. It covers the full client lifecycle — from inbound lead to booked safari, invoicing, and operational logistics — plus itinerary production and third-party integrations.

---

## 3. Modules

### 3.1 Leads / CRM (`modules/leads/`)
Lead Tracker and CRM core. Manages inbound requests, staging, and conversion.
- HubSpot sync with staging pipeline, reconversion detection, iBot property mapping
- Dropbox folder integration (OAuth2 refresh token); authoritative parser at `modules/leads/includes/folder_parser.php`
- Soft-delete system for requests
- `start_date` parsed from Dropbox folder names
- Pipeline Kanban view
- Email templates (`email_templates.php`) with public/private visibility, Quill editor, `$[ParameterName]` placeholders
- Booked requests view (`booked.php`) with start-date sorting and shared send modal (`includes/send_modal.php`)
- Dropbox shareable links via `dropbox_open.php` (refresh-token exchange, `sharing.write` scope)
- `agent_id` must be queried from the `users` table, never from session

### 3.2 Invoices (`modules/invoices/`)
Invoicing and credit notes.
- Numbering scheme: `SE/SH-YYYY-NNNN`
- Two issuers: Savannah Explorers Ltd, Savannah Holidays Ltd
- Credit notes
- Zoho PDF importer
- Payments tracking with cancellation support
- Import/search endpoints protected by `API_IMPORT_KEY` via `X-Hub-Token` header
- Linked from Lead Tracker nav (admin/manager only) and from `request_view.php`

### 3.3 ITI — Itinerary Builder (`modules/iti/`)
Multilingual, multi-currency itinerary production system. See `ITI_module_reference_EN.md` for full reference.
- Languages: EN, IT, FR, ES, DE (×5 columns per descriptive field)
- Currencies: USD, EUR (×2 columns per price field)
- 16 `iti_` tables (master data / requests-programmes / pricing)
- Price categories: `rack` (clients), `sto` (standard agencies/TO), `stospec` (preferred TO)
- Workflow: SAMPLE → clone to PERSONAL inside a request → publish via `public_token` UUID → public link `/itinerary/{token}` → branded `.docx` export (PHPWord)
- Quill rich-text editors for T&C; consultant bio blocks in 5 languages
- Internal flights always included in programme price, never a separate line item
- Helpers: `iti_field($row,'field',$lang)` multilingual fallback, `iti_nav()`, `iti_redirect()`, `iti_flash_set()`

### 3.4 Operations
Operational logistics management.
- Movements (arrivals/departures, driver, pickup/dropoff, flight)
- Medivac
- Lunch boxes

### 3.5 Quotes / Pricing (`modules/leads/` pricing pages)
Internal safari quotation engine backed by rate tables.
- Quote header + per-day breakdown (lodge, jeep, park, flight, transfer, activities)
- Markup types: standard (25%), TO, custom
- Rate master data: `jeep_rates`, `activity_rates`, `flight_routes`, lodge price tables
- Note: the authoritative quotation tool remains the per-PAX Excel files (`*_Calc.xlsx`); the Hub quotes module mirrors that logic

### 3.6 Wetu integration (`modules/leads/wetu.php`)
Wetu itinerary builder integration for staff-built personalised itineraries from Sample programmes.
- SOAP API (`AuthenticateUser`, `LoadItinerary`, `SaveItinerary`) for auth and itinerary ops; WSDL at `https://wetu.com/api/itineraryservicev8.asmx?WSDL`
- JSON REST API (`/API/Itinerary/V8/List`) for listing Samples (wrapper object, not a plain array — skip non-array elements when iterating)
- Five named user accounts; passwords never stored in session
- Samples fetched at login with full pagination, cached in `$_SESSION['wetu_samples']`
- Language inferred from sample name keywords
- Dashboard: `https://dashboard.wetu.com/ItineraryBuilder/Personal`

### 3.7 Leave Calendar
Staff leave management calendar.
- Tables: `leave_employees` (balances/allocations), `leave_entries`, `leave_meta`

### 3.8 Memo Board (`modules/memo/`)
Private per-user board for memos, todos, and notes with self-scheduled email reminders (one-shot or recurring daily/weekly/monthly). Decoupled from the requests/leads system.
- Post-it style UI, colored cards, drag-to-reorder, pin-to-top
- `cron_reminders.php` token-protected endpoint (`MEMO_CRON_TOKEN` in config), EAT timezone, self-advancing recurring reminders
- Reuses external cron pattern (cron-job.org HTTP endpoint)

### 3.9 RBAC (permissions)
Role-Based Access Control across the whole Hub.
- Tables: `roles`, `role_permissions` (per-module grants — `module` is a flat string, **not** `module.action`), `users.role_id`
- Permissions editable from the UI; checks via `has_permission()` / `require_permission()`
- Leads-specific helpers: `isLeadsRestricted()` (non-admin/manager), `isLeadsAdmin()`, `isInvoiceAdmin()` (gates delete/cancel only)

**Default permission map** (module grants per role):

| Role | hub | operations | leave | leads | invoices | admin |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| admin | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| manager | ✅ | ✅ | ✅ | — | — | — |
| operations | ✅ | ✅ | — | — | — | — |
| staff | ✅ | — | — | — | — | — |
| accountant | ✅ | — | — | ✅ | ✅ | — |

Notes:
- `staff` see and can view **all** requests (no own-agent filter); `accountant` has full edit access to requests despite not being admin/manager.
- ITI-specific roles also exist (see `ITI_module_reference_EN.md`).
- Seeding/repair script: `init_hub.php` defines the canonical role→module list; grants applied idempotently via `INSERT IGNORE`.

---

## 4. Architecture & conventions

### File structure
```
hub/
├── includes/              # shared: config.php (off-repo), auth.php, db, layout_header.php, mail_helper.php
├── sessions/              # private session storage (off web root logic)
├── migrations/            # NNN_name.sql migration files
├── admin/
│   └── uploads/signatures/{user_id}.html
├── hub.php                # landing dashboard (module cards, gated by has_permission)
├── login.php / logout.php / forgot_password.php / reset_password.php / change_password.php
├── init_hub.php           # role/permission seeding & repair
└── modules/
    ├── leads/             # Lead Tracker + CRM (own config.php, requires hub config internally)
    │   ├── config.php     # off-repo; defines API_KEY (line 35)
    │   ├── header.php     # LEGACY, unused
    │   ├── *.php          # requests, request_view, request_edit, booked, reconcile, wetu, pricing, api_*.php
    │   └── includes/      # header.php (ACTIVE), folder_parser.php, send_modal.php, mail_helper.php, phpmailer/, dropbox_helper.php
    ├── invoices/          # invoices, credit notes, payments, api_import/api_search_*.php
    │   ├── includes/      # header.php, auth helpers
    │   └── assets/        # style.css
    ├── iti/               # Itinerary Builder
    │   └── includes/      # iti_functions.php (iti_nav, iti_field, iti_redirect, iti_flash_set)
    ├── operations/        # index.php (movements / arrivals & departures)
    └── memo/              # ajax.php, cron_reminders.php, index.php, memo.js, memo.css
```
> Note: `modules/leads/` has **two** header files — the active one is `includes/header.php`. The top-level `modules/leads/header.php` is legacy.


### Auth & session
- `requireLogin()`, `current_user()`, `is_admin()` from `includes/auth.php`
- `current_user()` returns session data **without** `agent_id` — query the `users` table for it
- `h()` for HTML escaping; `db()` returns the PDO instance
- Role checks in leads: `isLeadsRestricted()`, `isLeadsAdmin()`
- Session storage redirected to private `sessions/` directory (fixes premature logout on BlueHost)
- Per-user HTML email signatures in `admin/uploads/signatures/{user_id}.html`, appended server-side

### Standard page pattern
```php
require_once 'config.php';
requireLogin();
$pageTitle = '...';
include __DIR__ . '/includes/header.php';
// $extra_css is wrapped in <style> tags inside the leads header
```

### Two header files in Leads
- `modules/leads/header.php` — **legacy, unused**
- `modules/leads/includes/header.php` — **active**; target this for nav changes
- Relative `href` paths computed from the calling page's directory (`modules/leads/`), not the include's location

### BlueHost / server specifics
- Server timezone is US-based; every PHP entrypoint calls `date_default_timezone_set('Africa/Dar_es_Salaam')` for EAT
- MySQL frequently drops with `ERROR 2006 (HY000)` and auto-reconnects — normal
- PHP CLI not available for syntax checking; use Node.js for JS validation
- `set_time_limit()` may be in `disable_functions` — wrap with `@`
- Error logging: `ini_set('error_log', dirname(__DIR__, 2) . '/wetu_errors.log')` pattern at hub root

### PHP patterns
- Avoid PHP 8-only constructs (`match`, arrow functions, `str_contains/ends_with/starts_with`) for BlueHost compatibility
- `ob_start()` at top of AJAX handlers to prevent PHP warnings corrupting JSON
- `db()` is a function with a static variable — never use `global $pdo` in leads context
- Two `mail_helper.php` files (root and `modules/leads/includes/`); shared utilities need `function_exists()` guards in both
- Wrap function defs in `if (!function_exists(...))` and constants in `if (!defined(...))` where re-inclusion is possible
- Stored-procedure `information_schema` comparisons need `COLLATE utf8_general_ci` (app schema is `utf8_unicode_ci`)

### JS patterns
- Common errors: duplicate function declarations, template literals in `innerHTML`, `let`/`const` before declaration, `await` outside async functions
- Use `var` + string concatenation for top-level JS

---

## 5. Deploy workflow

```
local edit → git push (Windows PC) → DeployHub.bat → SSH to server → deploy.sh
```

`deploy.sh` runs `git fetch` + `git reset --hard origin/main` (preserves `config.php`).

- **Deploy is manual** — run `DeployHub.bat` after each push. Claude never auto-deploys.
- **`DeployHub.bat` is additive** — deletions are NOT propagated; deleted files must be removed manually on the server via SSH (`rm`).
- `deploy.sh` loses its executable bit after git checkout on Windows. Fix: `git update-index --chmod=+x deploy.sh`, commit, and use `bash deploy.sh` in the batch file.
- Java files require `git add -f` (`.gitignore` covers `NetBeansProjects/`).
- Add `.claude/worktrees/` to `.gitignore`.
- Commit identity: `git -c user.email="rdesibi@savannahexplorers.com" -c user.name="Roberto De Sibi" commit`

---

## 6. Local development

There is **no local dev environment** — the Hub runs only against BlueHost (the live server) and its shared `savannp5_savannah_leads` database. Code is edited locally, pushed to GitHub, and deployed to the server, where it is tested directly.

Implications:
- No XAMPP / Docker / local LAMP setup is maintained. PHP behaviour (mail via `isMail()`, BlueHost `disable_functions`, US server timezone, MySQL auto-drop) can only be reproduced on the server.
- PHP cannot be lint-checked locally via CLI (not installed on BlueHost either). JS is validated with Node.js; Python is used for regex/logic simulations before committing.
- Because there is only one database, **test changes carefully** — there is no staging DB. Schema changes go through migrations (§7) applied in phpMyAdmin or via SSH `mysql`.

> If a local sandbox is ever needed, the minimum would be PHP 8 + MySQL with a `config.php` pointing at a throwaway copy of the schema (`db_schema.sql`) — but mail, Dropbox, HubSpot, and Wetu integrations would not function without their live credentials.

---

## 7. Database migrations

Migration files live in `migrations/` with the naming convention `NNN_description.sql` (e.g. `050_memo_module.sql`). They are **not** applied automatically by deploy.

To apply a migration manually, either:
- **phpMyAdmin** — paste the SQL into the SQL tab and run, or
- **SSH** — `mysql -u <user> -p savannp5_savannah_leads < migrations/NNN_name.sql` (or `source migrations/NNN_name.sql;` inside the mysql prompt).

Conventions:
- Write migrations to be **idempotent** where possible (`INSERT IGNORE`, `CREATE TABLE IF NOT EXISTS`, guarded `ALTER`).
- Verify current schema state with a MySQL query **before** applying.
- `init_hub.php` is the canonical seeder for roles/permissions; re-running it is safe (idempotent grants).
- `db_schema.sql` is a periodic full snapshot, not a migration — regenerate after structural changes.

---

## 8. API endpoints

### Leads (`modules/leads/`) — auth: `X-Api-Key` header == `API_KEY`
Sent by the Java BackOffice GUI (`api.key` in `config.properties`). ~11 endpoints, all comparing the key identically:
`api_create_request.php`, `api_folder_agents.php`, `api_send_email.php`, `api_get_agent_email.php`, `api_get_agents.php`, `api_create_agency.php`, `api_get_agencies.php`, `api_confirm_safari.php`, `api_rename_folder.php`, `api_update_status.php`, `api_confirm_booking.php`

### Invoices (`modules/invoices/`) — auth: `X-Hub-Token` header == `API_IMPORT_KEY`
`api_import.php`, `api_search_requests.php`, `api_search_billtosource.php`

### Memo (`modules/memo/`) — auth: token == `MEMO_CRON_TOKEN`
`cron_reminders.php` (called by cron-job.org)

---

## 9. Security TODOs (defer to in-office session)

- **Rotate `API_KEY`** (`modules/leads/config.php` line 35). Generate with `openssl rand -hex 32`; update only the `define` + `config.properties`; edit on server outside repo; test Lookup Leads. Endpoints unchanged (all compare identically).
- **Rotate `API_IMPORT_KEY`** (root `config.php`; currently weak: `savannah-import-2026`). Identify sending client first (`grep` GUI/scripts for `X-Hub-Token`).
- **Verify/rotate `ANTHROPIC_API_KEY`** (`sk-ant-` key used by `iti/iti_fix_destinations.php`) — highest priority if ever stored in cleartext (tied to billing).
- Rotate ONE key at a time and test its client immediately. Both config files are outside the repo: edit directly on the server via SSH, no git deploy.
- Never store credentials in git-tracked files; `modules/leads/config.php` and root `includes/config.php` are intentionally outside the repo.
- GitHub PATs provided fresh each session; rotate after each session.

---

## 10. Integrations

| Integration | Notes |
|---|---|
| Dropbox API | OAuth2, refresh token in `modules/leads/config.php`; `sharing.write` scope |
| HubSpot | Private App; staging pipeline, iBot mapping |
| Wetu | SOAP + JSON REST; five named accounts |
| PHPMailer | `isMail()` transport (`modules/leads/includes/phpmailer/`) |
| dompdf / PHPWord | document generation |
| Anthropic API | `iti/iti_fix_destinations.php` |
| Cron (cron-job.org) | external HTTP endpoints (memo reminders, HubSpot sync) |

---

## 11. Design tokens

- Brand red: `#C0211B`
- Off-white: `#F7F5F2`
- Fonts: Open Sans + Merriweather (web); Cormorant Garamond + Source Sans 3 (PDF brochures)

---

## 12. Database

Database `savannp5_savannah_leads`. Tables grouped by domain. The committed snapshot is `db_schema.sql` (generated 2026-05-16) and covers the core/leads/invoices/quotes/lodge/leave tables below; the `iti_*` and `memos` tables were added later and are **not** in that snapshot — regenerate it to capture them.

### Core / RBAC
`users`, `roles`, `role_permissions`, `password_resets`

### Leads / CRM
`requests`, `requests_import`, `lead_staging`, `agents`, `agencies`, `customers`

### Invoices
`invoices`, `invoice_items`, `invoice_payments`

### Quotes / rates
`quotes`, `quote_days`, `quote_day_items`, `quote_day_rooms`, `quote_safari_items`, `jeep_rates`, `activity_rates`, `flight_routes`

### Lodges (pricing master data)
`lodges`, `lodge_room_types`, `lodge_seasons`, `lodge_season_periods`, `lodge_prices`, `lodge_supplements`

### Operations
`movements`

### Leave
`leave_employees`, `leave_entries`, `leave_meta`

### ITI (not in the 2026-05-16 snapshot)
16 `iti_*` tables — see `ITI_module_reference_EN.md`

### Memo (not in the 2026-05-16 snapshot)
`memos`

### Key foreign keys
- `users` → `roles(id)`, `agents(id)`
- `requests` → `agents(id)`
- `role_permissions` → `roles(id)`
- `lodge_room_types`, `lodge_seasons`, `lodge_supplements` → `lodges(id)`
- `lodge_season_periods` → `lodge_seasons(id)`
- `lodge_prices` → `lodge_room_types(id)`, `lodge_seasons(id)`
- `quote_days`, `quote_safari_items` → `quotes(id)`
- `quote_day_items`, `quote_day_rooms` → `quote_days(id)`

---

## 13. Key reference docs (project knowledge)

- `ITI_module_reference_EN.md` — Itinerary Builder reference
- `KB_Quotazioni_Safari.md` — safari quotation pricing rules
- `PLAYBOOK_aggiornamento_prezzi_2026-27.md` — price update playbook
- `FONTS_BASE64.md` — base64 fonts for PDF pipeline
- `INFRASTRUCTURE.md` — hosting/deploy (needs cron section + additive-deploy note)
- `db_schema.sql` — database schema snapshot (2026-05-16; predates ITI & memo tables)
- `Savannah_Explorers_Policy_Document.pdf` — procedures & sustainability policy
