<?php
require_once 'config.php';
logout_user();
redirect(BASE_URL . '/login.php');
