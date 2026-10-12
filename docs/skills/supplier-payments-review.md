---
name: supplier-payments-review
description: Check the supplier payment Excel that Saruni/Glady send for approval: every row against a real booking and its Calc, cancelled groups, TL free, totals. Report before Roberto approves.
---

# Supplier payments review

Use when Roberto asks to check a supplier payment list ("controlla i pagamenti", "NEW PAYMENTS", "Saruni mi ha mandato i pagamenti"), a supplier monthly statement (e.g. Iddi, Nickson), or types /supplier-payments-review.
Reply in Italian. This is a CHECK: nothing is paid, approved or changed here — never edit the Excel or the Calc files, never mark anything paid.

Hub access and fixed rules: as in the project instructions.

Memo: at the start create one `doing` memo (clear title, stable `ext_key` e.g. `work-pagamenti-<mese>`); update it if Roberto says "parcheggia". When Roberto says the work is finished, delete it. Never close it on your own.

## 1. Get the file

- Saruni sends the Excel to info@ (Bluehost). Files live in `C:\Dropbox\Payments\2026\NEW PAYMENTS 2026 - SARUNI & GLADY\<month folder>`.
- Open it read-only (access only to `C:\Dropbox`, never ask for subfolders).
- Structure: tabs grouping suppliers; per supplier a breakdown where each row is an invoice for a safari.

## 2. Check every row

1. Real booking: find the client/group in the Hub (`find_requests`) and its folder in `/001_Safari/`. Not found → flag.
2. Cancelled: folder in `/001_Safari/00_CANCELED` → must NOT be paid (unless it is a cancellation fee Roberto agreed).
3. Amount vs Calc: compare with the booking's Calc cost for that supplier (accommodation / activities / flights / transfers columns, right day rows, pax and rooms). Columns N "DEPOSIT PD" and O "BALANCE PD" show what was already paid to that supplier: deduct it, nothing paid twice.
4. TL free: for group activities (e.g. Nickson / Mto wa Mbu) the agency tour leader is free — don't pay the TL.
5. Duplicates: same supplier + client/date twice in this list, or already paid in a previous month's list → flag.
6. Arithmetic: re-add each supplier breakdown and the tab/grand totals in code; report any difference.

Monthly supplier statements (Iddi, Nickson…): match each client to an actual Savannah booking, same checks.

## 3. Report (Italian, plain text, no emoji)

```
Pagamenti <mese> – file <nome>
Totale richiesto: <importo>   Totale verificato ok: <importo>

Da chiarire:
- <fornitore> – <cliente/cartella> – <importo>: <motivo> (es. pratica cancellata / già pagato deposito / TL incluso / non trovato / somma errata)

Ok: <N> righe su <M> (<fornitori>)
```

If he asks, prepare the reply to Saruni (and Glady) with the points to fix: English, as a reply to Saruni's mail (same address it arrived on, see `agency-email` §2), subject unchanged.
