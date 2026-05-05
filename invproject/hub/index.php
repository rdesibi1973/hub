<?php
require_once __DIR__ . '/includes/auth.php';
start_session();

if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/hub.php');
} else {
    header('Location: ' . BASE_URL . '/login.php');
}
exit;
