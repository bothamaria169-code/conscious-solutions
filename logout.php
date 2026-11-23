<?php
require_once __DIR__ . '/config/app.php';

// Destroy session and redirect to login
session_unset();
session_destroy();
header('Location: ' . BASE_PATH . '/signin.php');
exit;
