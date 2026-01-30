<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();
$pageTitle = 'Today\'s Appointments';

// RBAC: Only doctors can see this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
$conn = getDbConnection();

$doctorId = $_SESSION['doctor_id'];

// Get date filter parameter
$dateFilter = isset($_GET['date']) ? $_GET['date'] : 'today';
$customDate = isset($_GET['custom_date']) ? $_GET['custom_date'] : date('Y-m-d');

// Build SQL query based on date filter
$sql = "
    SELECT 
        a.appointment_id,
        a.appointment_date,
        a.appointment_time,
        a.status,
        u.user_id,
        u.full_name,
        u.email,
        u.phone_number
    FROM Appointments a
    INNER JOIN Users u ON a.patient_id = u.user_id
    WHERE a.doctor_id = ?
";

$params = [$doctorId];

// Add date condition based on filter
if ($dateFilter === 'today') {
    $sql .= " AND a.appointment_date = CAST(GETDATE() AS DATE)";
    $displayDate = date('l, F d, Y');
} elseif ($dateFilter === 'tomorrow') {
    $sql .= " AND a.appointment_date = DATEADD(day, 1, CAST(GETDATE() AS DATE))";
    $displayDate = 'Tomorrow - ' . date('l, F d, Y', strtotime('+1 day'));
} elseif ($dateFilter === 'week') {
    $sql .= " AND a.appointment_date >= CAST(GETDATE() AS DATE) AND a.appointment_date <= DATEADD(day, 7, CAST(GETDATE() AS DATE))";
    $displayDate = 'Next 7 Days';
} elseif ($dateFilter === 'all') {
    $sql .= " AND a.appointment_date >= CAST(GETDATE() AS DATE)";
    $displayDate = 'All Upcoming';
} elseif ($dateFilter === 'custom' && !empty($customDate)) {
    $sql .= " AND a.appointment_date = ?";
    $params[] = $customDate;
    $displayDate = date('l, F d, Y', strtotime($customDate));
}

$sql .= " ORDER BY a.appointment_date ASC, a.appointment_time ASC";

$appointments = fetchAll($conn, $sql, $params);

include __DIR__ . '/components/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .content-wrapper {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #1e88e5;
            text-decoration: none;
            font-weight: bold;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #1e88e5;
        }

        .page-header h2 {
            margin: 0;
            color: #1e88e5;
        }

        .date-badge {
            background: #e3f2fd;
            color: #1565c0;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
        }

        .appointments-table {
            width: 100%;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .appointments-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .appointments-table thead {
            background: #1e88e5;
            color: white;
        }

        .appointments-table th {
            padding: 15px;
            text-align: left;
            font-weight: bold;
        }

        .appointments-table td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .appointments-table tbody tr:hover {
            background: #f5f5f5;
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: bold;
            display: inline-block;
        }

        .status-booked {
            background: #fff3e0;
            color: #e65100;
        }

        .status-completed {
            background: #c8e6c9;
            color: #2e7d32;
        }

        .status-cancelled {
            background: #ffcdd2;
            color: #c62828;
        }

        .btn-view {
            padding: 6px 12px;
            background: #1e88e5;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.9em;
            display: inline-block;
        }

        .btn-view:hover {
            background: #1565c0;
        }

        .btn-update {
            padding: 6px 12px;
            background: #4caf50;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.9em;
            display: inline-block;
        }

        .btn-update:hover {
            background: #388e3c;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
            font-size: 1.1em;
        }

        .patient-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .patient-name {
            font-weight: bold;
            color: #333;
        }

        .patient-contact {
            font-size: 0.9em;
            color: #666;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        @media (max-width: 768px) {
            .appointments-table {
                overflow-x: auto;
            }
        }
    </style>
</head>

<body>
    <div class="content-wrapper">
        <a href="doctor_dashboard.php" class="back-link">← Back to Dashboard</a>

        <div class="page-header">
            <h2>Appointments</h2>
            <div class="date-badge">
                <?php echo $displayDate; ?>
            </div>
        </div>

        <!-- Date Filter Section -->
        <div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <form method="GET" action="view_appointment_list.php" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <label style="font-weight: bold; color: #333;">View:</label>
                    <select name="date" id="dateFilter" onchange="toggleCustomDate()" style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px;">
                        <option value="today" <?php echo $dateFilter === 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="tomorrow" <?php echo $dateFilter === 'tomorrow' ? 'selected' : ''; ?>>Tomorrow</option>
                        <option value="week" <?php echo $dateFilter === 'week' ? 'selected' : ''; ?>>Next 7 Days</option>
                        <option value="all" <?php echo $dateFilter === 'all' ? 'selected' : ''; ?>>All Upcoming</option>
                        <option value="custom" <?php echo $dateFilter === 'custom' ? 'selected' : ''; ?>>Custom Date</option>
                    </select>
                </div>

                <div id="customDatePicker" style="display: <?php echo $dateFilter === 'custom' ? 'flex' : 'none'; ?>; align-items: center; gap: 10px;">
                    <input type="date" name="custom_date" value="<?php echo htmlspecialchars($customDate); ?>" style="padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px;">
                </div>

                <button type="submit" style="padding: 8px 20px; background: #1e88e5; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
                    Apply Filter
                </button>

                <a href="view_appointment_list.php" style="padding: 8px 20px; background: #757575; color: white; border: none; border-radius: 4px; text-decoration: none; display: inline-block;">
                    Reset
                </a>
            </form>
        </div>

        <script>
            function toggleCustomDate() {
                const dateFilter = document.getElementById('dateFilter').value;
                const customDatePicker = document.getElementById('customDatePicker');
                customDatePicker.style.display = dateFilter === 'custom' ? 'flex' : 'none';
            }
        </script>

        <!-- Appointments Table -->
        <?php if (empty($appointments)): ?>
            <div class="appointments-table">
                <div class="no-data">
                    No appointments found for the selected date range.
                </div>
            </div>
        <?php else: ?>
            <div class="appointments-table">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Patient Name</th>
                            <th>Contact Information</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($appointments as $appt): ?>
                            <?php
                            $apptDate = $appt['appointment_date'];
                            $apptTime = $appt['appointment_time'];
                            
                            if ($apptDate instanceof DateTime) {
                                $dateFormatted = $apptDate->format('M d, Y');
                            } else {
                                $dateFormatted = date('M d, Y', strtotime($apptDate));
                            }
                            
                            if ($apptTime instanceof DateTime) {
                                $timeFormatted = $apptTime->format('h:i A');
                            } else {
                                $timeFormatted = date('h:i A', strtotime($apptTime));
                            }
                            
                            $statusClass = 'status-' . strtolower($appt['status']);
                            ?>
                            <tr>
                                <td>
                                    <strong style="color: #1e88e5;"><?php echo $dateFormatted; ?></strong>
                                </td>
                                <td>
                                    <strong style="font-size: 1.1em;"><?php echo $timeFormatted; ?></strong>
                                </td>
                                <td>
                                    <div class="patient-info">
                                        <span class="patient-name">
                                            <?php echo htmlspecialchars($appt['full_name']); ?>
                                        </span>
                                        <small style="color: #666;">ID: #<?php echo $appt['user_id']; ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div class="patient-info">
                                        <span class="patient-contact">
                                            <?php echo htmlspecialchars($appt['email']); ?>
                                        </span>
                                        <?php if (!empty($appt['phone_number'])): ?>
                                            <span class="patient-contact">
                                            <?php echo htmlspecialchars($appt['phone_number']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo htmlspecialchars($appt['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="view_patients_info.php?appointment_id=<?php echo $appt['appointment_id']; ?>&patient_id=<?php echo $appt['user_id']; ?>" 
                                           class="btn-view">
                                            View Info
                                        </a>
                                        <?php if ($appt['status'] === 'Booked'): ?>
                                            <a href="doctor_update_appointment.php?id=<?php echo $appt['appointment_id']; ?>" 
                                               class="btn-update">
                                                Update
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 20px; text-align: center; color: #666;">
                Showing <?php echo count($appointments); ?> appointment(s)
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
