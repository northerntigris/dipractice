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

    $pdo->exec("CREATE TABLE IF NOT EXISTS appeals (
        id SERIAL PRIMARY KEY,
        olympiad_id INTEGER NOT NULL REFERENCES olympiads(id) ON DELETE CASCADE,
        student_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        description TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        response_comment TEXT,
        response_score numeric,
        responder_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        response_created_at TIMESTAMPTZ,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");

    $userId = (int)$_SESSION['user_id'];

    $stmt = $pdo->prepare("WITH jury_olympiads AS (
        SELECT DISTINCT o.id
        FROM olympiad_jury oj
        JOIN jury_members jm ON jm.id = oj.jury_member_id
        JOIN olympiads o ON o.id = oj.olympiad_id
        WHERE jm.user_id = :user_id

        UNION

        SELECT DISTINCT o2.id
        FROM olympiad_jury oj
        JOIN jury_members jm ON jm.id = oj.jury_member_id
        JOIN olympiads o ON o.id = oj.olympiad_id
        JOIN olympiads o2 ON o2.template_id = o.id
        WHERE jm.user_id = :user_id
    )
    SELECT
        a.id,
        a.olympiad_id,
        o.subject,
        a.student_id,
        u.full_name AS student_name,
        a.description,
        a.status,
        a.created_at
    FROM appeals a
    JOIN jury_olympiads jo ON jo.id = a.olympiad_id
    JOIN olympiads o ON o.id = a.olympiad_id
    JOIN users u ON u.id = a.student_id
    WHERE a.status = 'pending'
    ORDER BY a.created_at DESC");
    $stmt->execute([':user_id' => $userId]);

    echo json_encode([
        'success' => true,
        'appeals' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка загрузки апелляций'], JSON_UNESCAPED_UNICODE);
}
