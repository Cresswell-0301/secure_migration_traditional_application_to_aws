<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();

$pageTitle = 'Users Management';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'SuperAdmin')) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

$conn = getDbConnection();

// Filter Role
$allowedRoles = ['All', 'Doctor', 'Patient'];
$roleFilter = $_GET['role'] ?? 'All';

if (!in_array($roleFilter, $allowedRoles)) {
    $roleFilter = 'All';
}
// =====

$searchEmail = substr(trim($_GET['email'] ?? ''), 0, 100);

// Sort 
$allowedSortColumns = [
    'name'     => 'u.full_name',
    'username' => 'u.username',
    'email'    => 'u.email',
    'role'     => 'u.role',
    'status'   => 'u.is_active'
];

$sortKey = $_GET['sort'] ?? 'name';
$sortOrder = strtolower($_GET['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

$sortColumn = $allowedSortColumns[$sortKey] ?? 'u.full_name';
// =====

// Paginate
$perPage = 12;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);
$offset = ($page - 1) * $perPage;

$totalRow = fetchOne(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM Users
    WHERE role IN ('Patient','Doctor')
    " . ($roleFilter !== 'All' ? "AND role = ?" : "") . "
    " . ($searchEmail !== '' ? "AND email LIKE ?" : ""),
    array_merge(
        $roleFilter !== 'All' ? [$roleFilter] : [],
        $searchEmail !== '' ? ['%' . $searchEmail . '%'] : []
    )
);


$totalUsers = $totalRow['total'];
$totalPages = (int) ceil($totalUsers / $perPage);
// =====

// Fetch Users
$sqlUsers = "
    SELECT 
        u.user_id,
        u.full_name,
        u.username,
        u.email,
        u.role,
        u.is_active,
        d.specialization
    FROM Users u
    LEFT JOIN Doctors d ON d.user_id = u.user_id
    WHERE u.role IN ('Patient','Doctor')
    " . ($roleFilter !== 'All' ? "AND u.role = ?" : "") . "
    " . ($searchEmail !== '' ? "AND u.email LIKE ?" : "") . "
    ORDER BY $sortColumn $sortOrder
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params = [];

if ($roleFilter !== 'All') {
    $params[] = $roleFilter;
}

if ($searchEmail !== '') {
    $params[] = '%' . $searchEmail . '%';
}

$params[] = $offset;
$params[] = $perPage;

$users = fetchAll($conn, $sqlUsers, $params);
// =====

if (isset($_POST['create_user'])) {

    $fullName = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'];
    $role = $_POST['role'];
    $specialization = trim($_POST['specialization'] ?? '');

    if ($fullName === '' || $username === '' || $email === '' || $password === '' || $confirmPassword === '' || $role === '') {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } elseif ($password !== '' && strlen($password) < 12) {
        $error = "Password must be at least 12 characters.";
    } elseif ($password !== '' && !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $error = "Password must contain at least one special character.";
    } elseif ($role === 'Doctor' && $specialization === '') {
        $error = "Specialization is required for doctors.";
    } else {
        $conflict = fetchOne(
            $conn,
            "
            SELECT
                CASE
                    WHEN username = ? THEN 'username'
                    WHEN email = ? THEN 'email'
                END AS conflict
            FROM Users
            WHERE username = ? OR email = ?
            ",
            [$username, $email, $username, $email]
        );

        if ($conflict) {
            if ($conflict['conflict'] === 'username') {
                $error = "Username already exists.";

                auditLog(
                    $conn,
                    $_SESSION['user_id'],
                    $_SESSION['role'],
                    'CREATE_FAILED',
                    'Users',
                    null,
                    'Attempted to create user with existing username: ' . $username
                );
            } else {
                $error = "Email already exists.";

                auditLog(
                    $conn,
                    $_SESSION['user_id'],
                    $_SESSION['role'],
                    'CREATE_FAILED',
                    'Users',
                    null,
                    'Attempted to create user with existing email: ' . $email
                );
            }
        } else {
            sqlsrv_begin_transaction($conn);

            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $stmtUser = sqlsrv_query(
                    $conn,
                    "INSERT INTO Users (full_name, username, email, password_hash, role, is_active)
                     VALUES (?, ?, ?, ?, ?, 1)",
                    [$fullName, $username, $email, $passwordHash, $role]
                );

                if ($stmtUser === false) {
                    // throw new Exception("Failed to insert user.");
                    $errors = sqlsrv_errors();

                    auditLog(
                        $conn,
                        $_SESSION['user_id'],
                        $_SESSION['role'],
                        'CREATE_FAILED',
                        'Users',
                        null,
                        'User creation failed for username: ' . $username . ' Error: ' . $errors[0]['message']
                    );

                    throw new Exception($errors[0]['message']);
                }

                $newUser = fetchOne(
                    $conn,
                    "SELECT user_id FROM Users WHERE username = ?",
                    [$username]
                );

                if (!$newUser) {
                    auditLog(
                        $conn,
                        $_SESSION['user_id'],
                        $_SESSION['role'],
                        'CREATE_FAILED',
                        'Users',
                        null,
                        'Failed to retrieve new user ID for username: ' . $username
                    );

                    throw new Exception("Failed to retrieve new user ID.");
                }

                if ($role === 'Doctor') {
                    $stmtDoctor = sqlsrv_query(
                        $conn,
                        "INSERT INTO Doctors (user_id, specialization)
                         VALUES (?, ?)",
                        [$newUser['user_id'], $specialization]
                    );

                    if ($stmtDoctor === false) {
                        auditLog(
                            $conn,
                            $_SESSION['user_id'],
                            $_SESSION['role'],
                            'CREATE_FAILED',
                            'Doctors',
                            null,
                            'Doctor record creation failed for user ID: ' . $newUser['user_id']
                        );

                        throw new Exception("Failed to insert doctor record.");
                    }
                }

                sqlsrv_commit($conn);

                auditLog(
                    $conn,
                    $_SESSION['user_id'],
                    $_SESSION['role'],
                    'CREATE_SUCCESS',
                    'Users',
                    $newUser['user_id'],
                    'Created new user: ' . json_encode([
                        'user_id' => $newUser['user_id'],
                        'role'    => $role
                    ])
                );

                $message = ucfirst($role) . " created successfully.";

                header("Refresh:1; url=admin_users.php");
            } catch (Exception $e) {
                sqlsrv_rollback($conn);

                auditLog(
                    $conn,
                    $_SESSION['user_id'],
                    $_SESSION['role'],
                    'CREATE_FAILED',
                    'Users',
                    null,
                    'User creation failed for username: ' . $username . ' Error: ' . $e->getMessage()
                );

                $error = "Creation failed: " . $e->getMessage();
                $keepModalOpen = true;
            }
        }
    }
}


if (isset($_POST['update_user'])) {

    $userId   = (int)$_POST['user_id'];
    $fullName = trim($_POST['full_name']);
    $role     = $_POST['role'];
    $password = $_POST['password'];
    $email    = trim($_POST['email']);
    $username = trim($_POST['username']);

    // 1. Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
        goto update_end;
    }

    // 2. Check email uniqueness
    $emailExists = fetchOne(
        $conn,
        "SELECT 1 FROM Users WHERE email = ? AND user_id <> ?",
        [$email, $userId]
    );

    if ($emailExists) {
        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'UPDATE_FAILED',
            'Users',
            $userId,
            'Attempted update with existing email: ' . $email
        );
        $error = "Email already exists.";
        goto update_end;
    }

    // 3. Get old email
    $oldEmailRow = fetchOne(
        $conn,
        "SELECT email FROM Users WHERE user_id = ?",
        [$userId]
    );
    $oldEmail = $oldEmailRow['email'];

    sqlsrv_begin_transaction($conn);

    try {
        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $sql = "
                UPDATE Users
                SET username = ?,  full_name = ?, email = ?, role = ?, is_active = ?, password_hash = ?
                WHERE user_id = ?
            ";
            $params = [$username, $fullName, $email, $role, 1, $passwordHash, $userId];
        } else {
            $sql = "
                UPDATE Users
                SET username = ?, full_name = ?, email = ?, role = ?, is_active = ?
                WHERE user_id = ?
            ";
            $params = [$username, $fullName, $email, $role, 1, $userId];
        }

        sqlsrv_query($conn, $sql, $params);

        // Doctor table
        if ($role === 'Doctor') {
            sqlsrv_query(
                $conn,
                "
                IF EXISTS (SELECT 1 FROM Doctors WHERE user_id = ?)
                    UPDATE Doctors SET specialization = ? WHERE user_id = ?
                ELSE
                    INSERT INTO Doctors (user_id, specialization) VALUES (?, ?)
                ",
                [$userId, $_POST['specialization'], $userId, $userId, $_POST['specialization']]
            );
        }

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'UPDATE_SUCCESS',
            'Users',
            $userId,
            "Email changed from {$oldEmail} to {$email}"
        );

        sqlsrv_commit($conn);
        $message = "User updated successfully.";
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $error = "Update failed.";
    }

    update_end:
    header("Refresh:1; url=admin_users.php");
}

if (isset($_GET['delete'])) {
    $userId = (int)$_GET['delete'];

    if ($userId === $_SESSION['user_id']) {
        $error = "You cannot delete your own account.";

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'DELETE_FAILED',
            'Users',
            $userId,
            'Attempted to delete own user ID: ' . $userId
        );
    } else {
        sqlsrv_query(
            $conn,
            "UPDATE Users SET is_active = 0 WHERE user_id = ?",
            [$userId]
        );

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'DELETE_SUCCESS',
            'Users',
            $userId,
            'Disabled user ID: ' . $userId
        );

        $message = "User disabled successfully.";

        header("Refresh:1; url=admin_users.php");
    }
}

if (isset($_GET['activate'])) {
    if ($_SESSION['role'] !== 'SuperAdmin') {
        $error = "You are not authorized to activate accounts.";

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'ACTIVATE_FAILED',
            'Users',
            (int)$_GET['activate'],
            'Unauthorized activation attempt for user ID: ' . (int)$_GET['activate']
        );
    } else {
        $userId = (int)$_GET['activate'];

        sqlsrv_query(
            $conn,
            "UPDATE Users SET is_active = 1 WHERE user_id = ?",
            [$userId]
        );

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'ACTIVATE_SUCCESS',
            'Users',
            $userId,
            'Reactivated user ID: ' . $userId
        );

        $message = "User reactivated successfully.";
        header("Refresh:1; url=admin_users.php");
    }
}

$nameArrow = $usernameArrow = $emailArrow = $roleArrow = $statusArrow = '<i class="fa-solid fa-angle-down"></i>';

function arrow($key, $current, $order)
{
    if ($key === $current) {
        return $order === 'ASC'
            ? '<i class="fa-solid fa-angle-up"></i>'
            : '<i class="fa-solid fa-angle-down"></i>';
    }
    return '<i class="fa-solid fa-angle-down"></i>';
}

$nameArrow     = arrow('name', $sortKey, $sortOrder);
$usernameArrow = arrow('username', $sortKey, $sortOrder);
$emailArrow    = arrow('email', $sortKey, $sortOrder);
$roleArrow     = arrow('role', $sortKey, $sortOrder);
$statusArrow   = arrow('status', $sortKey, $sortOrder);

include __DIR__ . '/components/header.php';
?>
<style>
    .admin-container {
        background: #fff;
        padding: 25px;
        padding-top: 10px;
        border-radius: 10px;
        border: 1px solid rgba(15, 23, 42, 0.08);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    }

    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        gap: 12px;
        flex-wrap: wrap;
    }

    .admin-header h2 {
        margin: 0;
    }

    .btn {
        padding: 8px 14px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 14px;
        cursor: pointer;
        border: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
        transition: transform 120ms ease, filter 120ms ease, box-shadow 120ms ease;
    }

    .btn:hover {
        transform: translateY(-1px);
        filter: brightness(0.99);
    }

    .btn-primary {
        background: #1E88E5;
        color: white;
    }

    .btn-danger {
        background: #e53935;
        color: white;
    }

    .btn-warning {
        background: #fbc02d;
        color: #000;
    }

    .btn-disabled {
        background: #e0e0e0;
        color: #777;
        cursor: not-allowed;
    }

    .btn-success {
        background: #43a047;
        color: white;
    }

    .table-admin {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 10px;
        overflow: hidden;
    }

    .table-admin th,
    .table-admin td {
        padding: 12px;
        text-align: center;
        border-bottom: 1px solid #eee;
    }

    .table-admin th {
        background: #f5f7fa;
    }

    .table-admin tbody tr:hover {
        background: rgba(30, 136, 229, 0.05);
    }

    .badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 700;
    }

    .badge-active {
        background: #C8E6C9;
        color: #256029;
    }

    .badge-disabled {
        background: #FFCDD2;
        color: #B71C1C;
    }

    .message-success {
        background: #E8F5E9;
        color: #256029;
        padding: 10px;
        border-radius: 6px;
        margin-bottom: 15px;
    }

    .message-error {
        background: #FFEBEE;
        color: #B71C1C;
        padding: 10px;
        border-radius: 6px;
        margin-bottom: 15px;
    }

    @media (max-width: 860px) {
        .admin-container {
            padding: 16px;
        }

        .admin-header {
            align-items: stretch;
        }
    }
</style>

<div class="content-wrapper">
    <div class="admin-container">

        <div class="admin-header">
            <h2>User Management</h2>

            <div style="display:flex; gap:10px;">
                <?php
                $roles = ['All', 'Doctor', 'Patient'];

                foreach ($roles as $r):
                    $active = ($roleFilter === $r);
                ?>
                    <a href="?role=<?= $r ?>&sort=<?= $sortKey ?>&order=<?= strtolower($sortOrder) ?>&page=1"
                        class="btn"
                        style="<?= $active ? 'background:#1E88E5;color:white;' : 'background:#eee;color:#333;' ?>">
                        <?= $r ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <form method="get" style="display:flex; gap:10px; align-items:center; justify-content:center;">
                <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter) ?>">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sortKey) ?>">
                <input type="hidden" name="order" value="<?= strtolower($sortOrder) ?>">

                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="text" name="email"
                        placeholder="Search by email"
                        value="<?= htmlspecialchars($searchEmail) ?>"
                        style="height:36px;padding:0 10px;border-radius:4px;border:1px solid #ccc;box-sizing:border-box;">

                    <button type="submit" class="btn btn-primary"
                        style="height:36px; padding:0 14px; display:inline-flex; align-items:center; justify-content:center; margin-top:0;">
                        Search
                    </button>
                </div>
            </form>

            <div>
                <button class="btn btn-primary" onclick="openUserModal('Patient')">
                    + Patient
                </button>

                <button class="btn btn-primary" onclick="openUserModal('Doctor')">
                    + Doctor
                </button>
            </div>

            <div id="userModal" class="modal">
                <div class="modal-content">
                    <h3 id="modalRoleTitle"></h3>

                    <form method="post">
                        <input type="hidden" name="mode" id="formMode" value="create">
                        <input type="hidden" name="user_id" id="editUserId">
                        <input type="hidden" name="role" id="roleHidden">

                        <div class="schedule-form-grid">
                            <div class="layout" style="grid-column: span 2;">
                                <label style="text-align: left;">Full Name</label>
                                <input type="text" name="full_name" required>
                            </div>

                            <div class="layout">
                                <label style="text-align: left;">Username</label>
                                <input type="text" name="username" required>
                            </div>

                            <div class="layout">
                                <label style="text-align: left;">Email</label>
                                <input type="email" name="email" required>
                            </div>

                            <div class="layout">
                                <label style="text-align: left;">Password</label>
                                <input type="password" name="password">
                            </div>

                            <div class="layout">
                                <label style="text-align: left;">Confirm Password</label>
                                <input type="password" name="confirm_password">
                            </div>

                            <div id="specializationField" class="layout" style="display:none; grid-column: span 2;">
                                <label>Specialization</label>
                                <input type="text" name="specialization" id="specializationInput">
                            </div>
                        </div>

                        <div id="modalError" class="error-message" style="display:none; color:red; margin-top:10px;"></div>

                        <div class="btn-row">
                            <button type="submit" id="submitBtn" name="create_user" class="btn btn-primary">
                                Create
                            </button>

                            <button type="button"
                                class="btn btn-secondary"
                                onclick="closeModal('userModal')">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="message-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="message-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <table class="table-admin">
            <tr>
                <th>
                    <a href="?sort=name&order=<?= ($sortKey === 'name' && $sortOrder === 'ASC') ? 'desc' : 'asc' ?>&page=<?= $page ?>"
                        style="text-decoration: none; color: inherit;">
                        Name <?= $nameArrow ?>
                    </a>
                </th>

                <th>
                    <a href="?sort=username&order=<?= ($sortKey === 'username' && $sortOrder === 'ASC') ? 'desc' : 'asc' ?>&page=<?= $page ?>"
                        style="text-decoration: none; color: inherit;">
                        Username <?= $usernameArrow ?>
                    </a>
                </th>

                <th>
                    <a href="?sort=email&order=<?= ($sortKey === 'email' && $sortOrder === 'ASC') ? 'desc' : 'asc' ?>&page=<?= $page ?>"
                        style="text-decoration: none; color: inherit;">
                        Email <?= $emailArrow ?>
                    </a>
                </th>

                <?php if ($roleFilter === 'All'): ?>
                    <th>
                        <a href="?sort=role&order=<?= ($sortKey === 'role' && $sortOrder === 'ASC') ? 'desc' : 'asc' ?>&page=<?= $page ?>&role=<?= $roleFilter ?>"
                            style="text-decoration:none;color:inherit;">
                            Role <?= $roleArrow ?>
                        </a>
                    </th>
                <?php endif; ?>

                <th>
                    <a href="?sort=status&order=<?= ($sortKey === 'status' && $sortOrder === 'ASC') ? 'desc' : 'asc' ?>&page=<?= $page ?>"
                        style="text-decoration: none; color: inherit;">
                        Status <?= $statusArrow ?>
                    </a>
                </th>

                <th>Action</th>
            </tr>

            <?php if (empty($users)): ?>
                <tr>
                    <td colspan="<?= $roleFilter === 'All' ? 6 : 5 ?>"
                        style="padding:30px; color:#777; font-style:italic;">
                        No users found for email "<?= htmlspecialchars($searchEmail) ?>"
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['full_name']) ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>

                        <?php if ($roleFilter === 'All'): ?>
                            <td><?= $u['role'] ?></td>
                        <?php endif; ?>

                        <td>
                            <?php if ($u['is_active']): ?>
                                <span class="badge badge-active">Active</span>
                            <?php else: ?>
                                <span class="badge badge-disabled">Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td style="gap: 10px; display: flex; justify-content: center;">
                            <?php if ($u['is_active']): ?>
                                <button class="btn btn-warning"
                                    onclick='openEditUserModal(<?= json_encode($u) ?>)'>
                                    Edit
                                </button>

                                <?php if ($_SESSION['role'] === 'SuperAdmin'): ?>
                                    <a href="?delete=<?= $u['user_id'] ?>"
                                        class="btn btn-danger"
                                        onclick="return confirm('Disable this user?')">
                                        Disable
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($_SESSION['role'] === 'SuperAdmin'): ?>
                                    <span class="btn btn-success"
                                        onclick="if(confirm('Reactivate this user?')) { window.location='?activate=<?= $u['user_id'] ?>'; }">
                                        Activate
                                    </span>
                                <?php else: ?>
                                    <span class="btn btn-disabled">Disabled</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>

        <?php if ($totalPages > 1): ?>
            <div style="margin-top:20px; text-align:center;">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?= $i ?> 
                &sort=<?= $sortKey ?> 
                &order=<?= strtolower($sortOrder) ?> 
                &role=<?= $roleFilter ?> 
                &email=<?= urlencode($searchEmail) ?>"
                        style="
                    margin: 0 5px;
                    padding: 6px 12px;
                    border-radius: 4px;
                    text-decoration: none;
                   <?= $i === $page ? 'background:#1E88E5;color:white;' : 'background:#eee;color:#333;' ?>
               ">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="assets/js/modal.js" defer></script>

<?php if (!empty($keepModalOpen)): ?>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            openScheduleModal();
            const err = document.getElementById("modalError");
            if (err) {
                err.style.display = "block";
                err.innerText = "<?php echo addslashes($error); ?>";
            }
        });
    </script>
<?php endif; ?>

<script>
    function closeModal(id) {
        const modal = document.getElementById(id);
        modal.style.display = "none";

        const form = modal.querySelector("form");
        if (form) {
            form.reset();
            document.getElementById("formMode").value = "create";
            document.getElementById("editUserId").value = "";

            document.querySelector("input[name='username']").disabled = false;
            document.querySelector("input[name='email']").disabled = false;

            const submitBtn = document.getElementById("submitBtn");
            submitBtn.innerText = "Create";
            submitBtn.name = "create_user";
        }
    }
</script>