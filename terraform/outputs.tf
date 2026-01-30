output "app_public_ip" {
  value = aws_instance.app.public_ip
}

output "app_ipv6" {
  value = aws_instance.app.ipv6_addresses
}

output "rds_endpoint" {
  value = aws_db_instance.mssql.endpoint
}

output "rds_option_group" {
  value = aws_db_option_group.mssql_backup_restore.name
}

output "rds_restore_role_arn" {
  value = aws_iam_role.rds_backup_restore_role.arn
}

output "backup_bucket_name" {
  value = aws_s3_bucket.backup.bucket
}

output "bak_s3_arn" {
  value = "arn:aws:s3:::${aws_s3_bucket.backup.bucket}/${var.bak_object_key}"
}

output "ecr_repo_url" {
  value = aws_ecr_repository.clinic_repo.repository_url
}

output "app_sg_id" {
  value = var.app_sg_id
}

output "rds_sg_id" {
  value = var.rds_sg_id
}

output "alb_sg_id" {
  value = var.alb_sg_id
}
