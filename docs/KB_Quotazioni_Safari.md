# KB — Safari Quotations (`*_Calc.xlsx`)

> **Authoritative source = the Excel files `*_Calc.xlsx`.** This document is *procedural documentation*: it records the rules and the change history, not a live price list. Point figures (jeep diesel, park-fee coefficients, etc.) live in the Excel sheets — here they appear only as a dated changelog entry. If a number here disagrees with the current Excel file, the Excel file wins.
>
> **Repository location:** `docs/KB_Quotazioni_Safari.md` in `rdesibi1973/hub`. The copy kept in the Claude project is session context only, not the master.

---

## 1. File types

| Type | Markup | Notes |
|---|---|---|
| **STANDARD** | 25% | D2 = 0.18 |
| **SPECIAL AGENTS / STOSPEC** | 14% | D2 = 0.16 |
| **GRP (groups)** | — | D2 varies for groups; not documented here, confirm per case. PAX sheets (12–24) × 3 seasons (suffix **L** low, **M** mid, **H** high) + a **PRICES** sheet |
| **Beach / ZNZ** | as per base program | Zanzibar / coast extensions |

---

## 2. PAX sheet layout (column mapping)

| Column | Content | Formula form |
|---|---|---|
| `C` | **FLIGHTS** | `=210*$B$1` etc. — internal flights |
| `D` | JEEP | `=$D$5*$B$5` |
| `F` | **PARK FEES** | Park taxes (see §3) |
| `H` | **ACTIVITIES** | Activities (emergency, MEDIVAC, lunch…) — **never modify for pricing** |
| `J` | ACCOMMODATION | Hotel/lodge, sometimes internal flights |

> ⚠️ **Common mistake to avoid:** formulas of the form `number*$B$1` appear in several columns. Flights to update are **only in column C**. Similar formulas in column H are activities, in column J are accommodation/transfer — these must **not** be touched for flight increases.

---

## 3. Park fees (PARK FEES — column F)

Formulas take the form `=(ADULT_COEFF*$B$2)+(TEEN_COEFF*($B$3+$B$4))[+(295*$B$5)]` where:
- `$B$2` = adults, `$B$3` = teenagers, `$B$4` = children, `$B$5` = number of jeeps
- the term `+(295*$B$5)` is the crater vehicle cost (Ngorongoro)

Variants to be aware of:
- The variant `=(96*$B$2)+(20*($B$3+$B$4))` must be normalized to `=(83*$B$2)+(24*($B$3+$B$4))` (both adult 96→83 and teen 20→24 change).
- **FaruSafari**: variant `=(179*$B$2)+(24*…)+(295*$B$5)`.
- **LUX_PumbaFlyOutZNZ / PumbaFlyOutZNZ**: variant `=(169*$B$2)+(48*…)+(295*$B$5)`.
- **MbogoSafari** uses formulas without parentheses: `=84*$B$2+18*$B$3`, `=69*$B$2+18*$B$3`, `=83*$B$2+24*$B$3+295*$B$4`.

> 🔄 **Coefficients change 1–2 times per year.** Current values live in the Excel files. When a new park tariff arrives, apply the new values to the Excel files and record the change as a dated entry in §8 (Changelog).

---

## 4. Price calculation logic (two modes)

After any change to costs (jeep, park, flights) **always recalculate** the file before reading D9/D10:

```bash
python3 /mnt/skills/public/xlsx/scripts/recalc.py file.xlsx
```

> openpyxl writes the formulas but does not evaluate them; D9/D10 values must be read **after** `recalc.py`.

### Rounding rule
Round **up to the next multiple of 5** (ceiling):
- 2003 → 2005
- 2008 → 2010
- 2061.25 → 2065

### STANDARD mode (markup 25%, D2 = 0.18)
1. `D9` (Price per person) rounded → write to `F9` (Price to customer) **and** to `H9` (rack)
2. `D10` (Price pp TO) rounded → write to `H10` (sto)

### SPECIAL AGENTS / STOSPEC mode (markup 14%, D2 = 0.16)
1. `D10` (Price pp TO) rounded → write **only** to `F9` (Price to customer)
2. **Do not touch** rack (`H9`) or sto (`H10`)
3. On request: rack/sto and their labels (`I9`, `I10`) may be **deleted** to reduce confusion

---

## 5. Flights rule

Flight increases apply **only to column C** (e.g. `+25`). Never touch column H (activities) or column J (accommodation). See the warning in §2.

---

## 6. GRP files (groups)

Example: `01_7nigtsGRP_Diamante.xlsx` — PAX sheets (12–24) × 3 seasons (suffix **L** / **M** / **H**) + a **PRICES** sheet.

- **F9 formula**: `=base+ROUNDUP(H4/B2,0)` — update **only the base**; keep the `ROUNDUP` term intact.
- **Dates**: changing the year in `A17` cascades to `A18` and following rows.
- **PRICES sheet**: references the `F9` of each PAX sheet across seasonality years (+ note).
- D2 for groups varies — confirm per case, not documented here.

---

## 7. Operational checklist

1. ☐ Identify file type (STANDARD / STOSPEC / GRP / Beach).
2. ☐ Update jeep cost (column D / `$D$5`) if changed.
3. ☐ Update park-fee coefficients (column F) per current tariff.
4. ☐ Update flights (**column C only**, never H or J).
5. ☐ For GRP: dates (`A17`→new year), PRICES (seasonality years + note).
6. ☐ Run `recalc.py` and verify zero formula errors.
7. ☐ Read D9/D10, apply rounding, write per mode (§4).
8. ☐ Save keeping the **original filename** (do not add `_updated`).

---

## 8. Changelog (dated, append-only)

> Record here every change to live figures. Newest first.

### 2026 — Diesel/jeep + park-fee update
- **Jeep**: D5 cost `250 → 300`.
- **Park-fee adult coefficients**:
  - Tarangire / Manyara: `69 → 60`
  - Serengeti: `179 → 154`
  - Ngorongoro (day): `96 → 83`
  - Ngorongoro + crater (with `+295*$B$5`): `83 → 71`
- **Park-fee variant normalizations**:
  - `=(96*$B$2)+(20*…)` → `=(83*$B$2)+(24*…)`
  - FaruSafari: `179 → 154`
  - LUX_PumbaFlyOutZNZ / PumbaFlyOutZNZ: `169 → 154`
  - MbogoSafari: `=69*$B$2+18*$B$3` → `=60*$B$2+18*$B$3`; `=83*$B$2+24*$B$3+295*$B$4` → `=71*$B$2+24*$B$3+295*$B$4`; `=84*$B$2+18*$B$3` unchanged.
- **Flights**: column C `+25`.

---

## 9. Travel programmes (itineraries)

*(Section to be expanded)* — Programme structure, Dropbox folder naming conventions, day/park/lodge mapping. To be filled with the specifics of each itinerary (Simba, Duma, Pumba, Kiboko, Mbogo, Faru, Nyani, Tembo, Migrazione, GranSafari, Beach/ZNZ programmes, GRP).

For each programme, document: duration (nights), parks visited, typical day sequence, reference lodge per tier, included activities, and any seasonal variants.
