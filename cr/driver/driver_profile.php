<?php
// driver_profile.php - Driver Profile & Account Settings Management
session_start();
require_once '../db.php';

// Ensure PDO throws exceptions for debugging database issues
if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// Auth Guard: Driver access only (or allow admin review)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['driver', 'admin'])) {
    header('Location: login.php?role=driver');
    exit;
}

$active_page = 'driver_profile';
$flash_message = '';
$flash_type = '';
$driver_id = $_SESSION['user_id'];

// ==========================================
// 1. ACTION HANDLERS (Profile & Password Updates)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Update Personal Info
    if ($action === 'update_profile') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');

        if (!empty($first_name) && !empty($last_name) && !empty($email)) {
            try {
                $check_email = $pdo->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :id");
                $check_email->execute([':email' => $email, ':id' => $driver_id]);
                
                if ($check_email->fetch()) {
                    $flash_message = "Error: Email address is already registered by another account.";
                    $flash_type = "danger";
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email, phone = :phone WHERE user_id = :id");
                    $stmt->execute([
                        ':first_name' => $first_name,
                        ':last_name'  => $last_name,
                        ':email'      => $email,
                        ':phone'      => $phone,
                        ':id'         => $driver_id
                    ]);
                    $flash_message = "Profile details updated successfully.";
                    $flash_type = "success";
                }
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $flash_message = "Failed to update profile: " . $e->getMessage();
                $flash_type = "danger";
            }
        } else {
            $flash_message = "Please fill in all required fields.";
            $flash_type = "warning";
        }
    }

    // Update Password
    if ($action === 'update_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (!empty($current_password) && !empty($new_password) && !empty($confirm_password)) {
            if ($new_password !== $confirm_password) {
                $flash_message = "New passwords do not match.";
                $flash_type = "danger";
            } else {
                try {
                    // Fetch stored password hash using the correct column name 'password_hash'
                    $pwd_stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = :id");
                    $pwd_stmt->execute([':id' => $driver_id]);
                    $driver_data = $pwd_stmt->fetch();

                    $stored_pwd = $driver_data['password_hash'] ?? '';
                    $is_valid = password_verify($current_password, $stored_pwd) || ($current_password === $stored_pwd);

                    if ($is_valid) {
                        $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $upd_pwd = $pdo->prepare("UPDATE users SET password_hash = :password_hash WHERE user_id = :id");
                        $upd_pwd->execute([':password_hash' => $new_hashed_password, ':id' => $driver_id]);
                        
                        $flash_message = "Password updated successfully.";
                        $flash_type = "success";
                    } else {
                        $flash_message = "Incorrect current password.";
                        $flash_type = "danger";
                    }
                } catch (PDOException $e) {
                    error_log($e->getMessage());
                    $flash_message = "Database Error: " . $e->getMessage();
                    $flash_type = "danger";
                }
            }
        } else {
            $flash_message = "Please fill in all password fields.";
            $flash_type = "warning";
        }
    }
}

// ==========================================
// 2. FETCH CURRENT DRIVER DATA
// ==========================================
try {
    $driver_stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :id");
    $driver_stmt->execute([':id' => $driver_id]);
    $current_driver = $driver_stmt->fetch();
} catch (PDOException $e) {
    error_log($e->getMessage());
    die("Database Error while fetching driver data.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Profile | GRLE Driver Portal</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    :root {
        --bg-body: #0d0e12;
        --bg-surface: #16181e;
        --bg-card: #1f222b;
        --border-color: #2e323e;
        --text-primary: #ffffff;
        --text-muted: #c5c6d0;
        --grle-red: #e53935;
        --grle-red-hover: #c62828;
    }

    body {
        font-family: 'Poppins', sans-serif;
        background-color: var(--bg-body);
        color: var(--text-primary);
        min-height: 100vh;
    }

    .navbar-custom {
        background-color: var(--bg-surface);
        border-bottom: 1px solid var(--border-color);
        padding: 15px 0;
    }
    .brand-logo-image { display: block; width: 115px; height: auto; }
    .portal-label { margin-left: 10px; }
    .nav-link.custom-link {
        color: var(--text-muted) !important;
        font-weight: 500;
        padding: 8px 16px;
        margin: 0 5px;
        border-radius: 6px;
        transition: all 0.3s ease;
    }
    .nav-link.custom-link:hover {
        color: var(--text-primary) !important;
        background-color: rgba(255, 255, 255, 0.05);
    }
    .nav-link.custom-link.active {
        color: #fff !important;
        background-color: var(--grle-red);
    }

    .main-content {
        padding: 30px 15px;
    }

    .text-contrast-muted { color: var(--text-muted) !important; }

    .content-panel {
        background-color: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 30px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }

    .form-control, .form-select {
        background-color: var(--bg-surface) !important;
        border: 1px solid var(--border-color) !important;
        color: var(--text-primary) !important;
        padding: 10px 15px;
        border-radius: 8px;
    }
    .form-control:focus, .form-select:focus {
        border-color: var(--grle-red) !important;
        box-shadow: 0 0 0 0.25rem rgba(229, 57, 53, 0.25) !important;
    }
    .form-label {
        color: var(--text-muted);
        font-weight: 500;
        font-size: 0.9rem;
    }

    .btn-grle {
        background-color: var(--grle-red);
        border: none;
        color: white;
        font-weight: 600;
        padding: 10px 20px;
        border-radius: 8px;
        transition: background-color 0.2s ease;
    }
    .btn-grle:hover {
        background-color: var(--grle-red-hover);
        color: white;
    }

    .profile-avatar-box {
        width: 90px;
        height: 90px;
        background-color: var(--grle-red);
        color: white;
        font-size: 2.5rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        box-shadow: 0 4px 10px rgba(0,0,0,0.3);
    }

    @media (max-width: 991.98px) {
        .navbar-nav {
            padding-top: 0.75rem;
        }

        .nav-link.custom-link {
            margin: 0;
            padding: 10px 12px;
        }

        .main-content {
            padding-top: 24px;
        }
    }

    @media (max-width: 575.98px) {
        .container-fluid.px-4,
        .main-content.px-4 {
            padding-left: 1rem !important;
            padding-right: 1rem !important;
        }

        .navbar-brand {
            font-size: 1rem;
        }

        .brand-logo-image {
            width: 90px;
        }

        .content-panel {
            padding: 16px;
        }

        .profile-avatar-box {
            width: 68px;
            height: 68px;
            font-size: 1.8rem;
        }

        .content-panel p {
            overflow-wrap: anywhere;
        }

        .content-panel form .btn-grle {
            width: 100%;
        }
    }
</style>
</head>
<body>

<!-- TOP NAVIGATION BAR -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top">
    <div class="container-fluid px-4">
        <a class="navbar-brand d-flex align-items-center fw-bold text-white" href="driver_dashboard.php">
            <img src="../logo.png" alt="GRLE Car Rental Shop" class="brand-logo-image">
            <span class="portal-label d-none d-sm-inline">Driver Portal</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#driverNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="driverNavbar">
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link custom-link" href="driver_dashboard.php"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link custom-link" href="driver_trips.php"><i class="fas fa-route me-1"></i> My Trips</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link custom-link active" href="driver_profile.php"><i class="fas fa-user-circle me-1"></i> Profile</a>
                </li>
            </ul>
            <div class="d-flex align-items-center">
                <div class="me-3 text-end d-none d-lg-block">
                    <span class="d-block text-white fw-semibold lh-1"><?php echo htmlspecialchars(($current_driver['first_name'] ?? 'Driver') . ' ' . ($current_driver['last_name'] ?? '')); ?></span>
                    <small class="text-contrast-muted" style="font-size: 0.75rem;">Driver ID: #<?php echo $driver_id; ?></small>
                </div>
                <a href="logout.php" class="btn btn-outline-danger btn-sm fw-bold">
                    <i class="fas fa-sign-out-alt me-1"></i> Logout
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- MAIN CONTENT AREA -->
<div class="container-fluid main-content px-4">
    
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h3 class="fw-bold mb-1 text-white">Driver Profile & Security</h3>
            <p class="text-contrast-muted small mb-0">Manage your personal information and update your account password.</p>
        </div>
    </div>

    <!-- Flash Alert Message -->
    <?php if (!empty($flash_message)): ?>
        <div class="alert alert-<?php echo $flash_type; ?> alert-dismissible fade show text-white fw-medium mb-4" role="alert">
            <i class="fas fa-info-circle me-2"></i> <?php echo htmlspecialchars($flash_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- PROFILE HEADER CARD -->
    <div class="content-panel mb-4">
        <div class="d-flex align-items-center gap-4 flex-wrap">
            <div class="profile-avatar-box">
                <?php echo strtoupper(substr($current_driver['first_name'] ?? 'D', 0, 1) . substr($current_driver['last_name'] ?? 'R', 0, 1)); ?>
            </div>
            <div>
                <h4 class="fw-bold text-white mb-1"><?php echo htmlspecialchars(($current_driver['first_name'] ?? '') . ' ' . ($current_driver['last_name'] ?? '')); ?></h4>
                <p class="text-contrast-muted mb-2"><i class="fas id-badge me-1"></i> Driver ID: #<?php echo $driver_id; ?> &nbsp;|&nbsp; <i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($current_driver['email'] ?? ''); ?></p>
                <span class="badge bg-danger text-uppercase px-3 py-1"><i class="fas fa-shield-alt me-1"></i> Role: <?php echo htmlspecialchars($current_driver['role'] ?? 'Driver'); ?></span>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- EDIT PERSONAL INFORMATION FORM -->
        <div class="col-lg-6">
            <div class="content-panel h-100">
                <h5 class="fw-bold text-white mb-4"><i class="fas fa-user-edit text-danger me-2"></i>Personal Information</h5>
                
                <form action="driver_profile.php" method="POST">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($current_driver['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($current_driver['last_name'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($current_driver['email'] ?? ''); ?>" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Phone Number</label>
                        <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($current_driver['phone'] ?? ''); ?>">
                    </div>

                    <button type="submit" class="btn btn-grle w-100">
                        <i class="fas fa-save me-2"></i> Save Profile Changes
                    </button>
                </form>
            </div>
        </div>

        <!-- UPDATE PASSWORD FORM -->
        <div class="col-lg-6">
            <div class="content-panel h-100">
                <h5 class="fw-bold text-white mb-4"><i class="fas fa-lock text-danger me-2"></i>Security & Password</h5>
                
                <form action="driver_profile.php" method="POST">
                    <input type="hidden" name="action" value="update_password">

                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" class="form-control" name="current_password" placeholder="Enter current password" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password" placeholder="Enter new password" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password" placeholder="Confirm new password" required>
                    </div>

                    <button type="submit" class="btn btn-grle w-100">
                        <i class="fas fa-key me-2"></i> Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
