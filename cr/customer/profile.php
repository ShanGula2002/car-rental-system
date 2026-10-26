<?php
// customer/profile.php - Customer Profile Management
session_start();
require_once '../db.php';

// Auth Guard: Customer access only
if (!isset($_SESSION['user_id']) || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')) {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$active_page = 'profile';
$flash_message = '';
$flash_type = '';

// ==========================================
// HANDLE PROFILE & PASSWORD UPDATES
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Update Personal Information
    if ($action === 'update_profile') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');

        if (empty($first_name) || empty($last_name) || empty($email) || empty($phone)) {
            $flash_message = "All personal detail fields are required.";
            $flash_type = "danger";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash_message = "Please enter a valid email address.";
            $flash_type = "danger";
        } else {
            try {
                // Check if email is already taken by another user
                $checkStmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :user_id");
                $checkStmt->execute([':email' => $email, ':user_id' => $user_id]);
                
                if ($checkStmt->rowCount() > 0) {
                    $flash_message = "The email address is already registered by another account.";
                    $flash_type = "danger";
                } else {
                    $updateStmt = $pdo->prepare("
                        UPDATE users 
                        SET first_name = :first_name, 
                            last_name = :last_name, 
                            email = :email, 
                            phone = :phone, 
                            updated_at = CURRENT_TIMESTAMP 
                        WHERE user_id = :user_id
                    ");
                    $updateStmt->execute([
                        ':first_name' => $first_name,
                        ':last_name'  => $last_name,
                        ':email'      => $email,
                        ':phone'      => $phone,
                        ':user_id'    => $user_id
                    ]);

                    $flash_message = "Your profile information has been successfully updated.";
                    $flash_type = "success";
                }
            } catch (PDOException $e) {
                $flash_message = "Database Error: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }

    // 2. Update Password
    if ($action === 'update_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $flash_message = "All password fields are required.";
            $flash_type = "danger";
        } elseif ($new_password !== $confirm_password) {
            $flash_message = "New password and confirmation password do not match.";
            $flash_type = "danger";
        } elseif (strlen($new_password) < 6) {
            $flash_message = "New password must be at least 6 characters long.";
            $flash_type = "danger";
        } else {
            try {
                // Fetch current password hash
                $passStmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = :user_id");
                $passStmt->execute([':user_id' => $user_id]);
                $userData = $passStmt->fetch(PDO::FETCH_ASSOC);

                if ($userData && password_verify($current_password, $userData['password_hash'])) {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

                    $updatePassStmt = $pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = CURRENT_TIMESTAMP WHERE user_id = :user_id");
                    $updatePassStmt->execute([':hash' => $new_hash, ':user_id' => $user_id]);

                    $flash_message = "Your password has been successfully updated.";
                    $flash_type = "success";
                } else {
                    $flash_message = "Incorrect current password provided.";
                    $flash_type = "danger";
                }
            } catch (PDOException $e) {
                $flash_message = "Database Error: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }
}

// ==========================================
// FETCH CURRENT USER DATA
// ==========================================
try {
    $userStmt = $pdo->prepare("SELECT first_name, last_name, email, phone, role, status, created_at FROM users WHERE user_id = :id");
    $userStmt->execute([':id' => $user_id]);
    $customer = $userStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Profile | GRLE Car Rental</title>
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

    /* Top Navbar Styling */
    .navbar-custom {
        background-color: var(--bg-surface);
        border-bottom: 1px solid var(--border-color);
    }
    .navbar-brand {
        font-weight: 700;
        letter-spacing: 0.5px;
    }
    .brand-logo-image {
        display: block;
        width: 115px;
        height: auto;
    }
    .nav-link-custom {
        color: var(--text-muted) !important;
        font-weight: 500;
        padding: 8px 14px !important;
        border-radius: 6px;
        transition: all 0.2s ease-in-out;
    }
    .nav-link-custom:hover {
        color: var(--text-primary) !important;
        background-color: rgba(255, 255, 255, 0.06);
    }
    .nav-link-custom.active {
        color: #ffffff !important;
        background-color: var(--grle-red) !important;
        font-weight: 600;
    }

    /* Utility Classes */
    .text-contrast-muted { color: var(--text-muted) !important; }

    /* Panels & Cards */
    .content-panel {
        background-color: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 24px;
    }

    .profile-avatar-box {
        width: 80px;
        height: 80px;
        background-color: rgba(229, 57, 53, 0.15);
        color: var(--grle-red);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        font-weight: 700;
        border: 2px solid var(--grle-red);
    }

    /* Form Controls Dark Theme */
    .form-control {
        background-color: var(--bg-body) !important;
        border: 1px solid var(--border-color) !important;
        color: var(--text-primary) !important;
    }
    .form-control:focus {
        border-color: var(--grle-red) !important;
        box-shadow: 0 0 0 0.25rem rgba(229, 57, 53, 0.25) !important;
    }

    @media (max-width: 991.98px) {
        .navbar-nav {
            padding-top: 0.75rem;
        }

        .nav-link-custom {
            padding: 10px 12px !important;
        }
    }

    @media (max-width: 575.98px) {
        .container {
            padding-left: 1rem;
            padding-right: 1rem;
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
            font-size: 1.65rem;
        }

        .content-panel .d-flex.justify-content-between {
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 0.35rem;
        }

        .content-panel .d-flex.justify-content-between > span:last-child {
            text-align: right;
            overflow-wrap: anywhere;
        }

        .content-panel .btn {
            width: 100%;
        }
    }
</style>
</head>
<body>

    <!-- TOP NAVIGATION BAR -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center text-white" href="customer_dashboard.php">
                <img src="../logo.png" alt="GRLE Car Rental Shop" class="brand-logo-image">
            </a>

            <button class="navbar-toggler border-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse justify-content-between" id="navbarNav">
                <ul class="navbar-nav mx-auto gap-1">
                    <li class="nav-item">
                        <a href="customer_dashboard.php" class="nav-link nav-link-custom">
                            <i class="fa-solid fa-gauge me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="browse_vehicles.php" class="nav-link nav-link-custom">
                            <i class="fa-solid fa-car me-1"></i> Browse Vehicles
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="my_bookings.php" class="nav-link nav-link-custom">
                            <i class="fa-solid fa-calendar-check me-1"></i> My Reservations
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link nav-link-custom active">
                            <i class="fa-solid fa-user-gear me-1"></i> Account Profile
                        </a>
                    </li>
                </ul>

                <div class="d-flex align-items-center gap-3 mt-3 mt-lg-0">
                    <span class="text-white small fw-semibold">
                        <i class="fa-solid fa-user-circle text-danger me-1"></i> <?= htmlspecialchars(($customer['first_name'] ?? 'Valued') . ' ' . ($customer['last_name'] ?? 'Customer')) ?>
                    </span>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm fw-bold px-3">
                        <i class="fa-solid fa-right-from-bracket me-1"></i> Sign Out
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- MAIN CONTAINER -->
    <div class="container py-4">
        
        <!-- Header Banner -->
        <div class="mb-4 pb-3 border-bottom border-secondary">
            <h3 class="fw-bold mb-1 text-white"><i class="fa-solid fa-user-gear text-danger me-2"></i>Account Profile Settings</h3>
            <p class="text-contrast-muted small mb-0">Update your personal contact details and manage your account password security.</p>
        </div>

        <!-- Flash Alert Message -->
        <?php if (!empty($flash_message)): ?>
            <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show text-white fw-medium mb-4" role="alert">
                <i class="fas fa-info-circle me-2"></i> <?= htmlspecialchars($flash_message) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            
            <!-- Left Column: Summary Card -->
            <div class="col-12 col-lg-4">
                <div class="content-panel text-center h-100 d-flex flex-column align-items-center justify-content-center">
                    <div class="profile-avatar-box mb-3">
                        <?= strtoupper(substr($customer['first_name'] ?? 'C', 0, 1)) ?>
                    </div>
                    <h4 class="fw-bold text-white mb-1"><?= htmlspecialchars(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) ?></h4>
                    <p class="text-contrast-muted small mb-3"><?= htmlspecialchars($customer['email'] ?? '') ?></p>
                    
                    <div class="d-flex gap-2 justify-content-center mb-4">
                        <span class="badge bg-danger text-uppercase px-2 py-1 fs-8"><?= htmlspecialchars($customer['role'] ?? 'customer') ?></span>
                        <span class="badge bg-success text-uppercase px-2 py-1 fs-8"><?= htmlspecialchars($customer['status'] ?? 'active') ?></span>
                    </div>

                    <div class="w-100 pt-3 border-top border-secondary text-start small">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-contrast-muted"><i class="fa-solid fa-phone text-danger me-1"></i> Phone:</span>
                            <span class="fw-semibold text-white"><?= htmlspecialchars($customer['phone'] ?? 'Not set') ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-contrast-muted"><i class="fa-solid fa-calendar-days text-danger me-1"></i> Member Since:</span>
                            <span class="fw-semibold text-white"><?= date('M d, Y', strtotime($customer['created_at'])) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Update Forms -->
            <div class="col-12 col-lg-8">
                
                <!-- 1. Personal Information Form -->
                <div class="content-panel mb-4">
                    <h5 class="fw-bold text-white mb-3"><i class="fa-solid fa-id-card text-danger me-2"></i>Personal Information</h5>
                    
                    <form method="POST" action="profile.php">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="row g-3 mb-3">
                            <div class="col-12 col-sm-6">
                                <label class="form-label small fw-semibold text-contrast-muted">First Name</label>
                                <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($customer['first_name'] ?? '') ?>" required>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label small fw-semibold text-contrast-muted">Last Name</label>
                                <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($customer['last_name'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-12 col-sm-6">
                                <label class="form-label small fw-semibold text-contrast-muted">Email Address</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($customer['email'] ?? '') ?>" required>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label small fw-semibold text-contrast-muted">Phone Number</label>
                                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($customer['phone'] ?? '') ?>" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-danger fw-bold px-4">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                        </button>
                    </form>
                </div>

                <!-- 2. Password Security Form -->
                <div class="content-panel">
                    <h5 class="fw-bold text-white mb-3"><i class="fa-solid fa-lock text-danger me-2"></i>Change Password</h5>
                    
                    <form method="POST" action="profile.php">
                        <input type="hidden" name="action" value="update_password">

                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-contrast-muted">Current Password</label>
                            <input type="password" name="current_password" class="form-control" placeholder="Enter current password..." required>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-12 col-sm-6">
                                <label class="form-label small fw-semibold text-contrast-muted">New Password</label>
                                <input type="password" name="new_password" class="form-control" placeholder="At least 6 characters..." required>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label small fw-semibold text-contrast-muted">Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter new password..." required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-danger fw-bold px-4">
                            <i class="fa-solid fa-key me-1"></i> Update Password
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
