<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();
$pageTitle = 'Doctor Schedule';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

$conn = getDbConnection();

$doctorId = $_SESSION['doctor_id'];

$message = "";
$error = "";

// Sort
$sortColumn = $_GET['sort'] ?? 'available_date';
$sortOrder  = $_GET['order'] ?? 'asc';

$allowedColumns = ['available_date', 'available_time', 'is_booked'];
if (!in_array($sortColumn, $allowedColumns, true)) {
    $sortColumn = 'available_date';
}

$sortOrder = strtolower($sortOrder) === 'desc' ? 'DESC' : 'ASC';

$orderExtra = '';

if ($sortColumn === 'available_date') {
    $orderExtra = ', available_time ASC';
} elseif ($sortColumn === 'is_booked') {
    $orderExtra = ', available_date ASC, available_time ASC';
}

function generateTimeSlots($start, $end, $durationMinutes)
{
    $slots = [];
    $current = strtotime($start);
    $endTime = strtotime($end);

    while ($current < $endTime) {
        $slots[] = date("H:i", $current);
        $current = strtotime("+$durationMinutes minutes", $current);
    }

    return $slots;
}

$keepModalOpen = false;

$old = [
    'start_date' => $_POST['start_date'] ?? '',
    'end_date' => $_POST['end_date'] ?? '',
    'days' => $_POST['days'] ?? [],
    'start_time' => $_POST['start_time'] ?? '',
    'end_time' => $_POST['end_time'] ?? '',
    'duration' => $_POST['duration'] ?? ''
];

if (isset($_GET['reset'])) {
    $old = [
        'start_date' => '',
        'end_date' => '',
        'days' => [],
        'start_time' => '',
        'end_time' => '',
        'duration' => ''
    ];
    $error = "";
}

if (isset($_POST['add_availability'])) {

    $startDate = $_POST['start_date'];
    $endDate   = $_POST['end_date'];
    $days      = $_POST['days'] ?? [];
    $startTime = $_POST['start_time'];
    $endTime   = $_POST['end_time'];
    $duration  = (int)$_POST['duration'];

    $keepModalOpen = true;

    $actualInterval = (DateTime::createFromFormat('H:i', $startTime))->diff(DateTime::createFromFormat('H:i', $endTime));
    $actual_duration_in_minutes =
        ($actualInterval->h * 60) +
        ($actualInterval->i);

    if (empty($days)) {
        $error = "Please select at least one day.";
    } elseif ($startDate > $endDate) {
        $error = "Start date cannot be later than end date.";
    } elseif ($startTime >= $endTime) {
        $error = "Start time must be earlier than end time.";
    } elseif ($startDate == $endDate && $startTime >= $endTime) {
        $error = "On the same day, start time must be earlier than end time.";
    } elseif ($actual_duration_in_minutes < $duration) {
        $error = "The time range is shorter than the slot duration.";
    } else {
        $slots = generateTimeSlots($startTime, $endTime, $duration);

        $date = $startDate;
        $createdCount = 0;

        while ($date <= $endDate) {

            $weekday = date('w', strtotime($date)); // 0–6

            if (in_array($weekday, $days)) {

                foreach ($slots as $time) {

                    // Check duplicates
                    $exists = fetchOne(
                        $conn,
                        "SELECT 1 FROM DoctorAvailability 
                        WHERE doctor_id = ? AND available_date = ? AND available_time = ?",
                        [$doctorId, $date, $time]
                    );

                    if ($exists) {
                        $error = "Slot on $date at $time already exists.";

                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'ADD_AVAILABILITY_FAILED',
                            'DoctorAvailability',
                            null,
                            "Attempted to add duplicate slot on $date at $time."
                        );
                        continue;
                    }

                    // Insert new slot
                    sqlsrv_query(
                        $conn,
                        "INSERT INTO DoctorAvailability (doctor_id, available_date, available_time) 
                        VALUES (?, ?, ?)",
                        [$doctorId, $date, $time]
                    );

                    auditLog(
                        $conn,
                        $_SESSION['user_id'],
                        $_SESSION['role'],
                        'ADD_AVAILABILITY_SUCCESS',
                        'DoctorAvailability',
                        null,
                        "Added availability slot on $date at $time."
                    );

                    $createdCount++;
                }
            }

            $date = date('Y-m-d', strtotime($date . ' +1 day'));
        }

        if (!empty($error)) {
            $keepModalOpen = true;

            auditLog(
                $conn,
                $_SESSION['user_id'],
                $_SESSION['role'],
                'ADD_AVAILABILITY_PARTIAL_SUCCESS',
                'DoctorAvailability',
                null,
                "Created $createdCount slots with some errors: $error"
            );
        } else {
            $old = [
                'start_date' => '',
                'end_date' => '',
                'days' => [],
                'start_time' => '',
                'end_time' => '',
                'duration' => ''
            ];
            $message = "$createdCount availability slots created.";
            $keepModalOpen = false;
        }
    }
}

if (isset($_GET['delete'])) {

    $availabilityId = (int)$_GET['delete'];

    // Check if booked
    $checkBooked = "
        SELECT is_booked FROM DoctorAvailability
        WHERE availability_id = ? AND doctor_id = ?
    ";
    $slot = fetchAll($conn, $checkBooked, [$availabilityId, $doctorId]);

    if (!$slot) {
        $error = "Invalid availability ID.";

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'DELETE_AVAILABILITY_FAILED',
            'DoctorAvailability',
            $availabilityId,
            'Attempted to delete non-existent availability ID: ' . $availabilityId
        );
    } elseif ($slot[0]['is_booked'] == 1) {
        $error = "You cannot delete a booked slot.";

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'DELETE_AVAILABILITY_FAILED',
            'DoctorAvailability',
            $availabilityId,
            'Attempted to delete booked availability ID: ' . $availabilityId
        );
    } else {
        $deleteSql = "
            DELETE FROM DoctorAvailability
            WHERE availability_id = ? AND doctor_id = ?
        ";
        sqlsrv_query($conn, $deleteSql, [$availabilityId, $doctorId]);

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'DELETE_AVAILABILITY_SUCCESS',
            'DoctorAvailability',
            $availabilityId,
            'Deleted availability ID: ' . $availabilityId
        );

        $message = "Availability deleted successfully.";

        header("Refresh:1; url=doctor_schedule.php");
    }
}

$sqlAvailability = "
    SELECT availability_id, available_date, available_time, is_booked
    FROM DoctorAvailability
    WHERE doctor_id = ?
    AND (
        available_date > CAST(GETDATE() AS DATE)
        OR (
            available_date = CAST(GETDATE() AS DATE)
            AND available_time >= CAST(GETDATE() AS TIME)
        )
    )
    ORDER BY $sortColumn $sortOrder $orderExtra
";

$availabilityList = fetchAll($conn, $sqlAvailability, [$doctorId]);

include __DIR__ . '/components/header.php';
?>

<style>
    :root {
        --ds-bg: #f4f7fb;
        --ds-card: #ffffff;
        --ds-text: #0f172a;
        --ds-muted: #64748b;
        --ds-border: rgba(15, 23, 42, 0.10);
        --ds-primary: #1e88e5;
        --ds-primary-2: #1565c0;
        --ds-danger: #e53935;
        --ds-success: #10b981;
        --ds-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
        --ds-radius: 14px;
    }

    body {
        margin: 0;
        background: radial-gradient(1200px 600px at 10% 0%, rgba(30, 136, 229, 0.12), transparent 60%),
            radial-gradient(900px 500px at 100% 10%, rgba(99, 102, 241, 0.10), transparent 55%),
            var(--ds-bg);
        color: var(--ds-text);
    }

    .modal-content,
    .modal-content * {
        box-sizing: border-box;
    }

    .doctor-schedule {
        max-width: 1100px;
        margin: 26px auto 40px;
        padding: 22px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.92), rgba(255, 255, 255, 0.98));
        border-radius: var(--ds-radius);
        box-shadow: var(--ds-shadow);
        border: 1px solid var(--ds-border);
    }

    .ds-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        padding: 4px 4px 14px;
        border-bottom: 1px solid var(--ds-border);
        margin-bottom: 16px;
    }

    .ds-header h2 {
        margin: 0;
        font-size: 22px;
        font-weight: 900;
        letter-spacing: -0.02em;
    }

    .ds-subtitle {
        margin-top: 6px;
        color: var(--ds-muted);
        font-size: 14px;
        line-height: 1.4;
    }

    .ds-actions {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .ds-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 12px 14px;
        border-radius: 12px;
        font-weight: 900;
        text-decoration: none;
        border: 1px solid transparent;
        cursor: pointer;
        transition: transform 120ms ease, box-shadow 120ms ease, filter 120ms ease;
        white-space: nowrap;
    }

    .ds-btn-primary {
        background: linear-gradient(180deg, var(--ds-primary), var(--ds-primary-2));
        color: #fff;
        box-shadow: 0 12px 20px rgba(30, 136, 229, 0.18);
    }

    .ds-btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 16px 26px rgba(30, 136, 229, 0.22);
        filter: brightness(1.02);
    }

    .ds-btn-secondary {
        background: rgba(255, 255, 255, 0.9);
        border-color: rgba(15, 23, 42, 0.14);
        box-shadow: 0 10px 18px rgba(15, 23, 42, 0.06);
    }

    .ds-btn-secondary:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 22px rgba(15, 23, 42, 0.08);
    }

    .modal {
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(6px);
    }

    .modal-content {
        width: min(760px, calc(100% - 28px));
        border-radius: 16px;
        border: 1px solid var(--ds-border);
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.25);
        padding: 18px;
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(255, 255, 255, 0.99));
    }

    .modal-content h3 {
        margin: 0 0 12px;
        font-size: 18px;
        font-weight: 900;
        letter-spacing: -0.01em;
    }

    .schedule-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .schedule-form-grid > div {
        min-width: 0;
    }

    .schedule-form-grid label {
        display: block;
        font-weight: 800;
        color: var(--ds-text);
        margin-bottom: 6px;
        font-size: 13px;
    }

    .schedule-form-grid input[type="date"],
    .schedule-form-grid input[type="time"],
    .schedule-form-grid select {
        width: 100%;
        padding: 11px 12px;
        border-radius: 12px;
        border: 1px solid var(--ds-border);
        background: #fff;
        outline: none;
    }

    .schedule-form-grid input[type="date"]:focus,
    .schedule-form-grid input[type="time"]:focus,
    .schedule-form-grid select:focus {
        border-color: rgba(30, 136, 229, 0.55);
        box-shadow: 0 0 0 4px rgba(30, 136, 229, 0.12);
    }

    .full-width {
        grid-column: 1 / -1;
    }

    .day-checkbox-group {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
        padding: 10px;
        background: rgba(100, 116, 139, 0.06);
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 12px;
    }

    .day-checkbox-group label {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 10px;
        border-radius: 999px;
        border: 1px solid rgba(15, 23, 42, 0.10);
        background: rgba(255, 255, 255, 0.85);
        margin: 0;
        font-weight: 800;
        cursor: pointer;
        user-select: none;
        justify-content: center;
        width: 100%;
    }

    .day-checkbox-group input[type="checkbox"] {
        transform: scale(1.1);
        accent-color: var(--ds-primary);
    }

    .btn-row {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-top: 14px;
        flex-wrap: wrap;
    }

    .btn-row .ds-btn {
        flex: 1 1 220px;
        height: 46px;
    }

    #modalError {
        border-radius: 12px;
        padding: 10px 12px;
        border: 1px solid rgba(229, 57, 53, 0.28);
        background: rgba(229, 57, 53, 0.08);
        color: #7f1d1d;
    }

    @media (max-width: 640px) {
        .schedule-form-grid {
            grid-template-columns: 1fr;
        }

        .day-checkbox-group {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .btn-row {
            justify-content: stretch;
        }

        .btn-row .ds-btn {
            width: 100%;
        }
    }

    .ds-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid var(--ds-border);
        border-radius: 12px;
        overflow: hidden;
        background: #fff;
    }

    .ds-table thead {
        background: rgba(100, 116, 139, 0.08);
    }

    .ds-table th,
    .ds-table td {
        padding: 12px;
        text-align: center;
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        vertical-align: middle;
    }

    .ds-table tbody tr:hover {
        background: rgba(30, 136, 229, 0.05);
    }

    .ds-sort-link {
        color: var(--ds-text);
        text-decoration: none;
        font-weight: 900;
    }

    .ds-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 900;
        letter-spacing: 0.01em;
        border: 1px solid transparent;
    }

    .ds-badge-available {
        color: #065f46;
        background: rgba(16, 185, 129, 0.12);
        border-color: rgba(16, 185, 129, 0.25);
    }

    .ds-badge-booked {
        color: #7c2d12;
        background: rgba(245, 158, 11, 0.12);
        border-color: rgba(245, 158, 11, 0.25);
    }

    .ds-btn-danger {
        background: var(--ds-danger);
        color: #fff;
        border-color: rgba(0, 0, 0, 0.06);
    }

    .ds-btn-danger:hover {
        filter: brightness(0.98);
        transform: translateY(-1px);
    }

    .ds-btn-disabled {
        background: #e5e7eb;
        color: #6b7280;
        cursor: not-allowed;
        border-color: rgba(15, 23, 42, 0.10);
    }

    @media (max-width: 860px) {
        .doctor-schedule {
            margin: 18px 12px 28px;
            padding: 16px;
        }

        .ds-header {
            flex-direction: column;
        }
    }
</style>

<div class="content-wrapper">

    <div class="admin-dashboard doctor-schedule">
        <div class="ds-header">
            <div>
                <h2 class="welcome-text">My Availability Schedule</h2>
                <div class="ds-subtitle">Create and manage your upcoming availability slots. Booked slots are locked.</div>
            </div>
            <div class="ds-actions">
                <button class="ds-btn ds-btn-primary" type="button" onclick="openModal('scheduleModal')">Add Schedule</button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="success-message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error && !$keepModalOpen): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>


        <!-- Add availability -->
        <div id="scheduleModal" class="modal">
            <div class="modal-content">

                <h3>Add Availability</h3>

                <form method="post" action="">
                    <div class="schedule-form-grid">

                        <!-- Row 1: Dates -->
                        <div>
                            <label>Start Date</label>
                            <input type="date" name="start_date"
                                value="<?php echo htmlspecialchars($old['start_date']); ?>" required>
                        </div>

                        <div>
                            <label>End Date</label>
                            <input type="date" name="end_date"
                                value="<?php echo htmlspecialchars($old['end_date']); ?>" required>
                        </div>

                        <!-- Row 2: Days -->
                        <div class="full-width">
                            <label>Select Days</label>
                            <div class="day-checkbox-group">
                                <label>
                                    <input type="checkbox" name="days[]" value="1"
                                        <?php echo in_array("1", $old['days']) ? "checked" : ""; ?>>
                                    Monday
                                </label>

                                <label>
                                    <input type="checkbox" name="days[]" value="2"
                                        <?php echo in_array("2", $old['days']) ? "checked" : ""; ?>>
                                    Tuesday
                                </label>

                                <label>
                                    <input type="checkbox" name="days[]" value="3"
                                        <?php echo in_array("3", $old['days']) ? "checked" : ""; ?>>
                                    Wednesday
                                </label>

                                <label>
                                    <input type="checkbox" name="days[]" value="4"
                                        <?php echo in_array("4", $old['days']) ? "checked" : ""; ?>>
                                    Thursday
                                </label>

                                <label>
                                    <input type="checkbox" name="days[]" value="5"
                                        <?php echo in_array("5", $old['days']) ? "checked" : ""; ?>>
                                    Friday
                                </label>

                                <label>
                                    <input type="checkbox" name="days[]" value="6"
                                        <?php echo in_array("6", $old['days']) ? "checked" : ""; ?>>
                                    Saturday
                                </label>

                                <label>
                                    <input type="checkbox" name="days[]" value="0"
                                        <?php echo in_array("0", $old['days']) ? "checked" : ""; ?>>
                                    Sunday
                                </label>
                            </div>
                        </div>

                        <!-- Row 3: Time Range -->
                        <div>
                            <label>Start Time</label>
                            <input type="time" name="start_time"
                                value="<?php echo htmlspecialchars($old['start_time']); ?>" required>
                        </div>

                        <div>
                            <label>End Time</label>
                            <input type="time" name="end_time"
                                value="<?php echo htmlspecialchars($old['end_time']); ?>" required>
                        </div>

                        <!-- Row 4: Duration -->
                        <div class="full-width">
                            <label>Slot Duration (minutes)</label>
                            <select name="duration" required style="width: 100%;">
                                <option value="30" <?php echo $old['duration'] == "30" ? "selected" : ""; ?>>30 minutes</option>
                                <option value="60" <?php echo $old['duration'] == "60" ? "selected" : ""; ?>>60 minutes</option>
                            </select>
                        </div>
                    </div>

                    <div id="modalError" class="error-message" style="display:none; color:red; margin-top:10px;"></div>

                    <!-- Buttons -->
                    <div class="btn-row">
                        <button class="ds-btn ds-btn-primary" id="generateBtn" type="submit" name="add_availability">
                            Generate Availability
                        </button>

                        <button type="button" class="ds-btn ds-btn-secondary" onclick="closeModal('scheduleModal')">
                            Close
                        </button>
                    </div>
                </form>

            </div>
        </div>

        <!-- Availability List -->
        <div class="ds-panel" style="margin-top:16px;">
            <div class="ds-panel-title">Upcoming Availability</div>

            <?php
            $dateArrow = $timeArrow = $statusArrow = '<i class="fa-solid fa-angle-down"></i>';

            if ($sortColumn === 'available_date') {
                $dateArrow = ($sortOrder === 'ASC')
                    ? '<i class="fa-solid fa-angle-up"></i>'
                    : '<i class="fa-solid fa-angle-down"></i>';
            }

            if ($sortColumn === 'available_time') {
                $timeArrow = ($sortOrder === 'ASC')
                    ? '<i class="fa-solid fa-angle-up"></i>'
                    : '<i class="fa-solid fa-angle-down"></i>';
            }

            if ($sortColumn === 'is_booked') {
                $statusArrow = ($sortOrder === 'ASC')
                    ? '<i class="fa-solid fa-angle-up"></i>'
                    : '<i class="fa-solid fa-angle-down"></i>';
            }
            ?>

            <table class="ds-table">
                <thead>
                    <tr>
                        <th>
                            <a href="?sort=available_date&order=<?php echo ($sortColumn == 'available_date' && $sortOrder == 'ASC') ? 'desc' : 'asc'; ?>"
                                class="ds-sort-link">
                                Date <?php echo $dateArrow; ?>
                            </a>
                        </th>

                        <th>
                            <a href="?sort=available_time&order=<?php echo ($sortColumn == 'available_time' && $sortOrder == 'ASC') ? 'desc' : 'asc'; ?>"
                                class="ds-sort-link">
                                Time <?php echo $timeArrow; ?>
                            </a>
                        </th>

                        <th>
                            <a href="?sort=is_booked&order=<?php echo ($sortColumn == 'is_booked' && $sortOrder == 'ASC') ? 'desc' : 'asc'; ?>"
                                class="ds-sort-link">
                                Status <?php echo $statusArrow; ?>
                            </a>
                        </th>

                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($availabilityList)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center;">No availability slots added yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($availabilityList as $slot): ?>
                            <tr>
                                <td><?php echo $slot['available_date']->format('Y-m-d'); ?></td>
                                <td><?php echo $slot['available_time']->format('H:i'); ?></td>
                                <td>
                                    <?php if ($slot['is_booked']): ?>
                                        <span class="ds-badge ds-badge-booked">Booked</span>
                                    <?php else: ?>
                                        <span class="ds-badge ds-badge-available">Available</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$slot['is_booked']): ?>
                                        <a href="?delete=<?php echo $slot['availability_id']; ?>" class="ds-btn ds-btn-danger">Delete</a>
                                    <?php else: ?>
                                        <span class="ds-btn ds-btn-disabled">Locked</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>

                </tbody>
            </table>

        </div>

    </div>
</div>

<script src="assets/js/modal.js" defer>
    <?php if ($keepModalOpen): ?>
        document.addEventListener("DOMContentLoaded", function() {
            openScheduleModal();
            const err = document.getElementById("modalError");
            if (err) {
                err.style.display = "block";
                err.innerText = "<?php echo addslashes($error); ?>";
            }
        });
    <?php endif; ?>
</script>

</body>

</html>