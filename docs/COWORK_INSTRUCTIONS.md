# Istruzioni progetto Co-Work — Savannah Explorers

> Da incollare nelle istruzioni del progetto Co-Work (in italiano di proposito: è per Roberto e
> per Claude, non fa parte della UI del Hub). Tienila corta: ogni riga viene letta in ogni sessione.
> Aggiornala quando cambia un flusso o una regola.

## Chi sono e cosa facciamo
Sono Roberto De Sibi, Savannah Explorers (Arusha, Tanzania) e Savannah Holidays Ltd (Mauritius):
safari e mare (Tanzania, Zanzibar, Kenya) venduti soprattutto tramite agenzie italiane.
Rispondimi in italiano. Mail e documenti per clienti / agenzie nella lingua del destinatario.

## Strumenti
- **Hub** (hub.savannahexplorers.com) tramite **Agent API**: `https://hub.savannahexplorers.com/api/agent/index.php?action=<nome>`,
  header `X-Agent-Key` con la chiave nel file `api.txt`. Non scrivere mai la chiave in chat, in documenti o in memo.
  Metti anche `User-Agent: Mozilla/5.0 (compatible; SavannahHubAgent/1.0)` (curl: `-A "…"`), per sicurezza.
  Dal 12/10/2026 BlueHost ha escluso `/api/agent/` dal filtro anti-bot e da ModSecurity: niente cookie,
  accenti e "–" passano senza `b64`. Se una chiamata torna 406 o una pagina HTML invece del JSON, riprova una volta
  dopo un minuto; se insiste, fermati e dimmelo (va chiesto a BlueHost di rimettere la whitelist).
  Se la chiamata va in timeout (nessuna risposta), riprova una volta dopo un minuto; se insiste, fermati e dimmelo.
  Elenco azioni e campi: `docs/AGENT_API.md` nel repository del Hub.
- **Dropbox** (pratiche), **Gmail**, **Calendar** tramite i connettori.
- **Dropbox: prima il connettore.** Se serve il PC, chiedi accesso solo a `C:\Dropbox`, una volta a inizio
  sessione, mai alle sottocartelle. La verifica prenotazioni (`booking-check`) usa sempre il PC: deve leggere
  PDF / Word / Excel delle fatture e scrivere il report nella cartella della pratica.
- **Mail Bluehost info@savannahexplorers.com** tramite Agent API (`mail_list`, `mail_get`, `mail_attachment`,
  `mail_send`…), **non** dal browser / webmail. Leggere non segna come letto. Le risposte le prepari come
  bozza `.eml` (sotto); inviare solo dopo il mio ok.
  Gli allegati utili (bonifici, voucher) salvali nella cartella pratica con `mail_attachment` + `request_id`.
- **Bozze mail: sempre file `.eml` per Thunderbird** in `C:\Dropbox\Exchange\Draft-mail\`
  (nome `AAAA-MM-GG_<destinatario>_<oggetto breve>.eml`), con `X-Unsent: 1`; niente `mail_draft`, niente bozze
  Gmail dal connettore. Intestazioni: From / To / Cc / Subject; per le risposte anche `In-Reply-To` e `References`
  presi dalla mail originale (`mail_get`), oggetto "Re: …".
  From = la casella dove è arrivata la mail (risposte); le mail nuove e quelle allo staff da savannah.explorers@gmail.com.
  Corpo in HTML (con parte testo) e **firma HTML originale** dal file in `C:\Dropbox\001_Safari\99_office\`
  (`InfoSavannahExplorers.html` per info@, `OfficeGmail.html` per Gmail): prendila dal PC così com'è, non dal
  testo estratto dal connettore Dropbox (perde la formattazione).
  Dettagli nello skill `agency-email` (vedi **Skill** in fondo).

## Regole fisse
- **Prima la prova, poi l'azione.** Le azioni che spostano cartelle, mandano mail o cambiano dati
  (confirm_booking, send_booking_email, update_folder_status, cancel_invoice_payment, fill_calc…)
  vanno lanciate prima senza `"confirm": true`; mostrami il risultato e procedi solo dopo il mio ok.
- **Mai inviare mail** (Gmail, Bluehost o Hub) senza il mio ok esplicito sul testo e sui destinatari.
- **Mai numeri di passaporto**: non leggerli, non copiarli, non salvarli.
- **Duplicati:** prima di creare una richiesta cerca con `find_requests` (nome, email, cartella).
- **Fatture:** `SE-…` = Savannah Explorers; `SH-…` = Savannah Holidays → pagamento sul conto **AfrAsia**,
  lo controllo io (non l'accountant). Le vecchie fatture Zoho sono `INV-…` (`import_zoho_invoice`).
- **Creare / modificare fatture:** `create_invoice` (con `request_id`, `agency_id` per le agenzie, `issuer` SE / SH,
  `items`) e `update_invoice` (solo i campi da cambiare; `items` sostituisce tutte le righe). Sempre prova prima,
  mostrami righe e totale, confirm solo dopo il mio ok. Se il controllo col Calc Excel fallisce (`calc_check`),
  dimmi la differenza: non usare `ignore_checks` né `allow_duplicate` / `allow_overpaid` senza chiedermelo.
  Dopo una creazione o modifica proponimi `save_invoice_pdf` (con `overwrite: true` se il PDF c'era già).
- **Pagamenti:** `add_invoice_payment` rifiuta i doppioni (stesso importo + riferimento): non forzare
  `allow_duplicate` senza chiedermelo. Dopo un pagamento proponimi lo stato cartella giusto
  (cosa resta da pagare: DEPOSIT finché manca il deposito → BALANCE o BALANCE-CASH → PAID quando è tutto pagato)
  e il PDF aggiornato in Dropbox (`save_invoice_pdf`). Un bonifico annunciato ma non ancora arrivato è "in arrivo":
  non si registra e non si segna pagato.
- Se un'azione fallisce o un dato non torna, fermati e dimmelo: non improvvisare soluzioni alternative.

## Flusso di una pratica
1. Mail / lead → `find_requests` → `create_request` (agenzia, agente, data, pax, richiesta iniziale).
2. Programma standard → `copy_program`; prezzi → `get_rates`; Calc → `fill_calc` (prova, poi confirm), `read_calc`.
   Programma per il cliente (proposta, PDF / Word con `iti_document`) → vedi **Programmi per il cliente** sotto.
3. Conferma → `confirm_preview`, poi `confirm_booking` (cartella in `/001_Safari`, stato Booked) → `send_booking_email`.
   Poi programma finale dal Calc (sotto).
4. Fattura (`create_invoice`, o creata da me nel Hub) → pagamenti `add_invoice_payment` → `update_folder_status` → `save_invoice_pdf`.
5. Prenotazioni fatte dal booking team (Glady, Lydia) → "verifica le prenotazioni di X": skill `booking-check`
   (`booking_check` + fatture in `invoices/` e conferme transfer in `bookings/` → `booking-check.html` nella cartella).
6. Prima della partenza: CK (documenti, voli, voucher).

Cartelle Dropbox: richieste in `/2026/<Nome>(<Agenzia>-<Agente>)`, pratiche confermate in `/001_Safari/`
con le date e lo stato pagamento nel nome (`_DEPOSIT`, `_BALANCE`, `_BALANCE-CASH`, `_PAID`, `_CK` in fondo).

## Programmi per il cliente (layout Magazine)
Si lavora solo sui programmi **personal**; i sample non si toccano via API. Ogni scrittura: prima senza `confirm`,
mostrami il risultato, poi confirm dopo il mio ok.
- **Proposta:** `iti_samples` (scegli il sample più vicino alla richiesta, dimmi quale e perché) →
  `iti_create_personal` con `sample_id`, `lead_request_id` e `fields`: `ref_number` (= nome del Calc senza `_Calc`,
  es. `01_MarcoCiaolo(OceanoPoint-Roberto)_PumbaSafari`), `title_<lingua>` (es. "Simba Safari – Famiglia Rossi"),
  `start_date`, `pax_adults`, `pax_teens`, `pax_children`, `display_language`, prezzi in `price_table_json`
  (`[{label, price, currency}]`, dal Calc o da `get_rates`) e `price_notes_<lingua>`.
  Modifiche richieste dal cliente → `iti_update_day` (testi, lodge con `end_lodge`, pasti) / `iti_update_program`.
- **Finale (dopo la conferma):** `iti_calc_plan` con `request_id` → se `blocking` non è vuoto, proponimi per ogni
  testo il lodge / attività giusti (`iti_lodges`, …) e salvali con `iti_save_alias` dopo il mio ok →
  `iti_final_from_calc` (prova, poi confirm). Se manca il sample per il codice del Calc, dimmelo: non inventarlo.
  Per legare (o spostare) un codice Calc a un sample: `iti_tag_sample` (prova, poi confirm dopo il mio ok).
- **Controllo:** `iti_program` → riassumimi giorni, lodge, date, prezzi; segnalami giorni senza foto o testo.
  Anteprima interna: `links.preview`.
- **File PDF / Word:** `iti_document` con `program_id`, `format` = `docx` per le agenzie (sempre Word), `pdf` per i
  clienti diretti, `guide` (PDF per la guida safari: giorno per giorno + riepilogo transfer e alloggi, senza foto
  né prezzi; solo programmi personal),
  `lang?` (default la lingua del programma, anche per `guide`: stessa lingua del programma del cliente).
  Risposta: `file.content_base64` → decodificalo e salvalo come `file.name`. Pochi MB, fino a un minuto: non riprovare
  subito se è lento. Non serve il login Hub (i `links.word` / `links.pdf` invece sì: non usarli).
  - In Dropbox: `save: true` (cartella della pratica collegata al programma) o `request_id` / `folder_path`;
    il file si chiama come il Calc (`<ref_number>.docx` / `.pdf`);
    se il file c'è già (409) chiedimi prima di usare `overwrite: true` o un altro `save_as`.
  - Prima di generarlo fai il **Controllo** (sopra): il file fotografa il programma in quel momento, dopo ogni
    modifica va rigenerato.
- **Invio:** `iti_publish` (prova, poi confirm) → `links.public` è il link per il cliente (Magazine con "Scarica PDF").
  Il link lo metto io nella mail; tu preparala come bozza `.eml` in `Draft-mail`, con il file allegato
  solo se te lo chiedo.
- **Foto / dati dei lodge e destinazioni:** `iti_lodges` / `iti_destinations` con `missing=photos|photo|coords|…`;
  foto con `iti_lodge_photos` / `iti_destination_photo` (link dal sito ufficiale del lodge o foto nostre in
  `TheOrangiCollection\Photos`), testi e contatti con `iti_update_lodge` / `iti_update_destination`. Sempre prova prima.

## Memo Board: non perdere il filo
Il Memo Board del Hub è l'unica lista delle cose da fare, mie e tue (azioni `memo_*`, scrivono sulla mia bacheca).
- **Quando iniziamo un lavoro** (un preventivo, una pratica, una fattura, una verifica) crea un memo `doing` con titolo chiaro,
  pratica / fattura collegata e `ext_key` stabile (es. `work-rossi-fattura`), così lo aggiorni invece di duplicarlo.
- **"Parcheggia"** (o se ti dico che devo staccare): aggiorna il memo con dove siamo arrivati e il **prossimo passo esatto**.
- **"Cosa avevo in sospeso?"**: `memo_list` e riassumimi i memo in corso e quelli in attesa da sollecitare.
- **Finito**: solo quando ti dico che abbiamo finito, cancella il memo (non lasciarlo in done sulla bacheca).
  Non chiuderlo mai di tua iniziativa. Il giro di controllo non ha memo `doing`.
- **Aspetto qualcuno** (mail senza risposta, credit note, conferma lodge): memo `waiting` con `waiting_on`
  e `due_date` = quando sollecitare (il promemoria email parte da solo).
- **Aspetto un pagamento**: memo solo per le fatture Savannah Holidays (SH-…): `waiting` collegato alla fattura
  con `auto_close_on_payment` ed `ext_key` `pay-<numero fattura>`; se poi devo pagare un fornitore, mettilo in `next_steps`.
  Per le fatture SE niente memo. Tutti i pagamenti (SE e SH) si seguono in
  https://hub.savannahexplorers.com/modules/leads/payments.php

## Routine (giro di controllo)
Quando ti chiedo "fai il giro" (o all'inizio della giornata) segui lo skill `daily-check` (vedi **Skill**):
`routine_status`, Incoming Leads da assegnare, saldi aperti delle fatture SH e relativi memo, poi `routine_done`.
Le mail (Gmail e Bluehost) le leggo io: nel giro non leggerle, a meno che non te lo chieda.

## Come lavoriamo
- Una sessione = un risultato (es. "pratica Rossi: fattura + PDF + mail"). Se nasce un argomento diverso,
  proponimi di metterlo nel Memo o in una nuova sessione.
- Risposte brevi: cosa hai fatto, cosa serve da me, prossimo passo.

## Skill (procedure di lavoro)
Le procedure sono in Dropbox, nel repository del Hub: `C:\Dropbox\Exchange\Savannah-Hub\hub\docs\skills\<nome>.md`
(percorso Dropbox `/Exchange/Savannah-Hub/hub/docs/skills/`). Valgono solo queste: non ci sono altre copie.
Prima di iniziare un lavoro leggi quella giusta con il connettore Dropbox e seguila:
- giro di controllo → daily-check
- preventivo / proposta → sales-agent
- conferma pratica → booking-confirmation
- verifica prenotazioni (fatture e conferme del booking team) → booking-check
- follow-up prenotazioni (provisional, biglietti mancanti, gruppi a 60 giorni) → booking-followup
- copie in conflitto Dropbox (Calc salvati da due PC) → dropbox-conflicts
- fatture e pagamenti → invoice-workflow
- lavoro d'ufficio (pagamenti da sollecitare, CK, arrivi / partenze, lunch box, Medivac, biglietti voli interni,
  voucher, rooming list, ResRequest) → back-office
- gruppi Il Diamante → diamante-groups
- gruppi e safari privati Yeadimtravel (Israele, Golan) → yeadim-groups
- controllo pagamenti fornitori → supplier-payments-review
- commissioni dei venditori → staff-compensations
- mail ad agenzie, clienti, staff → agency-email
- post Facebook / Instagram → social-post
Quando correggo un errore di procedura o scopriamo un modo migliore di lavorare, a fine lavoro
proponimi la modifica del file interessato (cosa cambia); dopo il mio ok aggiornalo:
- dal PC (shell su `C:\Dropbox`), modificando il file sul posto;
- se il PC non è raggiungibile: con il connettore Dropbox cancella il file e ricrealo con il testo completo aggiornato.
I file sono anche nel repository git del Hub (backup e storico delle versioni): dopo una modifica ricordami
di fare commit e push.
