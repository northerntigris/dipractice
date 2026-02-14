<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$region = isset($_GET['region']) ? trim($_GET['region']) : 'all';
if ($region === '') {
    $region = 'all';
}

try {
    $params = [];
    $regionClauseMain = '';
    $regionClauseSchool = '';

    if ($region !== 'all') {
        $regionClauseMain = " AND s.region = :region_main";
        $regionClauseSchool = " AND s.region = :region_school";
        $params[':region_main'] = $region;
        $params[':region_school'] = $region;
    }

    $sql = "
        SELECT *
        FROM (
            SELECT
                o.id,
                o.subject,
                o.datetime,
                o.grades,
                'archived' AS status,
                s.region,
                s.short_name AS school_name,
                COALESCE(s.id, o.school_id) AS school_id
            FROM olympiads o
            LEFT JOIN approved_schools s
              ON s.id = o.school_id
            WHERE o.status = 'archived'
            {$regionClauseMain}

            UNION ALL

            SELECT
                o.id,
                o.subject,
                so.scheduled_at AS datetime,
                so.grades,
                'archived' AS status,
                s.region,
                s.short_name AS school_name,
                so.school_id
            FROM school_olympiads so
            JOIN olympiads o ON o.id = so.olympiad_id
            LEFT JOIN approved_schools s
              ON s.id = so.school_id
            WHERE so.status = 'archived'
            {$regionClauseSchool}
        ) archived
        ORDER BY region ASC, datetime DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $olympiads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'olympiads' => $olympiads
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка загрузки архива'
    ], JSON_UNESCAPED_UNICODE);
}
