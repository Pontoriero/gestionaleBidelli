<?php
require_once __DIR__ . '/../includes/auth.php';

avviaSessione();
effettuaLogout();

header('Location: login.php');
exit;
