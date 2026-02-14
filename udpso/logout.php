<?php
header('Content-Type: application/json');
require_once 'config.php';

session_start();
session_unset();
session_destroy();

// Очищаем localStorage на клиенте
echo json_encode(['success' => true, 'clearLocalStorage' => true]);
exit;
?>