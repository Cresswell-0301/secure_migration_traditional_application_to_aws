<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';

// RBAC
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Patient') {
    header('Location: login.php');
    exit;
}

$conn = getDbConnection();
$csrf = ensureCsrfToken();
$patientId = $_SESSION['user_id'];

// Fetch only 'Booked' appointments
$sql = "
    SELECT A.appointment_id, A.appointment_date, A.appointment_time, A.status,
           U.full_name AS doctor_name
    FROM Appointments A
    JOIN Doctors D ON A.doctor_id = D.doctor_id
    JOIN Users U ON D.user_id = U.user_id
    WHERE A.patient_id = ? AND A.status = 'Booked'
    ORDER BY A.appointment_date ASC, A.appointment_time ASC
";

$appointments = fetchAll($conn, $sql, [$patientId]);

include __DIR__ . '/components/header.php';
?>

<div class="content-wrapper" style="padding: 20px;">
    <h2>Cancel Appointment</h2>

    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div style="background: #e8f5e9; color: #2e7d32; padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #a5d6a7;">
            <strong>Success:</strong> Appointment has been cancelled and the slot is now free.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div style="background: #ffebee; color: #c62828; padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #ef9a9a;">
            <strong>Error:</strong> <?= htmlspecialchars($_GET['error']) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($appointments)): ?>
        <p>No active appointments available to cancel.</p>
        <a href="patient_dashboard.php" style="color: #1E88E5;">Back to Dashboard</a>
    <?php else: ?>

    <table style="width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
        <thead>
            <tr style="background: #f4f4f4; border-bottom: 2px solid #ddd;">
                <th style="padding: 12px; text-align: left;">Date</th>
                <th style="padding: 12px; text-align: left;">Time</th>
                <th style="padding: 12px; text-align: left;">Doctor</th>
                <th style="padding: 12px; text-align: left;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($appointments as $a): ?>
            <tr style="border-bottom: 1px solid #eee;">
                <td style="padding: 12px;"><?= $a['appointment_date']->format('Y-m-d') ?></td>
                <td style="padding: 12px;"><?= $a['appointment_time']->format('H:i') ?></td>
                <td style="padding: 12px;"><?= htmlspecialchars($a['doctor_name']) ?></td>
                <td style="padding: 12px;">
                    <form method="post" action="cancel_appointment.php" style="margin:0;" onsubmit="return confirm('Are you sure you want to cancel this appointment?');">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="appointment_id" value="<?= $a['appointment_id'] ?>">
                        <input type="hidden" name="redirect" value="cancel_select.php">

                        <button type="submit" 
                                style="background-color: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 0.9em;">
                            Cancel
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php endif; ?>
</div>  