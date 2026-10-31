---
name: invoice-workflow
description: Create or change a Savannah invoice (SE/SH) and register payments in the Hub and the Calc: dry run, Calc check, confirm after ok, folder status, invoice PDF to Dropbox.
---

# Invoice workflow (Hub)

Use when Roberto asks to make, fix or check an invoice ("fattura"), to register a payment ("è arrivato il bonifico", "pagamento ricevuto", "hanno mandato la contabile"), or types /invoice-workflow.
Reply to Roberto in Italian, short: what you prepared, what needs his ok, next step.

Hub access and fixed rules: as in the project instructions (dry run first, confirm only after his ok; no mail without his ok; never passport numbers; stop and say so when something fails or doesn't add up).

Memo: at the start create one `doing` memo (clear title, linked request/invoice, stable `ext_key` e.g. `work-<client>-fattura`); update it if Roberto says "parcheggia". When Roberto says the work is finished, delete it with `memo_delete` (dry run, then `confirm`; don't leave it as done). Never close it on your own.

Reference list for all payments (SE and SH): https://hub.savannahexplorers.com/modules/leads/payments.php

## 1. Identify the booking

- `find_requests` (name, folder, agency) → `request_id`, folder name, agency.
- `read_calc` on the booking's Calc → total, pax, services, what was already invoiced/paid.

## 2. Issuer and bill-to

- `SE-…` = Savannah Explorers Ltd. `SH-…` = Savannah Holidays Ltd (Mauritius) → AfrAsia account; Roberto checks SH payments himself, not the accountant.
- Etnia (Etnia SRL / Etnia Viaggi) always pays Savannah Holidays (AfrAsia USD).
- Promoturismo = Alhambra Viaggi → invoice to ALHAMBRA VIAGGI.
- Promoservice (Paolo Maresi) is a representative, not invoiced: bill the actual agency.
- Agencies: pass `agency_id`. Direct clients: bill the client.
- Agency without an address in the Hub (`list_agencies` shows `address`): look for it in the agency's mails (signature, often an image) and save it with `update_agency` (dry run, then confirm) before creating the invoice, so the bill-to has the address (13 Oct 2026).
- Agency not in the Hub: `list_agencies` (try the short name and part of the name) first; if it is really missing, propose `create_agency` (name, type `savannah` / `promoservice` for Promoservice agencies / `lamprati`, email if known) — dry run, show Roberto the short name used in the folder tag, `confirm` after his ok; a 409 means it exists: use that id.
- Some `SE-…` numbers are really Savannah Holidays invoices (switched after creation; the number can't change): don't "fix" them.
- Old Zoho invoices are `INV-…` (`import_zoho_invoice`).
- If issuer or bill-to is unclear, ask before preparing.

## 3. Create an invoice

1. Build `items` from the Calc (description, quantity, unit price). Terms: 30% deposit to confirm; balance 60 days before start (direct) or 45 days (agencies). Il Diamante: no deposit, full balance 30 days before arrival.
2. `create_invoice` dry run with `request_id`, `agency_id` (agencies), `issuer` SE/SH, `items`.
3. Show Roberto: issuer, bill-to, lines, total, currency, due date, `calc_check` result.
4. `calc_check` fails → report the exact difference vs the Calc. Never `ignore_checks`, `allow_duplicate` or `allow_overpaid` unless he says so.
5. After his ok → confirm.
6. Offer `save_invoice_pdf` (practice folder; `overwrite: true` only if a PDF already existed and he agrees).
   After a new booking's deposit invoice don't touch the folder status: the booking team sets `_DEPOSIT` when the bookings are done (13 Oct 2026).
7. SH invoices only: propose the payment memo — `waiting`, linked to the invoice, `auto_close_on_payment`, `ext_key` `pay-<invoice number>`, `waiting_on` = client/agency, `due_date` = when to chase, supplier to pay afterwards in `next_steps`. SE invoices: no payment memo.

## Bank details for payment requests

Savannah Explorers Ltd (SE invoices, USD):
- Beneficiary: Savannah Explorers Ltd, Engosheraton, P.O. Box 16726, Arusha - Tanzania
- Bank: Exim Bank (T) Ltd, P.O. Box 1431, 9 Samora Avenue, Dar es Salaam - Tanzania
- Account Number: 0030024670 (USD)
- Swift: EXTNTZTZ

Savannah Explorers Ltd also has a CRDB account — agencies may pay there, it is ours (confirmed by Roberto 30 Oct 2026, GoWorld deposit for SE-2026-0640):
- Bank: CRDB Bank PLC, Tanzania
- Account Number: 0250467872701
- Swift: CORUTZTZXXX
Check an announced payment on the account it was actually sent to (see the POP), not only on Exim.

Savannah Holidays Ltd (SH invoices): AfrAsia account — details from Roberto (not recorded here yet).

## 4. Change an invoice

- `update_invoice` with only the fields that change. `items` REPLACES all lines: always send the full list.
- Dry run → show from/to and new total → confirm after ok → offer `save_invoice_pdf` with `overwrite: true`.
- SH invoice: if total or due date changed, propose updating its payment memo.

## 5. Payment announced but not received ("in arrivo")

A transfer the client/agency says was sent, or a payment advice (e.g. "Comunicazione di pagamento"), is NOT a received payment:
- do NOT register it in the Hub, do NOT change invoice or folder status, never mark anything paid;
- in the Calc write "ON THE WAY dd/mm/yyyy" in G11 (deposit) or G12 (balance);
- every note written in the Calc is in English ("ON THE WAY", "RECEIVED", "incoming, not yet received"), never Italian ("in arrivo") (19 Oct 2026);
- writing in the Calc: never re-save the file with openpyxl (it drops the saved values of every formula, and `read_calc` / the Hub then miss totals and balances). Insert the note directly into the sheet XML (inline string, other files of the .xlsx copied unchanged), or open the Calc in Excel; first keep a copy in `old/`; afterwards check `read_calc` has no warnings (25 Oct 2026);
- propose forwarding the agency's original payment mail, with its attachment, to Saruni and Doreen, with the folder name in the subject (draft, send only after his ok).

## 6. Payment received

Money actually on the account (bank statement / AfrAsia) or cash collected by Saruni.
1. `add_invoice_payment` dry run: date, amount, method, reference. It refuses duplicates (same amount + reference): never force `allow_duplicate` without asking. Confirm after ok.
2. Calc: amount in F11 (deposit) or F12 (balance), "RECEIVED dd/mm/yyyy" in G11/G12, the bank-statement line as a cell comment. NEVER in columns N "DEPOSIT PD" / O "BALANCE PD" (those are payments to suppliers). Calc not open in Excel on his PC; wait for Dropbox sync before the next Hub action. Write it as in §5 (no openpyxl re-save).
3. Folder status = what is still due: deposit received → `BALANCE`, or `BALANCE-CASH` when the balance will be paid in cash (ask if unclear); everything received → `PAID` (= fully paid). `DEPOSIT` only while the deposit is still due. `update_folder_status` dry run → confirm after ok.
4. Offer the updated `save_invoice_pdf` (`overwrite: true`).
5. SH invoice: its payment memo closes by itself when fully paid; after a partial payment propose updating it (new balance, next chase date). If a supplier must now be paid, remind Roberto (from `next_steps`).

## 7. Report (Italian, plain text, no emoji)

```
Fattura <SE/SH>-… (prova) – <cartella pratica>
Emittente: <SE/SH>   Intestata a: <...>
Righe:
- <descrizione> – <q.tà> x <prezzo> = <totale>
Totale: <importo valuta>   Scadenza: <data>
Controllo Calc: ok / differenza <importo> (<motivo>)
Memo pagamento: proposto (solo SH) / non serve (SE)
Serve da te: ok per confermare
```
