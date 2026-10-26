<?php
// db.php - Database Connection Configuration for GRLE Car Rental Services

$host = '127.0.0.1';
$db   = 'car_rental_db';
$user = 'root';      // Replace with your database username
$pass = '';          // Replace with your database password
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    // Throw exceptions on SQL errors for easier debugging and error handling
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    
    // Fetch associative arrays by default ($row['column_name'])
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    
    // Turn off emulation to use true native prepared statements (protects against SQL injection)
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Log the error message securely in production rather than exposing raw database details
    error_log($e->getMessage());
    die("Database connection failed. Please check your system configuration.");
}