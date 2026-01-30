<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();

$pageTitle = 'Register';

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

$error = '';
$success = '';

// Handle form submission
if (isset($_POST['register_submit']) && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phoneNumber = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : '';

    // Basic validation
    if (empty($username) || empty($password) || empty($confirmPassword) || empty($fullName) || empty($email)) {
        $error = 'Please fill in all required fields.';
    }
    // Password match validation
    elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    }
    // Password length validation (≥ 12 characters)
    elseif (strlen($password) < 12) {
        $error = 'Password must be at least 12 characters long.';
    }
    // Password special symbol validation
    elseif (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $error = 'Password must contain at least one special symbol (!@#$%^&*(),.?":{}|<>).';
    }
    // Email format validation
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $conn = getDbConnection();

        // Check if username already exists (unique username validation)
        $checkUserSql = "SELECT user_id FROM Users WHERE username = ?";
        $checkParams = [$username];
        $checkStmt = sqlsrv_prepare($conn, $checkUserSql, $checkParams);

        if ($checkStmt === false) {
            $error = 'An internal error occurred. Please try again later.';
        } else {
            if (!sqlsrv_execute($checkStmt)) {
                $error = 'An internal error occurred. Please try again later.';
            } else {
                $existingUser = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

                if ($existingUser) {
                    $error = 'Username already exists. Please choose a different username.';

                    auditLog(
                        $conn,
                        null,
                        'Guest',
                        'REGISTRATION_FAILED_USERNAME_EXISTS',
                        'Users',
                        null,
                        'Attempted registration with existing username: ' . $username
                    );
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                    // Insert new patient user
                    $insertSql = "INSERT INTO Users (username, password_hash, full_name, email, phone_number, role) 
                                  VALUES (?, ?, ?, ?, ?, 'Patient')";
                    $insertParams = [$username, $passwordHash, $fullName, $email, $phoneNumber];
                    $insertStmt = sqlsrv_prepare($conn, $insertSql, $insertParams);

                    if ($insertStmt === false) {
                        $error = 'An internal error occurred. Please try again later.';

                        auditLog(
                            $conn,
                            null,
                            'Guest',
                            'REGISTRATION_FAILED_DB_ERROR',
                            'Users',
                            null,
                            'Database error during registration for username: ' . $username
                        );
                    } else {
                        if (!sqlsrv_execute($insertStmt)) {
                            $error = 'Registration failed. Please try again later.';

                            auditLog(
                                $conn,
                                null,
                                'Guest',
                                'REGISTRATION_FAILED_DB_EXECUTE',
                                'Users',
                                null,
                                'Database execution error during registration for username: ' . $username
                            );
                        } else {
                            // Registration successful - redirect to login
                            $_SESSION['registration_success'] = 'Registration successful! Please login with your credentials.';

                            $idStmt = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS id");
                            $row = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
                            $newUserId = (int) $row['id'];

                            auditLog(
                                $conn,
                                null,
                                'Guest',
                                'REGISTRATION_SUCCESS',
                                'Users',
                                $newUserId,
                                'New patient registered with username: ' . $username
                            );

                            header('Location: login.php');
                            exit;
                        }
                    }
                }
            }
        }

        sqlsrv_close($conn);
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
        .register-container {
            max-width: 500px;
            margin: 60px auto;
            padding: 32px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgb(0 0 0 / 10%);
        }

        .register-container h2 {
            margin-top: 0;
            text-align: center;
        }

        .info-notice {
            background: #e3f2fd;
            color: #1565c0;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: center;
            border-left: 4px solid #1e88e5;
            font-weight: 500;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
        }

        .form-group label .required {
            color: #c62828;
        }

        .form-group input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .form-group input:focus {
            outline: none;
            border-color: #1e88e5;
        }

        .password-requirements {
            font-size: 0.85em;
            color: #666;
            margin-top: 4px;
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
            font-size: 16px;
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

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 30px;
            border: 1px solid #888;
            border-radius: 8px;
            width: 80%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .modal-content h3 {
            margin-top: 0;
            color: #1e88e5;
        }

        .modal-content p {
            margin: 20px 0;
            color: #333;
            line-height: 1.6;
        }

        .modal-btn {
            background-color: #1e88e5;
            color: white;
            padding: 10px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
        }

        .modal-btn:hover {
            background-color: #1565c0;
        }
    </style>
</head>

<body>

    <!-- Patient Registration Notice Modal -->
    <div id="patientModal" class="modal">
        <div class="modal-content">
            <h3>Patient Registration</h3>
            <p>This registration form is for <strong>patients only</strong>.</p>
            <p>If you are a doctor or administrator, please contact the system administrator for account creation.</p>
            <button class="modal-btn" onclick="closeModal()">I Understand</button>
        </div>
    </div>

    <div class="register-container">
        <h2>Patient Registration</h2>

        <div class="info-notice">
            This registration is for patients only
        </div>

        <?php if ($error !== ''): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="success-message">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="register.php">
            <div class="form-group">
                <label for="username">Username <span class="required">*</span></label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    required
                    value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="full_name">Full Name <span class="required">*</span></label>
                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    required
                    value="<?php echo isset($fullName) ? htmlspecialchars($fullName) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="email">Email <span class="required">*</span></label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    required
                    value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="phone_number">Phone Number</label>
                <input
                    type="tel"
                    id="phone_number"
                    name="phone_number"
                    value="<?php echo isset($phoneNumber) ? htmlspecialchars($phoneNumber) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="password">Password <span class="required">*</span></label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required>
                <div class="password-requirements">
                    Must be at least 12 characters and contain at least one special symbol
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    required>
            </div>

            <button type="submit" class="btn-primary" name="register_submit">Register</button>

            <div class="link-row">
                <span>Already have an account? <a href="login.php">Login here</a></span>
            </div>
        </form>
    </div>

    <script>
        // Show modal on page load
        window.onload = function() {
            document.getElementById('patientModal').style.display = 'block';
        };

        // Close modal function
        function closeModal() {
            document.getElementById('patientModal').style.display = 'none';
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            var modal = document.getElementById('patientModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        };
    </script>

</body>

</html>