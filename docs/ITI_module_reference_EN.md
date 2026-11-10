# ITI Module — Itinerary Builder (Savannah Explorers Hub)

> Reference document for the Itinerary Builder module, integrated into the main **Hub** project of Savannah Explorers. Same GitHub repository, same database, same `config.php`.
> Hosting, deploy, GitHub and coding conventions: see `claude/HUB_CONVENTIONS.md` (not repeated here). Updated 28 Sep 2026.
> Moved from the claude.ai Project into the repository (`docs/ITI_module_reference_EN.md`) on 10 Nov 2026: this file is now the only copy.

---

## 1. Project identity

| Item | Value |
|---|---|
| Module name | Itinerary Builder (`iti`), also called "itineraries" |
| Host project | hub (Savannah Explorers back office) |
| URL | `hub.savannahexplorers.com` |
| Database | `savannp5_savannah_leads` (shared with the hub) |
| Table prefix | `iti_` (16 tables at launch; more planned, see handoffs) |
| Config | shared: the hub's `config.php` |
| Stack | PHP 8.3, MySQL, HTML/CSS/JS integrated into the hub |
| Inspiration | Wetu, SafariOffice |

---

## 2. Purpose

Itinerary management system for a Safari DMC in Tanzania. It allows building multi-day, multi-language, multi-currency programmes, generating public links for clients, and producing branded Word exports.

---

## 3. Global conventions

These apply to **all** tables and fields in the module, except where noted otherwise:

- **Content languages:** EN, IT, FR, ES, DE — every descriptive field has five separate columns with a language suffix (e.g. `name_en`, `name_it`, `name_fr`, `name_es`, `name_de`).
- **Currencies:** USD, EUR — every price field has two separate columns with a currency suffix (e.g. `price_per_pax_usd`, `price_per_pax_eur`).

> The sections below do not repeat "×5 languages" or "USD/EUR": this is the default rule.

---

## 4. Brands and operations

**Managed brands** (ENUM `brand`: `savannah_explorers`, `orangi_collection`; the value `both` also exists in `iti_terms_conditions`):

1. **Savannah Explorers**
2. **Orangi Collection** — four properties:
   - Ngorongoro Marera Mountain View Lodge
   - Serengeti Kifaru Tented Lodge
   - Serengeti Orangi River Luxury Lodge
   - Arusha Explorers Lodge

**Geographic operations:**
- Northern Circuit: Serengeti, Ngorongoro, Tarangire, Lake Manyara, Karatu, Arusha
- Trekking: Kilimanjaro, Meru
- Extensions: Zanzibar (also Pemba and Mafia)

---

## 5. Database architecture

> **Authoritative source = the live database** (`savannp5_savannah_leads`), accessible directly from the Hub. The map below describes table structure and purpose; the DB may contain additional columns not listed here. For language/currency suffixes see section 3 (not repeated below).

### Master data
| Table | Content |
|---|---|
| `iti_destinations` | Destinations: name, description, region, country, coordinates (lat/lng), cover_photo, unique `code` (e.g. SNP, TRP, NCA) |
| `iti_lodges` | Lodges linked to a destination; `category` (budget/mid/luxury/ultra_luxury), `lodge_type` (lodge/tented_camp/hotel/mobile_camp/house), description, photos JSON |
| `iti_transfer_routes` | Road transfers between destinations: duration (the time the samples sell, not a road estimate), distance, road_type (tarmac/gravel/mixed), optional `via_destination`, `label_<lang>` (written label, else generated), notes — see **Transfer catalogue** below |
| `iti_flight_routes` | Internal flights: from/to airport + IATA, operator, flight_type (scheduled/charter), duration, notes |
| `iti_activities` | Activities per destination (NULL = generic): activity_type (game_drive/walking_safari/cultural/boat/balloon/hiking/beach/other), duration, description |
| `iti_terms_conditions` | Versioned T&C per brand, effective_date |
| `iti_standard_inclusions` | Reusable standard included/excluded list |

### Requests and programmes
| Table | Content |
|---|---|
| `iti_requests` | One request per client/group: client data, adult/child pax, dates, agent, preferred language/currency, source, status (open/quoted/confirmed/cancelled). **Not used in the real workflow** (0 records until Sep 2026); linking to Hub leads `requests` is planned (GAP 5) |
| `iti_programs` | SAMPLE or PERSONAL programme; PERSONAL ones belong to a request and track their originating SAMPLE via `sample_program_id`; `public_token` UUID for the public link; `brand`, titles/subtitles, duration_days, pax, display_language, display_currency |
| `iti_program_days` | Programme days: departure/arrival lodge, transfer route, title + narrative, meals B/L/D; unique on (program_id, day_number) |
| `iti_day_activities` | Day activities (from master) with custom notes, sort_order |
| `iti_day_flights` | Internal day flights (from master) with departure/arrival times, notes, sort_order |
| `iti_day_transfers` | Day transfers: `transfer_route_id` (+ `game_drive`) = from the catalogue, printed in every language; else free text `description` (programme language) + `description_<lang>` translations |

### Transfer catalogue (Nov 2026)
- A day transfer points to a route of `iti_transfer_routes`; documents (magazine, PDF, Word, guide sheet) print the route label in the
  document language: `label_<lang>` when written, otherwise generated from the short place names (park / conservation-area / "Town"
  words dropped), the via-point and the duration — "Karatu – Ngorongoro – Serengeti, circa 3 ore" / "…, about 3 hours" /
  "…, environ 3 heures" / "…, aprox. 3 horas" / "…, ca. 3 Stunden". `game_drive = 1`: "with a game drive on the way", no time.
- Free text is still possible (`description` + `description_<lang>`); a document in another language prints it only with its
  translation, otherwise leaves it out and lists it in `missing_texts` (type `transfer`).
- Hub: Transfers & Flights (via, labels), the day editor picks routes from the list. Agent API: `iti_transfer_routes`,
  `iti_create_transfer_route`, `iti_update_transfer_route`; day items `transfers: [{transfer_route_id, game_drive}]`.
- Code: `includes/iti_transfer_service.php`. Private safaris never print "the arrival transfer may be shared with other Savannah
  Explorers travellers"; group safaris keep it.
- The samples were moved to the catalogue on 10 Nov 2026 with the clean-up page `sample_cleanup.php` (admins).

### Prices
| Table | Content |
|---|---|
| `iti_programs.price_table_json` + `price_notes_<lang>` | **What the documents print**: rows `{label, price, currency, bold?}` and the notes per language. Filled from the Calc (final programme, or a new programme at a price level — section 6); the seller can overwrite |
| `iti_program_prices` | Legacy: categories `rack` / `sto` / `stospec` written by the editor's Prices tab, not read by any document |
| `iti_price_supplements` | Supplements per category; calc_type fixed/per_pax/percentage |
| `iti_price_discounts` | Discounts: early_bird/group/child/honeymoon/repeat/other; value_type fixed/per_pax/percentage |
| `iti_program_inclusions` | Included/excluded per programme: from `iti_standard_inclusions` or custom text |

---

## 6. Price levels (Nov 2026)

Markup on cost in the Calc templates (season folder `/itineraries/SafariClassic/it/Agenzia/2026-27/`):

| Level | Markup | Recipient | Source in the Calc pax sheet |
|---|---|---|---|
| `rack` | 25 % (D1) | Direct clients | H9 (typed) |
| `sto` | 18 % (D2) | Standard agencies / TO — **default** | H10 (typed) |
| `stospec` | 14 % (16 % on GRP files) | Partner agencies with volume | cost (B6:B8) × 1.14 ÷ pax, rounded up to 5 USD |
| `custom` | `agencies.custom_markup` | Case by case | cost × (1 + markup) ÷ pax, up to 5 USD |

- Never below 12 % (staff-commission threshold); below 14 % only case by case (confirmed groups), with Roberto's ok.
- The agency's level: `agencies.price_level` (+ `custom_markup`), Hub → Agencies → Edit; shown on the request page
  ("Price level"); a direct client (channel direct / `-Drct` folder) is always RACK. Code: `modules/leads/includes/price_level_service.php`.
- `get_rates?program=` returns per pax sheet `rack`, `sto`, `stospec`, `cost`, single supplement, teen / child reductions,
  and `sto_check` (the typed STO equals cost × (1 + D2): a false value means the template's typed prices and costs disagree).
- New personal programme from a sample with a Calc code (`iti_create_personal`, Hub "Create from sample" for a request): the price
  table at the level (2–6 pax per person) + notes in 5 languages — single supplement, teen / child reductions, high-season
  supplement (35 USD pp per night, 1–25 Aug and 24/25/26/31 Dec), "Net rates valid for travel until 31 December 2027" (ITI settings
  `price_valid_until`, `price_season_supplement`). Code: `includes/iti_price_service.php`. **Samples carry no prices.**

---

## 7. Core workflow

1. The salesperson keeps **SAMPLE** programmes without prices (the sample is the programme; the price is set case by case).
2. When a request arrives, they clone the SAMPLE into a **PERSONAL** programme inside that request — the price table at the request's level (section 6), preferred language and currency.
3. The PERSONAL programme is published: a `public_token` UUID is generated → public link `/itinerary/{token}` visible to the client **without a password**.
4. The salesperson downloads the programme (SAMPLE or PERSONAL) as a branded **Word .docx** file: day-by-day programme, prices, included/excluded, T&C.

Planned: a "final programme" generated from the confirmed booking's Calc Excel (see `claude/ITI_FINAL_PROGRAMME_HANDOFF.md`).

---

## 8. PHP file structure

All module files live under `modules/iti/` (real server path; not `hub/iti/`).

```
modules/iti/
  dashboard.php
  destinations.php
  lodges.php
  transfers.php        ← transfer routes + flight routes
  activities.php
  requests.php
  programs.php         ← sample and personal list
  program_edit.php     ← programme builder (core page)
  program_view.php     ← internal preview
  itinerary.php        ← public client page (?token=...)
  export_word.php      ← generates .docx
  includes/
    iti_functions.php
```

> Include path from `modules/iti/` to the hub root: `__DIR__ . '/../../includes/'`.

---

## 9. Operational and business rules

- **Internal flights:** always included in the programme price — never a separate cost line item.
- Sales team, staff and client-communication style (Italian "tu", Buongiorno / Buona giornata) are kept in project memory, not here, so there is one place to update when staff change.

---

## 10. Design system

Same as the Hub: see `claude/HUB_CONVENTIONS.md` §5 (`--red #C0211B`, Open Sans + Merriweather, shared classes, `iti-combo`).

---

## 11. Dependencies

- **Word export:** `phpoffice/phpword` (Composer), installed on the server.

---

## 12. Known traps

See `claude/HUB_CONVENTIONS.md` §7 (silent PHP TypeErrors, `?dry=1` carried through POST, day-swap duplicate key). Open ITI bugs (inclusions save/edit, duplicate losing child rows, request view fatal): `claude/ITI_BUGFIX_HANDOFF.md`.

---

## 13. Current state

**10 Nov 2026:** samples cleaned (`sample_cleanup.php`): shared-transfer sentence out of private samples, group sample titles,
new private sample NYUMBU SAFARI (#250, code Nyumbu), transfers linked to the catalogue (route times = the samples' times).
Price levels RACK / STO / STOSPEC per agency (section 6). Samples still have no Spanish / German day texts.

**28 Sep 2026:**

- ITI module functional, 12-file structure; Word export via `phpoffice/phpword` working, consultant bio block in Word export, public link and internal preview.
- Data imports completed: 63 destinations, 43 bidirectional transfer routes (86 rows), 4 Orangi Collection lodges; STO samples imported (`iti_import_sto.php`).
- Open bugs: see `ITI_BUGFIX_HANDOFF.md`. Next build: `ITI_FINAL_PROGRAMME_HANDOFF.md`.
- Nuru still builds final agency programmes in Wetu until ITI output is accepted.
