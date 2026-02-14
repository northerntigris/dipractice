<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
session_start();

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $chatId = isset($input['chat_id']) ? (int)$input['chat_id'] : 0;
    $message = trim($input['message'] ?? '');

    if ($chatId <= 0 || $message === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Введите сообщение'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $role = $_SESSION['user_role'] ?? 'guest';

    $pdo->exec("CREATE TABLE IF NOT EXISTS support_chats (
        id SERIAL PRIMARY KEY,
        user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
        guest_name TEXT,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        last_message_at TIMESTAMPTZ
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (
        id SERIAL PRIMARY KEY,
        chat_id INTEGER NOT NULL REFERENCES support_chats(id) ON DELETE CASCADE,
        sender_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
        sender_role TEXT NOT NULL,
        message TEXT NOT NULL,
        is_read BOOLEAN NOT NULL DEFAULT false,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");
    $pdo->exec("ALTER TABLE support_chats ADD COLUMN IF NOT EXISTS guest_name TEXT");
    $pdo->exec("ALTER TABLE support_messages ADD COLUMN IF NOT EXISTS is_read BOOLEAN NOT NULL DEFAULT false");

    $nullableStmt = $pdo->prepare("
        SELECT is_nullable
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'support_chats'
          AND column_name = 'user_id'
        LIMIT 1
    ");
    $nullableStmt->execute();
    if ($nullableStmt->fetchColumn() === 'NO') {
        $pdo->exec("ALTER TABLE support_chats ALTER COLUMN user_id DROP NOT NULL");
    }

    $nullableStmt = $pdo->prepare("
        SELECT is_nullable
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'support_messages'
          AND column_name = 'sender_id'
        LIMIT 1
    ");
    $nullableStmt->execute();
    if ($nullableStmt->fetchColumn() === 'NO') {
        $pdo->exec("ALTER TABLE support_messages ALTER COLUMN sender_id DROP NOT NULL");
    }

    $pdo->exec("UPDATE support_messages SET is_read = false WHERE is_read IS NULL");

    if (!in_array($role, ['admin', 'moderator'], true)) {
        if ($userId) {
            $check = $pdo->prepare("SELECT 1 FROM support_chats WHERE id = ? AND user_id = ? LIMIT 1");
            $check->execute([$chatId, $userId]);
        } else {
            $guestName = $_SESSION['support_guest_name'] ?? null;
            $guestChatId = $_SESSION['support_guest_chat_id'] ?? null;
            $check = $pdo->prepare("SELECT 1 FROM support_chats WHERE id = ? AND guest_name = ? LIMIT 1");
            $check->execute([(int)$guestChatId, $guestName]);
        }
        if (!$check->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $insert = $pdo->prepare("
        INSERT INTO support_messages (chat_id, sender_id, sender_role, message, is_read)
        VALUES (:chat_id, :sender_id, :sender_role, :message, :is_read)
    ");
    $isRead = in_array($role, ['admin', 'moderator'], true) ? 1 : 0;
    $insert->bindValue(':chat_id', $chatId, PDO::PARAM_INT);
    $insert->bindValue(':sender_id', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $insert->bindValue(':sender_role', $role, PDO::PARAM_STR);
    $insert->bindValue(':message', $message, PDO::PARAM_STR);
    $insert->bindValue(':is_read', $isRead, PDO::PARAM_BOOL);
    $insert->execute();

    $update = $pdo->prepare("UPDATE support_chats SET last_message_at = NOW() WHERE id = ?");
    $update->execute([$chatId]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка отправки сообщения'], JSON_UNESCAPED_UNICODE);
}
