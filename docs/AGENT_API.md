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
| Dry-run by default | `confirm_booking`, `send_booking_email`, `rollback_booking`, `rename_folder`, `cancel_invoice_payment`, `update_folder_status`, `import_zoho_invoice`, `create_invoice`, `update_invoice`, `mail_send` and `mail_move` (among others) do nothing unless the body has `"confirm": true`. |
| No deletes | No delete endpoint (mail included). Undo a confirm with `rollback_booking` (or BackOffice → Rollback). |
| Mailbox | The key also reads `info@` (`mail_*`): keep `api.txt` as private as the mailbox password. |

**Server firewall (Mod_Security) — always send `User-Agent: Mozilla/5.0 (compatible; SavannahHubAgent/1.0)`.**
BlueHost's firewall scores requests: the default `curl/…` or `Python-urllib/…` user agent from a cloud IP starts with a
high score, and then even plain calls (`iti_lodges&q=Mdonya`) can cross the threshold and get HTTP 406, seemingly at
random (access log, 4 Oct 2026: 9 of ~110 curl calls blocked, 0 of the calls with the agent user agent). Many 406 in a
row can block the IP for a few minutes. With curl: `-A "Mozilla/5.0 (compatible; SavannahHubAgent/1.0)"`.
If a call is still blocked: send JSON with `\uXXXX` escapes (`ensure_ascii`), or wrap the body as
`{"b64": "<base64 of the UTF-8 JSON>"}` (GET: `&b64=<base64 of a JSON object of the parameters>`), wait a minute, retry once.

Responses: `{"ok": true, …}` or `{"ok": false, "error": "…", …}` with a matching HTTP code
(400 bad input, 403 auth, 404 not found, 409 duplicate / blocked, 422 partial, 429 rate, 5xx server/Dropbox).

## Actions

### `find_requests` (GET)
`q` (name / folder / email substring), `status`, `agent` (id or name), `year` (received), `limit` ≤ 100.
At least one filter. Use it as the duplicate check before `create_request`.
Each row also has `last_activity_at` (latest timeline event, summary or note) and `has_summary` — see
[Request timeline](#request-timeline).

### `list_agencies` (GET)
`q` → `[{id, name, short_name, type}]`.

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
then the DB row. A likely duplicate → **409** with `dup_candidates`; resend with `"dup_override": true`
only if it is really new.

### `update_request` (POST)
`request_id` + any of `customer_name, email, whatsapp, source, destination, period, pax, value_usd,
commission_pct, date_paid, initial_request, notes` (top level or inside `fields`). `commission_usd`
is recomputed. Status / folder are **not** editable here (use `confirm_booking` / BackOffice).

### `list_standard_programs` (GET)
Program codes by group (`DumaShort`, `BeachDumaShort`, …) and the Confirm Safari `destinations`.

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
| `pax_sheet` (or `adults`/`teens`/`children`) | all other sheets deleted (names with trailing spaces handled) |
| — | H6:I14 cleared |
| `price_components` `[1625,255,70]` | F9 = `=1625+255+70` (never a hardcoded total) |
| `start_date` | A17 = date; A18+ `=SUM(A{n-1}+1)` extended to the last day, same format |
| `days[]` | from row 17 + `row_offset`; each: `label` (B), `flight` = route id/name → C `=<cost_pp>*$B$1` from the rate table (or `flight_cost_pp`, warned if not in the table), `activity` `{rate: name/id}` (cost from table) or `{amount, desc}` → H/I, `hotel` → K, `repeat` n |
| `guests[]` `{name,title,country}` | rows 43+ A/B/F, upper case; country ITALY inferred for -PS/-LAM agencies |
| `room_type` | A37; default `1 DBL` for MR+MRS |
| `adults_teen_chd` | A35; default "2 adults" |
| `arrival`, `departure` | A51, A56 |
| `mid_date` | first beach night, for the hotel-on-every-beach-night check |
| `file` | which Calc if the folder has several |

Returns `verify`: `passed`, `checks[]` (see below), `price_to_customer`, `total_price`, `total_costs`,
`margin` (B10), `to_price_pp` (D10). HTTP 422 if a check has level `error`.

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
  marked → 422 "not finalised". Group calcs (RECAP) → 422.
- `flight_cost` is the saved FLIGHTS amount of that day (e.g. `=215*$B$1` → 430 for 2 pax).

### Calc checks (also in Confirm preview, UI + API)
`error`: more than one pax sheet · a beach night (≥ MIDT) without hotel · formula cells with no saved
value (Hub would read End = Start). `warn`: H6:I14 not empty · F9 not a formula · flight cost not in the
rate table / not `=cost*$B$1` · other nights without hotel · guests / country / title / room type missing.
`confirm_booking` blocks on `error` unless `"force": true`; the BackOffice shows them (red ✖) but never blocks.

### `confirm_preview` (POST)
`request_id`, `start`, `end`, optional `mid`, `mid2`, `dest`, `grp` (`NONE`|`CREATE`|`ADD`),
`grp_code` (DDMM), `grp_main`.
Dates: ISO `2027-01-19` or Hub format (`19JAN`; `end` = `28JAN2027`). `dest`: a label from
`list_standard_programs.destinations`, or its suffix (`ZNZ`, `TREK`, `KENYA`…); empty = Tanzania safari.
Returns `new_name`, `checks` (Excel dates vs folder dates, flights, GRP) and `blocking`.

### `confirm_booking` (POST)
Same input + `"confirm": true`. Without it → dry-run (= preview). With blocking issues → 409
unless `"force": true`. Moves the folder to `001_Safari`, renames it
`MM_DDMON_<folder>_STARTddMON[_MIDTddMON]_ENDddMONyyyy_PROGRESS`, status → Booked,
snapshot stored for Rollback.

### `send_booking_email` (POST)
`request_id` (must be Booked), optional `to[]`, `cc[]` (replace the template lists),
`cc_remove[]` (drop addresses from the template CC), `subject`, `body_extra` (inserted before
"Thanks"), `sender_name`. Without `"confirm": true` → returns the email that *would* be sent.
Signed with the request agent's name, signature and Reply-To.

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
`request_id`*, `new_name`*, `confirm`.

- **Without `"confirm": true` → dry run**, nothing changes. Returns `current`, `new_name`, `is_group`,
  `status_from_name` `{matched, status, payment_status, current_status, current_payment_status}` (what the
  new suffix sets: `_DEPOSIT` → Booked/Deposit, `_PAID` → Booked/Paid, `_CANCELLED` → Cancelled …; no
  status tag → status untouched; a group sets the status only), `dropbox_path_old`, `dropbox_path_new`,
  `warnings[]` and `calc_dates` `{start, end}` (the `*_Calc.xlsx` itinerary dates, or null).
- **With `"confirm": true`** → renames the Dropbox folder, updates `practice_code` / `group_folder`,
  `dropbox_url`, `status` / `payment_status` (when the suffix matches), the CK tracker, and logs a
  `status_change` timeline event (on every request of a group). Same fields + `renamed: true`.
- **Errors (nothing changed):** 404 request not found · 400 no folder, empty name, invalid characters
  (`\ / : * ? " < > |`), identical name · 409 folder not found in Dropbox (stored path, then search), destination folder
  already exists · 502 Dropbox/DB failure.
- **Warnings (dry run, never blocking):** date tag malformed (`START29OC`), START / MIDT / END not in
  order (e.g. END before START) or spanning > 60 days, `_CK` not last, `MM_DDMON_` prefix ≠ START,
  `_CK` / status tag / START / END dropped from the current name, another request already using the name,
  Calc start/end ≠ the new name's START/END.

```bash
curl -sA "Mozilla/5.0 (compatible; SavannahHubAgent/1.0)" -H "$H" -X POST "$U?action=rename_folder" \
     -d '{"request_id":873,"new_name":"02_10FEB_Rossi(BTG-Roberto)_START10FEB_END16FEB2027_DEPOSIT"}'                  # dry run
curl -sA "Mozilla/5.0 (compatible; SavannahHubAgent/1.0)" -H "$H" -X POST "$U?action=rename_folder" \
     -d '{"request_id":873,"new_name":"02_10FEB_Rossi(BTG-Roberto)_START10FEB_END16FEB2027_DEPOSIT","confirm":true}'
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
folder is `<ref_number>.docx` / `<ref_number>.pdf` / `<ref_number>_Guida.pdf`. `ref_number`: max 60 characters (the column),
no `\ / : * ? " < > |`. `iti_create_personal` sets it from the folder; `iti_update_program` changes it.

#### `iti_samples` (GET)
`q?` → `samples[]` `{id, code (Calc code), title, route, language, days}`.

#### `iti_program` (GET)
`program_id`, `lang?` → `ref_number`, header (title, subtitle, intro, start_date, pax, prices, included / excluded), `days[]`
(`day, date, title, destination, lodge, meals, transfers, activities, narrative, lodge_photos, dest_photo`), `terms` and `links`
(`preview`, `edit`, `word`, `pdf`, `guide` (personal only, else null), `public` — `word` / `pdf` / `guide` need a Hub login).
`terms` = the T&C the documents print: `{variant (direct | agency | custom), terms_id, name, source (program = chosen on the
program | request = from the linked request | default), request_variant}`.

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
`display_language`, `display_currency`, `price_table_json` (`[{label, price, currency, bold?}]`; `bold: true` prints the row in bold — a row starting "Totale pratica" / "Total booking" is bold unless `bold: false`), `price_notes_<lang>`.
Copies the sample (days, activities, prices, inclusions) as a draft proposal, with the T&C of the request's client type
(`fields.terms_variant` = `direct` | `agency` forces one). Dry-run unless `"confirm": true` (→ plan with `terms_variant`,
`ref_number` and `ref_source`); confirmed → `program_id`, `links`, `terms` and the full `program`.
**ref_number:** `fields.ref_number` wins. Otherwise, with `lead_request_id`, it is the name (no extension) of the programme
Word `copy_program` put in the request folder (`NN_<folder>_<Prog>Safari.docx`) when there is exactly one; `calc_file`
(e.g. `01_…_Pumba_Calc.xlsx`) picks the Word of that Calc (same `NN_` and programme). None or several → **409**, nothing
created, with `word_candidates`, `calc_files`, `folder` and `hint`: resend with `fields.ref_number` or `calc_file` — never guess.

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
`iti_inclusions` → the standard rows `{std_id, text_<lang>}` by `included` / `excluded`.
`iti_update_inclusions`: `program_id`, `included[]` and / or `excluded[]` — items `{std_id}`, `{text_<lang>…}` or a
plain string (program language). Each list given replaces that list in one transaction; an empty list is refused.

#### Master data: `iti_flight_routes`, `iti_activities`, `iti_transfer_routes` (GET) and `iti_create_…` (POST)
- `iti_flight_routes` `q? from? to?` (airport name or code); `iti_create_flight_route` `fields`: `from_airport`*,
  `to_airport`*, `from_code`, `to_code`, `operator`, `flight_type` (`scheduled`|`charter`), `duration_min`, `notes_<lang>`.
- `iti_activities` `q? destination?`; `iti_create_activity` `fields`: `name_en` / `name_it`*, `activity_type`
  (`game_drive`|`walking_safari`|`cultural`|`boat`|`balloon`|`hiking`|`beach`|`other`), `destination_id` | `destination`,
  `description_<lang>`, `duration_hours`.
- `iti_transfer_routes` `from? to?`; `iti_create_transfer_route` `fields`: `from_destination`*, `to_destination`*
  (id, name or code), `duration_min`, `distance_km`, `road_type` (`tarmac`|`gravel`|`mixed`), `notes_<lang>`.
Duplicates are refused with the existing id. Dry-run unless confirm.

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
`missing?` (`photos` | `coords` | `website` | `description_<lang>` | `placeholder`), `limit?` ≤ 300 →
`lodges[]` `{id, name, destination_id, destination, category, type, website, latitude, longitude, photos[], description_langs[], active}`.

#### `iti_lodge` (GET)
`lodge_id` → the same plus `phone, emergency_phone, email, address, description_<lang>` (all 5).

#### `iti_destinations` / `iti_destination` (GET)
`q?`, `active?`, `missing?` (`photo` | `coords` | `description_<lang>` | `placeholder`) → `destinations[]`
`{id, code, name, region, latitude, longitude, cover_photo, lodges, description_langs[], active}`;
`iti_destination` (`destination_id`) adds `name_<lang>` and `description_<lang>`.

`missing=description_<lang>` also lists texts that are only the website blurb ("Savannah Explorers - Tour Operator for Safari
in Tanzania…"); `missing=placeholder` lists rows with that blurb in any language. The documents never print it (they fall back
to another language or leave the box out).

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
description_<lang>, latitude, longitude`. Destination: `name_<lang>, description_<lang>, region, latitude, longitude`.
Returns `changes` `{field: {from, to}}` (only what differs). Dry-run unless `"confirm": true`.
Name, destination and category of a lodge stay on the Lodges page.

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
Renders the PDF server-side (Dompdf — the same layout as the emailed invoice) and uploads it as
`Invoice <number>.pdf` (the name *Invoice Check — Dropbox* looks for) into the booking folder
(GRP client: the client sub-folder). `overwrite` (default false: 409 if the file exists; Dropbox keeps
the old version when overwritten), `folder_path` (optional full Dropbox path, overrides the folder).
Returns `path`, `size`, `rev`, `overwritten`.

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

### `memo_set_status` (POST)
`id` or `ext_key`, `status` (`open`|`doing`|`waiting`|`done`|`archived`), `note` (appended when done).
`done` opens the next steps → `opened_next[]`.

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

## Mailbox info@ (IMAP)

Lets Claude read and answer `info@savannahexplorers.com` without logging in to the BlueHost
webmail. Logic: root `includes/mailbox_service.php` (PHP imap extension, on BlueHost). The mailbox
is on the same server, so IMAP goes to `localhost`.

**Setup (server, once)** — in the server-only root `includes/config.php`:
```php
define('MAILBOX_USER', 'info@savannahexplorers.com');
define('MAILBOX_PASS', '<mailbox password>');
// optional: define('MAILBOX_IMAP', '{localhost:993/imap/ssl/novalidate-cert}');
// optional: define('MAILBOX_NAME', 'Savannah Explorers');   // From name
// optional: define('MAILBOX_SENT', 'INBOX.Sent'); define('MAILBOX_DRAFTS', 'INBOX.Drafts');
```
Without them every `mail_*` action answers 503. If the mailbox password is changed in cPanel,
update `MAILBOX_PASS` too (calls then fail with "IMAP login failed").

Messages are addressed by `folder` (default `INBOX`; BlueHost names: `INBOX.Sent`, `INBOX.Archive`, …)
+ `uid`. Reading never marks a message as seen unless `mark_seen: true`. There is no delete.
The audit log keeps who/subject for `mail_get` / `mail_attachment`, not bodies or files.

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
From `info@`. `to`, `cc`, `bcc` (string or list), `subject`, `body` (plain text) or `body_html`,
`attachments[]` `{name, content_base64}` (≤ 10 MB each). For a reply give `reply_to_uid` (+ `reply_folder`):
`to` defaults to the sender (Reply-To), `subject` to "Re: …", and the reply is threaded (In-Reply-To / References).
- `mail_draft` saves it in **Drafts** — nothing is sent; Roberto sends it from webmail / phone.
- `mail_send` is a dry-run (→ `email` preview) unless `"confirm": true`; then it sends, keeps a copy in
  **Sent** and marks the original as answered.
- Optional `request_id`: a confirmed `mail_send` is logged in that request's timeline (`mail_sent`,
  → `timeline_event_id`). Drafts are not logged.

```bash
curl -sH "$H" "$U?action=mail_list&unseen=1&limit=20"
curl -sH "$H" "$U?action=mail_get&uid=48213"
curl -sH "$H" "$U?action=mail_attachment&uid=48213&part=2&request_id=2958"        # → booking folder
curl -sH "$H" -X POST "$U?action=mail_draft" -d '{"reply_to_uid":48213,"body":"Dear Anna,\nthank you …"}'
curl -sH "$H" -X POST "$U?action=mail_flag"  -d '{"uids":[48213,48214],"seen":true}'
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
| `create_request` / New Request / Import Group Folder | `client_request` "Request received" (first 500 chars of the initial request) |
| `copy_program` / Copy programs | `program_update` "Copied program …" |
| `fill_calc` with confirm | `calc_update` (+ `refs.calc_file`, `price_total`) |
| `iti_create_personal`, "Create from sample" on a request, `iti_final_from_calc` / Final program page, `iti_publish`, `iti_document` / `iti_vouchers` saved to Dropbox | `program_update` (+ `refs.program_id`) |
| `confirm_booking` / Confirm Safari | `confirmation` "Booking confirmed — <folder>" |
| `rollback_booking` / Rollback | `status_change` "Confirmation rolled back" |
| `send_booking_email` / BackOffice booking email | `mail_sent` |
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
