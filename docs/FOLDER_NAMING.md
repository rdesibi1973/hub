# Dropbox Folder Naming Conventions

> **Authoritative source = the code in `modules/leads/includes/folder_parser.php`.** This document is *descriptive*: it explains the naming patterns and how the parser interprets them. It does **not** define behaviour. If you need to change how folders are parsed, edit the PHP — editing this file changes nothing. If this document and the code disagree, the code wins.
>
> ⚠️ A second, **older** copy exists at `includes/folder_parser.php` (root, 42 lines) containing only an early `parse_practice_dates()`. The live, complete logic is the `modules/leads/includes/` copy (functions `parse_folder_dates`, `get_date_folder`, `folder_agency`, `folder_payment_status`). Prefer the leads copy; consider consolidating the root copy in a future cleanup.

---

## 1. Folder name shape

Two broad formats are recognized.

**Normal booking** (date tags live in `practice_code`):
```
04_12APR_CustomerName(Agency-Agent)_START12APR_END17APR2026_CK
```

**GRP booking** (date/agency tags live in `group_folder`, parent folder under `/001_Safari/`):
```
GRP0206_CustomerName(Agency-Agent)
```

General agency/agent tag, inside the first parentheses:
- `CustomerName(AgencyShort-AgentName)`
- `CustomerName(AgentName-Drct/SB/LAM/PS)` — where the last token is a channel suffix

Channel suffixes: `Drct` (direct), `SB`, `LAM`, `PS`. When the last dash-separated token inside the parentheses is one of these, the agent is the **second-to-last** token; otherwise the agent is the **last** token. (This selection rule is applied by the consumer of `folder_agency()`, not inside the parser itself.)

Structural conventions:
- GRP folders always live under `/001_Safari/`.
- Unconfirmed bookings live under `/2026/`.

---

## 2. Date parsing (`parse_folder_dates`)

- The **`_END{DD}{MMM}{YYYY}`** tag is mandatory — it carries the year. No END tag → no dates returned.
- The **`_START{DD}{MMM}`** tag has no year; the year is inferred from END.
- **Year-boundary rule:** if the start month is more than one month after the end month (`start_mon > end_mon + 1`), the trip is treated as spanning a year boundary and the start year is set to `end_year − 1`.
- Month codes are the 3-letter uppercase English abbreviations (`JAN`…`DEC`).

`get_date_folder()` picks the right source string per booking type:
- If `group_folder` already contains `_START`, use it directly.
- For GRP bookings without START/END in `group_folder` (older records), extract the parent segment after `/001_Safari/` from `dropbox_url`.
- For normal bookings, use `practice_code` (which carries the START/END tags).

---

## 3. Agency/agent extraction (`folder_agency`)

Returns the content inside the **first** set of parentheses (e.g. `06_LauraBellocchi(Roberto-Drct)_START...` → `Roberto-Drct`). For GRP bookings the parentheses live in `group_folder`; for normal bookings, in `practice_code`. Returns empty string if no parentheses are present.

---

## 4. Payment status tags (`folder_payment_status`)

Used as a fallback when the `payment_status` column is null/empty. The folder suffix is matched against this map (**longest tags first**, so `_BALANCE` does not match inside `_BALANCE-CASH`):

| Suffix tag | Derived status |
|---|---|
| `_BALANCE-CASH` / `_BALANCE_CASH` | Balance-Cash |
| `_BALANCE` | Balance |
| `_DEPOSIT` | Deposit |
| `_PAID` | Paid |
| `_PROGRESS` | Progress |
| `_PROVISIONAL` | Provisional |
| `_CANCELLED` | Cancelled |

> **`_CK` is a document marker, not a payment status** — it is intentionally absent from this map.
