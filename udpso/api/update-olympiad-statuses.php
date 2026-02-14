<?php
// update-olympiad-statuses.php
// Этот скрипт обновляет статус олимпиад: ожидаемые -> в процессе, в процессе -> завершена

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

try {

    // 1) upcoming -> ongoing
    $stmt1 = $pdo->prepare("
        UPDATE olympiads
        SET status = 'ongoing'
        WHERE status = 'upcoming'
          AND datetime <= NOW()
    ");
    $stmt1->execute();
    $updatedToOngoing = $stmt1->rowCount();

    // 2) ongoing -> completed (через 3 часа после старта)
    $stmt2 = $pdo->prepare("
        UPDATE olympiads
        SET status = 'completed'
        WHERE status = 'ongoing'
          AND datetime <= (NOW() - INTERVAL '3 hours')
    ");
    $stmt2->execute();
    $updatedToCompleted = $stmt2->rowCount();

    echo json_encode([
        'success' => true,
        'updated_to_ongoing'   => $updatedToOngoing,
        'updated_to_completed' => $updatedToCompleted
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Ошибка обновления статусов олимпиад',
    ], JSON_UNESCAPED_UNICODE);
}

?>
