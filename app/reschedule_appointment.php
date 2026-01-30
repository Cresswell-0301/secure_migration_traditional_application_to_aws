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
$csrf = ensureCsrfToken();
$errors = [];
$success = '';

// Validate ID
$apptId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($apptId <= 0) {
    header("Location: reschedule_select.php");
    exit;
}

// 1. Fetch current appointment details (Need Old Date/Time/Doctor)
$apptSql = "
    SELECT a.appointment_id, a.doctor_id, a.appointment_date, a.appointment_time, a.status,
           u.full_name AS doctor_name
    FROM Appointments a
    JOIN Doctors d ON a.doctor_id = d.doctor_id
    JOIN Users u ON d.user_id = u.user_id
    WHERE a.appointment_id = ? AND a.patient_id = ?
";
$apptRow = fetchAll($conn, $apptSql, [$apptId, $_SESSION['user_id']])[0] ?? null;

if (!$apptRow) {
    die('Appointment not found or access denied.');
}
if ($apptRow['status'] !== 'Booked') {
    die('You can only reschedule "Booked" appointments.');
}

$doctorId = $apptRow['doctor_id'];

// 2. Handle POST Request (The Swap Logic)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modify_submit'])) {
    $token = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($token)) {
        $errors[] = 'Invalid session token. Please refresh.';
    } else {
        $newAvailabilityId = isset($_POST['availability_id']) ? (int)$_POST['availability_id'] : 0;

        if ($newAvailabilityId <= 0) {
            $errors[] = 'Please select a new time slot.';

            auditLog(
                $conn,
                $_SESSION['user_id'],
                $_SESSION['role'],
                'RESCHEDULE_APPOINTMENT_FAILED',
                'Appointments',
                $apptId,
                'No new slot selected for rescheduling appointment ID: ' . $apptId
            );
        } else {
            // Start Transaction
            if (sqlsrv_begin_transaction($conn) === false) {
                $errors[] = 'Database transaction failed.';
            } else {
                try {
                    // A. Lock and Verify NEW Slot
                    // We check if the chosen slot is valid, belongs to the doctor, and is NOT booked
                    $checkSlotSql = "
                        SELECT availability_id, is_booked, available_date, available_time 
                        FROM DoctorAvailability WITH (ROWLOCK, UPDLOCK) 
                        WHERE availability_id = ? AND doctor_id = ?
                    ";
                    $stmtS = sqlsrv_prepare($conn, $checkSlotSql, [$newAvailabilityId, $doctorId]);

                    if (!$stmtS || !sqlsrv_execute($stmtS)) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'RESCHEDULE_APPOINTMENT_FAILED',
                            'DoctorAvailability',
                            $newAvailabilityId,
                            'Error verifying new slot for rescheduling appointment ID: ' . $apptId
                        );
                        throw new Exception("Error verifying new slot.");
                    }

                    $newSlot = sqlsrv_fetch_array($stmtS, SQLSRV_FETCH_ASSOC);

                    if (!$newSlot) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'RESCHEDULE_APPOINTMENT_FAILED',
                            'DoctorAvailability',
                            $newAvailabilityId,
                            'New slot not found for rescheduling appointment ID: ' . $apptId
                        );

                        throw new Exception("New slot not found.");
                    }

                    if ((int)$newSlot['is_booked'] === 1) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'RESCHEDULE_APPOINTMENT_FAILED',
                            'DoctorAvailability',
                            $newAvailabilityId,
                            'Attempted to book an already booked slot for appointment ID: ' . $apptId
                        );

                        throw new Exception("That slot was just taken. Please choose another.");
                    }

                    // B. Lock and Verify OLD Slot (The one we are giving up)
                    // We must find the availability row that matches the CURRENT appointment date/time
                    // Important: Format dates to strings to ensure matching
                    $oldDateStr = $apptRow['appointment_date']->format('Y-m-d');
                    $oldTimeStr = $apptRow['appointment_time']->format('H:i:s'); // SQL Time often needs seconds

                    // C. Perform Updates

                    // 1. Update Appointment Table to NEW Date/Time
                    $updateApptSql = "UPDATE Appointments SET appointment_date = ?, appointment_time = ? WHERE appointment_id = ?";
                    $updA = sqlsrv_prepare($conn, $updateApptSql, [
                        $newSlot['available_date'],
                        $newSlot['available_time'],
                        $apptId
                    ]);

                    if (!$updA || !sqlsrv_execute($updA)) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'RESCHEDULE_APPOINTMENT_FAILED',
                            'Appointments',
                            $apptId,
                            'Failed to update appointment record for rescheduling appointment ID: ' . $apptId
                        );

                        throw new Exception("Failed to update appointment record.");
                    }

                    // 2. Mark NEW Slot as Booked (is_booked = 1)
                    $markNewSql = "UPDATE DoctorAvailability SET is_booked = 1 WHERE availability_id = ?";
                    $markNew = sqlsrv_prepare($conn, $markNewSql, [$newAvailabilityId]);

                    if (!$markNew || !sqlsrv_execute($markNew)) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'RESCHEDULE_APPOINTMENT_FAILED',
                            'DoctorAvailability',
                            $newAvailabilityId,
                            'Failed to book new slot for rescheduling appointment ID: ' . $apptId
                        );

                        throw new Exception("Failed to book new slot.");
                    }

                    // 3. Mark OLD Slot as Available (is_booked = 0)
                    $freeOldSql = "
                        UPDATE DoctorAvailability 
                        SET is_booked = 0 
                        WHERE doctor_id = ? 
                        AND available_date = ? 
                        AND available_time = ?
                    ";
                    $freeOld = sqlsrv_prepare($conn, $freeOldSql, [$doctorId, $oldDateStr, $oldTimeStr]);

                    if (!$freeOld || !sqlsrv_execute($freeOld)) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'RESCHEDULE_APPOINTMENT_FAILED',
                            'DoctorAvailability',
                            null,
                            'Failed to free old slot for rescheduling appointment ID: ' . $apptId
                        );
                        throw new Exception("Failed to free old slot.");
                    }

                    // Commit
                    sqlsrv_commit($conn);

                    auditLog(
                        $conn,
                        $_SESSION['user_id'],
                        $_SESSION['role'],
                        'RESCHEDULE_APPOINTMENT_SUCCESS',
                        'Appointments',
                        $apptId,
                        'Rescheduled appointment ID: ' . $apptId . ' to new slot: ' . json_encode([
                            'availability_id' => $newAvailabilityId,
                            'new_date'        => $newSlot['available_date']->format('Y-m-d'),
                            'new_time'        => $newSlot['available_time']->format('H:i:s')
                        ])
                    );

                    $success = "Appointment rescheduled successfully!";

                    // Refresh page data so the UI updates immediately
                    $apptRow['appointment_date'] = $newSlot['available_date'];
                    $apptRow['appointment_time'] = $newSlot['available_time'];

                    header("Refresh: 1; URL=patient_dashboard.php");
                } catch (Exception $e) {
                    sqlsrv_rollback($conn);
                    $errors[] = "Error: " . $e->getMessage();

                    auditLog(
                        $conn,
                        $_SESSION['user_id'],
                        $_SESSION['role'],
                        'RESCHEDULE_APPOINTMENT_FAILED',
                        'Appointments',
                        $apptId,
                        'Rescheduling appointment ID: ' . $apptId . ' failed with error: ' . $e->getMessage()
                    );
                }
            }
        }
    }
}

// 3. Fetch Available Slots for this Doctor (for the form)
// Only show future slots
$slotsSql = "
    SELECT availability_id, available_date, available_time
    FROM DoctorAvailability
    WHERE doctor_id = ? 
      AND is_booked = 0 
      AND (
            available_date > CONVERT(date, GETDATE())
            OR (
                available_date = CONVERT(date, GETDATE())
                AND available_time >= CONVERT(time, GETDATE())
            )
      )
    ORDER BY available_date, available_time
";
$availableSlots = fetchAll($conn, $slotsSql, [$doctorId]);

include __DIR__ . '/components/header.php';
?>

<div class="content-wrapper" style="padding: 20px;">
    <h2>Modify Appointment</h2>

    <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #90caf9;">
        <strong>Current Booking:</strong><br>
        Doctor: <?= htmlspecialchars($apptRow['doctor_name']) ?><br>
        Date: <?= $apptRow['appointment_date']->format('Y-m-d') ?><br>
        Time: <?= $apptRow['appointment_time']->format('H:i') ?>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="error-message" style="background: #ffebee; color: #c62828; padding: 10px; margin-bottom: 10px; border-radius: 4px;">
            <?= htmlspecialchars($err) ?>
        </div>
    <?php endforeach; ?>

    <?php if ($success): ?>
        <div class="success-message" style="background: #e8f5e9; color: #2e7d32; padding: 10px; margin-bottom: 10px; border-radius: 4px;">
            <?= htmlspecialchars($success) ?>
        </div>
        <p><a href="patient_dashboard.php" style="color: #1E88E5;">Return to Dashboard</a></p>
    <?php endif; ?>

    <?php if (empty($availableSlots)): ?>
        <p>No other available slots found for this doctor.</p>
    <?php else: ?>
        <h3>Select New Date & Time:</h3>
        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr style="background: #f4f4f4; border-bottom: 2px solid #ddd;">
                        <th style="padding: 10px; text-align: left; width: 50px;">Select</th>
                        <th style="padding: 10px; text-align: left;">Date</th>
                        <th style="padding: 10px; text-align: left;">Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($availableSlots as $s): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 10px;">
                                <input type="radio" name="availability_id" value="<?= $s['availability_id'] ?>" required style="transform: scale(1.3);">
                            </td>
                            <td style="padding: 10px;"><?= $s['available_date']->format('Y-m-d') ?></td>
                            <td style="padding: 10px;"><?= $s['available_time']->format('H:i') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top: 20px;">
                <button name="modify_submit" type="submit"
                    style="padding: 12px 24px; background: #1E88E5; color: white; border: none; border-radius: 6px; font-size: 16px; cursor: pointer;">
                    Confirm Change
                </button>
                <a href="reschedule_select.php" style="margin-left: 15px; text-decoration: none; color: #666;">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</div>