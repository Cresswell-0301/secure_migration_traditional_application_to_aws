<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Patient') {
    header('Location: login.php');
    exit;
}

$conn = getDbConnection();
$csrf = ensureCsrfToken();

$errors = [];
$success = '';

// Handle cancel POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_submit'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $errors[] = 'Invalid session token.';
    } else {
        $apptId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;
        if ($apptId <= 0) {
            $errors[] = 'Invalid appointment ID.';
        } else {
            // Begin transaction: set appointment status = 'Cancelled' and free matching DoctorAvailability
            if (!sqlsrv_begin_transaction($conn)) {
                $errors[] = 'Failed to start DB transaction.';
            } else {
                try {
                    // Verify appointment belongs to user and is Booked
                    $checkSql = "
                        SELECT appointment_id, doctor_id, appointment_date, appointment_time, status
                        FROM Appointments WITH (ROWLOCK, UPDLOCK)
                        WHERE appointment_id = ? AND patient_id = ?
                    ";
                    $stmt = sqlsrv_prepare($conn, $checkSql, [$apptId, $_SESSION['user_id']]);
                    if ($stmt === false || !sqlsrv_execute($stmt)) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'CANCEL_APPOINTMENT_FAILED',
                            'Appointments',
                            $apptId,
                            'Error verifying appointment for cancellation. Appointment ID: ' . $apptId
                        );
                        throw new Exception('Error verifying appointment.');
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
                            'Attempted to cancel non-existent appointment ID: ' . $apptId
                        );
                        throw new Exception('Appointment not found.');
                    }

                    if ($appt['status'] !== 'Booked') {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'CANCEL_APPOINTMENT_FAILED',
                            'Appointments',
                            $apptId,
                            'Attempted to cancel non-booked appointment ID: ' . $apptId
                        );
                        throw new Exception('Only booked appointments can be cancelled.');
                    }

                    // Update appointment status
                    $updSql = "UPDATE Appointments SET status = 'Cancelled' WHERE appointment_id = ?";
                    $updStmt = sqlsrv_prepare($conn, $updSql, [$apptId]);

                    if ($updStmt === false || !sqlsrv_execute($updStmt)) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'CANCEL_APPOINTMENT_FAILED',
                            'Appointments',
                            $apptId,
                            'Error updating appointment status to Cancelled. Appointment ID: ' . $apptId
                        );
                        throw new Exception('Failed to cancel appointment.');
                    }

                    // Free the DoctorAvailability row (match by doctor/date/time)
                    $freeSql = "
                        UPDATE DoctorAvailability
                        SET is_booked = 0
                        WHERE doctor_id = ? AND available_date = ? AND available_time = ?
                    ";
                    $freeStmt = sqlsrv_prepare($conn, $freeSql, [$appt['doctor_id'], $appt['appointment_date'], $appt['appointment_time']]);
                    if ($freeStmt === false || !sqlsrv_execute($freeStmt)) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'CANCEL_APPOINTMENT_FAILED',
                            'DoctorAvailability',
                            null,
                            'Error freeing booked slot for appointment ID: ' . $apptId
                        );
                        throw new Exception('Failed to free booked slot.');
                    }

                    if (!sqlsrv_commit($conn)) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'CANCEL_APPOINTMENT_FAILED',
                            'Appointments',
                            $apptId,
                            'Failed to commit cancellation transaction for appointment ID: ' . $apptId
                        );
                        throw new Exception('Failed to commit cancellation.');
                    }

                    auditLog(
                        $conn,
                        $_SESSION['user_id'],
                        $_SESSION['role'],
                        'CANCEL_APPOINTMENT_SUCCESS',
                        'Appointments',
                        $apptId,
                        'Successfully cancelled appointment ID: ' . $apptId . json_encode([
                            'doctor_id'       => $appt['doctor_id'],
                            'appointment_date'=> $appt['appointment_date']->format('Y-m-d'),
                            'appointment_time'=> $appt['appointment_time']->format('H:i:s')
                        ])
                    );

                    $success = 'Appointment cancelled.';
                } catch (Exception $e) {
                    sqlsrv_rollback($conn);
                    $errors[] = $e->getMessage();

                    auditLog(
                        $conn,
                        $_SESSION['user_id'],
                        $_SESSION['role'],
                        'CANCEL_APPOINTMENT_FAILED',
                        'Appointments',
                        $apptId,
                        'Cancellation of appointment ID: ' . $apptId . ' failed with error: ' . $e->getMessage()
                    );
                }
            }
        }
    }
}

// Fetch appointments for the patient
$listSql = "
    SELECT A.appointment_id, A.appointment_date, A.appointment_time, A.status,
           D.doctor_id, U.full_name AS doctor_name
    FROM Appointments A
    JOIN Doctors D ON A.doctor_id = D.doctor_id
    JOIN Users U ON D.user_id = U.user_id
    WHERE A.patient_id = ?
    ORDER BY A.appointment_date DESC, A.appointment_time DESC
";
$appointments = fetchAll($conn, $listSql, [$_SESSION['user_id']]);

include __DIR__ . '/components/header.php';
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>My Appointments</title>
</head>

<style>
    .btn-modify {
        background: #1E88E5;
        color: #fff;
    }

    .btn-cancel {
        background: #E53935;
        color: #fff;
    }

    .btn-cancel:hover {
        background: #8f4f4eff;
    }
</style>

<body>
    <div class="container">
        <h2> &nbsp; &nbsp; My Appointments</h2>

        <?php foreach ($errors as $err): ?>
            <div class="error-message"><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>
        <?php if ($success): ?>
            <div class="success-message"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if (empty($appointments)): ?>
            <p>You have no appointments.</p>
        <?php else: ?>
            <table style="width:100%; background:white; border-radius:8px; padding:20px; text-align:center;">
                <tr>
                    <th style="padding:10px;">Date</th>
                    <th style="padding:10px;">Time</th>
                    <th style="padding:10px;">Doctor</th>
                    <th style="padding:10px;">Status</th>
                    <th style="padding:10px;">Actions</th>
                </tr>

                <?php if (empty($appointments)): ?>
                    <tr>
                        <td colspan="5" style="padding:15px;">No appointments found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($appointments as $a): ?>
                        <tr>
                            <td><?= $a['appointment_date']->format('Y-m-d') ?></td>
                            <td><?= $a['appointment_time']->format('H:i') ?></td>
                            <td><?= htmlspecialchars($a['doctor_name']) ?></td>
                            <td><?= htmlspecialchars($a['status']) ?></td>
                            <td style="display:flex; gap:8px; justify-content:center;">

                                <?php if ($a['status'] === 'Booked'): ?>

                                    <a href="reschedule_select.php?id=<?= (int)$a['appointment_id'] ?>"
                                        class="admin-btn btn-modify">
                                        Modify
                                    </a>

                                    <form method="post"
                                        action="cancel_appointment.php"
                                        style="margin:0;"
                                        onsubmit="return confirm('Are you sure you want to cancel this appointment?');">

                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="appointment_id" value="<?= (int)$a['appointment_id'] ?>">
                                        <input type="hidden" name="redirect" value="view_appointment.php">

                                        <button type="submit" class="admin-btn btn-cancel">
                                            Cancel
                                        </button>
                                    </form>

                                <?php else: ?>
                                    <span class="admin-btn"
                                        style="background:#e0e0e0; color:#666; padding:6px 12px; border-radius:6px;">
                                        N/A
                                    </span>
                                <?php endif; ?>

                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        <?php endif; ?>
    </div>
</body>

</html>