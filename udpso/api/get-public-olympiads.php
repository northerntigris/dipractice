<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

$region = isset($_GET['region']) ? trim($_GET['region']) : 'all';
if ($region === '') {
    $region = 'all';
}

try {
    $params = [];
    $where = "WHERE o.status IN ('upcoming', 'ongoing')";

    if ($region !== 'all') {
        $where .= " AND s.region = :region";
        $params[':region'] = $region;
    }

    $sql = "
        SELECT
            o.id,
            o.subject,
            o.datetime,
            o.grades,
            o.status,
            s.region,
            s.short_name AS school_name
        FROM olympiads o
        JOIN approved_schools s ON s.id = o.school_id
        $where
        ORDER BY s.region ASC, o.datetime DESC
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
        'error' => 'Ошибка загрузки олимпиад'
    ], JSON_UNESCAPED_UNICODE);
}

?>
