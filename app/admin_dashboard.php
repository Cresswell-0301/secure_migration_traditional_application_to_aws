<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();
$pageTitle = 'Dashboard';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'SuperAdmin')) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
$conn = getDbConnection();

// Users
$sqlUsers = "
    SELECT
        SUM(CASE WHEN role = 'Patient' THEN 1 ELSE 0 END) AS totalPatients,
        SUM(CASE WHEN role = 'Doctor'  THEN 1 ELSE 0 END) AS totalDoctors
    FROM Users;
";

$userCounts = fetchOne($conn, $sqlUsers) ?? ['totalPatients' => 0, 'totalDoctors' => 0];

$totalPatients = $userCounts['totalPatients'];
$totalDoctors = $userCounts['totalDoctors'];

// Appointments
$sqlAppointments = "
    SELECT
        COALESCE(SUM(CASE WHEN status = 'Booked'    THEN 1 ELSE 0 END), 0) AS totalBooked,
        COALESCE(SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END), 0) AS totalCompleted,
        COALESCE(SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END), 0) AS totalCancelled
    FROM Appointments;
";

$apptCounts = fetchOne($conn, $sqlAppointments) ?? ['totalBooked' => 0, 'totalCompleted' => 0, 'totalCancelled' => 0];

$totalBookedAppointments = $apptCounts['totalBooked'];
$totalCompletedAppointments = $apptCounts['totalCompleted'];
$totalCancelledAppointments = $apptCounts['totalCancelled'];

include __DIR__ . '/components/header.php';
?>

<div class="content-wrapper">
    <div class="admin-dashboard">

        <h2 class="welcome-text">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></h2>

        <!-- Cards -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="icon">🧑‍💼</div>
                <div class="label">Total Patients</div>
                <div class="value"><?php echo $totalPatients; ?></div>
            </div>

            <div class="summary-card">
                <div class="icon">🩺</div>
                <div class="label">Total Doctors</div>
                <div class="value"><?php echo $totalDoctors; ?></div>
            </div>

            <div class="summary-card">
                <div class="icon">📅</div>
                <div class="label">Total Booked Appointments</div>
                <div class="value"><?php echo $totalBookedAppointments; ?></div>
            </div>

            <div class="summary-card">
                <div class="icon">✅</div>
                <div class="label">Total Completed Appointments</div>
                <div class="value"><?php echo $totalCompletedAppointments; ?></div>
            </div>

            <div class="summary-card">
                <div class="icon">❌</div>
                <div class="label">Total Cancelled Appointments</div>
                <div class="value"><?php echo $totalCancelledAppointments; ?></div>
            </div>
        </div>
    </div>
</div>