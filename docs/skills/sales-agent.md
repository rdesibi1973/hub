---
name: sales-agent
description: Savannah quotation for an agency or direct client: request in the Hub, standard programme, Calc pricing, ITI programme (Word/PDF), quote email draft in the client's language.
---

# Sales agent (quotation)

Use when Roberto asks to quote a request ("fai il preventivo", "quota", "prepara la proposta", "rispondi all'agenzia con i prezzi"), to change a proposal the client asked to modify, or types /sales-agent.
Reply to Roberto in Italian; client/agency texts in the recipient's language. Short: what is ready, what needs his ok.

Exceptions: `sales-agent-eccezioni.md` (same folder) holds the special cases, one section per topic (§A–§I), each rule with its date and request. This file points to the section when a step needs it: read only that section, not the whole file. A new exception goes there; a rule that becomes the normal way of working moves back here.

Hub access: `403` = `X-Agent-Key` missing or wrong (the key `api.txt` can only be read from his PC); what to do if the access to `C:\Dropbox` is refused: exceptions §A.
Hub access and fixed rules: as in the project instructions (dry run first, confirm only after his ok; no mail without his ok; never passport numbers; stop and say so when something fails or doesn't add up).
Full costing rules (Calc cells, flights, transfers, concession fees, TL groups): project memory `pricing-and-pdfs`. Read it before touching a Calc.
What the agency or the client sees (prices shown, links, names never shown, wording, exclusions): `rules-client-documents.md` in this folder. Read it before writing the programme or the email.

Memo: at the start create one `doing` memo (clear title with the folder name, linked request, stable `ext_key` e.g. `work-<client>-preventivo`); update it if Roberto says "parcheggia" (where we are + exact next step). When Roberto says the work is finished, delete it with `memo_delete` (dry run, then `confirm`; don't leave it as done). Never close it on your own. The memo covers only this session's work (e.g. "quote + reply to the agency"): later steps (waiting for the booking team's confirmations, invoice, final programme) do NOT go in its next_steps — they are done in a new session with a new memo (15 Oct 2026).

## 1. The request

- Roberto points to the mail. Agency requests often arrive in Gmail, not Bluehost: search both before saying a mail isn't there. Note the address it arrived on: the reply goes from the same one.
- Summarise: client / agency, pax (adults, teens 12–15, children under 12), dates, budget, wishes.
- `find_requests` (name, email, agency) for duplicates. A possible match → stop and show Roberto. Otherwise → `create_request` **directly with `"confirm": true`** (no dry run; Roberto, 31 Oct 2026) with agency, agent, date, pax, period, initial request, `notify_agent: true` — **always** (API default true, false for agent 14 — pass it anyway): the seller must always be notified, except Roberto De Sibi (id 14). Forgotten? Resend with `notify_request_agent`. If the API answers 409 duplicate (`dup_candidates`, also "possible"), stop and show Roberto the candidates; never add `dup_override` without his ok. Any other error: stop and say so. Report folder, `request_id` and `notify.sent`.
- Agent id, agency not in the Hub, folder naming: exceptions §B.

## 2. Programme and prices

**Availability of our own lodges** (Marera, Kifaru, Orangi Luxury, Arusha Explorers): don't check it for a proposal. The proposal goes out as it is; availability is checked at confirmation (booking team). Check the public ResRequest calendar only when Roberto asks for it — how to read it: exceptions §C (Roberto, 24 Nov 2026, Paola2paxLug27; replaces the check before every proposal).

1. Pick the closest standard programme (Duma, DumaShort, Pumba, Simba, Kiboko, Gran Safari, Migrazione…) and say why. Stance: no North Serengeti in January, Manyara not recommended, Ndutu fine as a stop near the crater.
2. `copy_program` → Word + Calc in the request folder (`NN_<folder>_<Prog>`). Pass `prognum` as a two-digit string (`"03"`): with `3` the Hub names the files `3_…` and they must be renamed (3 Nov 2026, Daniela2paxAgo27).
3. `get_rates` for programme prices per pax, flights, transfers.
4. `fill_calc` → show key values (cost, margin, D9, D10, F9) → confirm after ok → `read_calc` to verify.
   - Before the confirm check `rows[]` (B–K per day, `changed`): every lodge changed or added has `accommodation` (J); `august_supplement` is right (safari nights 1–25 August); no activity left on a template row that now holds another day; jeep (D) and drinks (E) present on every safari day. What `fill_calc` does not do and how to fix the sheet XML: exceptions §D.
5. **Price level (10 Nov 2026).** Quote at the level of the request: the request page "Price level" line, or `list_agencies`
   `price_level` — direct client → RACK 25 %; agencies STO 18 % (default) or STOSPEC 14 % (partners with volume; 14 % on GRP files too) or a
   custom markup. **Never quote below the agency's level, and never an ad-hoc markup (e.g. 10 %), without Roberto's ok; never below
   12 %** (staff-commission threshold). `get_rates` gives `rack`, `sto` and `stospec` per pax sheet; a `sto_check: false` means the
   template's typed STO and its costs disagree — tell Roberto before quoting.
   Which price goes in F9 (Price to customer): direct client → rounded D9 (rack); agency → rounded D10 at its level; STOSPEC programme → D2 = 14 %, rounded D10. Discounts, lodge changes, beach extensions, D2 in the sheet XML: exceptions §E.
6. On the first pass also:
   - keep only the PAX sheet for the pax count; clear H6:I14 when adults only;
   - round up to the next multiple of 5; teen/child prices are NOT rounded;
   - ZNZ/Pemba transfers per car (not per person) in F10, after the per-person total;
   - flights in col C (Auric + fuel surcharge); in-park camps with concession fee in the accommodation column; August supplement only on safari nights.
   - Teens and children: discounts in H13 / H14 (teen discount in H13 with label in I13) written AFTER the last `fill_calc`, subtracted in F10: exceptions §E.
   - Costs of specific suppliers and routes (transfers, flights, Mvuvi, Mafia, Mkomazi, festive supplement…): exceptions §F. Never take a transfer cost from `get_rates` without checking the route. Kilimanjaro for 1 pax: exceptions §G.
7. Calc not open in Excel on his PC; wait for Dropbox sync before the next Hub action. Writing a file back to his PC (staging names): exceptions §D.

## 3. Client programme (ITI, Magazine layout)

1. `iti_samples` → closest sample; tell Roberto which and why.
2. `iti_create_personal` with `sample_id`, `lead_request_id`, fields: `ref_number` = Calc name without `_Calc` (e.g. `01_MarcoCiaolo(OceanoPoint-Roberto)_PumbaSafari`), `title_<lang>`, `start_date`, `pax_adults`, `pax_teens`, `pax_children`, `display_language`. Prices: with a sample that has a Calc code, the price table (2–6 pax per person) and the notes (single
   supplement, teen / child reductions, high-season supplement, "net rates valid for travel until 31 December 2027") are filled at the
   request's level by themselves — check `prices` in the dry run (`price_level` overrides it; `none` = no table). When the client's
   Calc has its own price (changed lodges, extras), pass `price_table_json` (`[{label, price, currency}]` from the Calc) and `price_notes_<lang>` instead.
3. Client changes → `iti_update_day` (texts, lodge with `end_lodge`, meals, `room_type`) / `iti_update_program`.
4. Check with `iti_program`: days, lodges, dates, prices; flag days without photo or text (lodge photos from the lodge's own website when missing). Destinations and lodges without a description in the programme language print in English: check them (`iti_destinations` / `iti_lodges` with `missing=description_<lang>`) and write the missing texts with `iti_update_destination` / `iti_update_lodge` (dry run first) before generating the file.
   - Lodge not in the Hub: exceptions §H.
5. `iti_document`: `docx` for agencies, `pdf` for direct clients; saved as `<ref_number>.docx/.pdf` in the request folder. The Word that `copy_program` put in the folder (template copy, same name) is always replaced by the ITI one: save the docx with `overwrite: true` without asking (15 Oct 2026); for any other existing file, on 409 ask first. Regenerate after every change.
6. `links.public` is the client link (always on for personal programmes; `iti_publish` is only for samples). Agencies never get it, direct clients do: `rules-client-documents` §2.
Other cases (programme without prices, Kilimanjaro / Meru trekking, programme straight from the Calc, import from a partner such as Kenya): exceptions §H.

Programme content (no airline or balloon operator before booking, group / private wording, Italian programme rules, exclusions): `rules-client-documents` §3–§5.

## 4. Quote email

Where the draft goes, subject, greeting/closing, signature and general style: follow `agency-email` (§2–§4). Quote-specific content:
- One sentence on the safari (length, private jeep, Italian-speaking guide, parks) + day-by-day list with dates, overnight lodge, meal plan.
- "Prezzi:" list, each line "usd per persona" (agencies: quota netta, base N pax); Totale per persona; transfers per car on their own line; totale pratica; single supplement as a separate total (e.g. "Totale pratica in doppia + singola").
- "La quota include:" ("trasferimenti durante il safari"…) / "Non include:" (the exclusions of `rules-client-documents` §5; beach transfers named precisely…).
- Domestic flights: "Le tariffe e disponibilità dei voli interni sono soggette a disponibilità al momento della prenotazione".
- Prices, links, names never shown, group / private wording, "program" in English: `rules-client-documents`.
- Only when the programme is attached: "Programma safari dettagliato allegato (considera la quota netta per il gruppo di N pax)." Group departures (GRP): "Programma safari dettagliato allegato (quota netta per persona)." (23 Oct 2026, DiCredico).
- Group programmes: before attaching, check the group wording (`rules-client-documents` §4).
- An agency sends us a programme we already quoted to its client as a direct: Roberto evaluates case by case — don't answer with prices before his decision (16 Oct 2026).
- Don't mention international flights unless asked; don't offer to "verify availability and block everything" on a loose ok.
- Mafia: flight wording and exclusions in `rules-client-documents` §5.
- Group departures (GRP) wording, beach extension on a group, single travellers, reply headers: exceptions §I.

## 5. Report (Italian)

```
Preventivo <cartella>
Programma: <nome> (sample #id, perché)
Prezzo: <NNNN> usd per persona base N pax (Calc <file>) – margine <...>
File: <ref_number>.docx/.pdf salvato
Mail: bozza Exchange/Draft-mail/<file>.eml da <indirizzo> – Re: <oggetto>
Memo: <titolo> (doing)
Serve da te: ok su prezzo e testo
```
