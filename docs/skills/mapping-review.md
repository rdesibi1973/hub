---
name: mapping-review
description: Review the Hub's Calc mappings (aliases: Calc text → lodge, activity, route) for wrong or suspicious ones, propose the corrections to Roberto and apply them only after his ok.
---

# Mapping review (ITI aliases)

Use when Roberto asks "controlla i mapping", "rivedi gli alias", "revisione mapping", or types /mapping-review. Reply in Italian, short.

Hub access and fixed rules: as in the project instructions (dry run first, `"confirm": true` only after his ok; stop and say so when something fails or doesn't add up). API reference: `docs/AGENT_API.md` → `iti_aliases`, `iti_save_alias`. Hub page: ITI → Aliases.

Why it matters: an alias is used for every future program with that Calc text, and the night-by-night table shows it as "mapped", so a wrong one repeats without anyone noticing. Only report what you can back up with a reason: a list full of doubts gets ignored.

## 1. Fresh data

1. `memo_list` with `ext_key=mapping-review-last` and `status=open,doing,waiting,done`: its body holds the date of the last review and the aliases Roberto already accepted. No memo = first review.
2. `iti_aliases`: all of them on the first review or when Roberto asks; otherwise with `since` = the date of the last review. Note `counts`.
3. To judge a target, read the master data: `iti_lodges` (with `active=all`), `iti_activities`, `iti_transfer_routes`, `iti_flight_routes`.
4. Leave out the aliases already accepted, unless `changed_at` is later than the review that accepted them.

## 2. What is suspicious

| Check | Example | Likely |
|---|---|---|
| The text and the target share no word, and it isn't a known translation or abbreviation | "Marera" → Kifaru Tented Lodge | wrong |
| The text names a place, the target is elsewhere | "Kifaru Tarangire" → a lodge in the Serengeti | wrong, or a typo in the Calc: ask |
| The text fits two lodges and the target is only one of them | "Marera" → Marera Valley Lodge, when Ngorongoro Marera Mountain View Lodge also exists | check |
| Near-identical texts with different targets | "marera lodge" and "marera mountain" | one is wrong |
| Text says a meal plan or a room the target can't have | "beach villa HB" → a safari camp | wrong |
| Route: from / to reversed, or road transfer for a text saying flight ("volo") | "Arusha - Seronera volo" → road transfer only | wrong |
| Target inactive in the master data | lodge `active` 0 | ask: replace it? |
| `maps_to_nothing` on a text that looks like a real lodge, activity or transfer | lodge alias "Gran Melia" with no target and no "own arrangement" | check |

**Not suspicious, don't report:**
- `maps_to_nothing` on what is not meant to print: Medivac, Amref, emergency, insurance, internal notes, a place name alone on a route ("Arusha" = no transfer), a hotel marked "own arrangement".
- A migration camp mapped to its north or south twin: the Hub picks the right one by season.
- Italian and English names of the same activity ("mongolfiera" → Balloon Safari).

Look first at the aliases saved by staff during the AI trial and the most recent ones (`changed_by`, `changed_at`). You see only names: you don't know which hotel was booked. On a real doubt about one booking, compare with the confirmations and invoices of that folder; otherwise ask Roberto.

## 3. Report to Roberto

```
Revisione mapping – <data> (<N> controllati su <totale>, dal <data ultima revisione | tutti>)
Da correggere: <N>   Da verificare: <N>

<tipo> "<testo>" → <destinazione attuale | nessuna> (<changed_by>, <data>)
  Perché: <motivo in una riga>
  Proposta: <lodge / attività / tratta giusta, con id | nessuna destinazione | chiedere a <chi>>
  Sicurezza: <alta | media | bassa>
```
Nothing suspicious → say so in one line, with how many you checked.

## 4. Apply — only after Roberto's ok, alias by alias

- Correct: `iti_save_alias` with `type`, `text` = the alias as listed, and the right `lodge_id` (+ `meal_basis` if it had one) | `activity_id` | `transfer_route_id` / `flight_route_id`. Dry run, then `"confirm": true`.
- Remove: no API action. Roberto or the staff delete it in ITI → Aliases; the text is then asked again at the next Calc.
- `iti_aliases` with `q` afterwards: check the new target, and tell Roberto alias by alias what changed.

**Programs already generated are not repaired by correcting an alias.** For each alias confirmed wrong, check the programs: `iti_programs` with `type=personal` (latest 100), then `iti_program` on the ones updated after the alias's `changed_at`, starting from the most recent, at most 20; look at the day's `lodge` / `activities` / `transfers`. List those that carry the error and say that any older ones were not checked. To fix them, regenerate from the Calc only after his ok on each: `booking-confirmation` §5 or `sales-agent` §3.

## 5. Close the review

Save the state with `memo_save`, `ext_key` `mapping-review-last`, title `Revisione mapping: ultima il <data>`, body: the date, then the aliases Roberto judged fine (`tipo: testo`, keep those from earlier reviews), one per line. Then `memo_set_status` with `ext_key` and `done`, so it stays off his board.
