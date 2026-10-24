---
name: booking-confirmation
description: Confirm a Savannah safari booking in the Hub: Calc checks, confirm_preview/confirm_booking, booking email to staff, invoice hand-off, final programme from the Calc.
---

# Booking confirmation

Use when Roberto says a client/agency confirmed ("confermato", "conferma la pratica", "hanno confermato"), or types /booking-confirmation.
Reply in Italian, short: what is ready, what needs his ok, next step.
Il Diamante Panorama groups do NOT follow this flow: use `diamante-groups`.

Hub access and fixed rules: as in the project instructions (dry run first, confirm only after his ok; no mail without his ok; never passport numbers; stop and say so when something fails or doesn't add up).

Memo: at the start create one `doing` memo (clear title, linked request, stable `ext_key` e.g. `work-<client>-conferma`); update it if Roberto says "parcheggia". When Roberto says the work is finished, delete it with `memo_delete` (dry run, then `confirm`; don't leave it as done). Never close it on your own. The memo covers only this session's work (e.g. "quote + reply to the agency"): later steps (waiting for the booking team's confirmations, invoice, final programme) do NOT go in its next_steps — they are done in a new session with a new memo (15 Oct 2026).

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
- rooms: the Hub reads the value in the row BELOW the "ROOMS TYPE" header (A37 header, A38 value) — keep the header, write the rooms under it.
- **Groups (gruppi a raccolta / agency group departures):** guests stay TBA — the agency sends the room list with names only if and when the group confirms. Rooms = the block requested (e.g. "15 double/twin"); some rooms may be released and singles added when the group closes. Keep the H6:I14 block (single supplement, teen/child discounts) until the group confirms: supplements and discounts are applied then. Don't flag these as missing. In `send_booking_email` add a `body_extra` asking for provisional bookings until the option date Roberto gives, for the room block, with room list and singles to follow (13 Oct 2026).
Anything missing: list it and ask, don't fix silently. Calc not open in Excel on his PC; after a local edit wait for Dropbox sync before any Hub action.

## 3. Confirm

1. `confirm_preview` with start, mid / mid2 (or NA), end, destination, grp (NONE unless a real gruppo a raccolta). Show the new folder name, every check/warning AND the booking email it returns (`email`: To / Cc / subject / body; template To: accountant@, glady@, operations@, nuru@; Cc configurable — ask whether to keep info@). Kenya in the folder name if the trip includes Kenya; fam trips = agency + "FamTrip". Destination: leave it empty (Tanzania) for a safari, also with a Zanzibar/beach extension; `ZNZ` (or another suffix) only when the whole trip is in that destination, because it ends up in the folder name (13 Oct 2026).
2. Changes to the email go in an `email` object (`to_add`/`to_remove`/`cc_add`/`cc_remove`, `to`/`cc` to replace, `subject`, `body` for a full rewrite, `body_extra`, `no_invoice`): rerun the preview with it and show the result until Roberto is happy.
3. After his ok on folder AND email → `confirm_booking` with `confirm: true` and the same `email` + `"send": true`: folder to `/001_Safari/`, status Booked, email sent in one call. Check `email_sent`; if false, say so and retry with `send_booking_email` (same edits). If he wants to confirm now and mail later, omit `send` and use `send_booking_email` afterwards.
4. After the confirm, move the Calcs that were not confirmed (the other `NN_…_Calc.xlsx` versions) into the booking folder's `old/` subfolder: only the confirmed Calc stays at the top (13 Oct 2026).

## 4. Invoice

Hand off to `invoice-workflow`.

## 5. Final programme (from the Calc)

1. `iti_calc_plan` with `request_id`. If `blocking` is not empty: for each unknown Calc text propose the right lodge / activity / route (`iti_lodges`, …); `iti_save_alias` only after his ok on THAT alias — a new blocking alias that shows up later (e.g. a route after the hotel alias) needs its own ok. Never guess.
   If `sample` is null (no sample tagged with the Calc code), propose the closest sample and pass `sample_id`; then check `from_sample_day` against the Calc dates: a non-safari row at the end ("departure (own arrangement)") can take the sample's last day, leaving the real last safari day empty. Fix it after generating (`iti_update_day` the right day, `iti_delete_day` the extra one). Tag the sample with the Calc code with `iti_tag_sample` (dry run, then confirm after his ok); the same action moves a code that is on the wrong sample (23 Oct 2026).
2. `iti_final_from_calc` dry run → confirm after ok. No sample for the Calc programme code → say so, don't invent one.
3. ITI `ref_number` = the Calc name without `_Calc` (e.g. `01_MarcoCiaolo(OceanoPoint-Roberto)_PumbaSafari`).
4. Check with `iti_program`: days, lodges, dates, room types, meals; flag days without photo or text. Also flag sample texts that contradict the booking (e.g. "transfer condiviso con altri viaggiatori" on a private safari). Last safari day with a game drive (e.g. Manyara before the flight): breakfast + picnic lunch. Domestic flight on the day: `flights` with airline, flight_no, dep / arr from the booking (17 Oct 2026).
5. `iti_document`: `docx` for agencies, `pdf` for direct clients, `guide` for the safari guide (same language as the client programme). Saved as `<ref_number>.docx/.pdf` in the booking folder; on 409 ask before `overwrite: true`.

## 6. Reminders (only if relevant)

- Savannah needs passport copies (park permits) and a copy of the travel insurance: ask the agency/client.
- Booking team: rooms booked exactly as per the request (room type and pax).

## 7. Final confirmation to the agency (after `booking-check` is all OK)

Reply in the agency's thread, from the mailbox it arrived on (`agency-email`). Model: the Waelti / Fenice mail of 17 Oct 2026. Content, in this order:
1. "ti confermo la pratica: lodge e volo interno sono tutti prenotati e confermati."
2. "Riepilogo:" day by day (date, what they do, lodge, meal plan); answers to the agency's open questions.
3. Confirmed services: every stay (lodge, dates, nights, room, meal plan) and every domestic flight (airline, flight no., times, passengers, baggage Flightlink 20 kg hold + 5 kg hand (confirmed by Roberto 17 Oct 2026; other airlines: check)) — with the times the agency needs (e.g. for its own Zanzibar transfer). "Gli orari dei voli interni a volte vengono modificati dalla compagnia: in caso ti avvisiamo subito."
4. NIC / travel insurance (copy with emergency evacuation cover) if still missing.
5. Payment: first `find_invoices` with `request_id` — what is still unpaid. Lines as on the invoice ("<services>: <price> usd x <n> pax", "Totale: <n> usd"), invoice number and due date, then "Coordinate bancarie:" (SE: Exim Bank; SH: AfrAsia, see `invoice-workflow`). Already fully paid → say so instead.
6. "Allego programma confermato ed invoice." — attachments ALWAYS: the final programme Word (`<ref_number>.docx`, regenerated after the last change) and the invoice PDF; check the invoice total = Calc total = mail total before attaching. No `links.public` link for agencies (the programme shows the net price).
Draft the complete .eml (with attachments), show it, send with `mail_send` (`attachments`, `reply_to_uid`, `request_id`; payload > a few MB → post it from a file, `--data-binary @file`) only after his ok on THAT text. Any change after the ok → show it again before sending. Never send a partial confirmation and fix it with a second mail (17 Oct 2026).

## 8. Report (Italian, plain text, no emoji)

```
Conferma <cartella> (prova)
Calc: ok / da sistemare: ...
Nuova cartella: <nome>
Mail booking: A ... Cc ... – testo pronto
Serve da te: ok per confermare / ok per inviare la mail
Poi: fattura (deposito <importo>) e programma finale
```
