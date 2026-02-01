# CCS6344 Assignment 2 — Secure AWS Clinic System

This repository documents the completed implementation of a **secure cloud-based clinic appointment system** deployed on **Amazon Web Services (AWS)** for **CCS6344 Assignment 2**.

---

## What Has Been Implemented

### Infrastructure Deployment (Terraform)
- A dedicated **Amazon VPC** was created with public and private subnets.
- **Route tables** and **Internet Gateway** were configured to control inbound/outbound connectivity.
- **Security Groups** were configured to strictly restrict inbound and outbound traffic.
- **Infrastructure as Code (IaC)** was used to provision AWS resources in a consistent and auditable manner.

### Application Layer
- The clinic web application was **containerised using Docker**.
- The Docker container was deployed on an **Amazon EC2 instance**.
- The EC2 instance is not directly exposed to direct public management access beyond required ports.

### Load Balancing & HTTPS
- An **Application Load Balancer (ALB)** was deployed as the public entry point.
- **HTTPS (port 443)** is enabled at the security group and instance levels.
- SSL/TLS is implemented at the application layer using a **self-signed certificate** for demonstration purposes.

### Web Security (WAF & Shield)
- **AWS WAF** was configured and associated with the Application Load Balancer.
- The **AWS Managed Core Rule Set** is enabled to protect against:
  - SQL injection
  - Cross-site scripting (XSS)
  - Malicious request patterns
- **AWS Shield Standard** provides baseline DDoS protection for the ALB.

### Database Layer
- **Amazon RDS (SQL Server Express)** was deployed in private database subnets (RDS subnet group).
- The database is **not publicly accessible**.
- Database access is restricted to the application security group only (port **1433**).
- **Encryption at rest** is enabled using **AWS KMS**.

### Data Backup & Storage
- An **Amazon S3 bucket** was configured for database backup storage.
- The bucket has:
  - **Encryption enabled**
  - **Public access fully blocked**
- An IAM role was created to allow secure SQL Server backup/restore operations.

### Identity & Access Management
- IAM roles and policies follow the **principle of least privilege**.
- Current IAM design:
  - **rds-limited-user** — operational least privilege using a custom policy
  - **admin-readonly-user** — audit/admin visibility using `ReadOnlyAccess`
- EC2 instances use IAM roles rather than long-term credentials.

### Monitoring & Logging
- **AWS CloudTrail** records management and security-related API activities.
- **Amazon CloudWatch** provides infrastructure visibility and monitoring support.
- Logs were reviewed to verify security-relevant events.

### Security Validation
- External **Nmap port scanning** was performed to verify that only expected ports are open.
- AWS WAF dashboards were reviewed to confirm request inspection and rule enforcement.
- Encryption settings for RDS and S3 were verified through AWS console evidence.

---

## DevSecOps Considerations

This project adopts **DevSecOps principles** by integrating security controls throughout the application lifecycle, even though a fully automated CI/CD pipeline was outside the scope of this assignment.

### Security Embedded in the Lifecycle
- **Terraform (IaC)** makes security configurations (VPC isolation, Security Groups, IAM policies, encryption) version-controlled, reviewable, and reproducible.
- **Docker containerisation** reduces configuration drift and supports consistent deployments.
- **Least-privilege IAM roles** are used instead of long-term credentials.

### Manual Security Validation
Security checks were performed manually at key stages:
- Network exposure verified using external **Nmap port scanning**
- **AWS WAF** validation to confirm filtering against common web attacks
- **CloudTrail / CloudWatch** evidence reviewed for security-relevant events
- **RDS and S3 encryption** verified through AWS configuration evidence

### Future Enhancements
The implementation can be extended into a full DevSecOps pipeline:
- CI/CD automation (e.g., **GitHub Actions** or **AWS CodePipeline**)
- Automated container image scanning
- Terraform security checks during deployment
- Continuous vulnerability scanning (e.g., **AWS Inspector**)

---

## Scope Notes
- A single EC2 instance was deployed for demonstration purposes.
- The design supports horizontal scaling through the ALB, but Auto Scaling was not enabled.
- **AWS Certificate Manager (ACM)** integration is proposed as a future enhancement for production-grade TLS.

---

## Purpose of This Repository
This repository serves as:
- Evidence of secure AWS deployment
- Supporting material for the CCS6344 Assignment 2 report
- Documentation of applied cloud security controls
