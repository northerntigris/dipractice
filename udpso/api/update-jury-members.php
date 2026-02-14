<?php
require '../config.php';
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

try {
  $juryId = $_POST['jury_id'];
  $fullName = $_POST['full_name'];
  $organization = $_POST['organization'];
  $snils = $_POST['snils'];
  $passportSeries = $_POST['passport_series'];
  $passportNumber = $_POST['passport_number'];
  $passportIssuedBy = $_POST['passport_issued_by'];
  $passportIssuedDate = $_POST['passport_issued_date'];
  $birthdate = $_POST['birthdate'];
  $juryRole = $_POST['jury_role'];
  $olympiadId = $_POST['olympiad_id'];

  // Обновление users (ФИО и СНИЛС)
  $stmt = $pdo->prepare("
    UPDATE users
    SET full_name = ?, snils = ?
    WHERE id = (SELECT user_id FROM jury_members WHERE id = ?)
  ");
  $stmt->execute([$fullName, preg_replace('/\\D+/', '', $snils), $juryId]);

  // Обновление jury_members
  $stmt = $pdo->prepare("
    UPDATE jury_members
    SET organization = ?, passport_series = ?, passport_number = ?, passport_issued_by = ?, passport_issued_date = ?, birthdate = ?
    WHERE id = ?
  ");
  $stmt->execute([
    $organization, $passportSeries, $passportNumber,
    $passportIssuedBy, $passportIssuedDate, $birthdate, $juryId
  ]);

  // Обновление роли в olympiad_jury
  $stmt = $pdo->prepare("
    UPDATE olympiad_jury
    SET jury_role = ?
    WHERE jury_member_id = ? AND olympiad_id = ?
  ");
  $stmt->execute([$juryRole, $juryId, $olympiadId]);

  echo json_encode(['success' => true]);
} catch (Exception $e) {
  echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
}
?>