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

variable "rds_limited_user_name" {
  type    = string
  default = "rds-limited-user"
}

variable "admin_readonly_user_name" {
  type    = string
  default = "admin-readonly-user"
}

variable "backup_bucket_name" {
  type = string
}

resource "aws_iam_user" "rds_limited_user" {
  name = var.rds_limited_user_name
}

resource "aws_iam_user" "admin_readonly_user" {
  name = var.admin_readonly_user_name
}

resource "aws_iam_user_policy_attachment" "admin_readonly_attach" {
  user       = aws_iam_user.admin_readonly_user.name
  policy_arn = "arn:aws:iam::aws:policy/ReadOnlyAccess"
}

data "aws_iam_policy_document" "rds_limited_s3" {
  statement {
    sid = "ListBucket"
    actions = [
      "s3:ListBucket"
    ]
    resources = [
      "arn:aws:s3:::${var.backup_bucket_name}"
    ]
  }

  statement {
    sid = "ObjectRW"
    actions = [
      "s3:GetObject",
      "s3:PutObject",
      "s3:DeleteObject"
    ]
    resources = [
      "arn:aws:s3:::${var.backup_bucket_name}/*"
    ]
  }
}

resource "aws_iam_user_policy" "rds_limited_s3_inline" {
  name   = "rds-limited-s3-backup-bucket"
  user   = aws_iam_user.rds_limited_user.name
  policy = data.aws_iam_policy_document.rds_limited_s3.json
}

resource "aws_iam_access_key" "rds_limited_user_key" {
  user = aws_iam_user.rds_limited_user.name
}

resource "aws_iam_access_key" "admin_readonly_user_key" {
  user = aws_iam_user.admin_readonly_user.name
}

variable "key_name" {
  description = "Existing EC2 key pair name"
  type        = string
}

# App EC2
variable "app_instance_type" {
  description = "Instance type for the app EC2"
  type        = string
  default     = "t3.micro"
}

# Optional: if empty, main.tf will auto-pick latest Amazon Linux AMI
variable "app_instance_ami_id" {
  description = "Optional AMI id for app EC2. Leave empty to auto-select latest Amazon Linux 2023."
  type        = string
  default     = ""
}

# Windows DB EC2 (only used when enable_windows_db_ec2 = true)
variable "windows_db_sg_id" {
  description = "Security group id for Windows DB EC2"
  type        = string
}

variable "windows_db_instance_type" {
  description = "Instance type for Windows DB EC2"
  type        = string
  default     = "t3.large"
}

# Optional placeholder. Only required if enable_windows_db_ec2 = true.
variable "windows_db_instance_ami_id" {
  description = "AMI id for Windows DB EC2 (only needed when enable_windows_db_ec2 = true)"
  type        = string
  default     = ""
}

resource "aws_subnet" "public_2" {
  vpc_id                  = aws_vpc.main.id
  cidr_block              = "10.10.4.0/24"
  availability_zone       = data.aws_availability_zones.available.names[1]
  map_public_ip_on_launch = true

  ipv6_cidr_block                 = cidrsubnet(aws_vpc.main.ipv6_cidr_block, 8, 3)
  assign_ipv6_address_on_creation = true

  tags = { Name = "ccs6344-public-subnet-2" }

  lifecycle {
    prevent_destroy = true
  }
}

resource "aws_route_table_association" "public_assoc_2" {
  subnet_id      = aws_subnet.public_2.id
  route_table_id = aws_route_table.public_rt.id
}

variable "alb_name" {
  type        = string
  description = "Existing ALB name (console-created)"
  default     = "ccs6344-alb"
}

variable "waf_name" {
  type        = string
  description = "WAF Web ACL name"
  default     = "ccs6344-clinic-alb-waf"
}
