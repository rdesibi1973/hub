---
name: diamante-groups
description: Il Diamante 2027 Panorama groups and set departures: Calc checks, confirming a group (folder move, Hub import), staff mails, invoice, programme without prices.
---

# Il Diamante groups (Panorama della Tanzania)

Use when Il Diamante, a Panorama group (Panorama01…22, PanoramaDavide…) or a Diamante set departure comes up, or Roberto types /diamante-groups.
Reply to Roberto in Italian; staff mails in English; mails to Diamante in Italian ("tu", Buongiorno / Buona giornata).
For anything not covered here, read project memory `areas/diamante-groups` (status of each group, past mistakes).

Hub access and fixed rules: as in the project instructions (dry run first, confirm only after his ok; no mail without his ok; never passport numbers; stop and say so when something fails or doesn't add up).
API reference for the group actions: `docs/AGENT_API.md` in the Hub repo, section "Il Diamante set departures".

Memo: at the start create one `doing` memo (clear title, linked request, stable `ext_key` e.g. `work-panorama05-conferma`); update it if Roberto says "parcheggia". When Roberto says the work is finished, delete it with `memo_delete` (dry run, then `confirm`; don't leave it as done). Never close it on your own. No payment memo (SE invoice).

## 1. Where things are

- Provisional group folders: `C:\Dropbox\itineraries\SafariClassic\it\Agenzia\Diamante\2027-Groups` (`<MM>_<DDMMM>_PanoramaNN_(Diamante-PS-Roberto)_START…_END…_PROVISIONAL`).
- Diamante price files: the two tariff Excels in `C:\Dropbox\itineraries\SafariClassic\it\Agenzia\Diamante\2027`.
- Diamante operations: reservation.africa@ildiamante.com (Vincenzo, Gianfranco, Claudia); Lorenzo at Il Diamante confirms prices when in doubt. Every client booking has a Diamante REF: always quote it.

## 2. Prices — decided case by case with Roberto

- Which of the two Excels applies, and which price inside it, depends on the group and the period. Never decide it alone and never use price figures from memory.
- For each group: open both Excels, tell Roberto which file and which line/price you think applies and why (group type, pax, period), and wait for his ok before using it in the Calc or the invoice.
- If Roberto is unsure, prepare a short mail to Lorenzo (Il Diamante) asking to confirm the price (Italian, "tu"; draft as in `agency-email` §2; send only after his ok).
- Once agreed, note the price and its source (file + line) in the report, so the same case is not asked twice.
- Check the Calc against the agreed price; report differences to Roberto instead of fixing them.

## 3. Calc check (before confirming)

- Set departures include balloon, bush dinner and Masai boma; Diamante tells us only if someone skips an activity. The bush dinner is charged to Diamante but has no cost in the Calc.
- Extra services (pre-night, day use) = own Calc rows in chronological order; an extra on the same date as a group day goes into that day's row.
- Before writing any day activity, check the row date (A17 + offset): the balloon once landed one row off.
- Marera is FB: next day's lunch included.
- Panorama groups close at max 6 pax: with 5 pax only one more, as a single — keep the last room of the block.
- Ndutu: from June 2027 book Ndutu Naabi Glamping (our lodge).

## 4. Confirm a group (Agent API — no Dropbox connector, no import page)

1. `move_group_folder` dry run with `source` = the provisional folder name (or full path under `/itineraries/SafariClassic/it/Agenzia/Diamante/2027-Groups/`). It moves it to `/001_Safari/` and turns `_PROVISIONAL` into `_BALANCE` (default `new_suffix`; `new_name` only if the name must change).
   - Pax arriving before the group (pre-night): START = first arrivals' date, MIDT = group arrival date → pass the full `new_name`.
   - Pass `import` with the Hub fields (pax, value_usd, notes…) to create the Hub request in the same call.
   - Show Roberto: from → to, warnings, blocking, import preview (duplicates). Confirm after his ok.
   - Move ok but import failed (`moved: true, imported: false`) → report it and run `import_group_folder` separately; don't move the folder again.
2. Folder already in `/001_Safari/` (or import still missing): `import_group_folder` with `folder`, `pax`, `value_usd` (optional overrides). `exact` duplicate → `mode: update` + `target_id`; never `allow_duplicate` without asking. NOT `create_request`.
3. Hub request: status Booked, payment Balance.
4. Payment: no deposit — full balance due 30 days before arrival, followed with the `_BALANCE` folder status and payments.php.

## 5. Staff booking mail

- To Glady AND Lydia: operations@savannahexplorers.com (never Glady only). English. From Gmail (see `agency-email` §2).
- Subject with the folder name (no payment suffix).
- Content: group, dates, pax and rooms (exactly as per the request), lodges per night, activities (balloon, bush dinner, boma), Diamante REF.
- Priority when checking supplier bookings: make sure the rooms are still held (not released), not just deposits.
- Room-type changes (e.g. a triple) only when the group is closed; unsold block rooms decided 60 days before arrival; Tarangire Safari Lodge lunch booked only at the end, with final pax.

## 6. Invoice

`invoice-workflow`: one SE invoice to IL DIAMANTE BLU SRL, one line per item, matching the breakdown sent to Diamante, at the prices agreed in §2; due 30 days before arrival.

## 7. Programme

Final programme from the Calc (`booking-confirmation` §5), WITHOUT prices, as Word (docx). Exclusions: NIC (mandatory insurance for all visitors to Tanzania); no ZIC/Zanzibar lines for safari-only groups.

## 8. Report (Italian, short)

```
<PanoramaNN> <date> – <pax> pax – REF Diamante <...>
Prezzo proposto: <file> – <riga> = <importo> (perché) – da confermare / confermato da te / da chiedere a Lorenzo
Calc: ok / differenze: ...
move_group_folder (prova): <vecchio nome> → /001_Safari/<nuovo nome>
Import Hub (prova): duplicati nessuno / possibile #id
Mail staff: bozza pronta per operations@
Serve da te: ok su prezzo / spostare / importare / inviare
```
