<?php
header('Content-Type: application/json');
require_once 'config.php';

session_start();

if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    echo json_encode([
        'success' => true,
        'user_role' => $_SESSION['user_role']
    ]);
} else {
    echo json_encode([
        'success' => false
    ]);
}
?>