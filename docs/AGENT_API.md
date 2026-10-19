# Hub Agent API (v1)

JSON API that lets Claude (Cowork / Claude Code / scheduled tasks) run the booking
workflow — new request → copy standard program → Confirm Safari → booking email —
without driving the web UI.

- **Endpoint:** `https://hub.savannahexplorers.com/api/agent/index.php?action=<name>`
- **Code:** `modules/leads/agent_api.php` (thin) → `modules/leads/includes/booking_service.php`
  (the same functions `request_add.php`, `request_view.php` Copy Programs and
  `backoffice.php` Confirm Safari use).
- GET actions take query parameters; POST actions take a JSON body.

## Setup (server, once)

1. Generate a key: `openssl rand -hex 32`.
2. In the **server-only** root `includes/config.php` add:
   ```php
   define('AGENT_API_KEY',  '<the key>');
   define('AGENT_API_USER', 'claude_agent');   // Hub username the key acts as
   ```
3. In Hub → Admin → Users create user `claude_agent` (active, role `manager`,
   agent = the agent new requests should default to, e.g. Roberto).
4. Optional: run `migrations/060_agent_api.sql` (the audit table is otherwise created on first call).

## Security

| Rule | How |
|---|---|
| Auth | Header `X-Agent-Key: <AGENT_API_KEY>` (constant-time compare). |
| Identity | Calls act as `AGENT_API_USER`; it must exist and be active. |
| HTTPS only | Plain HTTP → 403. |
| Rate limit | 60 calls/min → 429. |
| Audit | Every call (including failures and dry-runs) → `agent_audit_log` (action, request_id, user, HTTP code, dry_run, payload, result, IP). |
| Dry-run by default | `update_request`, `confirm_booking`, `send_booking_email`, `rollback_booking`, `rename_folder`, `import_group_folder`, `move_group_folder`, `set_request_status`, `cancel_invoice_payment`, `update_folder_status`, `import_zoho_invoice`, `create_invoice`, `update_invoice`, `send_invoice_email`, `memo_delete`, `create_agency`, `update_agency`, `save_agency_contact`, `delete_agency_contact`, `mail_send`, `mail_move`, `ck_request_check`, `followup_scan`, `followup_update` and `followup_upsert` (among others) do nothing unless the body has `"confirm": true`. |
| No deletes | No delete endpoint (mail included), except `memo_delete`, which is soft (`deleted_at`, the row stays). Undo a confirm with `rollback_booking` (or BackOffice → Rollback). |
| Mailbox | The key also reads `info@` (`mail_*`): keep `api.txt` as private as the mailbox password. |

**Server firewall (Mod_Security) — always send `User-Agent: Mozilla/5.0 (compatible; SavannahHubAgent/1.0)`.**
BlueHost's firewall scores requests: the default `curl/…` or `Python-urllib/…` user agent from a cloud IP starts with a
high score, and then even plain calls (`iti_lodges&q=Mdonya`) can cross the threshold and get HTTP 406, seemingly at
random (access log, 4 Oct 2026: 9 of ~110 curl calls blocked, 0 of the calls with the agent user agent). Many 406 in a
row can block the IP for a few minutes. With curl: `-A "Mozilla/5.0 (compatible; SavannahHubAgent/1.0)"`
(the examples write it as `-A "$UA"` with `UA="Mozilla/5.0 (compatible; SavannahHubAgent/1.0)"`).
If a call is still blocked: send JSON with `\uXXXX` escapes (`ensure_ascii`), or wrap the body as
`{"b64": "<base64 of the UTF-8 JSON>"}` (GET: `&b64=<base64 of a JSON object of the parameters>`), wait a minute, retry once.

Responses: `{"ok": true, …}` or `{"ok": false, "error": "…", …}` with a matching HTTP code
(400 bad input, 403 auth, 404 not found, 409 duplicate / blocked, 422 partial, 429 rate, 5xx server/Dropbox).
Hub database down → **503** `{"ok": false, "error": "database unavailable"}` (since 9 Oct 2026; before, an HTML
text): an outage, not a bad call — wait and retry, don't change the request.

## Actions

### `find_requests` (GET)
Filters (at least one): `q` (name / folder / email substring), `status` (one value or several
comma-separated, e.g. `Quoted,Hot-Quoted`), `agent` (agent id, agent name, or Hub username such as
`rdesibi`; two agents are called Roberto: 14 = Roberto De Sibi, the other is Roberto Capri — prefer the id
or the username), `channel` (`agency`|`direct`|`sb`|`other`), `year` (received). `limit` ≤ 100.
Use it as the duplicate check before `create_request`.

Each row also has `channel`, `agency_id`, `agency_name`, `last_activity_at` (latest timeline event,
summary or note) and `has_summary` — see [Request timeline](#request-timeline).
`channel` / `agency_id` are stored since Oct 2026 (migration 067); older rows were filled from the
folder name only where it is conclusive (`-Drct`, `-SB`, or a known agency short name), so on the
rest they are `null` and a `channel` filter does not match them.

```bash
curl -sH "$H" "$U?action=find_requests&status=Quoted,Hot-Quoted&agent=rdesibi&channel=agency"
```

### `list_agencies` (GET)
`q` → `[{id, name, short_name, type, active, address, email, contacts[]}]` (inactive agencies included);
`contacts[]` = `{id, agency_id, name, role, email, phone, primary, notes}`, primary first.

### `create_agency` (POST)
Adds an agency, same as Agencies → Add in the Hub (`agency_create()` in `includes/agency_service.php`, also used by
the ➕ Add Agency button and the Java BackOffice). **Dry-run unless `"confirm": true`.**
`name`*, `short_name` (default: the name in CamelCase, "eko spostamenti" → `EkoSpostamenti`), `type`
(`savannah` default | `promoservice` → short name ends `-PS` | `lamprati` → `-LAM`), `address`, `email`.
The short name is the agency part of folder tags `(Short-Agent)`: no spaces, brackets or `/ \ : * ? " < > |` (400).
An agency whose name or short name already exists, ignoring case, spaces and punctuation
(`EKO-spostamenti` = `Eko Spostamenti`), → **409** with `existing[]` — use that id. Run `list_agencies` first.
→ `{dry_run: true, agency: {name, short_name, type, address, email}}`; with `confirm` → `{created: true, agency: {id, …}}`.
Then `create_request` / `update_request` with `agency_id`.

```bash
curl -sA "$UA" -H "$H" -X POST "$U?action=create_agency" -d '{"name":"Viaggi del Mappamondo","email":"info@example.it"}'                  # dry run
curl -sA "$UA" -H "$H" -X POST "$U?action=create_agency" -d '{"name":"Viaggi del Mappamondo","email":"info@example.it","confirm":true}'
```

### `update_agency` (POST)
Edits an agency, same as Agencies → Edit in the Hub (`agency_update()` in `includes/agency_service.php`).
**Dry-run unless `"confirm": true`.** `agency_id`* plus any of `name`, `short_name`, `type`, `active` (bool),
`address`, `email` (top level or inside `fields`); only the fields given change, `""` / `null` clears address / email.
`short_name` `""` → CamelCase of the name; a new `type` re-applies the `-PS` / `-LAM` suffix to the short name.
Same checks as `create_agency` (email, short-name characters, **409** + `existing[]` when the new name / short name
belongs to another agency); unknown id → 404.
→ `{dry_run: true, agency: {…after}, changes: {field: {from, to}}, warning}`; with `confirm` → `{updated: true, …}`;
nothing different → `{unchanged: true}`. Changing the short name does **not** rename Dropbox folders: old folders keep the
old `(Short-Agent)` tag (`warning`).

```bash
curl -sA "$UA" -H "$H" -X POST "$U?action=update_agency" -d '{"agency_id":164,"address":"Via Roma 1, 00100 Roma"}'                 # dry run
curl -sA "$UA" -H "$H" -X POST "$U?action=update_agency" -d '{"agency_id":164,"address":"Via Roma 1, 00100 Roma","confirm":true}'
```

### `save_agency_contact` (POST)
Adds or edits a contact of an agency, same as the contacts on the Agencies page (`agency_contact_save()` in
`includes/agency_service.php`). **Dry-run unless `"confirm": true`.** `agency_id`*, `contact_id` (edit; omit to add),
`name` (* when adding), `role`, `email`, `phone`, `notes`, `primary` (bool) — top level or inside `fields`. On an edit
only the fields given change; `""` / `null` clears (not the name). `primary: true` makes the agency's other contacts
non-primary. Adding a contact whose email, or name (ignoring case and punctuation), is already a contact of the agency
→ **409** with `existing[]` — edit that one instead. Invalid email → 400; unknown agency / contact → 404.
→ `{dry_run: true, contact, changes: {field: {from, to}}}`; with `confirm` → `{created: true | updated: true, contact: {id, …}}`;
nothing different → `{unchanged: true}`. Existing contacts and their ids: `contacts[]` in `list_agencies`.

### `delete_agency_contact` (POST)
`contact_id`* → `{dry_run: true, contact}`; with `"confirm": true` → `{deleted: true, contact}`.

```bash
curl -sA "$UA" -H "$H" -X POST "$U?action=save_agency_contact" -d '{"agency_id":164,"name":"Anna Rossi","role":"Booking","email":"anna@example.it","phone":"+39 02 1234567","primary":true}'   # dry run
curl -sA "$UA" -H "$H" -X POST "$U?action=save_agency_contact" -d '{"agency_id":164,"contact_id":31,"phone":"+39 333 1234567","confirm":true}'
```

### `create_request` (POST)
**Dry-run unless `"confirm": true`** (since Oct 2026): the dry run checks the fields and the duplicates and returns
`folder_name`, `dropbox_path` and `values`; no folder, no request.

Same fields as the New Request form:
`customer_name`*, `initial_request`*, `channel` (`agency`|`direct`|`sb`|`other`, default agency),
`agency_id` **or** `agency_short`, `agent_id` **or** `agent` (default: the API user's agent),
`email`, `whatsapp`, `source` (Email), `destination`, `period`, `pax`, `status` (Inquiry),
`value_usd`, `commission_pct`, `date_paid`, `notes`, `date_received` (today),
`notify_agent` (default **false**), `dup_override` (default false).

Creates the Dropbox folder `/2026/<Name>(<AgencyShort>-<Agent>)` + subfolders + `CustomerInfo.txt`,
then the DB row (which also stores `channel` and, for agency requests, `agency_id`). A likely duplicate → **409** with `dup_candidates`; resend with `"dup_override": true`
only if it is really new.

`customer_name` is the client's name only: a name with `(` `)` `[` `]` (e.g. `LucaDeSanctis(LasciatiViaggiare-Daniela)`)
→ **400** "Write only the client name — the Hub adds (Agency-Agent) itself" (same rule on New Request, Incoming
approve, Edit Request and the Java `api_create_request.php`). The folder name keeps a mixed-case word as typed:
`Luca DeSanctis` → `LucaDeSanctis` (all-lower / ALL-CAPS words are capitalised: `mario ROSSI` → `MarioRossi`).

### `update_request` (POST)
**Dry-run unless `"confirm": true`** (since 9 Oct 2026; before, it wrote at the first call).
`request_id` + any of `customer_name, email, whatsapp, source, destination, period, pax, value_usd,
commission_pct, date_paid, initial_request, notes, agency_id, channel` (top level or inside `fields`; `""` / `null` clears).
`commission_usd` is recomputed when `value_usd` or `commission_pct` change. Status / folder are **not**
editable here (use `set_request_status` below, `confirm_booking` / BackOffice, `rename_folder`).

- `agency_id` must exist (`list_agencies`) and sets `channel` = `agency` unless `channel` is given.
  `channel` is `agency|direct|sb|other`; `agency` needs an agency (given or already stored); another channel
  clears the stored agency (`agency_id` + a non-agency channel together → 400).
- The folder is **not** renamed: when the folder's `(Agency-Agent)` tag does not name the new agency,
  `warnings[]` gives the suggested folder name — rename it with `rename_folder`.
- A changed `customer_name` follows the `create_request` name rule (400 on `(` `)` `[` `]`).

Dry run → `{dry_run: true, changes: {field: {from, to}}, ignored, warnings, request}` — only the fields that differ
(`7890` = `7890.00`). With `confirm` → `{dry_run: false, updated: [fields], changes, ignored, warnings, request}`.

```bash
curl -sA "$UA" -H "$H" -X POST "$U?action=update_request" -d '{"request_id":3023,"pax":4,"value_usd":15780}'                 # dry run
curl -sA "$UA" -H "$H" -X POST "$U?action=update_request" -d '{"request_id":3023,"pax":4,"value_usd":15780,"confirm":true}'
curl -sA "$UA" -H "$H" -X POST "$U?action=update_request" -d '{"request_id":2923,"agency_id":54,"customer_name":"LucaDeSanctis"}'   # dry run
```

### `set_request_status` (POST)
**Dry-run unless `"confirm": true`.** Same rules as the status menu in the Hub
(`bs_set_request_status()` in `includes/booking_service.php`).

- `request_id` **or** `request_ids` (array, max 50)
- `status`: `Inquiry` | `Quoted` | `Hot-Quoted` | `Lost` | `Cancelled`
- `lost_reason` — **required for Lost**: `insufficient_budget` | `trip_postponed` | `no_more_replies` |
  `agent_request` | `other` (same list as the "Mark as Lost" modal: `includes/lost_reasons.php`);
  `lost_note` optional. Lost writes `lost_reason` / `lost_note` and adds a request note
  "Marked Lost — <reason>: <note>".

Rules:
- `Booked` → **409**: bookings go through `confirm_booking` (folder + DB together).
- A request that is **Booked now** is not changed here (any status) → **409**: use `rollback_booking` / BackOffice.
- **Reopen** (Lost → `Inquiry` / `Quoted` / `Hot-Quoted`): as in the Hub status menu, `lost_reason` and `lost_note`
  are **cleared**; the "Marked Lost" request note and the `status_change` timeline event stay as history.
- A real change logs a `status_change` event in the request timeline (author: `claude_agent`).
  A request already in that status is left alone and reported `unchanged: true`.
- Each request is handled on its own (a failure does not stop the others). All ok → 200; some failed → **422**;
  a single id that fails → its own code (404 not found, 409 Booked, 400 bad input).

Response: `{ok, dry_run, status, to_change|changed, unchanged, failed, results, reopen_rule}`; each result is
`{id, ok, customer_name, folder, current_status, new_status, lost_reason}` plus `unchanged: true`, or
`error_code` + `error`, or `effects` (dry run) / `done` (confirm) — the list of writes — and, on a reopen,
`reopen: "Lost → Quoted"`, `lost_reason_removed`, `lost_note_removed`.

```bash
curl -sA "$UA" -H "$H" -X POST "$U?action=set_request_status" -d '{"request_ids":[2410,2411],"status":"Lost","lost_reason":"no_more_replies","lost_note":"No reply after 3 follow-ups"}'
curl -sA "$UA" -H "$H" -X POST "$U?action=set_request_status" -d '{"request_ids":[2410,2411],"status":"Lost","lost_reason":"no_more_replies","confirm":true}'
curl -sA "$UA" -H "$H" -X POST "$U?action=set_request_status" -d '{"request_id":2451,"status":"Quoted"}'   # reopen, dry run
```

### `list_standard_programs` (GET)
Program codes by group (`DumaShort`, `BeachDumaShort`, …) and the Confirm Safari `destinations`.
A program with a `disabled` reason (template missing in Dropbox) is not copied: `copy_program`
lists it under `disabled`. The switch-off list is `bs_std_programs_disabled()` in `booking_service.php`.

### `copy_program` (POST)
`request_id`, `program` (or `programs: [...]`), optional `prognum` (default: next free number).
Returns `copied`, `skipped` (already there), `missing` (template not found), `unknown`.

### `get_rates` (GET)
`program?` (e.g. `DumaShort`), `q?` (alias `search`: filters flights **and** activities), `route?` / `activity?`
(filter one list only), `date?` (default today). Every word of the filter must match ("Arusha Zanzibar" → routes with both);
the reply echoes `filter {route, activity}`. Flights match route / origin / destination / airline, activities name / category / notes.
- `program.sheets[]`: prices read from the program's **Calc template** (the single source of truth),
  per pax sheet: `rack` (H9), `sto` (H10), `single_suppl` (H11), `teen_discount` (H13), `child_discount` (H14).
- `flights[]`: `route`, `cost_pp` (rate_pax — what we pay, written in the Calc), `sale_pp` (sale_pax — price to agency) valid on `date`.
- `activities[]`: activities / transfers / safari-fixed with `cost` (rate), `sale`, `per` (pax|fixed).

The existing rates in Hub → Pricing → Jeep, Activities & Flights are **costs** (quotes add the markup on them); the new "Sale" column holds the price to the agency.

### `update_rate` (POST)
`type` (`flight`|`activity`|`jeep`), `id` (from `get_rates`), and any of `cost` (what we pay — `rate_pax` / `rate`),
`sale` (price to agency; not for jeep), `valid_from`, `valid_to` (YYYY-MM-DD or null), `active` (bool), `notes`.
Returns `before` / `after`; dry-run unless `"confirm": true`.

### `add_flight_rates` (POST)
`routes[]` as `replace_flight_rates`, appended to the price list (other rows untouched; a row with the same `route` and
`valid_from` is skipped and reported). Dry-run unless `"confirm": true`.

### `replace_flight_rates` (POST)
`routes[]` `{route, origin, destination, airline?, cost, sale?, valid_from, valid_to?, notes?}` — replaces the whole
`flight_routes` table (price-list update). Old rows are returned as `old_rows_backup` (and kept in the audit log).
Dry-run unless `"confirm": true`.

### `fill_calc` (POST)
Fills the booking's `NN_<folder>_<Prog>_Calc.xlsx` server-side (PhpSpreadsheet) with the house rules,
recalculates, and verifies the result the way the Hub parser reads it. **Dry-run** (built and verified
on a copy) unless `"confirm": true`; then uploaded with Dropbox `mode=update` + `rev` (409 if the file
changed meanwhile, e.g. saved from Excel), re-downloaded and re-verified.

| Field | Rule applied |
|---|---|
| `pax_sheet` (or `adults`/`teens`/`children`) | the other pax sheets deleted (names with trailing spaces handled); `RECAP` and non-pax sheets stay (GRP quotes). In a group calc only bare `NPAX` sheets go, client sheets like `1PAX Cacioli` stay. Kept-sheet formulas pointing at a deleted sheet → warning (#REF!) |
| `keep_sheets` `["3PAX"]` | sheets kept even if they are pax sheets (422 if not in the Calc) |
| — | H6:I14 cleared |
| `price_components` `[1625,255,70]` | F9 = `=1625+255+70` (never a hardcoded total) |
| `august_supplement` `true` | adds `35 × safari nights` between 1 and 25 August to F9 (beach nights excluded: from `mid_date`, else Zanzibar/coast names in B/K). `false` = does not apply (no warning) |
| `start_date` | A17 = date; A18+ `=SUM(A{n-1}+1)` extended to the last day, same format |
| `days[]` | from row 17 + `row_offset`; each: `label` (B), `flight` = route id/name → C `=<cost_pp>*$B$1` from the rate table (or `flight_cost_pp`, warned if not in the table), `park_fees` (F) + `park_fees_desc` (G), `activity` `{rate: name/id}` (cost from table) or `{amount, desc}` → H/I, `accommodation` (J), `hotel` → K, `repeat` n (every value copied to each repeated row) |
| `days[].accommodation`, `days[].park_fees` | a number (`279`) or a formula on numbers and cells (`"=279"`, `"=(250+71)*$B$1"`); `null` clears the cell. Changing `hotel` on a template row without `accommodation` → warning "hotel changed, J cost left from the template (Kifaru 1054)" |
| `guests[]` `{name,title,country}` | rows 43+ A/B/F, upper case; country ITALY inferred for -PS/-LAM agencies |
| `room_type` | A37; default `1 DBL` for MR+MRS |
| `adults_teen_chd` | A35; default "2 adults" |
| `arrival`, `departure` | A51, A56 |
| `mid_date` | first beach night, for the hotel-on-every-beach-night check |
| `file` | which Calc if the folder has several |

Returns `kept_sheets[]` / `removed_sheets[]`, `f9` (the formula written), `august_supplement` `{safari_nights[], pp_per_night, amount_pp, added}`,
`rows[]` `{row, date, cells {B…K}, changed[]}` — every day row as written (formulas as `"=215*$B$1 → 430"`)
and the columns this call changed, to review before `confirm` — and `verify`: `passed`, `checks[]` (see below),
`price_to_customer`, `total_price`, `total_costs`, `margin` (B10), `to_price_pp` (D10) — all read on the filled pax sheet,
also when RECAP is kept. HTTP 422 if a check has level `error`.
fill_calc adds two checks: `error` a night with a hotel in K but J empty or 0 (except "own arrangement");
`warn` safari nights between 1 and 25 August with no supplement in `price_components` and no `august_supplement`.

### `read_calc` (GET)
Read-only. `request_id`, optional `file` (which Calc if the folder has several), `sheet` (pax sheet
name). Reads the booking's Calc (no PhpSpreadsheet needed) and returns:
`file`, `calc_path`, `calc_rev` (Dropbox rev — compare later to spot changes), `sheet`,
`pax` `{adults, teen, child}`, `pax_text` ("3 adults"), `room_config` (A37), `extra_details`,
`start_date`, `end_date`, `nights`,
`days[]` `{row, date, label, flight_cost, park_fees_desc, activity_desc, hotel_text, invoice, checked}`,
`guests[]` `{name, title, dob, country, tba}` (no passport numbers), `guests_tba`, `arrival`, `departure`,
`price_to_customer` (F9), `price_total`, `price_sto`, `warnings[]`.
- Day dates = the hard-typed date (A17, or a date typed later after a gap) + row offset; a formula's
  saved value is only compared (warning if different).
- Several pax sheets: the one whose name contains **CONF** is read (warning); none or several
  marked → 422 "not finalised".
- Group calcs (RECAP sheet): pass `sheet` = the client's own sheet (`"1PAX Cacioli"`: that client's days,
  guests and flights) or `"RECAP"` (the whole group: every client's guests and flights). Without `sheet` → 422
  listing the client sheets.
- `flight_cost` is the saved FLIGHTS amount of that day (e.g. `=215*$B$1` → 430 for 2 pax).

### `booking_check` (GET)
Read-only, for the Cowork skill `booking-check` (verify a booked safari). `request_id`, optional
`file`, `sheet` (as `read_calc`; a group calc is read on `RECAP` by default). Returns:
- `folder`, `group_folder` (GRP member: the group folder, its evidence is listed too), `local_folder`
  (`C:\Dropbox\…`, where Cowork reads the files and writes `booking-check.html`), `customer`, `status`, `payment`;
- `calc`: as `read_calc` without `days` (file, sheet, pax, room_config, arrival/departure, guests, warnings);
- `services[]` in date order:
  `accommodation` `{name, check_in, check_out, nights, own, calc_invoice[], calc_checked[], rows[]}`
  (consecutive nights in the same hotel; `own` = The Orangi Collection → booked in ResRequest, evidence
  is the "SV" file), `flight` `{date, route, cost, rows}`, `transfer` / `activity` `{date, text, route, rows}`
  (one per ACTIVITY DESC item; in-house items — emergency, medivac, lunch boxes, hot lunch, Maasai visits, Napuro, Eyasi, Olduvai,
  church visit / city tour — skipped; `activity.own` = run by our lodges, e.g. "Natural Walk" at Marera);
- `evidence[]` `{scope own|group, path, name, ext, size, old}`: every file in `invoices/` and `bookings/`
  (any format; `old` = inside an "old…" sub-folder);
- `summary`: services per type, `evidence_files`, `stays_without_invoice_or_checked`.
The INVOICE amount sits on the first row of a block, and one invoice may cover several services
("ARUSHA, MARERA & KIFARU - SV", one transfer mail for all transfers): match by content, not by name.

### Calc checks (also in Confirm preview, UI + API)
`error`: more than one pax sheet · a beach night (≥ MIDT) without hotel · formula cells with no saved
value (Hub would read End = Start). `warn`: H6:I14 not empty · F9 empty · flight cost not in the
rate table / not `=cost*$B$1` · other nights without hotel · guests / country / title / room type missing.
`confirm_booking` blocks on `error` unless `"force": true`; the BackOffice shows them (red ✖) but never blocks.

### `confirm_preview` (POST)
`request_id`, `start`, `end`, optional `mid`, `mid2`, `dest`, `grp` (`NONE`|`CREATE`|`ADD`),
`grp_code` (DDMM), `grp_main`.
Dates: ISO `2027-01-19` or Hub format (`19JAN`; `end` = `28JAN2027`). `dest`: a label from
`list_standard_programs.destinations`, or its suffix (`ZNZ`, `TREK`, `KENYA`…); empty = Tanzania safari.
Returns `new_name`, `checks` (Excel dates vs folder dates, flights, GRP, destination) and `blocking`.
**Destination check** (`warn`, not blocking): the places in the Calc day rows (place names, and
ITI lodges through their destination's country) must fit `dest` — e.g. Masai Mara with a Tanzania
safari, Serengeti with `KENYA`, mainland places with `ZNZ`, no route/hut with `TREK`, only Zanzibar
places with a Tanzania safari. Nairobi / Dar / Kigali / Entebbe / JNB on the first or last day = transit.
If you get this warning, ask the seller which destination is right before you confirm.

### `confirm_booking` (POST)
Same input + `"confirm": true`. Without it → dry-run (= preview). With blocking issues → 409
unless `"force": true`. Moves the folder to `001_Safari`, renames it
`MM_DDMON_<folder>_STARTddMON[_MIDTddMON]_ENDddMONyyyy_PROGRESS`, status → Booked,
snapshot stored for Rollback.
**Lost request** (Roberto, 18 Oct 2026): preview and confirm fail (400, `hint`) unless `"reopen_lost": true` — ask the
seller first. With it, the preview shows `reopen` ("Lost (reason) → Quoted, then confirmed") and the confirm reopens the
request exactly like the status menu (Lost → Quoted, Lost reason/note cleared, timeline) before moving the folder; if the
move then fails the request stays Quoted. Cancelled requests are not confirmed from the BackOffice.

**Booking email at confirm time.** `confirm_preview` and the `confirm_booking` dry run also return
`email` (`to[]`, `cc[]`, `subject`, `body`, `sender_user_id`): the booking-team email as it will be
with the new folder name. Optional `email` object in the body = the edits (same keys as
`send_booking_email` below) + `"send": true` → after a successful confirm the edited email is sent
in the same call (response: `email`, `email_sent`, `email_error` if it failed — the confirm stays
done; retry with `send_booking_email`). Without `send` nothing is mailed. A `send` with no valid To → 400.
```json
{"request_id":2958,"start":"2027-01-19","end":"2027-01-28","confirm":true,
 "email":{"send":true,"cc_remove":["savannah.explorers@gmail.com"],"no_invoice":true,
          "body_extra":"Provisional bookings until 30 Oct for 15 double/twin; room list to follow."}}
```

### `send_booking_email` (POST)
`request_id` (must be Booked). Edits of the template, all optional:
`to[]` / `cc[]` (replace the template lists), `to_add[]` / `cc_add[]`, `to_remove[]` / `cc_remove[]`,
`subject`, `body` (replaces the whole template text; signature and Reply-To are still added),
`body_extra` (inserted before "Thanks"), `no_invoice` (true → drops the "Esther - prepare the not
paid invoice" line, e.g. group options invoiced when the group closes), `sender_name`.
Without `"confirm": true` → returns the email that *would* be sent. No valid To → 400.
Signed with the request agent's name, signature and Reply-To. Shared logic:
`bs_booking_email_edit()` (booking_service.php).

### `rollback_booking` (POST)
`request_id`. Undoes a Hub confirmation exactly like BackOffice → Rollback…: moves the folder
back to where it was before Confirm Safari and restores name, status (never Booked → Inquiry),
payment status and group. Without `"confirm": true` → returns `from`, `to` and `restore` only.
409 if there is no confirm snapshot, the original location already exists, or a GRP still has
other members.

### `rename_folder` (POST)
Free rename of the booking folder — the same as BackOffice → **Rename…** (shared code:
`includes/folder_service.php`, `folder_rename()`). For a group the **group parent** (`group_folder`) is
renamed and every request of the group is updated. No email is sent (unlike Reschedule).
`request_id`*, `new_name`*, `current_path`, `rename_files`, `confirm`.

- **`current_path` (re-link)** — the folder's real Dropbox path when it was moved or renamed **outside the
  Hub** (the stored path no longer exists and its name is not found by search), e.g.
  `/001_Safari/07_27JUL_Lucadesanctis(lasciativiaggiareDaniela)_START27JUL_END03AUG2027_PROGRESS`. The rename
  starts from there. It must exist, be a folder, sit under `/2026`, `/2027` or `/001_Safari` (the inquiry root
  `DROPBOX_BASE_PATH`, the next year, `CK_BASE`), and no other request may point to it (→ 409).
  `new_name` may equal its basename: pure re-link, no Dropbox rename. Equal to the stored path → ignored.
  A re-link whose suffix makes the request **Booked** (it was not) also sets `confirmation_date` = today (if empty)
  and `start_date` from the name — warning "Booked via re-link: no confirm snapshot, Rollback not available".
  The timeline event is "Folder re-linked" with `relinked from <stored path>`.
- **`rename_files: true`** — also renames the files inside (recursive, e.g. `old/`) named `NN_<old>…`
  (`<old>` = the stored name, the folder's current name, or the customer part of either) to
  `NN_<customer part of new_name>…` — e.g. the `_Calc.xlsx` and Word copied by `copy_program`. The customer part
  drops the `MM_DDMON_` prefix and `_START…`: `01_LucaDeSanctis(LasciatiViaggiare-Daniela)_MigrazioneEstate_Calc.xlsx`,
  as the files are named after a Hub confirm. Dry run lists them in `file_renames[{from, to}]`; with confirm →
  `files_renamed[]`, `files_failed{}` (a failed file never undoes the folder rename).
- **Without `"confirm": true` → dry run**, nothing changes. Returns `current`, `new_name`, `is_group`, `relink`,
  `status_from_name` `{matched, status, payment_status, current_status, current_payment_status}` (what the
  new suffix sets: `_DEPOSIT` → Booked/Deposit, `_PAID` → Booked/Paid, `_PROGRESS` → Booked, `_CANCELLED` →
  Cancelled …; no status tag → status untouched; a group sets the status only), `dropbox_path_old` (re-link:
  the stored path), `current_path` (re-link: the real one), `dropbox_path_new`, `warnings[]` and `calc_dates`
  `{start, end}` (the `*_Calc.xlsx` itinerary dates, or null).
- **With `"confirm": true`** → renames the Dropbox folder, updates `practice_code` / `group_folder`,
  `dropbox_url`, `status` / `payment_status` (when the suffix matches), the CK tracker, and logs a
  `status_change` timeline event (on every request of a group). Same fields + `renamed: true`.
- **Errors (nothing changed):** 404 request not found, `current_path` not in Dropbox · 400 no folder, empty name,
  invalid characters (`\ / : * ? " < > |`), identical name (without `current_path`), `current_path` outside the
  allowed roots or a file · 409 folder not found in Dropbox (stored path, then search: the message suggests
  `current_path` and `candidates[]` lists up to 3 folders in `/001_Safari` found by the customer name),
  `current_path` already used by another request, destination folder already exists · 502 Dropbox/DB failure.
- **Warnings (dry run, never blocking):** date tag malformed (`START29OC`), START / MIDT / END not in
  order (e.g. END before START) or spanning > 60 days, `_CK` not last, `MM_DDMON_` prefix ≠ START,
  `_CK` / status tag / START / END dropped from the current name, another request already using the name,
  Calc start/end ≠ the new name's START/END.

```bash
curl -sA "Mozilla/5.0 (compatible; SavannahHubAgent/1.0)" -H "$H" -X POST "$U?action=rename_folder" \
     -d '{"request_id":873,"new_name":"02_10FEB_Rossi(BTG-Roberto)_START10FEB_END16FEB2027_DEPOSIT"}'                  # dry run
curl -sA "Mozilla/5.0 (compatible; SavannahHubAgent/1.0)" -H "$H" -X POST "$U?action=rename_folder" \
     -d '{"request_id":873,"new_name":"02_10FEB_Rossi(BTG-Roberto)_START10FEB_END16FEB2027_DEPOSIT","confirm":true}'
# re-link a folder moved outside the Hub (dry run), fixing its name and the files inside
curl -sA "Mozilla/5.0 (compatible; SavannahHubAgent/1.0)" -H "$H" -X POST "$U?action=rename_folder" \
     -d '{"request_id":2923,"current_path":"/001_Safari/07_27JUL_Lucadesanctis(lasciativiaggiareDaniela)_START27JUL_END03AUG2027_PROGRESS","new_name":"07_27JUL_LucaDeSanctis(LasciatiViaggiare-Daniela)_START27JUL_END03AUG2027_PROGRESS","rename_files":true}'
```
BackOffice → **Rename…**: when the folder is not found, the form reopens with a **Current Dropbox path** field
(and the candidates); "Folder moved outside the Hub?" opens it directly. Checkbox = `rename_files`.

### Il Diamante set departures — `move_group_folder` + `import_group_folder`
Confirming a Diamante group (Panorama01, 05, 07, 10, PanoramaDavide …) = two steps: move the provisional
folder `/itineraries/SafariClassic/it/Agenzia/Diamante/2027-Groups/<MM>_<DDMMM>_PanoramaNN_(Diamante-PS-Roberto)_START…_END…_PROVISIONAL`
to `/001_Safari/` with `_PROVISIONAL` → `_BALANCE`, then import it as a Hub request (status Booked, payment
Balance, pax, value) — the same as Hub → **Import Group Folder** (shared code: `includes/group_import_service.php`).
`create_request` is not used for these groups. One call does both: `move_group_folder` with `import`.

The imported request stores the folder name in both `practice_code` and `group_folder` and
`dropbox_url` = `/001_Safari/<folder>`; later renames (payment tag, `_CK`) keep the three in step.

#### `import_group_folder` (POST)
**Dry-run unless `"confirm": true`.**
- `folder`* — the folder name as it is in `/001_Safari` (name only, no path).
- Optional overrides (default = read from the name by the Hub parser): `customer_name` (PanoramaNN),
  `source` (tour operator, `Diamante`), `agent_id` (handler matched to an agent), `destination` (Tanzania),
  `period`, `start_date` (YYYY-MM-DD), `status` (default **Booked**), `payment_status` (`''`|`Deposit`|`Balance`|
  `Balance-Cash`|`Paid`; default from the suffix, else **Balance**), `pax`, `value_usd`, `notes`
  (default "Handler · Agency code · TO").
- `mode`: `create` (default) | `update` + `target_id` (overwrite an existing request with this folder).
- `allow_duplicate` (default false).

Checks (all in the dry run):
- `blocking` `folder_missing` — the folder is not in `/001_Safari` → 404 on confirm.
- Duplicates (`duplicates[]` `{id, customer_name, status, folder, start_date, period, level, reason}`, ranked):
  `exact` (same folder already imported) → `blocking` `duplicate_exact`, 409 in `create` mode — use `mode: update`;
  `high` (same group + dates with another suffix, or same name + start date) → `duplicate_high`, 409 unless
  `allow_duplicate: true`; `low` (same name) → warning only. In `update` mode the target itself is not a duplicate.
- Invalid values (status, payment status, date, pax / value not numbers, agent or target not found) → 400 `errors[]`.

Dry run → `{dry_run: true, folder, mode, parsed, values (what will be written), duplicates, blocking[{code, message}], warnings}`.
Confirm → `{dry_run: false, request_id, mode, request}` and a timeline event **"Group folder imported"**
(`confirmation`, author the API user; the Hub page logs it too).

```bash
curl -sA "$UA" -H "$H" -X POST "$U?action=import_group_folder" -d '{"folder":"08_10AUG_Panorama10_(Diamante-PS-Roberto)_START10AUG_END17AUG2027_BALANCE","pax":2,"value_usd":7890}'
curl -sA "$UA" -H "$H" -X POST "$U?action=import_group_folder" -d '{"folder":"…","pax":2,"value_usd":7890,"confirm":true}'
curl -sA "$UA" -H "$H" -X POST "$U?action=import_group_folder" -d '{"folder":"…","mode":"update","target_id":3042,"pax":2,"confirm":true}'
```

#### `move_group_folder` (POST)
**Dry-run unless `"confirm": true`.**
- `source`* — the folder name (looked up in `…/Diamante/2027-Groups/`) or its full path under
  `/itineraries/SafariClassic/it/Agenzia/Diamante/`. Must end with `_PROVISIONAL`.
- `new_suffix` — `BALANCE` (default) | `DEPOSIT` | `PAID`: `_PROVISIONAL` at the end is replaced by it.
- `new_name` — the full new name (overrides `new_suffix`; warning if it has no booking tag).
- `import` — object with the `import_group_folder` fields (without `folder`): after the move, imports the
  moved folder in the same call.

Rules: destination = `/001_Safari/<new name>`; source missing → 404, destination already there → 409
(both listed in `blocking` on the dry run). Name checked with the `rename_folder` rules (date tags, prefix =
START, `_CK` last → `warnings`). Dropbox **move** (not copy + delete), returns the new `dropbox_path`.
With `import`, the import is checked **before** moving (as `import_preview`; the folder-exists check runs
after the move): an import that would be refused (400 / 409) means nothing is moved. If the import still fails after the
move, the move stays done: **422** `{ok: false, moved: true, imported: false, import_error, dropbox_path}` — finish
with `import_group_folder` on `new_name`.

Dry run → `{dry_run: true, from, to, new_name, warnings, blocking, import_preview?}`.
Confirm → `{dry_run: false, moved: true, from, to, new_name, dropbox_path, warnings}` + with `import`
`{imported: true, request_id, mode, request}`.

```bash
curl -sA "$UA" -H "$H" -X POST "$U?action=move_group_folder" -d '{"source":"09_14SEP_Panorama14_(Diamante-PS-Roberto)_START14SEP_END21SEP2027_PROVISIONAL","import":{"pax":2,"value_usd":7890}}'   # dry run
curl -sA "$UA" -H "$H" -X POST "$U?action=move_group_folder" -d '{"source":"09_14SEP_Panorama14_…_PROVISIONAL","import":{"pax":2,"value_usd":7890},"confirm":true}'
```

### `iti_programs` (GET)
`q?`, `type?` (`sample`|`personal`), `lead_request_id?` (alias `request_id`: the programs of one Hub request) →
ITI programs: id, title, lead_request_id, language, days, published, public_url.

### `iti_texts` (GET)
`program_id`, `lang` (`en`|`it`|`fr`|`es`|`de`), `all?` → `from` (source language) and `items[]` `{key, source, target}`:
the texts to translate — program title/route/intro, day titles and texts, activity notes, day transfers (`t<id>`), included / not included,
and the descriptions of the program's lodges and destinations. Without `all`, only those still empty in `lang`.

### `iti_save_texts` (POST)
`program_id`, `lang`, `texts` `{"<key>": "<translation>", …}`, `overwrite?` (default false: only empty fields are written,
so edited translations are kept). Dry-run unless `"confirm": true`. Lets Claude translate programs in a session
(no Anthropic API billing); the Hub "Translate" button does the same through the API when `ANTHROPIC_API_KEY` is set.

### ITI personal programs (Cowork)
Code: `modules/iti/includes/iti_program_service.php` (+ `iti_final.php` for the Calc). Only **personal**
programs are changed; samples stay on the Hub pages. Every response with a program carries `links`:
`preview` (internal, magazine), `edit`, `word` (editable .docx for agencies, Hub login), `public` (client link,
magazine layout). A personal program's public link is always on — no publishing step; the token is made the
first time it is needed — until the program is cancelled. The PDF and the Word carry it too.

Typical flows:
- **Proposal:** `iti_samples` → `iti_create_personal` (sample + client data) → `iti_update_program` / `iti_update_day`
  for the client's changes → send `links.public`.
- **Final (after booking):** `iti_calc_plan` (reads the request's `*_Calc.xlsx` from Dropbox) → if `unmapped`,
  `iti_save_alias` for each text → `iti_final_from_calc` with confirm → `links.public`.

**Ref. number and file names (rule).** A client programme is named like the programme Word that `copy_program`
put in the request folder — same progressive number and prefix as its Calc:
Calc `01_MarcoCiaolo(OceanoPoint-Roberto)_Pumba_Calc.xlsx`, Word `01_MarcoCiaolo(OceanoPoint-Roberto)_PumbaSafari.docx`
→ `ref_number` = `01_MarcoCiaolo(OceanoPoint-Roberto)_PumbaSafari`, and every programme file the API saves in the booking
folder is `<ref_number>.docx` / `<ref_number>.pdf` / `<ref_number>_Guida.pdf`. `ref_number`: max 120 characters (the column; was 60 until Oct 2026),
no `\ / : * ? " < > |`. `iti_create_personal` sets it from the folder; `iti_update_program` changes it.

#### `iti_samples` (GET)
`q?` → `samples[]` `{id, code (Calc code), title, route, language, days}`.

#### `iti_program` (GET)
`program_id`, `lang?` → `ref_number`, header (title, subtitle, intro, start_date, pax, prices, included / excluded), `days[]`
(`day, date, title, destination, lodge, meals, transfers, activities, narrative, lodge_photos, dest_photo`), `terms` and `links`
(`preview`, `edit`, `word`, `pdf`, `guide` (personal only, else null), `public` — `word` / `pdf` / `guide` need a Hub login).
`terms` = the T&C the documents print: `{variant (direct | agency | custom), terms_id, name, source (program = chosen on the
program | request = from the linked request | default), request_variant}`.
`missing_texts[]` `{type (activity | lodge | destination), id, name, field (name_<lang> | description_<lang>), days[]}` = the
catalogue texts that do not exist in the program language (`lang`, else the program's): the documents leave them out (see
**Texts in the program language** below). Check it before `iti_document` and fill them with `iti_update_activity` /
`iti_update_lodge` / `iti_update_destination`.

**Texts in the program language (15 Oct 2026).** In every document (magazine web page, PDF, Word, guide sheet) the name and
description of an activity, lodge or destination are printed only in the program language — never English or another
language. Missing (or only the website blurb) → that box is not printed: an activity without its name in the language
(and no day note in that language) is left out of the day's list, a destination without its name has no description box, a
lodge without its description shows name, photos and contacts only. The program's own texts (title, intro, day titles and
narratives, included / excluded, T&C) keep the language fallback.

**T&C per client type:** direct clients (request folder `Name(Agent-Drct)`) get the standard version named DIRECT —
balance and cancellation penalties at **60 days**; agencies the version named AGENTS — **45 days**. Set on create, when the
linked request changes (Hub editor) and on `iti_final_from_calc`; a program with its own dedicated T&C is never changed.
With no T&C chosen, the documents use the linked request's version.

#### `iti_document` (GET)
The program as a file in the magazine layout (cover, route map, stays, day by day with photos, prices,
included / excluded, contacts, terms). `program_id`, `format?` (`pdf` default, rendered on the server with Dompdf;
`docx` = editable Word for agencies; `guide` = PDF for the safari guide: transfer / flight and accommodation
recap with check-in / check-out, then the day by day — no photos, intro, prices, lodge descriptions or terms,
file `…_GUIDE_<LANG>.pdf`; **personal programs only**, a sample → 400), `lang?` (default: the program's language, the guide sheet included).
→ `file {program_id, name, mime, size, format, lang, content_base64}` (`format` is the file type: `guide` → `pdf`). Decode and save it as `file.name`.
To put it in Dropbox instead: `save: true` (the program's `lead_request_id` folder), or `request_id` / `folder_path`;
`save_as?` (file name), `overwrite?` (an existing file is kept unless true; Dropbox keeps the old version)
→ `file` (no content), `saved_to`, `overwritten`. The audit log omits the file content.
**File name** (`file.name` and the saved file) = the `ref_number`: `<ref>.docx` (docx), `<ref>.pdf` (pdf),
`<ref>_Guida.pdf` (guide); `save_as` overrides it. A program without `ref_number` keeps the old name
(`<Title>_<LANG>.docx`) and the reply has `file.warning` — set it with `iti_update_program` first.
The Word copied by `copy_program` has the same name as the `docx`: the first save → **409** (expected); ask Roberto
before resending with `"overwrite": true`.
Photos make files of a few MB; generating can take up to a minute.
```bash
curl -sH "$H" "$U?action=iti_document&program_id=412&format=pdf" | jq -r .file.content_base64 | base64 -d > prog.pdf
curl -sH "$H" "$U?action=iti_document&program_id=412&format=docx&lang=en&save=1"      # → booking folder, <ref_number>.docx
curl -sH "$H" "$U?action=iti_document&program_id=412&format=guide&save=1"            # guide sheet → booking folder
```

#### `iti_create_personal` (POST)
`sample_id`, `lead_request_id?` (Hub request), `calc_file?`, `fields?` — any of `ref_number`, `title_<lang>`, `subtitle_<lang>`, `intro_<lang>`,
`start_date` (YYYY-MM-DD: dates appear on the cover and on each day), `pax_adults`, `pax_teens` (under 16), `pax_children` (under 12),
`display_language`, `display_currency`, `safari_type` (`private` | `group`), `price_table_json` (`[{label, price, currency, bold?}]`; `bold: true` prints the row in bold — a row starting "Totale pratica" / "Total booking" is bold unless `bold: false`), `price_notes_<lang>`.
Copies the sample (days, activities, prices, inclusions) as a draft proposal, with the T&C of the request's client type
(`fields.terms_variant` = `direct` | `agency` forces one). Dry-run unless `"confirm": true` (→ plan with `terms_variant`,
`ref_number` and `ref_source`); confirmed → `program_id`, `links`, `terms` and the full `program`.
**ref_number:** `fields.ref_number` wins. Otherwise, with `lead_request_id`, it is the name (no extension) of the programme
Word `copy_program` put in the request folder (`NN_<folder>_<Prog>Safari.docx`) when there is exactly one; `calc_file`
(e.g. `01_…_Pumba_Calc.xlsx`) picks the Word of that Calc (same `NN_` and programme). None or several → **409**, nothing
created, with `word_candidates`, `calc_files`, `folder` and `hint`: resend with `fields.ref_number` or `calc_file` — never guess.
**safari_type:** `private` ("Safari privato in Tanzania" / "Safari privato con guida" on the cover and facts line of web,
PDF and Word) or `group` ("Safari di gruppo in Tanzania" / "Safari di gruppo con guida"; 5 languages; the country follows the
programme's `country` — see **Programmes outside Tanzania**). `fields.safari_type` wins;
otherwise `group` when the sample is a group safari or `GRP` is in the ref_number, the Calc file or the request folder
(`_DumaGRP`, `_GRP0301`, `GRP1508Simba`), else `private`. Same rule for `iti_final_from_calc` and the Hub's copy buttons;
the plan shows it. `iti_program` returns `safari_type`; change it with `iti_update_program`.

#### `iti_update_program` (POST)
`program_id`, `fields` (same list — `ref_number` included, see the rule above — plus `terms_variant` = `direct` | `agency` | `auto` (from the linked request)) → `changes`
(a T&C change shows as `terms_id` with `variant_from` / `variant_to`). Dry-run unless confirm. Refused when the program has
its own dedicated T&C (edit those in the Hub).

#### `iti_update_day` (POST)
`program_id`, `day` (number), `fields`: `day_title_<lang>`, `narrative_<lang>`, `end_lodge_id` **or** `end_lodge`
(name, must match one lodge), `end_lodge_custom`, `room_type`, `destination_id`, `destination_custom`, `meal_breakfast` /
`meal_lunch` / `meal_dinner` (0/1) → `changes`. Dry-run unless confirm.

#### Day items (used by `iti_create_personal` `days[]`, `iti_set_days`, `iti_add_day`, `iti_update_day`)
Every key optional: `day_title_<lang>`, `narrative_<lang>`, `end_lodge_id` | `end_lodge` (name), `end_lodge_custom`,
`room_type` (free text in the program language, e.g. "1 camera matrimoniale + 1 tripla"; shown next to the lodge, ignored with no overnight), `destination_id` | `destination` (name or code), `destination_custom`, `start_lodge_id`, `start_destination_id`,
`start_custom`, `transfer_route_id`, `transfer_custom`, `meal_breakfast` / `meal_lunch` / `meal_dinner` /
`meal_all_inclusive` (0/1), and three lists — each one given **replaces** the day's list (`[]` = none):
- `transfers`: `["Dar airport – Serena Hotel, about 40 min", …]` (text shown on the day, program language) or
  `[{description, text_<lang>?: "<translation>"}]`; a transfer whose text is unchanged keeps its translations;
- `activities`: `[{activity_id} | {activity: "<name>"} | {custom: "<text>", text_<lang>?: "<translation>"}]` (see `iti_activities`; a catalogue activity shows its own name);
  a free-text activity (`custom`, stored as `activity_custom` + `custom_note_<lang>`, e.g. "Fotosafari" / "Game drive" in
  samples imported from WeTu) shows its `text_<lang>`, else the custom text. The Hub editor lists these too and keeps their
  translations when a day is saved (before Oct 2026 it did not show them, and saving the day deleted them);
- `flights`: `[{flight_route_id | custom, airline?, flight_no?: "UI 403", dep?: "07:40", arr?: "10:05", note_<lang>?}]` (see `iti_flight_routes`).
  `flight_no` and the times go on the flight voucher and on the transfer to / from the airport that day.
`iti_program` returns `structure[]`: the stored days in this same shape (with ids), to edit and send back.
Flights are shown on the day (magazine, Word) as "✈ Dar Es Salaam → Ruaha · Auric Air · 07:40–10:05".

#### `iti_create_personal` without a sample
No `sample_id` = blank program for a trip with no matching sample: `fields.title_<display_language>` and `days[]`
are required. With a `sample_id`, `days[]` (optional) replaces the sample's days.

#### `iti_set_days` (POST)
`program_id`, `days[]` (ordered day items) → replaces all the days (and their transfers / activities / flights) in one
transaction; `duration_days` follows. Dry-run (counts, validation errors) unless `"confirm": true`.

#### `iti_add_day` / `iti_delete_day` (POST)
`program_id`, `after_day` (0 = at the start) + `fields` (day item) / `day` → the following days are renumbered.
Dry-run unless confirm.

#### `iti_inclusions` (GET) / `iti_update_inclusions` (POST)
`iti_inclusions` `country?` (`TZ` | `KE` | `TZ+KE`) → the standard rows `{std_id, country, text_<lang>}` by `included` / `excluded`;
with `country`, only that country's rows and the `ALL` ones (a Kenya programme never gets NIC / the Tanzania visa; TZ+KE gets both).
`iti_standard_inclusions.country`: `TZ` (every row before Oct 2026), `KE` (eTA, health insurance USD 44 — seeded), `ALL` (set by hand for generic items).
`iti_update_inclusions`: `program_id`, `included[]` and / or `excluded[]` — items `{std_id}`, `{text_<lang>…}` or a
plain string (program language). Each list given replaces that list in one transaction; an empty list is refused.

#### Master data: `iti_flight_routes`, `iti_activities`, `iti_transfer_routes` (GET) and `iti_create_…` (POST)
- `iti_flight_routes` `q? from? to?` (airport name or code); `iti_create_flight_route` `fields`: `from_airport`*,
  `to_airport`*, `from_code`, `to_code`, `operator`, `flight_type` (`scheduled`|`charter`), `duration_min`, `notes_<lang>`.
- `iti_activities` (list with all 5 languages, see **ITI master data — activities**); `iti_create_activity` `fields`:
  `name_en` / `name_it`*, `name_<lang>`, `activity_type`
  (`game_drive`|`walking_safari`|`cultural`|`boat`|`balloon`|`hiking`|`beach`|`other`), `destination_id` | `destination`,
  `description_<lang>`, `duration_hours`. A language not given stays empty (no copy of another language, since 15 Oct 2026).
- `iti_transfer_routes` `from? to?`; `iti_create_transfer_route` `fields`: `from_destination`*, `to_destination`*
  (id, name or code), `duration_min`, `distance_km`, `road_type` (`tarmac`|`gravel`|`mixed`), `notes_<lang>`.
Duplicates are refused with the existing id. Dry-run unless confirm.

#### Programmes outside Tanzania — country (Oct 2026)
`iti_programs.country` (`TZ`, `KE`, mixed `TZ+KE`; `fields.country` in `iti_create_personal` / `iti_update_program`, `null` = from the
destinations): empty → the countries of the programme's destinations (`iti_destinations.country`, the name: "Kenya"), else Tanzania.
It sets the cover kicker ("Safari privato in Kenya", "Safari privé en Tanzanie et au Kenya") and the standard included / excluded
of an import. `iti_program` returns `country` and `source` (`{supplier, file}` of an imported programme — internal, never printed).
T&C and the contacts on the documents are always Savannah's (office + emergencies), whatever the country.
Columns / table added lazily on first use (`iti_ensure_country_schema()`, no migration to run).

#### `iti_create_destination` (POST)
`fields`: `name_en`*, `country`* (`KE` / `TZ` / … or the name), `name_<lang>` (the documents print the destination only in
the programme language: send `name_it` too), `code` (3–4 letters, unique; made from the name when missing: Amboseli → AMB,
Tsavo West → TSW), `type` (`park` | `reserve` | `lake` | `city` | `beach` | `airport` | `other`), `region` (default from the
type: park / reserve → National Parks, airport → Airports, else the country), `latitude`, `longitude`, `description_<lang>`.
Same name (EN / IT) or code → **400** with `existing` (the row). Dry-run (`would_create`, `missing_names`) unless confirm → `created`.
Cover photo afterwards with `iti_destination_photo`. Airports / airstrips stay separate rows (flights and transfers only).
`iti_destinations` takes `country?` (`KE` or the name) and `missing=stub`; rows show `country`, `type`, `needs_completion`.

#### `iti_import_program` (POST) — a partner's programme (text) → a Savannah personal programme
The text (Word / PDF / email) is parsed by Claude (Cowork now; a Hub page with the Anthropic API later — same code path,
`modules/iti/includes/iti_import_service.php`), never by PHP: the input is already structured.
```json
{ "lead_request_id": 3138, "country": "KE", "display_language": "it", "create_missing": true,
  "fields": { "title_it": "Kenya Classic – Gruppo Bramardi", "title_en": "Kenya Classic", "pax_adults": 15,
              "price_table_json": [...], "price_notes_it": "...", "ref_suffix": "KenyaClassic" },
  "source": { "supplier": "BTG", "file": "BTG_Kenya_7N8D_....pdf" },
  "days": [ { "day_title_en": "Nairobi – Amboseli", "day_title_it": "Nairobi – Amboseli",
              "narrative_en": "...", "narrative_it": "...", "destination": "Amboseli National Park",
              "end_lodge": "Kibo Safari Camp", "meals": "B,L,D",
              "activities": [{"custom": "Game drive", "text_it": "Fotosafari"}],
              "transfers": ["Nairobi – Amboseli, circa 4 ore"] } ],
  "included": [{"text_en": "...", "text_it": "..."}], "excluded": [{"text_en": "...", "text_it": "..."}] }
```
- **Days:** the day items of `iti_set_days` (both languages stored: `_en` and `_<display_language>`) plus `destination` /
  `end_lodge` as text (or `destination_id` / `end_lodge_id` to force a row), `meals` (`"B,L,D"`, `"(B,L,D)"`, `BB`, `HB`, `FB`, `AI`;
  `meal_*` keys win). For new rows: `destination_type`, `destination_country` (required for a new destination of a mixed
  programme), `destination_name_<lang>`, `end_lodge_type`, `end_lodge_category` (default from the name / `mid`).
- **Name matching** (only rows of the programme's countries; airports never): exact 100 → same without the generic words
  (Camp / Lodge / Resort / Hotel / & / Spa; National Park / Reserve …) 95 → one contained in the other 85 → similarity %; ≥ 80 and
  not tied = matched. `matches[]` `{kind, text, matched_id, matched_name, score, candidates[]}`.
- **Not found:** with `"create_missing": true` stubs are created (`needs_completion = 1`; a lodge's area = the day's
  destination; a name that exists in another country or inactive → `conflict`, pass the id). Without it, `to_create[]` lists them
  and a confirm stores them as free text on the day.
- **Included / not included:** the lists given, plus the country's standard items not already there (`"standard": false` = only the given).
- **ref_number:** `fields.ref_number`; else the request folder's programme Word (as `iti_create_personal`); with none there
  `NN_<folder>_<ref_suffix or title before " – ">` (`01_Kenya2027(BramardiViaggi-Roberto)_KenyaClassic`). Several Words → 409.
- **Supplier guard:** any text (all but `source`) containing a blocklisted term or `source.supplier` → **422** `supplier_hits[]`
  `{field, term, supplier}`, nothing saved. Same guard on `iti_update_program`, `iti_update_day`, `iti_set_days`, `iti_add_day`,
  `iti_update_inclusions`, `iti_save_texts` and `iti_document` (on the printed texts) for programmes with a `source.supplier`.
  (Edits on the Hub page `program_edit.php` are not checked.)
- **Dry run** (default) → `country`, `title`, `ref_number` / `ref_source`, `safari_type`, `terms_variant`, `days[]` `{day, title,
  destination {state: matched|new|text, id, name}, lodge {…}, meals}`, `matches[]`, `to_create[]`, `included[]`, `excluded[]`,
  `warnings[]` (day without destination / lodge, last day with a lodge, meals not given / no dinner / lunch on the last day,
  `nights` ≠ days − 1, `_<lang>` texts missing or looking English, Tanzania items on a non-TZ programme, Kenya without eTA),
  `photo_gaps[]`, `text_gaps[]` (lodges / destinations without photos or without text in the programme language).
- **Confirm** → stubs, programme (blank, `stage` proposal, T&C of the request's channel), days and lists in **one transaction**
  → `program_id`, `created {destinations[], lodges[]}`, `links`, `program`.

#### `iti_supplier_blocklist` (GET / POST)
GET → `blocklist` `{"BTG": ["BTG", "Bridge The Gap", "btgltd.com"], …}`. POST `supplier`, `add[]`, `remove[]` (names, brands,
domains, e-mails, phone numbers — matched as whole words, any case; phones by their last 9 digits). Dry-run unless confirm.
Also editable in the Hub: ITI → Settings → Partner names.

#### `iti_save_as_sample` (POST)
`program_id` (a finished personal program), `title` or `title_<lang>` (required), `code?` (Calc code) → a new sample:
same days / lists, client data removed (dates, pax, prices, request, publication). The personal program is not changed.
Dry-run unless confirm.

#### `iti_publish` (POST)
`program_id`, `publish?` (default true; false = unpublish) → `links.public`. Dry-run unless confirm.
Only needed for samples: a personal program's link is on without it (unpublish does not turn it off; cancel does).

#### `iti_calc_plan` (GET) / `iti_final_from_calc` (POST)
`request_id`, `sample_id?` (default: the sample whose Calc code matches the file name), `lang?`, `file?`, `sheet?`.
Plan: `calc` (file, files = every Calc of the folder, rev, pax), `sample`, `nights[]` (date, label, hotel text → lodge, state, meal, activities,
from_sample_day, flags), `unmapped` {lodge|activity|route: {norm: text}}, `blocking`, `existing_finals` (id, calc_file,
superseded_by). Several Calc files (e.g. the same safari with other lodges) → pass `file`; each file has its own final.
`iti_final_from_calc` with `"confirm": true` generates the final program (an older final built from the same Calc
file is superseded) → `program_id`, `program`. 409 when the Calc cannot be read.
How the plan and the final are built (`modules/iti/includes/iti_final.php`, same on the Hub page "Create Itinerary Program
from Excel Calc"):
- **Sample proposal** (no sample tagged with the Calc code yet): each sample is scored on its itinerary (Calc nights aligned
  with the sample's days: same lodge, same destination or a lodge within 30 km), the code words in the title
  ("MigrazioneEstate" → migrazione), the season in the title ("LUG-SET" fits a July start, "DIC-MAR" does not), days and
  language. The Hub shows the reason next to the ★.
- **Sample lodges kept only as text** ("Mara Pure Migration (Jul - Oct)") are read through the lodge aliases, else the closest
  lodge name, so the day still matches; the plan flags them ("link the lodge in the sample").
- **Migration camps, season rule:** a camp is a lodge of type `mobile_camp` or one with a season twin (`season_twin_id`, see
  Lodges). Open months: the camp's own (`season_months`, ticked on the lodge page — e.g. Untamed, which also opens in the
  central Serengeti April–June and November: all year), else by area: north (Mara / Kogatende) June–October, Ndutu
  December–March, a camp that moves itself all but April, May, November. A night in a month the camp is closed goes to
  its twin when the twin is open: "Pure Migration" mapped once → Mara Pure Migration Camp in July, Ndutu Pure Migration
  Camp in January, with a "Season rule" flag; otherwise the night is flagged "closed in <month>". The lodge suggestion for an unmapped hotel text follows the same rule.
- **Activities:** a Calc activity of the same kind as one already on the sample day (a common word of 6+ letters, e.g.
  "camminata"; game drives excluded) replaces it instead of being added next to it.
- **Prices:** the price table is the price to the customer from the Calc, per person for each pax type of the Calc —
  adult (F9 "Price to customer"), teenager ("Teenager price", H7), child ("Child price", H8) — then the total (F10
  "Tot price", bold: "Totale pratica (2 adulti, 1 ragazzo, 1 bambino)"), labels in the program language.
- **Added days** (not in the sample): another night in the same lodge gets no transfer and "Full day game drive" (beach:
  free day). Guest rows "TBA" / "waiting" / "?" are not saved as names. The title drops the sample's season / rate tags
  ("Lug-Set", "Inverno STO Dic2026-Mar2027") and ends with the first named guest of the Calc.

The new final's `ref_number` (`iti_final_ref_number()`): the ref of the final it replaces (kept when it was set by hand), else
the programme Word copied next to that Calc, else the Calc's name without `_Calc` (`01_Name(Agency-Agent)_Code`).

#### `iti_save_alias` (POST)
`type` (`lodge`|`activity`|`route`), `text` (the Calc text), `lodge_id` (+ `meal_basis` BB|HB|FB|AI) |
`activity_id` | `transfer_route_id` / `flight_route_id`. A lodge alias without `lodge_id` = own arrangement
(shown as free text). Dry-run unless confirm.

### ITI master data — lodges and destinations
Code: `modules/iti/includes/iti_content_service.php` (+ `iti_photos.php`, shared with the Lodges / Destinations pages).
Photos are what the magazine layout (`&layout=mag`) shows: lodge card and stays (lodge, first photo = main),
day header and program cover (destination).

#### `iti_lodges` (GET)
`q?` (lodge / destination name), `destination?` (id, name or code), `active?` (`1` default, `0`, `all`),
`missing?` (`photos` | `coords` | `website` | `description_<lang>` | `placeholder` | `stub` = made by `iti_import_program`, to complete), `limit?` ≤ 300 →
`lodges[]` `{id, name, destination_id, destination, category, type, website, latitude, longitude, photos[], description_langs[], active, needs_completion}`.

#### `iti_lodge` (GET)
`lodge_id` → the same plus `phone, emergency_phone, email, address, description_<lang>` (all 5).

#### `iti_destinations` / `iti_destination` (GET)
`q?`, `active?`, `missing?` (`photo` | `coords` | `description_<lang>` | `placeholder`) → `destinations[]`
`{id, code, name, region, latitude, longitude, cover_photo, lodges, description_langs[], active}`;
`iti_destination` (`destination_id`) adds `name_<lang>` and `description_<lang>`.

`missing=description_<lang>` also lists texts that are only the website blurb ("Savannah Explorers - Tour Operator for Safari
in Tanzania…"); `missing=placeholder` lists rows with that blurb in any language. The documents never print it: the box is
left out (no other language — see **Texts in the program language** under `iti_program`).

#### `iti_lodge_photos` (POST)
`lodge_id` + one of: `photos` [ordered final list, ≤ 12, first = main] | `add` [appended] ; `remove?` [];
`download?` (default **true**: web links are downloaded, checked as images, shrunk to 2000 px and stored in
`uploads/lodges/`; `false` keeps them as links). Photos already on the lodge stay as they are.
`uploads?` [{`name`, `content_base64`}] sends our own photo files (no web link), appended after the links
(base64 omitted from the audit log). Keep a body under ~8 MB: send a few files per call, then `add`/`uploads` again.
Dry-run (returns `plan[]` `{url, action: keep|download|link}` and `remove[]`) unless `"confirm": true`.
With confirm → `photos[]` (final, local URLs) and `errors[]` (links that could not be downloaded; 422 if any).
Removed photos stored in `uploads/` are deleted. Only public http(s) hosts are fetched (no private addresses),
≤ 15 MB, ≤ 3 redirects, 25 s each.

#### `iti_destination_photo` (POST)
`destination_id`, `photo` (one link) — or `remove: [<current>]` to clear. Same download and dry-run rules;
the new photo replaces the old cover.

#### `iti_create_lodge` (POST)
`fields`: `name`*, `destination_id`*, `category` (`budget`|`mid`|`luxury`|`ultra_luxury`), `lodge_type`
(`lodge`|`tented_camp`|`hotel`|`mobile_camp`|`house`) + any field of `iti_update_lodge`. A name that already exists
(exact or contained) → 400 with the existing lodge. Dry-run unless `"confirm": true`; then `created` (the lodge).
Photos afterwards with `iti_lodge_photos`.

#### `iti_update_lodge` / `iti_update_destination` (POST)
`lodge_id` / `destination_id`, `fields` `{…}`. Lodge: `website, phone, emergency_phone, email, address,
description_<lang>, latitude, longitude, destination_id, category` (`budget|mid|luxury|ultra_luxury`), `lodge_type`
(`lodge|tented_camp|hotel|mobile_camp|house`). Destination: `name_<lang>, description_<lang>, region, latitude, longitude`.
Both: `needs_completion` (0 once an imported stub has its photos and texts).
Returns `changes` `{field: {from, to}}` (only what differs). Dry-run unless `"confirm": true`.
A lodge's name stays on the Lodges page — and so does its **season twin** (`season_twin_id`, "Same camp in the other
season": a migration camp's counterpart in the other area, e.g. Tanzania Bush Camps Ndutu Mobile Camp ↔ Mara River
Camp; set on one camp, both are updated; shown as "⇄ …" in the Lodges list) and its **open months** (`season_months`).
Not editable through the API; see the season
rule under `iti_final_from_calc`. Its area (the "Parco Nazionale del Serengeti" under the name in the documents)
is its `destination_id`: use the park / area, not the airstrip (`… Airstrip` destinations are for flights and transfers).

### ITI master data — activities
Code: `modules/iti/includes/iti_content_service.php` (same as lodges / destinations; Hub page ITI → Activities). The documents
print an activity's `name_<lang>` in the program language only (descriptions are not printed today).

#### `iti_activities` (GET)
`q?` (any name), `destination?` (id, name or code), `active?` (`1` default, `0`, `all`),
`missing?` (`name_<lang>` | `description_<lang>` — empty; a description holding only the website blurb counts as missing) →
`activities[]` `{id, activity_type, destination_id, destination, duration_hours, active, name_en, name_it, name_fr, name_es,
name_de, description_en, description_it, description_fr, description_es, description_de, name_langs[], description_langs[]}`.

#### `iti_activity` (GET)
`activity_id` → `activity` (the same shape, one row).

#### `iti_update_activity` (POST)
`activity_id`, `fields` `{…}`: `name_<lang>`, `description_<lang>` (`""` clears; `name_en` cannot be empty), `activity_type`
(`game_drive|walking_safari|cultural|boat|balloon|hiking|beach|other`), `destination_id` (`null` = generic, valid everywhere),
`duration_hours` (number or `null`). Returns `changes` `{field: {from, to}}` (only what differs). Dry-run unless
`"confirm": true`; then also `activity` (the saved row). Audited like the other `iti_update_*`.

```bash
curl -sA "$UA" -H "$H" "$U?action=iti_activities&missing=name_fr"
curl -sA "$UA" -H "$H" "$U?action=iti_activity&activity_id=4"
curl -sA "$UA" -H "$H" -X POST "$U?action=iti_update_activity" -d '{"activity_id":4,"fields":{"name_fr":"Visite du village de Mto wa Mbu","name_de":"Besuch des Dorfes Mto wa Mbu"}}'   # dry run
```

## Invoices

Same logic as the invoice page (`modules/invoices/invoice_view.php`), shared through
`modules/invoices/includes/invoice_service.php`. Every invoice action takes `invoice_id`
**or** `invoice_number` (e.g. `SE-2026-0012`); the audit row carries the invoice's `request_id`.

### `find_invoices` (GET)
`q` (invoice number / bill-to / customer / folder substring), `request_id`, `status`
(`New`|`Partially Paid`|`Fully Paid`|`Cancelled`), `unpaid=1` (balance > 0, not cancelled), `limit` ≤ 100.
At least one filter. → `invoices[]` `{id, number, issuer, bill_to, currency, issue_date, due_date,
total, paid, balance, status, request_id, folder, follow_up}`.

### `get_invoice` (GET)
→ `invoice` (+ address, terms, notes, T&C, created/updated), `items[]`, `payments[]`
(`{id, date, amount, method, reference, notes, cancelled, cancelled_at, cancellation_reason}`, cancelled
ones included), `credit_notes[]`, `folder` (`{request_id, folder, current_tag, payment_status,
group_folder, dropbox_path}` or null), `pdf_name`, `methods`, `folder_statuses`.

### `add_invoice_payment` (POST)
`date` (YYYY-MM-DD, default today), `amount`* (> 0), `method` (`Bank Transfer`|`Credit Card`|`Cash`|`Other`,
default Bank Transfer), `reference`, `notes`. Recalculates the invoice (status New → Partially / Fully Paid)
and the request value (`recalculate_invoice` + `sync_request_value`).
- **409 duplicate** when an active payment has the same amount and the same reference (no reference:
  the same date) — resend with `"allow_duplicate": true` only if it is really a second payment.
- **409** when the amount is more than the balance due — `"allow_overpayment": true` to accept it.
- **409** on a Cancelled invoice.
Returns `payment_id`, the updated `invoice` and `folder` (to decide the next `update_folder_status`).
The folder is **not** renamed automatically.

### `cancel_invoice_payment` (POST)
`payment_id`*, `reason`*. The payment is kept, marked cancelled, and the invoice recalculated.
Without `"confirm": true` → returns the payment and `balance_after` only.

### `update_folder_status` (POST)
`status`* = `PROGRESS`|`PROVISIONAL`|`DEPOSIT`|`BALANCE`|`BALANCE-CASH`|`FULLY PAID` (`PAID` accepted).
Renames the booking's Dropbox folder tag (`_CK` stays last), sets `requests.payment_status`
(DEPOSIT → Deposit, BALANCE → Balance, BALANCE-CASH → Balance-Cash, PAID → Paid), and re-tags the GRP
parent folder. Without `"confirm": true` → `current` and `new_name` only. Same tag already → `unchanged`.

### `save_invoice_pdf` (POST)
Renders the PDF server-side (Dompdf) with the same layout as the invoice page's Print / Save as PDF, the
emailed invoice and the ZIP export — one template, `modules/invoices/includes/invoice_html.php` — and uploads it as
`Invoice <number>.pdf` (the name *Invoice Check — Dropbox* looks for) into the booking folder
(GRP client: the client sub-folder). `overwrite` (default false: 409 if the file exists; Dropbox keeps
the old version when overwritten), `folder_path` (optional full Dropbox path, overrides the folder).
Returns `path`, `size`, `rev`, `overwritten`.

### `send_invoice_email` (POST)
Same as **Send by email** on the invoice page (shared `inv_mail_defaults` / `inv_send_email` in
`modules/invoices/includes/invoice_service.php`): the invoice PDF (same Dompdf render as `save_invoice_pdf`)
attached as `Invoice_<number>.pdf`, sent with BlueHost `mail()` From `noreply@savannahexplorers.com`,
Reply-To `accountant@savannahexplorers.com` (not from info@ — no copy in a Sent folder).
`invoice_id`* or `invoice_number`*. Optional, each falls back to the page's default:
- `to` (string `"a@x, b@y"` or list) — default the linked request's email; 400 when there is none.
- `cc` — default `accountant@` + the **sales of the request** (agent → users.email), unless already in To.
  `"cc": ""` / `[]` = no Cc. `"cc_sales": false` keeps the default Cc without the sales.
- `subject` — default `<Bill to> trip with Savannah Explorers`; `body` — default Esther's receipt text
  ("…your money was successfully received…"): pass a different body for an invoice not yet paid.

Invalid addresses are dropped; a Cc already in To is not repeated. Dry-run unless `"confirm": true` →
`email {to, cc, subject, body, attachment, from, reply_to}`, `invoice`, `sales {name, email}|null`,
`warnings[]` (invoice Cancelled; sales not in To / Cc). After sending, the request timeline gets a `mail_sent`
event "Invoice <number> sent by email" (`timeline_event_id`) — also when Esther sends from the page.

### `import_zoho_invoice` (POST)
Brings an old Zoho invoice into Hub with its **original number** (Claude reads the Zoho PDF and sends the
fields): `invoice_number`* (e.g. `INV-002417`), `bill_to_name`*, `issue_date`*, `items[]`*
`{description, quantity, unit_price, line_total?}`, `issuer` (Savannah Explorers Ltd), `currency` (USD|EUR),
`request_id`, `bill_to_address`, `due_date`, `terms`, `notes`, `terms_conditions`, `total` (checked against the
items: 422 if different), `payment_amount`, `payment_date`, `payment_method`, `payment_reference`.
409 if the number already exists. Without `"confirm": true` → `would_create` summary only.
(The web importer `modules/invoices/api_import.php` uses the same code.)

### `create_invoice` (POST)
Same as **New Invoice** (`invoice_add.php`, shared `inv_prepare` / `inv_create`): number `SE-YYYY-NNNN`
(Savannah Explorers Ltd) or `SH-YYYY-NNNN` (Savannah Holidays Ltd — paid on AfrAsia), status New.
Fields (top level or in `fields`): `items[]`* `{description, quantity (default 1), unit_price}` (negative
price = discount line; the first line is the trip line "<Customer> N pax trip in …"), `issuer` (`SE`|`SH` or the
full name, default SE), `currency` (USD|EUR, default USD), `request_id`, `bill_to_name`* — or `agency_id`
(name, address and the agency T&C, 45 days) / `customer_id` (name, address) —, `bill_to_address`,
`issue_date` (default today), `due_date`, `terms` (default Due on Receipt), `notes`, `terms_conditions`
(default 60-day T&C), `follow_up`, `follow_up_note`, `total?` (checked against the items: 422 if different).
- With `request_id` the lines are checked against the booking's **Calc Excel** like the page: `failed`
  keys `pax` (TOT PAX = trip line qty and "N pax"), `total` (Tot price, USD only), `excel` (Calc not read).
  A failed check blocks the create (409) unless its key is in `ignore_checks` (`["pax"]`, or `true` = all);
  `calc_sheet` picks the sheet when several fit. Ignored checks are logged on the invoice, as on the page.
- **409 duplicate** when the request already has an invoice (not cancelled) with the same total and currency —
  `"allow_duplicate": true` for a real second invoice.
Dry-run unless `"confirm": true` → `would_create` + `calc_check`. Then → `invoice`, `items`, `calc_check`, `folder`.
The PDF is not saved: follow with `save_invoice_pdf`.

### `update_invoice` (POST)
Same as **Edit Invoice**. `invoice_id` / `invoice_number`, then only the fields to change (same names as
`create_invoice`, top level or in `fields`; `request_id: 0` unlinks) and/or `items` (replaces **all** the lines).
Refused (409): a Cancelled invoice; another `issuer` (it is in the number — cancel and create a new one);
another `currency` once payments exist; a new total below the amount paid unless `"allow_overpaid": true`
(then issue the credit note on the Hub page). Payments and status are recalculated, and the request value.
Dry-run unless `"confirm": true` → `changes {field: {from, to}}` (items / total included), `balance_after`.
The Dropbox PDF is not updated — `save_invoice_pdf` with `"overwrite": true` afterwards.

```bash
curl -sH "$H" "$U?action=find_invoices&q=Fiorini"
curl -sH "$H" "$U?action=get_invoice&invoice_number=SE-2026-0012"
curl -sH "$H" -X POST "$U?action=add_invoice_payment" -d '{"invoice_id":412,"date":"2026-10-01","amount":1950,"reference":"FT2627401","notes":"Deposit"}'
curl -sH "$H" -X POST "$U?action=update_folder_status" -d '{"invoice_id":412,"status":"DEPOSIT"}'                 # preview
curl -sH "$H" -X POST "$U?action=update_folder_status" -d '{"invoice_id":412,"status":"DEPOSIT","confirm":true}'
curl -sH "$H" -X POST "$U?action=save_invoice_pdf" -d '{"invoice_id":412,"overwrite":true}'
curl -sH "$H" -X POST "$U?action=send_invoice_email" -d '{"invoice_number":"SE-2026-0012"}'                   # preview: to / cc (with sales) / subject / body
curl -sH "$H" -X POST "$U?action=send_invoice_email" -d '{"invoice_number":"SE-2026-0012","to":"booking@agency.it","confirm":true}'
curl -sH "$H" -X POST "$U?action=create_invoice" -d '{"request_id":873,"agency_id":12,"issuer":"SE","items":[{"description":"Rossi 4 pax trip in Tanzania from 10 Feb until 16 Feb 2027","quantity":4,"unit_price":2450}]}'   # dry run
curl -sH "$H" -X POST "$U?action=update_invoice" -d '{"invoice_number":"SE-2026-0012","due_date":"2026-12-01","confirm":true}'
curl -sH "$H" -X POST "$U?action=cancel_invoice_payment" -d '{"invoice_id":412,"payment_id":901,"reason":"Recorded twice","confirm":true}'
```

## Memo Board (follow-ups)

Claude writes to **one** user's Memo Board: `AGENT_MEMO_USER` (Hub username) in the server-only
`includes/config.php`; if not defined, the active user sharing the API user's agent (Roberto).
Memos stay private to that user unless shared in the Hub. Logic: `modules/memo/memo_lib.php`
(columns added on first use, or run `migrations/064_memo_followups.sql`).

| Status | Meaning |
|---|---|
| `open` / `doing` | to do / in progress |
| `waiting` | waiting for someone (`waiting_on`); `due_date` = follow-up date; shown first on the board |
| `pending` | a next step — hidden until its parent is done, then opened with due = today + `days_after` |
| `done` / `archived` | closed |

**Morning digest (not an API action).** `modules/memo/cron_digest.php?token=MEMO_CRON_TOKEN`, called by
cron-job.org Mon–Sat 06:00 EAT (Africa/Nairobi), emails the `AGENT_MEMO_USER` board to `MEMO_DIGEST_TO` (both in
`includes/config.php`) with subject `[Memo Hub] YYYY-MM-DD – N da sollecitare, M scaduti`: overdue,
follow-ups due today, next 7 days, in progress, routines, and a last line
`DIGEST-COUNTS: sollecitare=…; scaduti=…; attesa7=…; incorso=…; leads=…; sh_aperte=…`. Open memos
without a due date are left out. Once a day (`memo_digest_log`); `&force=1` resends, `&dry=1` shows
the HTML without sending. The scheduled Cowork recap reads this email from Gmail instead of
calling the API. Rows come from `memo_rows()` and `memo_routines_status()`, the same code as
`memo_list` / `routine_status`.

### `memo_list` (GET)
`status` (comma list, default `open,doing,waiting`), `q`, `request_id`, `invoice_id`, `ext_key`,
`follow_up_due=1` (due date today or earlier). → `memos[]` `{id, title, status, waiting_on, due_date,
reminder_at, priority, body (text), request_id, folder, invoice_id, invoice_number, invoice_balance,
afrasia, auto_close_on_payment, next_steps[], source, ext_key}`.

### `memo_save` (POST)
Creates, or updates when `id` / `ext_key` matches (use `ext_key` such as `cn-asilia-glady` so repeated runs
update instead of duplicating). Fields: `title`*, `body` (plain text), `status` (`open`|`doing`|`waiting`),
`waiting_on`, `due_date`, `reminder_at` (`YYYY-MM-DD HH:MM` EAT, email), `priority`, `request_id`,
`invoice_id` or `invoice_number` (request taken from the invoice), `auto_close_on_payment` (closed when a
payment is recorded on that invoice — by the Hub page or `add_invoice_payment`), `next_steps[]`
`{title, days_after?, body?}` (replaces the pending ones), `ext_key`. A `waiting` memo with a `due_date` and no
reminder gets an email reminder at 08:00 that day. Created memos are marked 🤖 Claude.
The reply has a top-level `request_id` when the memo is linked to a request (memos also appear in
`request_resume`).

**What not to put on the board.** A memo (or a `next_steps` entry) is something **Roberto** has to do or
follow up. Never create memos for Claude's own work steps — API calls and Hub bookkeeping such as
"register the payment (`add_invoice_payment`)", "update the invoice PDF (`save_invoice_pdf`)",
"save in Inv4Agents", "update the Calc / folder": do them in the session, or say in the reply what is
still to do. A payment memo with `auto_close_on_payment` needs no next steps for recording that payment.

### `memo_set_status` (POST)
`id` or `ext_key`, `status` (`open`|`doing`|`waiting`|`done`|`archived`), `note` (appended when done).
`done` opens the next steps → `opened_next[]`.

### `memo_delete` (POST)
Deletes memos from the owner's board, like the bin on the Memo Board (same code, `memo_delete()` in
`memo_lib.php`): soft — `deleted_at` is set, the row stays in the DB (no restore through the API).
`id` or `ext_key`, or `ids` (array, max 50). **Dry-run unless `"confirm": true`** → `{dry_run, to_delete, memos[]}`;
with `confirm` → `{deleted: [ids], memos[]}`. One unknown id (or a memo on another user's board) → 404, nothing
deleted. Pending next steps of a deleted memo are left as they are (as on the board); they never open.
To close a finished task use `memo_set_status` `done` — delete only memos that are wrong, duplicated or no longer wanted.

```bash
curl -sA "$UA" -H "$H" -X POST "$U?action=memo_delete" -d '{"ids":[812,813]}'                  # dry run
curl -sA "$UA" -H "$H" -X POST "$U?action=memo_delete" -d '{"ids":[812,813],"confirm":true}'
```

`add_invoice_payment` returns `memos_closed[]` (memos closed automatically by that payment).

### Routines — `routine_status` (GET) / `routine_done` (POST)
Recurring checks on top of the Memo Board (definitions: `MEMO_ROUTINES` in `memo_lib.php`):

| key | every | count |
|---|---|---|
| `leads` — assign Incoming Leads | 3 h | rows in `lead_staging` |
| `mail` — Gmail + Bluehost, new requests → create & assign | 3 h | — |
| `payments` — payments to request (Payments page) | 3 days | — |
| `afrasia` — check the AfrAsia account | 3 days | SH invoices with open balance |

`routine_status` → `routines[]` `{key, title, every_hours, last_done, hours_since, due, count, attention}`.
`routine_done` `{key, note?}` records the check (shown as "(Claude)" on the board). Typical round:
read `routine_status`, do the due ones (e.g. Gmail via the connector and Bluehost via `mail_list unseen=1`
→ `find_requests` / `create_request`),
then `routine_done` with a short note ("3 new mails, 1 request created").

```bash
curl -sH "$H" -X POST "$U?action=memo_save" -d '{"ext_key":"cn-asilia","title":"Credit note Asilia","status":"waiting","waiting_on":"Glady (Asilia)","due_date":"2026-10-06","body":"Mail sent 2 Oct asking for the CN"}'
curl -sH "$H" -X POST "$U?action=memo_save" -d '{"title":"Balance Etnia – Rossi","status":"waiting","waiting_on":"Etnia","invoice_number":"SH-2026-0041","auto_close_on_payment":true,"due_date":"2026-10-05","next_steps":[{"title":"Pay Lake Natron Camp","days_after":2}]}'
curl -sH "$H" "$U?action=memo_list&follow_up_due=1"
```

## Mailboxes info@ and Gmail (IMAP)

Lets Claude read and answer `info@savannahexplorers.com` without logging in to the BlueHost
webmail, and draft / send from Gmail (`savannah.explorers@gmail.com`). Logic: root
`includes/mailbox_service.php` (PHP imap extension, on BlueHost).

**Which mailbox** — every `mail_*` action takes `mailbox` = `info` (default) | `gmail`; `mail_draft` /
`mail_send` take it as `from` (same values). Roberto's rule: a **reply** goes from the mailbox the mail
arrived on; **new mails and staff mails** go from Gmail.
- `info` — BlueHost, IMAP on `localhost`; `mail_send` uses the server mail() and keeps a copy in `INBOX.Sent`.
- `gmail` — IMAP `imap.gmail.com:993`, `mail_send` via SMTP `smtp.gmail.com:587` (STARTTLS); Gmail files the
  Sent copy itself. Drafts go to the Gmail Drafts folder, found by its `\Drafts` flag (the name is in the
  account language, e.g. `[Gmail]/Bozze`); `GMAIL_DRAFTS` overrides it.

**Setup (server, once)** — in the server-only root `includes/config.php`:
```php
define('MAILBOX_USER', 'info@savannahexplorers.com');
define('MAILBOX_PASS', '<mailbox password>');
// optional: define('MAILBOX_IMAP', '{localhost:993/imap/ssl/novalidate-cert}');
// optional: define('MAILBOX_NAME', 'Savannah Explorers');   // From name
// optional: define('MAILBOX_SENT', 'INBOX.Sent'); define('MAILBOX_DRAFTS', 'INBOX.Drafts');

// Gmail: Google account → Security → 2-Step Verification on → App passwords → "Hub"
define('GMAIL_USER', 'savannah.explorers@gmail.com');
define('GMAIL_APP_PASSWORD', '<16-character app password>');
// optional: define('GMAIL_NAME', 'Savannah Explorers'); define('GMAIL_DRAFTS', '[Gmail]/Bozze');
```
Without them the `mail_*` actions for that mailbox answer 503. If the mailbox password is changed in cPanel,
update `MAILBOX_PASS` too (calls then fail with "IMAP login failed"); a revoked Gmail app password fails the same way.
Passwords are never written to the audit log (only the request body is logged, and it never carries them).

Messages are addressed by `folder` (default `INBOX`; BlueHost names: `INBOX.Sent`, `INBOX.Archive`, …;
Gmail: `INBOX`, `[Gmail]/All Mail`, labels) + `uid`. Reading never marks a message as seen unless
`mark_seen: true`. There is no delete. The audit log keeps who/subject for `mail_get` / `mail_attachment`,
not bodies or files.

### `mail_folders` (GET)
→ `folders[]` `{name, messages, unseen}`.

### `mail_list` (GET)
`folder`, `unseen=1`, `flagged=1`, `from`, `to`, `subject`, `q` (full text), `since` / `before`
(YYYY-MM-DD), `limit` ≤ 100 (default 30), `offset`. Newest first.
→ `total`, `messages[]` `{uid, date, from, to, subject, seen, flagged, answered, size, has_attachments}`.

### `mail_get` (GET)
`uid`*, `folder`, `mark_seen=1`. → `message` `{uid, date, from[], reply_to[], to[], cc[], subject,
message_id, in_reply_to, references, seen, flagged, answered, body (plain text; HTML converted),
body_truncated, has_html, attachments[] {part, name, mime, size, inline}}` (small inline images such as
signature logos are left out).
With `request_id` the message is logged in that request's timeline as `mail_received` (sender, date,
first 1500 characters; `refs.mail_message_id`) → `timeline_event_id`. Once per Message-ID: reading it again
returns the same event. Use it in the mail round for client / agency emails about a request.

### `mail_attachment` (GET)
`uid`*, `part`* (from `mail_get`), `folder`. → `attachment {name, mime, size, content_base64}` (≤ 10 MB).
With `request_id` (booking folder) or `folder_path` (full Dropbox path) the file is **saved to Dropbox**
instead → `saved_to`; `save_as` renames it; an existing file is kept unless `overwrite: true`.

### `mail_flag` (POST)
`uid` or `uids[]`, `folder`, `seen` and/or `flagged` (true/false).

### `mail_move` (POST)
`uid` or `uids[]`, `folder`, `to_folder`* (must exist). Dry-run unless `"confirm": true`.

### `mail_draft` (POST) / `mail_send` (POST)
`from` = `info` (default) | `gmail`. `to`, `cc`, `bcc` (string or list), `subject`, `body` (plain text) or
`body_html` (a plain-text part is added from it), `attachments[]` `{name, content_base64}` (≤ 10 MB each).
The signature is part of the body (Cowork adds OfficeGmail.html); nothing is appended.
Replies, threaded with In-Reply-To / References:
- `reply_to_uid` (+ `reply_folder`) — a message of the same mailbox: `to` defaults to the sender (Reply-To),
  `subject` to "Re: …".
- `in_reply_to` (Message-ID, `<…@…>`) + `references` (its References header, string or list) + `subject`
  — a message read with another tool (the Gmail connector). Gmail threads the draft under the original when
  the subject matches ("Re: <original subject>").

- `mail_draft` saves it in **Drafts** — nothing is sent; Roberto sends it from webmail / Gmail / phone.
- `mail_send` is a dry-run (→ `email` preview) unless `"confirm": true`; then it sends, keeps a copy in
  **Sent** (Gmail: automatically) and marks the original as answered (`reply_to_uid`).
- → `email {from, mailbox, to, cc, bcc, subject, in_reply_to, references, body, attachments}`.
- Optional `request_id`: a confirmed `mail_send` is logged in that request's timeline (`mail_sent`,
  `refs.mail_box` `bluehost` | `gmail`, → `timeline_event_id`). Drafts are not logged.

```bash
curl -sH "$H" "$U?action=mail_list&unseen=1&limit=20"
curl -sH "$H" "$U?action=mail_get&uid=48213"
curl -sH "$H" "$U?action=mail_attachment&uid=48213&part=2&request_id=2958"        # → booking folder
curl -sH "$H" -X POST "$U?action=mail_draft" -d '{"reply_to_uid":48213,"body":"Dear Anna,\nthank you …"}'
curl -sH "$H" -X POST "$U?action=mail_flag"  -d '{"uids":[48213,48214],"seen":true}'
curl -sH "$H" "$U?action=mail_folders&mailbox=gmail"
# Gmail draft in an existing thread (Message-ID / References from the Gmail connector)
curl -sH "$H" -X POST "$U?action=mail_draft" -d '{"from":"gmail","to":"agent@agency.it","subject":"Re: Rossi 12 Oct","in_reply_to":"<CAF12ab@mail.gmail.com>","references":"<CAE99@mail.gmail.com> <CAF12ab@mail.gmail.com>","body_html":"<p>Dear Anna, …</p>"}'
curl -sH "$H" -X POST "$U?action=mail_send"  -d '{"from":"gmail","to":"staff@savannahexplorers.com","subject":"Lunch boxes 14 Oct","body":"…"}'   # dry-run
```

## Back office (read-only)

The data of the Hub back-office pages, for the Cowork "back-office" skill (`docs/skills/back-office.md`).
Same queries as the pages (shared services: `modules/leads/includes/payments_service.php`,
`modules/leads/includes/ck_lib.php`, `modules/operations/includes/ops_service.php`). All GET and read-only
except `ck_request_check`. "Booked" = request status Booked / Paid / Balance / Deposit, not cancelled.
Dates come from the folder name (`_START…` / `_END…`). The `ops_*` and `flight_tickets` actions read each
booking's Calc from Dropbox; it is cached (`ops_calc_cache`) and downloaded again only when the file changes,
so the first call over a period can take a minute. `calc=0` skips the Calc (no cross-check). Max 62 days per call.
Group bookings (GRP, Calc with a RECAP sheet in the group folder): each member is read from its own client
sheet, matched by name ("1PAX Cacioli" ~ AnnamariaCaciolli); with one client sheet that one; with no match the
RECAP sheet (whole group) and a warning in the Calc `warnings`.
Passport numbers are never read or returned.

### `payments_due` (GET)
The Payments page list (bookings at `_DEPOSIT` / `_BALANCE` / `_BALANCE-CASH`) with invoices, payments and due dates.
`days` (default 14: next due date within N days, overdue included), `overdue=1` (only overdue),
`stage` (`Deposit` | `Balance` | `Balance-Cash`), `issuer` (`SE` | `SH`; bookings without invoice drop out).
Due-date rule (the page stores none): deposit 30% at `confirmation_date`; balance 60 days before START for
direct clients, 45 for agencies (`channel` agency, else a folder tag without `Drct`); Il Diamante: no deposit,
balance 30 days before arrival. The next due is the deposit while the folder is `_DEPOSIT` and the invoice
payments don't cover 30%, else the balance.
→ `counts`, `rows[]` `{request_id, folder, client_folder, customer, agency, agent, client_type, start_date, stage,
confirmation_date, days_since_confirmation, invoice_number, invoice_ids[], issuer, currency, total, paid, balance,
deposit_due, deposit_due_date, balance_due_date, invoice_due_date, next_due (deposit|balance), next_due_date,
days_to_due, overdue_days, last_payment_date, last_contact (latest mail_sent in the timeline / Payments notes),
memo_id (open payment memo on the invoice), notes[]}`, sorted by `next_due_date`;
`sleeping[]` (confirmed > 7 days ago with **no invoice** — `reason: no_invoice` — or still `Deposit` with no
payment and no invoice activity for 7 days — `deposit_no_payment`); `no_due_date[]` (no confirmation date /
no START in the folder).

### `ck_status` (GET)
The CK tracker: every booking folder at the top of `/001_Safari` (a private safari or a whole GRP). Runs the
same Dropbox scan as the page first (`scan=0` skips it; a failed scan → `scan_warning`, last known state).
Tanzania only; trips already started are left out unless `past=1` or `q`.
`has_ck=0|1`, `start_before` (YYYY-MM-DD), `q` (folder / customer / agent words).
→ `rows[]` `{ck_folder_id, request_id, request_ids[], folder, agent, start_date, days_to_arrival, band
(red < 60 days · amber 60–90 · grey > 90), stage, ck_due (booking done, no _CK), waiting_days, has_ck, ck_at,
ck_by, check_requested_at, last_checked_at, overall (green|yellow|red|grey|error), n_red, n_yellow,
reds[] / yellows[] ("Section — Title" of the open points; confirmed ones left out), check_error, report_url,
last_note}`. To set `_CK` use `rename_folder` (it keeps `_CK` last).

### `ck_request_check` (POST)
`request_id` or `ck_folder_id`. Queues a SafariCheck run (`check_requested_at`) and starts the runner, as
"Run check" on the CK tracker (result in ~2 minutes, else in the nightly run). Dry-run (→ `would_check`)
unless `"confirm": true`.

### `ops_movements` (GET)
Arrivals and departures saved in Operations (real dates, not the 06:00 night shift of the daily view), cross-checked
with the bookings. `from`, `to` (default today → +14 days), `calc=0`.
→ `counts`, `rows[]` `{date, type (arrival|departure), movement_id, request_id, folder, customer, pax, flight,
time, from_to, transfer (driver), hotel, notes, source (calc = saved from the Calc extractor | manual),
calc_value (Calc ARRIVAL / DEPARTURE DETAILS text), match, diffs[] {field (date|flight|time), ops, calc}}`:
- `ok` — matched to a booking (customer name, date ± 7 days) and consistent with the Calc;
- `diff` — date (beyond ± 1 day), flight or time not in the Calc text;
- `missing_in_ops` — a booking starting / ending in the period with no arrival / departure row;
- `manual_only` — an Operations row with no booking (or a name the match did not recognise).
`warnings[]`: within 7 days, flight / time empty or TBA, or a booking with no Operations row.

### `ops_lunch_boxes` (GET)
Lunch boxes (current + history tables) by safari date. `from`, `to` (default today → +14), `calc=0`.
→ `rows[]` `{id, date, client, request_id, folder, pax, jeeps, guide, box_type, lodge (overnight before, from the
Calc), diet_notes (EXTRA DETAILS), notes, folder_name, source}` (the lunch box table has no departure time);
`missing[]` `{date, request_id, folder, customer, pax, start_date, end_date, lodge, safari_day, extra_details}`:
a safari day (park fees / safari park) after a night in Arusha in the Calc with no lunch box for that date;
`calc_unreadable[]` (several pax sheets and none CONF, no Calc …: check by hand).

### `ops_medivac` (GET)
Medivac registrations by coverage start (current + archive), and the Tanzania safaris starting in the
period that are not registered. `from`, `to` (default today → +30), `calc=0`.
Rule: Tanzania safaris only; trekking + safari included; trekking only, Only Zanzibar, Staff and other
countries excluded (→ `excluded[]` with `reason`).
→ `rows[]` `{group_ref, group_name, tour_agent, request_id, folder, start_date, end_date, guests[] {name, title,
nationality}, warnings[], source}`; `missing[]` `{request_id, folder, customer, pax, start_date, end_date, reason,
guests[] (from the Calc), warnings[]}`. Warnings: TBA names, missing nationality, Calc not readable.
Never passport numbers (the Medivac page keeps its own passport column; the API does not select it).

### `flight_tickets` (GET)
Bookings starting in `from`–`to` (default today → +30) with a domestic flight booked by Savannah (Calc
FLIGHT cost > 0), and the ticket files in the booking folder (top-level names with ticket / e-ticket / PNR /
boarding / airline names, or anything in `flights/`; a GRP member's group folder too).
→ `counts`, `rows[]` `{request_id, folder, customer, pax, start_date, end_date, flights[] {date, route (Calc day
text), cost}, ticket_files[], status (ok | missing | unknown = Calc not readable, + msg)}`.
Not yet: `sent` (a mail with the ticket in the request timeline), `vouchers_status`.

```bash
curl -sH "$H" "$U?action=payments_due&days=21"
curl -sH "$H" "$U?action=payments_due&overdue=1&issuer=SH"
curl -sH "$H" "$U?action=ck_status&has_ck=0&start_before=2027-01-15"
curl -sH "$H" -X POST "$U?action=ck_request_check" -d '{"request_id":2958}'                 # dry-run
curl -sH "$H" "$U?action=ops_movements&from=2026-10-14&to=2026-10-28"
curl -sH "$H" "$U?action=ops_lunch_boxes"
curl -sH "$H" "$U?action=ops_medivac&from=2026-10-12&to=2026-11-12"
curl -sH "$H" "$U?action=flight_tickets"
```

### Booking follow-up — `followup_list`, `followup_scan`, `followup_update`, `followup_upsert`
The open items the booking team chases (Hub: Operations → 📌 Booking follow-up, `modules/operations/booking_followup.php`;
logic `modules/operations/includes/followup_service.php`, documents read by `followup_classify.php`; tables
`booking_followups`, `booking_followup_events`, `booking_followup_docs`, `booking_followup_runs` — migration 069 or created on first use).
- `type: supplier` — documents in `<booking>/invoices/` (top level; `invoices/old/` is never read) of confirmed bookings not
  ended (same set as Operations: `_END` tag, not cancelled): `issue` `provisional`, `provisional_expired` (expiry printed and
  past), `on_request` (flight invoice), `orangi_rooms_provisional` (voucher Confirmed with a note "… provisional"),
  `unreadable` (no text in the PDF). Payment files (swift, receipt, bank …) are skipped.
- `type: ticket` — domestic flights in the next 14 days of bookings with no ticket file (the `flight_tickets` logic):
  `ticket_missing`; a Calc that cannot be read → `unreadable` with the reason.
- `type: group` — group folders at the top of `/001_Safari` arriving within **60 days**: every `GRPnnnn` folder,
  Il Diamante set departures (`(Diamante…)` / `Panorama…`) and private agency groups tagged `_PROVISIONAL`.
  `group_to_finalize`: what the check could collect for the booking team, which checks and finalises (release /
  confirm services, room list, arrival / departure flights): client sub-folders (GRP: one per confirmed client), the Excel
  (`*_Calc.xlsx`, else the Panorama workbook: RECAP + client sheets, pax and rooms), supplier blocks ("block 3 double rooms
  (6 adults)"), documents still provisional, names TBA. `group_znz_flight` (when a flight to Zanzibar is booked or a client has
  ARK-ZNZ in the Calc): seats on the flight invoices vs pax with the flight in their Calc sheet → "seats match", "N seat(s) to
  release", "N pax without a seat"; `expiry_date` = ticketing time limit. The full data is in `details`. A group line
  closed by staff is **not** re-opened by the next check; a group folder no longer found closes as `auto_not_found`.
  PDF facts come from the server reader or from the office PC scan (`followup_upsert` keeps every document sent, also for
  group folders without a confirmed request); the Excel is read on the server.
- One line per `dedupe_key`: request + supplier reference (WB…, booking ID), else request + supplier + service date;
  tickets request + flight date + route. GRP: documents in the group folder belong to the group's first request (a
  member's `request_id` filter shows them too).
- **Folders without a confirmed booking** (sent by `followup_upsert`): group folders in `/001_Safari` (GRP, Diamante /
  Panorama, `_PROVISIONAL`) and folders outside it (Diamante provisional groups, `/itineraries/SafariClassic/it/Agenzia/Diamante/20xx-Groups`)
  get their own lines with `request_id` `null`, `folder` = path under `/001_Safari` or absolute Dropbox path, key
  `S|F:<folder stem>|…`. When the booking is confirmed (folder in `/001_Safari` with the same stem), the next upsert moves
  those lines to it, with owner, notes and history (event `booking`).
- The check writes only the document fields; `owner`, `staff_status`, `notes` are the staff's. Each note saved is kept
  (event `notes`, `notes_log` in the rows); `notes` is the last one. `contacted_at` is still accepted but no longer shown. Lines are never
  deleted. They close with `close_reason`: `auto_confirmed` (the same reference in a document reading Confirmed without a
  provisional note), `auto_ticket_saved`, `auto_trip_ended`, `auto_not_found` (the file left `invoices/` and nothing else has
  that reference — check it was not moved without a confirmed one), `manual` (staff: `resolved`; admin: `not_relevant`).
  A line still found provisional after a staff `resolved` (or an auto-close) is **re-opened**; `not_relevant` stays closed.
- `priority` (computed): `1-URGENT` service ≤ 30 days, or expiry ≤ 7 days, or expired with service ≤ 90 days; `2-HIGH` ≤ 90
  days; `3-NORMAL` later. Tickets: `1-URGENT` within 7 days, else `2-HIGH`.
- **PDFs on the server:** BlueHost has no `pdftotext` and no Composer today, so `followup_scan` type `supplier` only uses
  documents already read and returns `reader: "none"` + a `warning`. Two ways: (a) on the office PC run
  `tools\followup_local_scan.bat [--confirm]` (reads `C:\Dropbox\001_Safari` and the Diamante `20*-Groups` folders (`--extra`),
  same rules, PDFs without text through the Windows OCR, sends `followup_upsert`); (b) install a reader
  on the server: `smalot/pdfparser` in `vendor/` (pure PHP), or a static `pdftotext` binary with its path in
  `FOLLOWUP_PDFTOTEXT` (`includes/config.php`).

#### `followup_list` (GET)
`type` (`supplier` | `ticket` | `group`), `status` (`open` default | `closed` | `all`), `owner` (username | user id | `none`),
`priority` (`1-URGENT` | `2-HIGH` | `3-NORMAL`, or `1`/`2`/`3`), `request_id`, `q` (client / folder / supplier / ref / notes).
→ `count`, `last_check_at` `{supplier, ticket}`, `last_checks`, `rows[]` `{id, type, request_id, customer, folder, folder_url,
file_url, folder_open_url, file_open_url, start_date, end_date, service_date, days_left, supplier, supplier_ref, service, source_file, issue, issue_label,
doc_status_text, expiry_date, expired, booked_by, owner, owner_name, contacted_at, staff_status, notes, notes_log[] {at, by, text} (newest first), priority,
first_seen_at, last_seen_at, closed_at, closed_by, close_reason, events_count}` — not taken in charge first, then problems,
priority, service date.

#### `followup_scan` (POST)
Runs the check. **Dry run unless `"confirm": true`** (the dry run returns what would change). `type` `supplier` | `ticket` | `group` |
`all` (default), `request_id` (one booking). → `supplier` / `ticket` summaries `{added[], updated[], closed[] (+ reason),
reopened[], unreadable[], unchanged}`; supplier also `units`, `units_complete`, `reader`, `partial` (time budget used up —
run again, each PDF is read once per Dropbox revision), `errors[]`.

#### `followup_update` (POST)
`id` + any of `owner` (username / id / `null`), `contacted_at` (YYYY-MM-DD or `""`), `staff_status` (`open`, `in_progress`,
`problem` — needs a note —, `resolved`, `not_relevant`), `notes`. Dry run unless `"confirm": true`. Acts as `AGENT_API_USER`
with the follow-up admin rights (reassign, not relevant); every change is an event (who / when). → `changes {field: [old, new]}`, `row`.

#### `followup_upsert` (POST)
Supplier documents read elsewhere (office PC, Cowork) — also the way to load a baseline. Dry run unless `"confirm": true`.
`rows[]` `{folder (path under /001_Safari, e.g. "00_2027/…" or "<GRP>/<member>"; or an absolute Dropbox path "/itineraries/…" for a folder outside it) | request_id, source_file
("invoices/x.pdf"), supplier?, supplier_ref?, service_date?, issue? | status: "confirmed", doc_status_text?, expiry_date?,
booked_by?}` (max 2000), `scanned[]` = folders whose `invoices/` were read completely (their lines not sent close as
`auto_not_found`; rows with `status: confirmed` close their reference). Folder names match by stem (status tags ignored).
A group folder without a confirmed booking, or a folder outside `/001_Safari`, gets its own lines (see above).
→ the summary + `matched`, `unmatched[]`, `folders_without_booking`.

```bash
curl -sA "$UA" -H "$H" "$U?action=followup_list&owner=none&priority=1"
curl -sA "$UA" -H "$H" "$U?action=followup_list&request_id=2958&status=all"
curl -sA "$UA" -H "$H" -X POST "$U?action=followup_scan" -d '{"type":"ticket"}'                                   # dry run
curl -sA "$UA" -H "$H" -X POST "$U?action=followup_scan" -d '{"type":"group"}'                                    # dry run: groups arriving within 60 days
curl -sA "$UA" -H "$H" -X POST "$U?action=followup_scan" -d '{"type":"all","confirm":true}'
curl -sA "$UA" -H "$H" -X POST "$U?action=followup_update" -d '{"id":12,"staff_status":"problem","notes":"Lodge full on 1 Jan, alternatives asked"}'   # dry run
curl -sA "$UA" -H "$H" -X POST "$U?action=followup_upsert" -d '{"rows":[{"folder":"12_30DEC_ElenaManfroni(UnEmozionePerSempre-PS-Roberto)_START30DEC_MIDT04JAN_END08JAN2027_BALANCE","source_file":"invoices/Lion'"'"'s paw-INV.pdf","supplier":"Lion'"'"'s Paw","supplier_ref":"WB41803","service_date":"2027-01-01","issue":"provisional_expired","doc_status_text":"Provisional (expires 28 Sep 2026)","expiry_date":"2026-09-28","booked_by":"Lydia"}],"scanned":[]}'   # dry run
```
Checks are run by hand for now (page → Run check now → All; office PC scan for the PDFs). Optional cron (cron-job.org, not set up): `modules/operations/followup_cron.php?token=…&type=ticket` daily 05:30, `&type=group` daily 05:40, `&type=supplier` Monday 05:30
(token `FOLLOWUP_CRON_TOKEN`, else `MEMO_CRON_TOKEN`; `&dry=1` = summary only). The memo digest (06:00) lists new lines,
problems, lines not taken in charge for > 2 days, provisional expiring ≤ 7 days and tickets missing ≤ 7 days.

## Mail security (read-only)

The cPanel account's mailboxes, filters, forwarders, auto-replies and catch-all are scanned by
`modules/security/mail_scan.php` (cPanel cron, daily 06:30 EAT) or `modules/security/mail_scan_cron.php?token=…`
(cron-job.org, daily 06:30 EAT; token `MAIL_SCAN_CRON_TOKEN`, else `MEMO_CRON_TOKEN`; cPanel token over HTTPS only, at most one
scan a day; same run, `mss_run()`; logic `modules/security/includes/mail_security_service.php`,
tables `migrations/071_mail_security.sql`). Daily email `[Mail Security] ALERT …` only on changes, Monday
`[Mail Security] Weekly …` always, `[Mail Security] SCAN FAILED …` if the scan breaks — to `MAIL_SCAN_TO`
(default savannah.explorers@gmail.com). Background: the Oct 2026 "GLAD" filter on agustin@ that redirected
client mail to savanah.explorers@gmail.com. cPanel is read over HTTPS UAPI with `CPANEL_API_TOKEN`
(includes/config.php); the `uapi` command line is only the fallback (it starts processes on the account, which
has a process limit) and a token failure sends `[Mail Security] WARNING … – cPanel token not working`.

HIGH: a filter delivering outside our domains (savannah.explorers@gmail.com counts as ours), discarding,
bouncing, saving to a folder or piping; a filter with ≥ 5 "contains @domain" rules; a forwarder / catch-all
to an outside address; any address that looks like ours but is not (savanah…, savannah-explorers@gmail…).
Items in the approved baseline (seeded by the first run, HIGH items excluded) are not alerted again unless they change.
Approve after checking: on the server `php ~/public_html/hub/modules/security/mail_scan.php --approve=<key>`
(no API action for that yet).

### `mail_security_status` (GET)
Reads the last run from the DB (never calls cPanel). → `status` (`ok` | `alerts` | `scan_failed` | `stale` = no good
scan for > 26 h | `never_run`), `last_run {at, ok, error, mailed}`, `last_good_scan {at, hours_ago, source (uapi|token),
state_unchanged_since}`, `counts {mailboxes, filters, forwarders, autoreplies, catchall_not_fail, alerts, high}` (same
as the email's `MAILSCAN:` line), `alerts[] {change (added|changed|removed|present), level (HIGH|normal), key, kind,
label, old_label, reasons[], detail}`, `high_items[] {key, label, reasons[], approved}`, `baseline_items`.

```bash
curl -sA "$UA" -H "$H" "$U?action=mail_security_status"
```

## Sales responsiveness (read-only)

How fast the sellers (Asia, Alessia, Anderson, Nuru, Agustin — not Roberto / Daniela) answer clients by email,
request by request. `modules/leads/sales_response_scan.php --cron` (cPanel cron, hourly at :05) or
`modules/leads/sales_response_cron.php?token=…` (cron-job.org, daily 23:30 for the scan + 07:05 for the mails; token `SALES_RESPONSE_CRON_TOKEN`, else
`MEMO_CRON_TOKEN`; same run, `sr_run()`, a MySQL lock skips overlaps) reads only the **headers**
(From, To, Cc, Date, Subject, Message-ID, In-Reply-To, References) of the sellers' mailboxes and sales@ straight
from the Maildirs (not info@ = Roberto, not safari@ = Daniela: their replies are seen only when a seller's mailbox
is in copy) — no bodies, no attachments, no IMAP. Logic `modules/leads/includes/sales_response_service.php`,
tables `migrations/072_sales_response.sql`, page `modules/leads/sales_response.php` (admin / manager: every seller and
reminders; anyone else: only their own agent, `users.agent_id`). Emails to `SALES_RESPONSE_TO`
(default savannah.explorers@gmail.com): daily 07:00 `[Sales Response] <date> – N mail senza risposta >24h` (only with
`SALES_RESPONSE_LIVE` true and something to report), Monday `[Sales Response] Weekly <date>` (always, "TEST" until LIVE),
`[Sales Response] SCAN FAILED <date>` on errors. First run: TEST report with the seller mapping and 90 days.

- **Linking**: 1. In-Reply-To = a mail already linked; 2. the counterpart address = request email / agency / agency
  contact (or same company domain); several open requests → References, then folder name in the subject, else the most
  recent one, `ambiguous`; 3. folder name `Name(Agency-Agent)` in the subject, only for a free-mail counterpart.
  Lost / Cancelled requests keep their history (their replies count in the KPIs) but never appear in the alerts.
- **Direct clients only** (`SR_DIRECT_ONLY`): requests with `channel` = direct, or no channel + no agency + not an "(…-SB)"
  folder. Agency / SB / Other requests are linked (better matching) but not counted anywhere (KPIs, lists, reminders).
- **client** = outside sender; **savannah** = from our domains (or Roberto's Gmail) to an outside address; internal
  mail, auto-replies, lists, bounces and no-reply senders are ignored.
- A wait = the first unanswered client mail (several in a row = one wait) until the next savannah mail.
  Threshold 24 clock hours, always. Evening / weekend / holiday = 18:00–08:00 EAT, Sat, Sun, Tanzanian holidays
  (`SR_HOLIDAYS`, Eid / Maulid dates are estimates). Leave days (`leave_entries`) count like any other day: sellers
  on leave must still answer (Roberto, 18 Oct 2026); `in_ferie` / `on_leave` are only notes. On request
  (`exclude_leave=1`, page checkbox) the waits starting on a leave day are left out of the KPIs (`on_leave_excluded`).

### `sales_response_status` (GET)
Params: `week` (any `YYYY-MM-DD` of the week; default last complete week), or `month` (`YYYY-MM`, compared with the month
before), or `from` / `to` (`YYYY-MM-DD`, compared with the same number of days before) — then `week`/`prev` in the sellers
are that period and the previous one, see `period`; `agent_id`, `exclude_leave` (1 = leave days out
of the KPIs; default 0, as the weekly email). Same numbers as the weekly email.
→ `week {start, end}`, `previous_weeks` (4), `live`, `leave_excluded`, `sellers[] {name, agent_id, agent_name, mailbox, leave_names[],
mapping_error, week, prev (the 4 weeks before, pooled), total, weeks {monday: metrics}, open_unanswered}` where metrics =
`{due, answered_24h, pct_24h, late, median_h, p90_h, answered, off_due, off_answered_24h, pct_off_24h, new_leads,
new_leads_median_h, client_msgs, sent_msgs, on_leave_excluded}`; `unanswered[] {seller, request_id, folder, customer,
client, subject (of the last client mail), first_client_at, last_client_at, client_msgs, hours_waiting, in_ferie, ambiguous, url}`, `new_leads[]`,
`silent[]` (our mail last, client silent 7+ days, not Booked), `last_scan {status (ok|stale|scan_failed|never_run), …}`,
`limits[]`, `footer`.
Reminders: the Hub page (unanswered list, filter by seller, tick the mails) mails the seller the list from the Hub,
reply-to `SALES_RESPONSE_TO`, logged in `sales_response_reminder` (shown as "reminded <date>"); logic `sr_send_reminder()`
(`$confirm` false = preview), no Agent API action yet.

```bash
curl -sA "$UA" -H "$H" "$U?action=sales_response_status"
curl -sA "$UA" -H "$H" "$U?action=sales_response_status&week=2026-10-12&agent_id=7"
```

## Request timeline

A running history and a short "where are we" summary for each request, so a new session can pick
up a practice that started weeks earlier in another chat. Staff see the same thing in the Hub
(request page → 🕓 Timeline; requests list → Last activity). Logic: `modules/leads/includes/timeline_service.php`
(tables created on first use, or run `migrations/065_request_timeline.sql`).

- **Events** (`request_timeline`): append-only. Authors are `claude` (these actions), `user` (Hub page) or
  `system` (automatic, below). An event can be hidden (soft delete), not deleted. The older Notes of the
  request page (and template emails logged there) are merged in the list as read-only rows with
  `source: "notes"` and an id like `"n123"`.
- **Summary** (`request_summary`): one text per request, max 1500 characters, with `next_step` and
  `waiting_on` (`client` | `agency` | `supplier` | `us`). Each save keeps the previous text as a
  `note` event "Summary updated — previous version".
- `session_url` must start with `https://claude.ai/`. A passport-like number next to "passport /
  passaporto" gives a warning in `warnings[]` (not a block). Never write passport numbers.

`event_type`: `note`, `client_request`, `quote_sent`, `program_update`, `calc_update`, `mail_sent`,
`mail_received`, `call`, `confirmation`, `invoice`, `payment`, `supplier`, `status_change`, `issue`.

`refs` (all optional, shown as links in the Hub): `program_id`, `program_version`, `calc_file`, `invoice_id`,
`invoice_number`, `payment_id`, `mail_message_id`, `mail_box`, `memo_ext_key`, `price_total`, `currency`,
`dropbox_path`.

**Automatic events** (`author_type: system`, `source: auto`, only on real writes, never dry-runs; the
same request + type + title within 60 s is logged once; a logging error never fails the action):

| Trigger (Hub page or API) | Event |
|---|---|
| `create_request` / New Request | `client_request` "Request received" (first 500 chars of the initial request) |
| `import_group_folder` (also via `move_group_folder`) / Import Group Folder | `confirmation` "Group folder imported" (folder, status, pax, value; `refs.dropbox_path`) |
| `copy_program` / Copy programs | `program_update` "Copied program …" |
| `fill_calc` with confirm | `calc_update` (+ `refs.calc_file`, `price_total`) |
| `iti_create_personal`, "Create from sample" on a request, `iti_final_from_calc` / Final program page, `iti_publish`, `iti_document` / `iti_vouchers` saved to Dropbox | `program_update` (+ `refs.program_id`) |
| `confirm_booking` / Confirm Safari | `confirmation` "Booking confirmed — <folder>" |
| `rollback_booking` / Rollback | `status_change` "Confirmation rolled back" |
| `send_booking_email` / BackOffice booking email | `mail_sent` |
| `send_invoice_email` / invoice page Send by email | `mail_sent` "Invoice <number> sent by email" (`refs.invoice_id`) |
| `create_invoice`, `update_invoice`, `import_zoho_invoice`, invoice cancelled / restored (request invoices) | `invoice` |
| `add_invoice_payment`, `cancel_invoice_payment` (and the invoice page) | `payment` |
| `update_folder_status` / Update Folder, BackOffice status change, request status change | `status_change` |

### `timeline_list` (GET)
`request_id`*, `limit` (default 50, ≤ 500), `since` (`YYYY-MM-DD[ HH:MM]`), `types` (comma list),
`include_hidden=1`, `include_notes=0` (leave out the older Notes). → `request`, `summary`, `events[]`
newest first `{id, event_at, event_type, title, body, next_step, author_type, author, triggered_by, source,
session_url, refs, pinned, hidden}`.

### `timeline_add` (POST)
`request_id`*, `event_type`*, `title`* (≤ 200), `body`, `next_step`, `event_at` (default now, EAT; can be in
the past), `session_url`, `refs` {…}, `pinned`, `source: "cowork"` (else `api`). **Written directly — no
confirm** (append-only, can be hidden). → `event_id`, `event`, `warnings[]`.

### `timeline_update` (POST)
`event_id`*, any of `title`, `body`, `next_step`, `event_at`, `session_url`, `refs`, `pinned`, `hidden`.
System events: only `pinned` / `hidden`. Preview (`changes`) unless `"confirm": true`.

### `summary_set` (POST)
`request_id`*, `summary`* (≤ 1500), `next_step`, `waiting_on`, `session_url`, `source: "cowork"`.
Preview with `previous` and `new` unless `"confirm": true`; then → `summary`, `previous_kept_as_note`.

### `request_resume` (GET)
`request_id`, or `q` (name / folder / email, as `find_requests`; several matches → 409 with
`candidates[]`). Everything needed to pick up a practice:
`request` `{id, customer_name, email, agency, agent, status, payment_status, period, pax, value_usd,
destination, folder, group_folder, dropbox_path, date_received, confirmation_date, start_date, initial_request}`,
`summary`, `events[]` (last 20, pinned first, Notes merged), `programs[]` `{program_id, title, type, stage,
language, status, updated_at, public_link}`, `calc_files[]` (`*_Calc*.xlsx` in the folder; null without a
folder), `invoices[]` `{invoice_number, total, amount_paid, balance_due, status, …}`, `memos[]`
`{ext_key, title, status, waiting_on, due_date}`, `client_history[]` (other requests with the same email —
or, without email, the same customer name — any year / agency), `last_activity_at`.

```bash
curl -sH "$H" "$U?action=request_resume&q=Rossi"
curl -sH "$H" -X POST "$U?action=timeline_add" -d '{"request_id":2958,"event_type":"quote_sent","title":"Quote v2 sent — USD 6,450","body":"DumaShort 7n + Zanzibar 4n","refs":{"program_id":412,"price_total":6450,"currency":"USD"},"session_url":"https://claude.ai/chat/…","source":"cowork"}'
curl -sH "$H" -X POST "$U?action=summary_set" -d '{"request_id":2958,"summary":"Quote v2 sent 6 Oct …","next_step":"Send v3 with Manyara","waiting_on":"us"}'   # preview; add "confirm":true
```
Italian text with accents or "–" can trip the server firewall (406): send the body as `{"b64": "<base64 of the JSON>"}`.

**Backfill (once, optional)**: `php tools/timeline_backfill.php [--months=12] [--confirm]` over SSH seeds the
last 12 months from `requests.notes`, confirmation dates, invoices and payments (dry-run without `--confirm`;
safe to re-run).

## Example — Fiorini (TVT)

```bash
H='X-Agent-Key: <key>'; U='https://hub.savannahexplorers.com/api/agent/index.php'
curl -sH "$H" "$U?action=find_requests&q=Fiorini"
curl -sH "$H" "$U?action=list_agencies&q=TVT"
curl -sH "$H" -X POST "$U?action=create_request" -d '{"customer_name":"Patrizia Fiorini","agency_id":164,"agent":"Roberto","initial_request":"Duma Short + Zanzibar, 2 pax, Jan 2027","pax":2,"destination":"Safari & Beach"}'
curl -sH "$H" -X POST "$U?action=copy_program" -d '{"request_id":2958,"program":"DumaShort"}'
curl -sH "$H" "$U?action=get_rates&program=DumaShort&q=Zanzibar"
curl -sH "$H" -X POST "$U?action=fill_calc" -d @fiorini_calc.json                      # dry-run; add "confirm":true to write
curl -sH "$H" "$U?action=read_calc&request_id=2958"                                     # the Calc as data (read-only)
curl -sH "$H" -X POST "$U?action=confirm_preview" -d '{"request_id":2958,"start":"2027-01-19","mid":"2027-01-22","end":"2027-01-28"}'
curl -sH "$H" -X POST "$U?action=confirm_booking" -d '{"request_id":2958,"start":"2027-01-19","mid":"2027-01-22","end":"2027-01-28","confirm":true}'
curl -sH "$H" -X POST "$U?action=send_booking_email" -d '{"request_id":2958}'                  # dry-run
curl -sH "$H" -X POST "$U?action=send_booking_email" -d '{"request_id":2958,"confirm":true}'
curl -sH "$H" -X POST "$U?action=rollback_booking" -d '{"request_id":2958}'                    # dry-run
curl -sH "$H" -X POST "$U?action=rollback_booking" -d '{"request_id":2958,"confirm":true}'
```

`fiorini_calc.json`:
```json
{"request_id":2958,"pax_sheet":"2PAX","start_date":"2027-01-19","mid_date":"2027-01-22",
 "price_components":[1625,255,70],
 "days":[{"row_offset":3,"label":"Karatu-Manyara-Arusha-znz","flight":"Arusha-Zanzibar",
          "activity":{"amount":60,"desc":"transfer ZNZ airport-Riu Palace"},"hotel":"Riu Palace Nungwi - own arrangement"},
         {"label":"zanzibar","hotel":"Riu Palace Nungwi - own arrangement","repeat":5},
         {"label":"zanzibar-home","activity":{"amount":60,"desc":"transfer Riu Palace-ZNZ airport"}}],
 "guests":[{"name":"Patrizia Fiorini","title":"MRS"},{"name":"Giuseppe Saccone","title":"MR"}],
 "arrival":"ET 815 19 JAN AT 10.40 JRO (ET 737 MXP-ADD 18 JAN)",
 "departure":"ET 812 28 JAN AT 19.10 ZNZ (ET 736 ADD-MXP 29 JAN)"}
```

## Server requirement
`fill_calc` and `get_rates.program` need **PhpSpreadsheet 1.29** in the Hub root `vendor/` — see
`composer.json` (the Hub runs on PHP 8.3 since 28 Sep 2026). `save_invoice_pdf` needs **dompdf**
(same `vendor/`, already used by the invoice email). Everything else, `read_calc` included, works without them.

## Not yet
- MCP server / connector wrapper
