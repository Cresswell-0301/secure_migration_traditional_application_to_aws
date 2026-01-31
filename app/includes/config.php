<?php

define('DB_SERVER', getenv('DB_HOST') ?: 'ccs6344-mssql-rds-public.cnecgwk24aci.ap-southeast-5.rds.amazonaws.com');
define('DB_DATABASE', getenv('DB_NAME') ?: 'db_n_cloudSecurity_assignment_1');

// Application User (use container env vars)
define('DB_APP_USER', getenv('DB_USER') ?: 'admin');
define('DB_APP_PASS', getenv('DB_PASS') ?: 'Pa$$w0rd');

// Backup User (keep as-is unless you also pass env vars for it)
define('DB_BACKUP_USER', 'clinic_backup_user');
define('DB_BACKUP_PASS', 'Pa$$w0rd');

// Super Admin
// define('DB_SA_USER', 'sa');
// define('DB_SA_PASSWORD', '12345678');

// Database Admin
// define('DB_RECOVERY_USER', 'clinic_dba');
// define('DB_RECOVERY_PASS', 'Pa$$w0rd');