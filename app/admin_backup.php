<?php
require_once __DIR__ . '/includes/setCookies.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'SuperAdmin') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

$backupConn = getBackupDbConnection();
$auditConn  = getDbConnection();

if (!$backupConn || !$auditConn) {
    http_response_code(500);
    exit('Database connection failed');
}

sqlsrv_query($backupConn, "EXEC dbo.usp_Backupdb_n_cloudSecurity_assignment_1");

$errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
if ($errors) {
    foreach ($errors as $e) {
        if ($e['SQLSTATE'] !== '01000') {
            http_response_code(500);
            exit('Backup failed');
        }
    }
}

auditLog(
    $auditConn,
    $_SESSION['user_id'],
    'SuperAdmin',
    'DATABASE_BACKUP',
    'db_n_cloudSecurity_assignment_1',
    null,
    json_encode([
        'backup_method' => 'Stored Procedure',
        'file_name'    => 'db_n_cloudSecurity_assignment_1.bak',
        'status'      => 'SUCCESS',
        'executed_at' => date('Y-m-d H:i:s')
    ]),
    $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
);

header('Location: admin_db_maintenance.php');
exit;
