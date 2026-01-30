############################################
# Networking: VPC + Subnets + Routes
############################################

resource "aws_vpc" "main" {
  cidr_block                       = var.vpc_cidr
  assign_generated_ipv6_cidr_block = true
  enable_dns_support               = true
  enable_dns_hostnames             = true

  tags = { Name = "ccs6344-vpc" }
}

resource "aws_internet_gateway" "igw" {
  vpc_id = aws_vpc.main.id
  tags   = { Name = "ccs6344-igw" }
}

# For IPv6 outbound from private subnet only (blocks inbound IPv6)
resource "aws_egress_only_internet_gateway" "eigw" {
  vpc_id = aws_vpc.main.id
  tags   = { Name = "ccs6344-eigw" }
}

# Public subnet (EC2 lives here)
resource "aws_subnet" "public" {
  vpc_id                  = aws_vpc.main.id
  cidr_block              = var.public_subnet_cidr
  map_public_ip_on_launch = true

  ipv6_cidr_block                 = cidrsubnet(aws_vpc.main.ipv6_cidr_block, 8, 0)
  assign_ipv6_address_on_creation = true

  tags = { Name = "ccs6344-public-subnet" }
}

# Private subnet 1 (RDS subnet group)
resource "aws_subnet" "private" {
  vpc_id                  = aws_vpc.main.id
  cidr_block              = var.private_subnet_cidr
  map_public_ip_on_launch = false

  ipv6_cidr_block                 = cidrsubnet(aws_vpc.main.ipv6_cidr_block, 8, 1)
  assign_ipv6_address_on_creation = true

  tags = { Name = "ccs6344-private-subnet" }
}

# Route table for public subnet: IPv4 + IPv6 to IGW
resource "aws_route_table" "public_rt" {
  vpc_id = aws_vpc.main.id
  tags   = { Name = "ccs6344-public-rt" }
}

resource "aws_route" "public_ipv4_default" {
  route_table_id         = aws_route_table.public_rt.id
  destination_cidr_block = "0.0.0.0/0"
  gateway_id             = aws_internet_gateway.igw.id
}

resource "aws_route" "public_ipv6_default" {
  route_table_id              = aws_route_table.public_rt.id
  destination_ipv6_cidr_block = "::/0"
  gateway_id                  = aws_internet_gateway.igw.id
}

resource "aws_route_table_association" "public_assoc" {
  subnet_id      = aws_subnet.public.id
  route_table_id = aws_route_table.public_rt.id
}

# Private route table: no IPv4 internet route (no NAT)
# IPv6 outbound only via Egress-Only IGW
resource "aws_route_table" "private_rt" {
  vpc_id = aws_vpc.main.id
  tags   = { Name = "ccs6344-private-rt" }
}

resource "aws_route" "private_ipv6_default" {
  route_table_id              = aws_route_table.private_rt.id
  destination_ipv6_cidr_block = "::/0"
  egress_only_gateway_id      = aws_egress_only_internet_gateway.eigw.id
}

resource "aws_route_table_association" "private_assoc" {
  subnet_id      = aws_subnet.private.id
  route_table_id = aws_route_table.private_rt.id
}

# Second private subnet for RDS subnet group
data "aws_availability_zones" "available" {
  state = "available"
}

resource "aws_subnet" "private_2" {
  vpc_id                  = aws_vpc.main.id
  cidr_block              = "10.10.3.0/24"
  availability_zone       = data.aws_availability_zones.available.names[1]
  map_public_ip_on_launch = false

  ipv6_cidr_block                 = cidrsubnet(aws_vpc.main.ipv6_cidr_block, 8, 2)
  assign_ipv6_address_on_creation = true

  tags = { Name = "ccs6344-private-subnet-2" }
}

resource "aws_route_table_association" "private_assoc_2" {
  subnet_id      = aws_subnet.private_2.id
  route_table_id = aws_route_table.private_rt.id
}

############################################
# Security Groups
############################################

# EC2 app SG: 80/443 public, SSH restricted
resource "aws_security_group" "ccs6344_app_sg" {
  name        = "ccs6344-app-sg"
  description = "Allow HTTP/HTTPS; SSH only from my IP"
  vpc_id      = aws_vpc.main.id

  # HTTP IPv4
  ingress {
    protocol    = "tcp"
    from_port   = 80
    to_port     = 80
    cidr_blocks = ["0.0.0.0/0"]
  }

  # HTTP IPv6
  ingress {
    protocol         = "tcp"
    from_port        = 80
    to_port          = 80
    ipv6_cidr_blocks = ["::/0"]
  }

  # HTTPS IPv4
  ingress {
    protocol    = "tcp"
    from_port   = 443
    to_port     = 443
    cidr_blocks = ["0.0.0.0/0"]
  }

  # HTTPS IPv6
  ingress {
    protocol         = "tcp"
    from_port        = 443
    to_port          = 443
    ipv6_cidr_blocks = ["::/0"]
  }

  # SSH (restricted)
  ingress {
    protocol    = "tcp"
    from_port   = 22
    to_port     = 22
    cidr_blocks = ["180.75.234.229/32"]
  }

  # HTTP from ALB SG
  ingress {
    protocol        = "tcp"
    from_port       = 80
    to_port         = 80
    security_groups = [aws_security_group.ccs6344_alb_sg.id]
  }

  # Allow all outbound IPv4 + IPv6
  egress {
    protocol         = "-1"
    from_port        = 0
    to_port          = 0
    cidr_blocks      = ["0.0.0.0/0"]
    ipv6_cidr_blocks = ["::/0"]
  }

  tags = {
    Name = "ccs6344-app-sg"
  }
}

# RDS SG: only allow 1433 from app SG
resource "aws_security_group" "ccs6344_rds_sg" {
  name        = "ccs6344-rds-sg"
  description = "RDS MSSQL only from app SG"
  vpc_id      = aws_vpc.main.id

  # MSSQL from app SG
  ingress {
    protocol        = "tcp"
    from_port       = 1433
    to_port         = 1433
    security_groups = [aws_security_group.ccs6344_app_sg.id]
  }

  # MSSQL from ECS service SG
  ingress {
    protocol        = "tcp"
    from_port       = 1433
    to_port         = 1433
    security_groups = var.ecs_clinic_sg_id != "" ? [var.ecs_clinic_sg_id] : []
  }

  egress {
    protocol    = "-1"
    from_port   = 0
    to_port     = 0
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "ccs6344-rds-sg"
  }
}

resource "aws_security_group" "ccs6344_alb_sg" {
  name        = "ccs6344-alb-sg"
  description = "ALB public ingress"
  vpc_id      = aws_vpc.main.id

  ingress {
    protocol    = "tcp"
    from_port   = 80
    to_port     = 80
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    protocol         = "tcp"
    from_port        = 80
    to_port          = 80
    ipv6_cidr_blocks = ["::/0"]
  }

  ingress {
    protocol    = "tcp"
    from_port   = 443
    to_port     = 443
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    protocol         = "tcp"
    from_port        = 443
    to_port          = 443
    ipv6_cidr_blocks = ["::/0"]
  }

  egress {
    protocol         = "-1"
    from_port        = 0
    to_port          = 0
    cidr_blocks      = ["0.0.0.0/0"]
    ipv6_cidr_blocks = ["::/0"]
  }

  tags = {
    Name = "ccs6344-alb-sg"
  }
}

resource "aws_security_group" "ccs6344_db_sg" {
  name        = "ccs6344-db-sg"
  description = "DB only from app SG"
  vpc_id      = aws_vpc.main.id

  ingress {
    protocol        = "tcp"
    from_port       = 1433
    to_port         = 1433
    security_groups = [aws_security_group.ccs6344_app_sg.id]
  }

  ingress {
    protocol    = "tcp"
    from_port   = 3389
    to_port     = 3389
    cidr_blocks = ["60.50.34.86/32"]
  }

  ingress {
    protocol    = "tcp"
    from_port   = 22
    to_port     = 22
    cidr_blocks = ["180.75.234.229/32"]
  }

  egress {
    protocol    = "-1"
    from_port   = 0
    to_port     = 0
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "ccs6344-db-sg"
  }
}

############################################
# EC2 Instance (Docker)
############################################

data "aws_ami" "amazon_linux" {
  most_recent = true
  owners      = ["amazon"]

  filter {
    name   = "name"
    values = ["al2023-ami-*-x86_64"]
  }
}

resource "aws_instance" "app" {
  ami                         = var.app_instance_ami_id
  instance_type               = var.app_instance_type
  subnet_id                   = aws_subnet.public.id
  vpc_security_group_ids      = [aws_security_group.ccs6344_app_sg.id]
  key_name                    = var.key_name
  associate_public_ip_address = true
  ipv6_address_count          = 1

  metadata_options {
    http_tokens = "required" # matches your AWS: IMDSv2 Required
  }

  iam_instance_profile = aws_iam_instance_profile.ec2_ecr_push_profile.name

  tags = { Name = "ccs6344-app" }
}

############################################
# RDS SQL Server + Backup/Restore Option Group
############################################

resource "aws_db_subnet_group" "db_subnets" {
  name       = "ccs6344-db-subnets"
  subnet_ids = [aws_subnet.private.id, aws_subnet.private_2.id]
  tags       = { Name = "ccs6344-db-subnets" }
}

# IAM role for RDS restore from S3
resource "aws_iam_role" "rds_backup_restore_role" {
  name = "rds-sqlserver-backup-restore-role"

  assume_role_policy = jsonencode({
    Version = "2012-10-17",
    Statement = [{
      Effect    = "Allow",
      Principal = { Service = "rds.amazonaws.com" },
      Action    = "sts:AssumeRole"
    }]
  })
}

# IMPORTANT: includes GetBucketLocation (your earlier failure)
resource "aws_iam_role_policy" "rds_backup_restore_s3_policy" {
  name = "rds-sqlserver-backup-restore-policy"
  role = aws_iam_role.rds_backup_restore_role.id

  policy = jsonencode({
    Version = "2012-10-17",
    Statement = [
      {
        Effect = "Allow",
        Action = [
          "s3:GetBucketLocation",
          "s3:ListBucket"
        ],
        Resource = "arn:aws:s3:::${var.s3_bucket_name}"
      },
      {
        Effect = "Allow",
        Action = [
          "s3:GetObject"
        ],
        Resource = "arn:aws:s3:::${var.s3_bucket_name}/${var.bak_object_key}"
      }
    ]
  })
}

resource "aws_db_option_group" "mssql_backup_restore" {
  name                 = "mssql-to-aws-s3"
  engine_name          = "sqlserver-ex"
  major_engine_version = "15.00"

  option {
    option_name = "SQLSERVER_BACKUP_RESTORE"

    option_settings {
      name  = "IAM_ROLE_ARN"
      value = aws_iam_role.rds_backup_restore_role.arn
    }
  }

  tags = { Name = "mssql-to-aws-s3" }
}

resource "aws_db_instance" "mssql" {
  identifier = "ccs6344-mssql-rds"

  engine         = "sqlserver-ex"
  engine_version = "15.00.4455.2.v1"   # matches console

  instance_class = "db.t3.micro"       # matches console

  allocated_storage     = 20           # matches console
  max_allocated_storage = 50           # matches console autoscaling limit
  storage_type          = "gp2"        # matches console
  storage_encrypted     = true         # matches console (KMS key shows aws/rds)

  # keep default KMS key (aws/rds) by NOT setting kms_key_id
  # kms_key_id = null

  username = "admin"                  # matches console (or var.db_username)
  password = var.db_password          # keep sensitive in tfvars

  db_subnet_group_name   = aws_db_subnet_group.db_subnets.name
  vpc_security_group_ids = [aws_security_group.ccs6344_rds_sg.id]

  option_group_name = aws_db_option_group.mssql_backup_restore.name  # mssql-to-aws-s3

  publicly_accessible = false         # you used private subnets; keep this
  multi_az            = false         # SQL Server Express / console shows N/A

  deletion_protection = false         # matches console disabled

  # Your earlier settings (keep unless your assignment requires otherwise)
  backup_retention_period  = 0
  delete_automated_backups = true

  skip_final_snapshot = true

  tags = { Name = "ccs6344-mssql-rds" }
}

############################################
# S3 Bucket for .bak
############################################

resource "aws_s3_bucket" "backup" {
  bucket = var.s3_bucket_name
  tags   = { Name = "ccs6344-backup-bucket" }
}

resource "aws_s3_bucket_public_access_block" "backup" {
  bucket                  = aws_s3_bucket.backup.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_server_side_encryption_configuration" "backup" {
  bucket = aws_s3_bucket.backup.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_object" "mssql_bak" {
  count  = var.enable_bak_upload ? 1 : 0
  bucket = aws_s3_bucket.backup.id
  key    = var.bak_object_key
  source = var.bak_file_path
  etag   = filemd5(var.bak_file_path)

  content_type = "application/octet-stream"

  depends_on = [
    aws_s3_bucket_server_side_encryption_configuration.backup,
    aws_s3_bucket_public_access_block.backup
  ]
}

resource "aws_ecr_repository" "clinic_repo" {
  name = "ccs6344-clinic"

  image_scanning_configuration {
    scan_on_push = true
  }
}

resource "aws_iam_instance_profile" "ec2_ecr_push_profile" {
  name = "ec2-ecr-push-role"
  role = "ec2-ecr-push-role"
}

resource "aws_instance" "windows_db" {
  count = var.enable_windows_db_ec2 ? 1 : 0

  ami                         = var.windows_db_instance_ami_id
  instance_type               = var.windows_db_instance_type
  subnet_id                   = aws_subnet.public.id
  vpc_security_group_ids      = [var.windows_db_sg_id]
  key_name                    = var.key_name
  associate_public_ip_address = true
  ipv6_address_count          = 1

  metadata_options {
    http_tokens = "optional" # matches your AWS: IMDSv2 Optional
  }

  iam_instance_profile = aws_iam_instance_profile.ec2_ecr_push_profile.name

  tags = { Name = "ccs6344-mssql-db" }
}

resource "aws_iam_role" "rds_backup_restore_role" {
  name = "rds-sqlserver-backup-restore-role"

  assume_role_policy = jsonencode({
    Version = "2012-10-17",
    Statement = [{
      Effect    = "Allow",
      Principal = { Service = "rds.amazonaws.com" },
      Action    = "sts:AssumeRole"
    }]
  })
}

variable "s3_bucket_name" {
  type        = string
  description = "S3 bucket for MSSQL .bak"
}

variable "bak_object_key" {
  type        = string
  description = "Object key of the .bak file in S3 (e.g. backups/db_n_cloudSecurity_assignment_1.bak)"
}