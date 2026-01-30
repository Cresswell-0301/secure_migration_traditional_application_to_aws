<?php
require_once __DIR__ . '/includes/setCookies.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'SuperAdmin') {
    http_response_code(403);
    exit('Access denied');
}

$pageTitle = 'Database Maintenance';

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

$sql = "
    SELECT action_type, action_details, created_at, ip_address
    FROM AuditLogs
    WHERE action_type = 'DATABASE_BACKUP'
    ORDER BY created_at DESC
";

$logs = fetchAll(getDbConnection(), $sql);

include __DIR__ . '/components/header.php';
?>
<style>
    .btn {
        margin-top: 10px;
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

    .badge {
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 12px;
        display: inline-block;
    }

    .badge-success {
        background: #43a047;
        color: #fff;
    }

    .badge-danger {
        background: #e53935;
        color: #fff;
    }

    .badge-warning {
        background: #fbc02d;
        color: #000;
    }

    .log-scroll {
        max-height: 660px;
        overflow: auto;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 8px;
        background: #fff;
    }

    .log-scroll thead th {
        position: sticky;
        top: 0;
        background: #fff;
        z-index: 2;
    }

    .json-log {
        margin: 6px 0 0;
        white-space: pre;
        max-height: 160px;
        overflow: auto;
        padding: 10px;
        border: 1px solid rgba(15, 23, 42, 0.12);
        border-radius: 6px;
        background: #f8fafc;
    }
</style>

<div class="content-wrapper">
    <h2 class="mb-3">Database Backup &amp; Recovery</h2>

    <div class="alert alert-warning">
        <strong>High-Privilege Operation:</strong>
        This action will trigger an immediate SQL Server database backup. It may temporarily increase server load and disk usage.
        Proceed only if you understand the impact. All backup attempts are recorded in the audit log below.
    </div>

    <form method="post" action="admin_backup.php"
        onsubmit="return confirm('Run database backup now? This is a high-privilege operation and will be recorded in the audit log.');">
        <button type="submit" class="btn btn-danger">Run Database Backup</button>
    </form>

    <hr class="my-4">

    <h3 class="mb-3">Backup Activity Log</h3>

    <?php if (empty($logs)): ?>
        <p style="color:#777;">No backup activity recorded.</p>
    <?php else: ?>
        <div class="log-scroll">
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Status</th>
                        <th>IP Address</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $detailsArr = json_decode($log['action_details'] ?? '', true) ?: [];
                        $status = strtoupper($detailsArr['status'] ?? 'UNKNOWN');

                        $statusClass =
                            ($status === 'SUCCESS') ? 'badge-success' : (($status === 'FAILED') ? 'badge-danger' : 'badge-warning');

                        $method = $detailsArr['backup_method'] ?? '-';
                        $file   = $detailsArr['file_name'] ?? '-';
                        $execAt = $detailsArr['executed_at'] ?? '-';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($log['created_at']->format('Y-m-d H:i:s')) ?></td>
                            <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span></td>
                            <td><?= htmlspecialchars($log['ip_address']) ?></td>
                            <td>
                                <div style="display:flex; flex-direction:column; gap:4px; text-align:left;">
                                    <div><strong>Method:</strong> <?= htmlspecialchars($method) ?></div>
                                    <div><strong>File:</strong> <?= htmlspecialchars($file) ?></div>
                                    <div><strong>Executed:</strong> <?= htmlspecialchars($execAt) ?></div>

                                    <details style="margin-top:6px;">
                                        <summary style="cursor:pointer; color:#1e88e5;">View raw JSON</summary>
                                        <pre style="
                                    margin:6px 0 0;
                                    white-space:pre;
                                    max-height:160px;
                                    overflow:auto;
                                    padding:10px;
                                    border:1px solid rgba(15,23,42,0.12);
                                    border-radius:6px;
                                    background:#f8fafc;
                                "><?= htmlspecialchars(json_encode($detailsArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                                    </details>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>