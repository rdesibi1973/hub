<?php
/**
 * client_docs.php — documents for clients, linked from Itineraries (programs.php): public PDFs on the website
 * https://www.savannahexplorers.com/pdf/ (public_html/pdf), so a seller can paste the link in a client email.
 * Source: Dropbox "SALES TEAM - info for customers/Jambo PDF - all languages" (same file names). When a PDF changes
 * there, copy it to public_html/pdf too (Roberto, 18 Oct 2026). group => [language label => file name].
 */
const ITI_CLIENT_DOCS_BASE = 'https://www.savannahexplorers.com/pdf/';

return [
    'Travel info Tanzania (Jambo)' => [
        'EN' => 'Jambo from Savannah Explorers.pdf',
        'IT' => 'Jambo da Savannah Explorers IT.pdf',
        'FR' => 'Jambo da Savannah Explorers FR.pdf',
        'ES' => 'Jambo da Savannah Explorers SPA.pdf',
    ],
    'Travel info Kenya (Jambo)' => [
        'EN' => 'Jambo from Savannah Explorers KENYA.pdf',
        'IT' => 'Jambo da Savannah Explorers KENYA IT.pdf',
    ],
    'Insurance Tanzania & Zanzibar (NIC / ZIC)' => [
        'EN' => 'Tanzania and Zanzibar - insurance ENGLISH.pdf',
        'IT' => 'Tanzania e Zanzibar - assicurazione.pdf',
        'FR' => 'Tanzanie et Zanzibar - assurance FRENCH.pdf',
        'ES' => 'Tanzania y Zanzibar - seguro ESPAÑOL.pdf',
    ],
];
