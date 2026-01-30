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
if (!$conn) {
    die("DB connection failed: " . print_r(sqlsrv_errors(), true));
}

$errors = [];
$success = '';
$csrf = ensureCsrfToken();

// Fetch doctors for selection
$doctorsSql = "
    SELECT D.doctor_id, U.full_name, D.specialization
    FROM Doctors D
    JOIN Users U ON D.user_id = U.user_id
    ORDER BY U.full_name
";
$doctors = fetchAll($conn, $doctorsSql, []);


// If POST -> attempt booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_submit'])) {
    // Validate CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $errors[] = 'Invalid session token. Please reload the page and try again.';
    } else {
        // Validate inputs
        $doctorId = isset($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0;
        $availabilityId = isset($_POST['availability_id']) ? (int)$_POST['availability_id'] : 0;

        if ($doctorId <= 0 || $availabilityId <= 0) {
            $errors[] = 'Invalid doctor or slot selection.';

            auditLog(
                $conn,
                $_SESSION['user_id'],
                $_SESSION['role'],
                'BOOKING_FAILED',
                'Appointments',
                null,
                'Invalid doctor or slot selection'
            );
        } else {
            // Start transaction
            if (!sqlsrv_begin_transaction($conn)) {
                $errors[] = 'Unable to start transaction.';

                auditLog(
                    $conn,
                    $_SESSION['user_id'],
                    $_SESSION['role'],
                    'BOOKING_FAILED',
                    'Appointments',
                    null,
                    'Unable to start transaction'
                );
            } else {
                try {
                    // Lock the availability row: ensure slot belongs to doctor and is not booked
                    $checkSql = "
                        SELECT availability_id, is_booked, available_date, available_time
                        FROM DoctorAvailability WITH (ROWLOCK, UPDLOCK)
                        WHERE availability_id = ? AND doctor_id = ?
                    ";
                    $params = [$availabilityId, $doctorId];
                    $stmt = sqlsrv_prepare($conn, $checkSql, $params);
                    if ($stmt === false || !sqlsrv_execute($stmt)) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'BOOKING_FAILED',
                            'Appointments',
                            null,
                            'Internal error checking availability'
                        );
                        throw new Exception('Internal error checking availability.');
                    }
                    $avail = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                    if (!$avail) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'BOOKING_FAILED',
                            'Appointments',
                            null,
                            'Selected slot not found'
                        );
                        throw new Exception('Selected slot not found.');
                    }
                    if ((int)$avail['is_booked'] === 1) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'BOOKING_FAILED',
                            'Appointments',
                            null,
                            'Selected slot is already taken'
                        );
                        throw new Exception('Selected slot is already taken.');
                    }

                    // Insert appointment
                    $insertSql = "
                        INSERT INTO Appointments (patient_id, doctor_id, appointment_date, appointment_time, status)
                        VALUES (?, ?, ?, ?, 'Booked')
                    ";
                    $insertParams = [$_SESSION['user_id'], $doctorId, $avail['available_date'], $avail['available_time']];
                    $insStmt = sqlsrv_prepare($conn, $insertSql, $insertParams);
                    if ($insStmt === false || !sqlsrv_execute($insStmt)) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'BOOKING_FAILED',
                            'Appointments',
                            null,
                            'Failed to create appointment'
                        );
                        throw new Exception('Failed to create appointment.');
                    }

                    // Mark availability as booked
                    $updateAvailSql = "
                        UPDATE DoctorAvailability
                        SET is_booked = 1
                        WHERE availability_id = ?
                    ";
                    $updStmt = sqlsrv_prepare($conn, $updateAvailSql, [$availabilityId]);
                    if ($updStmt === false || !sqlsrv_execute($updStmt)) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'BOOKING_FAILED',
                            'Appointments',
                            null,
                            'Failed to mark slot as booked'
                        );
                        throw new Exception('Failed to mark slot as booked.');
                    }

                    $idStmt = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS appointment_id");
                    if ($idStmt === false) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'BOOKING_FAILED',
                            'Appointments',
                            null,
                            'Failed to retrieve appointment ID'
                        );
                        throw new Exception('Failed to retrieve appointment ID.');
                    }

                    $idRow = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
                    $appointmentId = (int)$idRow['appointment_id'];

                    // Commit
                    if (!sqlsrv_commit($conn)) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'BOOKING_FAILED',
                            'Appointments',
                            null,
                            'Failed to commit transaction'
                        );
                        throw new Exception('Failed to commit transaction.');
                    }

                    auditLog(
                        $conn,
                        $_SESSION['user_id'],
                        $_SESSION['role'],
                        'CREATE',
                        'Appointments',
                        $appointmentId,
                        'Appointment booked successfully: ' . json_encode([
                            'availability_id' => $availabilityId,
                            'patient_id'      => $_SESSION['user_id'],
                            'doctor_id'       => $doctorId,
                            'appointment_date'=> $avail['available_date']->format('Y-m-d'),
                            'appointment_time'=> $avail['available_time']->format('H:i:s')
                        ])
                    );

                    $success = 'Appointment booked successfully.';
                } catch (Exception $e) {
                    // rollback on any error
                    sqlsrv_rollback($conn);

                    auditLog(
                        $conn,
                        $_SESSION['user_id'],
                        $_SESSION['role'],
                        'BOOKING_FAILED',
                        'Appointments',
                        null,
                        'Transaction rolled back due to error: ' . $e->getMessage()
                    );

                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

// Optionally, if doctor selected via GET, fetch their free slots
$selectedDoctorId = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
$availableSlots = [];
if ($selectedDoctorId > 0) {
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
    $availableSlots = fetchAll($conn, $slotsSql, [$selectedDoctorId]);
}

include __DIR__ . '/components/header.php';
?>
<style>
    :root {
        --ba-bg: #f4f7fb;
        --ba-card: #ffffff;
        --ba-text: #0f172a;
        --ba-muted: #64748b;
        --ba-border: rgba(15, 23, 42, 0.10);
        --ba-primary: #1e88e5;
        --ba-primary-2: #1565c0;
        --ba-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
        --ba-radius: 14px;
    }

    body {
        margin: 0;
        background: radial-gradient(1200px 600px at 10% 0%, rgba(30, 136, 229, 0.12), transparent 60%),
            radial-gradient(900px 500px at 100% 10%, rgba(99, 102, 241, 0.10), transparent 55%),
            var(--ba-bg);
        color: var(--ba-text);
    }

    .booking-container {
        max-width: 980px;
        margin: 26px auto 40px;
        padding: 22px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.92), rgba(255, 255, 255, 0.98));
        border-radius: var(--ba-radius);
        box-shadow: var(--ba-shadow);
        border: 1px solid var(--ba-border);
    }

    .booking-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        padding: 4px 4px 14px;
        border-bottom: 1px solid var(--ba-border);
        margin-bottom: 16px;
    }

    .booking-header h2 {
        margin: 0;
        font-size: 22px;
        font-weight: 800;
        letter-spacing: -0.02em;
    }

    .booking-subtitle {
        margin-top: 6px;
        color: var(--ba-muted);
        font-size: 14px;
        line-height: 1.4;
    }

    .booking-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 16px;
    }

    .panel {
        background: var(--ba-card);
        border-radius: 12px;
        border: 1px solid var(--ba-border);
        box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06);
        padding: 14px;
    }

    .panel-title {
        margin: 0 0 10px;
        font-weight: 800;
        font-size: 16px;
        letter-spacing: -0.01em;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .panel-title .badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 28px;
        padding: 0 10px;
        border-radius: 999px;
        background: rgba(30, 136, 229, 0.10);
        color: var(--ba-primary-2);
        font-size: 12px;
        font-weight: 800;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .booking-select {
        width: 100%;
        padding: 12px 12px;
        border: 1px solid var(--ba-border);
        border-radius: 12px;
        background: #fff;
        outline: none;
    }

    .booking-select:focus {
        border-color: rgba(30, 136, 229, 0.55);
        box-shadow: 0 0 0 4px rgba(30, 136, 229, 0.12);
    }

    .muted-empty {
        color: var(--ba-muted);
        font-style: normal;
        background: rgba(100, 116, 139, 0.08);
        border: 1px dashed rgba(100, 116, 139, 0.25);
        border-radius: 12px;
        padding: 12px;
        margin: 0;
    }

    .slots-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
        border: 1px solid var(--ba-border);
        border-radius: 12px;
        overflow: hidden;
        background: #fff;
    }

    .slots-table thead {
        background: rgba(100, 116, 139, 0.08);
    }

    .slots-table th,
    .slots-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        vertical-align: middle;
    }

    .slots-table tbody tr:hover {
        background: rgba(30, 136, 229, 0.05);
    }

    .slot-radio {
        transform: scale(1.25);
        accent-color: var(--ba-primary);
    }

    .book-btn-large {
        display: inline-flex;
        width: 100%;
        align-items: center;
        justify-content: center;
        padding: 14px 16px;
        font-size: 1.05rem;
        font-weight: 800;
        color: white;
        background: linear-gradient(180deg, var(--ba-primary), var(--ba-primary-2));
        border: none;
        border-radius: 12px;
        cursor: pointer;
        margin-top: 14px;
        box-shadow: 0 12px 20px rgba(30, 136, 229, 0.18);
        transition: transform 120ms ease, box-shadow 120ms ease, filter 120ms ease;
    }

    .book-btn-large:hover {
        transform: translateY(-1px);
        box-shadow: 0 16px 26px rgba(30, 136, 229, 0.22);
        filter: brightness(1.02);
    }

    .book-btn-large:active {
        transform: translateY(0);
    }

    .success-banner {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        padding: 14px;
        border-radius: 14px;
        border: 1px solid rgba(16, 185, 129, 0.28);
        background: linear-gradient(180deg, rgba(16, 185, 129, 0.12), rgba(255, 255, 255, 0.9));
        box-shadow: 0 10px 24px rgba(16, 185, 129, 0.12);
        margin-bottom: 14px;
    }

    .success-banner-title {
        font-weight: 900;
        letter-spacing: -0.01em;
        margin: 0 0 4px;
    }

    .success-banner-text {
        margin: 0;
        color: var(--ba-text);
        line-height: 1.45;
    }

    .success-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: flex-end;
        align-items: center;
        margin-top: 10px;
    }

    .btn-secondary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 12px 14px;
        border-radius: 12px;
        border: 1px solid rgba(15, 23, 42, 0.14);
        background: rgba(255, 255, 255, 0.9);
        color: var(--ba-text);
        text-decoration: none;
        font-weight: 800;
        transition: transform 120ms ease, box-shadow 120ms ease;
        box-shadow: 0 10px 18px rgba(111, 139, 206, 0.06);
        white-space: nowrap;
    }

    .btn-secondary:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 22px rgba(15, 23, 42, 0.08);
    }

    @media (max-width: 860px) {
        .booking-container {
            margin: 18px 12px 28px;
            padding: 16px;
        }
    }

    @media (max-width: 520px) {
        .success-banner {
            flex-direction: column;
        }

        .success-actions {
            justify-content: stretch;
        }

        .btn-secondary {
            width: 100%;
        }
    }
</style>

<div class="booking-container">
    <div class="booking-header">
        <div>
            <h2>Book an Appointment</h2>
            <div class="booking-subtitle">Pick a doctor, then choose a suitable available slot.</div>
        </div>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="error-message"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>
    <?php if ($success): ?>
        <div class="success-banner" role="status" aria-live="polite">
            <div>
                <p class="success-banner-title">Booking Confirmed</p>
                <p class="success-banner-text"><?= htmlspecialchars($success) ?></p>
                <div class="success-actions">
                    <a class="btn-secondary" href="view_appointment.php">View My Appointments</a>
                    <a class="btn-secondary" href="patient_dashboard.php">Back to Dashboard</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="booking-grid">
        <div class="panel">
            <div class="panel-title"><span class="badge">Step 1</span> Select Doctor</div>
            <form method="get" action="book_appointment.php" class="form-row">
                <label for="doctor_id_select">Select Doctor</label>
                <select id="doctor_id_select" name="doctor_id" onchange="this.form.submit()" class="booking-select">
                    <option value="">-- choose doctor --</option>
                    <?php foreach ($doctors as $d): ?>
                        <option value="<?= $d['doctor_id'] ?>" <?= ($selectedDoctorId == $d['doctor_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['full_name']) ?> <?= $d['specialization'] ? ' — ' . htmlspecialchars($d['specialization']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($selectedDoctorId > 0): ?>
            <div class="panel">
                <div class="panel-title"><span class="badge">Step 2</span> Available Slots</div>
                <?php if (empty($availableSlots)): ?>
                    <p class="muted-empty">No available slots (for today and future). Contact admin.</p>
                <?php else: ?>
                    <form method="post" action="book_appointment.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="doctor_id" value="<?= htmlspecialchars($selectedDoctorId) ?>">

                        <table class="slots-table">
                            <thead>
                                <tr>
                                    <th>Select</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($availableSlots as $s): ?>
                                    <tr>
                                        <td>
                                            <input class="slot-radio" type="radio" name="availability_id" value="<?= $s['availability_id'] ?>" required>
                                        </td>
                                        <td><?= $s['available_date']->format('Y-m-d') ?></td>
                                        <td><?= $s['available_time']->format('H:i') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <button name="book_submit" type="submit" class="book-btn-large">Confirm Booking</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>