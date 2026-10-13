---
name: booking-check
description: Verify a booked safari: every service of the Calc (stays, flights, transfers, activities) against the invoices and confirmations the booking team saved in Dropbox; write booking-check.html in the safari folder.
---

# Booking check

Use when Roberto asks to verify the bookings of a safari ("verifica le prenotazioni", "controlla la pratica X", "è tutto prenotato?"), or types /booking-check. One safari per run; for a list (e.g. "le partenze di novembre") run it per safari and give a short summary at the end.
Reply in Italian. The report file is in English (the booking team, Glady and Lydia, act on it).

This is a CHECK: never edit the Calc, never move, rename or delete files, never send mail, never read mailboxes. The evidence is only what the booking team saved in Dropbox: invoices / service vouchers in `invoices/`, transfer confirmations (mails) in `bookings/`. Missing there = missing.

**Passport numbers: supplier documents often contain them (reservation notes, guest lists). Never copy them into the report or the chat, not even partially.** Guest names are fine; leave out dates of birth.

## 1. Data from the Hub

`find_requests` → `request_id`, then `booking_check` (GET). It returns:
- `local_folder` (`C:\Dropbox\001_Safari\…`): read the files there, write the report there;
- `calc`: file, sheet, pax `{adults, teen, child}`, `room_config`, `arrival`, `departure`, guests, warnings;
- `services[]` in date order: `accommodation` (consecutive nights in one hotel: `check_in`, `check_out`, `nights`, `own`, `calc_invoice[]`, `calc_checked[]`), `flight` (`date`, `route`, `cost`), `transfer` / `activity` (`date`, `text`, `route`). In-house items (emergency, medivac, lunch boxes, hot lunch, Maasai visits — arranged by the safari guide —, Olduvai — paid with the Ngorongoro permit, church visit / city tour on Mafia) are already left out; activities have `own` (run by our lodges);
- `evidence[]`: every file in `invoices/` and `bookings/` (`scope` `group` = the GRP folder's), `old` = inside an `old…` sub-folder.

422 "several pax sheets, none marked CONF": look at the sheet names. Two real parts of one trip (e.g. `16PAX` + `2PAX ZNZ`) → call once per `sheet` and check both; old quotes left next to the confirmed one → say so and stop (the Calc must be cleaned first). No Calc → say so and stop.

## 2. Read every evidence file

Open each file in `local_folder` (and the group folder for `scope: group`): PDF, Word, Excel, `.eml` / `.msg` (body + quoted thread), images. File names are unpredictable: identify a document by its content, never by its name. Files with `old: true`: don't use them, list them as ignored.

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
| Stay | check-in / check-out (nights), pax adults + children, rooms vs `room_config`, meal plan when the Calc states it (HB/FB), status confirmed |
| Flight | date, route (all legs), pax, status; times consistent with `calc.arrival` / `departure` for the first / last leg |
| Transfer | date, pick-up / drop-off, the flight it meets (vs `arrival` / `departure` and the flight invoices); a reply like "noted" / "confirmed" counts as confirmation |
| Activity | a supplier booking (balloon, Mto wa Mbu walk, Materuni…): a document with the date and pax. Activities of our own lodges (`own: true`: "Natural Walk" = Marera Walking and Iraqw families; bush dinner at Orangi, also when the text is just "Bush dinner" on an Orangi night) → "in-house", no evidence needed, but note it if the SV doesn't mention it |

Amount: if a document's total differs from `calc_invoice` or the flight cost, note the difference (info, not an error: taxes, levies, extras).

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

## 6. Reply (Italian, plain text, no emoji)

```
Verifica prenotazioni <cartella>
OK <n> · Da controllare <n> · Mancanti <n> · Interni <n>
Da fare:
- <servizio> – <cosa manca / cosa non torna>
Report: <local_folder>\booking-check.html
```

Several safaris: one line each (`<cartella>: tutto ok` / `<n> mancanti, <n> da controllare`) and the list of what to do.
