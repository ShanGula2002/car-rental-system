<?php
// customer/my_bookings.php - Customer Reservation History with Live Leaflet Routing Machine Integration
session_start();
require_once '../db.php';

// Auth Guard: Customer access only
if (!isset($_SESSION['user_id']) || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')) {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Generate CSRF Token for security
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==========================================
// 1. LIVE AJAX ENDPOINT: FETCH DRIVER LOCATION
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'get_driver_location') {
    header('Content-Type: application/json');
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");

    $booking_id = intval($_GET['booking_id'] ?? 0);

    if ($booking_id > 0) {
        try {
            // Fetch vehicle_id associated with this booking
            $bStmt = $pdo->prepare("SELECT vehicle_id FROM bookings WHERE booking_id = :booking_id");
            $bStmt->execute([':booking_id' => $booking_id]);
            $bookingData = $bStmt->fetch(PDO::FETCH_ASSOC);
            $vehicle_id = $bookingData['vehicle_id'] ?? 0;

            // Search driver locations by booking_id OR vehicle_id using robust ordering
            $stmt = $pdo->prepare("
                SELECT 
                    current_latitude AS lat,
                    current_longitude AS lng,
                    total_mileage,
                    status AS trip_status,
                    updated_at
                FROM driver_locations 
                WHERE booking_id = :booking_id OR vehicle_id = :vehicle_id
                ORDER BY updated_at DESC, location_id DESC 
                LIMIT 1
            ");
            $stmt->execute([
                ':booking_id' => $booking_id,
                ':vehicle_id' => $vehicle_id
            ]);
            $loc = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($loc && !empty($loc['lat']) && floatval($loc['lat']) != 0 && !empty($loc['lng']) && floatval($loc['lng']) != 0) {
                echo json_encode([
                    'success'     => true,
                    'lat'         => floatval($loc['lat']),
                    'lng'         => floatval($loc['lng']),
                    'mileage'     => floatval($loc['total_mileage'] ?? 0),
                    'trip_status' => $loc['trip_status'] ?? '',
                    'updated'     => $loc['updated_at']
                ]);
                exit;
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'No active driver signal found']);
    exit;
}

// Check session flash messages
$flash_message = '';
$flash_type = '';
if (isset($_SESSION['success'])) {
    $flash_message = $_SESSION['success'];
    $flash_type = 'success';
    unset($_SESSION['success']);
} elseif (isset($_SESSION['error'])) {
    $flash_message = $_SESSION['error'];
    $flash_type = 'danger';
    unset($_SESSION['error']);
}

// ==========================================
// 2. HANDLE CANCELLATION REQUEST (PRG Pattern)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_booking') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Security check failed. Please try again.";
        header('Location: my_bookings.php');
        exit;
    }

    $booking_id = intval($_POST['booking_id'] ?? 0);

    if ($booking_id > 0) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = :id AND user_id = :user_id FOR UPDATE");
            $stmt->execute([':id' => $booking_id, ':user_id' => $user_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($booking && in_array(strtolower($booking['status']), ['pending', 'approved', 'ongoing', 'accepted'])) {
                $updateStmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE booking_id = :id");
                $updateStmt->execute([':id' => $booking_id]);

                if (!empty($booking['vehicle_id'])) {
                    $pdo->prepare("UPDATE vehicles SET status = 'available' WHERE vehicle_id = :vid")->execute([':vid' => $booking['vehicle_id']]);
                }

                $pdo->commit();
                $_SESSION['success'] = "Booking #{$booking_id} has been successfully cancelled.";
            } else {
                $pdo->rollBack();
                $_SESSION['error'] = "Unable to cancel booking. It may already be completed, active, or cancelled.";
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = "Database Error: " . $e->getMessage();
        }
    }
    header('Location: my_bookings.php');
    exit;
}

// ==========================================
// 3. FETCH CUSTOMER PROFILE & BOOKINGS
// ==========================================
try {
    $userStmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE user_id = :id");
    $userStmt->execute([':id' => $user_id]);
    $customer = $userStmt->fetch(PDO::FETCH_ASSOC);

    $bookingsStmt = $pdo->prepare("
        SELECT 
            b.*,
            COALESCE(b.pickup_latitude, 0) AS pickup_latitude,
            COALESCE(b.pickup_longitude, 0) AS pickup_longitude,
            v.brand, 
            v.model_name, 
            v.plate_number,
            dl.current_latitude AS driver_latitude,
            dl.current_longitude AS driver_longitude,
            dl.total_mileage,
            dl.status AS trip_status,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(v.brand, ''), ' ', COALESCE(v.model_name, ''))), ''), CONCAT('Vehicle #', b.vehicle_id)) AS full_vehicle_title
        FROM bookings b
        LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
        LEFT JOIN (
            SELECT dl1.*
            FROM driver_locations dl1
            INNER JOIN (
                SELECT MAX(location_id) AS max_id
                FROM driver_locations
                GROUP BY COALESCE(booking_id, vehicle_id)
            ) dl2 ON dl1.location_id = dl2.max_id
        ) dl ON (dl.booking_id = b.booking_id OR dl.vehicle_id = b.vehicle_id)
        WHERE b.user_id = :user_id
        ORDER BY b.booking_id DESC
    ");
    $bookingsStmt->execute([':user_id' => $user_id]);
    $bookings = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reservations | GRLE Car Rental</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Leaflet Routing Machine CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css" />

    <style>
        :root {
            --bg-body: #0d0e12;
            --bg-surface: #16181e;
            --bg-card: #1f222b;
            --border-color: #2e323e;
            --text-primary: #ffffff;
            --text-muted: #c5c6d0;
            --grle-red: #e53935;
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
        }
        .navbar-brand { font-weight: 700; letter-spacing: 0.5px; }
        .brand-logo-image { display: block; width: 115px; height: auto; }
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

        .text-contrast-muted { color: var(--text-muted) !important; }

        .content-panel {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 22px;
        }

        .filter-btn {
            background-color: var(--bg-surface);
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 6px 16px;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .filter-btn:hover, .filter-btn.active {
            background-color: var(--grle-red);
            color: #ffffff;
            border-color: var(--grle-red);
        }

        .booking-item-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        .booking-item-card:hover {
            border-color: rgba(229, 57, 53, 0.4);
            transform: translateY(-2px);
        }

        .info-pill {
            background-color: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 10px 14px;
        }

        .leaflet-map-container {
            height: 280px;
            width: 100%;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: #12141a;
            z-index: 1;
        }

        /* Hide the default Leaflet Routing text instruction box, keep only the route line on map */
        .leaflet-routing-container { display: none !important; }

        .modal-content {
            background-color: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }
        .modal-header, .modal-footer { border-color: var(--border-color); }

        .booking-item-card,
        .info-pill,
        .booking-item-card h5 {
            min-width: 0;
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

            .content-panel,
            .booking-item-card {
                padding: 16px !important;
            }

            .filter-btn {
                flex: 1 1 auto;
                text-align: center;
            }

            .booking-item-card > div:first-child > .d-flex:first-child {
                gap: 0.75rem;
            }

            .booking-item-card h5 {
                overflow-wrap: anywhere;
            }

            .info-pill {
                padding: 9px 10px;
            }

            .leaflet-map-container {
                height: 220px;
            }

            .leaflet-map-container + * {
                flex-wrap: wrap;
                gap: 0.35rem;
            }

            .modal-footer {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .modal-footer .btn {
                flex: 1 1 100%;
                margin: 0;
            }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center text-white" href="customer_dashboard.php">
                <img src="../logo.png" alt="GRLE Car Rental Shop" class="brand-logo-image">
            </a>

            <button class="navbar-toggler border-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse justify-content-between" id="navbarNav">
                <ul class="navbar-nav mx-auto gap-1">
                    <li class="nav-item">
                        <a href="customer_dashboard.php" class="nav-link nav-link-custom"><i class="fa-solid fa-gauge me-1"></i> Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a href="browse_vehicles.php" class="nav-link nav-link-custom"><i class="fa-solid fa-car me-1"></i> Browse Vehicles</a>
                    </li>
                    <li class="nav-item">
                        <a href="my_bookings.php" class="nav-link nav-link-custom active"><i class="fa-solid fa-calendar-check me-1"></i> My Reservations</a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link nav-link-custom"><i class="fa-solid fa-user-gear me-1"></i> Account Profile</a>
                    </li>
                </ul>

                <div class="d-flex align-items-center gap-3 mt-3 mt-lg-0">
                    <span class="text-white small fw-semibold">
                        <i class="fa-solid fa-user-circle text-danger me-1"></i> <?= htmlspecialchars(($customer['first_name'] ?? 'Valued') . ' ' . ($customer['last_name'] ?? 'Customer')) ?>
                    </span>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm fw-bold px-3"><i class="fa-solid fa-right-from-bracket me-1"></i> Sign Out</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 pb-3 border-bottom border-secondary gap-3">
            <div>
                <h3 class="fw-bold mb-1 text-white">My Reservations</h3>
                <p class="text-contrast-muted small mb-0">View, track live vehicle locations, mileage, and manage all your rental bookings.</p>
            </div>
            <div>
                <a href="browse_vehicles.php" class="btn btn-danger fw-bold shadow-sm"><i class="fas fa-plus me-1"></i> Book a Vehicle</a>
            </div>
        </div>

        <?php if (!empty($flash_message)): ?>
            <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show text-white fw-medium mb-4" role="alert">
                <i class="fas fa-info-circle me-2"></i> <?= htmlspecialchars($flash_message) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (empty($bookings)): ?>
            <div class="content-panel text-center py-5">
                <i class="fa-solid fa-calendar-xmark fa-3x mb-3 d-block text-secondary"></i>
                <h5 class="text-white fw-bold">No Reservations Found</h5>
                <p class="text-contrast-muted small mx-auto mb-4" style="max-width: 420px;">You haven't made any vehicle reservations yet.</p>
                <a href="browse_vehicles.php" class="btn btn-danger fw-bold px-4"><i class="fa-solid fa-car me-1"></i> Browse Vehicles</a>
            </div>
        <?php else: ?>

            <div class="d-flex flex-wrap gap-2 mb-4 align-items-center">
                <span class="text-contrast-muted small me-2"><i class="fa-solid fa-filter me-1"></i> Filter:</span>
                <button class="filter-btn active" onclick="filterBookings('all', this)">All (<?= count($bookings) ?>)</button>
                <button class="filter-btn" onclick="filterBookings('active', this)">Active / Approved</button>
                <button class="filter-btn" onclick="filterBookings('pending', this)">Pending</button>
                <button class="filter-btn" onclick="filterBookings('completed', this)">Completed</button>
                <button class="filter-btn" onclick="filterBookings('cancelled', this)">Cancelled</button>
            </div>

            <div class="row g-4" id="bookingsGrid">
                <?php foreach ($bookings as $b): ?>
                    <?php
                        try {
                            $pickup = new DateTime($b['pickup_date']);
                            $return = new DateTime($b['return_date']);
                            $days   = $pickup->diff($return)->days ?: 1;
                        } catch (Exception $e) {
                            $days = 1;
                        }

                        $has_pickup_coords = (!empty($b['pickup_latitude']) && !empty($b['pickup_longitude']) && floatval($b['pickup_latitude']) != 0 && floatval($b['pickup_longitude']) != 0);
                        $has_driver_coords = (!empty($b['driver_latitude']) && !empty($b['driver_longitude']) && floatval($b['driver_latitude']) != 0 && floatval($b['driver_longitude']) != 0);

                        $status_clean = strtolower($b['status']);
                        $is_active_trip = in_array($status_clean, ['approved', 'ongoing', 'accepted', 'confirmed', 'active']);
                        $mileage        = floatval($b['total_mileage'] ?? 0);

                        $filter_category = 'other';
                        if ($is_active_trip) {
                            $filter_category = 'active';
                        } elseif ($status_clean === 'pending') {
                            $filter_category = 'pending';
                        } elseif ($status_clean === 'completed') {
                            $filter_category = 'completed';
                        } elseif (in_array($status_clean, ['cancelled', 'rejected'])) {
                            $filter_category = 'cancelled';
                        }

                        $badge_class = [
                            'pending'   => 'bg-warning text-dark',
                            'approved'  => 'bg-primary text-white',
                            'ongoing'   => 'bg-info text-dark',
                            'completed' => 'bg-success text-white',
                            'cancelled' => 'bg-secondary text-white'
                        ][$status_clean] ?? 'bg-secondary text-white';
                    ?>
                    <div class="col-12 col-lg-6 booking-card-wrapper" data-category="<?= $filter_category ?>">
                        <div class="booking-item-card p-4 h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <span class="text-danger fw-bold small text-uppercase d-block">Booking #<?= htmlspecialchars($b['booking_id']) ?></span>
                                        <h5 class="fw-bold text-white mb-0 mt-1"><?= htmlspecialchars($b['full_vehicle_title']) ?></h5>
                                        <?php if (!empty($b['plate_number'])): ?>
                                            <span class="badge bg-secondary text-uppercase fs-8 mt-1"><?= htmlspecialchars($b['plate_number']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="badge <?= $badge_class ?> text-uppercase px-3 py-2 fw-semibold" style="font-size: 0.75rem;">
                                        <?= htmlspecialchars($b['status']) ?>
                                    </span>
                                </div>

                                <div class="row g-2 my-3">
                                    <div class="col-6">
                                        <div class="info-pill h-100">
                                            <span class="text-contrast-muted small d-block mb-1"><i class="fa-regular fa-calendar-check text-success me-1"></i> Pickup Date</span>
                                            <strong class="text-white small d-block"><?= date('M d, Y', strtotime($b['pickup_date'])) ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="info-pill h-100">
                                            <span class="text-contrast-muted small d-block mb-1"><i class="fa-regular fa-calendar-xmark text-danger me-1"></i> Return Date</span>
                                            <strong class="text-white small d-block"><?= date('M d, Y', strtotime($b['return_date'])) ?> (<?= $days ?> <?= $days > 1 ? 'days' : 'day' ?>)</strong>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="info-pill h-100">
                                            <span class="text-contrast-muted small d-block mb-1"><i class="fa-solid fa-money-bill-wave text-success me-1"></i> Total Price</span>
                                            <strong class="text-success fs-6">₱<?= number_format($b['total_price'], 2) ?></strong>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="info-pill h-100">
                                            <span class="text-contrast-muted small d-block mb-1"><i class="fa-solid fa-gauge-high text-info me-1"></i> Logged Mileage</span>
                                            <strong class="text-info fs-6" id="mileage-val-<?= $b['booking_id'] ?>"><?= number_format($mileage, 1) ?> <span class="fs-8">km</span></strong>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="info-pill d-flex justify-content-between align-items-center">
                                            <span class="text-contrast-muted small"><i class="fa-solid fa-map-pin text-danger me-1"></i> Location & GPS</span>
                                            <?php if ($has_pickup_coords): ?>
                                                <button type="button" class="btn btn-link p-0 text-danger text-decoration-none small fw-semibold shadow-none" 
                                                        data-bs-toggle="collapse" 
                                                        data-bs-target="#mapCollapse<?= $b['booking_id'] ?>">
                                                    <i class="fa-solid fa-map-location-dot me-1"></i> Live GPS Map <i class="fa-solid fa-chevron-down ms-1"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-contrast-muted small">Standard Depot Pickup (No Pin)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- LEAFLET MAP COLLAPSIBLE PANEL WITH ROUTING MACHINE -->
                                <?php if ($has_pickup_coords): ?>
                                    <div class="collapse map-collapse-container my-3" 
                                         id="mapCollapse<?= $b['booking_id'] ?>"
                                         data-booking-id="<?= $b['booking_id'] ?>"
                                         data-pickup-lat="<?= $b['pickup_latitude'] ?>"
                                         data-pickup-lng="<?= $b['pickup_longitude'] ?>"
                                         data-driver-lat="<?= $has_driver_coords ? $b['driver_latitude'] : '' ?>"
                                         data-driver-lng="<?= $has_driver_coords ? $b['driver_longitude'] : '' ?>">
                                        <div class="p-2 rounded border border-secondary" style="background-color: var(--bg-card);">
                                            <div class="d-flex justify-content-between align-items-center mb-2 px-1">
                                                <span class="small text-white fw-semibold"><i class="fa-solid fa-route me-1 text-danger"></i> Pickup vs Driver Route</span>
                                                <span class="badge bg-primary fs-8"><i class="fa-solid fa-signal me-1"></i> Live Polling Active</span>
                                            </div>

                                            <div id="map-booking-<?= $b['booking_id'] ?>" class="leaflet-map-container"></div>
                                            
                                            <div class="d-flex justify-content-between mt-2 px-1 text-contrast-muted fs-8">
                                                <span><i class="fa-solid fa-flag-checkered text-info me-1"></i> Customer Pickup</span>
                                                <span id="dist-badge-<?= $b['booking_id'] ?>"><i class="fa-solid fa-spinner fa-spin text-info me-1"></i> Checking signal...</span>
                                                <span><i class="fa-solid fa-car text-danger me-1"></i> Driver Live Pin</span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                            </div>

                            <div class="pt-3 border-top border-secondary d-flex align-items-center justify-content-between mt-2">
                                <span class="text-contrast-muted small">Requested: <?= !empty($b['created_at']) ? date('M d, Y', strtotime($b['created_at'])) : 'N/A' ?></span>

                                <?php if (in_array($status_clean, ['pending', 'approved'])): ?>
                                    <button type="button" class="btn btn-outline-danger btn-sm px-3 fw-semibold" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#cancelBookingModal" 
                                            data-id="<?= $b['booking_id'] ?>">
                                        <i class="fa-solid fa-ban me-1"></i> Cancel
                                    </button>
                                <?php else: ?>
                                    <span class="text-contrast-muted small">No actions available</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- MODAL: Cancel Reservation -->
    <div class="modal fade" id="cancelBookingModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="my_bookings.php">
                    <input type="hidden" name="action" value="cancel_booking">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="booking_id" id="cancel_booking_id">
                    
                    <div class="modal-header">
                        <h5 class="modal-title text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Cancel Reservation</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-contrast-muted">
                        Are you sure you want to cancel this booking? This action will release the vehicle reservation and cannot be undone.
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Booking</button>
                        <button type="submit" class="btn btn-danger fw-bold">Confirm Cancellation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <!-- Leaflet Routing Machine JS -->
    <script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>

    <script>
        const mapObjects = {};
        const routingControls = {};
        const pollIntervals = {};

        // ==========================================
        // 1. INITIALIZE MAP & CUSTOMER PICKUP PIN
        // ==========================================
        function initBookingMap(bookingId, pLat, pLng, dLat, dLng) {
            const containerId = `map-booking-${bookingId}`;
            if (mapObjects[bookingId]) {
                setTimeout(() => {
                    mapObjects[bookingId].map.invalidateSize();
                }, 200);
                return; 
            }

            const pickupLat = parseFloat(pLat);
            const pickupLng = parseFloat(pLng);

            if (isNaN(pickupLat) || isNaN(pickupLng) || pickupLat === 0 || pickupLng === 0) {
                console.error("Invalid pickup coordinates for booking #" + bookingId);
                return;
            }

            const pickupLatLng = L.latLng(pickupLat, pickupLng);

            const map = L.map(containerId).setView(pickupLatLng, 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19
            }).addTo(map);

            const pickupMarker = L.marker(pickupLatLng, {
                icon: L.divIcon({
                    className: 'customer-pin-icon',
                    html: '<div style="background-color:#0288d1;color:white;padding:5px 8px;border-radius:50%;text-align:center;font-weight:bold;box-shadow: 0 2px 5px rgba(0,0,0,0.3);"><i class="fas fa-flag-checkered"></i></div>',
                    iconSize: [30, 30],
                    iconAnchor: [15, 15]
                })
            }).addTo(map).bindPopup("<b>Customer Pickup Location</b>");

            mapObjects[bookingId] = {
                map: map,
                pickupLatLng: pickupLatLng,
                pickupMarker: pickupMarker,
                driverMarker: null
            };

            updateDriverLocation(bookingId, dLat, dLng, true);

            setTimeout(() => {
                map.invalidateSize();
            }, 250);
        }

        // ==========================================
        // 2. UPDATE DRIVER LOCATION & ROUTE LINE
        // ==========================================
        function updateDriverLocation(bookingId, dLat, dLng, isInitialLoad = false) {
            const state = mapObjects[bookingId];
            if (!state) return; 

            const driverLat = parseFloat(dLat);
            const driverLng = parseFloat(dLng);
            const distBadge = document.getElementById(`dist-badge-${bookingId}`);

            if (isNaN(driverLat) || isNaN(driverLng) || driverLat === 0 || driverLng === 0) {
                if (distBadge) {
                    distBadge.innerHTML = `<i class="fa-solid fa-circle-exclamation text-warning me-1"></i> Waiting for driver GPS signal...`;
                }
                return; 
            }

            const driverLatLng = L.latLng(driverLat, driverLng); 

            if (!state.driverMarker) {
                state.driverMarker = L.marker(driverLatLng, {
                    icon: L.divIcon({
                        className: 'driver-pin-icon',
                        html: '<div style="background-color:#e53935;color:white;padding:5px 8px;border-radius:50%;text-align:center;font-weight:bold;box-shadow: 0 2px 5px rgba(0,0,0,0.3);"><i class="fas fa-car"></i></div>',
                        iconSize: [30, 30],
                        iconAnchor: [15, 15]
                    })
                }).addTo(state.map).bindPopup("<b>Driver Live Location</b>");
            } else {
                state.driverMarker.setLatLng(driverLatLng);
            }

            if (!routingControls[bookingId]) {
                routingControls[bookingId] = L.Routing.control({
                    waypoints: [driverLatLng, state.pickupLatLng],
                    lineOptions: { styles: [{ color: '#e53935', opacity: 0.9, weight: 5 }] },
                    createMarker: function() { return null; },
                    show: false,
                    addWaypoints: false,
                    fitSelectedRoutes: false
                }).addTo(state.map);
            } else {
                routingControls[bookingId].setWaypoints([driverLatLng, state.pickupLatLng]);
            }

            if (distBadge) {
                try {
                    const distanceMeters = state.pickupLatLng.distanceTo(driverLatLng);
                    let distanceDisplay = "";

                    if (distanceMeters < 25) {
                        distanceDisplay = `<span class="text-success fw-bold"><i class="fa-solid fa-check-circle me-1"></i> Arrived</span>`;
                    } else if (distanceMeters < 1000) {
                        distanceDisplay = `<strong>${Math.round(distanceMeters)} m</strong> away`;
                    } else {
                        distanceDisplay = `<strong>${(distanceMeters / 1000).toFixed(2)} km</strong> away`;
                    }

                    distBadge.innerHTML = `<i class="fa-solid fa-route text-info me-1"></i> ${distanceDisplay}`;
                } catch (error) {
                    distBadge.innerHTML = `<i class="fa-solid fa-circle-exclamation text-danger me-1"></i> Calc Error`;
                }
            }

            if (isInitialLoad) {
                try {
                    state.map.fitBounds(L.latLngBounds([state.pickupLatLng, driverLatLng]), { padding: [40, 40] });
                } catch (e) {
                    state.map.setView(state.pickupLatLng, 15);
                }
            }
        }

        // ==========================================
        // 3. AJAX POLLING FOR REAL-TIME SYNC
        // ==========================================
        function pollDriverLocation(bookingId) {
            const url = `my_bookings.php?action=get_driver_location&booking_id=${bookingId}&_t=${new Date().getTime()}`;
            
            fetch(url, { cache: 'no-store' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateDriverLocation(bookingId, data.lat, data.lng, false);
                        
                        const mileageEl = document.getElementById(`mileage-val-${bookingId}`);
                        if (mileageEl && data.mileage !== undefined && data.mileage !== null) {
                            mileageEl.innerHTML = `${parseFloat(data.mileage).toFixed(1)} <span class="fs-8">km</span>`;
                        }
                    }
                })
                .catch(error => console.error('Live Map Polling Error:', error));
        }

        // ==========================================
        // 4. EVENT LISTENERS FOR COLLAPSIBLES & MODALS
        // ==========================================
        document.addEventListener('DOMContentLoaded', () => {
            const collapses = document.querySelectorAll('.map-collapse-container');
            
            collapses.forEach(collapse => {
                collapse.addEventListener('shown.bs.collapse', function () {
                    const bookingId = this.dataset.bookingId;
                    
                    initBookingMap(
                        bookingId, 
                        this.dataset.pickupLat, 
                        this.dataset.pickupLng, 
                        this.dataset.driverLat, 
                        this.dataset.driverLng
                    );

                    pollDriverLocation(bookingId);

                    if (!pollIntervals[bookingId]) {
                        pollIntervals[bookingId] = setInterval(() => pollDriverLocation(bookingId), 5000);
                    }
                });

                collapse.addEventListener('hidden.bs.collapse', function () {
                    const bookingId = this.dataset.bookingId;
                    if (pollIntervals[bookingId]) {
                        clearInterval(pollIntervals[bookingId]);
                        delete pollIntervals[bookingId];
                    }
                });
            });

            const cancelModal = document.getElementById('cancelBookingModal');
            if (cancelModal) {
                cancelModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    document.getElementById('cancel_booking_id').value = button.getAttribute('data-id');
                });
            }
        });

        function filterBookings(category, btn) {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const wrappers = document.querySelectorAll('.booking-card-wrapper');
            wrappers.forEach(wrapper => {
                if (category === 'all' || wrapper.dataset.category === category) {
                    wrapper.style.display = 'block';
                } else {
                    wrapper.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html> 
