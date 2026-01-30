<?php
$userRole = $_SESSION['role'] ?? null;
$baseTitle = 'Clinic Appointment System';

$fullTitle = isset($pageTitle) && $pageTitle !== ''
    ? $pageTitle . ' | ' . $baseTitle
    : $baseTitle;

$timeout = 1800;    // 30 minutes

if (
    isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > $timeout
) {

    session_unset();
    session_destroy();
    header("Location: login.php?timeout=1");
    exit;
}

$_SESSION['last_activity'] = time();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($fullTitle); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>

<body class="<?php echo ($userRole === 'Admin' || $userRole === 'SuperAdmin') ? 'admin-layout' : ''; ?>">
    <header class="navbar">
        <h1>CAS</h1>

        <nav>
            <!-- Home / Dashboard -->
            <?php if ($userRole !== 'Admin' && $userRole !== 'SuperAdmin'): ?>
                <?php if (strpos($_SERVER['PHP_SELF'], 'index.php') === false && strpos($_SERVER['PHP_SELF'], 'patient_dashboard.php') === false && strpos($_SERVER['PHP_SELF'], 'doctor_dashboard.php') === false): ?>
                    <a href=<?php
                            echo isset($_SESSION['user_id']) ?
                                ($_SESSION['role'] === 'Patient' ? 'patient_dashboard.php' : ($_SESSION['role'] === 'Doctor' ? 'doctor_dashboard.php' : "")) :
                                'index.php';
                            ?>>
                        <?php echo isset($_SESSION['user_id']) ? 'Dashboard' : 'Home'; ?>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Login & Register -->
            <?php if (!$userRole): ?>
                <?php if (strpos($_SERVER['PHP_SELF'], 'login.php') === false): ?>
                    <a href="login.php">Login</a>
                <?php endif; ?>
                <?php if (strpos($_SERVER['PHP_SELF'], 'register.php') === false): ?>
                    <a href="register.php">Register</a>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Patient -->
            <?php if ($userRole === 'Patient'): ?>
                <a href="view_appointment.php">My Appointments</a>
            <?php endif; ?>

            <!-- Doctor -->
            <?php if ($userRole === 'Doctor'): ?>
                <?php if (!strpos($_SERVER['PHP_SELF'], 'view_appointment_list.php')): ?>
                    <a href="view_appointment_list.php">Appointments</a>
                <?php endif; ?>

                <?php if (!strpos($_SERVER['PHP_SELF'], 'doctor_schedule.php')): ?>
                    <a href="doctor_schedule.php">
                        Scheduled
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Admin -->
            <?php if ($userRole === 'Admin' || $userRole === 'SuperAdmin'): ?>
                <div class="admin-menu">
                    <a href="admin_dashboard.php" style="text-decoration: none;"
                        class="<?php echo basename($_SERVER['PHP_SELF']) === 'admin_dashboard.php' ? 'active' : ''; ?>">
                        Dashboard
                    </a>

                    <a href="admin_users.php" style="text-decoration: none;"
                        class="<?php echo basename($_SERVER['PHP_SELF']) === 'admin_users.php' ? 'active' : ''; ?>">
                        Users Management
                    </a>

                    <?php if ($userRole === 'SuperAdmin'): ?>
                        <a href="admin_management.php" style="text-decoration: none;"
                            class="<?php echo basename($_SERVER['PHP_SELF']) === 'admin_management.php' ? 'active' : ''; ?>">
                            Admin Management
                        </a>
                    <?php endif; ?>

                    <a href="admin_doctor_availability.php" style="text-decoration: none;"
                        class="<?php echo basename($_SERVER['PHP_SELF']) === 'admin_doctor_availability.php' ? 'active' : ''; ?>">
                        Doctor Availability
                    </a>

                    <a href="admin_appointments.php" style="text-decoration: none;"
                        class="<?php echo basename($_SERVER['PHP_SELF']) === 'admin_appointments.php' ? 'active' : ''; ?>">
                        All Appointments
                    </a>

                    <?php if ($userRole === 'SuperAdmin'): ?>
                        <a href="admin_audit_logs.php" style="text-decoration: none;"
                            class="<?php echo basename($_SERVER['PHP_SELF']) === 'admin_audit_logs.php' ? 'active' : ''; ?>">
                            Audit Logs
                        </a>

                        <a href="admin_db_maintenance.php" style="text-decoration: none;"
                            class="<?php echo basename($_SERVER['PHP_SELF']) === 'admin_db_maintenance.php' ? 'active' : ''; ?>">
                            DB Maintenance
                        </a>
                    <?php endif; ?>
                </div>

                <div class="admin-logout">
                    <a href="logout.php" style="text-decoration: none;">Logout</a>
                </div>
            <?php endif; ?>

            <!-- Logout -->
            <?php if ($userRole && $userRole !== 'Admin' && $userRole !== 'SuperAdmin'): ?>
                <a href="logout.php" style="text-decoration: none;">Logout</a>
            <?php endif; ?>
        </nav>
    </header>