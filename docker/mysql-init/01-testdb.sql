-- Testdatenbank für PHPUnit; läuft nur beim ersten Start des MySQL-Volumes.
CREATE DATABASE IF NOT EXISTS getraenkeliste_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
GRANT ALL PRIVILEGES ON getraenkeliste_test.* TO 'getraenkeuser'@'%';
FLUSH PRIVILEGES;
