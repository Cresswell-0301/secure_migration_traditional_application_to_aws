<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';

// 1. RBAC: Only doctors can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header("Location: login.php");
    exit;
}

$conn = getDbConnection();
$csrf = ensureCsrfToken();
$doctorId = isset($_SESSION['doctor_id']) ? (int)$_SESSION['doctor_id'] : 0;
$errors = [];
$success = '';

// 2. Validation: Check if ID is in URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: doctor_dashboard.php");
    exit;
}

$apptId = (int)$_GET['id'];

// 3. QUERY: Fetch Appointment & Patient Name
$sql = "
    SELECT 
        a.appointment_id, 
        a.doctor_id, 
        a.patient_id, 
        a.status, 
        a.appointment_date, 
        a.appointment_time,
        p.full_name AS patient_name
    FROM Appointments a
    LEFT JOIN Users p ON a.patient_id = p.user_id 
    WHERE a.appointment_id = ?
";

$stmt = sqlsrv_query($conn, $sql, [$apptId]);

if ($stmt === false) {
    auditLog(
        $conn,
        $_SESSION['user_id'],
        $_SESSION['role'],
        'FETCH_APPOINTMENT_FAILED',
        'Appointments',
        $apptId,
        'Failed to fetch appointment details for appointment ID: ' . $apptId
    );
    die("Database Error Details: " . print_r(sqlsrv_errors(), true));
}

$appt = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

// CHECK 1: Does row exist?
if (!$appt) {
    echo "<div style='text-align:center; margin-top:50px; font-family:sans-serif;'>
            <h3 style='color:red;'>Appointment Not Found</h3>
            <p>The system searched for ID <strong>$apptId</strong> but found no records.</p>
            <a href='doctor_dashboard.php'>Return to Dashboard</a>
          </div>";
    exit;
}

// CHECK 2: Doctor Ownership
if ((int)$appt['doctor_id'] !== $doctorId) {
    echo "<div style='text-align:center; margin-top:50px; font-family:sans-serif;'>
            <h3 style='color:red;'>Access Denied</h3>
            <p>This appointment belongs to a different doctor.</p>
            <a href='doctor_dashboard.php'>Return to Dashboard</a>
          </div>";
    exit;
}

// 4. Handle Update Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $token = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($token)) {
        $errors[] = "Invalid session token. Please refresh.";
    } else {
        $newStatus = $_POST['status'] ?? '';
        $allowedStatuses = ['Booked', 'Completed', 'Cancelled'];

        if (!in_array($newStatus, $allowedStatuses, true)) {
            $errors[] = "Invalid status selected.";

            auditLog(
                $conn,
                $_SESSION['user_id'],
                $_SESSION['role'],
                'UPDATE_APPOINTMENT_INVALID_STATUS',
                'Appointments',
                $apptId,
                'Attempted to set invalid status: ' . $newStatus . ' for appointment ID: ' . $apptId
            );
        } else {

            // START TRANSACTION
            // We are updating two tables (Appointments and DoctorAvailability), so we need a transaction.
            if (sqlsrv_begin_transaction($conn) === false) {
                $errors[] = "Failed to start transaction.";
            } else {
                try {
                    // A. Update Appointment Status
                    $updateSql = "UPDATE Appointments SET status = ? WHERE appointment_id = ?";
                    $updateStmt = sqlsrv_prepare($conn, $updateSql, [$newStatus, $apptId]);

                    if (!$updateStmt || !sqlsrv_execute($updateStmt)) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'UPDATE_APPOINTMENT_FAILED',
                            'Appointments',
                            $apptId,
                            'Failed to update status to ' . $newStatus . ' for appointment ID: ' . $apptId
                        );
                        throw new Exception("Failed to update appointment status.");
                    }

                    // B. Update Schedule Availability Logic 
                    // If Cancelled -> Free the slot (is_booked = 0)
                    // If Booked    -> Lock the slot (is_booked = 1)
                    // If Completed -> Keep it locked (is_booked = 1) usually, as the time is passed/used.

                    $isBookedValue = -1; // -1 means do nothing to availability

                    if ($newStatus === 'Cancelled') {
                        $isBookedValue = 0; // Make slot available again
                    } elseif ($newStatus === 'Booked') {
                        $isBookedValue = 1; // Make slot booked again (in case of accidental cancel)
                    }
                    // Note: If 'Completed', we usually leave it as booked/occupied so it can't be reused.

                    if ($isBookedValue !== -1) {
                        // We use the date and time from the fetched $appt to find the matching slot
                        $availSql = "
                            UPDATE DoctorAvailability 
                            SET is_booked = $isBookedValue 
                            WHERE doctor_id = $doctorId
                            AND available_date = $appt[appointment_date]
                            AND available_time = $appt[appointment_time]
                        ";

                        $availParams = [
                            $isBookedValue,
                            $doctorId,
                            $appt['appointment_date'],
                            $appt['appointment_time']
                        ];

                        $availStmt = sqlsrv_prepare($conn, $availSql, $availParams);
                        if (!$availStmt || !sqlsrv_execute($availStmt)) {
                            auditLog(
                                $conn,
                                $_SESSION['user_id'],
                                $_SESSION['role'],
                                'UPDATE_AVAILABILITY_FAILED',
                                'DoctorAvailability',
                                null,
                                'Failed to update availability for Doctor ID: ' . $doctorId .
                                    ' on ' . $appt['appointment_date']->format('Y-m-d') .
                                    ' at ' . $appt['appointment_time']->format('H:i') .
                                    ' to is_booked=' . $isBookedValue
                            );
                            throw new Exception("Failed to update schedule availability.");
                        }
                    }

                    // Commit Transaction
                    sqlsrv_commit($conn);

                    auditLog(
                        $conn,
                        $_SESSION['user_id'],
                        $_SESSION['role'],
                        'UPDATE_APPOINTMENT_SUCCESS',
                        'Appointments',
                        $apptId,
                        'Successfully updated appointment ID: ' . $apptId . ' to status: ' . $newStatus
                    );

                    $success = "Status updated to '{$newStatus}' successfully.";
                    $appt['status'] = $newStatus; // Update display immediately

                } catch (Exception $e) {
                    sqlsrv_rollback($conn);

                    auditLog(
                        $conn,
                        $_SESSION['user_id'],
                        $_SESSION['role'],
                        'UPDATE_APPOINTMENT_FAILED',
                        'Appointments',
                        $apptId,
                        'Transaction failed while updating appointment ID: ' . $apptId . '. Error: ' . $e->getMessage()
                    );
                    
                    $errors[] = "Error: " . $e->getMessage();
                }
            }
        }
    }
}

include __DIR__ . '/components/header.php';
?>

<div class="content-wrapper">
    <div class="admin-dashboard">

        <div class="form-container" style="max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">

            <div style="border-bottom: 2px solid #eee; padding-bottom: 15px; margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center;">
                <h2 style="margin:0;">Manage Appointment</h2>
                <span style="background:#eee; padding:5px 10px; border-radius:4px; font-size:0.8rem;">ID: <?php echo $apptId; ?></span>
            </div>

            <?php foreach ($errors as $err): ?>
                <div class="error-message" style="background: #ffebee; color: #c62828; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
                    <?php echo htmlspecialchars($err); ?>
                </div>
            <?php endforeach; ?>

            <?php if ($success): ?>
                <div class="success-message" style="background: #e8f5e9; color: #2e7d32; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <table style="width:100%; margin-bottom: 25px; border-collapse: collapse;">
                <tr>
                    <td style="padding: 10px; border-bottom: 1px solid #eee; font-weight:bold; width: 150px;">Patient Name:</td>
                    <td style="padding: 10px; border-bottom: 1px solid #eee;">
                        <?php echo htmlspecialchars($appt['patient_name'] ?? 'Unknown'); ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 10px; border-bottom: 1px solid #eee; font-weight:bold;">Date:</td>
                    <td style="padding: 10px; border-bottom: 1px solid #eee;">
                        <?php
                        if ($appt['appointment_date'] instanceof DateTime) {
                            echo $appt['appointment_date']->format('Y-m-d');
                        } else {
                            echo htmlspecialchars($appt['appointment_date']);
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 10px; border-bottom: 1px solid #eee; font-weight:bold;">Time:</td>
                    <td style="padding: 10px; border-bottom: 1px solid #eee;">
                        <?php
                        if ($appt['appointment_time'] instanceof DateTime) {
                            echo $appt['appointment_time']->format('H:i');
                        } else {
                            echo htmlspecialchars($appt['appointment_time']);
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 10px; border-bottom: 1px solid #eee; font-weight:bold;">Current Status:</td>
                    <td style="padding: 10px; border-bottom: 1px solid #eee;">
                        <span style="
                            padding: 4px 8px; 
                            border-radius: 4px; 
                            font-weight: bold;
                            color: #1E88E5;
                        ">
                            <?php echo htmlspecialchars($appt['status']); ?>
                        </span>
                    </td>
                </tr>
            </table>

            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">

                <div style="margin-bottom: 20px;">
                    <label for="status" style="display: block; margin-bottom: 8px; font-weight: bold;">Update Status:</label>
                    <select name="status" id="status" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="Booked" <?php echo $appt['status'] === 'Booked' ? 'selected' : ''; ?>>Booked</option>
                        <option value="Completed" <?php echo $appt['status'] === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="Cancelled" <?php echo $appt['status'] === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <a href="doctor_dashboard.php" style="padding: 10px 20px; text-decoration: none; color: #555; background: #f5f5f5; border-radius: 4px;">Back</a>
                    <button type="submit" name="update_status" style="padding: 10px 20px; background: #1E88E5; color: white; border: none; border-radius: 4px; cursor: pointer;">
                        Save Changes
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>