# Hub Agent API — INDICE RAPIDO

Leggi **questo** file, non `AGENT_API.md` intero (circa 250 KB, ~70k token).
Per i dettagli di un'azione leggi **solo la sua sezione**:

```bash
# sezione di un'azione (fino al titolo successivo)
api_doc(){ awk -v a="\`$1\`" '/^##/{ if(p) exit; if(index($0,a)) p=1 } p' AGENT_API.md; }
api_doc fill_calc
```

Il numero di riga `L…` è indicativo (versione del 22/11/2026): se il file cambia, cerca il titolo con `grep -n`.
Quando aggiungi o cambi un'azione nell'API, aggiorna anche questo indice.

## Regole comuni
- Endpoint `https://hub.savannahexplorers.com/api/agent/index.php?action=<nome>`; header `X-Agent-Key: <api.txt>`
  e sempre `-A "Mozilla/5.0 (compatible; SavannahHubAgent/1.0)"`. GET = parametri in query, POST = body JSON.
- Risposta `{"ok":true,…}` / `{"ok":false,"error":…}`. Codici: 400 input · 403 chiave/HTTP · 404 · 409 doppione/bloccato
  · 422 parziale · 429 (60 chiamate/min) · 503 database giù (aspetta e riprova, non cambiare la richiesta) · 406 = firewall (riprova una volta dopo 1 min).
- **D** = dry-run: non fa niente senza `"confirm": true` (prima la prova, poi confirm dopo l'ok di Roberto).
- `*` = obbligatorio. Ogni `request` restituito ha `dropbox_path` e `dropbox_id`.

## Richieste, agenzie, pipeline
| Azione | Metodo | Parametri principali | Note | Riga |
|---|---|---|---|---|
| find_requests | GET | `q`, `status` (lista), `agent` (14 / `rdesibi` = Roberto De Sibi), `channel`, `year`, `limit` | controllo doppioni prima di create_request | L54 |
| request_resume | GET | `request_id` o `q` | tutto per riprendere una pratica (request, timeline, summary, file) | L2665 |
| list_agencies | GET | `q` | id, short_name, `price_level` (rack/sto/stospec/custom), contacts[] | L73 |
| create_agency | POST D | `name*`, `short_name`, `type` (savannah/promoservice/lamprati), `email`, `address` | 409 se esiste | L78 |
| update_agency | POST D | `agency_id*` + campi, `price_level`, `custom_markup` (min 12%) | | L94 |
| save_agency_contact / delete_agency_contact | POST D | `agency_id*`, `contact_id`, `name`, `email`, `phone`, `primary` | | L112 |
| create_request | POST D | `customer_name*`, `initial_request*`, `channel`, `agent_id`, `agency_id`, `email`, `period`, `pax`, `date_received`, `notify_agent` | | L130 |
| update_request | POST D | `request_id`, campi | | L150 |
| set_request_status | POST D | `request_id`, `status` | stato Parked: L216 | L179 |
| parked_wakeups | GET | — | per il giro | L259 |
| reassign_request | POST D | `request_id`, nuovo agente | rinomina cartella | L273 |
| pipeline_list / pipeline_move | GET / POST D | `agent`… / `request_id`, colonna | | L313 / L336 |
| timeline_list / timeline_add / timeline_update | GET / POST | `request_id*`, `event_type*`, `title*`, `body`, `next_step` | timeline_add scrive subito (no confirm) | L2646 |
| summary_set | POST D | `request_id*`, `summary*` (≤1500), `next_step`, `waiting_on` | | L2661 |

## Programmi standard, tariffe, Calc
| Azione | Metodo | Parametri principali | Note | Riga |
|---|---|---|---|---|
| list_standard_programs | GET | — | codici programma + destinations di Confirm | L360 |
| copy_program | POST | `request_id`, `program` / `programs[]`, `prognum` | file `NN_<cliente>(<agenzia>-<agente>)_…` anche in cartella confermata; `2` → `02` | L365 |
| get_rates | GET | `program`, `q`, `route`, `activity`, `date` | prezzi dal template Calc | L372 |
| update_rate / add_flight_rates / replace_flight_rates | POST D | `type`, `id`, `cost`, `sale` / `routes[]` | | L394 |
| fill_calc | POST D | `request_id`, righe / pax / date (vedi sezione), `keep_extra_rows`, `notes[]` | `null` = cella vuota su ogni campo del giorno; righe del modello dopo l'ultimo giorno svuotate (`cleared_rows`); `price_components` accetta `"90/6"`; 409 se il Calc è cambiato | L408 |
| read_calc | GET | `request_id`, `file`, `sheet` | pax, righe, totali, `notes[]`, `calc_rev` | L446 |
| booking_check | GET | `request_id`, `file`, `sheet` | per lo skill booking-check | L471 |
| gate_status / gate_run | GET / POST D | `request_id` | booking gate AI prima di uscire da _PROGRESS | L495 |

## Conferma, cartelle, gruppi
| Azione | Metodo | Parametri principali | Note | Riga |
|---|---|---|---|---|
| confirm_preview | POST | `request_id`, `start`, `end`, `mid`, `dest`, `grp` (NONE/CREATE/ADD), `grp_code`, `grp_program` | Calc checks L560 | L566 |
| confirm_booking | POST D | come confirm_preview + `confirm` | | L598 |
| send_booking_email | POST | `request_id` (Booked) | | L620 |
| rollback_booking | POST | `request_id` | annulla una conferma | L637 |
| rename_folder | POST D | `request_id*`, `new_name*` | | L644 |
| reopen_cancelled | POST D | `request_id*` | annullata → nuovo preventivo | L734 |
| folder_resolve | GET | `request_id*` | cartella da Dropbox id | L788 |
| folder_ids_backfill / folders_orphans / archive_cancelled | GET prova / POST D | — / `request_id` | manutenzione cartelle | L793–L810 |
| grp_names_report | GET | — | nomi GRP non normalizzati | L831 |
| import_group_folder / move_group_folder | POST D | `folder*` / `source*` | gruppi Il Diamante (L839) | L849 / L878 |

## ITI (programmi per il cliente)
| Azione | Metodo | Parametri principali | Note | Riga |
|---|---|---|---|---|
| iti_programs | GET | `q`, `type` (sample/personal), `lead_request_id` | | L904 |
| iti_samples | GET | `q` | id, code Calc, titolo, giorni | L942 |
| iti_program | GET | `program_id`, `lang` | controllo giorni/lodge/prezzi, `links.preview` | L945 |
| iti_document | GET | `program_id`, `format` (docx/pdf/guide), `lang`, `save`, `request_id`/`folder_path`, `overwrite`, `save_as` | risposta in base64: decodificala in un file, **non stamparla** | L1002 |
| iti_create_personal | POST D | `sample_id`, `lead_request_id`, `fields` (ref_number, title_<lang>, start_date, pax_*, display_language, price_table_json…), `days[]` | senza sample: L1113 | L1034 |
| iti_update_program | POST D | `program_id`, `fields` | | L1062 |
| iti_update_day | POST D | `program_id`, `day`, `fields` (testi, end_lodge, pasti…) | voci del giorno: L1072 | L1067 |
| iti_set_days / iti_add_day / iti_delete_day | POST D | `program_id`, `days[]` / `after_day` / `day` | | L1117 |
| iti_texts / iti_save_texts | GET / POST D | `program_id`, `lang`, `texts` | traduzioni | L908 |
| iti_inclusions / iti_update_inclusions / iti_update_standard_inclusion | GET / POST D | `country`, `program_id` / `std_id*` | | L1125 |
| iti_flight_routes / iti_activities / iti_transfer_routes + iti_create_… | GET / POST D | `q`, `from`, `to` | | L1137 |
| iti_import_program | POST D | testo del programma di un partner | | L1178 |
| iti_supplier_blocklist | GET / POST D | — | nomi fornitori da non mostrare | L1218 |
| iti_save_as_sample | POST D | `program_id`, `title` | | L1223 |
| iti_publish | POST D | `program_id`, `publish` | `links.public` per il cliente | L1230 |
| iti_calc_plan / iti_final_from_calc | GET / POST D | `request_id`, `sample_id` | programma finale dal Calc; `blocking` | L1234 |
| iti_save_alias | POST D | `type` (lodge/activity/route), `text`, `lodge_id`/`activity_id`/… | | L1331 |
| iti_alias_proposals / iti_alias_decide / iti_aliases / iti_change_log | GET / POST D | `proposal_id`, `decision` | revisione mapping | L1343–L1363 |
| iti_tag_sample | POST D | `sample_id`, `code` | lega un codice Calc a un sample | L1370 |
| iti_lodges / iti_lodge | GET | `q`, `destination`, `missing` (photos/coords/…) / `lodge_id` | | L1388 |
| iti_destinations / iti_destination | GET | `q`, `missing` | | L1398 |
| iti_lodge_photos / iti_destination_photo | POST D | `lodge_id` + `photos`/`add` / `destination_id`, `photo` | | L1407 |
| iti_create_lodge / iti_update_lodge / iti_update_destination | POST D | `fields` (`name*`, `destination_id*`…) | | L1422 |
| iti_create_destination | POST D | `fields` (`name_en*`, `country*`) | | L1169 |
| iti_activities / iti_activity / iti_update_activity | GET / POST D | `q`, `missing` / `activity_id`, `fields` | | L1446 |

## Fatture e credit note
| Azione | Metodo | Parametri principali | Note | Riga |
|---|---|---|---|---|
| find_invoices | GET | `q`, `request_id`, `status`, `unpaid=1` | | L1481 |
| get_invoice | GET | `invoice_id` / `invoice_number` | items, payments, net_due | L1488 |
| create_invoice | POST D | `items[]*` {description, quantity, unit_price}, `issuer` (SE/SH), `request_id`, `agency_id`, `bill_to_name` | calc_check contro il Calc | L1664 |
| update_invoice | POST D | `invoice_id`/`invoice_number` + solo i campi da cambiare; `items` sostituisce tutto | | L1682 |
| add_invoice_payment | POST | `invoice_id`, `amount*`, `date`, `method`, `reference` | 409 doppione | L1497 |
| cancel_invoice_payment | POST D | `payment_id*`, `reason*` | | L1508 |
| cancel_invoice / restore_invoice | POST D | `invoice_id*`, `reason*` | | L1513 / L1537 |
| save_invoice_pdf | POST | `invoice_id`, `overwrite`, `folder_path` | | L1630 |
| send_invoice_email | POST D | `invoice_id*` | | L1638 |
| import_zoho_invoice | POST D | `invoice_number*`, `issue_date*`, `bill_to_name*`, items | vecchie INV-… | L1655 |
| update_folder_status | POST D | `request_id`, `status*` (PROGRESS/PROVISIONAL/DEPOSIT/BALANCE/BALANCE-CASH/PAID) | | L1622 |
| list_credit_notes / cn_migrate_legacy / create_credit_note / apply_credit_note / refund_credit_note / update_credit_note / save_credit_note_pdf | GET / POST D | `invoice_id`, `amount`, `reason`, `credit_note_id` | | L1543–L1601 |

## Memo e routine
| Azione | Metodo | Parametri principali | Note | Riga |
|---|---|---|---|---|
| memo_list | GET | `status` (default open,doing,waiting), `q`, `request_id`, `invoice_id`, `ext_key`, `follow_up_due=1` | | L1732 |
| memo_save | POST | `title*`, `ext_key`, `status`, `body`, `waiting_on`, `due_date`, `reminder_at`, `request_id`, `invoice_number`, `auto_close_on_payment` | crea o aggiorna (stesso ext_key) | L1738 |
| memo_set_status | POST | `id`/`ext_key`, `status`, `note` | | L1755 |
| memo_delete | POST D | `id`/`ids[]`/`ext_key` | | L1759 |
| routine_status / routine_done | GET / POST | `key` | giro di controllo | L1774 |

## Mail
| Azione | Metodo | Parametri principali | Note | Riga |
|---|---|---|---|---|
| mail_folders / mail_list | GET | `folder`, `unseen`, `from`, `subject`, `q`, `since`, `limit` | info@ (IMAP); intro L1796 | L1832 |
| mail_get | GET | `uid*`, `folder` | Message-ID / References per le risposte | L1840 |
| mail_attachment | GET | `uid*`, `part*`, `request_id` (salva nella pratica) | | L1849 |
| mail_flag / mail_move | POST / POST D | `uid`, `to_folder` | | L1854 |
| mail_send | POST D | `from` (info/gmail), `to`, `cc`, `subject`, `body_html`, `in_reply_to`, `references`, `attachments[]`, `request_id` | mail_draft: non usarlo (bozze .eml) | L1860 |
| staff_mail_mailboxes / staff_mail_search | GET | `mailbox`, `folder`, `q`, `since`, `reason` | sola lettura; intro L1892 | L1916 |
| staff_mail_get / staff_mail_attachment | GET | `mailbox*`, `folder*`, `key*`, `reason*`, `part*` | | L1933 |
| staff_mail_save_eml | POST D | `mailbox*`, `folder*`, `key*`, `reason*`, `request_id` | salva in `mails/` | L1943 |
| staff_mail_reply | POST D | `mailbox*` (glady/operations), `folder*`, `key*`, `reason*`, testo | | L1956 |
| drafts_cleanup | POST D | — | bozze da `mails/` a `old/` | L2547 |
| mail_security_status | GET | — | | L2401 |

## Back office, follow-up, altro
| Azione | Metodo | Parametri principali | Note | Riga |
|---|---|---|---|---|
| payments_due | GET | — | lista pagamenti | L1997 |
| ck_status | GET | filtri date / owner | tracker CK | L2014 |
| ck_set_group_closed / ck_set_owners / ck_request_check | POST D | `request_id` / `ck_folder_id` | | L2068 |
| ck_findings_upsert / ck_findings_drafts | POST D / GET | risultati booking-check | | L2080 / L2109 |
| ops_movements / ops_lunch_boxes / ops_medivac | GET | `from`, `to` | | L2121–L2141 |
| flight_tickets | GET | `from`, `to` | biglietti voli interni mancanti | L2151 |
| jeep_demand / jeep_demand_day / jeep_demand_scan | GET / GET / POST D | `from`, `to`, `group`, `include_provisional`, `compare` / `date` / `mode` | jeep necessarie al giorno (dai Calc) | L2225 |
| followup_list / followup_scan / followup_update / followup_upsert | GET / POST D | `type`, `audience`, `status`, `owner` / `id` + campi | intro L2176 | L2312–L2352 |
| sales_response_status / sales_response_dismiss | GET / POST D | `week` | | L2456 / L2476 |
| dropbox_conflicts / dropbox_conflicts_run | GET / POST D | `owner_user_id`, `days` | intro L2491 | L2521 / L2537 |
| predeparture_list / predeparture_email | GET / POST D | `request_id`, `lang`, `countries`, `to` | | L2584 / L2589 |
