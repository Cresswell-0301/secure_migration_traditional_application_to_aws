output "vpc_id" {
  value = aws_vpc.main.id
}

output "vpc_ipv6_cidr" {
  value = aws_vpc.main.ipv6_cidr_block
}

output "public_subnet_id" {
  value = aws_subnet.public.id
}

output "private_subnet_id" {
  value = aws_subnet.private.id
}

output "app_public_ip" {
  value = aws_instance.app.public_ip
}

output "app_ipv6" {
  value = aws_instance.app.ipv6_addresses
}

output "db_public_ip" {
  value = aws_instance.db.public_ip
}

output "db_ipv6" {
  value = aws_instance.db.ipv6_addresses
}

