<?php
/**
 * procedures_map.php — contextual "?" links to the office procedures (proc_help_link() in procedures_service.php).
 * key => file (exact name in Dropbox /Operations/OfficeProcedures), page (hint for the PDF viewer, #page=N),
 * title (tooltip). Change file names / pages here only: they drift when the documents are rewritten.
 */
return [
    'quotation'      => ['file' => 'PREPARE CUSTOMER QUOTATION.pdf',       'page' => 1, 'title' => 'Procedure: prepare a customer quotation'],
    'confirm_safari' => ['file' => 'DROPBOX-REQUESTS-CONFIRMED TRIPS.pdf', 'page' => 1, 'title' => 'Procedure: Confirm Safari and booking email (section 3)'],
    'new_request'    => ['file' => 'DROPBOX-REQUESTS-CONFIRMED TRIPS.pdf', 'page' => 1, 'title' => 'Procedure: new request and Dropbox folder (section 2)'],
    'payments'       => ['file' => 'DROPBOX-REQUESTS-CONFIRMED TRIPS.pdf', 'page' => 3, 'title' => 'Procedure: client payments (section 6)'],
    'folder_status'  => ['file' => 'DROPBOX-REQUESTS-CONFIRMED TRIPS.pdf', 'page' => 2, 'title' => 'Procedure: folder status up to CK (section 4)'],
    'ck_followup'    => ['file' => 'CK AND BOOKING FOLLOW-UP.pdf',          'page' => 1, 'title' => 'Procedure: CK, Booking follow-up and incoming payments'],
    'ck_payments'    => ['file' => 'CK AND BOOKING FOLLOW-UP.pdf',          'page' => 2, 'title' => 'Procedure: incoming client payments (section 6)'],
];
