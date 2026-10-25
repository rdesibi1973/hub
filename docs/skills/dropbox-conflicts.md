---
name: dropbox-conflicts
description: Review the Dropbox conflicted copies (Calc open in Excel on two PCs) that the Hub's nightly run could not resolve by itself — find the right original, compare the two files, propose to Roberto what to keep, and apply it only after his ok.
---

# Dropbox conflicted copies

Use when Roberto asks "controlla i conflitti", "copie in conflitto", "conflicted copy", on Monday with the daily round,
or types /dropbox-conflicts. Reply in Italian, short.

Hub access and fixed rules: as in the project instructions (dry run first, `"confirm": true` only after his ok; never
passport numbers; stop and say so when something fails or doesn't add up). API reference: `docs/AGENT_API.md` →
"Dropbox conflicted copies". Hub page: Lead Tracker → 🔀 Conflicts.

## What the Hub already does (don't redo it)

Every night (01:00–07:00 EAT) the Hub compares each conflicted copy in `/001_Safari` (not `old/`, not the archives)
with its original and the Dropbox version history, cell by cell. It resolves by itself — copy to the Dropbox trash —
when the copy adds nothing, or when copy and original changed *different* cells (it writes the copy's cells into the
original, `.xlsx` only, ≤ 40 cells, copy ≤ 30 days old, emptying no cell). Everything else is "to fix": with
`DBX_CONFLICTS_LIVE` on, the booking's owner (the seller in the folder tag) gets a memo and an email.
**Your job is only the "to fix" ones**: the cases that need judgement.

## 1. Fresh data

1. `dropbox_conflicts` (GET) → `live`, `last_run.at`, `open[]`.
2. If `last_run.at` is older than 2 days: `dropbox_conflicts_run` (POST, **without** `confirm`: it only reads) with
   `{"budget": 240}`; again while `left` > 0. Then `dropbox_conflicts` again.
3. Keep the rows with `auto` false and a trip not ended more than 7 days ago (`trip_end` empty or ≥ today − 7).
   Rows with `auto` true are the Hub's ("will be resolved" / "next run"): just count them.

## 2. Look at each copy

Reading the Excel files needs the PC (ask access to `C:\Dropbox` once): the connector cannot read cells reliably.
Local path = `C:\Dropbox` + `path`. Read only; never open them in Excel (that locks them and can create a new copy).
`cells[]` gives, for up to 40 cells, `base` (the version before both edits, when known), `original`, `copy` and
`both` (changed in both files). Look at the row (column A label, the CHECKED / notes columns, dates) to understand it.

| outcome / reason | what to check | usual proposal |
|---|---|---|
| `conflict` · same cells | for each `both` cell: which value is newer / right (payment notes, CHECKED names, prices vs invoices in `invoices/`) | copy the right values into the original |
| `conflict` · copy empties cells | the copy was probably made from an older version: are the emptied cells (deposit / balance rows, notes) still valid in the original? | keep the original, copy only the other changed cells |
| `conflict` · copy > 30 days old | its changes are often already superseded: is any value in the copy still missing from the original? | usually keep the original |
| `conflict` · not .xlsx (.xls) / rewrite / sheets / too many | compare the two files sheet by sheet (rows inserted? sheet renamed?) | case by case |
| `no_original` | the original was renamed or moved: look in the same folder for the Calc with the same client (`_FIXED`, another number prefix, `old/`), compare with it | merge into that file, or rename the copy if it is the only good version |
| `no_history` | no earlier version: compare copy and original directly, use the saved dates (`saved_at` vs the original's modified date) | keep the newer one, bring over what only the other has |
| `not_excel` | Word / PDF / CSV: compare the text | case by case |
| `error` | read `error` | tell Roberto |

## 3. Proposal to Roberto

```
Copie in conflitto – <data> (modalità <live|solo analisi>, ultimo controllo <data>)
Risolte dal Hub: <N>   Da sistemare: <N>   (viaggi passati esclusi: <N>)

<cliente> – <file copia> (<owner>, salvata <data>)
  Differenze: <cella: originale → copia, …>
  Proposta: <tenere l'originale e archiviare la copia | riportare <celle> nell'originale | rinominare la copia …>
  Sicurezza: <alta | media | bassa> – <perché>
```
Low confidence → propose to ask the owner (draft per `agency-email`, staff mail in English, from Gmail).

## 4. Apply — only after Roberto's ok, file by file

- **Nobody may have the file open.** Ask Roberto to confirm (or do it outside office hours). Right before writing,
  check that the original's modified time / Dropbox `rev` is still the one you read; if it changed, stop and re-read.
- **Copy cells into the original**: with Excel on the PC through PowerShell COM (open the original, set the cells,
  save, close, quit Excel) — it keeps formulas, formats and macros. No openpyxl / pandas rewrite of a Calc (it drops
  formatting, validations and cached values). Excel not available → don't write: send the owner the exact cells.
- **The copy**: never delete it. Move it into the `old/` sub-folder of the same folder (create it if missing) with the
  Dropbox connector `move` — the nightly run ignores `old/` and the copy stays at hand.
- **Rename** (copy is the only good version): move the original to `old/` first, then rename the copy to the
  original name.
- Afterwards `dropbox_conflicts_run` (no confirm) → the copy must show as gone ("fixed by hand"). Say what you did,
  file by file, and what is left.
- `dropbox_conflicts_run` with `"confirm": true` resolves the Hub's safe cases now instead of at night: only if
  Roberto asks.
