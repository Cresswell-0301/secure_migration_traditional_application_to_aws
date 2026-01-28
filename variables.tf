variable "region" {
  type    = string
  default = "ap-southeast-5"
}

variable "vpc_cidr" {
  type    = string
  default = "10.10.0.0/16"
}

variable "public_subnet_cidr" {
  type    = string
  default = "10.10.1.0/24"
}

variable "private_subnet_cidr" {
  type    = string
  default = "10.10.2.0/24"
}

variable "my_ipv4_cidr" {
  # Put your public IP /32 here for SSH restriction, e.g. "1.2.3.4/32"
  type = string
}

variable "key_name" {
  type = string
}

variable "db_name" {
  type = string
}

variable "db_username" {
  type = string
}

variable "db_password" {
  type      = string
  sensitive = true
}

variable "bak_file_path" {
  description = "Local path to the MSSQL .bak file to upload to S3"
  type        = string
}

variable "bak_object_key" {
  description = "S3 object key (path) for the .bak"
  type        = string
  default     = "backups/db_n_cloudSecurity_assignment_1.bak"
}

variable "s3_bucket_name" {
  description = "S3 bucket name for backups (must be globally unique)"
  type        = string
}
