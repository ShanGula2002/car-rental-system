<?php
// admin_bookings.php - Booking Management with High-Contrast UI & Modular Sidebar
session_start();
require_once '../db.php';
require_once '../late_return.php';

// Auth Guard: Admin access only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php?role=admin');
    exit;
}

$active_page = 'bookings';
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

    // ACTION: Update Booking Status
    if ($_POST['action'] === 'update_status') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $new_status = trim($_POST['status'] ?? '');

        $allowed_statuses = ['available', 'booked', 'maintenance', 'out_of_service'];

        if ($booking_id > 0 && in_array($new_status, $allowed_statuses)) {
            try {
                $stmt = $pdo->prepare("UPDATE bookings SET status = :status WHERE booking_id = :id");
                $stmt->execute([':status' => $new_status, ':id' => $booking_id]);

                // Sync vehicle availability status based on booking status
                $pdo->prepare("UPDATE vehicles SET status = :vstatus WHERE vehicle_id = (SELECT vehicle_id FROM bookings WHERE booking_id = :id)")
                    ->execute([':vstatus' => $new_status, ':id' => $booking_id]);

                $flash_message = "Booking #{$booking_id} status updated to " . ucfirst(str_replace('_', ' ', $new_status)) . ".";
                $flash_type = "success";
            } catch (PDOException $e) {
                $flash_message = "Database Error: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }

    // ACTION: Assign / Update Driver
    if ($_POST['action'] === 'assign_driver') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $driver_id  = intval($_POST['driver_id'] ?? 0);

        if ($booking_id > 0) {
            try {
                $booking_stmt = $pdo->prepare("SELECT pickup_date, return_date FROM bookings WHERE booking_id = :id LIMIT 1");
                $booking_stmt->execute([':id' => $booking_id]);
                $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$booking) {
                    $flash_message = "Booking not found.";
                    $flash_type = "danger";
                } elseif ($driver_id > 0 && driver_has_overlapping_booking($pdo, $driver_id, $booking['pickup_date'], $booking['return_date'], $booking_id)) {
                    $flash_message = "This driver is already assigned to another booking during the same date and time. Please choose another driver.";
                    $flash_type = "danger";
                } else {
                    $stmt = $pdo->prepare("UPDATE bookings SET driver_id = :driver_id WHERE booking_id = :id");
                    $stmt->execute([':driver_id' => ($driver_id > 0 ? $driver_id : null), ':id' => $booking_id]);

                    $flash_message = "Driver successfully updated for Booking #{$booking_id}.";
                    $flash_type = "success";
                }
            } catch (PDOException $e) {
                $flash_message = "Database Error: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }

    // ACTION: Delete Booking
    if ($_POST['action'] === 'delete_booking') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        if ($booking_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM bookings WHERE booking_id = :id");
                $stmt->execute([':id' => $booking_id]);

                $flash_message = "Booking #{$booking_id} deleted successfully.";
                $flash_type = "success";
            } catch (PDOException $e) {
                $flash_message = "Database Error: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }
}

// ==========================================
// 2. FILTERS & SEARCH
// ==========================================
$search       = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? 'all');

$where_clauses = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_clauses[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE :search_name OR u.email LIKE :search_email OR v.brand LIKE :search_brand OR v.model_name LIKE :search_model OR v.plate_number LIKE :search_plate)";
    $wildcard = "%{$search}%";
    $params[':search_name']    = $wildcard;
    $params[':search_email']   = $wildcard;
    $params[':search_brand']   = $wildcard;
    $params[':search_model']   = $wildcard;
    $params[':search_plate']   = $wildcard;
}

$allowed_filter_statuses = ['available', 'booked', 'maintenance', 'out_of_service'];
if ($status_filter !== 'all' && in_array($status_filter, $allowed_filter_statuses)) {
    $where_clauses[] = "b.status = :status";
    $params[':status'] = $status_filter;
}

$where_sql = implode(' AND ', $where_clauses);

// ==========================================
// 3. FETCH METRICS & DATA
// ==========================================
try {
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total_bookings,
            SUM(CASE WHEN status = 'booked' THEN 1 ELSE 0 END) as total_booked,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as total_available,
            SUM(CASE WHEN status = 'booked' THEN total_price ELSE 0 END) as total_revenue
        FROM bookings
    ")->fetch(PDO::FETCH_ASSOC);

    $query = "
        SELECT 
            b.*,
            v.brand, v.model_name, v.plate_number, v.daily_rate,
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Guest') as customer_name,
            COALESCE(u.email, 'N/A') as customer_email,
            COALESCE(u.phone, 'N/A') as customer_phone,
            d.first_name as driver_first, d.last_name as driver_last
        FROM bookings b
        LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
        LEFT JOIN users u ON b.user_id = u.user_id
        LEFT JOIN users d ON b.driver_id = d.user_id
        WHERE {$where_sql}
        ORDER BY b.booking_id DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_late_fees = 0;
    foreach ($bookings as $booking) {
        $booking_pickup = new DateTime($booking['pickup_date']);
        $booking_return = new DateTime($booking['return_date']);
        $planned_days = max(1, (int) ceil(($booking_return->getTimestamp() - $booking_pickup->getTimestamp()) / 86400));
        $planned_total = $planned_days * (float) $booking['daily_rate'];

        if ($booking['status'] === 'completed') {
            $total_late_fees += max(0, (float) $booking['total_price'] - $planned_total);
        } else {
            $total_late_fees += calculate_late_return_fee($booking['return_date'], (float) $booking['daily_rate'])['fee'];
        }
    }

    // Fetch active drivers for assignment dropdowns
    $drivers = $pdo->query("SELECT user_id, first_name, last_name FROM users WHERE role = 'driver' AND status = 'active' ORDER BY first_name ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Management | GRLE Car Rental</title>
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
    .custom-dark-select, .custom-dark-input {
        background-color: #12141a !important;
        color: #ffffff !important;
        border: 1px solid var(--border-color) !important;
    }
    .custom-dark-select option {
        background-color: #12141a !important;
        color: #ffffff !important;
    }
    .custom-dark-input::placeholder {
        color: var(--text-muted);
    }

    /* Modal Dark Overrides */
    .modal-content {
        background-color: var(--bg-card);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
    }
    .modal-header, .modal-footer {
        border-color: var(--border-color);
    }

    .table-custom { min-width: 900px; }

    @media (max-width: 767.98px) {
        .admin-layout { display: block; }
        .sidebar-wrapper { width: 100%; min-width: 0; border-right: 0; }
        .main-content { padding: 1rem !important; }
        .content-panel, .stat-card { padding: 16px; }
        .main-content > .d-flex:first-child { align-items: flex-start !important; flex-wrap: wrap; }
        .table-custom { min-width: 900px; }
        .modal-dialog { margin: 0.5rem; }
        .modal-body { padding: 1rem !important; }
        .modal-footer { flex-wrap: wrap; gap: 0.5rem; }
        .modal-footer .btn { flex: 1 1 100%; margin: 0; }
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
                <h3 class="fw-bold mb-1 text-white">Booking Management</h3>
                <p class="text-contrast-muted small mb-0">View, manage, assign drivers, and track pinned pickup coordinates.</p>
            </div>
            <div>
                <span class="badge bg-success px-3 py-2 fs-7"><i class="fas fa-signal me-1"></i> System Online</span>
            </div>
        </div>

        <!-- Flash Alert Message -->
        <?php if (!empty($flash_message)): ?>
            <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show text-white fw-medium mb-4" role="alert">
                <i class="fas fa-info-circle me-2"></i> <?= htmlspecialchars($flash_message) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Summary Metrics -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Total Bookings</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?= number_format($stats['total_bookings'] ?? 0) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card" style="border-left-color: #0288d1;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Total Booked</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?= number_format($stats['total_booked'] ?? 0) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card" style="border-left-color: #388e3c;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Total Available</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?= number_format($stats['total_available'] ?? 0) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card" style="border-left-color: #388e3c;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Revenue Earned</span>
                    <h2 class="fw-bold text-white mb-0 mt-1">₱<?= number_format($stats['total_revenue'] ?? 0, 2) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card" style="border-left-color: #f57c00;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Total Late Fees</span>
                    <h2 class="fw-bold text-white mb-0 mt-1">₱<?= number_format($total_late_fees, 2) ?></h2>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="content-panel mb-4">
            <form method="GET" action="admin_bookings.php" class="row g-2">
                <div class="col-md-6">
                    <div class="input-group"> 
                        <span class="input-group-text custom-dark-select border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control custom-dark-input border-start-0" placeholder="Search customer, vehicle, or plate..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="status" class="form-select custom-dark-select" onchange="this.form.submit()">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="available" <?= $status_filter === 'available' ? 'selected' : '' ?>>Available</option>
                        <option value="booked" <?= $status_filter === 'booked' ? 'selected' : '' ?>>Booked</option>
                        <option value="maintenance" <?= $status_filter === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        <option value="out_of_service" <?= $status_filter === 'out_of_service' ? 'selected' : '' ?>>Out of Service</option>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-danger fw-bold"><i class="fa-solid fa-filter me-1"></i> Filter</button>
                </div>
            </form>
        </div>

        <!-- Bookings Table Panel -->
        <div class="content-panel">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold text-white mb-0"><i class="fas fa-calendar-check text-danger me-2"></i>Reservation Directory</h5>
                <span class="badge bg-secondary px-2 py-1"><?= count($bookings) ?> Records</span>
            </div>

            <?php if (empty($bookings)): ?>
                <div class="text-center py-4 text-contrast-muted">
                    <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                    <p class="mb-0">No bookings found matching criteria.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer</th>
                                <th>Vehicle & Pin</th>
                                <th>Assigned Driver</th>
                                <th>Dates</th>
                                <th>Total Price</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $b): ?>
                                <?php
                                    $pickup = new DateTime($b['pickup_date']);
                                    $return = new DateTime($b['return_date']);
                                    $now    = new DateTime(); // Current time for "End Trip" calculation
                                    $days   = max(1, (int) ceil(($return->getTimestamp() - $pickup->getTimestamp()) / 86400));

                                    $base_price  = $days * (float) $b['daily_rate'];
                                    $late_hours  = 0;
                                    $late_days   = 0;
                                    $remaining_hours = 0;
                                    $penalty_fee = 0;

                                    if ($b['status'] !== 'completed') {
                                        $late_charge = calculate_late_return_fee($b['return_date'], (float) $b['daily_rate'], $now);
                                        $late_hours = $late_charge['late_hours'];
                                        $late_days = $late_charge['late_days'];
                                        $remaining_hours = $late_charge['remaining_hours'];
                                        $penalty_fee = $late_charge['fee'];
                                        $final_total = (float) $b['total_price'] + $penalty_fee;
                                    } else {
                                        $penalty_fee = max(0, (float) $b['total_price'] - $base_price);
                                        $late_days = (float) $b['daily_rate'] > 0 ? (int) floor($penalty_fee / (float) $b['daily_rate']) : 0;
                                        $remaining_fee = $penalty_fee - ($late_days * (float) $b['daily_rate']);
                                        $remaining_hours = (int) round($remaining_fee / 200);
                                        $late_hours = ($late_days * 8) + $remaining_hours;
                                        $final_total = (float) $b['total_price'];
                                    }

                                    $late_breakdown = $penalty_fee > 0
                                        ? (($late_days > 0 ? $late_days . ' day' . ($late_days > 1 ? 's' : '') . ' × ₱' . number_format((float) $b['daily_rate'], 2) : '')
                                            . ($late_days > 0 && $remaining_hours > 0 ? ' + ' : '')
                                            . ($remaining_hours > 0 ? $remaining_hours . ' hr' . ($remaining_hours > 1 ? 's' : '') . ' × ₱200' : ''))
                                        : 'No late fee';

                                    $badge_class = [
                                        'available'      => 'bg-success',
                                        'booked'         => 'bg-info text-dark',
                                        'maintenance'    => 'bg-warning text-dark',
                                        'out_of_service' => 'bg-danger'
                                    ][$b['status']] ?? 'bg-secondary';
                                ?>
                                <tr>
                                    <td class="fw-bold text-danger">#<?= $b['booking_id'] ?></td>
                                    <td>
                                        <span class="fw-semibold text-white d-block"><?= htmlspecialchars($b['customer_name']) ?></span>
                                        <small class="text-contrast-muted"><?= htmlspecialchars($b['customer_email']) ?></small>
                                    </td>
                                    <td>
                                        <span class="fw-semibold text-white d-block"><?= htmlspecialchars(($b['brand'] ?? 'N/A') . ' ' . ($b['model_name'] ?? '')) ?></span>
                                        <small class="text-contrast-muted d-block"><i class="fa-solid fa-car text-danger me-1"></i><?= htmlspecialchars($b['plate_number'] ?? 'N/A') ?></small>
                                        
                                        <!-- Pinned Location GPS Coordinates Handling -->
                                        <?php if (!empty($b['pickup_latitude']) && !empty($b['pickup_longitude'])): ?>
                                            <a href="https://maps.google.com/?q=<?= $b['pickup_latitude'] ?>,<?= $b['pickup_longitude'] ?>" target="_blank" class="text-info text-decoration-none small d-block mt-1">
                                                <i class="fa-solid fa-map-pin me-1"></i>Pin: <?= round($b['pickup_latitude'], 4) ?>, <?= round($b['pickup_longitude'], 4) ?>
                                            </a>
                                        <?php else: ?>
                                            <small class="text-contrast-muted d-block mt-1">
                                                <i class="fa-solid fa-map-pin me-1"></i>No pinned location
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($b['driver_first'])): ?>
                                            <span class="text-info fw-medium"><i class="fas fa-user-tie me-1"></i><?= htmlspecialchars($b['driver_first'] . ' ' . $b['driver_last']) ?></span>
                                        <?php else: ?>
                                            <span class="text-warning small"><i class="fas fa-exclamation-triangle me-1"></i>Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small text-contrast-muted">
                                            <i class="fa-regular fa-calendar-check text-success me-1"></i><?= date('M d, Y', strtotime($b['pickup_date'])) ?><br>
                                            <i class="fa-regular fa-calendar-xmark text-danger me-1"></i><?= date('M d, Y', strtotime($b['return_date'])) ?>
                                            <span>(<?= $days ?> <?= $days > 1 ? 'days' : 'day' ?>)</span>
                                        </div>
                                    </td>
                                    <td class="fw-bold text-success">
                                        ₱<?= number_format($final_total, 2) ?>
                                        <small class="d-block text-contrast-muted fw-normal fs-8">Base: ₱<?= number_format($base_price, 2) ?></small>
                                        <small class="d-block text-danger fw-normal fs-8">Late: <?= htmlspecialchars($late_breakdown) ?> = ₱<?= number_format($penalty_fee, 2) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?= $badge_class ?> text-uppercase fs-8 px-2 py-1">
                                            <?= htmlspecialchars(str_replace('_', ' ', $b['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group">
                                            <!-- DYNAMIC RECEIPT POPUP BUTTON -->
                                            <button type="button" class="btn btn-sm btn-outline-success px-2 py-1 me-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#receiptModal" 
                                                    data-customer="<?= htmlspecialchars($b['customer_name']) ?>"
                                                    data-vehicle="<?= htmlspecialchars(($b['brand'] ?? 'N/A') . ' ' . ($b['model_name'] ?? '')) ?>"
                                                    data-return="<?= $return->format('M d, Y h:i A') ?>"
                                                    data-baseprice="<?= number_format($base_price, 2) ?>"
                                                    data-latehours="<?= $late_hours ?>"
                                                    data-latebreakdown="<?= htmlspecialchars($late_breakdown) ?>"
                                                    data-penalty="<?= number_format($penalty_fee, 2) ?>"
                                                    data-total="<?= number_format($final_total, 2) ?>"
                                                    title="View Receipt">
                                                <i class="fa-solid fa-file-invoice-dollar"></i> Receipt
                                            </button>

                                            <!-- ASSIGN DRIVER BUTTON -->
                                            <button type="button" class="btn btn-sm btn-outline-info px-2 py-1 me-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#assignDriverModal" 
                                                    data-id="<?= $b['booking_id'] ?>" 
                                                    data-driver="<?= $b['driver_id'] ?? '' ?>"
                                                    title="Assign Driver">
                                                <i class="fa-solid fa-user-tie"></i> Driver
                                            </button>

                                            <!-- UPDATE STATUS BUTTON -->
                                            <button type="button" class="btn btn-sm btn-outline-light px-2 py-1 me-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editStatusModal" 
                                                    data-id="<?= $b['booking_id'] ?>" 
                                                    data-status="<?= $b['status'] ?>"
                                                    title="Update Status">
                                                <i class="fa-solid fa-pen"></i> Status
                                            </button>

                                            <!-- DELETE BUTTON -->
                                            <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteBookingModal" 
                                                    data-id="<?= $b['booking_id'] ?>"
                                                    title="Delete Booking">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </div>
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

<!-- MODAL: View Receipt -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-success"><i class="fa-solid fa-file-invoice-dollar me-2"></i>Trip Receipt</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-contrast-muted">Customer:</span>
                    <span class="fw-bold text-white" id="r_customer"></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-contrast-muted">Vehicle:</span>
                    <span class="fw-bold text-white" id="r_vehicle"></span>
                </div>
                <hr class="border-secondary">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-contrast-muted">Expected Return:</span>
                    <span class="text-white" id="r_return"></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-contrast-muted">Actual End Time:</span>
                    <span class="text-white fw-medium" id="r_actual"></span>
                </div>
                <hr class="border-secondary">
                
                <!-- Financial Breakdown -->
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-contrast-muted">Base Price:</span>
                    <span class="text-white">₱<span id="r_baseprice"></span></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-contrast-muted">Late Fee Breakdown:</span>
                    <span class="text-danger text-end" id="r_latebreakdown"></span>
                </div>
                
                <!-- Late Penalty Row (Hidden if no late penalty) -->
                <div class="d-flex justify-content-between mb-2 text-danger" id="r_penalty_row" style="display: none;">
                    <span>Late Penalty (<span id="r_latehours"></span> hrs @ ₱200):</span>
                    <span class="fw-bold">+ ₱<span id="r_penalty"></span></span>
                </div>
                
                <hr class="border-secondary">
                <div class="d-flex justify-content-between fs-5 fw-bold">
                    <span class="text-white">Final Total:</span>
                    <span class="text-success">₱<span id="r_total"></span></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success fw-bold" onclick="window.print()">
                    <i class="fa-solid fa-print me-1"></i> Print Receipt
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Assign Driver -->
<div class="modal fade" id="assignDriverModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="admin_bookings.php">
                <input type="hidden" name="action" value="assign_driver">
                <input type="hidden" name="booking_id" id="assign_booking_id">
                
                <div class="modal-header">
                    <h5 class="modal-title text-white"><i class="fas fa-user-tie text-danger me-2"></i>Assign Driver</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-contrast-muted small mb-3">Select an active driver to assign to this booking.</p>
                    <div class="mb-3">
                        <label for="driverSelect" class="form-label text-contrast-muted">Select Driver</label>
                        <select name="driver_id" id="driverSelect" class="form-select custom-dark-select" required>
                            <option value="">-- No Driver / Unassign --</option>
                            <?php foreach ($drivers as $drv): ?>
                                <option value="<?= $drv['user_id'] ?>">
                                    <?= htmlspecialchars($drv['first_name'] . ' ' . $drv['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger fw-bold">Save Driver Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: Update Status -->
<div class="modal fade" id="editStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="admin_bookings.php">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="booking_id" id="status_booking_id">
                
                <div class="modal-header">
                    <h5 class="modal-title text-white">Update Booking Status</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="statusSelect" class="form-label text-contrast-muted">Select New Status</label>
                        <select name="status" id="statusSelect" class="form-select custom-dark-select" required>
                            <option value="available">Available</option>
                            <option value="booked">Booked</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="out_of_service">Out of Service</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger fw-bold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: Delete Booking -->
<div class="modal fade" id="deleteBookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="admin_bookings.php">
                <input type="hidden" name="action" value="delete_booking">
                <input type="hidden" name="booking_id" id="delete_booking_id">
                
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Delete Booking</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-contrast-muted">
                    Are you sure you want to permanently delete this booking record?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-bold">Delete Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Receipt Modal Population
        const receiptModal = document.getElementById('receiptModal');
        if(receiptModal) {
            receiptModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                
                document.getElementById('r_customer').textContent = button.getAttribute('data-customer');
                document.getElementById('r_vehicle').textContent = button.getAttribute('data-vehicle');
                document.getElementById('r_return').textContent = button.getAttribute('data-return');
                document.getElementById('r_baseprice').textContent = button.getAttribute('data-baseprice');
                document.getElementById('r_latebreakdown').textContent = button.getAttribute('data-latebreakdown');
                document.getElementById('r_total').textContent = button.getAttribute('data-total');
                
                // Actual End Time (Current local timestamp)
                const now = new Date();
                const options = { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true };
                document.getElementById('r_actual').textContent = now.toLocaleString('en-US', options);

                // Penalty Handling
                const lateHours = parseInt(button.getAttribute('data-latehours')) || 0;
                const penaltyRow = document.getElementById('r_penalty_row');
                
                if (lateHours > 0) {
                    penaltyRow.style.setProperty('display', 'flex', 'important');
                    document.getElementById('r_latehours').textContent = lateHours;
                    document.getElementById('r_penalty').textContent = button.getAttribute('data-penalty');
                } else {
                    penaltyRow.style.setProperty('display', 'none', 'important');
                }
            });
        }

        const assignDriverModal = document.getElementById('assignDriverModal');
        if(assignDriverModal) {
            assignDriverModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                document.getElementById('assign_booking_id').value = button.getAttribute('data-id');
                document.getElementById('driverSelect').value = button.getAttribute('data-driver') || '';
            });
        }

        const editStatusModal = document.getElementById('editStatusModal');
        if(editStatusModal) {
            editStatusModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                document.getElementById('status_booking_id').value = button.getAttribute('data-id');
                document.getElementById('statusSelect').value = button.getAttribute('data-status');
            });
        }

        const deleteBookingModal = document.getElementById('deleteBookingModal');
        if(deleteBookingModal) {
            deleteBookingModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                document.getElementById('delete_booking_id').value = button.getAttribute('data-id');
            });
        }
    });
</script>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>