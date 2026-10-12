---
name: booking-confirmation
description: Confirm a Savannah safari booking in the Hub: Calc checks, confirm_preview/confirm_booking, booking email to staff, invoice hand-off, final programme from the Calc.
---

# Booking confirmation

Use when Roberto says a client/agency confirmed ("confermato", "conferma la pratica", "hanno confermato"), or types /booking-confirmation.
Reply in Italian, short: what is ready, what needs his ok, next step.
Il Diamante Panorama groups do NOT follow this flow: use `diamante-groups`.

Hub access and fixed rules: as in the project instructions (dry run first, confirm only after his ok; no mail without his ok; never passport numbers; stop and say so when something fails or doesn't add up).

Memo: at the start create one `doing` memo (clear title, linked request, stable `ext_key` e.g. `work-<client>-conferma`); update it if Roberto says "parcheggia". When Roberto says the work is finished, delete it (don't leave it as done). Never close it on your own.

## 1. Identify the booking

`find_requests` → `request_id`, folder in `/2026/`, agency, agent. If there are several Calcs (`01_`, `02_`…), ask which one is confirmed.

## 2. Check the Calc before confirming (`read_calc`)

- only ONE PAX sheet (the confirmed pax count);
- H6:I14 cleared when adults only; with teens the teen discount stays in H13/I13 (see `sales-agent` §2);
- F9 as a formula of the per-person components (e.g. `=1625+255`): STO price for agencies, rack for direct clients — the same figure as programme and email; ZNZ/Pemba transfers per car in F10, not F9;
- a hotel on every night, beach nights included ("<hotel> - own arrangement" when the agency books it); meal plan stated for Zanzibar/Pemba/Mafia/Arusha;
- rooms exactly as requested (man+woman couple = 1 DBL; families of 4 in quadruple at Orangi Collection lodges);
- guests from row 43: "First Last", MR/MRS, country (Italian names + Italian agency → ITALY); arrival A51, departure A56;
- cached formula values present (otherwise the Hub reads End date = Start date).
Anything missing: list it and ask, don't fix silently. Calc not open in Excel on his PC; after a local edit wait for Dropbox sync before any Hub action.

## 3. Confirm

1. `confirm_preview` with start, mid / mid2 (or NA), end, destination, grp (NONE unless a real gruppo a raccolta). Show the new folder name and every check/warning. Kenya in the folder name if the trip includes Kenya; fam trips = agency + "FamTrip".
2. After ok → `confirm_booking` with confirm → folder to `/001_Safari/`, status Booked.
3. `send_booking_email` dry run → show To / Cc / body (template To: accountant@, glady@, operations@, nuru@; Cc configurable — ask whether to keep info@). Send only after his ok on text and recipients.

## 4. Invoice

Hand off to `invoice-workflow`.

## 5. Final programme (from the Calc)

1. `iti_calc_plan` with `request_id`. If `blocking` is not empty: for each unknown Calc text propose the right lodge / activity / route (`iti_lodges`, …); `iti_save_alias` only after his ok. Never guess.
2. `iti_final_from_calc` dry run → confirm after ok. No sample for the Calc programme code → say so, don't invent one.
3. ITI `ref_number` = the Calc name without `_Calc` (e.g. `01_MarcoCiaolo(OceanoPoint-Roberto)_PumbaSafari`).
4. Check with `iti_program`: days, lodges, dates, room types, meals; flag days without photo or text.
5. `iti_document`: `docx` for agencies, `pdf` for direct clients, `guide` for the safari guide (same language as the client programme). Saved as `<ref_number>.docx/.pdf` in the booking folder; on 409 ask before `overwrite: true`.

## 6. Reminders (only if relevant)

- Savannah needs passport copies (park permits) and a copy of the travel insurance: ask the agency/client.
- Booking team: rooms booked exactly as per the request (room type and pax).

## 7. Report (Italian, plain text, no emoji)

```
Conferma <cartella> (prova)
Calc: ok / da sistemare: ...
Nuova cartella: <nome>
Mail booking: A ... Cc ... – testo pronto
Serve da te: ok per confermare / ok per inviare la mail
Poi: fattura (deposito <importo>) e programma finale
```
