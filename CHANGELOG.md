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

## 2026-10-25 — Dropbox conflicted copies: nightly check
- Calc kept open in Excel on one PC while another PC or the Hub saves it → Dropbox "conflicted copy" (all PCs share one
  Dropbox account, so Dropbox file locking cannot help). New nightly run `modules/leads/conflicts_cron.php` (cron-job.org
  every 30 min, works 01:00–07:00 EAT), logic `includes/dbx_conflict_service.php`, page `dropbox_conflicts.php`
  ("🔀 Conflicts" in the Lead Tracker menu), Agent API `dropbox_conflicts` / `dropbox_conflicts_run`.
- Each copy in `/001_Safari` (not `old/`) is compared cell by cell with the original and its Dropbox version history:
  copies that add nothing, or that changed other cells than the original (merged into it, `.xlsx`, ≤ 40 cells, read back
  before and after upload), go to the Dropbox trash; the rest → memo + email to the booking's CK owner.
- **Not live yet**: until `DBX_CONFLICTS_LIVE` is set in `includes/config.php` the run only analyses. To do on the
  server: add the cron-job.org job, check the page after a night, then set the constant.

## 2026-10-25 — CK procedure, "?" links, "Group closed"
- New office procedure **CK AND BOOKING FOLLOW-UP** (Dropbox `/Operations/OfficeProcedures`, Word source in `orig/`, same
  layout as the other procedures, English then Italian): listed by the procedures page automatically.
- "?" links: `procedures_map.php` keys `ck_followup` (page 1) and `ck_payments` (section 6, page 2); on Booking follow-up and
  the CK tracker (next to `folder_status`), and `ck_payments` on Payments. `proc_help_link($key, $base)`: `$base` for pages
  outside `modules/leads/` (Booking follow-up now loads `modules/leads/config.php` for the Dropbox listing, as its API does).
- **Group closed** (migration 075, also created by `ck_ensure_schema()`): `ck_folders.group_closed_at` / `group_closed_by`,
  `ck_events` `group_closed` / `group_reopened`. `ck_is_group_folder()` (GRPnnnn, Diamante / Panorama, GRUPPO, `_PROVISIONAL`,
  folders used as `group_folder`). `_CK` on a group not closed is refused everywhere the provisional block applies
  (`ck_pipeline_block()`: CK tracker Set CK, Rename…, `rename_folder` 409, request edit) with "Group not closed: mark it closed
  in the CK tracker first"; `ck_pipeline_block_message()` words both reasons. CK tracker: group state + "👥 Group closed" /
  "↩ Reopen group" (admin, CK owner, booking owner), "Blocked" tile counts open groups too. Agent API: `ck_status` `is_group`,
  `group_closed`, `group_closed_at`, `group_closed_by`; new `ck_set_group_closed`. Digest: groups arriving ≤ 60 days not closed.

## 2026-10-25 — CK owner default = the seller of the folder
- `ck_owner_rule()` defaults to `seller` (was `fixed:rdesibi`; `CK_OWNER_DEFAULT` in the server config still overrides).
  Seller = the last name in the folder's `(…)` tag that is a Hub user (`ck_seller_names()`, `ck_seller_user()`):
  username or first name, channel / destination tags and `TZ`/`KE` suffixes skipped; "Roberto" in a tag = `rdesibi`
  (`CK_OWNER_TAG_ALIASES`; Roberto Capri is `roberto`). Sellers who left: `CK_OWNER_LEFT` in the server config
  (`['sultan' => 'saruni', 'micky' => 'saruni']`). Not matched → no CK owner ("not assigned"); CK tracker tooltip names the
  tag's seller, Owner filter "No CK owner". Owners set by hand and open sales lines keep their owner.

## 2026-10-25 — Booking follow-up: Claude transfer findings merge with SafariCheck lines
- Bug (Buffone, request 2802): `ck_findings_upsert` `transfer_missing` 5 / 10 Feb (supplier Iddi, "ZNZ airport → Z Hotel")
  came back as `would_add` next to the open SafariCheck lines 187 / 188 ("Transfer 05 Feb · Karatu-Ark-ZNZ") — the match
  still required the same supplier / service words. `followup_service_match()`: transfers and activities match on request +
  type + date; stays keep the lodge match. On merge the line keeps its owner and gets `claude_check` in `sources`.
- `followup_service_type()`: SafariCheck issues typed from the code (`sc_transfer` → transfer, `sc_flight` → flight,
  `sc_dates` → dates, others → stay). `followup_backfill_keys()` (once, flag `backfill_keys_2`): service_key recomputed on
  open lines without one and on every open SafariCheck line. `followup_list` rows show `service_key`.

## 2026-10-25 — One pipeline: CK tracker + Booking follow-up
- **Migration 074** (additive; `followup_schema()` does the same on first use): `booking_followups` types `safaricheck` and
  `manual`, columns `sources` (JSON: the checks still reporting the line), `service_key`, `due_date`, `due_reason`; table
  `booking_followup_meta`. The first scan / page view after deploy (`followup_backfill()`, once) writes the SafariCheck points of
  every upcoming booking's last check, and gives the open lines their service key, deadline and — when nobody owns them — their
  default owner.
- **One list**: every check writes into `booking_followups` (supplier / ticket / group scans, SafariCheck, Claude check,
  staff). A finding about a service another check has open (`followup_service_match()`: same request + audience, same supplier
  ref or same service type + date + supplier, initials too) joins that line's `sources` instead of a new line; a check that
  no longer reports it leaves `sources`; the line closes when none is left. A confirmed supplier document clears every
  "provisional" source of that service (`followup_clear_confirmed()`). `followup_apply()` rewritten for it.
- **SafariCheck booking points** → lines `type: safaricheck` when the runner posts a result (`ck_sc_booking_points()`,
  `CK_SC_BOOKING_POINTS` in `ck_lib.php`: stays, transfers, flights, dates, pax; naming / document points stay in the CK
  tracker). Ticking a point in the SafariCheck report closes its line (`ck_confirm.php`).
- **Owner on insert for every check** (booking → booking owner, sales → CK owner, accounts → Roberto); never reassigned.
- **Deadlines** (`followup_due()`): provisional expiry, supplier payment (check-in − 30 days; Orangi always; other lodges the
  days read in the document terms — `followup_classify()` reads `payment_days`, `FU_CLASSIFY_VERSION` 3, the office PC scan
  sends it), group release (arrival − 60), ticketing limit or flight − 7, else service − 14. Priority from the deadline:
  URGENT overdue / ≤ 7 days, HIGH ≤ 30. Default sort by priority and deadline.
- **Pipeline** (`modules/leads/includes/ck_pipeline.php`): stage confirmed → booked → issues → ready → ck, nearest deadline,
  days since the Claude check, `needs_check` / `next_check_reason` (first check after 7 days booked, re-check at 60 / 30 days
  before arrival). CK tracker: "Pipeline" column, row colour red / amber from the lines' priority (else the SafariCheck
  colour), view + tile "To check", tile "Blocked".
- **`_CK` blocked while a booking is provisional** (no override): `ck_set_marker()` (CK tracker button disabled with the
  reasons), `folder_rename()` (BackOffice Rename…, Agent API `rename_folder` → 409, also on the dry run), request edit form.
- Booking follow-up page: deadline under the priority, "found by" chips, filter "Found by", tab "Checks" (Claude check +
  SafariCheck), link to the CK tracker row and the booking-check report on every line of the booking, "＋ Add line" (manual
  lines with an editable deadline).
- Agent API: `ck_status` (`pipeline_stage`, `open_lines`, `open_by_audience`, `open_by_source`, `next_due_date`, `needs_check`
  filter + `next_check_reason`, `ck_blocked`, `ck_blocked_by[]`, `last_claude_check_at`, `last_safaricheck_at`),
  `ck_findings_upsert` (`merged[]` / `would_merge[]`, `cleared[]`, `service_type`), `followup_list` (`source`, `due_before`;
  rows with `sources[]`, `due_date`, `due_reason`), `rename_folder` (409 block), `followup_upsert` (`payment_days`).
- Memo digest: section "CK findings" → "Bookings pipeline" (URGENT lines by owner, CK blocked by provisional bookings ≤ 60
  days, first checks due).

## 2026-10-25 — CK owners, booking owners and CK findings in Booking follow-up
- **Migration 073** (additive; `ck_ensure_schema()` / `followup_schema()` do the same on first use): `ck_folders` gets
  `ck_owner_user_id`, `booking_owner_user_id`, `owners_updated_at/_by`, the cached default booking owner
  (`booking_owner_auto_id/_text/_at`) and the last booking check (`findings_checked_at`, `findings_report_path`);
  `booking_followups` gets type `ck`, `audience` (`booking` / `sales` / `accounts`, NULL = booking), `issue` VARCHAR(40)
  (was ENUM), `doc_status_text` VARCHAR(500), close reason `auto_resolved`.
- Owners (`ck_lib.php`): CK owner default from `CK_OWNER_DEFAULT` (`fixed:rdesibi` when not set; `seller` = the user
  linked to the request's agent, `CK_SELLER_HANDOVER` for sellers who left); booking owner default = the name most
  often in the Calc CHECKED column among the stays (read through the cached `ops_calc()`, `OPS_CALC_VERSION` 4 adds
  `checked`). CK tracker: two owner columns (grey = default, inline select of every active user for admin and the row's
  owners), owner filter (me / user / no booking owner), "📌 N findings open" link, warning when setting `_CK` with
  open lines. Owner changes logged in `ck_events`.
- CK findings: `followup_ck_upsert()` — the findings of a booking check replace the booking's open ck lines (new →
  default owner by audience, missing → `auto_resolved`, resolved but still reported → re-opened); passport numbers
  stripped. `followup_ck_drafts()` — one English mail draft per owner, nothing sent.
- Booking follow-up: owners open to **every active user** (no more Glady / Lydia only); the current owner of a line
  works it without the module; reassign / not relevant: admin or the current owner. Tab + stat card "CK findings",
  filter "For" (audience), report link on ck lines; request view shows ck lines.
- Agent API: `ck_status` adds `ck_owner`, `booking_owner`, `open_findings`, `open_followup`, `ck_ready`,
  `findings_checked_at` (+ `owner` filter, `owners_pending`); new `ck_set_owners`, `ck_findings_upsert` (both dry-run
  unless confirm), `ck_findings_drafts` (read-only); `followup_list` accepts `type=ck`, `audience`; `rename_folder`
  warns when adding `_CK` with open lines. Memo digest: section "CK findings" (new by owner, open > 3 days, arrivals
  < 60 days).

## 2026-10-15 — ITI activities editable from the Agent API; documents print catalogue texts only in the programme language
- Agent API: `iti_activities` returns `name_<lang>` / `description_<lang>` for the 5 languages + `name_langs[]` /
  `description_langs[]` and takes `missing=name_<lang>|description_<lang>` (like lodges / destinations); new `iti_activity`
  (`activity_id` → the row) and `iti_update_activity` (`activity_id` + `fields`: `name_<lang>`, `description_<lang>`,
  `activity_type`, `destination_id` (null = generic), `duration_hours` → `changes {field: {from, to}}`, dry-run unless confirm).
  Logic in `modules/iti/includes/iti_content_service.php` (`iti_cs_activities` / `iti_cs_activity_row` / `iti_cs_update`);
  `iti_pb_activities()` removed. No migration: the 10 language columns were already there (SHOW COLUMNS, 15 Oct).
- Documents (magazine web, PDF, Word, guide sheet — all built from `iti_doc_data()`): the name / description of an activity,
  lodge or destination missing in the programme language is no longer replaced by the English one; the box is left out
  (`iti_doc_own()`), and `iti_doc_data()['missing_texts']` / `iti_program` `missing_texts[]` list the gaps. The programme's
  own texts keep the fallback.
- `iti_create_activity` no longer copies the first name into the languages not given (that hid the gap).

## 2026-10-14 — Booking follow-up: groups 60 days before arrival (+ ARK-ZNZ seats)
- New line type `group` (tab Groups, card "Groups to finalize", Run check "Groups"): every GRP folder, Il Diamante set
  departures and private groups tagged `_PROVISIONAL` arriving within 60 days. `group_to_finalize` collects client
  sub-folders, the Excel (RECAP + client sheets, or the Panorama workbook: pax, rooms), supplier blocks ("block 3 double
  rooms (6 adults)"), documents still provisional and names TBA, for the booking team to release / confirm and finalise.
  `group_znz_flight`: seats on the Zanzibar flight invoices ("Count: Adult: 4", "2XAdult") vs pax with ARK-ZNZ in their
  Calc sheet, ticketing time limit as expiry. A group closed by staff is not re-opened by the check.
- Classifier: block / rooms notes, names TBA, flight seats and direction (towards Zanzibar only), ticketing time limit;
  tested on the group folders of Oct 2026 – Aug 2027 (GRP2710: 4 seats = Ferri 2 + Pizi 2; Savannah collective GRPs:
  0 client folders vs the Orangi block). `followup_upsert` keeps every document sent (also groups without a confirmed
  request); the office PC scan runs the group check after sending. Cron `&type=group` daily. Migration 070 (or on first use).

## 2026-10-14 — Booking follow-up: provisional bookings + missing domestic tickets
- The 9 Oct Excel of supplier bookings still provisional went back and forth by mail untouched. Now one page,
  Operations → 📌 Booking follow-up (`modules/operations/booking_followup.php`, also on the Hub home, the Lead Tracker
  bar and the Operations tabs): stat cards, tabs All open / Provisional / Tickets / Problem / Closed, filters (owner incl.
  nobody, priority, supplier, trip month, search), Take in charge, inline contacted / status / notes, history per line,
  "Run check now". Everyone logged in can view; edits need the new module `booking_followup_edit` (Admin → Roles,
  "Booking follow-up (edit)"; added to the list in `admin/roles.php`, not in the server `MODULES`); admin also reassigns
  and closes as not relevant. Request page: a "Booking follow-up" box with the booking's open lines (GRP: the group's).
- The check (`modules/operations/includes/followup_service.php`): supplier documents in `<booking>/invoices/` (not `old/`)
  of confirmed trips not ended, classified by `followup_classify.php` (ResRequest "Reservation Status: Provisional
  (Expires …)", Orangi vouchers Confirmed with "x rooms provisional" notes, TNS / Sopa "will expire on", AeroCRS flight
  "Request", "Status: OPTION" …; tested on the 410 PDFs of the current trips, with and without pdftotext -layout); tickets
  from `ops_flight_tickets_report()` (flights in the next 14 days, no ticket file). Upsert by dedupe key, staff fields never
  touched, auto-close (confirmed / ticket saved / trip ended / not found), re-open when still provisional, every change in
  `booking_followup_events`. Tables: migration `069_booking_followup.sql` (or created on first use).
- BlueHost cannot read PDFs (no pdftotext, no Composer): the server supplier scan says so and reads nothing new.
  `tools\followup_local_scan.bat [--confirm]` reads the PDFs on the office PC (Git's pdftotext) and sends them to the new
  Agent API action `followup_upsert`. Agent API: `followup_list`, `followup_scan`, `followup_update`, `followup_upsert`
  (dry-run unless confirm). Cron `modules/operations/followup_cron.php` (tickets daily, supplier Monday). Memo digest:
  section "📌 Booking follow-up" (new, problems, not taken in charge > 2 days, expiring ≤ 7 days, tickets ≤ 7 days).

## 2026-10-13 — CK tracker: SafariCheck reads programmes built in ITI
- SafariCheck (cloud runner) only read a Wetu Word: a booking whose programme was made in ITI got
  "Word file not found", and an ITI Word export (no Wetu tables) would give false reds.
- `ck_agent_api.php ?action=iti`: the ITI programme of the folder's requests — the active final one(s),
  else the newest personal one, with a start date; GRP: per client sub-folder — as SafariCheck data
  (stays night by night with meal basis from the day flags, room type, pax, adult/teen USD price from
  the price table, internal flights, exclusions). Shared logic: `modules/iti/includes/iti_ck_export.php`.
- safariagent (`iti_program.py`, `run.py`, `ci_check.py`): the ITI programme is checked when the folder
  has no programme `.docx` or the `.docx` is its ITI export; a Wetu Word still wins. The report notes the
  source; programme changes change the fingerprint (nightly re-check).
- Which programme is the booking's (same day, second step): only active finals generated from a Calc in
  `/001_Safari/` (a final generated while the request was still in `/2026/` does not count) and programmes
  marked Confirmed in ITI; `?action=iti` returns them as `programs` (`chosen_by` final / confirmed) and the
  others as `skipped` with the reason. The runner takes the final from the Calc it checks (file name; the
  folder is not compared, it is renamed as tags change), else the one Confirmed programme (several = red),
  else a final from another Calc (yellow); with none, "Word file not found" lists the skipped ones.

## 2026-10-09 — Incoming approve: channel + agency, shared folder name
- Bug: approving an Incoming lead never stored `agency_id` and stored `channel` only when the folder
  field was empty — but the form always pre-filled it, so almost every approval got `channel = NULL`.
  Agency leads got their "(Agency-Agent)" tag only by hand-typing it into the folder name (#2923).
- `staging.php` approve form: Channel (Direct / Agency / SB / Other, default Direct) and, for Agency, the
  searchable agency picker with ➕ Add (as `request_add.php`; agency mandatory). The folder preview follows
  channel / agency / agent; a hand-edited name is marked "edited by hand" (↺ rebuild) and is the only case
  where `folder_name_override` is sent. Eleonora Ongaro referral flag only for Direct (still "(Agent-EleonoraOngaro)",
  channel direct).
- `staging_action.php` approve now goes through `bs_create_request()` (own Dropbox / INSERT / notify code
  removed): stores `channel` + `agency_id`, folder built by `bs_request_folder_name()`, logs the
  "Request received" timeline event, CustomerInfo.txt gets the CUSTOMER block like New Request. The service's
  duplicate check is skipped (the operator reviewed the drawer flags; the lead would match itself in `lead_staging`).
- `booking_service.php`: `bs_request_folder_name()` takes an optional direct tag (default "Drct");
  new `bs_folder_name_error()` (Name(Tag), exactly one "(…)" tag at the end, no "/" or "\");
  `bs_create_request()` options `direct_tag`, `folder_name` (validated), `initial_request_optional`.
- `tools/backfill_request_channel.php`: reads the LAST "(…)" tag (broken double-tag names); direct also for
  `(RobertoCapri)`, any `EleonoraOngaro` tag and `(Nuru-Trekk|Trek|Tekk)`; `--list` prints each row filled.
  Then also `(Roberto-Trek)` direct; agent-first tags `(Sultan-Yeadimtravel)`, one-token `(SouriTrip)` /
  `(GoWorld_Alex)` read as agencies; aliases AVIT, Adriana, Avventure, Areatour, SouriTip, FedericaKailas.
  Run on the server 9 Oct: 118 requests filled in three runs (87 direct, 31 agency), 534 still NULL (mostly one-word
  `Client(Agent)` folders, agency spellings with no agency record, no folder). NULL-id lists kept in
  `~/channel_null_ids_20261009*.txt`.

## 2026-10-09 — Re-link a folder moved outside the Hub; agency edit; customer-name guard
- `rename_folder` / BackOffice "Rename…": new `current_path` (re-link a folder moved or renamed outside the
  Hub, e.g. #2923) and `rename_files` (also the `NN_<old name>…` Calc / Word files). Shared code in
  `folder_service.php` (`fs_rename_validate`, `fs_relink_resolve`, `fs_file_renames`, `fs_allowed_roots`).
  A re-link to Booked sets `confirmation_date` / `start_date` (no Rollback: no confirm snapshot). "Folder not
  found" suggests `current_path` with up to 3 candidates; the BackOffice form reopens with the path field.
- `update_request`: `agency_id` / `channel` (folder not renamed; warning with the suggested name,
  `bs_agency_tag_hint()`). Edit Request: Channel + Agency fields (admin/manager).
- Customer name guard (`bs_customer_name_error()`): no `( ) [ ]` in the name — New Request (inline),
  `create_request`, Incoming approve, Edit Request, Java `api_create_request.php`. Incoming approve also
  refuses a typed folder name with more than one `(…)` tag.
- `bs_camel_case()` keeps mixed-case words (`Luca DeSanctis` → `LucaDeSanctis`, was `Lucadesanctis`); Incoming
  approve and `api_create_request.php` now use it instead of their own copies.

## 2026-10-09 — Invoice PDF: one layout for the page and Dompdf
- `modules/invoices/includes/invoice_html.php` is now the only invoice / credit note layout (tables + class
  CSS, no flex / grid): `invoice_pdf.php` and `cn_pdf.php` (Print / Save as PDF) and Dompdf
  (`inv_pdf()` → API `save_invoice_pdf`, `api_send_invoice_email.php`, `invoices_zip.php`) render the same body.
- Dompdf PDFs now look like the printed page: logo + "Invoice", BALANCE DUE, issuer block, BILL TO, dates
  (Terms / Due Date only when set — no more "Due Date: —", no Currency row), ITEM & DESCRIPTION, Sub Total /
  Total / Payment Made / Balance Due, NOTES and TERMS & CONDITIONS side by side; SH: AfrAsia bank details per currency.
- The design follows Chrome's print (background graphics off): no dark table header or grey Balance Due fill,
  on screen too. SH bank-details block more compact (an SH invoice with 2 lines fits one A4 page) and never split.
- Dompdf uses Open Sans like the page: static TTF in `modules/invoices/assets/fonts/` (300 / 400 / 600 / 700,
  OFL), options from `inv_doc_dompdf_options()` (font folder in chroot, metrics cached in `sys_get_temp_dir()`).
  Checked against the Chrome print of SE-2026-0177: same column positions and line positions within 1–2 pt.
- `invoice_pdf.php` / `cn_pdf.php` now require login + invoices permission (`requireInvoiceAccess()`); before,
  any invoice or credit note could be opened by id without logging in. File name unchanged.

## 2026-10-09 — Agent API: Il Diamante group folders; update_request dry-run
- New `includes/group_import_service.php` (`gi_parse`, `gi_duplicates`, `gi_validate`, `gi_import`, plus
  `gi_plan` / `gi_move_plan` for the API): the logic of Import Group Folder, moved out of `import_folder.php`
  and `api_import_folder_parse.php` (same behaviour; pax / value now checked as numbers). An import now logs
  a "Group folder imported" timeline event.
- API `import_group_folder` (folder in `/001_Safari` → request, Booked / Balance; duplicate rules exact → 409,
  high → 409 unless `allow_duplicate`) and `move_group_folder` (`…/Diamante/2027-Groups/…_PROVISIONAL` →
  `/001_Safari/…_BALANCE|_DEPOSIT|_PAID`, optional `import` in the same call). Both dry-run unless `"confirm": true`.
- **Breaking for callers:** API `update_request` is now **dry-run unless `"confirm": true`** and returns
  `changes {field: {from, to}}` (on 7 Oct it changed pax / value of #3023 at the first call).
- API: Hub DB down → `{"ok":false,"error":"database unavailable"}` HTTP 503 instead of an HTML text
  (`includes/db.php`, flag `DB_FAIL_JSON`).
- Request view, imported groups (`group_folder` = `practice_code`, e.g. #3055, #3064): Dropbox folder no longer
  shown as `<folder>/<folder>`; CK box says "not in the CK tracker yet" instead of "not in 001_Safari" when the
  folder is in 001_Safari but not scanned yet. `req_folder_path()` fallback no longer doubles the name.
- Fix: renaming an imported group (Rename… / `rename_folder`, group payment tag in `grp_status.php`, `_CK`
  sync in `ck_lib.php`) wrote `dropbox_url = …/<new>/<old>` and left `practice_code` on the old name;
  now both follow the new name.

## 2026-10 — ITI ref number and file names = the copy_program Word
- Rule: a client programme's `ref_number` and every programme file saved in the booking folder are named
  like the Word `copy_program` copied there (`01_Name(Agency-Agent)_PumbaSafari`). Shared naming:
  `bs_program_file_name()` / `bs_copied_programs()` (`booking_service.php`).
- API: `iti_update_program` edits `ref_number` (max 60, no `\ / : * ? " < > |`); `iti_create_personal`
  derives it from the folder (exactly one copied Word, or `calc_file`; else 409 with the candidates);
  `iti_document` saves `<ref>.docx` / `<ref>.pdf` / `<ref>_Guida.pdf` (warning if no ref); `iti_program`
  returns `ref_number`. Documented in `docs/AGENT_API.md`.
- ITI editor: the header 💾 Save also saves Program Details when changed (+ unsaved-changes warning);
  Ref. Number validated; linking a request derives it with the same rule.

## 2026-10 — Agent API `rename_folder` (free rename, shared with BackOffice "Rename…")
- New `includes/folder_service.php`: the BackOffice free-rename logic (validation, `bo_do_rename`,
  CK tracker, `status_change` timeline — on every request of a group) moved out of `backoffice.php`
  into `folder_rename()` / `fs_rename_validate()` / `fs_rename_preview()` / `fs_rename_record()`.
  BackOffice "Rename…" and "Change status" use it; behaviour unchanged.
- API `rename_folder` (POST): `request_id`, `new_name`, `confirm`. Dry-run by default → `current`,
  `new_name`, `is_group`, `status_from_name`, `dropbox_path_old/new`, `warnings` (date tags malformed or
  out of order, `MM_DDMON_` prefix ≠ START, `_CK` not last, tags dropped, Calc dates ≠ new name).
  With confirm: Dropbox + Hub (name, URL, status/payment_status from the suffix) + CK + timeline.
  No email. Documented in `docs/AGENT_API.md`.
- Fix (UI + API): the folder is located by its stored `dropbox_url` first, then Dropbox search —
  search lags after a rename, so a second rename in a row failed with "not found".
- Tested on request 530 (rename + back); first real use: 2608 END08SEP → END08NOV2026.

## 2026-09 — Kilimanjaro Calc templates → .xlsx, safari layout
- The 7 Kili templates in `/itineraries/Kili/it/Trekking/` (`*_CalcPrice.xls`) converted to
  `*_Calc.xlsx` with the safari Calc layout, so Safari check, Payments, fill_calc and "📊 Excel"
  handle them: TOT PAX B1 / Adults B2 / Teenagers B3 / Children B4 / Jeeps B5, Totals on row 30,
  Tot price / Deposit (30%) / Balance in F10:F12, rack / sto / single supp in H9:H11,
  teen / child price + discount in H6:H7 / H13:H14, guests from row 43. Trek parameters (days,
  porters, guides, cooker) stay in H1:I4; the trek cost block is G33:H45.
- Formula changes: trek costs and hotels count all pax (B1, was adults only — children were
  ignored); Total Price `=SUM(B7:B8)*(1+D1)` (bank commission counted once — B7 already holds it);
  Price pp TO divided by TOT PAX; deposit 30% of Tot price (was 30% + 1.5% bank commission).
  With adults only, every computed value is unchanged (checked sheet by sheet).
- `std_programs.php`: Trekking programs copy the new files as `…_Calc.xlsx`. The old `.xls` are
  still in the folder — archive them once this is deployed.

## 2026-09 — "📊 Open Excel" link next to "📂 Open folder"
- BackOffice, Payments and Request view: new link that opens the booking's quotation Excel
  (`savannah://open?path=<folder>&open=calc`). The local handler picks the file with the same rule
  as `fill_calc`: top-level `*_Calc.xlsx` (Office `~$` lock files skipped), else any
  `.xlsx/.xlsm/.xls`; if several, the highest leading number wins. No Excel → it opens the folder.
  Also on every other "📂 Open": BackOffice contract / file-search folders, CK tracker, GRP groups.
  A GRP member's link carries `&up=1`: no Excel in the member's sub-folder → the group's Calc
  (same rule as Payments).
- `tools/savannah-open/savannah-open.js`: new `open=calc` mode; lists files via Shell.Application
  (JScript `Enumerator` over FSO `.Files` returns nothing on Windows 11 24H2). **Re-run
  `install.cmd` on each PC** to get it — an old handler ignores `open=calc` and just opens the folder.

## 2026-09 — ITI: import STO sample programmes (Wetu Word exports)
- `modules/iti/iti_import_sto.php` (button "Import STO" on Programs → Sample): reads the Wetu
  Word exports in Dropbox (`/itineraries/SafariClassic/it/Agenzia/2026-27/STO`) and creates Sample
  programmes — days, day titles and texts, transfers, activities, meals, included / not included,
  route (subtitle) and introduction (new `intro_<lang>` column). Fills lodge / destination
  descriptions only where empty; unknown lodges/places kept as free text. Preview before import;
  re-import replaces (tracked by new `source_ref` column). Parser handles Italian-localised Word
  styles (Titolo1…) and content controls. Prices are not imported (they come from the Calc Excel).

## 2026-09 — Agent API phase 2: rates, fill_calc, Calc checks
- `get_rates`: program prices read from the Calc template pax sheets (H9 rack, H10 sto,
  H11 single, H13/H14 discounts) + flight routes and activities/transfers with **cost and sale**
  (existing `rate_pax` / `rate` are costs; new `sale_pax` / `sale` columns, editable in Pricing).
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
