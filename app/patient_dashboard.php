<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();

$pageTitle = 'Dashboard';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Patient') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';

$conn = getDbConnection();

$patientId = $_SESSION['user_id'];
$today = date('Y-m-d');

// 1. Today's Appointments
// FIX: Added "AND A.status = 'Booked'" so cancelled appts don't show up
$sqlToday = "
    SELECT A.appointment_id, A.appointment_date, A.appointment_time, U.full_name AS doctor_name
    FROM Appointments A
    JOIN Doctors D ON A.doctor_id = D.doctor_id
    JOIN Users U ON D.user_id = U.user_id
    WHERE A.patient_id = ? 
    AND A.appointment_date = ? 
    AND A.status = 'Booked'
    ORDER BY A.appointment_time ASC
";

$stmtToday = sqlsrv_prepare($conn, $sqlToday, [$patientId, $today]);

sqlsrv_execute($stmtToday);

$todaysAppointments = [];

while ($row = sqlsrv_fetch_array($stmtToday, SQLSRV_FETCH_ASSOC)) {
    $todaysAppointments[] = $row;
}

// 2. Next Upcoming Appointment
// FIX: Added "AND A.status = 'Booked'"
$sqlNext = "
    SELECT TOP 1 A.appointment_id, A.appointment_date, A.appointment_time, U.full_name AS doctor_name
    FROM Appointments A
    JOIN Doctors D ON A.doctor_id = D.doctor_id
    JOIN Users U ON D.user_id = U.user_id
    WHERE A.patient_id = ? 
    AND A.appointment_date > ? 
    AND A.status = 'Booked'
    ORDER BY A.appointment_date ASC, A.appointment_time ASC
";

$stmtNext = sqlsrv_prepare($conn, $sqlNext, [$patientId, $today]);

sqlsrv_execute($stmtNext);

$nextAppointment = sqlsrv_fetch_array($stmtNext, SQLSRV_FETCH_ASSOC);

include __DIR__ . '/components/header.php';
?>

<style>
    :root {
        --pd-bg: #f4f7fb;
        --pd-card: #ffffff;
        --pd-text: #0f172a;
        --pd-muted: #64748b;
        --pd-border: rgba(15, 23, 42, 0.08);
        --pd-primary: #1e88e5;
        --pd-primary-2: #1565c0;
        --pd-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
        --pd-radius: 14px;
    }

    body {
        margin: 0;
        background: radial-gradient(1200px 600px at 10% 0%, rgba(30, 136, 229, 0.12), transparent 60%),
            radial-gradient(900px 500px at 100% 10%, rgba(99, 102, 241, 0.10), transparent 55%),
            var(--pd-bg);
        color: var(--pd-text);
    }

    .dashboard-container {
        max-width: 900px;
        margin: 26px auto 40px;
        padding: 22px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.92), rgba(255, 255, 255, 0.98));
        border-radius: var(--pd-radius);
        box-shadow: var(--pd-shadow);
        border: 1px solid var(--pd-border);
    }

    .dashboard-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        padding: 4px 4px 14px;
        border-bottom: 1px solid var(--pd-border);
    }

    .dashboard-header h2 {
        margin: 0;
        font-size: 22px;
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .dashboard-subtitle {
        margin-top: 6px;
        color: var(--pd-muted);
        font-size: 14px;
        line-height: 1.4;
    }

    .dashboard-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-top: 16px;
    }

    .panel {
        background: var(--pd-card);
        border-radius: 12px;
        border: 1px solid var(--pd-border);
        box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06);
        padding: 14px;
    }

    .section-title {
        margin: 0 0 10px;
        font-weight: 800;
        font-size: 16px;
        letter-spacing: -0.01em;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .section-title .badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 28px;
        height: 28px;
        padding: 0 10px;
        border-radius: 999px;
        background: rgba(30, 136, 229, 0.10);
        color: var(--pd-primary-2);
        font-size: 12px;
        font-weight: 800;
    }

    .appointment-card {
        padding: 12px;
        border: 1px solid var(--pd-border);
        border-radius: 12px;
        margin-bottom: 10px;
        background: linear-gradient(180deg, rgba(30, 136, 229, 0.06), rgba(255, 255, 255, 0.9));
        border-left: 5px solid var(--pd-primary);
        line-height: 1.45;
    }

    .appointment-card strong {
        color: var(--pd-text);
    }

    .muted-empty {
        color: var(--pd-muted);
        font-style: normal;
        background: rgba(100, 116, 139, 0.08);
        border: 1px dashed rgba(100, 116, 139, 0.25);
        border-radius: 12px;
        padding: 12px;
        margin: 0;
    }

    .dashboard-actions {
        margin-top: 18px;
    }

    .dashboard-actions .section-title {
        margin-bottom: 12px;
    }

    .dashboard-links {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .dashboard-links a {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 12px 14px;
        background: linear-gradient(180deg, var(--pd-primary), var(--pd-primary-2));
        color: #fff;
        text-align: center;
        text-decoration: none;
        border-radius: 12px;
        font-weight: 800;
        letter-spacing: 0.01em;
        box-shadow: 0 10px 18px rgba(30, 136, 229, 0.18);
        transition: transform 120ms ease, box-shadow 120ms ease, filter 120ms ease;
    }

    .dashboard-links a:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 26px rgba(30, 136, 229, 0.22);
        filter: brightness(1.02);
    }

    .dashboard-links a:active {
        transform: translateY(0);
    }

    @media (max-width: 860px) {
        .dashboard-container {
            margin: 18px 12px 28px;
            padding: 16px;
        }

        .dashboard-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 520px) {
        .dashboard-links {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="dashboard-container">
    <div class="dashboard-header">
        <div>
            <h2>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></h2>
            <div class="dashboard-subtitle">Manage your appointments quickly from one place.</div>
        </div>
    </div>

    <div class="dashboard-grid">
        <div class="panel">
            <div class="section-title"><span class="badge">Today</span> Today's Appointments</div>
            <?php if (empty($todaysAppointments)): ?>
                <p class="muted-empty">No active appointments for today.</p>
            <?php else: ?>
                <?php foreach ($todaysAppointments as $appt): ?>
                    <div class="appointment-card">
                        <strong>Doctor:</strong> <?= htmlspecialchars($appt['doctor_name']) ?><br>
                        <strong>Time:</strong> <?= $appt['appointment_time']->format('H:i') ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="panel">
            <div class="section-title"><span class="badge">Next</span> Next Upcoming Appointment</div>
            <?php if ($nextAppointment): ?>
                <div class="appointment-card">
                    <strong>Date:</strong> <?= $nextAppointment['appointment_date']->format('Y-m-d') ?><br>
                    <strong>Time:</strong> <?= $nextAppointment['appointment_time']->format('H:i') ?><br>
                    <strong>Doctor:</strong> <?= htmlspecialchars($nextAppointment['doctor_name']) ?>
                </div>
            <?php else: ?>
                <p class="muted-empty">No upcoming appointments found.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="dashboard-actions">
        <div class="section-title"><span class="badge">Actions</span> Quick Actions</div>
        <div class="dashboard-links">
            <a href="view_appointment.php">View My Appointments</a>
            <a href="book_appointment.php">Book a New Appointment</a>
            <a href="reschedule_select.php">Reschedule My Appointments</a>
            <a href="cancel_select.php">Cancel My Appointments</a>
        </div>
    </div>
</div>

</body>