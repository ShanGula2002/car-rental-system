<?php
// register.php - Multi-Role Registration with Pending Admin Approval
session_start();
require_once 'db.php';

// Redirect logged-in users away from registration page
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: admin_dashboard.php');
            exit;
        case 'driver':
            header('Location: driver_dashboard.php');
            exit;
        case 'customer':
            header('Location: customer_dashboard.php');
            exit;
    }
}

$error_message = '';
$success_message = '';

// Preserve user input on validation errors
$first_name = '';
$last_name = '';
$email = '';
$phone = '';
$selected_role = 'customer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name    = trim($_POST['first_name'] ?? '');
    $last_name     = trim($_POST['last_name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $role          = trim($_POST['role'] ?? 'customer');
    $password      = $_POST['password'] ?? '';
    $confirm_pw    = $_POST['confirm_password'] ?? '';

    if (in_array($role, ['customer', 'driver', 'admin'])) {
        $selected_role = $role;
    }

    // Input Validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($phone) || empty($password) || empty($confirm_pw)) {
        $error_message = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } elseif ($password !== $confirm_pw) {
        $error_message = "Passwords do not match.";
    } elseif (strlen($password) < 8) {
        $error_message = "Password must be at least 8 characters long.";
    } else {
        try {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            
            if ($stmt->fetch()) {
                $error_message = "An account with this email address already exists.";
            } else {
                // Secure password hashing
                $password_hash = password_hash($password, PASSWORD_BCRYPT);

                // Insert record with DEFAULT 'pending' status
                $insert_stmt = $pdo->prepare("
                    INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status) 
                    VALUES (:role, :first_name, :last_name, :email, :password_hash, :phone, 'pending')
                ");
                
                $insert_stmt->execute([
                    ':role'          => $selected_role,
                    ':first_name'    => $first_name,
                    ':last_name'     => $last_name,
                    ':email'         => $email,
                    ':password_hash' => $password_hash,
                    ':phone'         => $phone
                ]);

                $success_message = "Registration submitted successfully! Your " . ucfirst($selected_role) . " account is pending approval by an Administrator.";
                
                // Reset form inputs
                $first_name = $last_name = $email = $phone = '';
                $selected_role = 'customer';
            }
} catch (PDOException $e) {
    // TEMPORARY DEBUG MODE: Display the real SQL error
    $error_message = "Database Error: " . $e->getMessage();
}
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Registration | GRLE Car Rental Services</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --grle-red: #d32f2f;
            --grle-red-hover: #b71c1c;
            --grle-black: #121212;
            --grle-white: #ffffff;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--grle-black);
            color: var(--grle-white);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
            background: linear-gradient(135deg, rgba(18,18,18,0.95) 0%, rgba(30,30,30,0.9) 100%), url('photo1.jpg') center/cover no-repeat;
        }

        .register-container {
            width: 100%;
            max-width: 580px;
        }

        .register-card {
            background-color: var(--grle-white);
            color: var(--grle-black);
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
            border-top: 5px solid var(--grle-red);
        }

        .brand-logo {
            text-decoration: none;
        }
        .brand-logo-image {
            display: block;
            width: 200px;
            height: auto;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--grle-red);
            box-shadow: 0 0 0 0.25rem rgba(211, 47, 47, 0.25);
        }

        .btn-grle-red {
            background-color: var(--grle-red);
            color: var(--grle-white);
            font-weight: 600;
            padding: 12px;
            border: none;
            transition: background-color 0.3s;
        }
        .btn-grle-red:hover {
            background-color: var(--grle-red-hover);
            color: var(--grle-white);
        }
    </style>
</head>
<body>

<div class="register-container">
    <div class="text-center mb-4">
        <a href="index.php" class="brand-logo">
            <img src="logo.png" alt="GRLE Car Rental Shop" class="brand-logo-image">
        </a>
        <p class="text-light small mt-1">Request System Access Account</p>
    </div>

    <div class="register-card p-4 p-md-5">
        <div class="text-center mb-4">
            <h4 class="fw-bold">Account Registration</h4>
            <p class="text-muted small">Registrations require approval from an Administrator</p>
        </div>

        <!-- Alert Messaging -->
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show text-center py-2 fs-6" role="alert">
                <i class="fas fa-exclamation-circle me-1"></i> <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show text-center py-2 fs-6" role="alert">
                <i class="fas fa-clock me-1"></i> <?php echo htmlspecialchars($success_message); ?>
                <div class="mt-2">
                    <a href="login.php" class="btn btn-sm btn-success text-white fw-bold">Return to Login</a>
                </div>
            </div>
        <?php endif; ?>

        <form action="register.php" method="POST">
            <!-- Account Role Selection -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Register As</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-user-tag text-danger"></i></span>
                    <select name="role" class="form-select" required>
                        <option value="customer" <?php echo ($selected_role === 'customer') ? 'selected' : ''; ?>>Customer (Book Vehicles)</option>
                        <option value="driver" <?php echo ($selected_role === 'driver') ? 'selected' : ''; ?>>Driver (Manage Rides & Routes)</option>
                        <option value="admin" <?php echo ($selected_role === 'admin') ? 'selected' : ''; ?>>Admin (System & Fleet Control)</option>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">First Name</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user text-danger"></i></span>
                        <input type="text" name="first_name" class="form-control" placeholder="John" value="<?php echo htmlspecialchars($first_name); ?>" required>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">Last Name</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user text-danger"></i></span>
                        <input type="text" name="last_name" class="form-control" placeholder="Doe" value="<?php echo htmlspecialchars($last_name); ?>" required>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-envelope text-danger"></i></span>
                    <input type="email" name="email" class="form-control" placeholder="john@example.com" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Phone Number</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-phone text-danger"></i></span>
                    <input type="tel" name="phone" class="form-control" placeholder="+1234567890" value="<?php echo htmlspecialchars($phone); ?>" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock text-danger"></i></span>
                        <input type="password" name="password" class="form-control" placeholder="Min. 8 chars" required>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-check-double text-danger"></i></span>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password" required>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-grle-red w-100 rounded-3 mt-3">
                <i class="fas fa-paper-plane me-2"></i>Submit Registration Request
            </button>
        </form>

        <div class="text-center mt-4">
            <p class="small text-muted mb-0">Already registered? <a href="login.php" class="text-danger fw-bold text-decoration-none">Login Here</a></p>
        </div>
    </div>

    <div class="text-center mt-4">
        <a href="index.php" class="text-light small text-decoration-none"><i class="fas fa-arrow-left me-1"></i> Back to Landing Page</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
