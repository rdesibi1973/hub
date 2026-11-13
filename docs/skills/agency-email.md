---
name: agency-email
description: Draft any Savannah email to agencies, direct clients or staff (replies, updates, complaints, payment notes) in Roberto's style, as an .eml draft in Exchange/Draft-mail from the right address.
---

# Agency / client / staff email

Use when Roberto asks to answer or write a mail ("rispondi a…", "prepara la mail", "scrivi all'agenzia", "manda allo staff"), or types /agency-email. Other skills point here for where drafts go and for style.
For the content of a quotation (prices, programme), see `sales-agent` §4.
Talk to Roberto in Italian; the mail is in the recipient's language (Italian, English, French, Spanish).
Fixed rules as in the project instructions: never send without his explicit ok on text and recipients; never passport numbers.
Content rules for agencies and clients (prices shown, programme link, names never shown such as partners and domestic airlines before booking, wording, costs outside the quote): `rules-client-documents.md` in this folder. Read it before writing a mail to an agency or a client.

## 1. Read first

- Read the whole thread Roberto points to (Gmail, or info@ via `mail_get`; booking / staff mailboxes such as glady@, operations@ via `staff_mail_search` + `staff_mail_get` with a `reason`) and note the address it arrived on.
- Find the request/booking (`find_requests`) for the folder name and the facts (dates, pax, lodges, payments). Never invent facts: if something is unknown, ask Roberto or leave a clear [DA COMPLETARE].
- Before asking the client/agency for anything (international flights, passports, insurance, other data), check what we already have: the Calc in the booking folder (arrival/departure details with flight, airport, time, airline) as well as the folder's subfolders. Empty subfolders don't mean the data is missing (29 Oct 2026).
- If Roberto gives exact wording, use it verbatim.
- Gmail threads: read them through the Hub (`mail_get` with `mailbox=gmail`), which gives the Message-ID for `In-Reply-To` and the attachments (`mail_attachment`); the Gmail connector gives neither. Don't tell Roberto an attachment or a Message-ID can't be fetched before trying the Hub (31 Oct 2026).
- Write only what is verified: guide language, availability, contacts, prices, flight routes. If not checked, write "verifichiamo" or ask Roberto; never take prices from old Calcs (e.g. 2012) without saying so (31 Oct 2026).

## 2. Where the draft goes

- ALWAYS a draft as an `.eml` file in `C:\Dropbox\Exchange\Draft-mail` (Dropbox `/Exchange/Draft-mail/`), named `YYYY-MM-DD_<folder name>_<topic>.eml`; when the mail is about a request/booking, save a copy in that folder's `old` subfolder too — never in `mails/`: `mails/` holds only real mails received or sent (e.g. supplier proofs saved from the Hub), drafts there confuse staff and tools (12 Nov 2026). No Hub `mail_draft`, no Gmail-connector drafts (12 Oct 2026). Also when Roberto asks for a draft "in Gmail" to see the attachment: a Hub `mail_draft` with `from: gmail` and `attachments[]` lands in Gmail Drafts with the file in the MIME (the Hub reports `has_attachments`), but Gmail does not show it — Roberto could not see the Word (5 Nov 2026, KiliSimba2paxDec26). Save the `.eml` in Draft-mail and tell him that is where the attachment shows.
- The .eml must be HTML: multipart text + html, header `X-Unsent: 1`, UTF-8; default font, no width limit (remove the `max-width` from the signature div).
- Replies: `From` = the address the mail arrived on (info@savannahexplorers.com or savannah.explorers@gmail.com); `To` = the sender; subject "Re: …"; `In-Reply-To` and `References` = the original Message-ID (from `mail_get` or the Gmail connector) so it threads. Mandatory: if the Message-ID really can't be found, say so in the report to Roberto. Subject with a single "Re:" (never "Re: Re:").
- Replies quote the original mail below the signature, as a normal reply does: a line "Il <gg/mm/aaaa hh:mm>, <mittente> ha scritto:" (English "On <date>, <sender> wrote:"), then the original body as received (`mail_get` `body`, with the earlier thread it contains), in a `<blockquote type="cite">` in the html part and with "> " lines in the text part; strip empty lines at the start (12 Oct 2026).
- New mails (no thread) and mails to staff: `From` Gmail (savannah.explorers@gmail.com).
- Signature at the bottom matching the address: `InfoSavannahExplorers.html` (info@) or `OfficeGmail.html` (Gmail), in `C:\Dropbox\001_Safari\99_office\`. The signature already holds "Rimango a disposizione…", "Buona giornata", "Roberto": don't repeat them in the body.
- English mails: in the draft, replace the signature's Italian closing block ("Rimango a disposizione per maggiori informazioni e domande." / "Buona giornata" / "Roberto") with "Please do not hesitate to contact me for any further information." / "Best regards" / "Roberto" (same tags, html and text parts); leave the rest of the signature as it is (19 Oct 2026).
- The signature images (Roberto's photo, Tripadvisor badge) are links to savannahexplorers.com: Thunderbird hides remote images when a draft is opened from file, recipients see them. Don't embed or change them.
- Take the signature's raw HTML from his PC (`device_stage_files` or the device shell) and paste it as is into the html part. Never rebuild it from the Dropbox connector `fetch` text: that is extracted plain text and loses the formatting (12 Oct 2026).
- Attachments only when Roberto asks — this includes price lists and the Word programme. If one seems useful, ask; don't attach it on your own (31 Oct 2026).
- Write the file to his PC (device shell or `device_commit_files`); if the PC is not reachable, stop and tell him.
- After writing, list `Exchange/Draft-mail` again and check the file is there before telling Roberto it is saved (drafts there have disappeared within a minute; 17 Oct 2026). If it is gone, say so and copy it back from the booking folder's `old/`.
- After sending: when Roberto okays sending ("ok, manda"), Claude sends the mail itself (Hub `mail_send`, or the Gmail connector for staff mails), checks it is in Sent, then deletes the draft from `Exchange/Draft-mail` straight away; the copy in the booking folder's `old/` stays. This way nobody sends it again from Thunderbird (3 Nov 2026: the booking and Iddi mails went out twice, once from the Hub and once from Thunderbird). When Roberto sends the draft from Thunderbird himself, Claude doesn't send it: it only checks Sent and deletes the draft.
- Booking confirmations to agencies: content and attachments as in `booking-confirmation` §7.

## 2b. Replies from the booking mailboxes (glady@, operations@)

When the thread is in glady@ or operations@ (a supplier, a lodge, a transfer company) and Roberto wants the answer to
come from that mailbox, the Hub can send it (13 Nov 2026):
- Find the mail with `staff_mail_search` (`mailbox=glady` or `operations`, supplier / ref in `q`), read it with
  `staff_mail_get` (`reason` = why, e.g. "Rossi: Ndutu dates for the lodge"). Keep `mailbox`, `folder`, `key`.
- Draft as usual (§2): `.eml` in Draft-mail with `From` = that mailbox, `To` = the sender, "Re: …", `In-Reply-To` /
  `References` from `staff_mail_get`. English, short, signed "Booking Team / Savannah Explorers" (not Roberto's signature).
- After his ok on THAT text: `staff_mail_reply` with the same `mailbox` / `folder` / `key`, `reason`, `body` (or
  `body_html` + `body`), `request_id` → dry run, check from / to / subject, then `"confirm": true`. To, subject and
  threading come from the original mail and can't be changed; extra people only in `cc`.
- Check: `staff_mail_search` on that mailbox with `message_id` = the original's Message-ID shows the reply in its Sent
  folder. Then delete the draft from Draft-mail (as §2).
- Only glady@ and operations@ can send. A mail in another staff mailbox (sellers, nuru@…) is answered from info@ or
  Gmail with `mail_send`. If the Hub answers "Not active yet" (BOOKING_MAIL_LIVE off) or a password error, stop and tell
  Roberto; leave the `.eml` for Thunderbird.

## 3. Subject

Every mail about a request/booking carries the Dropbox folder name: `Re: <original subject> - <folder name>` (new mails: `<subject> - <folder name>`), without the payment suffix (_DEPOSIT, _BALANCE, _BALANCE-CASH, _PAID, _CK).

## 4. Style

- Italian: informal "tu"; always opens "Buongiorno <Nome>," — "Ciao <Nome>," only when Roberto asks for it in that mail or his own last mail in the thread already uses "Ciao" (31 Oct 2026); closes with "Rimango a disposizione per maggiori informazioni e domande." on its own line, then "Buona giornata" and "Roberto". Never "Cordiali saluti".
- English / French / Spanish: same warm, professional trade tone and structure (greeting with the name, short closing, "Roberto"). English: "program" (`rules-client-documents` §4).
- Agencies: never the programme link (`rules-client-documents` §2); the Word attachment only when Roberto asks for it (§2 of this skill).
- Payment reminders / short follow-ups to agencies: speak for the company to the agency team ("ve lo confermiamo", "quando fate il bonifico"), keep "mandami"; say plainly what is missing ("non mi risulta ancora arrivato in contabilità e non abbiamo la contabile"); "per favore"; only the figures needed (invoice number and amount, no balance or due date unless asked); no lists for 1–2 items (26 Oct 2026).
- Official/legal letters: minimal, direct, formal.
- Complaints: diplomatic, non-confrontational — acknowledge the feedback without admitting fault, de-escalate, propose a next step.
- Short paragraphs; when there are several items, lists under short heading lines ("Programma:", "Prezzi:", "La quota include:", "Non include:").
- HTML body: default font, no forced font or size, no width limit. Little or no bold. Never emoji.

## 5. Standard content (when relevant)

- Payment terms: 30% deposit to confirm; balance 60 days before start (direct clients) or 45 days (agencies). Il Diamante: no deposit, balance 30 days before arrival.
- On confirmation Savannah needs: passport copies (for the park permits) and a copy of the client's travel insurance (for MEDIVAC: it must cover emergency evacuation; not for the parks). Never ask for the NIC: it is the client's business, bought closer to departure (valid 92 days) (9 Nov 2026, RemoLenci).
- Costs outside the quote (NIC, Zanzibar tax, Kenya health insurance, Mafia): `rules-client-documents` §5.
- Health declaration (Ebola): since 17 Oct 2026 every traveller entering mainland Tanzania fills in the Ministry of Health Traveller's Health Surveillance Form at afyamsafiri.moh.go.tz, one per person (children included), ideally within 24 hours before arrival (also possible on arrival); keep the QR code (screenshot) for the port health check, where temperature is also taken. Zanzibar has its own portal: healthtravelznz.mohz.go.tz. Mention it with the entry requirements (passport 6 months + 2 blank pages, eVisa USD 50 for Italians on eservices.immigration.go.tz, NIC insurance) (26 Oct 2026).
- International flights only if asked: "Possiamo prenotare anche i voli internazionali dall'Italia, spesso abbiamo buone tariffe Tour Operator. Per opzionare senza impegno i voli internazionali abbiamo bisogno di: cognomi / nomi esattamente come sono scritti sul passaporto e le date di nascita; aeroporto di partenza; date con eventuale flessibilità".
- A payment is "received" only when the money has arrived; a transfer announced is "in arrivo".
- Agency confirms but our bookings are not done yet: write "procediamo con le prenotazioni…", not "confermiamo"; summary of services and prices only — no deposit, balance, due dates or bank details. Payment terms (30% deposit, balance with due date) and SE bank details go only in our definitive confirmation to the agency, when all services are confirmed and we send the invoice and the final programme (`booking-confirmation` §7) (Roberto, 9 Nov 2026). Close with "appena abbiamo tutti i servizi confermati ti scriviamo per la conferma definitiva". Domestic flight details (airline, flight, times, baggage) only after the booking team has booked it (13 Oct 2026).
- Discount requests: no discounts (they become a habit). Decline kindly: costs have risen a lot (lodges, park fees, fuel, domestic flights) and the rates are already tight, there is really no margin (13 Oct 2026).
- Kenya trips: never mention the Kenya partner (`rules-client-documents` §3).
- Standalone excursions for clients who didn't book the package: politely declined (easy to arrange locally).
- Lodge-only (Orangi Collection) enquiries: no Savannah road transfers between Serengeti/Ngorongoro lodges; transfers by bush flight, arranged on their side.

## 6. Don't

- Offer to "verify availability and block everything" on a loose ok.
- Mention passing the Word programme onto the agency letterhead.
- Tell agencies about staff joining a fam trip for free.
- Proofread programmes agencies rewrite for their clients (answer only the specific points they ask).

## 7. Staff mails

English, short, factual: folder name in the subject, what to do, by when. Booking teams: rooms exactly as per the request (room type and pax). Incoming payment alerts: forward the agency's original payment mail with its attachment to Saruni and Doreen, folder name in the subject.
Sending staff mails after Roberto's ok: if the Hub `mail_send` with `from: gmail` answers that Gmail is not configured, send from savannah.explorers@gmail.com with the Gmail connector (`send_message`), same text and signature; the mail is then not logged in the Hub timeline (19 Oct 2026).
Staff mails with attachments: after Roberto's ok, send them with the Hub `mail_send` (`from: gmail`, `attachments[]` `{name, content_base64}`, `request_id` for the booking timeline): dry run first, check from / to / subject / attachments, then `confirm`. Never from the Gmail connector (no attachments). If the Hub refuses (Gmail not configured, file over 10 MB), leave the `.eml` draft (attachments included) in `Exchange/Draft-mail` and Roberto sends it from Thunderbird (3 Nov 2026; replaces the 22 Oct rule).

## 8. Report to Roberto (Italian)

Show from / to / cc, subject, full text, attachments, then: "Bozza salvata in Exchange/Draft-mail/<file>.eml (da <indirizzo>). Serve da te: ok sul testo e sui destinatari."

Never say a mail "has been sent" unless you checked it: Roberto said so in this session, or it is in Sent (Hub `mail_list` on the Sent folders / Gmail `in:sent`). info@ has two Sent folders: check both `INBOX.Sent` (Hub / webmail) and `INBOX.Sent Messages` (Thunderbird) (5 Nov 2026). Otherwise write "da inviare" (31 Oct 2026).

After sending, report: sent from <address>, found in Sent, draft deleted from Draft-mail (3 Nov 2026).
