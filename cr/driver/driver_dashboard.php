<?php
// driver_dashboard.php - Driver Portal & Trip Management Center with Live GPS Tracking & Smart Routing
session_start();
require_once '../db.php';
require_once '../maintenance_rules.php';
require_once '../late_return.php';

if (isset($pdo)) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['driver', 'admin'])) {
    header('Location: login.php?role=driver');
    exit;
}

$active_page = 'driver_dashboard';
$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);
$driver_id = $_SESSION['user_id'];

// ==========================================
// 1. DYNAMIC ACTION HANDLERS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'update_trip_status') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $trip_status = $_POST['trip_status'] ?? '';
        $start_lat = !empty($_POST['start_latitude']) ? floatval($_POST['start_latitude']) : 0.000000;
        $start_lng = !empty($_POST['start_longitude']) ? floatval($_POST['start_longitude']) : 0.000000;
        $end_lat = !empty($_POST['end_latitude']) ? floatval($_POST['end_latitude']) : null;
        $end_lng = !empty($_POST['end_longitude']) ? floatval($_POST['end_longitude']) : null;

        if ($booking_id > 0 && in_array($trip_status, ['ongoing', 'completed'])) {
            try {
                if ($trip_status === 'ongoing') {
                    $vehicle_stmt = $pdo->prepare('SELECT vehicle_id FROM bookings WHERE booking_id = :booking_id AND driver_id = :driver_id');
                    $vehicle_stmt->execute([
                        ':booking_id' => $booking_id,
                        ':driver_id' => $driver_id
                    ]);
                    $vehicle_id = (int) ($vehicle_stmt->fetchColumn() ?? 0);
                    $maintenance_alert = $vehicle_id > 0 ? get_vehicle_maintenance_alert($pdo, $vehicle_id) : null;

                    if ($maintenance_alert) {
                        $_SESSION['flash_message'] = $maintenance_alert['label'] . ': ' . $maintenance_alert['message'];
                        $_SESSION['flash_type'] = $maintenance_alert['status'] === 'overdue' ? 'danger' : 'warning';
                        header('Location: driver_dashboard.php');
                        exit;
                    }
                }

                $b_stmt = $pdo->prepare("SELECT b.return_date, b.total_price, b.status, v.daily_rate FROM bookings b JOIN vehicles v ON v.vehicle_id = b.vehicle_id WHERE b.booking_id = :id");
                $b_stmt->execute([':id' => $booking_id]);
                $bk_data = $b_stmt->fetch(PDO::FETCH_ASSOC);

                $stmt = $pdo->prepare("UPDATE bookings SET status = :status WHERE booking_id = :id");
                $stmt->execute([':status' => $trip_status, ':id' => $booking_id]);

                if ($trip_status === 'ongoing') {
                    // Fetch vehicle and customer details (ignoring booking pickup coords, using live device coords instead)
                    $b_stmt = $pdo->prepare("SELECT vehicle_id, user_id, driver_id FROM bookings WHERE booking_id = :id");
                    $b_stmt->execute([':id' => $booking_id]);
                    $bk_data = $b_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($bk_data) {
                        $v_id = !empty($bk_data['vehicle_id']) ? intval($bk_data['vehicle_id']) : null;
                        $c_id = !empty($bk_data['user_id']) ? intval($bk_data['user_id']) : null;
                        $d_id = !empty($bk_data['driver_id']) ? intval($bk_data['driver_id']) : intval($driver_id);

                        $check_loc = $pdo->prepare("SELECT location_id FROM driver_locations WHERE booking_id = :booking_id");
                        $check_loc->execute([':booking_id' => $booking_id]);
                        
                        if (!$check_loc->fetch()) {
                            $ins_loc = $pdo->prepare("
                                INSERT INTO driver_locations 
                                (booking_id, vehicle_id, driver_id, customer_id, start_latitude, start_longitude, current_latitude, current_longitude, status, created_at, updated_at) 
                                VALUES (:booking_id, :vehicle_id, :driver_id, :customer_id, :start_lat, :start_lng, :curr_lat, :curr_lng, 'en_route', NOW(), NOW())
                            ");
                            $ins_loc->execute([
                                ':booking_id'  => $booking_id,
                                ':vehicle_id'  => $v_id,
                                ':driver_id'   => $d_id,
                                ':customer_id' => $c_id,
                                ':start_lat'   => $start_lat,
                                ':start_lng'   => $start_lng,
                                ':curr_lat'    => $start_lat,
                                ':curr_lng'    => $start_lng
                            ]);
                        }
                    }
                } 
                elseif ($trip_status === 'completed') {
                    if ($bk_data && $bk_data['status'] !== 'completed') {
                        $late_charge = calculate_late_return_fee($bk_data['return_date'], (float) $bk_data['daily_rate']);
                        if ($late_charge['fee'] > 0) {
                            $charge_stmt = $pdo->prepare("UPDATE bookings SET total_price = total_price + :late_fee WHERE booking_id = :booking_id");
                            $charge_stmt->execute([
                                ':late_fee' => $late_charge['fee'],
                                ':booking_id' => $booking_id
                            ]);
                        }
                    }

                    $upd_loc = $pdo->prepare("
                        UPDATE driver_locations 
                        SET status = 'completed', 
                            end_latitude = COALESCE(:end_lat, current_latitude), 
                            end_longitude = COALESCE(:end_lng, current_longitude), 
                            updated_at = NOW() 
                        WHERE booking_id = :booking_id
                    ");
                    $upd_loc->execute([
                        ':end_lat'    => $end_lat,
                        ':end_lng'    => $end_lng,
                        ':booking_id' => $booking_id
                    ]);
                }

                $flash_message = "Trip #" . $booking_id . " status updated successfully.";
                $flash_type = "success";
            } catch (PDOException $e) {
                error_log("DB Error in trip update: " . $e->getMessage());
                $flash_message = "Database Error: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }
}

// ==========================================
// 2. FETCH REAL-TIME DRIVER DATA
// ==========================================
try {
    $driver_stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :id");
    $driver_stmt->execute([':id' => $driver_id]);
    $current_driver = $driver_stmt->fetch();

    $active_trips_count = $pdo->query("SELECT COUNT(*) FROM bookings WHERE driver_id = $driver_id AND status IN ('approved', 'ongoing')")->fetchColumn();
    $completed_trips_count = $pdo->query("SELECT COUNT(*) FROM bookings WHERE driver_id = $driver_id AND status = 'completed'")->fetchColumn();
    
    $assigned_trips_stmt = $pdo->prepare("
        SELECT b.booking_id, b.pickup_date, b.return_date, b.total_price, b.status,
               b.pickup_latitude, b.pickup_longitude,
               c.first_name AS cust_first, c.last_name AS cust_last, c.phone AS cust_phone,
               v.brand, v.model_name, v.plate_number, v.category
        FROM bookings b
        JOIN users c ON b.user_id = c.user_id
        JOIN vehicles v ON b.vehicle_id = v.vehicle_id
        WHERE b.driver_id = :driver_id AND b.status IN ('approved', 'ongoing', 'completed')
        ORDER BY b.pickup_date DESC
        LIMIT 10
    ");
    $assigned_trips_stmt->execute([':driver_id' => $driver_id]);
    $assigned_trips = $assigned_trips_stmt->fetchAll();

    $ongoing_trip_stmt = $pdo->prepare("
        SELECT b.booking_id, b.pickup_latitude, b.pickup_longitude,
               c.first_name AS cust_first, c.last_name AS cust_last, c.phone AS cust_phone,
               v.brand, v.model_name
        FROM bookings b
        JOIN users c ON b.user_id = c.user_id
        JOIN vehicles v ON b.vehicle_id = v.vehicle_id
        WHERE b.driver_id = :driver_id AND b.status = 'ongoing'
        LIMIT 1
    ");
    $ongoing_trip_stmt->execute([':driver_id' => $driver_id]);
    $ongoing_trip = $ongoing_trip_stmt->fetch();

} catch (PDOException $e) {
    error_log($e->getMessage());
    die("Database Error.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Dashboard | GRLE Car Rental</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
    body { font-family: 'Poppins', sans-serif; background-color: var(--bg-body); color: var(--text-primary); min-height: 100vh; }
    .navbar-custom { background-color: var(--bg-surface); border-bottom: 1px solid var(--border-color); padding: 15px 0; }
    .brand-logo-image { display: block; width: 115px; height: auto; }
    .portal-label { margin-left: 10px; }
    .nav-link.custom-link { color: var(--text-muted) !important; font-weight: 500; padding: 8px 16px; margin: 0 5px; border-radius: 6px; transition: all 0.3s ease; }
    .nav-link.custom-link:hover { color: var(--text-primary) !important; background-color: rgba(255, 255, 255, 0.05); }
    .nav-link.custom-link.active { color: #fff !important; background-color: var(--grle-red); }
    .main-content { padding: 30px 15px; }
    .text-contrast-muted { color: var(--text-muted) !important; }
    .stat-card { background-color: var(--bg-card); border: 1px solid var(--border-color); border-left: 4px solid var(--grle-red); border-radius: 12px; padding: 25px 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
    .content-panel { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
    .table-custom { --bs-table-bg: transparent !important; --bs-table-color: #ffffff !important; --bs-table-hover-bg: rgba(255, 255, 255, 0.05) !important; background-color: transparent !important; color: #ffffff !important; }
    .table-custom tr, .table-custom th, .table-custom td { background-color: transparent !important; color: #ffffff !important; border-bottom: 1px solid var(--border-color) !important; }
    .table-custom th { font-weight: 600; text-transform: uppercase; font-size: 0.8rem; background-color: rgba(255, 255, 255, 0.03) !important; }
    #tripMap { height: 450px; width: 100%; border-radius: 10px; border: 1px solid var(--border-color); }
    .leaflet-routing-container { display: none !important; }

    .table-custom { min-width: 760px; }

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

        .stat-card,
        .content-panel {
            padding: 16px;
        }

        .content-panel > .d-flex,
        .content-panel p {
            overflow-wrap: anywhere;
        }

        .content-panel > .d-flex:first-child {
            align-items: flex-start !important;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        #tripMap {
            height: 280px;
        }

        .table-custom {
            min-width: 760px;
        }
    }
</style>
</head>
<body>

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
                <li class="nav-item"><a class="nav-link custom-link active" href="driver_dashboard.php"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link custom-link" href="driver_trips.php"><i class="fas fa-route me-1"></i> My Trips</a></li>
                <li class="nav-item"><a class="nav-link custom-link" href="driver_profile.php"><i class="fas fa-user-circle me-1"></i> Profile</a></li>
            </ul>
            <div class="d-flex align-items-center">
                <div class="me-3 text-end d-none d-lg-block">
                    <span class="d-block text-white fw-semibold lh-1"><?php echo htmlspecialchars(($current_driver['first_name'] ?? 'Driver') . ' ' . ($current_driver['last_name'] ?? '')); ?></span>
                    <small class="text-contrast-muted" style="font-size: 0.75rem;">Driver ID: #<?php echo $driver_id; ?></small>
                </div>
                <a href="logout.php" class="btn btn-outline-danger btn-sm fw-bold"><i class="fas fa-sign-out-alt me-1"></i> Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid main-content px-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h3 class="fw-bold mb-1 text-white">Driver Dashboard</h3>
            <p class="text-contrast-muted small mb-0">Welcome back, manage your assigned trips and monitor live tracking.</p>
        </div>
    </div>

    <?php if (!empty($flash_message)): ?>
        <div class="alert alert-<?php echo $flash_type; ?> alert-dismissible fade show text-white fw-medium mb-4" role="alert">
            <i class="fas fa-info-circle me-2"></i> <?php echo htmlspecialchars($flash_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-xl-4 col-md-6">
            <div class="stat-card" style="border-left-color: #0288d1;">
                <span class="text-contrast-muted small fw-bold text-uppercase d-block">Active / Assigned Trips</span>
                <h2 class="fw-bold text-white mb-0 mt-1"><?php echo $active_trips_count; ?></h2>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="stat-card" style="border-left-color: #388e3c;">
                <span class="text-contrast-muted small fw-bold text-uppercase d-block">Completed Trips</span>
                <h2 class="fw-bold text-white mb-0 mt-1"><?php echo $completed_trips_count; ?></h2>
            </div>
        </div>
        <div class="col-xl-4 col-md-12">
            <div class="stat-card" style="border-left-color: #f57c00;">
                <span class="text-contrast-muted small fw-bold text-uppercase d-block">Duty Status</span>
                <div class="d-flex align-items-center mt-2">
                    <span class="badge bg-success px-3 py-2 fs-6 rounded-pill"><i class="fas fa-check-circle me-1"></i> Ready for Dispatch</span>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($ongoing_trip)): ?>
    <div class="content-panel mb-5">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="fw-bold text-white mb-0"><i class="fas fa-map-marked-alt text-danger me-2"></i>Live Trip Routing #TRP-<?php echo $ongoing_trip['booking_id']; ?></h5>
            <span class="badge bg-danger px-3 py-2 rounded-pill"><i class="fas fa-satellite-dish me-1"></i> Live Tracking</span>
        </div>
        <p class="text-contrast-muted small mb-3 border-bottom border-secondary pb-3">
            Target Customer: <strong class="text-white"><?php echo htmlspecialchars($ongoing_trip['cust_first'] . ' ' . $ongoing_trip['cust_last']); ?></strong> &nbsp;|&nbsp; 
            Vehicle Assigned: <strong class="text-white"><?php echo htmlspecialchars($ongoing_trip['brand'] . ' ' . $ongoing_trip['model_name']); ?></strong>
        </p>
        <div id="tripMap"></div>
    </div>
    <script>
        const customerLat = <?php echo floatval($ongoing_trip['pickup_latitude'] ?? 0); ?>;
        const customerLng = <?php echo floatval($ongoing_trip['pickup_longitude'] ?? 0); ?>;
        const ongoingBookingId = <?php echo intval($ongoing_trip['booking_id']); ?>;
    </script>
    <?php endif; ?>

    <div class="content-panel">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h5 class="fw-bold text-white mb-0"><i class="fas fa-list text-danger me-2"></i>Recent Trip Assignments</h5>
        </div>

        <?php if (empty($assigned_trips)): ?>
            <div class="text-center py-5 text-contrast-muted">
                <i class="fas fa-car-side fs-2 text-secondary mb-3 d-block"></i>
                <p class="mb-0">No trip assignments currently available.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Trip #</th>
                            <th>Customer & Contact</th>
                            <th>Vehicle & Pin</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assigned_trips as $trip): ?>
                            <tr>
                                <td class="fw-bold text-danger">#TRP-<?php echo $trip['booking_id']; ?></td>
                                <td>
                                    <span class="d-block fw-semibold text-white"><?php echo htmlspecialchars($trip['cust_first'] . ' ' . $trip['cust_last']); ?></span>
                                    <small class="text-contrast-muted"><i class="fas fa-phone-alt me-1"></i> <?php echo htmlspecialchars($trip['cust_phone']); ?></small>
                                </td>
                                <td>
                                    <span class="text-white fw-medium d-block"><?php echo htmlspecialchars($trip['brand'] . ' ' . $trip['model_name']); ?></span>
                                    <?php if (!empty($trip['pickup_latitude']) && !empty($trip['pickup_longitude'])): ?>
                                        <small class="text-info d-block mt-1"><i class="fas fa-map-pin me-1"></i>Pin: <?php echo round($trip['pickup_latitude'], 4); ?>, <?php echo round($trip['pickup_longitude'], 4); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-contrast-muted d-block">Pickup: <?php echo date('M d, Y H:i', strtotime($trip['pickup_date'])); ?></small>
                                    <small class="text-contrast-muted d-block">Return: <?php echo date('M d, Y H:i', strtotime($trip['return_date'])); ?></small>
                                </td>
                                <td><span class="badge bg-secondary text-uppercase fs-8"><?php echo $trip['status']; ?></span></td>
                                <td class="text-end">
                                    <form action="driver_dashboard.php" method="POST" class="d-inline trip-action-form">
                                        <input type="hidden" name="action" value="update_trip_status">
                                        <input type="hidden" name="booking_id" value="<?php echo $trip['booking_id']; ?>">
                                        <input type="hidden" name="start_latitude" class="start-lat-input" value="">
                                        <input type="hidden" name="start_longitude" class="start-lng-input" value="">
                                        <input type="hidden" name="end_latitude" class="end-lat-input" value="">
                                        <input type="hidden" name="end_longitude" class="end-lng-input" value="">
                                        <?php if ($trip['status'] === 'approved'): ?>
                                            <button type="submit" name="trip_status" value="ongoing" class="btn btn-sm btn-primary px-3 py-1 start-trip-btn">Start Trip</button>
                                        <?php elseif ($trip['status'] === 'ongoing'): ?>
                                            <button type="submit" name="trip_status" value="completed" class="btn btn-sm btn-success px-3 py-1 complete-trip-btn">Complete Trip</button>
                                        <?php else: ?>
                                            <span class="text-contrast-muted small">Closed</span>
                                        <?php endif; ?>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>
<script>
    let latestDriverLat = null;
    let latestDriverLng = null;

    // Continuously capture GPS location in the background so buttons click instantly
    if (navigator.geolocation) {
        navigator.geolocation.watchPosition(function(position) {
            latestDriverLat = position.coords.latitude;
            latestDriverLng = position.coords.longitude;
        }, function(error) {}, { enableHighAccuracy: true });
    }

    document.addEventListener("DOMContentLoaded", function () {
        // Inject live device GPS coordinates when clicking "Start Trip"
        document.querySelectorAll('.start-trip-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const form = this.closest('form');
                if (latestDriverLat !== null && latestDriverLng !== null) {
                    form.querySelector('.start-lat-input').value = latestDriverLat;
                    form.querySelector('.start-lng-input').value = latestDriverLng;
                }
            });
        });

        // Instant submit: Inject latest known coords immediately on click without blocking for "Complete Trip"
        document.querySelectorAll('.complete-trip-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const form = this.closest('form');
                if (latestDriverLat !== null && latestDriverLng !== null) {
                    form.querySelector('.end-lat-input').value = latestDriverLat;
                    form.querySelector('.end-lng-input').value = latestDriverLng;
                }
            });
        });

        if (document.getElementById('tripMap') && typeof customerLat !== 'undefined' && typeof customerLng !== 'undefined') {
            const map = L.map('tripMap').setView([customerLat || 14.5995, customerLng || 120.9842], 14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);

            L.marker([customerLat, customerLng], {
                icon: L.divIcon({
                    className: 'customer-pin-icon',
                    html: '<div style="background-color:#0288d1;color:white;padding:5px 8px;border-radius:50%;text-align:center;font-weight:bold;"><i class="fas fa-flag-checkered"></i></div>',
                    iconSize: [30, 30]
                })
            }).addTo(map);

            let routingControl = null;
            if (navigator.geolocation) {
                navigator.geolocation.watchPosition(function (position) {
                    const driverLat = position.coords.latitude;
                    const driverLng = position.coords.longitude;

                    if (window.driverMarkerInstance) map.removeLayer(window.driverMarkerInstance);
                    window.driverMarkerInstance = L.marker([driverLat, driverLng], {
                        icon: L.divIcon({
                            className: 'driver-pin-icon',
                            html: '<div style="background-color:#e53935;color:white;padding:5px 8px;border-radius:50%;text-align:center;font-weight:bold;"><i class="fas fa-car"></i></div>',
                            iconSize: [30, 30]
                        })
                    }).addTo(map);

                    if (!routingControl) {
                        routingControl = L.Routing.control({
                            waypoints: [L.latLng(driverLat, driverLng), L.latLng(customerLat, customerLng)],
                            lineOptions: { styles: [{ color: '#e53935', opacity: 0.9, weight: 5 }] },
                            createMarker: function() { return null; },
                            show: false,
                            addWaypoints: false,
                            fitSelectedRoutes: false
                        }).addTo(map);
                    } else {
                        routingControl.setWaypoints([L.latLng(driverLat, driverLng), L.latLng(customerLat, customerLng)]);
                    }

                    if (typeof ongoingBookingId !== 'undefined' && ongoingBookingId > 0) {
                        let formData = new FormData();
                        formData.append('booking_id', ongoingBookingId);
                        formData.append('latitude', driverLat);
                        formData.append('longitude', driverLng);
                        fetch('update_location.php', { method: 'POST', body: formData }).catch(e => console.error(e));
                    }
                }, null, { enableHighAccuracy: true });
            }
        }
    });
</script>
</body>
</html>
