<?php
$host = getenv('MYSQLHOST');
$db   = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$port = getenv('MYSQLPORT');

echo "HOST: $host <br>";
echo "DB: $db <br>";
echo "USER: $user <br>";
echo "PORT: $port <br>";
