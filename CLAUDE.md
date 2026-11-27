# Savannah Explorers Hub — notes for Claude Code

Internal web app of Savannah Explorers (Arusha, Tanzania) and Savannah Holidays Ltd
(Mauritius): requests/CRM, bookings in Dropbox, invoices, itineraries (ITI), operations,
Memo Board. PHP + MySQL on BlueHost, no framework. Owner: Roberto De Sibi.

Full overview: `README.md` (modules, conventions, DB tables). Some README notes are
outdated — this file wins where they disagree (PHP version, local lint).

## Where things are
- `modules/leads/` — requests, BackOffice, payments, CK, pricing, **Agent API** (`agent_api.php`,
  entry `api/agent/index.php`). Shared logic in `modules/leads/includes/`
  (`booking_service.php`, `calc_service.php`, `folder_parser.php`, `grp_status.php`, `ck_lib.php`,
  `ck_pipeline.php` = CK tracker stage / deadlines / `_CK` block over the Booking follow-up lines).
- `modules/invoices/` — invoices, credit notes. Shared logic: `includes/invoice_service.php`
  (numbering, recalculation, payments, folder status, PDF, import); `config.php` = bootstrap only.
- `modules/memo/` — Memo Board; logic in `memo_lib.php` (follow-ups, next steps, routines).
- **AI buttons of ITI** (logic `modules/iti/includes/iti_ai_service.php`, limits in ITI → Settings → AI): every new AI button calls
  `iti_ai_call()`, which checks the spending limits and records the real cost in `iti_ai_usage`; never a separate connection.
  **Pre-check + Program assistant** (since 23 Nov 2026, trial): `iti_final_precheck()` (no AI) lists on program_final.php what to fix /
  decide / map / check in the Calc; the assistant chat (Sonnet 5.5, `includes/iti_ai_chat_service.php`, endpoint `program_chat.php`,
  panel `assets/program_chat.js`, table `iti_ai_chats`) starts only with nothing to fix, proposes mappings / sample as cards the seller
  confirms (saved through `iti_final_map_text()`, same path as the page), memos Roberto on missing master data; limit per conversation
  `ai_limit_chat`. Transcripts: Settings → AI. Works for quotes in the sales phase too (`iti_final_is_quote()`: request not Booked)
  as for booked trips. Room types required on quotes too (not on a quote priced on several pax sheets); the guests' own
  arrival / departure flights are never required (bought later, also after confirmation).
- `modules/iti/` — itinerary builder. `includes/` (root) — config (server-only), auth, db, mail.
  **Shared ITI data** (logic `modules/iti/includes/iti_shared_service.php`, since 16 Nov 2026): aliases, lodges, activities,
  destinations, routes, airlines, imports and samples are changed on the Hub by an admin only (`iti_can_edit_shared()`,
  `iti_sh_guard_post()`); the Agent API acts as Roberto's agent. The staff map a Calc text for their booking only
  (`iti_alias_propose()` → `iti_alias_proposals`, approved in ITI → Aliases or `iti_alias_decide`). Every write to shared
  data is logged with `iti_sh_log()` / `iti_sh_log_row()` (`iti_change_log`, restore in ITI → History): a new writer does the same.
  **Sample of a final program** (`includes/iti_final.php` `iti_final_pick_sample()`): chosen from the Calc CONTENT (nights /
  lodges vs each sample's days), not the file name; the Calc code tag (`hub_program_code` + extra codes in `iti_sample_codes`,
  one code → one sample, set by an admin only) is a tie-break and the prices source. Sellers / Agent API override for one
  booking only (`sample_id`); no sample close enough → memo to Roberto.
  **Trekking** (since 21 Nov 2026): `safari_type` `trekking` (labels in `iti_safari_labels()`), standard rows by
  `iti_standard_inclusions.program_kind` (`ALL` / `safari` / `trekking`, `iti_trek_kinds()`); a path that sets or changes a
  programme's type to / from trekking calls `iti_trek_sync_inclusions()`. Lodge types `hut` / `campsite` have no category.
  `iti_programs.cover_photo` overrides the automatic cover (`iti_doc_day_photos()` / `iti_mag_cover()`).
- `modules/operations/` — movements, Medivac, lunch boxes, **Booking follow-up** (`booking_followup.php`; logic
  `includes/followup_service.php` + `followup_classify.php`, shared with the Agent API `followup_*` and
  `tools/followup_local_scan.php`, which reads the supplier PDFs on the office PC because BlueHost cannot).
  **`_PROVISIONAL` folders** (24 Nov 2026, private or group: no room list yet): follow-up keeps only rooms count, supplier holds /
  expiry, group and manual lines, gate holds only (`followup_prov_keeps()`); never `_CK` (`ck_pipeline_block()`, `CK_FOLDER_PROVISIONAL_MSG`).
  **`_PROGRESS` folders** (25 Nov 2026): only supplier / ticket / group / manual lines and gate lines of a refused status change
  (`followup_progress_keeps()`); the gate checks the folder when it leaves `_PROGRESS`.
  **Shorter list** (25 Nov 2026): a line closed by staff stays closed for every check until the finding changes (`followup_same_finding()`);
  one "provisional" line per supplier document (`followup_doc_id()`); `FU_PROV_UNTIL_PAID` (Heart and Soul) = provisional without expiry is no line;
  group Orangi "rooms still provisional" / SafariCheck "GRP unsold rooms" are no line before arrival − 60 days (`followup_group_rooms_wait()`).
- **Jeep demand** (`modules/operations/jeep_demand.php`, endpoint `api/jeep_demand.php`; logic `includes/jeep_service.php`, shared
  with the Agent API `jeep_demand` / `jeep_demand_day` / `jeep_demand_scan`; since 22 Nov 2026): jeeps per day = Calc JEEP ÷ Cost per
  Jeep, per `/001_Safari` folder (+ `00_YYYY` archives) in `ops_jeep_scan` / `ops_jeep_days` (keyed by Dropbox id; migration 087).
  Kept current by `ck_cron.php` (≤ 60 s per run). Reading rules = the Cowork prototype (`/Exchange/JeepUtilization/`); a change to
  them bumps `JEEP_READER_VERSION`. Phase 2 (fleet, guide assignment) not built.
- **Booking mail** (`modules/operations/booking_mail.php`, `booking_mail_cron.php` from cron-job.org; logic `includes/booking_mail_service.php`,
  `includes/passport_service.php`): headers of glady@ / operations@ crossed with the follow-up (own table, Sales Response untouched); admin opens
  one thread with a logged reason and replies from the same mailbox; passports → `passports/` + Calc after a person confirms. Off until
  `BOOKING_MAIL_LIVE`, which goes on only after the staff notice (`docs/STAFF_NOTICE_MAIL.md`). From the Agent API, mails are read
  with the `staff_mail_*` actions below (the Booking mail page actions themselves are not exposed; the reply is, as `staff_mail_reply`).
- **Staff mail** (Agent API `staff_mail_mailboxes` / `_search` / `_get` / `_attachment` / `_save_eml` (→ booking `mails/`) / `_reply`; logic `includes/staff_mail_service.php`, reusing the
  MIME parsing of `booking_mail_service.php`): any mailbox @savannahexplorers.com, straight from the Maildir, **read-only** (files never
  renamed or moved: mails stay unread; no move / delete). Only send: `staff_mail_reply` (`sm_reply()`), in the thread, from glady@ /
  operations@ only (their passwords in config.php), needs `BOOKING_MAIL_LIVE`, dry-run unless confirm. `_get` / `_attachment` need a `reason`, logged in `mail_access_log` +
  `agent_audit_log` (no bodies). Passport numbers masked, passport-named attachments withheld. Off with `STAFF_MAIL_API` false.
- `modules/security/` — mail security scan (`mail_scan.php`, CLI on cPanel cron, or `mail_scan_cron.php` from cron-job.org; logic
  `includes/mail_security_service.php`, shared with the Agent API `mail_security_status`).
- **Sales Response** (`modules/leads/sales_response.php`, CLI `sales_response_scan.php` on cPanel cron or `sales_response_cron.php` from cron-job.org; logic
  `includes/sales_response_service.php`, shared with the Agent API `sales_response_status`): seller reply times from
  Maildir **headers only** — never store or show mail bodies.
- **Dropbox conflicted copies** (`modules/leads/dropbox_conflicts.php`, nightly from `ck_cron.php` after its reply; logic
  `includes/dbx_conflict_service.php`, shared with the Agent API `dropbox_conflicts*`): copies in `/001_Safari` resolved when
  safe (trash / cell merge), else memo + mail to the CK owner. Analysis only until `DBX_CONFLICTS_LIVE`.
- **Draft mails** (`includes/drafts_service.php` in `modules/leads/`, Agent API `drafts_cleanup`, page `drafts_cleanup.php`): a booking's
  `mails/` = real mails only; `.eml` with `X-Unsent: 1` (Cowork's draft copies) move to `old/`. Readers of `mails/` skip drafts.
- **Procedures** (`modules/leads/procedures.php`, proxy `procedure_file.php`; logic `includes/procedures_service.php`,
  "?" links map `includes/procedures_map.php`): office procedure PDFs read live from Dropbox `/Operations/OfficeProcedures`
  (one copy only, cached by `rev`); `orig/` Word sources for admins only, `old/` never.
- **Booking gate** (logic `modules/leads/includes/booking_gate_service.php`, panel `assets/booking_gate.js` + endpoint
  `booking_gate.php`, worker `cron_booking_gate.php`, Agent API `gate_status` / `gate_run`): AI booking check before a folder
  leaves `_PROGRESS` (first payment tag) or `_PROVISIONAL` (any status but `_CANCELLED` / `_PROGRESS`; toward `_PROGRESS` is always free).
  Only those two sources are gated (27 Nov 2026): no tag and DEPOSIT → BALANCE / BALANCE-CASH / PAID are free (accountant).
  GRP participants carry their own sub-folder tag (27 Nov 2026): new ones `_PROGRESS` (`bo_grp_member_name()`), old untagged ones
  via Agent API `grp_member_tags` (`includes/grp_member_service.php`); a member is blocked only on its own / the group's services,
  not on another participant's (`bg_member_scope()` in the run snapshot, applied in `bg_state()`). A new path that renames a private folder out of `_PROGRESS` calls `booking_gate_check()` first and refuses
  on `refused`. `_CANCELLED` only → `_PROGRESS`, or back to `/<year>/<Customer>(<Agency>-<Agent>)` as a quote (not Booked, not a group;
  callers pass `dest_path` — `bg_reopen_quote_ok()`; Agent API `reopen_cancelled`, request page "Reopen as quote", `folder_reopen_quote()`). GRP members (24 Nov 2026): one shared check on the group parent (Calc on RECAP + the parent's invoices/ / bookings/;
  `bg_grp_member()`, `bg_group_rids()`, `bg_check_dir()`), a block over the pax is `group_block` until arrival − 60 days. Issue → blocking table `BG_ISSUES` (also in `docs/AGENT_API.md`). Enforced since 13 Nov 2026; `define('BOOKING_GATE_ENFORCE', false)` in includes/config.php = shadow mode (warns only).
- **Travel info** (Booked "✈ Travel info"; logic `includes/predeparture_service.php`, shared with the Agent API
  `predeparture_*`): pre-departure mail per language / country — Jambo, visa, NIC / ZIC, Ebola form.
- **Folder link by Dropbox id** (`requests.dropbox_id`, migration 082; logic `modules/leads/includes/folder_link_service.php`,
  Agent API `folder_resolve` / `folder_ids_backfill` / `folders_orphans` / `archive_cancelled`, admin page `relink_folders.php`):
  a folder moved / renamed outside the Hub is found by its id and the link heals itself (`folder_heal()`; status never
  changed — warning + memo). A new reader of a request's folder calls `folder_heal($db, $token, $r)` before
  `req_folder_path($r)`; a new writer that creates a request folder saves the id (`folder_id_store()`). Nightly orphan
  memo `folders-orphans` from `ck_cron.php`.
- **Status Parked** (waiting for the agency; logic `modules/leads/includes/parked_service.php`, since 20 Nov 2026): off the
  pipeline, not open, not Lost. Transitions in `bs_status_check()`; `parked_at` / `parked_by` / `parked_from` /
  `parked_wake_after` (= now on every park) set and cleared by `bs_set_request_status()` (pages writing `status` directly
  call `pk_sync_fields()`; a new park path sets `parked_wake_after` too). Wakes up by itself on a timeline mail_received /
  client_request / user note (`tl_add()` hook) and on a client mail after `parked_wake_after` **about that file** — folder name
  in the subject, same thread, direct client's own address; the agency address alone never wakes, it goes to "Possibili
  risvegli" (`pk_wake_from_mail()` / `pk_possible_wakeups()`, `ck_cron.php` → `pk_cron()`). **Auto-wake off since 21 Nov 2026**:
  only with `PARKED_AUTOWAKE_LIVE` (`pk_autowake_live()`); off, no status change / "Request reactivated" mail, the matches go to
  "Possibili risvegli" and Roberto wakes by hand. Daily auto-close report-only until
  `PARKED_AUTOCLOSE_LIVE`. `'Parked'` must be in `STATUSES` (server config).
- **GRP programme in group folder names** (logic `modules/leads/includes/grp_program_service.php`, since 20 Nov 2026): group
  parent `…_GRP{DDMM}{Duma|Simba|Nyumbu}(Agency-Agent)_START…`, programme once right after the code (DumaShort is private, not
  Duma). A new path that names / renames a group folder goes through `bo_grp_normalise()` / `bo_grp_insert()`; groups of
  another programme on the same code are different groups (`bo_grp_split()`). Report `grp_names_report` / `grp_names.php`.
- **Commercial referrers / commissions** (27 Nov 2026; logic `modules/leads/includes/commission_service.php`, page `commissions.php`,
  endpoint `commission_split.php`, Agent API `list_commercial_referrers` / `commissions_report` / `set_commission_split` / `commission_backfill`,
  migration 088 — tables also created by `comm_schema()`): referrers Promoservice 5 % (no commission on penalties), Lamprati 3 % (semester),
  Elena Perfetti 5 %. Split `[{referrer_id, rate}]` on the agency (default; NULL = from `agencies.type`) and on each agency contact
  (`referrer_from`, history `agency_contact_referrer_log`); `requests.agency_contact_id` = the consultant (matched by email on create).
  Resolution `comm_resolve()`: snapshot → contact → agency → folder tag. Frozen at confirmation (`comm_snapshot()` in `bs_confirm_commit()`
  and `gi_import()`, cleared by rollback); a new path that books a request calls `comm_snapshot()`. `agencies.type` / `-PS` stay as they are.
- **Calc sheets hidden in Excel** (24 Nov 2026) are not part of the Calc: `sc_xlsx_sheets()` (safari_check.php) leaves them out
  (`true` as 2nd arg = all), SafariCheck `parse_excel.visible_sheets()` too. A new reader of a Calc's sheet list uses these.
- `migrations/NNN_name.sql` — not applied by deploy. `docs/` — AGENT_API, FOLDER_NAMING,
  INFRASTRUCTURE, KB_Quotazioni_Safari.

**Rule:** logic used by both a Hub page and the Agent API lives in a shared service file
(`*_service.php` / `memo_lib.php`); pages and `agent_api.php` stay thin. Don't duplicate it.

## Checking changes
- No local server or DB. Lint every changed PHP file: `C:\Utility\PHP\php.exe -l <file>`.
- JS: `node --check` if available, else `python -c "import esprima; esprima.parseScript(open(f).read())"`.
- UI changes: build a static harness (page HTML + JS + mocked XHR) in the scratchpad and look at it
  in the browser pane. Say clearly what could only be tested on the server.
- **Shell commands (avoids permission prompts):** no `for`/`while` loops, `$(…)` or shell variables
  in Bash commands — they always trigger a manual approval. To lint several files, run
  `C:/Utility/PHP/php.exe -l <file>` once per file with the explicit path (several commands joined by `;` are fine);
  for anything more complex, write a script in the scratchpad and run it with
  `C:/Utility/PHP/php.exe <script>` or `python <script>`.

## Server facts
- **PHP 8.3** since 28 Sep 2026 (was 8.0). New code may use PHP 8, but match the file you edit
  (`modules/memo/` and `grp_status.php` are kept PHP-7 style).
- **MySQL, not MariaDB**: no `ADD COLUMN IF NOT EXISTS`. For new columns either a migration or a
  lazy schema function (check `INFORMATION_SCHEMA` once, then `ALTER` in try/catch — see `memo_schema()`).
- Timezone: server is US; the Hub runs on EAT via `includes/timezone.php` (PHP `Africa/Dar_es_Salaam`
  + `hub_db_timezone($pdo)` → MySQL session `+03:00`, so `NOW()` matches `date()`). It is loaded by
  `session_boot.php`/`auth.php`, `db.php`, `iti_functions.php` and, on the server, the off-repo
  `modules/leads/config.php` (top `require_once` + `hub_db_timezone()` in its `db()`). A new PDO
  connection must call `hub_db_timezone()`. DATETIME rows written before Oct 2026 by non-API pages are US time.
- AJAX/JSON handlers: `ob_start()` first so warnings don't break JSON.
- Server-only, never in git: `includes/config.php`, `modules/leads/config.php`, `api.txt` (agent key).
  Constants there: `AGENT_API_KEY`, `AGENT_API_USER`, `AGENT_MEMO_USER`, `MEMO_CRON_TOKEN`,
  `MAILBOX_USER` / `MAILBOX_PASS` (info@ over IMAP, `includes/mailbox_service.php`), Dropbox/HubSpot keys.

## Git and deploy
- Commit / push / deploy **only when Roberto asks**. Commit message ends with the Co-Authored-By line.
- Push: `git push origin main` (needs sandbox disabled). Deploy: run
  `C:\Dropbox\Exchange\Savannah-Hub\DeployHub.bat` with stdin from `/dev/null` (needs sandbox disabled).
  It POSTs the pushed commit to `deploy_hook.php` (HTTPS, no SSH / git on the server); deployed only if the
  output ends with `DEPLOY OK <sha>`. Verify: `DeployHub.bat dry` → "0 file(s) would change".
  Fallback if the hook is broken: `DeployHubSSH.bat` (old SSH + `deploy.sh`, may hit the 25-process limit;
  then verify on the server with `git diff origin/main --stat -- modules migrations`).
  Don't run `bin\hub-push.bat` from a tool — it ends on `pause` and hangs.
- BlueHost SSH often refuses repeated connections: wait and retry once.
- Deploy does not delete files on the server; removed files must be deleted by hand (the hook lists them).

## Business rules that matter in code
- **Passport numbers live only in the booking's `passports/` folder and in the Calc passport column**, written there by
  the Passports flow (`modules/operations/includes/passport_service.php`) after a person confirms each row. Never in the
  Hub DB, logs, memos, reports, chat, mail text or Agent API output; every other read of guests / CK still skips them.
- Invoices `SE-YYYY-NNNN` = Savannah Explorers; `SH-YYYY-NNNN` = Savannah Holidays, paid on the
  **AfrAsia** account (Roberto checks it, not the accountant). Old Zoho invoices keep `INV-…`.
- Dropbox: inquiries in `/2026/<Name>(<Agency>-<Agent>)`, confirmed bookings in `/001_Safari/`
  with date and payment tags (`_DEPOSIT`, `_BALANCE`, `_PAID`, `_CK` last). See `docs/FOLDER_NAMING.md`;
  `folder_parser.php` is the authority.
- Agent API: actions that move folders, send mail or overwrite data are dry-run unless `"confirm": true`;
  every call goes to `agent_audit_log`. Document new actions in `docs/AGENT_API.md`.
