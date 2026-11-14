---
name: back-office
description: Savannah office clerk work (not sales): chase payments at due dates, CK after bookings, arrivals/departures cross-check, lunch boxes, Medivac list, domestic flight tickets, vouchers, rooming lists, ResRequest checks, pre-departure travel info mails.
---

# Back office (office clerk, not a seller)

Use when Roberto asks for office / operations checks: payments to chase, CK, arrivals and departures, lunch boxes, Medivac, flight tickets, vouchers, rooming lists, ResRequest — or types /back-office.
Sales work (requests, quotes, proposals) is `sales-agent`. Related office skills: `daily-check` (the round), `invoice-workflow` (invoices and payments), `booking-confirmation` (confirming a booking), `supplier-payments-review` (supplier payment lists), `agency-email` (any mail).
Reply to Roberto in Italian, short: what is open, what needs his ok, next step.

Hub access and fixed rules: as in the project instructions (dry run first, confirm only after his ok; no mail without his ok; never passport numbers; stop and say so when something fails or doesn't add up).
Memo: at the start of a piece of work create one `doing` memo (stable `ext_key` e.g. `work-backoffice-<topic>-<date>`); delete it with `memo_delete` (dry run, then `confirm`) only when Roberto says the work is finished.

Tools: Agent API first. The dedicated back-office actions are specified in the Project doc `claude/HUB_BACKOFFICE_API_HANDOFF.md`; check `docs/AGENT_API.md` to see which are live. Until an action exists, use what the API already has (`find_requests`, `read_calc`, `find_invoices`, `get_invoice`, `memo_*` incl. `memo_delete`) plus the Dropbox connector, and say clearly which part could not be checked. The Operations module (https://hub.savannahexplorers.com/modules/operations/index.php: arrivals/departures, lunch boxes, Medivac) is the reference the staff use.

## 1. Payments to chase (no "sleeping" bookings)

Goal: every payment is requested on time and no confirmed booking sits without a reason.
- Sources: Hub Leads → Payments (https://hub.savannahexplorers.com/modules/leads/payments.php), `find_invoices unpaid=1`, folder tags in `/001_Safari/`.
- Terms: deposit 30% at confirmation; balance 60 days before start (direct clients) or 45 days (agencies); Il Diamante: no deposit, full balance 30 days before arrival.
- Flag:
  - confirmed folders still `_DEPOSIT` (deposit not received) — how many days since confirmation, last contact;
  - balances due in the next 14 days or overdue (`_BALANCE`, `_BALANCE-CASH`);
  - confirmed bookings with no invoice yet.
- For each: propose the chase mail (draft per `agency-email`, folder name in the subject, amount, due date, bank details as on the invoice). A payment announced but not arrived is "in arrivo": never registered or marked paid.
- SH invoices: check the payment memo exists (see `invoice-workflow`). SE: no memo.

## 2. CK (bookings completed)

- `_CK` goes on the folder as soon as all bookings are done (lodges, flights, transfers, activities), not just before departure.
- Find confirmed folders without `_CK` and say for each what is still missing (Calc day rows without INVOICE / CHECKED, flights not ticketed, lodges not confirmed). Use the SafariCheck / CK tracker result when available (red / yellow items).
- Folders departing within 30 days without `_CK` = priority, at the top of the report.
- Adding `_CK`: `rename_folder` dry run (CK stays last in the name) → confirm after Roberto's ok.
- No CK outside Tanzania (the CK tracker skips other destinations).

## 3. Arrivals and departures — cross-check

The Hub extracts arrivals/departures from the Calc Excels (A51 arrival, A56 departure) and the staff add some by hand. Cross-check for the period Roberto gives (default next 14 days):
- every booked folder starting/ending in the period is in the Operations arrivals/departures list;
- date, flight and time match the Calc (A51 / A56) and, if present, the flight ticket in the folder;
- manual entries with no matching booking folder → flag;
- missing flight details (TBA) close to arrival → flag, propose asking the agency/client.
Anderson and Harrison handle arrivals and departures: the report is for them too (English list if Roberto wants to forward it).

## 4. Lunch boxes

- From the Calc Excels: guests sleeping in Arusha (Arusha Explorers Lodge or other Arusha hotel) the night before a safari day that needs a lunch box.
- Build the list per date: lodge, client / folder, pax, departure time if known, dietary restrictions and notes (vegetarian, allergies, etc. from the Calc / CustomerInfo).
- Done in the Hub Operations module (lunch boxes); compare with what is already there and report differences. Nothing is sent to the lodge without Roberto's ok.

## 5. Medivac list

- From the Hub (Operations → Medivac): list of people + nationality for safaris in Tanzania.
- Include: Tanzania safaris, and trekking + safari (the safari wins). Exclude: trekking only, other destinations (Kenya, Zanzibar-only, etc.).
- Check every guest has a name and nationality; "TBA" names → flag. Never passport numbers.
- Sending to Medivac (Joleen) only after Roberto's ok.

## 6. Domestic flight tickets (weekly)

Once a week (Monday, or the first round of the week — see `daily-check`): `flight_tickets` with `from` = today, `to` = today + 14 days. It lists the confirmed bookings starting in the window with a domestic flight booked / bought by Savannah (Calc FLIGHT cost > 0 = flight included in the programme) and the ticket files in the folder.
- Tickets are always saved in the booking's `flights/` sub-folder.
- `ok` → ticket present: check names, date and flight against the Calc; if not yet sent, propose the mail to the agency / client with the ticket attached (draft per `agency-email`).
- `missing` → flag it (who booked it, Glady / Lydia) and propose asking the airline for the ticket.
- `unknown` → Calc not readable: check by hand.
- Before the 2-week window a missing ticket is not a problem (tickets come late); `booking-check` only notes it as info.
- Names on tickets have no spaces in compound names (e.g. DICESARE): not a discrepancy.

## 7. Vouchers, rooming lists, ResRequest

- Vouchers: for departures in the next 30 days, check the vouchers are in the folder and were sent.
- Generating vouchers — two ways, depending on the programme (5 Nov 2026):
  - ITI programme (Hub personal/final programme linked to the booking, `iti_programs` with `lead_request_id`): `iti_vouchers` with `program_id` (`preview: true` first), or the "Vouchers" button in the ITI editor;
  - WeTu programme (Word made in WeTu, no ITI programme): the Hub voucher generator from Word + Calc (https://hub.savannahexplorers.com/modules/iti/vouchers.php, Roberto logs in in the browser; upload the booking's programme .docx and the Calc .xlsx). Don't create an ITI final programme just to get vouchers.
  In the review: travellers, rooms and meal basis per lodge, transfers; fill flight number and time on the arrival and departure transfers (from the Calc A51 / A56). The PDF goes in the booking's `vouchers/` sub-folder.
- New flight plan or client phone numbers from the agency: arrival / departure in the Calc (A51 / A56); client phones as a note in the Calc next to the guest names (column G), not in the Hub (5 Nov 2026).
- Rooming lists to lodges: rooms exactly as per the request (room type and pax), names as in the Calc; propose the mail to the lodge.
- ResRequest (Orangi Collection lodges): check that our confirmations still match what is booked (dates, rooms, pax), as in the ResRequest plan; flag differences.
- Booking in ResRequest when Roberto asks (14 Nov 2026, GRP1001 Belli), in his Chrome (Claude in Chrome): ResRequest opens pop-up windows Claude can't see (agent search, documents) and the page freezes. So Roberto picks agent and rate (Savannah Explorers Ltd, Complimentary) and selects the rooms in the Availability Calendar. Claude checks the selection (rooms, dates, 2A per room), then Create Itinerary → Make Booking → Details (reservation name "<Client> <GRP code>", voucher ref, notes: block, meal plan per night, special needs, names), status as Roberto says → Guest details → summary. Then open the reservation and check it shows the right status.
- Voucher after every booking Claude makes: Documents → Itinerary Summary, saved as PDF in the folder's `invoices/` (Roberto saves it: pop-up), renamed by Claude `The Orangi collection sv <WB number>.pdf` and read back (status, rooms, dates). Then tell the booking team the Orangi part is already booked (WB number, voucher in invoices/).

## 8. Travel info before departure (Jambo, visa, NIC / ZIC, Ebola form)

The pre-departure mail, same as the Hub Booked page button "✈ Travel info" (Agent API `predeparture_list` /
`predeparture_email`, see `docs/AGENT_API.md`). Default window: arrivals in the next 21 days.
- `predeparture_list` with `only_unsent: 1` (and `days` if Roberto gives a period). Show Roberto a table: arrival, folder,
  agency / direct (`-Drct` in the folder tag = direct client), email, proposed `lang` and `countries`.
- `defaults` are a guess (itinerary if linked, else folder words; language falls back to `it`). Check them: language of
  the client / agency from the thread or the Calc; countries from the Calc / programme (`tz` mainland, `znz` Zanzibar,
  `ke` Kenya). Pemba / Mafia count as `znz`.
- Recipient: the request email; if empty or wrong, find the right one (thread, agency contacts) and pass `to`. Agency
  bookings: the mail goes to the agency for their clients — adapt the greeting / opening ("vi giriamo le informazioni di
  viaggio per i vostri clienti …") with `body`, or ask Roberto whether the agency wants it at all.
- `predeparture_email` dry run for each → show Roberto recipient, subject and the text (short summary is fine when he has
  already seen one in the same language). Send only after his ok, with `"confirm": true`: it goes from the Hub as the
  request's agent (signature, Reply-To), logged on the request, and the Booked page shows ✓.
- If Roberto wants a draft instead: `.eml` per `agency-email` with the dry-run subject and body (then the Booked page
  doesn't show it as sent).
- Never add facts the mail doesn't have; if an entry rule changed (visa, NIC, Ebola form), tell Roberto: the texts are in
  `modules/leads/includes/predeparture_service.php` (Claude Code changes them).

## 9. Report (Italian, plain text, no emoji)

```
Back office – <data> – periodo <dal> <al>

Pagamenti da sollecitare: <N>
- <cartella> – <deposito/saldo> <importo> scad. <data> (<giorni> gg) → bozza mail pronta / da preparare
CK: <N> pratiche senza CK (<M> in partenza entro 30 gg)
- <cartella> – manca: ...
Arrivi/partenze: <N> ok, <M> da sistemare: ...
Lunch box: <date> – <N> persone – differenze: ...
Medivac: <N> persone – mancano nazionalità: ...
Biglietti voli interni: mancanti <N>, da inviare <M>
Voucher / rooming list / ResRequest: ...
Info di viaggio: <N> partenze entro 21 gg senza mail → <inviate / da approvare>

Non verificato (manca l'azione API): ...
Serve da te: ...
```
