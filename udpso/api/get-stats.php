<?php
header('Content-Type: application/json');
require_once '../config.php';

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die(json_encode(['success' => false, 'error' => 'Доступ запрещен']));
}

try {
    // Всего школ
    $stmt = $pdo->query("SELECT COUNT(*) FROM organizer_registrations WHERE status = 'approved'");
    $total_schools = $stmt->fetchColumn();
    
    // Заявок на рассмотрении
    $stmt = $pdo->query("SELECT COUNT(*) FROM organizer_registrations WHERE status = 'pending'");
    $pending_applications = $stmt->fetchColumn();
    
    // Всего пользователей
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $total_users = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'total_schools' => $total_schools,
        'pending_applications' => $pending_applications,
        'total_users' => $total_users
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}