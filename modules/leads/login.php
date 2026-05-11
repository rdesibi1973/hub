<?php
/* This login form is no longer used.
   All authentication goes through the hub's central login. */
require_once 'config.php';
$next = $_GET['next'] ?? $_SERVER['QUERY_STRING'] ?? '';
$qs   = ($next && str_starts_with($next, '/')) ? '?next=' . urlencode($next) : '';
redirect(BASE_URL . '/login.php' . $qs);
