# Handoff — Booking follow-up: fewer false lines, right owner, auto-close

*For Claude Code. From a Cowork review of the Booking follow-up with Roberto, 11 Nov 2026. Code: `modules/operations/includes/followup_service.php`, `followup_classify.php`, SafariCheck → follow-up import.*

The page had 150 open lines on 57 bookings; after a line-by-line review 28 were closed as duplicates or false alarms
and only ~6 "Word ≠ Excel" lines were real. These rules stop the same noise from coming back. Layout of the page is NOT
part of this handoff (to be discussed with Roberto separately).

## 1. Close lines when the trip is over or the booking is gone
- When `end_date` < today: close **every** line of that booking, SafariCheck lines included (`close_reason` `auto_trip_ended`).
  Today SafariCheck lines stay open (example: Marco Zanini 31 Oct–4 Nov 2026, line 162, still "URGENT 18 d late" on 11 Nov).
- When the Hub request becomes `Cancelled` or `Lost`: close its lines (`auto_request_cancelled`) — but see §6 first:
  a cancelled request may still point at a live folder.

## 2. One line per transfer
SafariCheck (`sc_transfer`) and CK (`transfer_missing`) create two lines for the same transfer because the last part of
`service_key` differs (`R2316|transfer|2027-01-05|transferdarairportamanibeach` vs `…|transfer05jankaratumanyaraarushadar`).
Rule: for transfers, key = request + `transfer` + date; merge into one line with both `sources`.

## 3. "Word ≠ Excel" lines go to the seller, not the booking team
Issues `sc_pax`, `sc_dates`, `sc_basis_differs`, `sc_nights_differ`, `sc_checkin_differs`: `audience = sales`, owner =
the seller of the request (agent in the folder name). Sellers who left: Micky's English-speaking clients → Agustin,
Italian → Anderson; Sultan likewise; Sara → ask Roberto. Folders tagged `-Roberto` → Roberto De Sibi (id 14).

## 4. Don't raise these (false alarms found on 11 Nov)
- **Tour leaders:** Calc pax text like "15 ADULTS + 1 TOUR LEADER FREE", "10 PAX+2 TL free", or a Word with "+ 2 TL":
  compare guests + TL, not guests only (Golemac 15+1, Dameng 20+2, Sumner 10+2).
- **GRP groups:** never compare the RECAP sheet (room block) with the client Words (sold pax). Compare each client
  sheet ("2PAX Giulia Brando") with that client's Word in its sub-folder. Unsold rooms are already the group lines' job.
- **Groups not closed** (folder `_PROVISIONAL`, Il Diamante groups before closing): no pax / Word checks.
- **Meal basis:** Word "pensione completa / FB" on a day whose lunch is a lunch box, picnic, crater picnic or "hot lunch"
  in the Calc activities (that day) is correct — do not flag. Word showing *less* than booked (HB in Word, FB booked) — do not flag.
  Flag only when the Word promises more than the lodge booking and no lunch activity covers it.
- **Old Words:** use the Word whose prefix matches the Calc / ITI `ref_number` (e.g. `02_…` beats `01_…`); ignore files in `old/`.
  Superseded Words should be moved to `old/` by whoever replaces them.
- **Excel misreads:** DiCredico (Marera 02+03 Sep read as 1 night, end 03 vs 04 Sep) — check the night count logic on
  consecutive rows with the same hotel.

## 5. Transfers without the client's flight
A transfer can't be booked without the flight time. If the Calc has no arrival/departure flight for that day, the
transfer line is shown as waiting for the flight (not a booking-team delay) and linked to the CK
`client_flights_missing` line (owner: CK owner).

## 6. Duplicate request still linked to a live folder (GRP0101 case)
Giulia Brando was first opened as a second GRP for the same date by mistake, then moved into GRP0101. Result:
- request **2746** "Giulia Brando" = Booked, Deposit, folder `GiuliaBrando(GoWorld-Roberto)` in GRP0101 — the right one;
- request **1144** "Giulia" = **Cancelled** (the duplicate), but still linked to the **same** folder;
- the group lines of GRP0101 (115–118, 229, 230) were created on request 1144.

Fixes:
- **One-off:** unlink the folder from cancelled request 1144 (no Agent API action can do it: `update_request` does not
  touch folders) and move lines 115–118, 229, 230 to an active request of the group (2263 Federica Lanza or 2746).
- **Rule:** when picking the request for a folder / group line, prefer active requests (Booked) over Cancelled / Lost
  ones linked to the same folder; never close a line because of a cancelled request while an active request has the
  same folder. Show a warning in BackOffice when two requests point at the same folder.

## Implemented (Claude Code, 11 Nov 2026)
- Hub: `followup_service.php` (`followup_close_stale()`, `followup_rules_once()`, SafariCheck import, transfer keys,
  `followup_mark_waiting_flight()`), `ck_lib.php` (`ck_reqs_sorted()`: active request first), `backoffice.php` (warning
  "Two requests on the same folder"). The page layout is unchanged. Rules summarised in `docs/AGENT_API.md`
  (Booking follow-up — Rules of 11 Nov 2026).
- §4 is in the SafariCheck engine (`safariagent` repo): tour leaders (`tour_leaders_in()`), GRP pax per client sheet,
  meal basis (lunch activities, Word less than booked), Word version prefix (`folder_scan._pick_word()`),
  `'Marera 02+03 Sep'` read as two nights. Not-closed groups are filtered in the Hub import.
- §5 uses the open Claude-check line `client_flights_missing` as the signal (the Hub does not read the Calc flights here).

## Test
Re-run the scan after the change: lines 108, 111–112, 119, 123, 130–131, 143–144, 149, 162, 168, 178, 183, 193, 214,
219, 223, 226, 242, 253, 259, 260, 275, 103–104, 107, 150 (closed by hand on 11 Nov with notes) must not come back,
and the GRP0101 group lines must be on request 2263 or 2746, not 1144.
