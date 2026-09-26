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

## Example — Fiorini (TVT)

```bash
H='X-Agent-Key: <key>'; U='https://hub.savannahexplorers.com/api/agent/index.php'
curl -sH "$H" "$U?action=find_requests&q=Fiorini"
curl -sH "$H" "$U?action=list_agencies&q=TVT"
curl -sH "$H" -X POST "$U?action=create_request" -d '{"customer_name":"Patrizia Fiorini","agency_id":164,"agent":"Roberto","initial_request":"Duma Short + Zanzibar, 2 pax, Jan 2027","pax":2,"destination":"Safari & Beach"}'
curl -sH "$H" -X POST "$U?action=copy_program" -d '{"request_id":2958,"program":"DumaShort"}'
curl -sH "$H" -X POST "$U?action=confirm_preview" -d '{"request_id":2958,"start":"2027-01-19","mid":"2027-01-22","end":"2027-01-28"}'
curl -sH "$H" -X POST "$U?action=confirm_booking" -d '{"request_id":2958,"start":"2027-01-19","mid":"2027-01-22","end":"2027-01-28","confirm":true}'
curl -sH "$H" -X POST "$U?action=send_booking_email" -d '{"request_id":2958}'                  # dry-run
curl -sH "$H" -X POST "$U?action=send_booking_email" -d '{"request_id":2958,"confirm":true}'
curl -sH "$H" -X POST "$U?action=rollback_booking" -d '{"request_id":2958}'                    # dry-run
curl -sH "$H" -X POST "$U?action=rollback_booking" -d '{"request_id":2958,"confirm":true}'
```

## Not in v1 yet (build order §4–7 of the handoff)

- Rate tables + `get_rates` + admin page
- `fill_calc` (PhpSpreadsheet, Dropbox rev-checked upload, recalculation, post-upload verification)
- New Calc validations in Confirm preview (single pax sheet, H6:I14, F9 formula, beach-night hotels,
  flight cost vs rate table, cached values, guests/country) — the API already treats any check with
  level `error` as blocking.
- MCP server / connector wrapper
