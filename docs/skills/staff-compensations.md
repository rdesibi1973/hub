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
- Each booking's Calc Excel: once a commission is paid, the amount goes in the ACTIVITIES column with description "paid <MONTH>" (e.g. "paid SEPT"). GRP files: in the client's own worksheet, not the RECAP.
- Seller invoices (sellers who invoice Savannah Holidays Ltd): Alessia → `C:\Dropbox\SafariBookings\SafariBookings 2023_2026\Consultants\Alessia\<year>\INVOICE`; Daniela (Daniela Maggi) → `C:\Users\savan\Documents\DanielaMaggi\2026`. New invoice = same format as the previous ones, next number, PDF.

## 2. General rules (all sellers)

- Base: the invoice total of the booking (`get_invoice`).
- Markup: the one in the booking's Calc (`read_calc`).
- Minimum markup for any commission: 12%. Below 12% → no commission (exception: the non-Italian sellers' 10% rule below).
- Bookings from agencies (any agency, anywhere in the world): no commission.
- Seller = the agent in the folder name, e.g. `Name(Anderson-Drct)`.
- Roberto has made exceptions to these rules: when a booking doesn't fit, or Saruni's figure differs from the rule, don't treat it as an error — list it and ask him.

## 3. Rules per seller

### Anderson and Nuru

| Markup | Commission if ALL nights in Arusha, Manyara/Karatu and Serengeti Central are in Orangi Collection lodges | Otherwise |
|---|---|---|
| 16% or more | 2% | 1% |
| 14–15% | 1% | 0.5% |
| 12–13% | 1% | 1% |

- Orangi Collection lodges: Arusha Explorers Lodge (Arusha), Ngorongoro Marera Mountain View Lodge (Manyara/Karatu), Serengeti Kifaru Tented Lodge and Serengeti Orangi River Luxury Lodge (Serengeti Central). Nights in other zones (Tarangire, Ndutu, North Serengeti, Zanzibar...) don't affect the condition. Check it night by night in the Calc.
- Trekking programmes: markup 16%, commission 1%.

### Non-Italian sellers (English / French / Spanish)

Markup 10%, commission 0.5%.

### Agustin

0.5%, only on safaris sold after the first 5 in the month. Counting: Hub "Booked" date, calendar month (1st to last day), a booking without the deposit paid does not count.

### Asia, Alessia, Daniela

New requests (from 13 Oct 2026) — commission scale by markup:

| Markup | Commission |
|---|---|
| 25% or more | 4% |
| 21–24% | 3% |
| 18–20% | 2% |
| 12–17% | 1% |
| below 12% | none |

Bookings already in progress before then keep the old rule (same for all three): 2% agency / 4% direct client, no 5-safari minimum.

## 4. Checking the monthly calculation

For each booking in Saruni's list (and any booking of the seller in that month she left out):
1. Real booking: `find_requests` → request, folder in `/001_Safari/`, seller in the folder name. Cancelled (`00_CANCELED`) → no commission unless Roberto says so.
2. At least the deposit is paid (invoice / folder status not `_DEPOSIT`).
3. Not paid before: earlier monthly worksheets + "paid <MONTH>" in the Calc.
4. Calc total = invoice total; a difference → flag.
5. Rate: markup from the Calc → rules in §2–3 (agency booking? Orangi condition for Anderson/Nuru) → expected %.
6. Amount = expected % × invoice total; recompute in code and compare with Saruni's figure; re-add her totals per seller.

## 5. After Roberto's ok

- Write "paid <MONTH>" + amount in each booking's Calc (ACTIVITIES column; GRP → client's worksheet). Calc not open in Excel on his PC.
- Sellers who invoice Savannah Holidays (Alessia, Daniela): prepare their invoice as in §1.
- Reply to Saruni with the corrections (English, reply on the mail she sent, per `agency-email`), only after his ok.

## 6. Report (Italian, plain text, no emoji)

```
Commissioni <mese> – <venditore>
Totale Saruni: <importo>   Totale verificato: <importo>

- <cartella> – markup <x>% – Orangi: sì/no – <y>% di <totale fattura> = <commissione> [ok / Saruni <importo>: <motivo>]

Da decidere (eccezioni / fuori regola): ...
Serve da te: ...
```
