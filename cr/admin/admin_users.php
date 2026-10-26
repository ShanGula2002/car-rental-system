<?php
// admin_users.php - User Management & Approvals for GRLE Car Rental
session_start();
require_once '../db.php';

// Auth Guard: Admin access only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php?role=admin');
    exit;
}

$active_page = 'users';
$flash_message = '';
$flash_type = '';

// ==========================================
// 1. DYNAMIC ACTION HANDLERS (POST REQUESTS)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ACTION: Add New User Directly (e.g. Create a Driver or Admin)
    if ($_POST['action'] === 'add_user') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $password   = $_POST['password'] ?? '';
        $role       = $_POST['role'] ?? 'customer';
        $status     = $_POST['status'] ?? 'active';

        if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
            $flash_message = "All required fields must be filled out.";
            $flash_type = "danger";
        } else {
            try {
                // Check if email exists
                $check_stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email LIMIT 1");
                $check_stmt->execute([':email' => $email]);
                
                if ($check_stmt->fetch()) {
                    $flash_message = "Email address is already registered.";
                    $flash_type = "danger";
                } else {
                    $password_hash = password_hash($password, PASSWORD_BCRYPT);
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO users (role, first_name, last_name, email, password_hash, phone, status)
                        VALUES (:role, :first_name, :last_name, :email, :password_hash, :phone, :status)
                    ");
                    $insert_stmt->execute([
                        ':role'          => $role,
                        ':first_name'     => $first_name,
                        ':last_name'      => $last_name,
                        ':email'          => $email,
                        ':password_hash' => $password_hash,
                        ':phone'          => $phone,
                        ':status'         => $status
                    ]);

                    $flash_message = "New " . ucfirst($role) . " account created successfully!";
                    $flash_type = "success";
                }
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $flash_message = "Failed to create user account.";
                $flash_type = "danger";
            }
        }
    }

    // ACTION: Update User Status (Approve, Ban, Deactivate, Activate)
    if ($_POST['action'] === 'update_user_status') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $new_status     = $_POST['status'] ?? '';

        if ($target_user_id > 0 && in_array($new_status, ['pending', 'active', 'inactive', 'banned'])) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET status = :status WHERE user_id = :id");
                $stmt->execute([':status' => $new_status, ':id' => $target_user_id]);

                $flash_message = "User account #" . $target_user_id . " status changed to '" . ucfirst($new_status) . "'.";
                $flash_type = "info";
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $flash_message = "Failed to update user status.";
                $flash_type = "danger";
            }
        }
    }

    // ACTION: Delete User Account
    if ($_POST['action'] === 'delete_user') {
        $target_user_id = intval($_POST['user_id'] ?? 0);

        // Prevent self-deletion
        if ($target_user_id === intval($_SESSION['user_id'])) {
            $flash_message = "You cannot delete your own logged-in admin account.";
            $flash_type = "danger";
        } elseif ($target_user_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = :id");
                $stmt->execute([':id' => $target_user_id]);

                $flash_message = "User account #" . $target_user_id . " permanently removed.";
                $flash_type = "warning";
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $flash_message = "Failed to delete user account.";
                $flash_type = "danger";
            }
        }
    }
}

// ==========================================
// 2. SEARCH & FILTERING LOGIC
// ==========================================
$filter_role   = isset($_GET['role']) && in_array($_GET['role'], ['admin', 'driver', 'customer']) ? $_GET['role'] : 'all';
$filter_status = isset($_GET['status']) && in_array($_GET['status'], ['pending', 'active', 'inactive', 'banned']) ? $_GET['status'] : 'all';
$search_query  = trim($_GET['search'] ?? '');

$query_sql = "SELECT user_id, role, first_name, last_name, email, phone, status, created_at FROM users WHERE 1=1";
$params = [];

if ($filter_role !== 'all') {
    $query_sql .= " AND role = :role";
    $params[':role'] = $filter_role;
}

if ($filter_status !== 'all') {
    $query_sql .= " AND status = :status";
    $params[':status'] = $filter_status;
}

if (!empty($search_query)) {
    $query_sql .= " AND (first_name LIKE :search_first OR last_name LIKE :search_last OR email LIKE :search_email OR phone LIKE :search_phone)";
    $params[':search_first'] = '%' . $search_query . '%';
    $params[':search_last'] = '%' . $search_query . '%';
    $params[':search_email'] = '%' . $search_query . '%';
    $params[':search_phone'] = '%' . $search_query . '%';
}

$query_sql .= " ORDER BY created_at DESC";

try {
    $stmt = $pdo->prepare($query_sql);
    $stmt->execute($params);
    $users_list = $stmt->fetchAll();

    // Summary Statistics Counters
    $count_total    = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $count_pending  = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
    $count_drivers  = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver' AND status = 'active'")->fetchColumn();
    $count_customers= $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND status = 'active'")->fetchColumn();

} catch (PDOException $e) {
    error_log($e->getMessage());
    die("Database Query Failed.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Accounts Management | GRLE Car Rental</title>
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

        /* Layout Grid */
        .admin-layout {
            display: flex;
            min-height: 100vh;
        }
        .sidebar-wrapper {
            width: 260px;
            min-width: 260px;
            border-right: 1px solid var(--border-color);
            background-color: var(--bg-surface);
        }
        .main-content {
            flex-grow: 1;
            background-color: var(--bg-body);
            overflow-x: hidden;
        }

        /* Sidebar Button High Contrast */
        .sidebar-btn {
            color: var(--text-muted) !important;
            font-weight: 500;
            padding: 11px 16px;
            border-radius: 8px;
            transition: all 0.2s ease-in-out;
        }
        .sidebar-btn:hover {
            color: var(--text-primary) !important;
            background-color: rgba(255, 255, 255, 0.08);
        }
        .sidebar-btn.active {
            color: #ffffff !important;
            background-color: var(--grle-red) !important;
            font-weight: 600;
        }

        /* Utilities */
        .bg-dark-surface { background-color: var(--bg-surface) !important; }
        .bg-dark-card { background-color: var(--bg-card) !important; }
        .text-contrast-muted { color: var(--text-muted) !important; }

        .sidebar-link-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            gap: 12px;
        }
        .sidebar-link-label {
            display: inline-flex;
            align-items: center;
        }
        .sidebar-alert-stack {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .sidebar-inline-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
            line-height: 1;
            padding: 0;
        }

        .avatar-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: rgba(229, 57, 53, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--grle-red);
            border-radius: 10px;
            padding: 20px;
        }

        .content-panel {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 22px;
        }

        /* High-Contrast Seamless Table */
        .table-custom {
            --bs-table-bg: transparent !important;
            --bs-table-color: #ffffff !important;
            --bs-table-hover-bg: rgba(255, 255, 255, 0.05) !important;
            background-color: transparent !important;
            color: #ffffff !important;
        }
        .table-custom tr,
        .table-custom th,
        .table-custom td {
            background-color: transparent !important;
            color: #ffffff !important;
            border-bottom: 1px solid var(--border-color) !important;
        }
        .table-custom th {
            color: #ffffff !important;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
            background-color: rgba(255, 255, 255, 0.03) !important;
        }
        .table-custom td small,
        .table-custom td .text-muted,
        .table-custom td .text-contrast-muted {
            color: var(--text-muted) !important;
        }

        /* Form Controls Dark High Contrast */
        .custom-dark-input,
        .custom-dark-select {
            background-color: #12141a !important;
            color: #ffffff !important;
            border: 1px solid var(--border-color) !important;
        }
        .custom-dark-input:focus,
        .custom-dark-select:focus {
            border-color: var(--grle-red) !important;
            box-shadow: 0 0 0 0.2rem rgba(229, 57, 53, 0.25) !important;
            color: #ffffff !important;
        }
        .custom-dark-select option {
            background-color: #12141a !important;
            color: #ffffff !important;
        }

        .modal-content-dark {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            color: #ffffff;
        }

        /* Status Badges */
        .badge-pending { background-color: #f57c00; color: #fff; }
        .badge-active { background-color: #2e7d32; color: #fff; }
        .badge-inactive { background-color: #616161; color: #fff; }
        .badge-banned { background-color: #c62828; color: #fff; }

        .badge-admin { background-color: #7b1fa2; color: #fff; }
        .badge-driver { background-color: #0288d1; color: #fff; }
        .badge-customer { background-color: #00897b; color: #fff; }

        .table-custom { min-width: 820px; }

        @media (max-width: 767.98px) {
            .admin-layout { display: block; }
            .sidebar-wrapper { width: 100%; min-width: 0; border-right: 0; }
            .main-content { padding: 1rem !important; }
            .content-panel, .stat-card { padding: 16px; }
            .main-content > .d-flex:first-child { align-items: flex-start !important; flex-wrap: wrap; }
            .table-custom { min-width: 820px; }
            .modal-dialog { margin: 0.5rem; }
            .modal-body { padding: 1rem !important; }
            .modal-footer { flex-wrap: wrap; gap: 0.5rem; }
            .modal-footer .btn { flex: 1 1 100%; margin: 0; }
        }
    </style>
</head>
<body>

<div class="admin-layout">
    
    <!-- INCLUDE REUSABLE SIDEBAR -->
    <?php include 'sidebar.php'; ?>

    <!-- MAIN CONTENT AREA -->
    <div class="main-content p-4">
        
        <!-- Header Topbar -->
        <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom border-secondary">
            <div>
                <h3 class="fw-bold mb-1 text-white"><i class="fas fa-users-cog text-danger me-2"></i>User Account Approvals & Management</h3>
                <p class="text-contrast-muted small mb-0">Control system access, approve pending signups, and register drivers or admins</p>
            </div>
            <button class="btn btn-danger fw-bold" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="fas fa-user-plus me-2"></i>Add New Account
            </button>
        </div>

        <!-- Flash Alert Message -->
        <?php if (!empty($flash_message)): ?>
            <div class="alert alert-<?php echo $flash_type; ?> alert-dismissible fade show text-white fw-medium mb-4" role="alert">
                <i class="fas fa-info-circle me-2"></i> <?php echo htmlspecialchars($flash_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- METRIC COUNTERS -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Total Registered</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?php echo $count_total; ?></h2>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" style="border-left-color: #f57c00;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Pending Approvals</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?php echo $count_pending; ?></h2>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" style="border-left-color: #0288d1;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Active Drivers</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?php echo $count_drivers; ?></h2>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" style="border-left-color: #00897b;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Active Customers</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?php echo $count_customers; ?></h2>
                </div>
            </div>
        </div>

        <!-- SEARCH AND FILTER PANEL -->
        <div class="content-panel mb-4">
            <form action="admin_users.php" method="GET" class="row g-3 align-items-center">
                <div class="col-lg-4 col-md-6">
                    <div class="input-group">
                        <span class="input-group-text custom-dark-input border-end-0"><i class="fas fa-search text-contrast-muted"></i></span>
                        <input type="text" name="search" class="form-control custom-dark-input border-start-0" placeholder="Search by name, email, or phone..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <select name="role" class="form-select custom-dark-select" onchange="this.form.submit()">
                        <option value="all" <?php echo ($filter_role === 'all') ? 'selected' : ''; ?>>All Roles (Admins, Drivers, Customers)</option>
                        <option value="admin" <?php echo ($filter_role === 'admin') ? 'selected' : ''; ?>>Admins Only</option>
                        <option value="driver" <?php echo ($filter_role === 'driver') ? 'selected' : ''; ?>>Drivers Only</option>
                        <option value="customer" <?php echo ($filter_role === 'customer') ? 'selected' : ''; ?>>Customers Only</option>
                    </select>
                </div>

                <div class="col-lg-3 col-md-6">
                    <select name="status" class="form-select custom-dark-select" onchange="this.form.submit()">
                        <option value="all" <?php echo ($filter_status === 'all') ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="pending" <?php echo ($filter_status === 'pending') ? 'selected' : ''; ?>>Pending Approvals</option>
                        <option value="active" <?php echo ($filter_status === 'active') ? 'selected' : ''; ?>>Active Accounts</option>
                        <option value="inactive" <?php echo ($filter_status === 'inactive') ? 'selected' : ''; ?>>Inactive Accounts</option>
                        <option value="banned" <?php echo ($filter_status === 'banned') ? 'selected' : ''; ?>>Banned Accounts</option>
                    </select>
                </div>

                <div class="col-lg-2 col-md-6 d-flex gap-2">
                    <button type="submit" class="btn btn-danger w-100 fw-bold">Filter</button>
                    <?php if ($filter_role !== 'all' || $filter_status !== 'all' || !empty($search_query)): ?>
                        <a href="admin_users.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="fas fa-undo"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- USERS TABLE CONTAINER -->
        <div class="content-panel">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold text-white mb-0">User Directory Records</h5>
                <span class="badge bg-secondary px-3 py-1"><?php echo count($users_list); ?> Result(s)</span>
            </div>

            <?php if (empty($users_list)): ?>
                <div class="text-center py-5 text-contrast-muted">
                    <i class="fas fa-user-slash fs-1 mb-2 text-secondary d-block"></i>
                    <p class="mb-0 fs-6">No user accounts matched your search or filter parameters.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Full Name</th>
                                <th>Email & Contact</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Registered Date</th>
                                <th class="text-end">Account Management</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users_list as $usr): ?>
                                <tr>
                                    <td class="fw-bold text-contrast-muted">#USR-<?php echo $usr['user_id']; ?></td>
                                    <td>
                                        <span class="fw-bold text-white d-block fs-6"><?php echo htmlspecialchars($usr['first_name'] . ' ' . $usr['last_name']); ?></span>
                                    </td>
                                    <td>
                                        <span class="d-block text-white"><?php echo htmlspecialchars($usr['email']); ?></span>
                                        <small class="text-contrast-muted"><i class="fas fa-phone me-1 fs-8"></i><?php echo htmlspecialchars($usr['phone']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $usr['role']; ?> text-uppercase fs-8 px-2 py-1">
                                            <?php echo $usr['role']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $usr['status']; ?> text-uppercase fs-8 px-2 py-1">
                                            <?php echo $usr['status']; ?>
                                        </span>
                                    </td>
                                    <td class="text-contrast-muted">
                                        <small><?php echo date('M d, Y h:i A', strtotime($usr['created_at'])); ?></small>
                                    </td>
                                    <td class="text-end">
                                        <!-- Quick Status Change Form -->
                                        <form action="admin_users.php" method="POST" class="d-inline me-1">
                                            <input type="hidden" name="action" value="update_user_status">
                                            <input type="hidden" name="user_id" value="<?php echo $usr['user_id']; ?>">
                                            
                                            <?php if ($usr['status'] === 'pending'): ?>
                                                <button type="submit" name="status" value="active" class="btn btn-sm btn-success fw-bold px-2 py-1 me-1">
                                                    <i class="fas fa-check me-1"></i> Approve
                                                </button>
                                                <button type="submit" name="status" value="banned" class="btn btn-sm btn-outline-danger px-2 py-1">
                                                    Reject
                                                </button>
                                            <?php elseif ($usr['status'] === 'active'): ?>
                                                <button type="submit" name="status" value="banned" class="btn btn-sm btn-outline-warning px-2 py-1" title="Ban Account">
                                                    <i class="fas fa-ban me-1"></i> Ban
                                                </button>
                                            <?php elseif ($usr['status'] === 'banned'): ?>
                                                <button type="submit" name="status" value="active" class="btn btn-sm btn-outline-success px-2 py-1" title="Unban Account">
                                                    <i class="fas fa-undo me-1"></i> Unban
                                                </button>
                                            <?php endif; ?>
                                        </form>

                                        <!-- Delete Account Form -->
                                        <?php if ($usr['user_id'] !== intval($_SESSION['user_id'])): ?>
                                            <form action="admin_users.php" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this account?');">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?php echo $usr['user_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-1" title="Delete User">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- MODAL: ADD NEW USER ACCOUNT -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold" id="addUserModalLabel"><i class="fas fa-user-plus text-danger me-2"></i>Register New Account</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="admin_users.php" method="POST">
                <input type="hidden" name="action" value="add_user">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold text-contrast-muted">First Name *</label>
                            <input type="text" name="first_name" class="form-control custom-dark-input" required placeholder="John">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold text-contrast-muted">Last Name *</label>
                            <input type="text" name="last_name" class="form-control custom-dark-input" required placeholder="Doe">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-contrast-muted">Email Address *</label>
                            <input type="email" name="email" class="form-control custom-dark-input" required placeholder="user@domain.com">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-contrast-muted">Phone Number *</label>
                            <input type="text" name="phone" class="form-control custom-dark-input" required placeholder="09123456789">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-contrast-muted">Account Password *</label>
                            <input type="password" name="password" class="form-control custom-dark-input" required placeholder="Assign secure password">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold text-contrast-muted">Assigned Role</label>
                            <select name="role" class="form-select custom-dark-select">
                                <option value="driver">Driver</option>
                                <option value="customer" selected>Customer</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold text-contrast-muted">Initial Status</label>
                            <select name="status" class="form-select custom-dark-select">
                                <option value="active" selected>Active</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-bold">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>