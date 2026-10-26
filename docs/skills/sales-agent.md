---
name: sales-agent
description: Savannah quotation for an agency or direct client: request in the Hub, standard programme, Calc pricing, ITI programme (Word/PDF), quote email draft in the client's language.
---

# Sales agent (quotation)

Use when Roberto asks to quote a request ("fai il preventivo", "quota", "prepara la proposta", "rispondi all'agenzia con i prezzi"), to change a proposal the client asked to modify, or types /sales-agent.
Reply to Roberto in Italian; client/agency texts in the recipient's language. Short: what is ready, what needs his ok.

Hub access and fixed rules: as in the project instructions (dry run first, confirm only after his ok; no mail without his ok; never passport numbers; stop and say so when something fails or doesn't add up).
Full costing rules (Calc cells, flights, transfers, concession fees, TL groups): project memory `pricing-and-pdfs`. Read it before touching a Calc.

Memo: at the start create one `doing` memo (clear title with the folder name, linked request, stable `ext_key` e.g. `work-<client>-preventivo`); update it if Roberto says "parcheggia" (where we are + exact next step). When Roberto says the work is finished, delete it with `memo_delete` (dry run, then `confirm`; don't leave it as done). Never close it on your own. The memo covers only this session's work (e.g. "quote + reply to the agency"): later steps (waiting for the booking team's confirmations, invoice, final programme) do NOT go in its next_steps — they are done in a new session with a new memo (15 Oct 2026).

## 1. The request

- Roberto points to the mail. Agency requests often arrive in Gmail, not Bluehost: search both before saying a mail isn't there. Note the address it arrived on: the reply goes from the same one.
- Summarise: client / agency, pax (adults, teens 12–15, children under 12), dates, budget, wishes.
- `find_requests` (name, email, agency) for duplicates. Not in the Hub → `create_request` with agency, agent, date, pax, period, initial request, `notify_agent: true` — **always**, also in the dry run: the API default is false and the seller must always be notified.
- Agency not in the Hub: `list_agencies` (try the short name and part of the name) first; if it is really missing, propose `create_agency` (name, type `savannah` / `promoservice` for Promoservice agencies / `lamprati`, email if known) — dry run, show Roberto the short name used in the folder tag, `confirm` after his ok; a 409 means it exists: use that id.
- Naming: fam trips → agency + "FamTrip"; Kenya in the name if included; agency private groups get an agency+month code (e.g. TTOCT), never "GRP" unless it is a gruppo a raccolta.

## 2. Programme and prices

**Availability of our own lodges (Marera, Kifaru, Orangi Luxury, Arusha Explorers).** Before a proposal goes out, check the public ResRequest calendar https://theorangicollection.resrequest.com/page/public/availabilitycalendar in the built-in browser (no login). The calendar sits in an iframe (`reservation.php?20`): set the start date (`dateSelectorSelect` / `tempCalStartDate` + the text field, then `reloadPage()` in the iframe) and read the table. Each number = rooms free that night for that room type; 0 = full; `*` = provisional bookings on it. A couple needs Dbl rooms; "3 Sngl" rooms only after asking the booking team. If one of our lodges is full on a programme date, tell Roberto before generating the PDF / email: rooms marked `*` may be freed by the booking team, otherwise find an alternative (Roberto may send the proposal first and solve it once the client confirms) (22 Oct 2026, NicolaMarta).

1. Pick the closest standard programme (Duma, DumaShort, Pumba, Simba, Kiboko, Gran Safari, Migrazione…) and say why. Stance: no North Serengeti in January, Manyara not recommended, Ndutu fine as a stop near the crater.
2. `copy_program` → Word + Calc in the request folder (`NN_<folder>_<Prog>`).
3. `get_rates` for programme prices per pax, flights, transfers.
4. `fill_calc` → show key values (cost, margin, D9, D10, F9) → confirm after ok → `read_calc` to verify.
   - Every day whose lodge you change or add (beach nights beyond the template too) needs `accommodation` (J): a number or a formula (`"=279"`, `"=(250+71)*$B$1"`); park fees in `park_fees` (F) / `park_fees_desc` (G) when they change. A "hotel changed, J cost left from the template" warning or a "Hotel without accommodation cost" error means the margin is wrong: fix it before showing prices.
   - Safari nights 1–25 August: `"august_supplement": true` (35 pp per safari night, beach nights excluded, added to F9); `false` only if it really does not apply.
   - Check `rows[]` of the dry run (B–K per day, `changed`) before the confirm.
   - `fill_calc` writes neither jeep (D) nor drinks (E), and does not clear activities (H/I) left on a template row that now holds another day (`"activity": null` leaves them). When the trip has more days than the template, or days move: in the dry run check that every safari day has D/E and that each activity sits on its own day. If not, after the confirm fix the sheet XML (D `=$D$5*$B$5`, E `=($B$2+$B$3)*4`, move/clear H/I), update the cached values (row 30 totals, B7, B9, D9, B10, D10), check with a recalculation on a copy, then `read_calc` (17 Oct 2026, Del Fiandra Calc 05).
   - Beach booked by the agency itself ("a ZNZ si arrangia l'agenzia"): start from the safari-only programme (e.g. `Simba`, not `BeachSimba`) and add the beach nights yourself as "<place> - own arrangement" (no J cost, no beach transfers) up to the departure day. `fill_calc` cannot clear labels (B) or dates of leftover template rows, so a Beach template with more beach nights than the trip leaves extra days and fails the check. When the safari gets shorter, the row that becomes a beach day keeps the template's jeep/drinks (D/E): clear them in the sheet XML as above (21 Oct 2026, AmedeoMarino).
5. Which price goes in F9 (Price to customer):
   - Direct clients: STANDARD mode (markup 25%, D1) → rounded D9 = rack price → F9 (and H9).
   - Direct clients: the programme (ITI / PDF) always shows the rack price (F9), also when we grant a discount or a special price. The discount or special price goes only in the quote email ("per voi possiamo fare … usd per persona"), never in the programme or the PDF, so a programme forwarded to an agency shows the rack. Never give a direct client the STO or net price. No "riservata / personale" disclaimers (16 Oct 2026).
   - Direct clients with lodge changes and a beach extension: F9 = components, e.g. `=3625+270+435+195+2010+210` — standard programme rack + lodge-upgrade supplement (cost difference × 1.25, rounded up to 5: Kifaru → Orangi Luxury, 2 nights = 270) + domestic flights at the pricelist sale price + beach hotel at its rack rate + August supplement (added by `august_supplement`).
   - Agencies: rounded D10 = STO price (markup TO in D2: 18% for standard agencies such as the Promoservice ones; 14% for EKO and for Etnia (21 Oct 2026)) → F9. `fill_calc` does not change D2: when the markup differs from the template's, set D2 in the sheet XML after the confirm, with the cached values (B9, D9, D10, B10).
   - STOSPEC programmes (a pricing mode, not an agency): D2 = 14% (16% for GRP files); rounded D10 → F9 only; delete H9/H10 and the I9/I10 labels.
   - The F9 figure is the same in Calc, programme and email, except a special price for a direct client, which appears only in the email.
6. On the first pass also:
   - keep only the PAX sheet for the pax count; clear H6:I14 when adults only;
   - round up to the next multiple of 5; teen/child prices are NOT rounded;
   - ZNZ/Pemba transfers per car (not per person) in F10, after the per-person total;
   - flights in col C (Auric + fuel surcharge); in-park camps with concession fee in the accommodation column; August supplement only on safari nights.
   - ZNZ airport transfers: 60 per car per leg, as in the templates (≈70 rack after markup); don't lower them.
   - Mvuvi: the template cost is the July–August rate; for other periods use the STO contract in /000_Contracts/Zanzibar/Mvuvi (season + pax) + HB 25 pp. Deluxe 2 pax HB: 279 (16/1–31/3, Jul–Aug), 245 (1/4–30/6, 1/9–22/12), 313 (23/12–15/1, + compulsory dinners).
   - Flight Arusha → Zanzibar: cost always 235 (`=235*$B$1`), same in the Hub rate table.
   - Mafia extensions: flights from the Hub rate table (`flight` 60 Arusha → Mafia, 65 Mafia → Dar es Salaam); guests leave via Dar. Last day: Dar domestic → international terminal transfer, 50 per car in H/I ("Dar transfer domestic -> international airport"), added after the per-person total: F10 `=F9*$B$1+50`, G10 "Inc Dar transfer" (`fill_calc` does not write F10/G10: edit the sheet XML afterwards, keeping cached values for F10, B10, F11, F12). Pole Pole includes airport transfers on Mafia.
   - Mafia hotel cost (Butiama, Pole Pole): rack rate − 20% (commission on rack), so the price to a direct client equals the hotel's own rack rate. Our agency pricelist (`PolePole_Pricelist_SE_2027`) is a sale price, not the cost.
   - Festive supplement (safari programmes, SIC included): 35 USD pp per night for the nights of 24, 25, 26 and 31 December (not 1 January), as its own item in `price_components` (e.g. `=2495+105+…`) (22 Oct 2026, FabrizioFlorio).
   - `fill_calc` `days[]` are positional from row 17: to change one day only, use `row_offset` (e.g. 5 = sixth day), not the date; an activity is written as `{"amount", "desc"}`. Agency extras outside the standard package (e.g. private jeep + park fees + drinks, or a hotel such as Airport Planet Lodge) are quoted at cost × 1.14, rounded up to 5 USD, as their own price item (22 Oct 2026, FabrizioFlorio).
   - Day rows run 17–29 (row 30 = Totals): 13 days at most. A longer trip doesn't fit — say so at once and agree with Roberto what to shorten (e.g. a second North Serengeti night costs a beach night).
7. Teens: AFTER the last `fill_calc` (it clears H6:I14), write the teen discount in H13 with the label in I13 (e.g. "teen discount (2 teens 13 y.o.)") and subtract it in F10 (e.g. `=F9*$B$1-H13*2`, with ZNZ transfers `=F9*$B$1+140-H13*2`), note "Inc teen discount" in G10. Keep cached values (edit the sheet XML, not via openpyxl).
8. Calc not open in Excel on his PC; wait for Dropbox sync before the next Hub action.

## 3. Client programme (ITI, Magazine layout)

1. `iti_samples` → closest sample; tell Roberto which and why.
2. `iti_create_personal` with `sample_id`, `lead_request_id`, fields: `ref_number` = Calc name without `_Calc` (e.g. `01_MarcoCiaolo(OceanoPoint-Roberto)_PumbaSafari`), `title_<lang>`, `start_date`, `pax_adults`, `pax_teens`, `pax_children`, `display_language`, `price_table_json` (`[{label, price, currency}]` from the Calc), `price_notes_<lang>`.
3. Client changes → `iti_update_day` (texts, lodge with `end_lodge`, meals, `room_type`) / `iti_update_program`.
4. Check with `iti_program`: days, lodges, dates, prices; flag days without photo or text (lodge photos from the lodge's own website when missing). Destinations and lodges without a description in the programme language print in English: check them (`iti_destinations` / `iti_lodges` with `missing=description_<lang>`) and write the missing texts with `iti_update_destination` / `iti_update_lodge` (dry run first) before generating the file.
   - Lodge not in the Hub (e.g. a beach hotel): `iti_create_lodge` (name, `destination_id` of the area, category, type, website, email, address, coordinates, `description_it` + `description_en`) → `iti_lodge_photos` with 6–8 photos (aerial view, room, beach, dining): extracted from the hotel's rate/brochure PDF in /000_Contracts (`pdfimages`, shrunk to ~1600 px, sent as `uploads`) or links from its official website. Dry run first for both.
5. `iti_document`: `docx` for agencies, `pdf` for direct clients; saved as `<ref_number>.docx/.pdf` in the request folder. The Word that `copy_program` put in the folder (template copy, same name) is always replaced by the ITI one: save the docx with `overwrite: true` without asking (15 Oct 2026); for any other existing file, on 409 ask first. Regenerate after every change.
6. `links.public` is the client link (always on for personal programmes; `iti_publish` is only for samples).
   - Agencies: NEVER put the link in the email — the programme shows our net price and the agency must not forward it to its client. Attach the Word (`<ref_number>.docx`) (19 Oct 2026).
   - Direct clients: put the link in the email draft ("Qui trovi il programma dettagliato giorno per giorno, con le foto dei lodge: <link>") and attach the PDF.

**Programme from a partner (Kenya, later Uganda / Madagascar …) — `iti_import_program`.** When the programme arrives as text (Word / PDF / email from our DMC partner), don't retype it day by day:
1. Read the file and build the JSON yourself: `country` (`KE`, mixed `TZ+KE`), `display_language`, `fields` (`title_<lang>` + `title_en`, pax, `price_table_json`, `price_notes_<lang>`), `source {supplier, file}` (internal only), `days[]` with `day_title_en` / `day_title_<lang>`, `narrative_en` / `narrative_<lang>` (translate them yourself — both are stored), `destination`, `end_lodge` (names as in the text), `meals: "B,L,D"`, `activities` (`{custom, text_<lang>}`), `transfers`; `included[]` / `excluded[]` as `{text_en, text_<lang>}`. **Remove the partner's name, brand, domain, e-mails and phone numbers from every text** — never mention the partner to agencies or clients; the Hub refuses (422) any text containing them (list: Hub → ITI Settings → Partner names; `iti_supplier_blocklist`).
2. New destinations first (`iti_create_destination`, `country` + `type`, `name_it` too) — e.g. the Kenya parks; then the dry run of `iti_import_program` with `"create_missing": true`: check `days` (matched / new / text), `matches` (score), `to_create`, `warnings` (meals, missing destination / lodge, English left in Italian fields, NIC on a Kenya programme), `photo_gaps`, `text_gaps`. Show Roberto the table, then confirm.
3. New lodges are stubs (`needs_completion`): add photos (`iti_lodge_photos`), descriptions in the programme language and contacts (`iti_update_lodge`, then `needs_completion: 0`) before `iti_document`; `iti_lodges?missing=stub` lists what is left. Kenya exclusions (eTA + health insurance USD 44) and Savannah's T&C / office contacts come automatically.
Staff (Daniela, Alessia) ask Roberto: he runs the import from Cowork until the Hub page exists.

Never name the airline of domestic flights or the balloon-safari operator (programme, Word, email): we know who operates them only after booking. In ITI, a flight taken from the route table shows its operator ("· Auric Air"): use `flights: [{custom: "Arusha → Serengeti (Seronera)"}]` instead of `flight_route_id`, and write inclusions as "voli interni Arusha – Serengeti e ritorno" / "safari in mongolfiera" with no company name.

Italian programme rules: no shared-transfer disclaimer on Day 1; "Pranzo pic-nic nel cratere"; "under 12"; guide "in lingua italiana"; savannahexplorers.com; NIC as mandatory insurance in the exclusions; meal plan stated for beach and Arusha stays; price "NNNN USD - base N pax" (quota netta for agencies).

## 4. Quote email

Where the draft goes, subject, greeting/closing, signature and general style: follow `agency-email` (§2–§4). Quote-specific content:
- One sentence on the safari (length, private jeep, Italian-speaking guide, parks) + day-by-day list with dates, overnight lodge, meal plan.
- "Prezzi:" list, each line "usd per persona" (agencies: quota netta, base N pax); Totale per persona; transfers per car on their own line; totale pratica; single supplement as a separate total (e.g. "Totale pratica in doppia + singola").
- "La quota include:" ("trasferimenti durante il safari"…) / "Non include:" (NIC USD 44 pp; Zanzibar tax USD 5 pppn paid at the hotel, only with Zanzibar; beach transfers named precisely…).
- Domestic flights: "Le tariffe e disponibilità dei voli interni sono soggette a disponibilità al momento della prenotazione".
- No airline name for domestic flights and no operator name for the balloon safari (known only after booking).
- Only when the programme is attached: "Programma safari dettagliato allegato (considera la quota netta per il gruppo di N pax)."
- Group departures (Simba / Duma / Nyumbu di gruppo, GRP): never "base N pax" or "quota netta per il gruppo di N pax" in the email, the ITI price table or the price notes: the group may hold other travellers. Write "quota netta per persona" and close with "Programma safari dettagliato allegato (quota netta per persona)." (23 Oct 2026, DiCredico).
- Agencies: no `links.public` link in the email (net price); programme as Word attachment (19 Oct 2026).
- English emails and programme texts: write "program", never "programme" (Roberto's preference, 19 Oct 2026).
- An agency sends us a programme we already quoted to its client as a direct: Roberto evaluates case by case — don't answer with prices before his decision (16 Oct 2026).
- Don't mention international flights unless asked; don't offer to "verify availability and block everything" on a loose ok.
- Mafia: flight Arusha – Mafia "non diretto, di solito con scalo a Dar es Salaam"; exclusions: "tasse del Parco Marino di Mafia, da pagare con carta di credito all'arrivo", "immersioni ed escursioni extra a Mafia".
- Reply headers: the Gmail connector gives no Message-ID and the Hub Gmail mailbox may not be configured; then take In-Reply-To / References from the previous draft of the request (`mails/` subfolder) and say so in the report.

## 5. Report (Italian)

```
Preventivo <cartella>
Programma: <nome> (sample #id, perché)
Prezzo: <NNNN> usd per persona base N pax (Calc <file>) – margine <...>
File: <ref_number>.docx/.pdf salvato
Mail: bozza Exchange/Draft-mail/<file>.eml da <indirizzo> – Re: <oggetto>
Memo: <titolo> (doing)
Serve da te: ok su prezzo e testo
```
