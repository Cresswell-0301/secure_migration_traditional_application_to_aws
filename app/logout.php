<?php
require_once __DIR__ . '/includes/setCookies.php';

session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

$userId   = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['role'] ?? 'Unknown';

$conn = getDbConnection();

auditLog(
    $conn,
    $userId,
    $userRole,
    'LOGOUT',
    'Session',
    null,
    'User logged out successfully' . json_encode([
        'user_id' => $userId,
        'role'    => $userRole
    ])
);

$_SESSION = [];

session_unset();
session_destroy();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 3600,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

header('Location: index.php');
exit;
