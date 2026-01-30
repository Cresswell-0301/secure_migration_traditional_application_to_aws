<?php

function auditLog(
    $conn,
    ?int $userId,
    string $userRole,
    string $actionType,
    string $entityName,
    ?int $entityId,
    ?string $actionDetails = null
) {
    $ipAddress = getClientIp();

    $sql = "
        INSERT INTO AuditLogs
        (user_id, user_role, action_type, entity_name, entity_id, action_details, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ";

    $params = [
        $userId,
        $userRole,
        $actionType,
        $entityName,
        $entityId,
        $actionDetails,
        $ipAddress
    ];

    sqlsrv_query($conn, $sql, $params);
}

function getClientIp(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($parts as $p) {
            $ip = trim($p);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = trim($_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }

    return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
}
