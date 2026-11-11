# Rules for everything an agency or a client sees

Not a skill: the shared rules that `sales-agent`, `agency-email` and `booking-confirmation` read before writing anything an agency or a client sees: the ITI programme, its Word / PDF, the client link, quote and confirmation emails. Later the Hub's AI buttons for the staff will read this same file (Phase 2), so the rules live here only, not copied in the skills.
Only rules about the content go here. How to work (asking Roberto, dry runs, the order of the API calls) stays in each skill.
When a rule changes, change it here (with the date) and not in a skill.

## 1. Prices

- The price to the customer (Calc F9) is the same figure in the Calc, the programme and the email. The only exception is a special price for a direct client, which goes only in the email (see below).
- **Direct clients:** the programme (ITI / PDF) always shows the rack price (F9), also when we grant a discount or a special price. The discount or special price goes only in the quote email ("per voi possiamo fare … usd per persona"), never in the programme or the PDF, so a programme forwarded to an agency shows the rack. Never give a direct client the STO or net price. No "riservata / personale" disclaimers (16 Oct 2026).
- **Agencies:** net price, "quota netta", "base N pax" (Italian programme price line: "NNNN USD - base N pax").
- **Group departures** (Simba / Duma / Nyumbu di gruppo, GRP): never "base N pax" or "quota netta per il gruppo di N pax" in the email, the ITI price table or the price notes, because the group may hold other travellers. Write "quota netta per persona" (23 Oct 2026, DiCredico).
- **Private group quoted for several group sizes** (one Calc pax sheet per size, e.g. 10 / 11 / 12 pax): one price row per size, "Quota per persona, base N pax", smallest group first, no total. Never for GRP group departures (7 Nov 2026).
- Prices are whole numbers (ITI prints no decimals): teen and child prices are rounded to a whole number so the Calc, the Word and the email show the same figure.
- ZNZ / Pemba transfers are per car, on their own line after the per-person total.

## 2. Links and attachments

- **Agencies:** never the `links.public` programme link, in an email or anywhere else. The programme shows our net price and the agency must not forward it to its client. The programme goes as the Word attachment (`<ref_number>.docx`) (19 Oct 2026).
- **Direct clients:** the link in the email ("Qui trovi il programma dettagliato giorno per giorno, con le foto dei lodge: <link>") and the PDF attached.

## 3. Names never shown

- **Domestic flights and balloon safari, before booking:** never name the airline or the balloon operator (programme, Word, email), because we know who operates them only after booking. In ITI a flight taken from the route table shows its operator ("· Auric Air"): use `flights: [{custom: "Arusha → Serengeti (Seronera)"}]` instead of `flight_route_id`, and write the inclusions as "voli interni Arusha – Serengeti e ritorno" / "safari in mongolfiera", with no company name. After booking, the final programme and the confirmation to the agency do carry airline, flight number and times (see `booking-confirmation`).
- **Partners** (the Kenya DMC, later Uganda / Madagascar …): never mention them to agencies or clients, not for availability, quotes, lodges or emergencies. They see Savannah as handling everything. Write "we will check availability", not "with our partner in Kenya". The client-facing emergency contact is always Savannah's office; don't mention the partner's Medivac (12 Oct 2026). Every text must be free of the partner's name, brand, domain, e-mails and phone numbers: the Hub refuses (422) a programme text containing them (list: Hub → ITI Settings → Partner names; `iti_supplier_blocklist`).

## 4. Wording

- English emails and programme texts: "program", never "programme" (Roberto's preference, 19 Oct 2026).
- **Group programmes** (STO / Gruppi Word, ITI programmes of GRP folders): never "Safari privato" or "jeep ad uso esclusivo". Group wording: "Safari di gruppo" and "jeep … condivisa con gli altri partecipanti del gruppo". ITI programmes with `safari_type` group get the right cover; the jeep line lives in the inclusions (`iti_update_inclusions`) (26 Oct 2026).
- **Private safaris:** no sample text that contradicts it (e.g. "transfer condiviso con altri viaggiatori").
- **Italian programmes:** no shared-transfer disclaimer on Day 1; "Pranzo pic-nic nel cratere"; "under 12"; guide "in lingua italiana"; savannahexplorers.com; "NIC o ZIC" as mandatory insurance in the exclusions (§5); meal plan stated for beach and Arusha stays.

## 5. Costs outside the quote (exclusions)

- **NIC or ZIC**, the mandatory insurance: one OR the other, depending on the point of entry — mainland Tanzania (Kilimanjaro, Arusha, Dar es Salaam) → NIC; Zanzibar → ZIC. Only one is needed. USD 44 per person; in force since 1 Oct 2026, bought before travel or on arrival; it does not replace the client's own travel insurance. Italian exclusion line: "NIC o ZIC (assicurazione obbligatoria, a seconda del punto d'ingresso), USD 44 per persona"; when the entry point is certain (e.g. arrival at Kilimanjaro) the programme and the email may name only the right one ("NIC (assicurazione obbligatoria per la Tanzania)"). Never "NIC e ZIC" (26 Oct 2026; corrected by Roberto 11 Nov 2026, EKOSEP27Jabali).
- **Zanzibar entry:** no separate visa, the Tanzania visa covers it. Flights Serengeti (Seronera) – Dar es Salaam: no direct flights, they stop in Zanzibar; don't say whether clients change plane (9 Nov 2026, RemoLenci).
- **Zanzibar** infrastructure tax: USD 5 per person per night, paid at the hotel. Only with Zanzibar.
- **Kenya:** mandatory Kenya health insurance, USD 44 per person (children included) plus the portal fees, bought only on the government portal together with the eTA (etakenya.go.ke or insurance.ecitizen.go.ke; no policy document: keep the reference and the "Valid" screenshot). In force since 6 Oct 2026; EAC citizens exempt. Italian exclusion line: "assicurazione sanitaria obbligatoria per il Kenya, USD 44 per persona (bambini inclusi) più le commissioni del portale, da acquistare sul portale governativo insieme all'eTA" (15 Oct 2026).
- **Mafia:** "tasse del Parco Marino di Mafia, da pagare con carta di credito all'arrivo", "immersioni ed escursioni extra a Mafia". The flight Arusha – Mafia is "non diretto, di solito con scalo a Dar es Salaam".
