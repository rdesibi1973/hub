---
name: sales-agent
description: Savannah quotation for an agency or direct client: request in the Hub, standard programme, Calc pricing, ITI programme (Word/PDF), quote email draft in the client's language.
---

# Sales agent (quotation)

Use when Roberto asks to quote a request ("fai il preventivo", "quota", "prepara la proposta", "rispondi all'agenzia con i prezzi"), to change a proposal the client asked to modify, or types /sales-agent.
Reply to Roberto in Italian; client/agency texts in the recipient's language. Short: what is ready, what needs his ok.

Hub access and fixed rules: as in the project instructions (dry run first, confirm only after his ok; no mail without his ok; never passport numbers; stop and say so when something fails or doesn't add up).
Full costing rules (Calc cells, flights, transfers, concession fees, TL groups): project memory `pricing-and-pdfs`. Read it before touching a Calc.

Memo: at the start create one `doing` memo (clear title with the folder name, linked request, stable `ext_key` e.g. `work-<client>-preventivo`); update it if Roberto says "parcheggia" (where we are + exact next step). When Roberto says the work is finished, delete it with `memo_delete` (dry run, then `confirm`; don't leave it as done). Never close it on your own.

## 1. The request

- Roberto points to the mail. Agency requests often arrive in Gmail, not Bluehost: search both before saying a mail isn't there. Note the address it arrived on: the reply goes from the same one.
- Summarise: client / agency, pax (adults, teens 12–15, children under 12), dates, budget, wishes.
- `find_requests` (name, email, agency) for duplicates. Not in the Hub → `create_request` with agency, agent, date, pax, period, initial request, `notify_agent: true`.
- Agency not in the Hub: `list_agencies` (try the short name and part of the name) first; if it is really missing, propose `create_agency` (name, type `savannah` / `promoservice` for Promoservice agencies / `lamprati`, email if known) — dry run, show Roberto the short name used in the folder tag, `confirm` after his ok; a 409 means it exists: use that id.
- Naming: fam trips → agency + "FamTrip"; Kenya in the name if included; agency private groups get an agency+month code (e.g. TTOCT), never "GRP" unless it is a gruppo a raccolta.

## 2. Programme and prices

1. Pick the closest standard programme (Duma, DumaShort, Pumba, Simba, Kiboko, Gran Safari, Migrazione…) and say why. Stance: no North Serengeti in January, Manyara not recommended, Ndutu fine as a stop near the crater.
2. `copy_program` → Word + Calc in the request folder (`NN_<folder>_<Prog>`).
3. `get_rates` for programme prices per pax, flights, transfers.
4. `fill_calc` → show key values (cost, margin, D9, D10, F9) → confirm after ok → `read_calc` to verify.
   - Every day whose lodge you change or add (beach nights beyond the template too) needs `accommodation` (J): a number or a formula (`"=279"`, `"=(250+71)*$B$1"`); park fees in `park_fees` (F) / `park_fees_desc` (G) when they change. A "hotel changed, J cost left from the template" warning or a "Hotel without accommodation cost" error means the margin is wrong: fix it before showing prices.
   - Safari nights 1–25 August: `"august_supplement": true` (35 pp per safari night, beach nights excluded, added to F9); `false` only if it really does not apply.
   - Check `rows[]` of the dry run (B–K per day, `changed`) before the confirm.
5. Which price goes in F9 (Price to customer):
   - Direct clients: STANDARD mode (markup 25%, D1) → rounded D9 = rack price → F9 (and H9).
   - Agencies: rounded D10 = STO price (markup TO in D2: 18% for standard agencies such as the Promoservice ones; 14% for EKO) → F9.
   - STOSPEC programmes (a pricing mode, not an agency): D2 = 14% (16% for GRP files); rounded D10 → F9 only; delete H9/H10 and the I9/I10 labels.
   - The F9 figure is the same in Calc, programme and email.
6. On the first pass also:
   - keep only the PAX sheet for the pax count; clear H6:I14 when adults only;
   - round up to the next multiple of 5; teen/child prices are NOT rounded;
   - ZNZ/Pemba transfers per car (not per person) in F10, after the per-person total;
   - flights in col C (Auric + fuel surcharge); in-park camps with concession fee in the accommodation column; August supplement only on safari nights.
   - ZNZ airport transfers: 60 per car per leg, as in the templates (≈70 rack after markup); don't lower them.
   - Mvuvi: the template cost is the July–August rate; for other periods use the STO contract in /000_Contracts/Zanzibar/Mvuvi (season + pax) + HB 25 pp. Deluxe 2 pax HB: 279 (16/1–31/3, Jul–Aug), 245 (1/4–30/6, 1/9–22/12), 313 (23/12–15/1, + compulsory dinners).
   - Flight Arusha → Zanzibar: cost always 235 (`=235*$B$1`), same in the Hub rate table.
7. Teens: AFTER the last `fill_calc` (it clears H6:I14), write the teen discount in H13 with the label in I13 (e.g. "teen discount (2 teens 13 y.o.)") and subtract it in F10 (e.g. `=F9*$B$1-H13*2`, with ZNZ transfers `=F9*$B$1+140-H13*2`), note "Inc teen discount" in G10. Keep cached values (edit the sheet XML, not via openpyxl).
8. Calc not open in Excel on his PC; wait for Dropbox sync before the next Hub action.

## 3. Client programme (ITI, Magazine layout)

1. `iti_samples` → closest sample; tell Roberto which and why.
2. `iti_create_personal` with `sample_id`, `lead_request_id`, fields: `ref_number` = Calc name without `_Calc` (e.g. `01_MarcoCiaolo(OceanoPoint-Roberto)_PumbaSafari`), `title_<lang>`, `start_date`, `pax_adults`, `pax_teens`, `pax_children`, `display_language`, `price_table_json` (`[{label, price, currency}]` from the Calc), `price_notes_<lang>`.
3. Client changes → `iti_update_day` (texts, lodge with `end_lodge`, meals, `room_type`) / `iti_update_program`.
4. Check with `iti_program`: days, lodges, dates, prices; flag days without photo or text (lodge photos from the lodge's own website when missing).
5. `iti_document`: `docx` for agencies, `pdf` for direct clients; saved as `<ref_number>.docx/.pdf` in the request folder; on 409 ask before `overwrite: true`. Regenerate after every change.
6. `iti_publish` only if Roberto wants the public link; he puts the link in the mail himself.

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
- Don't mention international flights unless asked; don't offer to "verify availability and block everything" on a loose ok.

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
