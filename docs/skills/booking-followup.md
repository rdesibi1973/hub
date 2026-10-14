---
name: booking-followup
description: Weekly review of the Hub Booking follow-up (supplier bookings still provisional / on request, domestic tickets not saved, groups to finalize 60 days before arrival with ARK-ZNZ seats): what the booking team has not taken in charge, problems, expiries, and the chase mails as .eml drafts. Also runs the office-PC PDF scan.
---

# Booking follow-up review

Use when Roberto asks "controlla il follow-up", "provisional", "cosa manca da confermare", "biglietti mancanti", "gruppi da finalizzare", on Monday after the weekly scan, or types /booking-followup.
Reply in Italian, short. Glady and Lydia work from the Hub page (Operations → 📌 Booking follow-up); this skill is Roberto's overview and the chasing — it does not do their work in the page.

Hub access and fixed rules: as in the project instructions (dry run first, `"confirm": true` only after his ok; no mail sent, only drafts; never passport numbers; stop and say so when something fails or doesn't add up). API reference: `docs/AGENT_API.md` → "Booking follow-up".

## What the lines are

| type | issue | meaning |
|---|---|---|
| supplier | `provisional` / `provisional_expired` | a lodge / camp document in `<booking>/invoices/` still provisional (expiry printed, past = expired) |
| supplier | `on_request` | flight invoice with a flight still on request |
| supplier | `orangi_rooms_provisional` | Orangi voucher Confirmed but its notes say "x rooms still provisional" |
| supplier / ticket | `unreadable` | scanned PDF without text, or a Calc that cannot be read: a human looks at it |
| ticket | `ticket_missing` | domestic flight in the next 14 days, no ticket file in the booking folder |
| group | `group_to_finalize` | GRP / Il Diamante SIC / private `_PROVISIONAL` group arriving within 60 days: client folders, Excel pax & rooms, supplier blocks, names TBA — release unsold rooms, confirm, room list, flights |
| group | `group_znz_flight` | seats on the Zanzibar flight vs pax with ARK-ZNZ in the Calc ("N seat(s) to release" / "N pax without a seat"), expiry = ticketing time limit |

Priority (computed): `1-URGENT` service ≤ 30 days, or expiry ≤ 7 days, or expired with service ≤ 90 days (tickets: ≤ 7 days); `2-HIGH` ≤ 90 days; `3-NORMAL` later.
Lines close by themselves when the confirmed document / ticket is saved in the folder (`auto_confirmed`, `auto_ticket_saved`), when the trip ends, or when the file leaves `invoices/` (`auto_not_found` — check it was not moved to `old/` without a confirmed one). A staff "resolved" on a document still provisional is re-opened by the next scan (groups are not).

## 1. Fresh data

1. `followup_list` (GET) → `last_check_at`. If the supplier check is older than 7 days (or Roberto asks), the PDFs must be read again **on the office PC** (BlueHost cannot read PDFs):
   - from the PC shell: `C:\Dropbox\Exchange\Savannah-Hub\hub\tools\followup_local_scan.bat` (dry run) → show the counts and the new / closed lines → after Roberto's ok the same with `--confirm` (it also runs the group check). PC not reachable → ask Roberto to run it.
2. No cron for now: the checks are run by hand. Roberto (or the booking team) uses the page → "Run check now" → **All**
   (tickets in the next 14 days + groups arriving within 60 days); the same from here: `followup_scan` `{"type":"all"}`
   (dry run, then `"confirm": true` after his ok). The daily round reminds it every Monday (`daily-check` §3c).

## 2. Review

Calls: `followup_list` with `owner=none`, `priority=1`, `type=group`, `status=closed` as needed (closed: last 7 days by `closed_at`).

Report in this order (one line each: client · supplier/ref or flight · issue · service date (days) · expiry · owner · status/notes):
1. **Problem** lines (with the staff note).
2. **Not taken in charge** for more than 2 days (`owner_name` empty, `first_seen_at` older than 2 days).
3. **Expired provisional with service ≤ 90 days** and **expiring ≤ 7 days**.
4. **Tickets missing** with flight ≤ 7 days.
5. **Groups** within 60 days: per group what was found (`doc_status_text` + `details`: sold vs block, provisional docs, names TBA) and the ZNZ seats verdict; say clearly "da rilasciare" / "pax senza posto".
6. **Closed in the last 7 days** with `auto_not_found` (a provisional file disappeared without a confirmed one) — ask the booking team where the confirmation is.
7. `unreadable` lines: who should open the PDF / Calc.

```
Follow-up prenotazioni – <data> (ultimo controllo fornitori <data>, biglietti <data>)
Problemi: <N>   Non presi in carico: <N>   Scaduti/in scadenza: <N>   Biglietti: <N>   Gruppi: <N>

Problemi:
- <cliente> – <fornitore> <ref> – <stato documento> – servizio <gg/mm> (<n> gg) – <owner>: <nota>
...
Proposta: <a chi scrivere / cosa sollecitare>
```

## 3. Chase

- Internal (Glady + Lydia): one mail to operations@savannahexplorers.com (never Glady only), from Gmail, English, as an
  `.eml` draft per `agency-email` (`C:\Dropbox\Exchange\Draft-mail`): this week's urgencies first (tickets, provisional
  expiring, groups), then the expired ones by who booked them. Use the folder names as on disk (the Hub customer name can differ).
  Leave out what Roberto already knows is fine (e.g. FamTrip Promoservice: the 2 ZNZ pax have their tickets).
- Supplier (provisional expired close to the service, on-request flights, rooms to release for a group): only if Roberto asks — one draft per supplier with the references (WB…, booking ID) and the dates; the booking team normally does it.
- Never change staff fields for them. If Roberto asks to reassign, mark not relevant or add a note: `followup_update` (dry run → his ok → `"confirm": true`).

## 4. Memo

If something stays open after the review (e.g. a supplier to call), one memo in Roberto's board with `memo_save` (`ext_key` `followup-<id>`), linked to the request — only after his ok.
