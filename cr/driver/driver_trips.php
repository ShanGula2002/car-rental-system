<?php
// driver_trips.php - Comprehensive Trip Management for Drivers with Live GPS Tracking & Mileage Calculation
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

$active_page = 'driver_trips';
$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);
$driver_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_trip_status') {
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
                    header('Location: driver_trips.php');
                    exit;
                }
            }

            $b_stmt = $pdo->prepare("SELECT b.vehicle_id, b.user_id, b.return_date, b.total_price, b.status, v.daily_rate FROM bookings b JOIN vehicles v ON v.vehicle_id = b.vehicle_id WHERE b.booking_id = :id AND b.driver_id = :driver_id");
            $b_stmt->execute([':id' => $booking_id, ':driver_id' => $driver_id]);
            $bk_data = $b_stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE bookings SET status = :status WHERE booking_id = :id AND driver_id = :driver_id");
            $stmt->execute([':status' => $trip_status, ':id' => $booking_id, ':driver_id' => $driver_id]);

            if ($trip_status === 'ongoing') {
                // Fetch vehicle and customer details using live starting device coordinates passed via POST
                if ($bk_data) {
                    $v_id = !empty($bk_data['vehicle_id']) ? intval($bk_data['vehicle_id']) : null;
                    $c_id = !empty($bk_data['user_id']) ? intval($bk_data['user_id']) : null;

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
                            ':driver_id'   => $driver_id,
                            ':customer_id' => $c_id,
                            ':start_lat'   => $start_lat,
                            ':start_lng'   => $start_lng,
                            ':curr_lat'    => $start_lat,
                            ':curr_lng'    => $start_lng
                        ]);
                    }
                }
            } elseif ($trip_status === 'completed') {
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

                // 1. Fetch original start coordinates from driver_locations
                $loc_stmt = $pdo->prepare("SELECT start_latitude, start_longitude FROM driver_locations WHERE booking_id = :booking_id");
                $loc_stmt->execute([':booking_id' => $booking_id]);
                $loc_data = $loc_stmt->fetch(PDO::FETCH_ASSOC);

                $orig_start_lat = $loc_data ? floatval($loc_data['start_latitude']) : 0.0;
                $orig_start_lng = $loc_data ? floatval($loc_data['start_longitude']) : 0.0;
                
                $final_end_lat = $end_lat !== null ? floatval($end_lat) : 0.0;
                $final_end_lng = $end_lng !== null ? floatval($end_lng) : 0.0;

                // 2. Calculate Total Mileage using Haversine formula
                $total_mileage = 0.00;
                if ($orig_start_lat != 0.0 && $orig_start_lng != 0.0 && $final_end_lat != 0.0 && $final_end_lng != 0.0) {
                    $earthRadius = 6371; // Maintenance intervals are measured in kilometers.
                    $dLat = deg2rad($final_end_lat - $orig_start_lat);
                    $dLon = deg2rad($final_end_lng - $orig_start_lng);
                    $a = sin($dLat/2) * sin($dLat/2) +
                         cos(deg2rad($orig_start_lat)) * cos(deg2rad($final_end_lat)) *
                         sin($dLon/2) * sin($dLon/2);
                    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
                    $total_mileage = round($earthRadius * $c, 2);
                }

                // 3. Update driver_locations with completion status, end coordinates, and total mileage
                $upd_loc = $pdo->prepare("
                    UPDATE driver_locations 
                    SET status = 'completed', 
                        end_latitude = :end_lat, 
                        end_longitude = :end_lng, 
                        total_mileage = :total_mileage,
                        updated_at = NOW() 
                                        WHERE booking_id = :booking_id
                                            AND status = 'en_route'
                ");
                $upd_loc->execute([
                    ':end_lat'       => ($end_lat !== null ? $end_lat : null),
                    ':end_lng'       => ($end_lng !== null ? $end_lng : null),
                    ':total_mileage' => $total_mileage,
                    ':booking_id'    => $booking_id
                ]);

                if ($upd_loc->rowCount() > 0 && $total_mileage > 0 && !empty($bk_data['vehicle_id'])) {
                    $upd_vehicle = $pdo->prepare("UPDATE vehicles SET current_mileage = current_mileage + :trip_distance WHERE vehicle_id = :vehicle_id");
                    $upd_vehicle->execute([
                        ':trip_distance' => $total_mileage,
                        ':vehicle_id'    => (int) $bk_data['vehicle_id']
                    ]);
                }
            }

            $flash_message = "Trip #" . $booking_id . " completed successfully! Total Mileage added to vehicle: " . number_format((float) ($total_mileage ?? 0), 2) . " km.";
            $flash_type = "success";
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $flash_message = "Failed to update trip status.";
            $flash_type = "danger";
        }
    }
}

$filter_status = $_GET['status'] ?? 'all';

try {
    $driver_stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :id");
    $driver_stmt->execute([':id' => $driver_id]);
    $current_driver = $driver_stmt->fetch();

    $query = "
        SELECT b.booking_id, b.pickup_date, b.return_date, b.total_price, b.status,
               b.pickup_latitude, b.pickup_longitude,
               c.first_name AS cust_first, c.last_name AS cust_last, c.phone AS cust_phone, c.email AS cust_email,
               v.brand, v.model_name, v.plate_number, v.category
        FROM bookings b
        JOIN users c ON b.user_id = c.user_id
        JOIN vehicles v ON b.vehicle_id = v.vehicle_id
        WHERE b.driver_id = :driver_id
    ";

    $params = [':driver_id' => $driver_id];

    if ($filter_status !== 'all' && in_array($filter_status, ['approved', 'ongoing', 'completed', 'cancelled'])) {
        $query .= " AND b.status = :status";
        $params[':status'] = $filter_status;
    }

    $query .= " ORDER BY b.pickup_date DESC";

    $trips_stmt = $pdo->prepare($query);
    $trips_stmt->execute($params);
    $trips = $trips_stmt->fetchAll();

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
    <title>My Trips | GRLE Driver Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
    .content-panel { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
    .table-custom { --bs-table-bg: transparent !important; --bs-table-color: #ffffff !important; --bs-table-hover-bg: rgba(255, 255, 255, 0.05) !important; background-color: transparent !important; color: #ffffff !important; }
    .table-custom tr, .table-custom th, .table-custom td { background-color: transparent !important; color: #ffffff !important; border-bottom: 1px solid var(--border-color) !important; }
    .table-custom th { font-weight: 600; text-transform: uppercase; font-size: 0.8rem; background-color: rgba(255, 255, 255, 0.03) !important; }
    .filter-btn { background-color: var(--bg-surface); border: 1px solid var(--border-color); color: var(--text-muted); font-weight: 500; transition: all 0.2s ease; }
    .filter-btn:hover, .filter-btn.active { background-color: var(--grle-red); border-color: var(--grle-red); color: white; }

    .table-custom { min-width: 820px; }

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

        .main-content > .d-flex:first-child {
            align-items: stretch !important;
        }

        .main-content > .d-flex:first-child .btn-group {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .main-content > .d-flex:first-child .filter-btn {
            border-radius: 0;
        }

        .table-custom {
            min-width: 820px;
        }

        .trip-action-form {
            min-width: 118px;
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
                <li class="nav-item"><a class="nav-link custom-link" href="driver_dashboard.php"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link custom-link active" href="driver_trips.php"><i class="fas fa-route me-1"></i> My Trips</a></li>
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
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
        <div>
            <h3 class="fw-bold mb-1 text-white">Trip History & Management</h3>
            <p class="text-contrast-muted small mb-0">View all assigned trips, check passenger details, and update dispatch statuses.</p>
        </div>
        <div class="btn-group" role="group">
            <a href="driver_trips.php?status=all" class="btn filter-btn <?php echo $filter_status === 'all' ? 'active' : ''; ?>">All</a>
            <a href="driver_trips.php?status=approved" class="btn filter-btn <?php echo $filter_status === 'approved' ? 'active' : ''; ?>">Approved</a>
            <a href="driver_trips.php?status=ongoing" class="btn filter-btn <?php echo $filter_status === 'ongoing' ? 'active' : ''; ?>">Ongoing</a>
            <a href="driver_trips.php?status=completed" class="btn filter-btn <?php echo $filter_status === 'completed' ? 'active' : ''; ?>">Completed</a>
        </div>
    </div>

    <?php if (!empty($flash_message)): ?>
        <div class="alert alert-<?php echo $flash_type; ?> alert-dismissible fade show text-white fw-medium mb-4" role="alert">
            <i class="fas fa-info-circle me-2"></i> <?php echo htmlspecialchars($flash_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="content-panel">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h5 class="fw-bold text-white mb-0"><i class="fas fa-list text-danger me-2"></i>Assigned Trips List</h5>
            <span class="badge bg-secondary px-3 py-2 rounded-pill">Total: <?php echo count($trips); ?></span>
        </div>

        <?php if (empty($trips)): ?>
            <div class="text-center py-5 text-contrast-muted">
                <i class="fas fa-route fs-2 text-secondary mb-3 d-block"></i>
                <p class="mb-0">No trips found matching the selected filter.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Trip #</th>
                            <th>Customer Details</th>
                            <th>Vehicle Assignment</th>
                            <th>Schedule & Duration</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trips as $trip): ?>
                            <tr>
                                <td class="fw-bold text-danger">#TRP-<?php echo $trip['booking_id']; ?></td>
                                <td>
                                    <span class="d-block fw-semibold text-white"><?php echo htmlspecialchars($trip['cust_first'] . ' ' . $trip['cust_last']); ?></span>
                                    <small class="text-contrast-muted d-block"><i class="fas fa-phone-alt me-1"></i> <?php echo htmlspecialchars($trip['cust_phone']); ?></small>
                                    <small class="text-contrast-muted d-block"><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($trip['cust_email']); ?></small>
                                </td>
                                <td>
                                    <span class="text-white fw-medium d-block"><?php echo htmlspecialchars($trip['brand'] . ' ' . $trip['model_name']); ?></span>
                                    <small class="text-contrast-muted d-block">Plate: <?php echo htmlspecialchars($trip['plate_number'] ?? 'N/A'); ?></small>
                                    <?php if (!empty($trip['pickup_latitude']) && !empty($trip['pickup_longitude'])): ?>
                                        <small class="text-info d-block mt-1"><i class="fas fa-map-pin me-1"></i>Pin: <?php echo round($trip['pickup_latitude'], 4); ?>, <?php echo round($trip['pickup_longitude'], 4); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-contrast-muted d-block">Pickup: <?php echo date('M d, Y H:i', strtotime($trip['pickup_date'])); ?></small>
                                    <small class="text-contrast-muted d-block">Return: <?php echo date('M d, Y H:i', strtotime($trip['return_date'])); ?></small>
                                </td>
                                <td>
                                    <?php 
                                        $status_badge = 'bg-secondary';
                                        if ($trip['status'] === 'approved') $status_badge = 'bg-info text-dark';
                                        if ($trip['status'] === 'ongoing') $status_badge = 'bg-warning text-dark';
                                        if ($trip['status'] === 'completed') $status_badge = 'bg-success';
                                    ?>
                                    <span class="badge <?php echo $status_badge; ?> text-uppercase px-2 py-1 fs-8"><?php echo $trip['status']; ?></span>
                                </td>
                                <td class="text-end">
                                    <form action="driver_trips.php?status=<?php echo $filter_status; ?>" method="POST" class="d-inline trip-action-form">
                                        <input type="hidden" name="action" value="update_trip_status">
                                        <input type="hidden" name="booking_id" value="<?php echo $trip['booking_id']; ?>">
                                        <input type="hidden" name="start_latitude" class="start-lat-input" value="">
                                        <input type="hidden" name="start_longitude" class="start-lng-input" value="">
                                        <input type="hidden" name="end_latitude" class="end-lat-input" value="">
                                        <input type="hidden" name="end_longitude" class="end-lng-input" value="">
                                        <?php if ($trip['status'] === 'approved'): ?>
                                            <button type="submit" name="trip_status" value="ongoing" class="btn btn-sm btn-primary px-3 py-1 fw-semibold start-trip-btn">Start Trip</button>
                                        <?php elseif ($trip['status'] === 'ongoing'): ?>
                                            <button type="submit" name="trip_status" value="completed" class="btn btn-sm btn-success px-3 py-1 fw-semibold complete-trip-btn">Complete Trip</button>
                                        <?php else: ?>
                                            <a href="driver_dashboard.php" class="btn btn-sm btn-outline-secondary px-3 py-1">View Map</a>
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
<script>
    let latestDriverLat = null;
    let latestDriverLng = null;

    if (navigator.geolocation) {
        navigator.geolocation.watchPosition(function(position) {
            latestDriverLat = position.coords.latitude;
            latestDriverLng = position.coords.longitude;
        }, function(error) {}, { enableHighAccuracy: true });
    }

    document.addEventListener("DOMContentLoaded", function () {
        // Robust click handler for "Start Trip" to fetch current GPS coordinates explicitly on action
        document.querySelectorAll('.start-trip-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const form = this.closest('form');
                
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        form.querySelector('.start-lat-input').value = position.coords.latitude;
                        form.querySelector('.start-lng-input').value = position.coords.longitude;
                        form.submit();
                    }, function(error) {
                        if (latestDriverLat !== null && latestDriverLng !== null) {
                            form.querySelector('.start-lat-input').value = latestDriverLat;
                            form.querySelector('.start-lng-input').value = latestDriverLng;
                        }
                        form.submit();
                    }, { enableHighAccuracy: true, timeout: 5000 });
                } else {
                    if (latestDriverLat !== null && latestDriverLng !== null) {
                        form.querySelector('.start-lat-input').value = latestDriverLat;
                        form.querySelector('.start-lng-input').value = latestDriverLng;
                    }
                    form.submit();
                }
            });
        });

        // Robust click handler for "Complete Trip" to capture final endpoint coordinates
        document.querySelectorAll('.complete-trip-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const form = this.closest('form');
                
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        form.querySelector('.end-lat-input').value = position.coords.latitude;
                        form.querySelector('.end-lng-input').value = position.coords.longitude;
                        form.submit();
                    }, function(error) {
                        if (latestDriverLat !== null && latestDriverLng !== null) {
                            form.querySelector('.end-lat-input').value = latestDriverLat;
                            form.querySelector('.end-lng-input').value = latestDriverLng;
                        }
                        form.submit();
                    }, { enableHighAccuracy: true, timeout: 5000 });
                } else {
                    if (latestDriverLat !== null && latestDriverLng !== null) {
                        form.querySelector('.end-lat-input').value = latestDriverLat;
                        form.querySelector('.end-lng-input').value = latestDriverLng;
                    }
                    form.submit();
                }
            });
        });
    });
</script>
</body>
</html>
