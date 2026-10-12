---
name: agency-email
description: Draft any Savannah email to agencies, direct clients or staff (replies, updates, complaints, payment notes) in Roberto's style, as an .eml draft in Exchange/Draft-mail from the right address.
---

# Agency / client / staff email

Use when Roberto asks to answer or write a mail ("rispondi a…", "prepara la mail", "scrivi all'agenzia", "manda allo staff"), or types /agency-email. Other skills point here for where drafts go and for style.
For the content of a quotation (prices, programme), see `sales-agent` §4.
Talk to Roberto in Italian; the mail is in the recipient's language (Italian, English, French, Spanish).
Fixed rules as in the project instructions: never send without his explicit ok on text and recipients; never passport numbers.

## 1. Read first

- Read the whole thread Roberto points to (Gmail, or info@ via `mail_get`) and note the address it arrived on.
- Find the request/booking (`find_requests`) for the folder name and the facts (dates, pax, lodges, payments). Never invent facts: if something is unknown, ask Roberto or leave a clear [DA COMPLETARE].
- If Roberto gives exact wording, use it verbatim.

## 2. Where the draft goes

- ALWAYS a draft as an `.eml` file in `C:\Dropbox\Exchange\Draft-mail` (Dropbox `/Exchange/Draft-mail/`), named `YYYY-MM-DD_<folder name>_<topic>.eml`; when the mail is about a request/booking, save a copy in that folder's `mails` subfolder too. No Hub `mail_draft`, no Gmail-connector drafts (12 Oct 2026).
- The .eml must be HTML: multipart text + html, header `X-Unsent: 1`, UTF-8; default font, no width limit (remove the `max-width` from the signature div).
- Replies: `From` = the address the mail arrived on (info@savannahexplorers.com or savannah.explorers@gmail.com); `To` = the sender; subject "Re: …"; `In-Reply-To` and `References` = the original Message-ID (from `mail_get` or the Gmail connector) so it threads.
- Replies quote the original mail below the signature, as a normal reply does: a line "Il <gg/mm/aaaa hh:mm>, <mittente> ha scritto:" (English "On <date>, <sender> wrote:"), then the original body as received (`mail_get` `body`, with the earlier thread it contains), in a `<blockquote type="cite">` in the html part and with "> " lines in the text part; strip empty lines at the start (12 Oct 2026).
- New mails (no thread) and mails to staff: `From` Gmail (savannah.explorers@gmail.com).
- Signature at the bottom matching the address: `InfoSavannahExplorers.html` (info@) or `OfficeGmail.html` (Gmail), in `C:\Dropbox\001_Safari\99_office\`. The signature already holds "Rimango a disposizione…", "Buona giornata", "Roberto": don't repeat them in the body.
- The signature images (Roberto's photo, Tripadvisor badge) are links to savannahexplorers.com: Thunderbird hides remote images when a draft is opened from file, recipients see them. Don't embed or change them.
- Take the signature's raw HTML from his PC (`device_stage_files` or the device shell) and paste it as is into the html part. Never rebuild it from the Dropbox connector `fetch` text: that is extracted plain text and loses the formatting (12 Oct 2026).
- Attachments only when Roberto asks.
- Write the file to his PC (device shell or `device_commit_files`); if the PC is not reachable, stop and tell him.

## 3. Subject

Every mail about a request/booking carries the Dropbox folder name: `Re: <original subject> - <folder name>` (new mails: `<subject> - <folder name>`), without the payment suffix (_DEPOSIT, _BALANCE, _BALANCE-CASH, _PAID, _CK).

## 4. Style

- Italian: informal "tu"; opens "Buongiorno <Nome>,"; closes with "Rimango a disposizione per maggiori informazioni e domande." on its own line, then "Buona giornata" and "Roberto". Never "Cordiali saluti".
- English / French / Spanish: same warm, professional trade tone and structure (greeting with the name, short closing, "Roberto").
- Official/legal letters: minimal, direct, formal.
- Complaints: diplomatic, non-confrontational — acknowledge the feedback without admitting fault, de-escalate, propose a next step.
- Short paragraphs; when there are several items, lists under short heading lines ("Programma:", "Prezzi:", "La quota include:", "Non include:").
- HTML body: default font, no forced font or size, no width limit. Little or no bold. Never emoji.

## 5. Standard content (when relevant)

- Payment terms: 30% deposit to confirm; balance 60 days before start (direct clients) or 45 days (agencies). Il Diamante: no deposit, balance 30 days before arrival.
- On confirmation Savannah needs passport copies (park permits) and a copy of the travel insurance; the client's insurance must cover emergency evacuation; Savannah provides MEDIVAC.
- Costs outside the quote: NIC mandatory insurance for Tanzania USD 44 pp (NIC, not a Zanzibar insurance); Zanzibar infrastructure tax USD 5 pppn paid at the hotel.
- International flights only if asked: "Possiamo prenotare anche i voli internazionali dall'Italia, spesso abbiamo buone tariffe Tour Operator. Per opzionare senza impegno i voli internazionali abbiamo bisogno di: cognomi / nomi esattamente come sono scritti sul passaporto e le date di nascita; aeroporto di partenza; date con eventuale flessibilità".
- A payment is "received" only when the money has arrived; a transfer announced is "in arrivo".
- Kenya trips: never mention the Kenya partner to agencies or clients (not for availability, quotes, lodges or emergencies) — they see Savannah as handling everything. Write "we will check availability", not "with our partner in Kenya". The client-facing emergency contact is always Savannah's office; don't mention the partner's Medivac (12 Oct 2026).
- Standalone excursions for clients who didn't book the package: politely declined (easy to arrange locally).
- Lodge-only (Orangi Collection) enquiries: no Savannah road transfers between Serengeti/Ngorongoro lodges; transfers by bush flight, arranged on their side.

## 6. Don't

- Offer to "verify availability and block everything" on a loose ok.
- Mention passing the Word programme onto the agency letterhead.
- Tell agencies about staff joining a fam trip for free.
- Proofread programmes agencies rewrite for their clients (answer only the specific points they ask).

## 7. Staff mails

English, short, factual: folder name in the subject, what to do, by when. Booking teams: rooms exactly as per the request (room type and pax). Incoming payment alerts: forward the agency's original payment mail with its attachment to Saruni and Doreen, folder name in the subject.

## 8. Report to Roberto (Italian)

Show from / to / cc, subject, full text, attachments, then: "Bozza salvata in Exchange/Draft-mail/<file>.eml (da <indirizzo>). Serve da te: ok sul testo e sui destinatari."
