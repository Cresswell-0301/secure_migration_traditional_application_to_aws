<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();

// RBAC: Only doctors can see this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
$conn = getDbConnection();

$doctorId = $_SESSION['doctor_id'];

// Check if this is a detail view (has appointment_id and patient_id)
$appointmentId = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;
$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

if ($appointmentId > 0 && $patientId > 0) {
    // DETAIL VIEW - Show specific patient information
    $pageTitle = 'Patient Details';
    
    // Get appointment details - verify it belongs to this doctor
    $sqlAppointment = "
        SELECT 
            a.appointment_id,
            a.appointment_date,
            a.appointment_time,
            a.status,
            u.user_id,
            u.username,
            u.full_name,
            u.email,
            u.phone_number,
            u.role
        FROM Appointments a
        INNER JOIN Users u ON a.patient_id = u.user_id
        WHERE a.appointment_id = ?
        AND a.doctor_id = ?
    ";
    
    $appointment = fetchOne($conn, $sqlAppointment, [$appointmentId, $doctorId]);
    
    if (!$appointment) {
        $_SESSION['error'] = 'Appointment not found or access denied.';
        header('Location: doctor_dashboard.php');
        exit;
    }
    
    // Get all appointments for this patient with this doctor
    $sqlPatientHistory = "
        SELECT 
            appointment_id,
            appointment_date,
            appointment_time,
            status
        FROM Appointments
        WHERE patient_id = ?
        AND doctor_id = ?
        ORDER BY appointment_date DESC, appointment_time DESC
    ";
    
    $patientHistory = fetchAll($conn, $sqlPatientHistory, [$patientId, $doctorId]);
    
    // Get total appointments count for this patient
    $sqlTotalAppts = "
        SELECT COUNT(*) AS total
        FROM Appointments
        WHERE patient_id = ?
        AND doctor_id = ?
    ";
    $totalAppts = fetchOne($conn, $sqlTotalAppts, [$patientId, $doctorId])['total'] ?? 0;
    
    // Get completed appointments count
    $sqlCompletedAppts = "
        SELECT COUNT(*) AS completed
        FROM Appointments
        WHERE patient_id = ?
        AND doctor_id = ?
        AND status = 'Completed'
    ";
    $completedAppts = fetchOne($conn, $sqlCompletedAppts, [$patientId, $doctorId])['completed'] ?? 0;
    
    // Show detail view
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
            .patient-header {
                background: linear-gradient(135deg, #1e88e5 0%, #1565c0 100%);
                color: white;
                padding: 30px;
                border-radius: 8px;
                margin-bottom: 30px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            }
            .patient-header h2 {
                margin: 0 0 15px 0;
                font-size: 2em;
            }
            .patient-meta {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 15px;
                margin-top: 20px;
            }
            .meta-item {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .meta-icon {
                font-size: 1.5em;
            }
            .meta-content {
                display: flex;
                flex-direction: column;
            }
            .meta-label {
                font-size: 0.85em;
                opacity: 0.9;
            }
            .meta-value {
                font-weight: bold;
                font-size: 1.1em;
            }
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }
            .stat-card {
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                text-align: center;
            }
            .stat-icon {
                font-size: 2.5em;
                margin-bottom: 10px;
            }
            .stat-value {
                font-size: 2em;
                font-weight: bold;
                color: #1e88e5;
                margin: 10px 0;
            }
            .stat-label {
                color: #666;
                font-size: 0.95em;
            }
            .section {
                background: white;
                padding: 25px;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }
            .section-title {
                font-size: 1.5em;
                color: #1e88e5;
                margin: 0 0 20px 0;
                padding-bottom: 10px;
                border-bottom: 2px solid #e0e0e0;
            }
            .info-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 20px;
            }
            .info-item {
                padding: 15px;
                background: #f5f5f5;
                border-radius: 6px;
                border-left: 4px solid #1e88e5;
            }
            .info-label {
                font-weight: bold;
                color: #333;
                margin-bottom: 5px;
            }
            .info-value {
                color: #666;
                font-size: 1.05em;
            }
            .history-table {
                width: 100%;
                border-collapse: collapse;
            }
            .history-table th {
                background: #f5f5f5;
                padding: 12px;
                text-align: left;
                font-weight: bold;
                border-bottom: 2px solid #e0e0e0;
            }
            .history-table td {
                padding: 12px;
                border-bottom: 1px solid #e0e0e0;
            }
            .history-table tbody tr:hover {
                background: #f9f9f9;
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
            .current-appointment {
                background: #e3f2fd;
                border-left: 4px solid #1e88e5;
            }
            .action-buttons {
                display: flex;
                gap: 10px;
                margin-top: 20px;
            }
            .btn {
                padding: 10px 20px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
                font-weight: bold;
            }
            .btn-primary {
                background: #1e88e5;
                color: white;
            }
            .btn-primary:hover {
                background: #1565c0;
            }
        </style>
    </head>
    <body>
        <div class="content-wrapper">
            <a href="view_appointment_list.php" class="back-link">← Back to Appointment List</a>
            
            <!-- Patient Header -->
            <div class="patient-header">
                <h2><?php echo htmlspecialchars($appointment['full_name']); ?></h2>
                <div class="patient-meta">
                    <div class="meta-item">
                        <div class="meta-content">
                            <span class="meta-label">Email</span>
                            <span class="meta-value"><?php echo htmlspecialchars($appointment['email']); ?></span>
                        </div>
                    </div>
                    <?php if (!empty($appointment['phone_number'])): ?>
                    <div class="meta-item">
                        <div class="meta-content">
                            <span class="meta-label">Phone</span>
                            <span class="meta-value"><?php echo htmlspecialchars($appointment['phone_number']); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="meta-item">
                        <div class="meta-content">
                            <span class="meta-label">Patient ID</span>
                            <span class="meta-value">#<?php echo htmlspecialchars($appointment['user_id']); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $totalAppts; ?></div>
                    <div class="stat-label">Total Appointments</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $completedAppts; ?></div>
                    <div class="stat-label">Completed Visits</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">
                        <?php 
                        $apptDate = $appointment['appointment_date'];
                        if ($apptDate instanceof DateTime) {
                            echo $apptDate->format('M d, Y');
                        } else {
                            echo date('M d, Y', strtotime($apptDate));
                        }
                        ?>
                    </div>
                    <div class="stat-label">Current Appointment</div>
                </div>
            </div>

            <!-- Current Appointment Details -->
            <div class="section">
                <h3 class="section-title">Current Appointment Details</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Appointment ID</div>
                        <div class="info-value">#<?php echo htmlspecialchars($appointment['appointment_id']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Date</div>
                        <div class="info-value">
                            <?php 
                            $apptDate = $appointment['appointment_date'];
                            if ($apptDate instanceof DateTime) {
                                echo $apptDate->format('l, F d, Y');
                            } else {
                                echo date('l, F d, Y', strtotime($apptDate));
                            }
                            ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Time</div>
                        <div class="info-value">
                            <?php 
                            $apptTime = $appointment['appointment_time'];
                            if ($apptTime instanceof DateTime) {
                                echo $apptTime->format('h:i A');
                            } else {
                                echo date('h:i A', strtotime($apptTime));
                            }
                            ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <span class="status-badge status-<?php echo strtolower($appointment['status']); ?>">
                                <?php echo htmlspecialchars($appointment['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if ($appointment['status'] === 'Booked'): ?>
                <div class="action-buttons">
                    <a href="doctor_update_appointment.php?id=<?php echo $appointment['appointment_id']; ?>" class="btn btn-primary">
                        Update Appointment Status
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Appointment History -->
            <div class="section">
                <h3 class="section-title">Appointment History with You</h3>
                <?php if (empty($patientHistory)): ?>
                    <p style="text-align: center; color: #999; padding: 20px;">No appointment history found.</p>
                <?php else: ?>
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Appointment ID</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($patientHistory as $history): ?>
                                <?php
                                $histDate = $history['appointment_date'];
                                $histTime = $history['appointment_time'];
                                
                                if ($histDate instanceof DateTime) {
                                    $dateFormatted = $histDate->format('Y-m-d');
                                } else {
                                    $dateFormatted = date('Y-m-d', strtotime($histDate));
                                }
                                
                                if ($histTime instanceof DateTime) {
                                    $timeFormatted = $histTime->format('h:i A');
                                } else {
                                    $timeFormatted = date('h:i A', strtotime($histTime));
                                }
                                
                                $isCurrent = ($history['appointment_id'] == $appointmentId);
                                ?>
                                <tr <?php echo $isCurrent ? 'class="current-appointment"' : ''; ?>>
                                    <td>
                                        #<?php echo htmlspecialchars($history['appointment_id']); ?>
                                        <?php if ($isCurrent): ?>
                                            <strong style="color: #1e88e5;"> (Current)</strong>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $dateFormatted; ?></td>
                                    <td><?php echo $timeFormatted; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($history['status']); ?>">
                                            <?php echo htmlspecialchars($history['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Patient Account Information -->
            <div class="section">
                <h3 class="section-title">Account Information</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?php echo htmlspecialchars($appointment['username']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Account Role</div>
                        <div class="info-value"><?php echo htmlspecialchars($appointment['role']); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// LIST VIEW - Show all patients
$pageTitle = 'My Patients';

// Get filter parameters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build SQL query with filters
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

// Add status filter
if ($statusFilter !== 'all') {
    $sql .= " AND a.status = ?";
    $params[] = $statusFilter;
}

// Add search filter
if ($searchQuery !== '') {
    $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone_number LIKE ?)";
    $searchParam = '%' . $searchQuery . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$sql .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC";

$appointments = fetchAll($conn, $sql, $params);

// Get unique patients count
$sqlPatients = "
    SELECT COUNT(DISTINCT patient_id) AS total_patients
    FROM Appointments
    WHERE doctor_id = ?
";
$totalPatients = fetchOne($conn, $sqlPatients, [$doctorId])['total_patients'] ?? 0;

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

        .stats-badge {
            background: #e3f2fd;
            color: #1565c0;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
        }

        .filter-section {
            background: #f5f5f5;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-group label {
            font-weight: bold;
            color: #333;
        }

        .filter-group select,
        .filter-group input[type="text"] {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }

        .btn-filter {
            padding: 8px 20px;
            background: #1e88e5;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-filter:hover {
            background: #1565c0;
        }

        .btn-reset {
            padding: 8px 20px;
            background: #757575;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-reset:hover {
            background: #616161;
        }

        .patients-table {
            width: 100%;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .patients-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .patients-table thead {
            background: #1e88e5;
            color: white;
        }

        .patients-table th {
            padding: 15px;
            text-align: left;
            font-weight: bold;
        }

        .patients-table td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .patients-table tbody tr:hover {
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

        .appointment-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .appointment-date {
            font-weight: bold;
            color: #333;
        }

        .appointment-time {
            font-size: 0.9em;
            color: #666;
        }

        @media (max-width: 768px) {
            .filter-section {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                flex-direction: column;
                align-items: stretch;
            }

            .patients-table {
                overflow-x: auto;
            }
        }
    </style>
</head>

<body>
    <div class="content-wrapper">
        <div class="page-header">
            <h2>👥 My Patients</h2>
            <div class="stats-badge">
                Total Unique Patients: <?php echo $totalPatients; ?>
            </div>
        </div>

        <!-- Filter Section -->
        <form method="GET" action="doctor_patients.php" class="filter-section">
            <div class="filter-group">
                <label for="status">Status:</label>
                <select name="status" id="status">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="Booked" <?php echo $statusFilter === 'Booked' ? 'selected' : ''; ?>>Booked</option>
                    <option value="Completed" <?php echo $statusFilter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="Cancelled" <?php echo $statusFilter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="search">Search:</label>
                <input 
                    type="text" 
                    name="search" 
                    id="search" 
                    placeholder="Name, email, or phone..."
                    value="<?php echo htmlspecialchars($searchQuery); ?>"
                    style="min-width: 250px;">
            </div>

            <button type="submit" class="btn-filter">Apply Filter</button>
            <a href="doctor_patients.php" class="btn-reset">Reset</a>
        </form>

        <!-- Patients Table -->
        <div class="patients-table">
            <?php if (empty($appointments)): ?>
                <div class="no-data">
                    📋 No patient appointments found.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Appointment ID</th>
                            <th>Patient Information</th>
                            <th>Appointment Date & Time</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($appointments as $appt): ?>
                            <?php
                            // Convert date/time objects if needed
                            $apptDate = $appt['appointment_date'];
                            $apptTime = $appt['appointment_time'];
                            
                            if ($apptDate instanceof DateTime) {
                                $dateFormatted = $apptDate->format('Y-m-d');
                            } else {
                                $dateFormatted = date('Y-m-d', strtotime($apptDate));
                            }
                            
                            if ($apptTime instanceof DateTime) {
                                $timeFormatted = $apptTime->format('H:i');
                            } else {
                                $timeFormatted = date('H:i', strtotime($apptTime));
                            }
                            
                            // Status badge class
                            $statusClass = 'status-' . strtolower($appt['status']);
                            ?>
                            <tr>
                                <td>#<?php echo htmlspecialchars($appt['appointment_id']); ?></td>
                                <td>
                                    <div class="patient-info">
                                        <span class="patient-name">
                                            <?php echo htmlspecialchars($appt['full_name']); ?>
                                        </span>
                                        <span class="patient-contact">
                                            📧 <?php echo htmlspecialchars($appt['email']); ?>
                                        </span>
                                        <?php if (!empty($appt['phone_number'])): ?>
                                            <span class="patient-contact">
                                                📱 <?php echo htmlspecialchars($appt['phone_number']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="appointment-info">
                                        <span class="appointment-date">
                                            📅 <?php echo $dateFormatted; ?>
                                        </span>
                                        <span class="appointment-time">
                                            🕐 <?php echo $timeFormatted; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo htmlspecialchars($appt['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="doctor_view_patient.php?appointment_id=<?php echo $appt['appointment_id']; ?>&patient_id=<?php echo $appt['user_id']; ?>" 
                                       class="btn-view">
                                        View Details
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div style="margin-top: 20px; text-align: center; color: #666;">
            Showing <?php echo count($appointments); ?> appointment(s)
        </div>
    </div>
</body>
</html>
