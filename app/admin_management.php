<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();

$pageTitle = 'Admins Management';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'SuperAdmin') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

$conn = getDbConnection();

$allowedRoles = ['All', 'Admin', 'SuperAdmin'];
$roleFilter = $_GET['role'] ?? 'All';

if (!in_array($roleFilter, $allowedRoles)) {
    $roleFilter = 'All';
}

$searchEmail = substr(trim($_GET['email'] ?? ''), 0, 100);

$allowedSort = [
    'name'   => 'u.full_name',
    'email'  => 'u.email',
    'status' => 'u.is_active'
];

$sortKey = $_GET['sort'] ?? 'name';
if (!isset($allowedSort[$sortKey])) $sortKey = 'name';

$sortCol = $allowedSort[$sortKey];

$order = strtoupper($_GET['order'] ?? 'ASC');
$order = in_array($order, ['ASC', 'DESC'], true) ? $order : 'ASC';

$pageSize = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $pageSize;

$countSql = "
SELECT COUNT(*) AS total
FROM Users u
WHERE u.role IN ('Admin','SuperAdmin')
AND u.user_id <> ?
";

$params = [$_SESSION['user_id']];

if ($roleFilter !== 'All') {
    $countSql .= " AND u.role = ?";
    $params[] = $roleFilter;
}

if ($searchEmail !== '') {
    $countSql .= " AND u.email LIKE ?";
    $params[] = "%$searchEmail%";
}

$totalRow = fetchOne($conn, $countSql, $params);
$total = (int)($totalRow['total'] ?? 0);
$totalPages = max(1, ceil($total / $pageSize));

$sql = "
SELECT
    u.user_id,
    u.full_name,
    u.username,
    u.email,
    u.role,
    u.is_active
FROM Users u
WHERE u.role IN ('Admin','SuperAdmin')
AND u.user_id <> ?
";

$paramsFetch = [$_SESSION['user_id']];

if ($roleFilter !== 'All') {
    $sql .= " AND u.role = ?";
    $paramsFetch[] = $roleFilter;
}

if ($searchEmail !== '') {
    $sql .= " AND u.email LIKE ?";
    $paramsFetch[] = "%$searchEmail%";
}

$sql .= "
ORDER BY $sortCol $order
OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$paramsFetch[] = $offset;
$paramsFetch[] = $pageSize;

$admins = fetchAll($conn, $sql, $paramsFetch);

// CREATE ADMIN
if (isset($_POST['create_admin'])) {

    $fullName = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    if ($fullName === '' || $username === '' || $email === '' || $password === '' || $confirm === '') {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 12) {
        $error = "Password must be at least 12 characters.";
    } elseif (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $error = 'Password must contain at least one special symbol (!@#$%^&*(),.?":{}|<>).';
    } else {
        $exists = fetchOne(
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

        if ($exists) {
            if ($exists['conflict'] === 'username') {
                $error = "Username already exists.";
                auditLog(
                    $conn,
                    $_SESSION['user_id'],
                    $_SESSION['role'],
                    'CREATE_FAILED',
                    'Users',
                    null,
                    'Attempted to create admin with existing username: ' . $username
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
                    'Attempted to create admin with existing email: ' . $email
                );
            }
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            sqlsrv_query(
                $conn,
                "INSERT INTO Users (full_name, username, email, password_hash, role, is_active)
                 VALUES (?, ?, ?, ?, 'Admin', 1)",
                [$fullName, $username, $email, $hash]
            );

            $newAdminId = fetchOne(
                $conn,
                "SELECT SCOPE_IDENTITY() AS new_id"
            )['new_id'];

            auditLog(
                $conn,
                $_SESSION['user_id'],
                $_SESSION['role'],
                'CREATE',
                'Users',
                $newAdminId,
                'Admin created successfully: ' . json_encode([
                    'full_name' => $fullName,
                    'username'  => $username,
                    'email'     => $email,
                    'role'      => 'Admin'
                ])
            );

            $message = "Admin created successfully.";
        }
        header("Refresh:1; url=admin_management.php");
    }
}

// UPDATE ADMIN
if (isset($_POST['update_admin'])) {

    $userId   = (int)$_POST['user_id'];
    $fullName = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    if ($password !== '' && $password !== $_POST['confirm_password']) {
        $error = "Passwords do not match.";
    }

    if ($userId === $_SESSION['user_id']) {
        $error = "You cannot modify your own account.";
    } elseif ($fullName === '' || $username === '' || $email === '') {
        $error = "Full name, username and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif ($password !== '' && strlen($password) < 12) {
        $error = 'Password must be at least 12 characters long.';
    } elseif ($password !== '' && !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $error = 'Password must contain at least one special symbol (!@#$%^&*(),.?":{}|<>).';
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
                WHERE (username = ? OR email = ?)
                AND user_id <> ?
            ",
            [$username, $email, $username, $email, $userId]
        );

        if ($conflict) {
            if ($conflict['conflict'] === 'username') {
                $error = "Username already exists.";
            } else {
                $error = "Email already exists.";
            }
        } else {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                sqlsrv_query(
                    $conn,
                    "UPDATE Users
                        SET full_name = ?, username = ?, email = ?, password_hash = ?
                        WHERE user_id = ? AND role = 'Admin'",
                    [$fullName, $username, $email, $hash, $userId]
                );

                auditLog(
                    $conn,
                    $_SESSION['user_id'],
                    $_SESSION['role'],
                    'UPDATE',
                    'Users',
                    $userId,
                    json_encode([
                        'updated_fields' => ['full_name', 'username', 'email', 'password']
                    ])
                );
            } else {
                sqlsrv_query(
                    $conn,
                    "UPDATE Users
                        SET full_name = ?, username = ?, email = ?
                        WHERE user_id = ? AND role = 'Admin'
                    ",
                    [$fullName, $username, $email, $userId]
                );

                auditLog(
                    $conn,
                    $_SESSION['user_id'],
                    $_SESSION['role'],
                    'UPDATE',
                    'Users',
                    $userId,
                    json_encode([
                        'updated_fields' => ['full_name', 'username', 'email']
                    ])
                );
            }
        }

        if (!isset($error)) {
            $message = "Admin updated successfully.";
        }

        header("Refresh:1; url=admin_management.php");
    }
}

// DISABLE / ACTIVATE
if (isset($_GET['disable'])) {
    $id = (int)$_GET['disable'];

    $target = fetchOne($conn, "SELECT role FROM Users WHERE user_id=?", [$id]);

    if (!$target || $target['role'] !== 'Admin') {
        $error = "Only Admin accounts can be disabled.";

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'DISABLE_FAILED',
            'Users',
            $id,
            'Attempted to disable non-admin or non-existing user ID: ' . $id
        );
    } elseif ($id === $_SESSION['user_id']) {
        $error = "You cannot disable yourself.";

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'DISABLE_FAILED',
            'Users',
            $id,
            'Attempted to disable own account.'
        );
    } else {
        sqlsrv_query($conn, "UPDATE Users SET is_active=0 WHERE user_id=?", [$id]);

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'UPDATE',
            'Users',
            $id,
            'Admin account disabled' . json_encode([
                'disabled_user_id' => $id
            ])
        );

        $message = "Admin disabled.";
        header("Refresh:1; url=admin_management.php");
    }
}

if (isset($_GET['activate'])) {
    $id = (int)$_GET['activate'];

    sqlsrv_query($conn, "UPDATE Users SET is_active=1 WHERE user_id=? AND role='Admin'", [$id]);

    auditLog(
        $conn,
        $_SESSION['user_id'],
        $_SESSION['role'],
        'UPDATE',
        'Users',
        $id,
        'Admin account activated' . json_encode([
            'activated_user_id' => $id
        ])
    );

    $message = "Admin activated.";
    header("Refresh:1; url=admin_management.php");
}

// DELETE
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    if ($id === $_SESSION['user_id']) {
        $error = "You cannot delete your own account.";

        auditLog(
            $conn,
            $_SESSION['user_id'],
            $_SESSION['role'],
            'DELETE_FAILED',
            'Users',
            $id,
            'Attempted to delete own account.'
        );
    } else {
        $target = fetchOne(
            $conn,
            "SELECT role FROM Users WHERE user_id = ?",
            [$id]
        );

        if (!$target) {
            $error = "User not found.";

            auditLog(
                $conn,
                $_SESSION['user_id'],
                $_SESSION['role'],
                'DELETE_FAILED',
                'Users',
                $id,
                'Attempted to delete non-existing user ID: ' . $id
            );
        } elseif ($target['role'] !== 'Admin') {
            $error = "Only Admin accounts can be deleted.";

            auditLog(
                $conn,
                $_SESSION['user_id'],
                $_SESSION['role'],
                'DELETE_FAILED',
                'Users',
                $id,
                'Attempted to delete non-admin user ID: ' . $id
            );
        } else {
            sqlsrv_query(
                $conn,
                "DELETE FROM Users WHERE user_id = ? AND role = 'Admin'",
                [$id]
            );

            auditLog(
                $conn,
                $_SESSION['user_id'],
                $_SESSION['role'],
                'DELETE',
                'Users',
                $id,
                'Admin account deleted' . json_encode([
                    'deleted_user_id' => $id
                ])
            );

            $message = "Admin deleted successfully.";
            header("Refresh:1; url=admin_management.php");
        }
    }
}

function arrow($key, $current, $order)
{
    if ($key !== $current) {
        return '<i class="fa-solid fa-angle-down" style="opacity:.4"></i>';
    }
    return $order === 'ASC'
        ? '<i class="fa-solid fa-angle-up"></i>'
        : '<i class="fa-solid fa-angle-down"></i>';
}

include __DIR__ . '/components/header.php';
?>
<style>
    .admin-container {
        background: #fff;
        padding: 25px;
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

    .badge-active {
        background: #C8E6C9;
        color: #256029;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 12px;
    }

    .badge-disabled {
        background: #FFCDD2;
        color: #B71C1C;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 12px;
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

    .select-filter {
        height: 36px;
        padding: 0 36px 0 12px;
        border: 1px solid #ccc;
        border-radius: 6px;
        background-color: #fff;
        font-size: 14px;
        cursor: pointer;
        background-repeat: no-repeat;
        background-position: right 10px center;
        background-size: 14px;
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
        transition: transform 120ms ease, filter 120ms ease;
    }

    .btn:hover {
        transform: translateY(-1px);
        filter: brightness(0.99);
    }

    .btn-warning {
        background: #fbc02d;
        color: #000;
    }

    .btn-danger {
        background: #e53935;
        color: #fff;
    }

    .btn-success {
        background: #43a047;
        color: #fff;
    }

    .btn-secondary {
        background: #9e9e9e;
        color: #fff;
    }
</style>

<div class="content-wrapper">
    <div class="admin-container">
        <div class="admin-header">
            <h2>Admins Management</h2>

            <form method="get" style="display:flex;gap:10px;align-items:center;">
                <input type="hidden" name="sort" value="<?= $sortKey ?>">
                <input type="hidden" name="order" value="<?= strtolower($order) ?>">

                <select name="role" class="select-filter">
                    <?php foreach (['All', 'Admin'] as $r): ?>
                        <option value="<?= $r ?>" <?= $roleFilter === $r ? 'selected' : '' ?>>
                            <?= $r ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="text"
                    name="email"
                    placeholder="Search by email"
                    value="<?= htmlspecialchars($searchEmail) ?>"
                    style="height:36px;padding:0 10px;border-radius:4px;border:1px solid #ccc;">

                <button class="btn btn-primary" style="margin-top:0;">Search</button>
            </form>

            <button class="btn btn-primary" onclick="openModal('adminModal')">
                + Admin
            </button>
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
                    <a href="?<?= http_build_query(array_merge($_GET, [
                                    'sort' => 'name',
                                    'order' => ($sortKey === 'name' && $order === 'ASC') ? 'DESC' : 'ASC',
                                    'page' => 1
                                ])) ?>"
                        style="text-decoration:none; color:inherit;">
                        Name <?= arrow('name', $sortKey, $order) ?>
                    </a>
                </th>
                <th>
                    <a href="?<?= http_build_query(array_merge($_GET, [
                                    'sort' => 'email',
                                    'order' => ($sortKey === 'email' && $order === 'ASC') ? 'DESC' : 'ASC',
                                    'page' => 1
                                ])) ?>"
                        style="text-decoration:none; color:inherit;">
                        Email <?= arrow('email', $sortKey, $order) ?>
                    </a>
                </th>
                <th>
                    <a href="?<?= http_build_query(array_merge($_GET, [
                                    'sort' => 'status',
                                    'order' => ($sortKey === 'status' && $order === 'ASC') ? 'DESC' : 'ASC',
                                    'page' => 1
                                ])) ?>"
                        style="text-decoration:none; color:inherit;">
                        Status <?= arrow('status', $sortKey, $order) ?>
                    </a>
                </th>
                <th>Action</th>
            </tr>

            <?php if (empty($admins)): ?>
                <tr>
                    <td colspan="5" style="padding:30px;color:#777;">
                        No admin accounts found
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($admins as $a): ?>
                    <tr>
                        <td><?= htmlspecialchars($a['full_name']) ?></td>
                        <td><?= htmlspecialchars($a['email']) ?></td>
                        <td>
                            <?= $a['is_active']
                                ? '<span class="badge-active">Active</span>'
                                : '<span class="badge-disabled">Disabled</span>' ?>
                        </td>
                        <td style="display:flex;gap:8px;justify-content:center;">
                            <?php if ($a['role'] === 'Admin'): ?>
                                <button class="btn btn-warning"
                                    onclick='openEditAdmin(<?= json_encode($a) ?>)'>
                                    Edit
                                </button>

                                <?php if ($a['is_active']): ?>
                                    <a href="?disable=<?= $a['user_id'] ?>"
                                        class="btn btn-danger"
                                        onclick="return confirm('Disable this admin?')">
                                        Disable
                                    </a>
                                <?php else: ?>
                                    <a href="?activate=<?= $a['user_id'] ?>"
                                        class="btn btn-success">
                                        Activate
                                    </a>
                                <?php endif; ?>

                                <a href="?delete=<?= $a['user_id'] ?>"
                                    class="btn"
                                    onclick="return confirm('⚠ Are you sure you want to permanently delete this admin? This action cannot be undone.')">
                                    <i class="fa-solid fa-trash" style="color: #ff0000;"></i>
                                </a>
                            <?php else: ?>
                                <span style="opacity:.6;">Protected</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>

        <?php if ($totalPages > 1): ?>
            <div style="margin-top:20px;text-align:center;">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?= $i ?>"
                        style="padding:6px 12px;margin:0 4px;
       <?= $i === $page ? 'background:#1E88E5;color:#fff;' : 'background:#eee;' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<div id="adminModal" class="modal">
    <div class="modal-content">
        <h3 id="adminModalTitle">Create Admin</h3>

        <form method="post">
            <input type="hidden" name="user_id" id="admin_id">

            <div class="schedule-form-grid">
                <div class="layout" style="grid-column:span 2;">
                    <label>Full Name</label>
                    <input type="text" name="full_name" id="admin_name" required>
                </div>

                <div class="layout">
                    <label>Username</label>
                    <input type="text" name="username" id="admin_username" style="width: 80%;">
                </div>

                <div class="layout">
                    <label>Email</label>
                    <input type="email" name="email" id="admin_email" style="width: 80%;">
                </div>

                <div class="layout">
                    <label>Password</label>
                    <input type="password" name="password" style="width: 80%;">
                </div>

                <div class="layout">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" style="width: 80%;">
                </div>
            </div>

            <div class="btn-row">
                <button type="submit"
                    class="btn btn-primary"
                    name="create_admin"
                    id="adminSubmitBtn">
                    Create
                </button>
                <button type="button"
                    class="btn btn-secondary"
                    onclick="closeModal('adminModal')">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/modal.js"></script>

<script>
    function openEditAdmin(a) {
        openModal('adminModal');
        document.getElementById('adminModalTitle').innerText = 'Edit Admin';
        document.getElementById('admin_id').value = a.user_id;
        document.getElementById('admin_name').value = a.full_name;
        document.getElementById('admin_username').value = a.username;
        document.getElementById('admin_email').value = a.email;

        const btn = document.getElementById('adminSubmitBtn');
        btn.name = 'update_admin';
        btn.innerText = 'Update';
    }
</script>