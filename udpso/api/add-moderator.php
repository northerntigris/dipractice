<?php
header('Content-Type: application/json');
require_once '../config.php';

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die(json_encode(['success' => false, 'error' => 'Доступ запрещен']));
}

$input = json_decode(file_get_contents('php://input'), true);

$fio = trim($input['fio'] ?? '');
$email = trim($input['email'] ?? '');
$isAdmin = (bool)($input['is_admin'] ?? false);

// Валидация
if (empty($fio) || empty($email)) {
    die(json_encode(['success' => false, 'error' => 'Все поля обязательны для заполнения']));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die(json_encode(['success' => false, 'error' => 'Некорректный email']));
}

try {
    // Генерируем временный пароль
    $tempPassword = bin2hex(random_bytes(4));
    
    // Добавляем пользователя
    $stmt = $pdo->prepare("
        INSERT INTO users (username, password, role, full_name, email) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $role = $isAdmin ? 'admin' : 'moderator';
    $username = 'mod_' . time(); // Генерируем уникальное имя пользователя
    
    $stmt->execute([
        $username,
        password_hash($tempPassword, PASSWORD_DEFAULT),
        $role,
        $fio,
        $email
    ]);
    
    // Отправляем email с учетными данными (можно использовать PHPMailer как в других частях кода)
    
    echo json_encode([
        'success' => true,
        'username' => $username,
        'temp_password' => $tempPassword // В реальном проекте не отправляем пароль в ответе!
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных: ' . $e->getMessage()]);
}