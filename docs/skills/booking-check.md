---
name: booking-check
description: Verify a booked safari: every service of the Calc (stays, flights, transfers, activities) against the invoices and confirmations the booking team saved in Dropbox; write booking-check.html in the safari folder.
---

# Booking check

Use when Roberto asks to verify the bookings of a safari ("verifica le prenotazioni", "controlla la pratica X", "è tutto prenotato?"), or types /booking-check. One safari per run; for a list (e.g. "le partenze di novembre") run it per safari and give a short summary at the end.
Which bookings: when Roberto asks for a round ("fai i CK", "cosa c'è da controllare") take them from `ck_status&needs_check=1` and give the reason for each (`next_check_reason`: `first_check` right after the bookings are done, `recheck_60` / `recheck_30` before arrival), nearest arrival first; not by month (25 Oct 2026).
Reply in Italian. The report file is in English (the booking team, Glady and Lydia, act on it).

This is a CHECK: never edit the Calc (only exception, with Roberto's ok: flight details pasted as screenshots — see §4), never move, rename or delete files, never send mail, never read mailboxes. The evidence is only what the booking team saved in Dropbox: invoices / service vouchers in `invoices/`, transfer confirmations (mails) in `bookings/`. Missing there = missing.

**Passport numbers: supplier documents often contain them (reservation notes, guest lists). Never copy them into the report or the chat, not even partially.** Guest names are fine; leave out dates of birth.

## 1. Data from the Hub

`find_requests` → `request_id`, then `booking_check` (GET). It returns:
- `local_folder` (`C:\Dropbox\001_Safari\…`): read the files there, write the report there;
- `calc`: file, sheet, pax `{adults, teen, child}`, `room_config`, `arrival`, `departure`, guests, warnings;
- `services[]` in date order: `accommodation` (consecutive nights in one hotel: `check_in`, `check_out`, `nights`, `own`, `calc_invoice[]`, `calc_checked[]`), `flight` (`date`, `route`, `cost`), `transfer` / `activity` (`date`, `text`, `route`). In-house items (emergency, medivac, lunch boxes, hot lunch, Maasai visits, Napuro waterfalls, Lake Eyasi activities — arranged by the safari guide —, Olduvai — paid with the Ngorongoro permit, church visit / city tour on Mafia) are already left out; activities have `own` (run by our lodges);
- `evidence[]`: every file in `invoices/` and `bookings/` (`scope` `group` = the GRP folder's), `old` = inside an `old…` sub-folder.

422 "several pax sheets, none marked CONF": look at the sheet names. Two real parts of one trip (e.g. `16PAX` + `2PAX ZNZ`) → call once per `sheet` and check both; old quotes left next to the confirmed one → say so and stop (the Calc must be cleaned first). No Calc → say so and stop.

Calc sheet rule (Roberto, 25 Oct 2026): first the RECAP sheet (for GRP it is the one that counts), then the CONF sheet when the Excel has several worksheets — CONF on one sheet only, the one in use; otherwise the Excel must have a single worksheet. Two or more sheets marked CONF → don't check: say so and draft a mail (skill agency-email) asking the seller of the folder to keep CONF only on the sheet in use and remove it elsewhere. A Calc found only as a Dropbox conflicted copy ("… conflicted copy …" / "Copia in conflitto …") with no main file → the Hub can't read it: tell Roberto and, with his ok, rename the latest copy to the normal Calc name (older versions stay in `old/`).

## 2. Read every evidence file

Here the PC is needed, not the Dropbox connector (it can't read Excel / Word reliably nor write the report): ask access to `C:\Dropbox` once at the start of the session, never to sub-folders. Open each file in `local_folder` (and the group folder for `scope: group`): PDF, Word, Excel, `.eml` / `.msg` (body + quoted thread), images. File names are unpredictable: identify a document by its content, never by its name. Files with `old: true`: don't use them, list them as ignored.

From each document note: supplier / property, kind (invoice, pro-forma, service voucher "SV", reservation summary, confirmation mail), status (Confirmed / Provisional / Cancelled / just "noted"), service dates (arrive / depart, flight or transfer date), nights, rooms and room type, meal plan, pax (adults / children), guest or booking name, amount and currency, reference number.

## 3. Match documents ↔ services

- One document can cover several services, and one service can have several documents:
  - The Orangi Collection SV covers all our own lodges of the trip (`own: true`: Arusha Explorers, Marera, Orangi, Kifaru), one line per night block.
  - One airline invoice can cover several legs (Kogatende → Arusha → Zanzibar), one transfer mail all the transfers.
- Calc names are short and often differ from the supplier's: "Mara River" = Serengeti River Camp (Karibu Camps), "Embarakai NCAA", "Pure Mara" = Pure Migration… Match on dates + nights + pax first, then the amount: the INVOICE column (`calc_invoice`, on the first night of the block) usually holds the supplier's total ("Invoice $1660").
- A document of another client or another date = wrong file in the folder: flag it.

## 4. Checks per service

| Service | Must match |
|---|---|
| Stay | check-in / check-out (nights), pax adults + children, rooms vs `room_config`, meal plan when the Calc states it (HB/FB), status confirmed; the document must also agree with itself: nights in the header = arrival → departure = quantity on the invoice lines (Butiama PRO 2026/AUG/1147: header 6 nights, lines billed 5 → CHECK) |
| Flight | date, route (all legs), pax, status; times consistent with `calc.arrival` / `departure` for the first / last leg |
| Transfer | date, pick-up / drop-off, the flight it meets (vs `arrival` / `departure` and the flight invoices); a reply like "noted" / "confirmed" counts as confirmation |
| Activity | a supplier booking (balloon, Mto wa Mbu walk, Materuni…): a document with the date and pax. Activities of our own lodges (`own: true`: "Natural Walk" = Marera Walking and Iraqw families; bush dinner at Orangi, also when the text is just "Bush dinner" on an Orangi night) → "in-house", no evidence needed, but note it if the SV doesn't mention it |

GRP folders (group departures): a group block with more rooms or flight seats than the pax of the folder is normal (they are held for other participants, e.g. 3 doubles for a couple, or a return leg nobody of this folder flies). Rule: unsold rooms / seats are released 60 days before arrival. Before that date write only an info note ("group block: N rooms / seats over the folder's pax, release date <arrival - 60 days>") and do not count it as CHECK; from 60 days before arrival mark it CHECK with "release unsold rooms / seats".

Amount — invoiced must be ≤ quoted (Roberto, 26 Oct 2026). Rules in "Invoice vs Calc" below.

### Invoice vs Calc (amount check)

Compare, per service, the supplier's NET amount (after its commission line, e.g. Butiama "COMMISSION 20%") with the Calc cost of the same service: stays → column J of the night block (sum of its nights), flights → column C, activities → column H, transfers → the column the Calc uses on that row. `calc_invoice` ("Invoice $…") is what the booking team wrote, not the quoted cost: use it only as a hint.
- One invoice ↔ several Calc rows / blocks (Roberto, 26 Oct 2026): group by supplier, not by row. The beach nights (Zanzibar, Mafia, Pemba: one row per night, sometimes the cost on the first row only), or the same lodge twice in the trip (Karatu / Marera before and after the Serengeti, Arusha at the start and at the end) are usually ONE supplier invoice. Compare the invoice with the SUM of all the Calc rows of that supplier that fall inside the invoice's dates, and its nights with the total nights of those blocks (each block's check-in / check-out must appear on the invoice). Only when the supplier sent separate invoices per block compare them block by block. The +5 USD tolerance is per invoice.
- **Invoiced ≤ Calc cost + 5 USD → OK.** Lower is normal (the Calc often overestimates: Mtoni 320 vs 460): no finding, mention the saving only in the report.
- **More than Calc cost + 5 USD → CHECK**, finding `invoice_mismatch` (`audience: booking`), text "invoice <amount> vs quoted <amount> (+<diff>)" and the likely cause.
- Concession fee (in-park / Serengeti camps: 71 USD pp per night + 1% levy) and WMA fee (33 USD pp per night + levy) are inside the Calc accommodation cost: when the supplier bills them on a separate document or line, add them up before comparing. A lower contract figure (e.g. 59) is fine.
- Beach hotels with commission (Butiama: rack − 20%): compare the net.
- Auric / domestic flights: the Calc includes the fuel surcharge; compare with the invoice total, fuel included.
- Single supplement: the Calc day rows are costed in double; the single supplement written above the cost table (H6:I14 block, e.g. "single supplement (only dbl+sgl)") is extra money for the single room. When the booking has a single, the stays may exceed the Calc cost by up to that amount in total (whole trip, not per stay) + 5 USD.
- Child / teen discounts (above the cost table, e.g. H13 "teen discount", child price): the Calc rows cost everyone as adults and the discount lowers the client price. The amount check stays against the adult cost, but the lodge must bill the children / teens at its child rate: children billed as adults on a stay → CHECK (we gave a discount we are not getting).
- August (1–25 Aug) and festive (24, 25, 26, 31 Dec) supplements charged by a lodge must be in the Calc cost too; if they are not → CHECK.
- Own lodges (Orangi Collection SV, `own: true`) and in-house items: no amount check.
- Currency other than USD: info only (no conversion).

Domestic flight tickets are saved in `flights/` and are often issued only a few weeks or days before arrival. A flight correctly booked (confirmed invoice / pro-forma, right date, route and pax) with no ticket yet is NOT an error: info "ticket not issued yet — checked by the weekly ticket round (back-office §6)". If the ticket is there, check names, date, flight and times against the Calc.

Client's own international flights (booked by the client, no flight cost in the Calc): clients usually do not send us their tickets, so no ticket in `IntFlights/` / `flights/` is normal, never an error. Check only the dates: arrival / departure date in `calc.arrival` / `calc.departure` = first / last day of the Calc and of the Word program → OK. Different dates → CHECK. Times are checked only when the first night / last night is in Karatu, Manyara / Mto wa Mbu or Tarangire (the drive to / from the airport is long and we never drive in the dark — daylight about 06:30–18:30): drive 2.5 h to / from Arusha (ARK / Arusha town), 4 h to / from JRO (Kilimanjaro). Arrival: landing + about 1 h (international) / 30 min (domestic) + drive must reach the lodge before 18:30. Departure: leaving the lodge not before 06:30 + drive must reach the airport by check-in (international 3 h, domestic 90 min before departure). Same rule for domestic flights leaving from ARK / landing at ARK on a day that starts or ends in those places. Not feasible → CHECK with the times (e.g. "lands JRO 17:30, 4 h to Karatu → after dark: add a night in Arusha"). Never compare with the client's first request or booking form (`CustomerInfo.txt`): the client may have changed flights since; the Calc holds the latest data. Empty ARRIVAL / DEPARTURE details → CHECK "ask the client for the flight details".

Flight details pasted in the Calc as screenshots (pictures under ARRIVAL / DEPARTURE DETAILS, so `calc.arrival` / `departure` come back empty): read the pictures and, with Roberto's ok, replace them with text rows in the usual format (`TK 1322 01JAN BLQIST 1055 1535`, one leg per row, PNR at the end) by editing the xlsx XML in place (inline strings, remove the drawing) — never re-save with openpyxl (it drops the saved formula values the Hub reads). Never invent a flight number that is cut in the picture: write `????` and say so.

Who does what — route each finding to the right people:
- Airport pick-ups / drop-offs of the client on the mainland (JRO, ARK, also at night) are Savannah's own transfers, handled by operations through the Arrivals / Departures page: not a booking-team item, not a finding (note them in the report only).
- Transfers by external suppliers (Zanzibar — Iddi —, Dar, Mafia…), lodges, domestic flights, payments to suppliers → booking team (`audience: booking`).
- Information missing from the client (flights, names, requests) → `audience: sales` (CK owner).
- Client invoice / payment that doesn't match → `audience: accounts` (Roberto). Before reporting a payment difference, read the comment on the Calc deposit / balance cell: it usually quotes the bank statement with the amount actually received.

Status of each service:
- **OK**: evidence found and everything matches.
- **CHECK**: evidence found but something differs or is unclear (pax, dates, room, provisional, amount).
- **MISSING**: no evidence in `invoices/` / `bookings/`.
- **IN-HOUSE**: own-lodge or in-house item, nothing to book.

Also list: documents not matched to any service (and why), ignored files (`old`), Calc notes (`calc.warnings`, stays with INVOICE/CHECKED empty although evidence exists → the Calc should be updated, or the reverse: INVOICE says "Invoice $…" but no file).

## 5. Write `booking-check.html`

Save it in `local_folder` as `booking-check.html` (replace the previous one — it is a report, it always reflects the last check). One self-contained page: inline CSS, no scripts, no external files, readable on a phone and printable. In English. Structure:

1. Title "Booking check — <customer>" and a line: folder name, travel dates, pax, rooms, Calc file (+ sheet), checked on <date> by Claude.
2. Summary boxes: OK / CHECK / MISSING / IN-HOUSE counts, and one sentence ("All services confirmed" or "2 services missing, 1 to check").
3. Table, one row per service in date order: Dates · Service (Calc text) · Calc (nights, pax, invoice/checked marks) · Evidence (file names) · Document says (dates, pax, room, status, amount, ref) · Status badge · Notes (what to do: "ask Iddi to confirm the 13 Oct transfer", "Pax 3 on the invoice, Calc has 2").
4. "Documents not matched", "Ignored files (old)", "Calc notes" — only when not empty.

Badge colours: OK green, CHECK amber, MISSING red, IN-HOUSE grey. Never passport numbers.

## 6. Findings to the Hub (Booking follow-up)

After writing the report, send the open points to the Hub so the owners follow them: `ck_findings_upsert` with `request_id`, `checked_at`, `report_path` (the folder's `booking-check.html`) and `findings[]` `{audience, issue, service_date, service, supplier?, text, source_file?}` (issues: `transfer_missing`, `transfer_unconfirmed`, `booking_provisional`, `flight_unpaid`, `client_flights_missing`, `invoice_mismatch`, `payment_mismatch`, `other`). One finding per CHECK / MISSING row that needs action; IN-HOUSE and info notes are not findings. Text in English, what is wrong + what to do, never passport numbers.

- First the dry run: show Roberto the lines and the owner each one gets (`booking` → booking owner, `sales` → CK owner, `accounts` → Roberto); `confirm: true` only after his ok.
- Send every finding, also when another check already reports it (supplier scan, ticket scan, SafariCheck): the Hub merges lines with the same service (`would_merge[]` in the dry run, `merged[]` after) and adds `claude_check` to their `sources`, keeping the existing owner. Show the merges to Roberto with the dry run.
- Fix first what Roberto can fix now (invoice text, payment, names): those never become lines.
- `findings: []` when everything is fine: it closes any open ck line and the booking shows `ck_ready` in the CK tracker.
- `_CK` is blocked by the Hub while a booking is still provisional (provisional, expired provisional, on request, Orangi rooms provisional, `booking_provisional`): `ck_status` shows `ck_blocked` + `ck_blocked_by[]` and `rename_folder` refuses `_CK` (409). If `ck_blocked`, never propose `_CK`: say what blocks it. Other open lines (transfers, tickets, client info) only give a warning.
- Groups (GRP folders, Il Diamante / Panorama, "GRUPPO" folders): `_CK` only when the group is closed (final pax and room list, unsold rooms / seats released). Never propose `_CK` on an open group, whatever else is fine (Roberto, 25 Oct 2026).
- No mail to the owners: on request, `ck_findings_drafts` → `.eml` drafts in `C:\Dropbox\Exchange\Draft-mail\` for Roberto (skill agency-email).

## 7. Reply (Italian, plain text, no emoji)

```
Verifica prenotazioni <cartella>
OK <n> · Da controllare <n> · Mancanti <n> · Interni <n>
Da fare:
- <servizio> – <cosa manca / cosa non torna>
Report: <local_folder>\booking-check.html
```

Several safaris: one line each (`<cartella>: tutto ok` / `<n> mancanti, <n> da controllare`) and the list of what to do.
