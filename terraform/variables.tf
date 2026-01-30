variable "region" {
  type    = string
  default = "ap-southeast-5"
}

variable "vpc_id" {
  description = "Existing VPC ID (ccs6344-vpc)"
  type        = string
  default     = "vpc-0ddf51244d8d8fd45"
}

variable "my_ipv4_cidr" {
  description = "Your public IP /32 for SSH restriction (e.g. 180.75.234.229/32)"
  type        = string
}

variable "alb_sg_id" {
  description = "Existing ALB SG id (ccs6344-alb-sg)"
  type        = string
  default     = "sg-07a6c1e63a2d1a15b"
}

variable "app_sg_id" {
  description = "Existing App SG id (ccs6344-app-sg)"
  type        = string
  default     = "sg-0d1bc3b1e5131778b"
}

variable "rds_sg_id" {
  description = "Existing RDS SG id (ccs6344-rds-sg)"
  type        = string
  default     = "sg-0f25239ca134c067f"
}

variable "ecs_sg_id" {
  description = "Existing ECS SG id that currently has 1433 allowed into RDS SG (ecs-clinic). Optional."
  type        = string
  default     = "sg-0c584a5952d8459ab"
}

variable "enable_bak_upload" {
  description = "Whether Terraform should upload the .bak to S3 using aws_s3_object"
  type        = bool
  default     = false
}

variable "enable_windows_db_ec2" {
  description = "Whether Terraform should create the Windows DB EC2 instance"
  type        = bool
  default     = false
}

variable "bak_object_key" {
  description = "S3 object key for the MSSQL .bak file (e.g., backups/db_n_cloudSecurity_assignment_1.bak)"
  type        = string
  default     = "backups/db_n_cloudSecurity_assignment_1.bak"
}

variable "vpc_cidr" {
  description = "VPC CIDR"
  type        = string
  default     = "10.10.0.0/16"
}

variable "s3_bucket_name" {
  description = "S3 bucket name storing the MSSQL .bak file"
  type        = string
}

variable "public_subnet_cidr" {
  description = "Public subnet CIDR"
  type        = string
  default     = "10.10.1.0/24"
}

variable "private_subnet_cidr" {
  description = "Private subnet CIDR (RDS subnet group subnet 1)"
  type        = string
  default     = "10.10.2.0/24"
}

variable "ecs_clinic_sg_id" {
  description = "ECS service security group ID allowed to reach RDS (optional)"
  type        = string
  default     = ""
}

variable "bak_file_path" {
  description = "Local path to .bak file (only used if enable_bak_upload=true)"
  type        = string
  default     = ""
}

variable "db_password" {
  description = "RDS master password"
  type        = string
  sensitive   = true
}

variable "db_username" {
  description = "RDS master username"
  type        = string
  default     = "admin"
}