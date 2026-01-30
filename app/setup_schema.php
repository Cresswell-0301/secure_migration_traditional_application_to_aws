<?php

require_once __DIR__ . '/includes/db.php';

$conn = getDbConnection();
if (!$conn) {
    die("DB connection failed: " . print_r(sqlsrv_errors(), true));
}

$queries = [];

// USERS
$queries[] = "
IF OBJECT_ID('dbo.Users', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.Users (
        user_id        INT IDENTITY(1,1) PRIMARY KEY,
        username       VARCHAR(50)  NOT NULL UNIQUE,
        password_hash  VARCHAR(255) NOT NULL,
        full_name      VARCHAR(100) NOT NULL,
        email          VARCHAR(100) NOT NULL,
        phone_number   VARCHAR(20)  NULL,
        role           VARCHAR(20)  NOT NULL,
        is_active      BIT          NOT NULL DEFAULT 1,
            CHECK (role IN ('Patient','Doctor','Admin', 'SuperAdmin'))
    );
END
";

// DOCTORS
$queries[] = "
IF OBJECT_ID('dbo.Doctors', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.Doctors (
        doctor_id      INT IDENTITY(1,1) PRIMARY KEY,
        user_id        INT NOT NULL,
        specialization VARCHAR(100) NULL,

        CONSTRAINT FK_Doctors_Users
            FOREIGN KEY (user_id) REFERENCES dbo.Users(user_id)
    );
END
";

// DOCTOR AVAILABILITY
$queries[] = "
IF OBJECT_ID('dbo.DoctorAvailability', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.DoctorAvailability (
        availability_id INT IDENTITY(1,1) PRIMARY KEY,
        doctor_id       INT  NOT NULL,
        available_date  DATE NOT NULL,
        available_time  TIME NOT NULL,
        is_booked       BIT  NOT NULL DEFAULT 0,

        CONSTRAINT FK_DoctorAvailability_Doctors
            FOREIGN KEY (doctor_id) REFERENCES dbo.Doctors(doctor_id)
    );
END
";

// APPOINTMENTS
$queries[] = "
IF OBJECT_ID('dbo.Appointments', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.Appointments (
        appointment_id   INT IDENTITY(1,1) PRIMARY KEY,
        patient_id       INT  NOT NULL,
        doctor_id        INT  NOT NULL,
        appointment_date DATE NOT NULL,
        appointment_time TIME NOT NULL,
        status           VARCHAR(20) NOT NULL
            CHECK (status IN ('Booked','Completed','Cancelled')),

        CONSTRAINT FK_Appointments_Patient
            FOREIGN KEY (patient_id) REFERENCES dbo.Users(user_id),
        CONSTRAINT FK_Appointments_Doctor
            FOREIGN KEY (doctor_id)  REFERENCES dbo.Doctors(doctor_id)
    );
END
";

// AUDIT LOG
$queries[] = "
IF OBJECT_ID('dbo.AuditLogs', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.AuditLogs (
        audit_id       INT IDENTITY(1,1) PRIMARY KEY,
        user_id        INT NULL,
        user_role      VARCHAR(20) NULL,
        action_type    VARCHAR(50) NOT NULL,
        entity_name    VARCHAR(50) NOT NULL,
        entity_id      INT NULL,
        action_details VARCHAR(500) NULL,
        ip_address     VARCHAR(45) NULL,
        created_at     DATETIME2 NOT NULL DEFAULT SYSDATETIME()
    );
END
";

// LOGIN ATTEMPTS
$queries[] = "
IF OBJECT_ID('dbo.LoginAttempts', 'U') IS NULL
BEGIN
    CREATE TABLE LoginAttempts (
        username VARCHAR(50),
        attempt_time DATETIME DEFAULT GETDATE()
    );
END
";

foreach ($queries as $sql) {
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        echo "<pre>ERROR:\n" . print_r(sqlsrv_errors(), true) . "</pre>";
        exit;
    }
}

echo "Schema setup completed. Tables are ready.";
