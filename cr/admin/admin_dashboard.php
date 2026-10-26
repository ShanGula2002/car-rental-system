<?php
// admin_dashboard.php - Dynamic Control Center with High-Contrast UI & Modular Sidebar
session_start();
require_once '../db.php';
require_once '../late_return.php';

// Auth Guard: Admin access only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php?role=admin');
    exit;
}

$active_page = 'dashboard';
$flash_message = '';
$flash_type = '';

function driver_has_overlapping_booking(PDO $pdo, int $driver_id, string $pickup_date, string $return_date, ?int $exclude_booking_id = null): bool
{
    $sql = "
        SELECT 1
        FROM bookings
        WHERE driver_id = :driver_id
          AND booking_id != :exclude_booking_id
          AND status NOT IN ('cancelled', 'completed')
          AND pickup_date < :return_date
          AND return_date > :pickup_date
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':driver_id' => $driver_id,
        ':exclude_booking_id' => $exclude_booking_id ?? 0,
        ':pickup_date' => $pickup_date,
        ':return_date' => $return_date,
    ]);

    return (bool) $stmt->fetchColumn();
}

// ==========================================
// 1. DYNAMIC ACTION HANDLERS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // User Status Update
    if ($_POST['action'] === 'update_user_status') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $new_status     = $_POST['status'] ?? '';

        if ($target_user_id > 0 && in_array($new_status, ['active', 'banned', 'inactive'])) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET status = :status WHERE user_id = :id");
                $stmt->execute([':status' => $new_status, ':id' => $target_user_id]);
                $flash_message = "User status updated to '" . ucfirst($new_status) . "'.";
                $flash_type = "success";
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $flash_message = "Failed to update user status.";
                $flash_type = "danger";
            }
        }
    }

    // Vehicle Quick Status Update
    if ($_POST['action'] === 'update_vehicle_status') {
        $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
        $veh_status = $_POST['vehicle_status'] ?? '';

        if ($vehicle_id > 0 && in_array($veh_status, ['available', 'booked', 'maintenance', 'out_of_service'])) {
            try {
                $stmt = $pdo->prepare("UPDATE vehicles SET status = :status WHERE vehicle_id = :id");
                $stmt->execute([':status' => $veh_status, ':id' => $vehicle_id]);
                $flash_message = "Vehicle #" . $vehicle_id . " status changed to '" . str_replace('_', ' ', $veh_status) . "'.";
                $flash_type = "success";
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $flash_message = "Failed to update vehicle status.";
                $flash_type = "danger";
            }
        }
    }

    // Assign Driver & Approve Booking
    if ($_POST['action'] === 'assign_driver_to_booking') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $driver_id  = intval($_POST['driver_id'] ?? 0);

        if ($booking_id > 0 && $driver_id > 0) {
            try {
                $booking_stmt = $pdo->prepare("SELECT pickup_date, return_date FROM bookings WHERE booking_id = :id LIMIT 1");
                $booking_stmt->execute([':id' => $booking_id]);
                $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$booking) {
                    $flash_message = "Booking not found.";
                    $flash_type = "danger";
                } elseif (driver_has_overlapping_booking($pdo, $driver_id, $booking['pickup_date'], $booking['return_date'], $booking_id)) {
                    $flash_message = "This driver is already assigned to another booking during the same date and time. Please choose another driver.";
                    $flash_type = "danger";
                } else {
                    $stmt = $pdo->prepare("UPDATE bookings SET status = 'approved', driver_id = :driver_id WHERE booking_id = :id");
                    $stmt->execute([':driver_id' => $driver_id, ':id' => $booking_id]);
                    $flash_message = "Booking #" . $booking_id . " approved and driver assigned successfully.";
                    $flash_type = "success";
                }
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $flash_message = "Failed to assign driver and approve booking.";
                $flash_type = "danger";
            }
        }
    }

    // Standard Booking Status Update (For Cancel, Start Trip, Complete)
    if ($_POST['action'] === 'update_booking_status') {
        $booking_id     = intval($_POST['booking_id'] ?? 0);
        $booking_status = $_POST['booking_status'] ?? '';

        if ($booking_id > 0 && in_array($booking_status, ['approved', 'ongoing', 'completed', 'cancelled'])) {
            try {
                $stmt = $pdo->prepare("UPDATE bookings SET status = :status WHERE booking_id = :id");
                $stmt->execute([':status' => $booking_status, ':id' => $booking_id]);
                $flash_message = "Booking #" . $booking_id . " updated to '" . ucfirst($booking_status) . "'.";
                $flash_type = "info";
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $flash_message = "Failed to update booking status.";
                $flash_type = "danger";
            }
        }
    }
}

// ==========================================
// 2. FETCH REAL-TIME DATA
// ==========================================
try {
    $pending_users_count = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
    $active_fleet_count  = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'available'")->fetchColumn();
    $total_fleet_count   = $pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
    $active_bookings_cnt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('pending', 'approved', 'ongoing')")->fetchColumn();
    $total_revenue       = $pdo->query("SELECT IFNULL(SUM(total_price), 0.00) FROM bookings WHERE status = 'completed'")->fetchColumn();

    $late_fee_bookings = $pdo->query("SELECT b.pickup_date, b.return_date, b.total_price, b.status, v.daily_rate FROM bookings b JOIN vehicles v ON v.vehicle_id = b.vehicle_id")->fetchAll(PDO::FETCH_ASSOC);
    $total_late_fees = 0;
    foreach ($late_fee_bookings as $booking) {
        $pickup = new DateTime($booking['pickup_date']);
        $return = new DateTime($booking['return_date']);
        $planned_days = max(1, (int) ceil(($return->getTimestamp() - $pickup->getTimestamp()) / 86400));
        $planned_total = $planned_days * (float) $booking['daily_rate'];

        if ($booking['status'] === 'completed') {
            $total_late_fees += max(0, (float) $booking['total_price'] - $planned_total);
        } else {
            $total_late_fees += calculate_late_return_fee($booking['return_date'], (float) $booking['daily_rate'])['fee'];
        }
    }

    $pending_users = $pdo->query("SELECT user_id, role, first_name, last_name, email, phone, created_at FROM users WHERE status = 'pending' ORDER BY created_at DESC")->fetchAll();
    $vehicles      = $pdo->query("SELECT vehicle_id, brand, model_name, year, plate_number, category, daily_rate, status, current_mileage FROM vehicles ORDER BY vehicle_id DESC")->fetchAll();
    
    // Fetch active drivers for modal dropdown assignment
    $drivers = $pdo->query("SELECT user_id, first_name, last_name FROM users WHERE role = 'driver' AND status = 'active' ORDER BY first_name ASC")->fetchAll();

    // UPDATED QUERY: Included pickup_latitude and pickup_longitude
    $recent_bookings = $pdo->query("
        SELECT b.booking_id, b.pickup_date, b.return_date, b.total_price, b.status,
               b.pickup_latitude, b.pickup_longitude,
               c.first_name AS cust_first, c.last_name AS cust_last, c.email AS cust_email,
               v.brand, v.model_name, v.plate_number, v.daily_rate,
               d.first_name AS driver_first, d.last_name AS driver_last
        FROM bookings b
        JOIN users c ON b.user_id = c.user_id
        JOIN vehicles v ON b.vehicle_id = v.vehicle_id
        LEFT JOIN users d ON b.driver_id = d.user_id
        ORDER BY b.created_at DESC LIMIT 10
    ")->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    die("Database Error. Please ensure tables are created.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | GRLE Car Rental</title>
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

    /* High-Contrast Navigation Links */
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

    /* Utility Classes */
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

    /* Stat Cards & Panels */
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

    /* Seamless Dark Table Fix */
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

    /* Form Controls */
    .custom-dark-select {
        background-color: #12141a !important;
        color: #ffffff !important;
        border: 1px solid var(--border-color) !important;
    }
    .custom-dark-select option {
        background-color: #12141a !important;
        color: #ffffff !important;
    }

    .table-custom { min-width: 760px; }

    @media (max-width: 767.98px) {
        .admin-layout { display: block; }
        .sidebar-wrapper { width: 100%; min-width: 0; border-right: 0; }
        .main-content { padding: 1rem !important; }
        .content-panel, .stat-card { padding: 16px; }
        .main-content > .d-flex:first-child { align-items: flex-start !important; flex-wrap: wrap; }
        .table-custom { min-width: 760px; }
    }
</style>
</head>
<body>

<div class="admin-layout">
    
    <!-- INCLUDE MODULAR SIDEBAR -->
    <?php include 'sidebar.php'; ?>

    <!-- MAIN CONTENT AREA -->
    <div class="main-content p-4">
        
        <!-- Header Topbar -->
        <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom border-secondary">
            <div>
                <h3 class="fw-bold mb-1 text-white">System Dashboard</h3>
                <p class="text-contrast-muted small mb-0">Overview of users, fleet status, and active reservations</p>
            </div>
            <div>
                <span class="badge bg-success px-3 py-2 fs-7"><i class="fas fa-signal me-1"></i> System Online</span>
            </div>
        </div>

        <!-- Flash Alert Message -->
        <?php if (!empty($flash_message)): ?>
            <div class="alert alert-<?php echo $flash_type; ?> alert-dismissible fade show text-white fw-medium mb-4" role="alert">
                <i class="fas fa-info-circle me-2"></i> <?php echo htmlspecialchars($flash_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- METRICS COUNTER ROW -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Pending Approvals</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?php echo $pending_users_count; ?></h2>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" style="border-left-color: #0288d1;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Available Fleet</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?php echo $active_fleet_count; ?> <span class="fs-6 text-contrast-muted">/ <?php echo $total_fleet_count; ?></span></h2>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" style="border-left-color: #f57c00;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Active Bookings</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?php echo $active_bookings_cnt; ?></h2>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" style="border-left-color: #388e3c;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Completed Revenue</span>
                    <h2 class="fw-bold text-white mb-0 mt-1">₱<?php echo number_format($total_revenue, 2); ?></h2>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" style="border-left-color: #f57c00;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Total Late Fees</span>
                    <h2 class="fw-bold text-white mb-0 mt-1">₱<?php echo number_format($total_late_fees, 2); ?></h2>
                </div>
            </div>
        </div>

        <!-- TWO COLUMN GRID -->
        <div class="row g-4 mb-4">
            
            <!-- LEFT: PENDING APPROVALS QUEUE -->
            <div class="col-xl-7">
                <div class="content-panel">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h5 class="fw-bold text-white mb-0"><i class="fas fa-user-clock text-danger me-2"></i>Pending Registrations</h5>
                        <span class="badge bg-danger rounded-pill px-3 py-1"><?php echo count($pending_users); ?> Requests</span>
                    </div>

                    <?php if (empty($pending_users)): ?>
                        <div class="text-center py-4 text-contrast-muted">
                            <i class="fas fa-check-circle fs-2 text-success mb-2 d-block"></i>
                            <p class="mb-0">No pending account approval requests.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-custom align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>User Details</th>
                                        <th>Role</th>
                                        <th>Contact</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_users as $p_user): ?>
                                        <tr>
                                            <td>
                                                <span class="fw-bold d-block text-white"><?php echo htmlspecialchars($p_user['first_name'] . ' ' . $p_user['last_name']); ?></span>
                                                <small class="text-contrast-muted"><?php echo htmlspecialchars($p_user['email']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary text-uppercase fs-8"><?php echo $p_user['role']; ?></span>
                                            </td>
                                            <td class="text-contrast-muted"><?php echo htmlspecialchars($p_user['phone']); ?></td>
                                            <td class="text-end">
                                                <form action="admin_dashboard.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="update_user_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $p_user['user_id']; ?>">
                                                    <input type="hidden" name="status" value="active">
                                                    <button type="submit" class="btn btn-sm btn-success fw-bold px-2 py-1 me-1">Approve</button>
                                                </form>
                                                <form action="admin_dashboard.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="update_user_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $p_user['user_id']; ?>">
                                                    <input type="hidden" name="status" value="banned">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-1">Reject</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT: FLEET QUICK CONTROL -->
            <div class="col-xl-5">
                <div class="content-panel">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h5 class="fw-bold text-white mb-0"><i class="fas fa-car text-danger me-2"></i>Fleet Status</h5>
                        <span class="badge bg-secondary px-2 py-1"><?php echo count($vehicles); ?> Vehicles</span>
                    </div>

                    <?php if (empty($vehicles)): ?>
                        <div class="text-center py-4 text-contrast-muted">
                            <p class="mb-0">No vehicles recorded in fleet inventory.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
                            <table class="table table-custom align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Vehicle</th>
                                        <th>Rate</th>
                                        <th>Status Control</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vehicles as $veh): ?>
                                        <tr>
                                            <td>
                                                <span class="fw-bold text-white d-block"><?php echo htmlspecialchars($veh['brand'] . ' ' . $veh['model_name']); ?></span>
                                                <small class="text-contrast-muted"><?php echo htmlspecialchars($veh['plate_number']); ?></small>
                                            </td>
                                            <td class="text-success fw-bold">₱<?php echo number_format($veh['daily_rate'], 2); ?></td>
                                            <td>
                                                <form action="admin_dashboard.php" method="POST">
                                                    <input type="hidden" name="action" value="update_vehicle_status">
                                                    <input type="hidden" name="vehicle_id" value="<?php echo $veh['vehicle_id']; ?>">
                                                    <select name="vehicle_status" class="form-select form-select-sm custom-dark-select" onchange="this.form.submit()">
                                                        <option value="available" <?php echo ($veh['status'] === 'available') ? 'selected' : ''; ?>>Available</option>
                                                        <option value="booked" <?php echo ($veh['status'] === 'booked') ? 'selected' : ''; ?>>Booked</option>
                                                        <option value="maintenance" <?php echo ($veh['status'] === 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                                                        <option value="out_of_service" <?php echo ($veh['status'] === 'out_of_service') ? 'selected' : ''; ?>>Out of Service</option>
                                                    </select>
                                                </form>
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

        <!-- FULL WIDTH ROW: RECENT BOOKINGS MONITOR -->
        <div class="content-panel">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold text-white mb-0"><i class="fas fa-list-alt text-danger me-2"></i>Recent Reservation Requests</h5>
            </div>

            <?php if (empty($recent_bookings)): ?>
                <div class="text-center py-4 text-contrast-muted">
                    <p class="mb-0">No booking requests found.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Customer</th>
                                <th>Vehicle & Pin</th>
                                <th>Assigned Driver</th>
                                <th>Pickup Date</th>
                                <th>Total Cost</th>
                                <th>Status</th>
                                <th class="text-end">Manage Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_bookings as $bk): ?>
                                <?php
                                    $trip_pickup = new DateTime($bk['pickup_date']);
                                    $trip_return = new DateTime($bk['return_date']);
                                    $trip_days = max(1, (int) ceil(($trip_return->getTimestamp() - $trip_pickup->getTimestamp()) / 86400));
                                    $trip_base = $trip_days * (float) $bk['daily_rate'];
                                    if ($bk['status'] === 'completed') {
                                        $trip_late_fee = max(0, (float) $bk['total_price'] - $trip_base);
                                        $trip_late_days = (float) $bk['daily_rate'] > 0 ? (int) floor($trip_late_fee / (float) $bk['daily_rate']) : 0;
                                        $trip_remaining_hours = (int) round(($trip_late_fee - ($trip_late_days * (float) $bk['daily_rate'])) / 200);
                                    } else {
                                        $trip_late_charge = calculate_late_return_fee($bk['return_date'], (float) $bk['daily_rate']);
                                        $trip_late_fee = $trip_late_charge['fee'];
                                        $trip_late_days = $trip_late_charge['late_days'];
                                        $trip_remaining_hours = $trip_late_charge['remaining_hours'];
                                    }
                                    $trip_late_breakdown = $trip_late_fee > 0
                                        ? (($trip_late_days > 0 ? $trip_late_days . ' day' . ($trip_late_days > 1 ? 's' : '') . ' × ₱' . number_format((float) $bk['daily_rate'], 2) : '')
                                            . ($trip_late_days > 0 && $trip_remaining_hours > 0 ? ' + ' : '')
                                            . ($trip_remaining_hours > 0 ? $trip_remaining_hours . ' hr' . ($trip_remaining_hours > 1 ? 's' : '') . ' × ₱200' : ''))
                                        : 'No late fee';
                                    $trip_total = $bk['status'] === 'completed' ? (float) $bk['total_price'] : (float) $bk['total_price'] + $trip_late_fee;
                                ?>
                                <tr>
                                    <td class="fw-bold text-danger">#BK-<?php echo $bk['booking_id']; ?></td>
                                    <td>
                                        <span class="d-block fw-semibold text-white"><?php echo htmlspecialchars($bk['cust_first'] . ' ' . $bk['cust_last']); ?></span>
                                        <small class="text-contrast-muted"><?php echo htmlspecialchars($bk['cust_email']); ?></small>
                                    </td>
                                    <td>
                                        <span class="text-white fw-medium d-block"><?php echo htmlspecialchars($bk['brand'] . ' ' . $bk['model_name']); ?></span>
                                        
                                        <!-- Pinned Location GPS Coordinates Handling -->
                                        <?php if (!empty($bk['pickup_latitude']) && !empty($bk['pickup_longitude'])): ?>
                                            <a href="https://maps.google.com/?q=<?php echo $bk['pickup_latitude']; ?>,<?php echo $bk['pickup_longitude']; ?>" target="_blank" class="text-info text-decoration-none small d-block mt-1">
                                                <i class="fa-solid fa-map-pin me-1"></i>Pin: <?php echo round($bk['pickup_latitude'], 4); ?>, <?php echo round($bk['pickup_longitude'], 4); ?>
                                            </a>
                                        <?php else: ?>
                                            <small class="text-contrast-muted d-block mt-1">
                                                <i class="fa-solid fa-map-pin me-1"></i>No pinned location
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($bk['driver_first'])): ?>
                                            <span class="text-info fw-medium"><i class="fas fa-user-tie me-1"></i><?php echo htmlspecialchars($bk['driver_first'] . ' ' . $bk['driver_last']); ?></span>
                                        <?php else: ?>
                                            <span class="text-warning small"><i class="fas fa-exclamation-triangle me-1"></i>Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-contrast-muted"><small><?php echo date('M d, Y H:i', strtotime($bk['pickup_date'])); ?></small></td>
                                    <td class="fw-bold text-success">
                                        ₱<?php echo number_format($trip_total, 2); ?>
                                        <small class="d-block text-contrast-muted fw-normal fs-8">Base: ₱<?php echo number_format($trip_base, 2); ?></small>
                                        <small class="d-block text-danger fw-normal fs-8">Late: <?php echo htmlspecialchars($trip_late_breakdown); ?> = ₱<?php echo number_format($trip_late_fee, 2); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary text-uppercase fs-8"><?php echo $bk['status']; ?></span>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($bk['status'] === 'pending'): ?>
                                            <!-- Triggers Assign Driver Pop-Out Modal -->
                                            <button type="button" class="btn btn-sm btn-success me-1 px-2 py-1" data-bs-toggle="modal" data-bs-target="#assignDriverModal" data-booking-id="<?php echo $bk['booking_id']; ?>">
                                                Approve
                                            </button>
                                            
                                            <form action="admin_dashboard.php" method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="update_booking_status">
                                                <input type="hidden" name="booking_id" value="<?php echo $bk['booking_id']; ?>">
                                                <button type="submit" name="booking_status" value="cancelled" class="btn btn-sm btn-outline-danger px-2 py-1">Cancel</button>
                                            </form>
                                        <?php else: ?>
                                            <form action="admin_dashboard.php" method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="update_booking_status">
                                                <input type="hidden" name="booking_id" value="<?php echo $bk['booking_id']; ?>">
                                                <?php if ($bk['status'] === 'approved'): ?>
                                                    <span class="text-contrast-muted small">N/A</span>
                                                <?php elseif ($bk['status'] === 'ongoing'): ?>
                                                    <button type="submit" name="booking_status" value="completed" class="btn btn-sm btn-success px-2 py-1">Complete</button>
                                                <?php else: ?>
                                                    <span class="text-contrast-muted small">N/A</span>
                                                <?php endif; ?>
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

<!-- ========================================== -->
<!-- ASSIGN DRIVER MODAL (POP-OUT MESSAGE)      -->
<!-- ========================================== -->
<div class="modal fade" id="assignDriverModal" tabindex="-1" aria-labelledby="assignDriverModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark-card text-white border border-secondary">
            <form action="admin_dashboard.php" method="POST">
                <div class="modal-header border-bottom border-secondary">
                    <h5 class="modal-title fw-bold" id="assignDriverModalLabel">
                        <i class="fas fa-user-tie text-danger me-2"></i>Assign Driver & Approve Booking
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="assign_driver_to_booking">
                    <input type="hidden" name="booking_id" id="modal_booking_id" value="">
                    
                    <p class="text-contrast-muted small mb-3">Please choose an active driver from the list below to assign to this reservation before completing the approval.</p>
                    
                    <div class="mb-3">
                        <label for="driver_id" class="form-label fw-semibold">Select Driver</label>
                        <select name="driver_id" id="driver_id" class="form-select custom-dark-select" required>
                            <option value="">-- Choose Driver --</option>
                            <?php foreach ($drivers as $drv): ?>
                                <option value="<?php echo $drv['user_id']; ?>">
                                    <?php echo htmlspecialchars($drv['first_name'] . ' ' . $drv['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top border-secondary">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success btn-sm fw-bold">Confirm & Approve</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Pass the correct Booking ID into the Modal when clicking Approve
    const assignDriverModal = document.getElementById('assignDriverModal');
    if (assignDriverModal) {
        assignDriverModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const bookingId = button.getAttribute('data-booking-id');
            const modalBookingIdInput = assignDriverModal.querySelector('#modal_booking_id');
            modalBookingIdInput.value = bookingId;
        });
    }
</script>
</body>
</html>