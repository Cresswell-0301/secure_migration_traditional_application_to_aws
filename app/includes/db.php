<?php
require_once __DIR__ . '/config.php';

function getDbConnection()
{
    $connectionInfo = [
        "Database" => DB_DATABASE,
        "UID"      => DB_APP_USER,
        "PWD"      => DB_APP_PASS,
        "Encrypt"  => "yes",
        "TrustServerCertificate" => "yes",
    ];
    
    $conn = sqlsrv_connect(DB_SERVER, $connectionInfo);

    if ($conn === false) {
        die("Database connection failed." . print_r(sqlsrv_errors(), true));
    }

    return $conn;
}

function getBackupDbConnection()
{
    $connectionInfo = [
        "Database" => DB_DATABASE,
        "UID"      => DB_BACKUP_USER,
        "PWD"      => DB_BACKUP_PASS,
        "CharacterSet" => "UTF-8"
    ];

    return sqlsrv_connect(DB_SERVER, $connectionInfo);
}

if (!function_exists('fetchAll')) {
    function fetchAll($conn, string $sql, array $params = []): array
    {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            return [];
        }

        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }
}

if (!function_exists('fetchOne')) {
    function fetchOne($conn, string $sql, array $params = []): ?array
    {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            return null;
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        return $row === null ? null : $row;
    }
}
