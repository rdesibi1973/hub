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
