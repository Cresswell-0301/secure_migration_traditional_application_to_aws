<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();

$pageTitle = 'Login';

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

$conn = getDbConnection();

$error = '';
$success = '';

if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];

    auditLog(
        $conn,
        null,
        'Guest',
        'LOGIN_FAILED',
        'Users',
        null,
        'Failed login attempt: ' . $error
    );

    unset($_SESSION['login_error']);
}

if (isset($_SESSION['registration_success'])) {
    $success = $_SESSION['registration_success'];

    auditLog(
        $conn,
        null,
        'Guest',
        'REGISTER',
        'Users',
        null,
        'New user registered successfully'
    );

    unset($_SESSION['registration_success']);
}

if (isset($_POST['login_submit']) && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($username === '' || $password === '') {
        $_SESSION['login_error'] = 'Please enter both username and password.';

        auditLog(
            $conn,
            null,
            'Guest',
            'LOGIN_FAILED',
            'Users',
            null,
            'Failed login attempt: Missing username or password'
        );

        header('Location: login.php');
        exit;
    } else {
        // Only count failed attempts within the last 10 minutes
        $sql = "SELECT COUNT(*) AS attempts
        FROM LoginAttempts WHERE username = ? AND attempt_time > DATEADD(MINUTE, -10, GETDATE())";

        $stmt = sqlsrv_query($conn, $sql, [$username]);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

        if ($row && $row['attempts'] >= 5) {
            auditLog(
                $conn,
                null,
                'Guest',
                'LOGIN_LOCKOUT',
                'Users',
                null,
                'Account locked due to too many failed login attempts'
            );

            $_SESSION['login_error'] = 'Too many login attempts. Please try again later.';
            header('Location: login.php');
            exit;
        }

        $sql = "SELECT user_id, username, full_name, email, phone_number, password_hash, role, is_active FROM Users WHERE username = ?";

        $stmt = sqlsrv_prepare($conn, $sql, [$username]);

        if (!$stmt || !sqlsrv_execute($stmt)) {
            $_SESSION['login_error'] = 'System error. Please try again later.';
            header('Location: login.php');
            exit;
        }

        $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {

            sqlsrv_query(
                $conn,
                "DELETE FROM LoginAttempts WHERE username = ?",
                [$username]
            );

            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['phone_number'] = $user['phone_number'];
            $_SESSION['role'] = $user['role'];
        } else {

            sqlsrv_query(
                $conn,
                "INSERT INTO LoginAttempts (username) VALUES (?)",
                [$username]
            );

            auditLog(
                $conn,
                null,
                'Guest',
                'LOGIN_FAILED',
                'Users',
                null,
                'Failed login attempt: Invalid credentials'
            );

            $_SESSION['login_error'] = 'Invalid username or password.';
            header('Location: login.php');
            exit;
        }

        if ($stmt === false) {
            $_SESSION['login_error'] = 'An internal error occurred. Please try again later.';

            auditLog(
                $conn,
                null,
                'Guest',
                'LOGIN_FAILED',
                'Users',
                null,
                'Failed login attempt: SQL prepare error'
            );

            header("Location: login.php");
            exit;
        } else {
            if (!sqlsrv_execute($stmt)) {
                $_SESSION['login_error'] = 'An internal error occurred. Please try again later.';

                auditLog(
                    $conn,
                    null,
                    'Guest',
                    'LOGIN_FAILED',
                    'Users',
                    null,
                    'Failed login attempt: SQL execute error'
                );

                header("Location: login.php");
                exit;
            } else {
                $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

                if ($row) {
                    session_regenerate_id(true);

                    $checkActiveSql = "
                        SELECT is_active FROM Users
                        WHERE user_id = ?
                    ";
                    $activeRow = fetchAll($conn, $checkActiveSql, [$row['user_id']])[0] ?? [];

                    if (!$activeRow['is_active']) {
                        if ($row['role'] === 'Admin') {
                            $affectedUserRole = 'System Administrator';
                        } elseif ($row['role'] === 'Patient' || $row['role'] === 'Doctor') {
                            $affectedUserRole = 'Administrator';
                        }

                        $_SESSION['login_error'] = "Your account is inactive. Please contact the $affectedUserRole.";

                        auditLog(
                            $conn,
                            $row['user_id'],
                            $row['role'],
                            'LOGIN_FAILED',
                            'Users',
                            $row['user_id'],
                            'Attempted login to inactive account'
                        );

                        header('Location: login.php');
                        exit;
                    }

                    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                        $newHash = password_hash($password, PASSWORD_DEFAULT);

                        $updateSql = "UPDATE Users SET password_hash = ? WHERE user_id = ?";
                        sqlsrv_query($conn, $updateSql, [$newHash, $user['user_id']]);
                    }

                    session_regenerate_id(true);

                    $_SESSION['user_id']    = $row['user_id'];
                    $_SESSION['full_name']  = $row['full_name'];
                    $_SESSION['email']      = $row['email'];
                    $_SESSION['phone_number'] = $row['phone_number'];
                    $_SESSION['role']       = $row['role'];
                    $_SESSION['last_activity'] = time();

                    auditLog(
                        $conn,
                        $row['user_id'],
                        $row['role'],
                        'LOGIN',
                        'Users',
                        $row['user_id'],
                        'User login successful' . json_encode([
                            'username' => $row['username'],
                            'role'     => $row['role']
                        ])
                    );

                    switch ($row['role']) {
                        case 'Patient':
                            header('Location: patient_dashboard.php');
                            exit;

                        case 'Doctor':
                            $sqlDoctor = "
                                SELECT doctor_id FROM Doctors
                                WHERE user_id = ?
                            ";
                            $doctorRow = fetchAll($conn, $sqlDoctor, [$_SESSION['user_id']])[0] ?? [];

                            $_SESSION['doctor_id'] = $doctorRow['doctor_id'];

                            header('Location: doctor_dashboard.php');
                            exit;

                        case 'Admin':
                        case 'SuperAdmin':
                            header('Location: admin_dashboard.php');
                            exit;

                        default:
                            auditLog(
                                $conn,
                                $row['user_id'],
                                $row['role'],
                                'LOGIN_FAILED',
                                'Users',
                                $row['user_id'],
                                'Login failed due to unrecognized role'
                            );

                            $error = 'Your account role is not recognized. Please contact the administrator.';
                    }
                } else {
                    $_SESSION['login_error'] = 'Invalid username or password.';

                    auditLog(
                        $conn,
                        null,
                        'Guest',
                        'LOGIN_FAILED',
                        'Users',
                        null,
                        'Failed login attempt: Invalid credentials'
                    );

                    header('Location: login.php');
                    exit;
                }
            }
        }
    }
}

include __DIR__ . '/components/header.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .login-container {
            max-width: 400px;
            margin: 60px auto;
            padding: 32px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgb(0 0 0 / 10%);
        }

        .login-container h2 {
            margin-top: 0;
            text-align: center;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
        }

        .form-group input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        .btn-primary {
            width: 100%;
            padding: 10px;
            margin-top: 8px;
            border: none;
            border-radius: 4px;
            background: #1e88e5;
            color: #fff;
            font-weight: bold;
            cursor: pointer;
        }

        .btn-primary:hover {
            background: #1565c0;
        }

        .error-message {
            background: #ffcdd2;
            color: #c62828;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 16px;
            text-align: center;
        }

        .success-message {
            background: #c8e6c9;
            color: #2e7d32;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 16px;
            text-align: center;
        }

        .link-row {
            margin-top: 12px;
            text-align: center;
        }

        .link-row a {
            color: #1e88e5;
            text-decoration: none;
        }

        .link-row a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>

    <div class="login-container">
        <h2>Login</h2>

        <?php if ($success !== ''): ?>
            <div class="success-message">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php">
            <div class="form-group">
                <label for="username">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    required
                    value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required>
            </div>

            <button type="submit" class="btn-primary" name="login_submit">Login</button>

            <div class="link-row">
                <span>Don't have an account? <a href="register.php">Register here</a></span>
            </div>
        </form>
    </div>

</body>

</html>