---
name: daily-check
description: Savannah Explorers daily round ("fai il giro"): routine_status, incoming leads to assign, open SH/AfrAsia balances and their memos, weekly domestic flight tickets check, weekly Booking follow-up reminder, then routine_done. Mail is read by Roberto, not here.
---

# Daily check ("il giro")

Use when Roberto says "fai il giro", "giro", "giro di controllo", asks to start the day, or types /daily-check.
Reply to Roberto in Italian, short: what is open and what needs his ok.

Hub access and fixed rules: as in the project instructions (dry run first, confirm only after his ok; no mail without his ok; never passport numbers; stop and say so when something fails or doesn't add up).

Mail is NOT part of this round: Roberto reads Gmail and info@ himself. Don't read mail here unless he asks.
No "doing" memo for the round (it is a routine, not a piece of work).

## 1. What is due

`routine_status` → routines due (leads, payments / afrasia). If the mail routine shows as due, remind Roberto in one line (he does it by hand) and don't mark it done.

## 2. Leads (if due)

Count the Incoming Leads still to assign and list them, one line each (name, source, date).
If Roberto wants: duplicates check with `find_requests` (name, email, agency) and a proposed agent.
- Folder naming: fam trips use the agency name and "FamTrip" as client (never the staff name, never `-Drct`); trips including Kenya show Kenya in the name.
- Alessia and Alessandra are different agents; Roberto De Sibi (id 14) is not Roberto Capri.
- Assigning/creating: always `notify_agent: true`.
- Agency not in the Hub: `list_agencies` (try the short name and part of the name) first; if it is really missing, propose `create_agency` (name, type `savannah` / `promoservice` for Promoservice agencies / `lamprati`, email if known) — dry run, show Roberto the short name used in the folder tag, `confirm` after his ok; a 409 means it exists: use that id.

## 3. Payments / AfrAsia (if due)

Reference list for all payments (SE and SH): https://hub.savannahexplorers.com/modules/leads/payments.php

List open balances:
- SH-… invoices (Savannah Holidays → AfrAsia; Roberto checks these himself, not the accountant): number, client/agency, balance, due date.
- Booking folders with a balance due soon or overdue (folder tags `_DEPOSIT`, `_BALANCE`, `_BALANCE-CASH`). Terms: deposit 30%; balance 60 days before start for direct clients, 45 days for agencies; Il Diamante: no deposit, full balance 30 days before arrival.
- A transfer the client says was sent is "in arrivo", NOT paid. Nothing is registered or marked paid in this round.

SH payment memos: every open SH invoice must have a `waiting` memo linked to it with `auto_close_on_payment` and `ext_key` `pay-<invoice number>`. If one is missing, propose it (`waiting_on` = client/agency, `due_date` = when to chase, supplier to pay afterwards in `next_steps`). Never payment memos for SE invoices.

## 3b. Domestic flight tickets (once a week)

On Monday, or the first round of the week: `flight_tickets` with `from` = today, `to` = today + 14 days, as in `back-office` §6. Report the bookings with `missing` (who booked it) and the tickets present but not yet sent. Other days skip this section.

## 3c. Booking follow-up reminder (once a week)

On Monday, or the first round of the week: `followup_list` (open lines) → only counts and dates, no review here:
lines open, not taken in charge, Problem, last check of supplier documents / tickets / groups (`last_checks`).
Remind Roberto in two lines that the weekly follow-up is due. The checks are run by hand (no cron for now):
on the Hub page Operations → 📌 Booking follow-up → "Run check now" → **All** (tickets + groups), and the supplier
PDFs from the office PC with `tools\followup_local_scan.bat --confirm`. Then the review with skill `booking-followup`.
Other days skip this section.

## 4. Report (Italian, plain text, no emoji)

```
Giro del <data>

Leads da assegnare: <N>
- <nome> – <fonte> – <data> → proposta: agente <X> [duplicati: nessuno / possibile #id]

Saldi aperti SH: <N> (<totale>)
- <SH-…> <cliente/agenzia> <saldo> scad. <data> [memo: ok / manca]
In scadenza / scaduti (altre pratiche): ...

Follow-up prenotazioni (solo il lunedì): <N> aperte, <N> non prese in carico, <N> Problem; ultimo controllo fornitori <data>, biglietti <data>, gruppi <data>
→ da fare questa settimana: Run check (All) dalla pagina + scansione PDF dal PC, poi revisione (booking-followup)

Biglietti voli interni (solo il lunedì, prossime 2 settimane): mancanti <N>, da inviare <M>
- <cartella> – <volo> <data> – manca il biglietto (prenotato da <Glady/Lydia>)

Cosa serve da te: ...
```

## 5. Close

After Roberto has seen the report: `routine_done` for the routines actually checked (not mail), with a short note (e.g. "2 leads, 3 saldi SH aperti, 1 memo creato"). Assign leads and create SH memos only for those he approved.
