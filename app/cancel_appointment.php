<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';

// RBAC
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Patient') {
    header('Location: login.php');
    exit;
}

$conn = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. CSRF Check
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid Security Token. Please go back and refresh the page.');
    }

    $redirect = $_POST['redirect'] ?? 'view_appointment.php';

    $allowedRedirects = ['view_appointment.php', 'cancel_select.php'];

    if (!in_array($redirect, $allowedRedirects, true)) {
        $redirect = 'view_appointment.php';
    }

    $apptId = (int)($_POST['appointment_id'] ?? 0);
    $patientId = $_SESSION['user_id'];

    if ($apptId <= 0) die('Invalid Appointment ID.');

    // 2. Start Transaction
    if (sqlsrv_begin_transaction($conn) === false) {
        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'CANCEL_APPOINTMENT_FAILED',
            'Appointments',
            $apptId,
            'Failed to start database transaction for appointment ID: ' . $apptId
        );
        die("Failed to start database transaction.");
    }

    try {
        // A. Lock and Fetch Appointment
        // We ensure it belongs to the patient AND is currently 'Booked'
        $sql = "
            SELECT appointment_id, doctor_id, appointment_date, appointment_time 
            FROM Appointments WITH (ROWLOCK, UPDLOCK)
            WHERE appointment_id = ? AND patient_id = ? AND status = 'Booked'
        ";
        $stmt = sqlsrv_prepare($conn, $sql, [$apptId, $patientId]);

        if (!$stmt || !sqlsrv_execute($stmt)) {
            auditLog(
                $conn,
                $_SESSION['user_id'],
                $_SESSION['role'],
                'CANCEL_APPOINTMENT_FAILED',
                'Appointments',
                $apptId,
                'Error verifying appointment for cancellation. Appointment ID: ' . $apptId
            );

            throw new Exception("Error verifying appointment.");
        }

        $appt = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

        if (!$appt) {
            auditLog(
                $conn,
                $_SESSION['user_id'],
                $_SESSION['role'],
                'CANCEL_APPOINTMENT_FAILED',
                'Appointments',
                $apptId,
                'Appointment not found or already cancelled. Appointment ID: ' . $apptId
            );

            throw new Exception("Appointment not found or already cancelled.");
        }

        // B. Format Data for safe SQL matching
        // Convert objects to strings to ensure the WHERE clause finds the availability row
        $dateStr = $appt['appointment_date']->format('Y-m-d');
        $timeStr = $appt['appointment_time']->format('H:i:s'); // Include seconds for Time column

        // C. Update Appointment Status
        $updSql = "UPDATE Appointments SET status = 'Cancelled' WHERE appointment_id = ?";
        $upd = sqlsrv_prepare($conn, $updSql, [$apptId]);

        if (!$upd || !sqlsrv_execute($upd)) {
            auditLog(
                $conn,
                $_SESSION['user_id'],
                $_SESSION['role'],
                'CANCEL_APPOINTMENT_FAILED',
                'Appointments',
                $apptId,
                'Failed to update appointment status to Cancelled. Appointment ID: ' . $apptId
            );

            throw new Exception("Failed to update status.");
        }

        // D. Free the Doctor's Slot 
        $freeSql = "
            UPDATE DoctorAvailability 
            SET is_booked = 0
            WHERE doctor_id = ? 
            AND available_date = ? 
            AND available_time = ?
        ";
        $free = sqlsrv_prepare($conn, $freeSql, [$appt['doctor_id'], $dateStr, $timeStr]);

        if (!$free || !sqlsrv_execute($free)) {
            auditLog(
                $conn,
                $_SESSION['user_id'],
                $_SESSION['role'],
                'CANCEL_APPOINTMENT_FAILED',
                'DoctorAvailability',
                null,
                'Failed to release schedule slot for Doctor ID: ' . $appt['doctor_id'] .
                    ' on ' . $dateStr . ' at ' . $timeStr
            );

            throw new Exception("Failed to release schedule slot.");
        }

        // E. Commit
        sqlsrv_commit($conn);

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'CANCEL_APPOINTMENT_SUCCESS',
            'Appointments',
            $apptId,
            'Successfully cancelled appointment ID: ' . $apptId . json_encode([
                'doctor_id'       => $appt['doctor_id'],
                'appointment_date'=> $dateStr,
                'appointment_time'=> $timeStr
            ])
        );

        // Redirect with success message
        header("Location: {$redirect}?success=1");
        exit;
    } catch (Exception $e) {
        // Rollback on any failure
        sqlsrv_rollback($conn);
        // Redirect with error message
        $msg = urlencode($e->getMessage());

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'CANCEL_APPOINTMENT_FAILED',
            'Appointments',
            $apptId,
            'Appointment cancellation failed: ' . $e->getMessage()
        );

        header("Location: {$redirect}?error={$msg}");
        exit;
    }
} else {
    // If accessed directly without POST
    auditLog(
        $conn,
        $_SESSION['user_id'],
        $_SESSION['role'],
        'CANCEL_APPOINTMENT_INVALID_ACCESS',
        'Appointments',
        null,
        'Direct access to cancel_appointment.php without POST.'
    );

    header("Location: {$redirect}");
    exit;
}
