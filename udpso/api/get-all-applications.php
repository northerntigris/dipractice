<?php
header('Content-Type: application/json');
require_once '../config.php';

session_start();

// Проверка авторизации и прав администратора
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Доступ запрещен']));
}

// Параметры пагинации
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? max(1, (int)$_GET['per_page']) : 25;
$status = isset($_GET['status']) && in_array($_GET['status'], ['pending', 'approved', 'rejected']) 
    ? $_GET['status'] 
    : null;

try {
    // Базовый запрос с подсчетом общего количества
    $baseQuery = "FROM organizer_registrations sr";
    $whereClause = $status ? " WHERE sr.status = :status" : "";
    
    // Получаем общее количество записей
    $countStmt = $pdo->prepare("SELECT COUNT(*) " . $baseQuery . $whereClause);
    if ($status) {
        $countStmt->bindParam(':status', $status);
    }
    $countStmt->execute();
    $total = $countStmt->fetchColumn();
    
    // Получаем данные для текущей страницы
    $query = "SELECT sr.* " . $baseQuery . $whereClause . 
             " ORDER BY sr.created_at DESC LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    if ($status) {
        $stmt->bindParam(':status', $status);
    }
    $stmt->execute();
    
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Формируем успешный ответ
    echo json_encode([
        'success' => true,
        'applications' => $applications,
        'total' => $total,
        'current_page' => $page,
        'per_page' => $perPage,
        'last_page' => ceil($total / $perPage)
    ]);

} catch(PDOException $e) {
    error_log("Database error in get-all-applications: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Ошибка при загрузке заявок',
        'debug' => 'Development mode only: ' . $e->getMessage()
    ]);
}