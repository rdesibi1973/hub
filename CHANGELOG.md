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

## 2026-09 — Agent API phase 2: rates, fill_calc, Calc checks
- `get_rates`: program prices read from the Calc template pax sheets (H9 rack, H10 sto,
  H11 single, H13/H14 discounts) + flight routes and activities/transfers with **sale and cost**
  (new `cost_pax` / `cost` columns, editable in Pricing → Jeep, Activities & Flights).
- `fill_calc`: fills the booking Calc server-side (PhpSpreadsheet 1.29) with the house rules —
  single pax sheet, H6:I14 cleared, F9 as formula, date cascade, flight cost from the rate table,
  hotels, guests, room type, flights — recalculates (saved values for the Hub parser), uploads with a
  Dropbox rev check and re-verifies. Dry-run unless `"confirm": true`.
- Confirm preview (UI + API) gains Calc house-rule checks; `error` level shown as red ✖ and blocks
  the API confirm unless forced. `rollback_booking` added to the API (logic shared with BackOffice).

## 2026-09 — Agent API v1 (Claude runs the booking workflow)
- `api/agent/index.php?action=…` → `modules/leads/agent_api.php`: find_requests,
  list_agencies, create_request, update_request, list_standard_programs, copy_program,
  confirm_preview, confirm_booking, send_booking_email. Key `AGENT_API_KEY`
  (header `X-Agent-Key`) acting as Hub user `AGENT_API_USER`; HTTPS only, 60/min,
  every call in `agent_audit_log`; confirm/email are dry-runs without `"confirm": true`.
  See `docs/AGENT_API.md`.
- Refactor: request creation, Copy Programs and Confirm Safari (+ booking email
  template/sender) moved into `modules/leads/includes/booking_service.php`, now used
  by `request_add.php`, `request_view.php`, `backoffice.php`, `ajax_booking_email.php`
  and the API — one implementation for UI and agent.
- Not yet: rate tables / `get_rates`, `fill_calc`, new Calc validations (handoff §4–6).

## 2026-09 — CK tracker: automatic SafariCheck (phase 2)
- When a folder first reaches Deposit/Balance/Balance-Cash/Paid (booking done),
  the Hub marks it for a check and starts the safariagent workflow on GitHub
  Actions (`workflow_dispatch`, repo `CK_GITHUB_REPO`, token `CK_GITHUB_TOKEN`).
  Also a **▶ Check** button per row, and a nightly run (pending requests +
  upcoming folders without CK whose files changed — fingerprint of content hashes).
- `ck_agent_api.php` (token `CK_AGENT_TOKEN`): queue / recursive listing / file
  download / result. The runner has **no Dropbox credentials**; the API serves only
  what the checks open (top-level xlsx/xlsm/docx/pdf and `invoices/*.pdf`) —
  passports and everything else are recreated by the runner as empty placeholders
  (only counted). Results in `ck_checks` (traffic light, checks, parsed booking
  facts for phase 3, file list, HTML report shown sandboxed by `ck_report.php`).
- CK tracker: Check column (🟢🟡🔴 + counts, link to report, ⏳ while running),
  "Check RED" tab/tile.

## 2026-09 — CK tracker (Leads → ✅ CK)
- New page `modules/leads/ck_tracker.php`, replacing the Java "Groups & CK →
  Missing CK" (`MissingCK.bat`). Lists every top-level booking folder in
  `/001_Safari` with days to arrival, stage (Progress → Deposit/Balance/…), days
  in that stage, and days waiting for the CK since booking finished. Urgency
  bands for folders without `_CK`: red < 60 days to arrival, amber 60–90
  (supplier penalties), grey > 90. Tabs: Missing CK / In booking / CK done / All;
  filters by sales person (sellers default to their own), started trips, other
  destinations (Kenya/Uganda/… — left out by the old script).
- **Set CK / Remove CK** renames the Dropbox folder (`…_CK`), syncs the
  requests (private `practice_code` or GRP members' `group_folder` + url) and
  records who did it. Anyone with Leads access can use it.
- History in `ck_folders` / `ck_events` (migration `058_ck_tracking.sql`, also
  created lazily). Folders are keyed by Dropbox file ID, so renames are tracked.
  Changes come from a scan of `/001_Safari` on each page visit, from BackOffice
  status changes (with the user), and from `ck_cron.php?token=…` (MEMO_CRON_TOKEN,
  or CK_CRON_TOKEN if defined) for the external cron service. The first scan is a
  baseline: existing stages/CKs show "before tracking".
- Phase 2 (planned): run the safariagent checks automatically when a folder
  reaches Deposit/Balance/Balance-Cash, and reset the CK when the booking changes.

## 2026-09 — Itinerary map: start/end airports + layout (ITI)
- The map now shows the trip **start and end** — the arrival/departure airport —
  in addition to the overnight stops. Airports are derived from the first/last
  day's transfers (free-text, matched by IATA `[CODE]` or name) or flights
  (route codes); when none is found it falls back to the day's start / final
  destination coordinates. New airport coordinate table + matchers in
  `iti_functions.php` (`iti_map_airports`, `iti_match_airport`, `iti_day_airport`).
- New `iti_get_program_map()` returns the full route (airport start → numbered
  stops → airport end), grouped markers, and legend rows with per-leg
  straight-line distance. Airports render as a distinct slate ✈ pin.
- Layout: larger map with the **legend beside it** (stacks on mobile) via a
  shared `includes/iti_map_script.php` + restyled `includes/iti_map_legend.php`,
  used by `program_view.php` and `itinerary.php`. Legend shows distances.
- Word export (`export_word.php` + `iti_map.php`) plots the same airport pins
  (slate, labelled with the IATA code) and lists arrival/stops/departure with
  distances. `iti_map.php` markers now accept a per-point `label`/`airport`.
- New labels: `iti_lbl_map_start/end/airdist`.

## 2026-09 — Itinerary map: merged markers + legend (ITI)
- Fixed overlapping map markers: when a stop is visited more than once (e.g. a
  return to the same lodge on day 2 and day 4) the two pins used to stack and one
  disappeared. Stops sharing coordinates now collapse into a single marker whose
  label combines the numbers ("2 & 4"). The route line still visits every point
  in order, so the out-and-back leg stays drawn.
- New `iti_group_map_points()` helper (`includes/iti_functions.php`) is the single
  source of the grouping, used by both interactive maps and the export.
- Added a **legend** under the map (new `includes/iti_map_legend.php`) listing each
  numbered stop and its name — shown on `program_view.php` (client view) and
  `itinerary.php` (editor), and as a table under the map in the Word export
  (`export_word.php`). New label `iti_lbl_map_legend()`.
- Interactive markers are now auto-width pills so combined numbers fit; the PNG/Word
  export (`includes/iti_map.php`) draws the same pill via a new `iti_map_pill()`.

## 2026-08 — Voucher generator (ITI)
- New `modules/iti/vouchers.php`: upload a WeTu Word programme (.docx) + the Excel
  calc (.xlsx), review/edit traveller names (Mr/Mrs), dietary notes, per-lodge
  details and transfers/internal flights, then download vouchers as **PDF**
  (Dompdf) or **Word** (PhpWord)
- Accommodation vouchers skip own-arrangement stays; transfer vouchers auto-attach
  the departing flight; meal basis mapped (FB/HB/B&B → full text); dates parsed
  from the Italian programme
- Each voucher carries the Savannah Explorers logo + standard phone contacts
  (Office / Emergency / Zanzibar transfers) and a highlighted service band (lodge
  name or transfer route); review screen has per-voucher include/skip checkboxes
- Zanzibar airport drop-offs get an automatic pick-up-timing note (4.5h intl /
  3.5h internal), editable in review
- Input parsing is dependency-free (ZipArchive + DOM) — no new vendor libs needed
- `includes/voucher_lib.php`: parsers, model builder, lodge lookup, both renderers
- Migration `054_iti_voucher_lodges.sql`: `iti_voucher_lodges` directory (GPS /
  phone / address per lodge, missing from the WeTu export), seeded from existing
  vouchers; the review screen can fill + save new lodges
- Hub dashboard gains a **Vouchers** card; ITI nav gains a **Vouchers** tab

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
