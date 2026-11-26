---
name: quote-review
description: Check a seller's Calc before it goes to booking ("Hi Robi, kindly advise if we can book"): dates vs programme, sheets, costs against our standards, Bank Commission, markup actually applied, margin and commission. Report to Roberto; he approves.
---

# Quote review (seller's Calc before booking)

Use when a seller (Agustin, Anderson, Nuru, Asia, Daniela...) asks Roberto to approve a quote before sending it to booking ("Kindly advise if we can book", "can we book?", "approvi?"), when Roberto says "controlla il Calc di X prima del booking", or types /quote-review.
Reply to Roberto in Italian, short. This is a CHECK: never edit the Calc, the programme or the Hub, never confirm or send to booking. Roberto decides; the markup in particular is his call, not an error to fix by itself.

Hub access and fixed rules: as in the project instructions (dry run first, no mail without his ok, stop and say so when something fails).
Never read, copy or report passport numbers (the Calc lists them under the passenger names: skip that block).
Memo: at the start create one `doing` memo (`ext_key` e.g. `work-review-<cliente>`); delete it only when Roberto says the work is finished.

## 1. Find the files

- Folder: `find_requests` with the client name, or Dropbox search on the folder name (`/2026/<Nome>(<Agenzia/Venditore>-<Agente>)`, or `/001_Safari/` if already confirmed).
- The Calc to check = the one the seller names, normally the highest number (`04_..._Calc.xlsx`).
- The programme the client has = Word/PDF with the SAME number (`04_..._SimbaSafari.docx/.pdf`). The price that counts is the one in the programme (and invoice, if any).
- Read both with the Dropbox connector (`fetch` gives the Calc values per sheet; enough for the check). Open the Calc on the PC only if formulas are needed.

## 2. Checks

### 2a. Dates, pax, sheets
1. Calc day dates (col A) = programme dates, YEAR included. Also compare with the arrival / departure notes in the Calc. Classic error: rows in the next year (2027) while the trip is this year (Dec 2026) → the booking team would book the wrong year. If the trip is less than 60 days away: full payment at confirmation.
2. Pax, adults/teens/children, room types = programme.
3. Only ONE pax sheet, marked CONF. Alternative sheets (other lodges, other pax) must be deleted before booking. Name the sheet that matches the programme.
4. Last day: airport in the Calc (JRO / ARK) = programme; departure flight known? No driving after dark (about 4 h Ngorongoro/Karatu → JRO).

### 2b. Costs (our standards; see memory pricing rules)
Check every day row against:
- Jeep 300 per safari day; transfers Arusha–JRO 150, Arusha–ARK 70, JRO–Tarangire 300, JRO–Moshi 300; jeep doubled when it drives empty (fly-in/fly-out).
- Park fees per person: Tarangire / Manyara / Arusha NP 60, Serengeti 83, NCA (incl. Ndutu) 71, Karatu → Serengeti transit 154 (71 + 83), crater = 71 pp + 295 per vehicle. Leaving and re-entering a park, or staying in the NCA past 24 h (e.g. morning game drive in Ndutu before driving to the Serengeti), means paying again.
- Lunch boxes 10 pp only on the first safari day; Medivac in the activities; drinks 4 pp per day.
- Our lodges per double room per night: Arusha Explorers 210 HB, Marera 250 FB, Kifaru 527 FB, Orangi River Luxury 740 AI (lower = special price: say so). Pure Migration / Ndutu Naabi at the Kifaru rate (527 per room). In-park camps: concession 71 pp + 1% levy included; WMA 33 pp.
- External lodges: compare with the contract in `/000_Contracts` only if Roberto asks (say which ones you did not check).
- Bank Commission (D-block "Bank Commission"): must be 100. Sellers who price "all'osso" often set it to 0 — always flag it.
- Supplements by date: August 35 pp per safari night (1–25 Aug), festive 35 pp for the nights of 24, 25, 26, 31 Dec.
- Totals row = sum of the columns; Total Costs = totals + Bank Commission.

### 2c. Markup actually applied
- D1 = Markup %, D2 = Markup TO %. Effective markup = the one that gives F9 "Price to customer" (D9 rounded up to 5 → D1; D10 → D2). Compute it also as price ÷ costs − 1 and give the margin in USD.
- Usual markups, for reference (Roberto decides, not blocking):
  - direct Italian clients: 25% (rack); sellers often go lower;
  - direct foreign (non-Italian) clients: 18%; Agustin usually sells at about 10%;
  - STO agencies 18%, STOSPEC 14% (EKO, Etnia, GoWorld...);
  - 1–20 August with an Italian-speaking guide: never below 18%.
- Price in the programme = F9. If they differ, say which price the client has.
- Recompute with the corrected costs (2b) at the same price: margin left; and the price at 18% for comparison.
- Israeli and other very price-driven clients: Roberto may approve a low markup to close the sale — just report the numbers.

### 2d. Commission consequence
Per `staff-compensations`: below 12% no commission for anyone; Agustin 0.5% only after his first 5 safaris of the month; Anderson/Nuru depend on Orangi lodges and special prices; Asia/Daniela by the markup scale. State what the seller would get and that it is Roberto's decision.

## 3. Report to Roberto (Italian, plain text, no emoji, little bold)

```
<Calc> – foglio <CONF> – prezzo <F9> pp (programma <prezzo>)
Markup applicato: <x>% (D1 / D2) – margine <USD> su <totale>
Da correggere prima del booking:
- <date / foglio / costo / Bank Commission ...>
Costi corretti: <totale> → margine <USD> (<x>%) – al 18%: <prezzo> pp
Commissione venditore: <...>
Ok: <cosa torna>
Decidi tu: approvare così / riprezzare
```

Then propose a short reply to the seller in English with only the points to fix (no lecture on the markup unless Roberto wants it). Roberto often writes it himself (e.g. on WhatsApp): give it as text; prepare an .eml only if he asks (per `agency-email`).

## 4. Examples

- 25 Nov 2026, `04_ShimonCohen(Agustin-Drct)_Simba_Calc` (Israeli clients, 4 pax, 11–18 Dec 2026): Calc rows in 2027; markup 8% (D1), price 2570 pp; transfer JRO 100 instead of 150, Pure Migration 475/room instead of 527, Bank Commission 0, NCA fee missing on the Ndutu → Serengeti day. Roberto approved the low price (price-driven clients), no commission to Agustin, asked only to fix the dates.
