<?php
header('Content-Type: application/json');
require_once '../config.php';

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die(json_encode(['success' => false, 'error' => 'Доступ запрещен']));
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
$status = isset($_GET['status']) ? $_GET['status'] : 'pending';

try {
    // Общее количество заявок "на рассмотрении"
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM organizer_registrations WHERE status = 'pending'");
    $stmt->execute();
    $total_pending = $stmt->fetchColumn();

    // Последние заявки
    $stmt = $pdo->prepare("
        SELECT id, full_name, status, created_at 
        FROM organizer_registrations 
        WHERE status = :status
        ORDER BY created_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $applications = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'applications' => $applications,
        'total_pending' => $total_pending
    ]);

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}