<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$email = getenv('ADMIN_EMAIL') ?: '';
$password = getenv('ADMIN_PASSWORD') ?: '';
$phone = getenv('ADMIN_PHONE') ?: '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || $phone === '') {
    fwrite(STDERR, "Set ADMIN_EMAIL, ADMIN_PASSWORD (at least 12 characters), and ADMIN_PHONE.\n");
    exit(1);
}

require_once __DIR__ . '/db.php';

try {
    $stmt = $pdo->prepare("
        INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status)
        VALUES ('admin', 'System', 'Admin', :email, :password_hash, :phone, 'active')
    ");

    $stmt->execute([
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':phone' => $phone,
    ]);

    fwrite(STDOUT, "Admin account created.\n");
} catch (PDOException $e) {
    fwrite(STDERR, "Admin account creation failed: {$e->getMessage()}\n");
    exit(1);
}