---
name: staff-compensations
description: Sales staff commissions (Anderson, Nuru, Asia, Alessia, Daniela, Agustin): rules per seller and markup, where they are tracked, how to check the monthly calculation Saruni sends, how to record what was paid.
---

# Staff compensations (sales commissions)

Use when Roberto asks to calculate or check commissions ("commissioni", "Saruni ha calcolato le commissioni", "Commission for ..."), to rate a request against the commission scale, or types /staff-compensations.
Reply to Roberto in Italian, short: what is ok, what doesn't add up, what needs his decision.
This is a CHECK: never pay, never edit the commission worksheet, never write "paid" in a Calc without Roberto's ok.

Hub access and fixed rules: as in the project instructions (dry run first, confirm only after his ok; no mail without his ok; never passport numbers; stop and say so when something fails or doesn't add up).
Memo: at the start create one `doing` memo (stable `ext_key` e.g. `work-commissioni-<mese>`); update it if Roberto says "parcheggia"; delete it only when he says the work is finished.

## 1. Where commissions live

- Worksheet: `TotalRequests 2026.xlsx` on Google Drive (savannah.explorers@gmail.com, `My Drive\Commissions\`), one worksheet per month; the Hub "Commissions" card opens it. Saruni fills it and announces it by mail to info@ ("Commission for ...", usually no attachment).
  - Columns: A seller, B folder/client, C sale value USD, D commission (formula `=C*rate`), E status notes ("ok", "not yet received", "paid <MONTH>" on the TOTAL row), J/L/M payment table (bank account, USD, TSH at the exchange rate in L6).
- Each booking's Calc Excel: once a commission is paid, the amount goes in the ACTIVITIES column with description "paid <MONTH>" (e.g. "paid SEPT"). GRP files: in the client's own worksheet, not the RECAP.
- Seller invoices (sellers who invoice Savannah Holidays Ltd): Alessia → `C:\Dropbox\SafariBookings\SafariBookings 2023_2026\Consultants\Alessia\<year>\INVOICE`; Daniela (Daniela Maggi) → `C:\Users\savan\Documents\DanielaMaggi\2026`. New invoice = same format as the previous ones, next number, PDF.

## 2. General rules (all sellers)

- When: the commission is paid as soon as the deposit has been received. A booking without the deposit stays in the list as "not yet received" and is paid in a later month, when the deposit arrives.
- Base: the full invoice total of the booking (`get_invoice`), even though only the deposit has been paid. This is a big favour to the sales staff.
- Cancellation: if the safari is later cancelled, the commission is recalculated on what the client actually paid (deposit / penalty kept). The difference already paid to the seller is deducted from their next commissions — show it in the report as a negative line on the original booking.
- Markup: the one actually used for the price in the booking's Calc (`read_calc` doesn't return it: open the Calc; D1 = Markup %, D2 = Markup TO %; the effective markup is the one that gives F9 "Price to customer").
- Minimum markup for any commission: 12%. Below 12% → no commission.
- Agency bookings follow the same rules as direct clients, unless Roberto decides otherwise (e.g. Yeadim Travel: no commission, Oct 2026).
- Seller = the agent in the folder name, e.g. `Name(Anderson-Drct)`, `Name(Agency-Anderson)`.
- Roberto may make exceptions: when a booking doesn't fit, or Saruni's figure differs from the rule, don't treat it as an error — list it and ask him.

## 3. Rules per seller

### Anderson and Nuru

| Markup | With The Orangi Collection lodges | Without |
|---|---|---|
| 16% or more | 2% (1% if Orangi lodge prices were discounted) | 0.5% |
| 12–15% | 1% | 0.5% |
| below 12% | 0 | 0 |

- "With The Orangi Collection lodges" = all nights in Arusha, Manyara/Karatu and Serengeti Central are in our lodges: Arusha Explorers Lodge (Arusha), Ngorongoro Marera Mountain View Lodge (Manyara/Karatu), Serengeti Kifaru Tented Lodge and Serengeti Orangi River Luxury Lodge (Serengeti Central). Nights in other zones (Tarangire, Ndutu, North Serengeti, Zanzibar...) don't affect the condition. Check it night by night in the Calc.
- Special lodge prices: Anderson and Nuru often lower the price of our lodges (especially Serengeti Orangi River Luxury Lodge) to close the sale; then a commission that would be 2% becomes 1%. Compare the ACCOMMODATION column (J) of each Orangi night with the standard cost per double room per night:

  | Lodge | Standard cost, double room / night |
  |---|---|
  | Arusha Explorers Lodge | 160 USD, HB |
  | Ngorongoro Marera Mountain View Lodge | 250 USD, FB |
  | Serengeti Kifaru Tented Lodge | 527 USD, FB |
  | Serengeti Orangi River Luxury Lodge | 740 USD, AI |

  - Lower than the standard → special price → 1%. The classic case: Orangi River Luxury costed at the Kifaru rate (527, often written just "Orangi" in col K).
  - Calc formulas are per room (e.g. `=527*$B$1/2` = 527 per double room). Other room types (single, triple): compare per person.
  - When unsure, list it and ask Roberto.
- Trekking: 1%. Trekking + safari in one booking: 1% on the trekking part, the safari part follows the table above (e.g. Ruzzier Oct 2026: =2255*3*0.01+(3525-2255)*3*0.02).
- Examples (Oct 2026): Boccale (Aug 2027, markup 12%, Orangi at Kifaru rate) → 1% — August is high season and shouldn't be sold at such low prices. Vella (Orangi at 610) kept at 2% as an exception.

### Asia, Alessia, Daniela

New requests (from 13 Oct 2026) — commission scale by markup:

| Markup | Commission |
|---|---|
| 25% or more | 4% |
| 21–24% | 3% |
| 18–20% | 2% |
| 12–17% | 1% |
| below 12% | none |

Bookings already in progress before then keep the old rule: 2% agency / 4% direct client, no 5-safari minimum.

### Agustin

0.5%, only on safaris sold after the first 5 in the month. Counting: Hub "Booked" date, calendar month (1st to last day), a booking without the deposit paid does not count.

## 4. Checking the monthly calculation

For each booking in Saruni's list (and any booking of the seller in that month she left out):
1. Real booking: `find_requests` → request, folder in `/001_Safari/`, seller in the folder name. Cancelled (`00_CANCELED`) → recalculate on what was actually paid (§2) and deduct any difference already paid.
2. Deposit received: invoice `paid` > 0 (`find_invoices`), folder status not `_DEPOSIT`. Otherwise → "not yet received", not in this month's total.
3. Not paid before: earlier monthly worksheets + "paid <MONTH>" in the Calc.
4. Calc total = invoice total; a difference → flag.
5. Rate: markup from the Calc → rules in §2–3 (Orangi condition, Orangi special prices, trekking part) → expected %.
6. Amount = expected % × invoice total; recompute in code and compare with Saruni's figure; re-add her totals per seller and check the payment table (USD × rate = TSH).
7. Also look for bookings of these sellers cancelled since the last payment (`/001_Safari/00_CANCELED`) whose commission was already paid → deduction.

## 5. After Roberto's ok

- Write "paid <MONTH>" + amount in each booking's Calc (ACTIVITIES column; GRP → client's worksheet). Calc not open in Excel on his PC.
- Sellers who invoice Savannah Holidays (Alessia, Daniela): prepare their invoice as in §1.
- Reply to Saruni with the corrections (English, reply on the mail she sent, per `agency-email`), only after his ok.

## 6. Report (Italian, plain text, no emoji)

```
Commissioni <mese> – <venditore>
Totale Saruni: <importo>   Totale verificato: <importo>

- <cartella> – markup <x>% – Orangi: sì/no (prezzo speciale sì/no) – deposito: sì/no – <y>% di <totale fattura> = <commissione> [ok / Saruni <importo>: <motivo>]
- <cartella cancellata> – ricalcolo su <pagato> = <nuova commissione> – da recuperare <differenza>

Da pagare dopo il deposito: ...
Da decidere (eccezioni / fuori regola): ...
Serve da te: ...
```
