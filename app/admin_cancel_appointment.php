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

if ($appointmentId <= 0) {
    http_response_code(400);
    exit('Invalid request');
}

$sql = "
    SELECT
        appointment_id,
        doctor_id,
        appointment_date,
        appointment_time,
        DATEADD(SECOND, DATEDIFF(SECOND, '00:00:00', appointment_time),
                CAST(appointment_date AS datetime2)) AS appointment_dt,
        status
    FROM Appointments
    WHERE appointment_id = ?
";
$appointment = fetchOne($conn, $sql, [$appointmentId]);

if (!$appointment) {
    http_response_code(404);
    exit('Appointment not found');
}

if ($appointment['status'] !== 'Booked') {
    http_response_code(400);
    exit('Appointment cannot be cancelled');
}

$now = new DateTime('now');

if ($appointment['appointment_dt'] < $now) {
    http_response_code(400);
    exit('Past appointments cannot be cancelled');
}

sqlsrv_begin_transaction($conn);

try {
    $sql = "
        UPDATE Appointments
        SET status = 'Cancelled'
        WHERE appointment_id = ?
    ";

    $stmt = sqlsrv_query($conn, $sql, [$appointmentId]);

    if ($stmt === false) {
        throw new Exception('Failed to update appointment');
    }

    $sql = "
        UPDATE da
        SET da.is_booked = 0
        FROM DoctorAvailability da
        JOIN Appointments a
        ON da.doctor_id = a.doctor_id
        AND da.available_date = a.appointment_date
        AND da.available_time = a.appointment_time
        WHERE a.appointment_id = ?
    ";
    $stmt = sqlsrv_query($conn, $sql, [$appointmentId]);

    if ($stmt === false || sqlsrv_rows_affected($stmt) === 0) {
        throw new Exception('Failed to release availability');
    }


    auditLog(
        $conn,
        $_SESSION['user_id'],
        $_SESSION['role'],
        'CANCEL_APPOINTMENT',
        'Appointments',
        $appointmentId,
        json_encode([
            'appointment_id' => $appointment['appointment_id'],
            'doctor_id' => $appointment['doctor_id'],
            'appointment_date' => $appointment['appointment_date']->format('Y-m-d'),
            'appointment_time' => $appointment['appointment_time']->format('H:i:s'),
            'status' => 'Cancelled',
        ]),
        $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
    );

    sqlsrv_commit($conn);
} catch (Throwable $e) {
    sqlsrv_rollback($conn);

    http_response_code(500);
    // exit('Cancellation failed');
    exit('Cancellation failed: ' . $e->getMessage());
}

header('Location: admin_appointments.php');
exit;
