variable "region" {
  type    = string
  default = "ap-southeast-1"
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

