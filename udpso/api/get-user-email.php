<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("\n    SELECT COALESCE(NULLIF(u.email, ''), ao.contact_email, '') AS email\n    FROM users u\n    LEFT JOIN approved_organizations ao ON ao.id = u.organization_id\n    WHERE u.id = ?\n    LIMIT 1\n");
$stmt->execute([$userId]);
$email = $stmt->fetchColumn();

echo json_encode([
    'success' => true,
    'email' => $email ?: ''
], JSON_UNESCAPED_UNICODE);
