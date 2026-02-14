<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = (int)$_SESSION['user_id'];

    $stmt = $pdo->prepare("
        SELECT
            o.id,
            o.subject,
            o.datetime,
            o.grades,
            o.status,
            o.school_id,
            oj.jury_role,
            s.short_name AS school_name,
            org.full_name AS organization_short_name,
            s.region
        FROM olympiad_jury oj
        JOIN jury_members jm ON oj.jury_member_id = jm.id
        JOIN olympiads o ON oj.olympiad_id = o.id
        LEFT JOIN approved_organizations org ON o.organization_id = org.id
        LEFT JOIN approved_schools s ON o.school_id = s.id
        WHERE jm.user_id = ?
        ORDER BY o.datetime DESC
    ");
    $stmt->execute([$userId]);
    $olympiads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'olympiads' => $olympiads], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка загрузки олимпиад жюри'], JSON_UNESCAPED_UNICODE);
}
?>
