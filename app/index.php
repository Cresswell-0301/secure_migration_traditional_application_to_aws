<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();

if (isset($_SESSION['user_id'])) {
    $pageTitle = 'Home';
}

require_once __DIR__ . '/includes/db.php';
$conn = getDbConnection();

include __DIR__ . '/components/header.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <section class="main-section">
        <h2>Welcome to the Clinic Appointment Booking System</h2>
        <p>Manage appointments efficiently with a secure and user-friendly platform.</p>

        <a href="login.php" class="btn-primary">Get Started</a>
    </section>

    <section class="status-box">
        <?php if ($conn): ?>
            <p class="status success">✓ Database Connected Successfully</p>
        <?php else: ?>
            <p class="status error">✗ Failed to Connect to Database</p>
        <?php endif; ?>
    </section>

</body>

</html>