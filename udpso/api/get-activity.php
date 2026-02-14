<?php
header('Content-Type: application/json');
require_once '../config.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'error' => 'Доступ запрещен']));
}

$userRole = $_SESSION['user_role'] ?? '';
if (!in_array($userRole, ['admin', 'moderator', 'organizer', 'school', 'school_coordinator'], true)) {
    die(json_encode(['success' => false, 'error' => 'Доступ запрещен']));
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;

try {
    $query = "
        SELECT 
            a.*,
            u.username as user_name,
            u.role as user_role
        FROM activities a
        LEFT JOIN users u ON a.user_id = u.id
    ";

    $conditions = [];
    if ($userRole === 'admin') {
        $conditions[] = "u.role IN ('admin', 'moderator')";
    } elseif ($userRole === 'moderator') {
        $conditions[] = "u.role = 'moderator'";
        $conditions[] = "a.user_id = :user_id";
    } elseif ($userRole === 'organizer') {
        $conditions[] = "u.role = 'organizer'";
        $conditions[] = "a.user_id = :user_id";
    } elseif (in_array($userRole, ['school', 'school_coordinator'], true)) {
        $conditions[] = "u.role IN ('school', 'school_coordinator')";
        $conditions[] = "a.user_id = :user_id";
    }

    if ($conditions) {
        $query .= " WHERE " . implode(' AND ', $conditions);
    }

    $query .= " ORDER BY a.created_at DESC LIMIT :limit";

    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    if (in_array($userRole, ['moderator', 'organizer', 'school', 'school_coordinator'], true)) {
        $stmt->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    }
    $stmt->execute();
    $activities = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'activities' => $activities
    ]);

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}
?>
