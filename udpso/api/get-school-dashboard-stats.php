<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $role = $_SESSION['user_role'] ?? '';
    if (!in_array($role, ['school', 'school_coordinator'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Недостаточно прав'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $schoolIdStmt = $pdo->prepare('SELECT school_id FROM users WHERE id = ? LIMIT 1');
    $schoolIdStmt->execute([(int)$_SESSION['user_id']]);
    $schoolId = (int)$schoolIdStmt->fetchColumn();

    if ($schoolId <= 0) {
        echo json_encode([
            'success' => true,
            'total_coordinators' => 0,
            'active_olympiads' => 0,
            'completed_olympiads' => 0
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $coordinatorsStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE school_id = :school_id AND role = 'school_coordinator'");
    $coordinatorsStmt->execute([':school_id' => $schoolId]);
    $totalCoordinators = (int)$coordinatorsStmt->fetchColumn();

    $olympiadsStmt = $pdo->prepare("
        WITH school_olympiads_union AS (
            SELECT o.id, COALESCE(so.status, o.status) AS effective_status
            FROM olympiads o
            LEFT JOIN school_olympiads so
              ON so.olympiad_id = o.id
             AND so.school_id = :school_id
            WHERE o.school_id = :school_id OR so.school_id = :school_id
        )
        SELECT
            COUNT(*) FILTER (WHERE effective_status IN ('upcoming', 'ongoing')) AS active_count,
            COUNT(*) FILTER (WHERE effective_status IN ('completed', 'archived')) AS completed_count
        FROM school_olympiads_union
    ");
    $olympiadsStmt->execute([':school_id' => $schoolId]);
    $counts = $olympiadsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'total_coordinators' => $totalCoordinators,
        'active_olympiads' => (int)($counts['active_count'] ?? 0),
        'completed_olympiads' => (int)($counts['completed_count'] ?? 0)
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка загрузки статистики'], JSON_UNESCAPED_UNICODE);
}
