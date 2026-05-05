<?php
require_once __DIR__ . '/includes/auth.php';
logout_user();
redirect(BASE_URL . '/login.php');
