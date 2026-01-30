<?php
$h=getenv("DB_HOST"); $u=getenv("DB_USER"); $p=getenv("DB_PASS");
$conn=sqlsrv_connect($h, ["UID"=>$u,"PWD"=>$p,"Encrypt"=>"yes","TrustServerCertificate"=>"yes","LoginTimeout"=>10]);
if(!$conn){ print_r(sqlsrv_errors()); exit; }
echo "CONNECTED\n";
$stmt=sqlsrv_query($conn, "SELECT name FROM sys.databases");
while($row=sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { echo $row["name"]."\n"; }
