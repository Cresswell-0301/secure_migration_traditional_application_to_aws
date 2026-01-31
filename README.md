# CCS6344 Assignment 2 — Secure AWS Clinic System

This repository documents the completed implementation of a **secure cloud-based clinic appointment system** deployed on **Amazon Web Services (AWS)** for CCS6344 Assignment 2.

## What Has Been Implemented

### Infrastructure Deployment (Terraform)
- A dedicated **Amazon VPC** was created with public and private subnets.
- **Security Groups** were configured to strictly restrict inbound and outbound traffic.
- **Infrastructure as Code (IaC)** was used to provision AWS resources in a consistent and auditable manner.

### Application Layer
- The clinic web application was **containerised using Docker**.
- The Docker container was deployed on an **Amazon EC2 instance**.
- The EC2 instance is not directly exposed to the internet and only accepts traffic via the load balancer.

### Load Balancing & HTTPS
- An **Application Load Balancer (ALB)** was deployed as the public entry point.
- **HTTPS (port 443)** was enabled at the security group and instance levels.
- SSL/TLS was implemented at the application layer using a **self-signed certificate** for demonstration purposes.

### Web Security
- **AWS WAF** was configured and associated with the Application Load Balancer.
- The AWS Managed Core Rule Set is enabled to protect against:
  - SQL injection
  - Cross-site scripting (XSS)
  - Malicious request patterns
- **AWS Shield Standard** is active to provide baseline DDoS protection for the ALB.

### Database Layer
- **Amazon RDS (SQL Server Express)** was deployed in a private subnet.
- The database is **not publicly accessible**.
- Database access is restricted to the application security group only.
- **Encryption at rest** is enabled using **AWS KMS**.

### Data Backup & Storage
- An **Amazon S3 bucket** was configured for database backup storage.
- The bucket has:
  - Encryption enabled
  - Public access fully blocked
- An IAM role was created to allow secure SQL Server backup and restore operations.

### Identity & Access Management
- IAM roles and policies were implemented following the **principle of least privilege**.
- The design includes:
  - A restricted RDS operational role
  - A read-only administrative/audit role
- EC2 instances use an IAM role instead of long-term credentials.

### Monitoring & Logging
- **AWS CloudTrail** records all management and security-related API activities.
- **Amazon CloudWatch** provides visibility into infrastructure and application behaviour.
- Logs were reviewed to verify security-relevant events.

### Security Validation
- External **Nmap port scanning** was performed to verify that only expected ports are open.
- AWS WAF dashboards were reviewed to confirm request inspection and rule enforcement.
- Encryption settings for RDS and S3 were verified through AWS console evidence.

## Scope Notes
- A single EC2 instance was deployed for demonstration purposes.
- The architecture supports horizontal scaling through the ALB, but Auto Scaling was not enabled.
- AWS Certificate Manager (ACM) was not used; production-grade TLS is proposed as a future enhancement.

## Purpose of This Repository
This repository serves as:
- Evidence of secure AWS deployment
- Supporting material for the CCS6344 Assignment 2 report
- Documentation of applied cloud security controls
