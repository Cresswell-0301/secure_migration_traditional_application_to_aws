# Clinic Appointment Booking System  
**CCS6344 – Database & Cloud Security (T2530) – Assignment 1**

---

## Project Overview
This project implements a **secure web-based Clinic Appointment Booking System** using **PHP** and **Microsoft SQL Server (MSSQL)**.

The system supports four distinct roles — **Patient, Doctor, Administrator, and SuperAdmin** — and is designed with a strong emphasis on **backend security, database protection, and access control**, in alignment with the learning outcomes of the **CCS6344 – Database & Cloud Security** course.

Beyond functional requirements, the primary objective of this project is to **demonstrate secure backend and database design against both internal and external threats**, following industry-aligned security practices.

---

## Key Objectives
- Provide a secure platform for booking and managing clinic appointments  
- Enforce **role-based** and **ownership-based** access control  
- Protect sensitive personal and appointment data stored in MSSQL  
- Apply database security concepts covered in CCS6344  
- Demonstrate compliance with **PDPA 2010 security principles**

---

## System Architecture
The system follows a **three-tier architecture**:

### 1. Presentation Layer
- HTML, CSS, JavaScript  
- Handles user interaction and input validation  

### 2. Application Layer
- PHP  
- Implements authentication, authorization, business logic, and security controls  

### 3. Database Layer
- Microsoft SQL Server (MSSQL)  
- Stores users, appointments, availability, audit logs, and login attempts  

> **Note:** Security controls are enforced at the **backend layer**, not solely at the UI.

---

## User Roles
- **Patient** – Book, view, modify, and cancel own appointments  
- **Doctor** – Manage schedules and view assigned patient information  
- **Administrator** – Manage users, appointments, and doctor availability  
- **SuperAdmin** – Highest privilege role with access to audit logs and critical system functions  

---

## Backend Security Features

### Authentication & Session Security
- Password hashing using `password_hash()` (bcrypt)  
- Secure authentication using `password_verify()`  
- Session ID regeneration after login (prevents session fixation)  
- Secure session cookies:
  - `HttpOnly`
  - `Secure`
  - `SameSite=Strict`
- CSRF token validation for all state-changing requests  

---

### Role-Based Access Control (RBAC)
- Enforced on **every protected backend page**  
- Role validation occurs **before any database query is executed**  
- SuperAdmin-only access to sensitive operations such as audit logs and backups  
- Prevents privilege escalation and unauthorized access  

---

### SQL Injection Prevention
- **100% parameterized queries** (PDO / SQLSRV prepared statements)  
- No dynamic SQL concatenation  
- User input cannot alter query structure  

---

### Ownership & Row-Level Protection (Application Layer)
- Patients can only access records linked to their own `user_id`  
- Doctors can only access appointments linked to their `doctor_id`  
- Administrative actions are role-restricted and logged  
- Prevents **horizontal privilege escalation**  

---

### Least-Privilege Database Access
- Application connects using a **restricted SQL account**  
- No use of `sa` or administrative credentials  
- Application account cannot:
  - DROP tables  
  - ALTER schema  
  - Access system-level objects  

> Even if the application layer is compromised, database damage is limited.

---

### Audit Logging & Monitoring
- Security-relevant actions are recorded in the `AuditLogs` table:
  - Login attempts  
  - User management  
  - Appointment creation, update, and cancellation  
  - Database backup operations  
- Each log entry records:
  - User ID  
  - Role  
  - Action type  
  - Affected entity  
  - IP address  
  - Timestamp  

Supports **accountability**, **repudiation prevention**, and **forensic review**.

---

## Threat Modelling
The system is evaluated using **STRIDE** and **DREAD** threat modelling techniques:

- **Spoofing** → Password hashing and login attempt monitoring  
- **Tampering** → Ownership checks and CSRF protection  
- **Repudiation** → Mandatory audit logging  
- **Information Disclosure** → Row-level access enforcement  
- **Denial of Service** → Login attempt monitoring  
- **Elevation of Privilege** → Strict RBAC enforcement  

Each identified threat is mitigated through **concrete backend controls implemented in code**.

---

## PDPA 2010 Compliance
Backend controls support key PDPA 2010 principles:

- **Security Principle** – Encrypted credentials, secure sessions, RBAC  
- **Disclosure Principle** – Role-based and ownership-based data access  
- **Access Principle** – Users can only access authorized personal data  
- **Accountability** – Audit logs record sensitive operations  

---

## Testing
The system has been tested for:
- Appointment creation  
- Appointment modification  
- Appointment cancellation  
- Role-restricted access validation  
- Audit log generation and integrity  

---

## Notes
This project was developed **for academic purposes** as part of the  
**CCS6344 – Database & Cloud Security** course at **Multimedia University (MMU)**.

---

## Setup Instructions
1. Copy `config.example.php` to `config.php`  
2. Update database credentials and secret values in `config.php`  
3. Deploy the project on a PHP-supported server with MSSQL connectivity  