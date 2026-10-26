<?php
// login.php - Multi-Role Authentication for GRLE Car Rental Services
session_start();
require_once 'db.php';

// Redirect if already logged in (Fixed subfolder paths to prevent redirect loops)
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: admin/admin_dashboard.php');
            exit;
        case 'driver':
            header('Location: driver/driver_dashboard.php');
            exit;
        case 'customer':
            header('Location: customer/customer_dashboard.php');
            exit;
    }
}

$error_message = '';
$success_message = '';

// Determine active tab from GET parameter (default to 'customer')
$active_role = isset($_GET['role']) && in_array($_GET['role'], ['admin', 'customer', 'driver']) ? $_GET['role'] : 'customer';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $login_role = trim($_POST['role'] ?? '');

    // Synchronize active tab with submitted role in case of error
    if (in_array($login_role, ['admin', 'customer', 'driver'])) {
        $active_role = $login_role;
    }

    if (empty($email) || empty($password) || empty($login_role)) {
        $error_message = "Please enter both email and password.";
    } else {
        try {
            // Fetch user by email and matching role
            $stmt = $pdo->prepare("SELECT user_id, role, first_name, last_name, email, password_hash, status FROM users WHERE email = :email AND role = :role LIMIT 1");
            $stmt->execute([
                ':email' => $email,
                ':role' => $login_role
            ]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['status'] !== 'active') {
                    $error_message = "Your account is currently " . htmlspecialchars($user['status']) . ". Please contact support.";
                } else {
                    // Regenerate session ID to prevent session fixation attacks
                    session_regenerate_id(true);

                    // Set Session Data
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                    $_SESSION['email'] = $user['email'];

                    // Redirect based on role
                    switch ($user['role']) {
                        case 'admin':
                            header('Location: admin/admin_dashboard.php');
                            exit;
                        case 'driver':
                            header('Location: driver/driver_dashboard.php');
                            exit;
                        case 'customer':
                            header('Location: customer/customer_dashboard.php');
                            exit;
                    }
                }
            } else {
                $error_message = "Invalid email address or password for the selected portal.";
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error_message = "An unexpected error occurred. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | GRLE Car Rental Services</title>
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
            --grle-dark-gray: #1e1e1e;
            --grle-light-gray: #f8f9fa;
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
            background: linear-gradient(135deg, rgba(18,18,18,0.95) 0%, rgba(30,30,30,0.9) 100%), url('photo1.jpg') center/cover no-repeat;
        }

        .login-container {
            width: 100%;
            max-width: 480px;
            padding: 15px;
        }

        .login-card {
            background-color: var(--grle-white);
            color: var(--grle-black);
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
            border-top: 5px solid var(--grle-red);
            overflow: hidden;
        }

        .brand-logo {
            text-decoration: none;
        }
        .brand-logo-image {
            display: block;
            width: 200px;
            height: auto;
            margin: 0 auto;
        }

        /* Custom Tabs Styling */
        .nav-tabs {
            border-bottom: 2px solid #e0e0e0;
            background-color: #f1f1f1;
        }
        .nav-tabs .nav-link {
            border: none;
            color: #666;
            font-weight: 600;
            padding: 12px;
            border-radius: 0;
            transition: all 0.3s;
        }
        .nav-tabs .nav-link.active {
            color: var(--grle-red);
            background-color: var(--grle-white);
            border-bottom: 3px solid var(--grle-red);
        }
        .nav-tabs .nav-link:hover:not(.active) {
            color: var(--grle-black);
        }

        /* Form Controls */
        .form-control:focus {
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

<div class="login-container">
    <div class="text-center mb-4">
        <a href="index.php" class="brand-logo">
            <img src="logo.png" alt="GRLE Car Rental Shop" class="brand-logo-image">
        </a>
        <p class="text-light small mt-1">Multi-Portal Authentication System</p>
    </div>

    <div class="login-card">
        <!-- Tab Headers -->
        <ul class="nav nav-tabs nav-fill" id="loginTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo ($active_role === 'customer') ? 'active' : ''; ?>" 
                        id="customer-tab" data-bs-toggle="tab" data-bs-target="#customer-pane" type="button" role="tab">
                    <i class="fas fa-user me-1"></i> Customer
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo ($active_role === 'driver') ? 'active' : ''; ?>" 
                        id="driver-tab" data-bs-toggle="tab" data-bs-target="#driver-pane" type="button" role="tab">
                    <i class="fas fa-id-card me-1"></i> Driver
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo ($active_role === 'admin') ? 'active' : ''; ?>" 
                        id="admin-tab" data-bs-toggle="tab" data-bs-target="#admin-pane" type="button" role="tab">
                    <i class="fas fa-user-shield me-1"></i> Admin
                </button>
            </li>
        </ul>

        <div class="p-4">
            <!-- Alert Feedback -->
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show text-center py-2 fs-6" role="alert">
                    <i class="fas fa-exclamation-circle me-1"></i> <?php echo $error_message; ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="tab-content" id="loginTabsContent">
                
                <!-- CUSTOMER LOGIN PANE -->
                <div class="tab-pane fade <?php echo ($active_role === 'customer') ? 'show active' : ''; ?>" id="customer-pane" role="tabpanel">
                    <div class="text-center mb-3">
                        <h5 class="fw-bold">Customer Portal</h5>
                        <p class="text-muted small">Book vehicles and track your assigned driver</p>
                    </div>
                    <form action="login.php" method="POST">
                        <input type="hidden" name="role" value="customer">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-envelope text-danger"></i></span>
                                <input type="email" name="email" class="form-control" placeholder="customer@example.com" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock text-danger"></i></span>
                                <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-grle-red w-100 rounded-3 mt-2">Login as Customer</button>
                    </form>
                    <div class="text-center mt-3">
                        <p class="small text-muted mb-0">Don't have an account? <a href="register.php" class="text-danger fw-bold text-decoration-none">Register Here</a></p>
                    </div>
                </div>

                <!-- DRIVER LOGIN PANE -->
                <div class="tab-pane fade <?php echo ($active_role === 'driver') ? 'show active' : ''; ?>" id="driver-pane" role="tabpanel">
                    <div class="text-center mb-3">
                        <h5 class="fw-bold">Driver Portal</h5>
                        <p class="text-muted small">Access assigned trips and update route locations</p>
                    </div>
                    <form action="login.php" method="POST">
                        <input type="hidden" name="role" value="driver">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Driver Email</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-id-badge text-danger"></i></span>
                                <input type="email" name="email" class="form-control" placeholder="driver@grlecarrental.com" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock text-danger"></i></span>
                                <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-grle-red w-100 rounded-3 mt-2">Login as Driver</button>
                    </form>
                </div>

                <!-- ADMIN LOGIN PANE -->
                <div class="tab-pane fade <?php echo ($active_role === 'admin') ? 'show active' : ''; ?>" id="admin-pane" role="tabpanel">
                    <div class="text-center mb-3">
                        <h5 class="fw-bold">Admin Control Center</h5>
                        <p class="text-muted small">Manage fleet, predictive maintenance, and bookings</p>
                    </div>
                    <form action="login.php" method="POST">
                        <input type="hidden" name="role" value="admin">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Admin Email</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-user-shield text-danger"></i></span>
                                <input type="email" name="email" class="form-control" placeholder="admin@grlecarrental.com" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-key text-danger"></i></span>
                                <input type="password" name="password" class="form-control" placeholder="Enter admin password" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-grle-red w-100 rounded-3 mt-2">Login to Control Panel</button>
                    </form>
                </div>

            </div>
        </div>
    </div>

    <div class="text-center mt-4">
        <a href="index.php" class="text-light small text-decoration-none"><i class="fas fa-arrow-left me-1"></i> Back to Landing Page</a>
    </div>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
