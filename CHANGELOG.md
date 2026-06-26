# Changelog — Savannah Explorers Hub

Running log of notable changes and current build state. Module-level "active / pending" status lives in `README.md` §3; this file holds dated detail that goes stale quickly.

> Format: newest first. Dates are approximate (session dates).

---

## Current build state (snapshot)

**Operational modules:** Leads/CRM, Invoices, ITI, Operations, Wetu, Leave Calendar, Memo Board, RBAC, Quotes/Pricing.

**RBAC:** fully implemented, permissions editable from UI. Roles seeded: admin, manager, operations, staff, accountant (+ ITI-specific roles).

**Pending / not yet done:**
- PHP help system (Option D): global `help.php` + contextual ⓘ tooltips + per-page "? Help" button — planned, not built
- `INFRASTRUCTURE.md`: cron section + additive-deploy note still to be added
- `db_schema.sql`: regenerate to include `iti_*` and `memos` tables
- Security: rotate `API_KEY`, `API_IMPORT_KEY`, verify `ANTHROPIC_API_KEY` (see README §9)

---

## 2026-06 — Memo reminders: extra recipients
- Reminder form now shows who the email goes to (your account, by default) and an
  optional "Also send to" field for additional comma-separated addresses
- Migration `053_memo_reminder_recipients.sql` adds `memos.reminder_emails`
- `cron_reminders.php` sends each reminder to the owner + any validated extras

## 2026-06 — Memo Board
- New `modules/memo/` module: per-user memos/todos/notes, post-it UI, drag-to-reorder, pin-to-top
- Self-scheduled email reminders (one-shot + recurring daily/weekly/monthly), self-advancing
- `cron_reminders.php` token-protected endpoint (`MEMO_CRON_TOKEN`), EAT timezone
- Migration `050_memo_module.sql` (table `memos`, soft-delete, `recur_rule`, `sort_order`)

## 2026-06 — ITI Settings & T&C
- `modules/iti/settings.php`: company info (admin-only), emergency contacts, logo upload, T&C management
- Per-program T&C overrides in `program_edit.php`
- T&C editor upgraded to Quill rich-text + `iti_sanitize_richtext()` sanitizer + `iti_richtext_to_phpword()` renderer
- `iti_terms_conditions`: column `version` renamed to `name` (varchar 20→50)

## 2026-05 — RBAC: operations role
- New `operations` role (Operations Hub only)
- Permission map after migration:
  - `admin` → hub, operations, leave, leads, admin
  - `manager` → hub, operations, leave
  - `operations` → hub, operations
  - `staff` → hub (operations removed)
  - `accountant` → hub, leads

## 2026-05 — Shared request visibility
- All staff now see all requests (removed `WHERE agent_id = self` force-filter in `requests.php`)
- Staff can view any request detail (removed access-denied redirect in `request_view.php`)
- Accountant role: full edit access to requests (overrides `isLeadsRestricted()` in `request_edit.php` / `request_view.php`)

## 2026-05 — Invoices module
- New `modules/invoices/`: invoices, credit notes, payments, Zoho PDF importer
- Numbering `SE/SH-YYYY-NNNN`; two issuers (Savannah Explorers Ltd, Savannah Holidays Ltd)
- Import/search API endpoints (`X-Hub-Token` / `API_IMPORT_KEY`)
- Cross-links between Leads and Invoices nav

## 2026-05 — Wetu integration
- `modules/leads/wetu.php`: build personalised itineraries from Wetu Sample programmes
- SOAP (auth + itinerary ops) + JSON REST (sample list); samples cached at login

## 2026-05 — Dropbox reconciliation
- `reconcile.php`: scans `/001_Safari/` or `/YYYY/`, matches folder names to `practice_code`
- `dropbox_list_folder()` with pagination added to `dropbox_helper.php`

## 2026-05 — Password reset
- `password_resets` table; `forgot_password.php`, `reset_password.php`, `change_password.php`

## 2026-05 — Email templates & booked requests
- `email_templates.php`: public/private templates, Quill editor, `$[ParameterName]` placeholders
- `booked.php`: start-date sorting, shared `includes/send_modal.php`
- `dropbox_open.php`: shareable Dropbox links (refresh-token exchange, `sharing.write` scope)
