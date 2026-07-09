<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
sessionDestroy();
header('Location: ' . BASE_URL . '/auth/login.php');
exit;
