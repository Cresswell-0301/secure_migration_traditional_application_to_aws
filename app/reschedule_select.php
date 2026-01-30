<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();
require_once __DIR__ . '/includes/db.php';

// RBAC: Only Patients
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Patient') {
    header('Location: login.php');
    exit;
}

$conn = getDbConnection();
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
    <h2>Reschedule Appointment</h2>
    <p>Select an appointment to change its date or time.</p>

    <?php if (empty($appointments)): ?>
        <p>You have no active appointments to reschedule.</p>
        <a href="patient_dashboard.php">Back to Dashboard</a>
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
                    <a href="reschedule_appointment.php?id=<?= $a['appointment_id'] ?>" 
                       style="background-color: #007bff; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-size: 0.9em;">
                        Reschedule
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php endif; ?>
</div>