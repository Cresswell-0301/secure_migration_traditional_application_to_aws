<?php
require_once __DIR__ . '/includes/setCookies.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

$conn = getDbConnection();

if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'], ['Admin', 'SuperAdmin'], true)
) {
    http_response_code(403);
    exit('Forbidden');
}

$appointmentId = (int)($_POST['appointment_id'] ?? 0);
$newStatus     = $_POST['new_status'] ?? '';

if ($appointmentId <= 0 || !in_array($newStatus, ['Completed'], true)) {
    http_response_code(400);
    exit('Invalid request');
}

$sql = "
    SELECT status
    FROM Appointments
    WHERE appointment_id = ?
";

$appointment = fetchOne($conn, $sql, [$appointmentId]);

if (!$appointment || $appointment['status'] !== 'Booked') {
    http_response_code(400);
    exit('Invalid state transition');
}

$sql = "
    UPDATE Appointments
    SET status = ?
    WHERE appointment_id = ?
";

$stmt = sqlsrv_query($conn, $sql, [$newStatus, $appointmentId]);

if ($stmt === false) {
    http_response_code(500);
    exit('Update failed');
}

auditLog(
    $conn,
    $_SESSION['user_id'],
    $_SESSION['role'],
    'UPDATE_APPOINTMENT_STATUS',
    'Appointments',
    $appointmentId,
    json_encode([
        'appointment_id' => $appointmentId,
        'old_status' => $appointment['status'],
        'new_status' => $newStatus
    ]),
    $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
);

header('Location: admin_appointments.php');
exit;
