# Hub Agent API (v1)

JSON API that lets Claude (Cowork / Claude Code / scheduled tasks) run the booking
workflow — new request → copy standard programme → Confirm Safari → booking email —
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
| Dry-run by default | `confirm_booking`, `send_booking_email` and `rollback_booking` do nothing unless the body has `"confirm": true`. |
| No deletes | No delete endpoint. Undo a confirm with `rollback_booking` (or BackOffice → Rollback). |

Responses: `{"ok": true, …}` or `{"ok": false, "error": "…", …}` with a matching HTTP code
(400 bad input, 403 auth, 404 not found, 409 duplicate / blocked, 422 partial, 429 rate, 5xx server/Dropbox).

## Actions

### `find_requests` (GET)
`q` (name / folder / email substring), `status`, `agent` (id or name), `year` (received), `limit` ≤ 100.
At least one filter. Use it as the duplicate check before `create_request`.

### `list_agencies` (GET)
`q` → `[{id, name, short_name, type}]`.

### `create_request` (POST)
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
`program?` (e.g. `DumaShort`), `route?` / `activity?` / `q?` (text filter), `date?` (default today).
- `program.sheets[]`: prices read from the program's **Calc template** (the single source of truth),
  per pax sheet: `rack` (H9), `sto` (H10), `single_suppl` (H11), `teen_discount` (H13), `child_discount` (H14).
- `flights[]`: `route`, `cost_pp` (rate_pax — what we pay, written in the Calc), `sale_pp` (sale_pax — price to agency) valid on `date`.
- `activities[]`: activities / transfers / safari-fixed with `cost` (rate), `sale`, `per` (pax|fixed).

The existing rates in Hub → Pricing → Jeep, Activities & Flights are **costs** (quotes add the markup on them); the new "Sale" column holds the price to the agency.

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

### `iti_programs` (GET)
`q?`, `type?` (`sample`|`personal`) → ITI programmes: id, title, language, days, published, public_url.

### `iti_texts` (GET)
`program_id`, `lang` (`en`|`it`|`fr`|`es`|`de`), `all?` → `from` (source language) and `items[]` `{key, source, target}`:
the texts to translate — programme title/route/intro, day titles and texts, activity notes, included / not included,
and the descriptions of the programme's lodges and destinations. Without `all`, only those still empty in `lang`.

### `iti_save_texts` (POST)
`program_id`, `lang`, `texts` `{"<key>": "<translation>", …}`, `overwrite?` (default false: only empty fields are written,
so edited translations are kept). Dry-run unless `"confirm": true`. Lets Claude translate programmes in a session
(no Anthropic API billing); the Hub "Translate" button does the same through the API when `ANTHROPIC_API_KEY` is set.

## Example — Fiorini (TVT)

```bash
H='X-Agent-Key: <key>'; U='https://hub.savannahexplorers.com/api/agent/index.php'
curl -sH "$H" "$U?action=find_requests&q=Fiorini"
curl -sH "$H" "$U?action=list_agencies&q=TVT"
curl -sH "$H" -X POST "$U?action=create_request" -d '{"customer_name":"Patrizia Fiorini","agency_id":164,"agent":"Roberto","initial_request":"Duma Short + Zanzibar, 2 pax, Jan 2027","pax":2,"destination":"Safari & Beach"}'
curl -sH "$H" -X POST "$U?action=copy_program" -d '{"request_id":2958,"program":"DumaShort"}'
curl -sH "$H" "$U?action=get_rates&program=DumaShort&q=Zanzibar"
curl -sH "$H" -X POST "$U?action=fill_calc" -d @fiorini_calc.json                      # dry-run; add "confirm":true to write
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
`fill_calc` and `get_rates.program` need **PhpSpreadsheet 1.29** (PHP 8.0 on BlueHost; 2.x needs 8.1)
in the Hub root `vendor/` — see `composer.json`. Everything else works without it.

## Not yet
- MCP server / connector wrapper
