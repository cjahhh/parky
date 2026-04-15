<?php
$host = getenv('MYSQLHOST');
$db   = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$port = getenv('MYSQLPORT');

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$sql = "
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `admin_users` (`id`, `name`, `email`, `username`, `password`, `last_login`, `created_at`) VALUES
(2, 'Admin', 'admin@parky.com', 'admin', '\$2y\$10\$g2ho../XrROiIM.PzqVJ.el43.yv3XXNeoPj6bl.HcstxDLQhT92C', '2026-04-14 08:45:05', '2026-04-05 02:20:06');

CREATE TABLE IF NOT EXISTS `parking_slots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slot_code` varchar(10) NOT NULL,
  `floor` tinyint(4) NOT NULL,
  `status` enum('available','occupied','reserved','maintenance') DEFAULT 'available',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slot_code` (`slot_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `plate_number` varchar(20) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `is_verified` tinyint(1) DEFAULT 0,
  `verification_token` varchar(255) DEFAULT NULL,
  `verification_expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `plate_number` (`plate_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `parking_slots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slot_code` varchar(10) NOT NULL,
  `floor` tinyint(4) NOT NULL,
  `status` enum('available','occupied','reserved','maintenance') DEFAULT 'available',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slot_code` (`slot_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reservations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `slot_id` int(11) NOT NULL,
  `plate_number` varchar(20) NOT NULL,
  `car_type` varchar(50) NOT NULL DEFAULT '',
  `car_color` varchar(50) NOT NULL DEFAULT '',
  `reserved_at` datetime DEFAULT current_timestamp(),
  `arrival_time` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `extended` tinyint(1) DEFAULT 0,
  `extension_fee` decimal(8,2) DEFAULT 0.00,
  `payment_status` enum('unpaid','paid') DEFAULT 'unpaid',
  `paid_until` datetime DEFAULT NULL,
  `status` enum('pending','confirmed','active','completed','expired','cancelled') DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `slot_id` (`slot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions_walkin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slot_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `plate_number` varchar(20) NOT NULL,
  `car_type` varchar(50) DEFAULT NULL,
  `car_color` varchar(50) DEFAULT NULL,
  `checked_in_at` datetime DEFAULT current_timestamp(),
  `checked_out_at` datetime DEFAULT NULL,
  `status` enum('active','completed') DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `slot_id` (`slot_id`),
  KEY `sessions_walkin_ibfk_2` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `session_type` enum('walkin','reservation') NOT NULL,
  `base_amount` decimal(8,2) NOT NULL,
  `extension_fee` decimal(8,2) DEFAULT 0.00,
  `total_amount` decimal(8,2) NOT NULL,
  `method` enum('cash','card','gcash','maya') NOT NULL,
  `source` enum('online','kiosk') NOT NULL DEFAULT 'kiosk',
  `receipt_number` varchar(20) NOT NULL,
  `paid_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `receipt_number` (`receipt_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

$statements = array_filter(array_map('trim', explode(';', $sql)));
$errors = [];
$success = 0;

foreach ($statements as $statement) {
    if (empty($statement)) continue;
    try {
        $pdo->exec($statement);
        $success++;
    } catch (PDOException $e) {
        $errors[] = $e->getMessage();
    }
}

// Insert parking slots
try {
    $pdo->exec("INSERT IGNORE INTO `parking_slots` (`id`, `slot_code`, `floor`, `status`) VALUES
    (1,'1A1',1,'occupied'),(2,'1A2',1,'maintenance'),(3,'1A3',1,'reserved'),(4,'1A4',1,'available'),
    (5,'1A5',1,'reserved'),(6,'1A6',1,'available'),(7,'1A7',1,'available'),(8,'1A8',1,'available'),
    (9,'1A9',1,'available'),(10,'1A10',1,'maintenance'),(11,'1B1',1,'available'),(12,'1B2',1,'available'),
    (13,'1B3',1,'available'),(14,'1B4',1,'available'),(15,'1B5',1,'available'),(16,'1B6',1,'available'),
    (17,'1B7',1,'available'),(18,'1B8',1,'available'),(19,'1B9',1,'available'),(20,'1B10',1,'available'),
    (21,'1C1',1,'available'),(22,'1C2',1,'available'),(23,'1C3',1,'available'),(24,'1C4',1,'available'),
    (25,'1C5',1,'available'),(26,'1C6',1,'available'),(27,'1C7',1,'available'),(28,'1C8',1,'available'),
    (29,'1C9',1,'available'),(30,'1C10',1,'available'),(31,'2A1',2,'available'),(32,'2A2',2,'available'),
    (33,'2A3',2,'available'),(34,'2A4',2,'available'),(35,'2A5',2,'available'),(36,'2A6',2,'available'),
    (37,'2A7',2,'available'),(38,'2A8',2,'available'),(39,'2A9',2,'available'),(40,'2A10',2,'maintenance'),
    (41,'2B1',2,'available'),(42,'2B2',2,'available'),(43,'2B3',2,'available'),(44,'2B4',2,'available'),
    (45,'2B5',2,'available'),(46,'2B6',2,'available'),(47,'2B7',2,'available'),(48,'2B8',2,'available'),
    (49,'2B9',2,'available'),(50,'2B10',2,'available'),(51,'2C1',2,'available'),(52,'2C2',2,'available'),
    (53,'2C3',2,'available'),(54,'2C4',2,'available'),(55,'2C5',2,'available'),(56,'2C6',2,'available'),
    (57,'2C7',2,'available'),(58,'2C8',2,'available'),(59,'2C9',2,'available'),(60,'2C10',2,'available'),
    (61,'3A1',3,'available'),(62,'3A2',3,'available'),(63,'3A3',3,'available'),(64,'3A4',3,'available'),
    (65,'3A5',3,'available'),(66,'3A6',3,'available'),(67,'3A7',3,'available'),(68,'3A8',3,'available'),
    (69,'3A9',3,'available'),(70,'3A10',3,'maintenance'),(71,'3B1',3,'available'),(72,'3B2',3,'available'),
    (73,'3B3',3,'available'),(74,'3B4',3,'available'),(75,'3B5',3,'available'),(76,'3B6',3,'available'),
    (77,'3B7',3,'available'),(78,'3B8',3,'available'),(79,'3B9',3,'available'),(80,'3B10',3,'available'),
    (81,'3C1',3,'available'),(82,'3C2',3,'available'),(83,'3C3',3,'available'),(84,'3C4',3,'available'),
    (85,'3C5',3,'available'),(86,'3C6',3,'available'),(87,'3C7',3,'available'),(88,'3C8',3,'available'),
    (89,'3C9',3,'available'),(90,'3C10',3,'available')");
    $success++;
} catch (PDOException $e) {
    $errors[] = "Slots: " . $e->getMessage();
}

// Insert users
try {
    $pdo->exec("INSERT IGNORE INTO `users` (`id`,`first_name`,`last_name`,`username`,`email`,`password`,`phone`,`plate_number`,`created_at`,`is_verified`) VALUES
    (2,'Karl','Howard','karlward','cascayok@gmail.com','\$2y\$10\$gi9ueDlxbtJKj6f91fxXJuz.UWTAPfiKp1Vr5GnWKqsbpJtNQjffm','0919 191 1919','NGP3944','2026-04-13 13:20:49',1),
    (4,'Lebron','James','king','cj1009garcia@gmail.com','\$2y\$10\$Ag46Qef2qOSJNf.NdNbDIuzYETxkkWH/uoPaSvvvaHvEjnTn8pbdC','','NDS 123','2026-04-14 01:15:39',1),
    (5,'Christian','Garcia','cjahhh','mcjgarcia1@tip.edu.ph','\$2y\$10\$cQZd2QM7ljIIsksILDTnu.HQ908HUb0q9eZBUwCJbhdGKryGu46Ty','09564938354','DBA4658','2026-04-14 02:17:29',1)");
    $success++;
} catch (PDOException $e) {
    $errors[] = "Users: " . $e->getMessage();
}

// Add foreign keys
try {
    $pdo->exec("ALTER TABLE `reservations`
        ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
        ADD CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`slot_id`) REFERENCES `parking_slots` (`id`)");
} catch (PDOException $e) {
    // ignore if already exists
}

try {
    $pdo->exec("ALTER TABLE `sessions_walkin`
        ADD CONSTRAINT `sessions_walkin_ibfk_1` FOREIGN KEY (`slot_id`) REFERENCES `parking_slots` (`id`),
        ADD CONSTRAINT `sessions_walkin_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL");
} catch (PDOException $e) {
    // ignore if already exists
}

echo "<h2>Import Complete!</h2>";
echo "<p>✅ $success statements ran successfully.</p>";
if ($errors) {
    echo "<p>⚠️ Errors (may be harmless if tables already exist):</p><ul>";
    foreach ($errors as $e) echo "<li>$e</li>";
    echo "</ul>";
}
echo "<p><strong>🗑️ DELETE this file (import_db.php) from your project now!</strong></p>";
?>
