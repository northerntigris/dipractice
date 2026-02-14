<?php
header('Content-Type: application/json');
require_once '../config.php';

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die(json_encode(['success' => false, 'error' => 'Доступ запрещен']));
}

$id = $_GET['id'] ?? 0;

try {
    $stmt = $pdo->prepare("
        SELECT * FROM organizer_registrations 
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    $application = $stmt->fetch();

    if ($application) {
        echo json_encode([
            'success' => true,
            'application' => $application
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Заявка не найдена'
        ]);
    }
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}
