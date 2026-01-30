<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();

$pageTitle = 'Doctor Availability';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'SuperAdmin'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

$conn = getDbConnection();

$where  = [];
$params = [];

$doctorId = (int)($_GET['doctor_id'] ?? 0);
$date     = trim($_GET['date'] ?? '');
$status = $_GET['status'] ?? 'All';

$allowedSort = [
    'date'   => 'da.available_date',
    'time'   => 'da.available_time',
    'doctor' => 'u.full_name',
    'status' => 'da.is_booked'
];

$sortKey = $_GET['sort'] ?? 'date';
$sortCol = $allowedSort[$sortKey] ?? $allowedSort['date'];

$order = strtoupper($_GET['order'] ?? 'DESC');
$order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';

if ($doctorId > 0) {
    $where[]  = "d.doctor_id = ?";
    $params[] = $doctorId;
}

if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $where[]  = "da.available_date = ?";
    $params[] = $date;
}

if ($status === 'Available') {
    $where[] = "da.is_booked = 0";
}

if ($status === 'Booked') {
    $where[] = "da.is_booked = 1";
}

$page     = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 10;
$offset   = ($page - 1) * $pageSize;

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
SELECT
    da.availability_id,
    da.doctor_id,
    da.available_date,
    da.available_time,
    da.is_booked,
    u.full_name AS doctor_name,
    doc.specialization
FROM DoctorAvailability da
JOIN Doctors doc ON da.doctor_id = doc.doctor_id
JOIN Users u     ON doc.user_id = u.user_id
$whereSql
ORDER BY $sortCol $order
OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$rows = fetchAll(
    $conn,
    $sql,
    array_merge($params, [$offset, $pageSize])
);

$sqlCount = "
SELECT COUNT(*) AS total
FROM DoctorAvailability da
JOIN Doctors doc ON da.doctor_id = doc.doctor_id
JOIN Users u     ON doc.user_id = u.user_id
$whereSql
";

$totalRow  = fetchOne($conn, $sqlCount, $params);
$total     = (int)($totalRow['total'] ?? 0);
$totalPages = max(1, (int)ceil($total / $pageSize));

$doctors = fetchAll(
    $conn,
    "
    SELECT d.doctor_id, u.full_name
    FROM Doctors d
    JOIN Users u ON d.user_id = u.user_id
    ORDER BY u.full_name
    "
);

$patients = fetchAll(
    $conn,
    "
    SELECT user_id, full_name, email AS user_email
    FROM Users
    WHERE role = 'Patient' AND is_active = 1
    ORDER BY full_name
    "
);

function arrowIcon($key, $current, $order)
{
    if ($key !== $current) {
        return '<i class="fa-solid fa-angle-down" style="opacity:.4"></i>';
    }
    return $order === 'ASC'
        ? '<i class="fa-solid fa-angle-up"></i>'
        : '<i class="fa-solid fa-angle-down"></i>';
}

if (isset($_POST['book_appointment'])) {

    $availabilityId = (int)$_POST['availability_id'];
    $patientId      = (int)$_POST['patient_id'];
    $doctorId       = (int)$_POST['doctor_id'];
    $date           = $_POST['appointment_date'];
    $time           = $_POST['appointment_time'];

    sqlsrv_begin_transaction($conn);

    try {

        sqlsrv_query(
            $conn,
            "
            INSERT INTO Appointments
                (patient_id, doctor_id, appointment_date, appointment_time, status)
            VALUES (?, ?, ?, ?, 'Booked')
            ",
            [$patientId, $doctorId, $date, $time]
        );

        sqlsrv_query(
            $conn,
            "UPDATE DoctorAvailability SET is_booked = 1 WHERE availability_id = ?",
            [$availabilityId]
        );

        sqlsrv_commit($conn);

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'BOOKING_SUCCESS',
            'Appointments',
            null,
            'Appointment booked successfully: ' . json_encode([
                'availability_id' => $availabilityId,
                'patient_id'      => $patientId,
                'doctor_id'       => $doctorId,
                'appointment_date'=> $date,
                'appointment_time'=> $time
            ])
        );

        header("Location: admin_doctor_availability.php");
        exit;
    } catch (Exception $e) {
        sqlsrv_rollback($conn);

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'BOOKING_FAILED',
            'Appointments',
            null,
            'Appointment booking failed: ' . $e->getMessage()
        );
        $error = "Booking failed.";
    }
}

include __DIR__ . '/components/header.php';
?>

<style>
    .admin-container {
        background: #fff;
        border-radius: 12px;
        padding: 22px;
        border: 1px solid rgba(15, 23, 42, 0.08);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    }

    .admin-container h2 {
        margin: 0 0 16px;
    }

    .filter-bar {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 14px;
        padding: 12px;
        border-radius: 10px;
        background: rgba(100, 116, 139, 0.06);
        border: 1px solid rgba(15, 23, 42, 0.08);
    }

    .filter-bar input[type="date"],
    .filter-bar select {
        height: 38px;
        padding: 0 12px;
        border-radius: 8px;
        border: 1px solid rgba(15, 23, 42, 0.18);
        background: #fff;
        box-sizing: border-box;
    }

    .table-admin {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 10px;
        overflow: hidden;
        background: #fff;
    }

    .table-admin th,
    .table-admin td {
        padding: 12px;
        text-align: center;
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        vertical-align: middle;
    }

    .table-admin th {
        background: #f5f7fa;
        font-weight: 800;
    }

    .table-admin tbody tr:hover {
        background: rgba(30, 136, 229, 0.05);
    }

    .modal-content {
        border-radius: 14px;
        border: 1px solid rgba(15, 23, 42, 0.10);
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.25);
    }

    .modal-content label {
        font-weight: 700;
        font-size: 13px;
        color: #0f172a;
    }

    .view-field {
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid rgba(15, 23, 42, 0.12);
        background: rgba(100, 116, 139, 0.06);
        min-height: 40px;
        display: flex;
        align-items: center;
    }

    @media (max-width: 640px) {
        .admin-container {
            padding: 16px;
        }

        .filter-bar {
            align-items: stretch;
        }

        .filter-bar > * {
            flex: 1 1 100%;
        }
    }
</style>
<div class="content-wrapper">
    <div class="admin-container">

        <h2>Doctor Availability</h2>

        <form method="get" class="filter-bar">
            <select name="doctor_id">
                <option value="0">All Doctors</option>
                <?php foreach ($doctors as $d): ?>
                    <option value="<?= $d['doctor_id'] ?>"
                        <?= $doctorId == $d['doctor_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($d['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">

            <select name="status">
                <?php foreach (['All', 'Available', 'Booked'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>>
                        <?= $s ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button class="btn btn-primary" style="margin-top: 0;">Filter</button>

            <?php if ($doctorId || $date || $status !== 'All'): ?>
                <button type="button" class="btn btn-secondary" style="margin-top: 0;"
                    onclick="window.location='admin_doctor_availability.php'">
                    Clear
                </button>
            <?php endif; ?>
        </form>

        <!-- Book Form -->
        <div id="bookingModal" class="modal">
            <div class="modal-content">
                <h3>Book Appointment</h3>

                <form method="post">
                    <input type="hidden" name="availability_id" id="bm_availability_id">
                    <input type="hidden" name="doctor_id" id="bm_doctor_id">
                    <input type="hidden" name="appointment_date" id="bm_date">
                    <input type="hidden" name="appointment_time" id="bm_time">

                    <div class="schedule-form-grid">
                        <div class="layout">
                            <label>Doctor</label>
                            <div class="view-field" id="bm_doctor_name_view">—</div>
                        </div>

                        <div class="layout">
                            <label>Specialization</label>
                            <div class="view-field" id="bm_specialization_view">—</div>
                        </div>

                        <div class="layout">
                            <label>Date</label>
                            <div class="view-field" id="bm_date_view">—</div>
                        </div>

                        <div class="layout">
                            <label>Time</label>
                            <div class="view-field" id="bm_time_view">—</div>
                        </div>

                        <div class="layout" style="grid-column: span 2;">
                            <label>Patient</label>

                            <input type="text"
                                id="patient_search"
                                list="patient_list"
                                placeholder="Type patient name/email..."
                                autocomplete="off"
                                required
                                style="width: 100%; padding: 8px; box-sizing: border-box;">

                            <datalist id="patient_list">
                                <?php foreach ($patients as $p): ?>
                                    <option
                                        value="<?= htmlspecialchars($p['full_name']) ?>"
                                        data-id="<?= (int)$p['user_id'] ?>">
                                        <?= htmlspecialchars($p['user_email']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </datalist>

                            <input type="hidden" name="patient_id" id="patient_id" required>
                            <small id="patient_hint" style="color:#b71c1c; display:none; margin-top:6px;">
                                Please pick a patient from the list.
                            </small>
                        </div>
                    </div>

                    <div class="btn-row">
                        <button type="submit" name="book_appointment" class="btn btn-primary">
                            Confirm Booking
                        </button>

                        <button type="button" class="btn btn-secondary"
                            onclick="closeModal('bookingModal')">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <table class="table-admin">
            <tr>
                <th>
                    <a href="?<?= http_build_query(array_merge($_GET, [
                                    'sort' => 'date',
                                    'order' => ($sortKey === 'date' && $order === 'ASC') ? 'DESC' : 'ASC'
                                ])) ?>"
                        style="text-decoration: none; color: inherit;">
                        Date <?= arrowIcon('date', $sortKey, $order) ?>
                    </a>
                </th>

                <th>
                    <a href="?<?= http_build_query(array_merge($_GET, [
                                    'sort' => 'time',
                                    'order' => ($sortKey === 'time' && $order === 'ASC') ? 'DESC' : 'ASC'
                                ])) ?>"
                        style="text-decoration: none; color: inherit;">
                        Time <?= arrowIcon('time', $sortKey, $order) ?>
                    </a>
                </th>

                <th>
                    <a href="?<?= http_build_query(array_merge($_GET, [
                                    'sort' => 'doctor',
                                    'order' => ($sortKey === 'doctor' && $order === 'ASC') ? 'DESC' : 'ASC'
                                ])) ?>"
                        style="text-decoration: none; color: inherit;">
                        Doctor <?= arrowIcon('doctor', $sortKey, $order) ?>
                    </a>
                </th>

                <th>Specialization</th>

                <th>
                    <a href="?<?= http_build_query(array_merge($_GET, [
                                    'sort' => 'status',
                                    'order' => ($sortKey === 'status' && $order === 'ASC') ? 'DESC' : 'ASC'
                                ])) ?>"
                        style="text-decoration: none; color: inherit;">
                        Status <?= arrowIcon('status', $sortKey, $order) ?>
                    </a>
                </th>
                <th>Action</th>
            </tr>

            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="5">No availability found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <?php
                            $d = $r['available_date'];
                            echo ($d instanceof DateTime)
                                ? $d->format('Y-m-d')
                                : htmlspecialchars((string)$d);
                            ?>
                        </td>
                        <td>
                            <?php
                            $t = $r['available_time'];
                            echo ($t instanceof DateTime)
                                ? $t->format('H:i')
                                : htmlspecialchars(substr((string)$t, 0, 5));
                            ?>
                        </td>
                        <td><?= htmlspecialchars($r['doctor_name']) ?></td>
                        <td><?= htmlspecialchars($r['specialization'] ?? '-') ?></td>
                        <td>
                            <?php if ($r['is_booked']): ?>
                                <span class="badge badge-Cancelled">Booked</span>
                            <?php else: ?>
                                <span class="badge badge-Completed">Available</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$r['is_booked']): ?>
                                <button class="btn btn-primary" style="margin-top: 0;"
                                    onclick='openBookingModal(<?= json_encode([
                                                                    "availability_id" => $r["availability_id"],
                                                                    "doctor_id" => $r["doctor_id"],
                                                                    "doctor_name"     => $r["doctor_name"],
                                                                    "specialization"  => $r["specialization"],
                                                                    "date"            => ($r["available_date"] instanceof DateTime)
                                                                        ? $r["available_date"]->format("Y-m-d")
                                                                        : $r["available_date"],
                                                                    "time"            => ($r["available_time"] instanceof DateTime)
                                                                        ? $r["available_time"]->format("H:i")
                                                                        : substr((string)$r["available_time"], 0, 5)
                                                                ]) ?>)'>
                                    Book
                                </button>

                            <?php else: ?>
                                <span style="opacity: 0.6;">N/A</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
                        class="<?= $i === $page ? 'active' : '' ?>"
                        style=" 
                        margin: 0 5px; 
                        padding: 6px 12px; 
                        border-radius: 4px; 
                        text-decoration: none;">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="assets/js/modal.js">
    document.addEventListener("submit", function(e) {
        if (e.target.closest("#bookingModal form")) {
            const pid = document.getElementById("patient_id");
            if (!pid || !pid.value) {
                e.preventDefault();
                alert("Please select a patient from the list.");
            }
        }
    });

    document.getElementById("patient_search").addEventListener("input", function() {
        const input = this.value;
        const list = document.getElementById("patient_list");
        const hidden = document.getElementById("patient_id");

        hidden.value = ""; // reset

        for (const option of list.options) {
            if (option.value === input) {
                hidden.value = option.dataset.id;
                break;
            }
        }
    });
</script>