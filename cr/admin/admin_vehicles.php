<?php
// admin_vehicles.php - Fleet & Vehicle Management for GRLE Car Rental
session_start();
require_once '../db.php';

$ajax_request = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// Auth Guard: Admin access only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php?role=admin');
    exit;
}

$active_page = 'vehicles';
$flash_message = '';
$flash_type = '';

// Directory for uploaded vehicle images
$upload_dir = 'uploads/vehicles/';
$default_vehicle_image = 'uploads/vehicles/default_vehicle.svg';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Helper: Handle Image Upload Process
if (!function_exists('handle_image_upload')) {
    function handle_image_upload($file_array, $target_dir) {
        if (isset($file_array) && $file_array['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $file_array['tmp_name'];
            $file_name = $file_array['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];

            if (in_array($file_ext, $allowed_exts)) {
                $new_file_name = 'vehicle_' . time() . '_' . uniqid() . '.' . $file_ext;
                $destination = $target_dir . $new_file_name;
                if (move_uploaded_file($file_tmp, $destination)) {
                    return $destination;
                }
            }
        }
        return false;
    }
}

// ==========================================
// 1. ACTION HANDLERS (POST REQUESTS)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ACTION: Add New Vehicle
    if ($_POST['action'] === 'add_vehicle') {
        $brand           = trim($_POST['brand'] ?? '');
        $model_name      = trim($_POST['model_name'] ?? '');
        $plate_number    = trim($_POST['plate_number'] ?? '');
        $category        = trim($_POST['category'] ?? 'sedan');
        $capacity        = intval($_POST['capacity'] ?? 5);
        $transmission    = $_POST['transmission'] ?? 'Automatic';
        $daily_rate      = floatval($_POST['daily_rate'] ?? 0);
        $current_mileage = floatval($_POST['current_mileage'] ?? 0);
        $status          = $_POST['status'] ?? 'available';

        if (empty($brand) || empty($model_name) || empty($plate_number) || $daily_rate <= 0) {
            $flash_message = "Please fill in all required fields and set a valid daily rate.";
            $flash_type = "danger";
        } else {
            $image_path = handle_image_upload($_FILES['vehicle_image'] ?? null, $upload_dir);
            if (!$image_path) {
                $image_path = $default_vehicle_image;
            }

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO vehicles (brand, model_name, plate_number, category, capacity, transmission, daily_rate, current_mileage, status, image_url)
                    VALUES (:brand, :model_name, :plate_number, :category, :capacity, :transmission, :daily_rate, :current_mileage, :status, :image_url)
                ");
                $stmt->execute([
                    ':brand'           => $brand,
                    ':model_name'      => $model_name,
                    ':plate_number'    => $plate_number,
                    ':category'        => $category,
                    ':capacity'        => $capacity,
                    ':transmission'    => $transmission,
                    ':daily_rate'      => $daily_rate,
                    ':current_mileage' => $current_mileage,
                    ':status'          => $status,
                    ':image_url'       => $image_path
                ]);

                $flash_message = "Vehicle '{$brand} {$model_name}' added to fleet successfully!";
                $flash_type = "success";
            } catch (PDOException $e) {
                $flash_message = "Database Error: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }

    // ACTION: Edit Vehicle
    if ($_POST['action'] === 'edit_vehicle') {
        $vehicle_id      = intval($_POST['vehicle_id'] ?? 0);
        $brand           = trim($_POST['brand'] ?? '');
        $model_name      = trim($_POST['model_name'] ?? '');
        $plate_number    = trim($_POST['plate_number'] ?? '');
        $category        = trim($_POST['category'] ?? 'sedan');
        $capacity        = intval($_POST['capacity'] ?? 5);
        $transmission    = $_POST['transmission'] ?? 'Automatic';
        $daily_rate      = floatval($_POST['daily_rate'] ?? 0);
        $current_mileage = floatval($_POST['current_mileage'] ?? 0);
        $add_mileage     = isset($_POST['add_mileage']) && $_POST['add_mileage'] === '1';
        $status          = $_POST['status'] ?? 'available';

        if ($vehicle_id > 0) {
            try {
                if ($add_mileage) {
                    $mileage_stmt = $pdo->prepare("SELECT current_mileage FROM vehicles WHERE vehicle_id = :id");
                    $mileage_stmt->execute([':id' => $vehicle_id]);
                    $existing_mileage = (float) ($mileage_stmt->fetchColumn() ?? 0);
                    $current_mileage = $existing_mileage + max(0, $current_mileage);
                }

                $new_image = handle_image_upload($_FILES['vehicle_image'] ?? null, $upload_dir);

                if ($new_image) {
                    $stmt = $pdo->prepare("
                        UPDATE vehicles 
                        SET brand = :brand, model_name = :model_name, plate_number = :plate_number, 
                            category = :category, capacity = :capacity, transmission = :transmission, 
                            daily_rate = :daily_rate, current_mileage = :current_mileage, status = :status, image_url = :image_url
                        WHERE vehicle_id = :id
                    ");
                    $stmt->execute([
                        ':brand'           => $brand,
                        ':model_name'      => $model_name,
                        ':plate_number'    => $plate_number,
                        ':category'        => $category,
                        ':capacity'        => $capacity,
                        ':transmission'    => $transmission,
                        ':daily_rate'      => $daily_rate,
                        ':current_mileage' => $current_mileage,
                        ':status'          => $status,
                        ':image_url'       => $new_image,
                        ':id'              => $vehicle_id
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE vehicles 
                        SET brand = :brand, model_name = :model_name, plate_number = :plate_number, 
                            category = :category, capacity = :capacity, transmission = :transmission, 
                            daily_rate = :daily_rate, current_mileage = :current_mileage, status = :status
                        WHERE vehicle_id = :id
                    ");
                    $stmt->execute([
                        ':brand'           => $brand,
                        ':model_name'      => $model_name,
                        ':plate_number'    => $plate_number,
                        ':category'        => $category,
                        ':capacity'        => $capacity,
                        ':transmission'    => $transmission,
                        ':daily_rate'      => $daily_rate,
                        ':current_mileage' => $current_mileage,
                        ':status'          => $status,
                        ':id'              => $vehicle_id
                    ]);
                }

                $flash_message = "Vehicle details updated successfully.";
                $flash_type = "info";
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $flash_message = "Failed to update vehicle: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }

    // ACTION: Delete Vehicle
    if ($_POST['action'] === 'delete_vehicle') {
        $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
        if ($vehicle_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM vehicles WHERE vehicle_id = :id");
                $stmt->execute([':id' => $vehicle_id]);
                $flash_message = "Vehicle removed from fleet database.";
                $flash_type = "warning";
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $flash_message = "Cannot delete vehicle if it is linked to active rentals.";
                $flash_type = "danger";
            }
        }
    }
}

// ==========================================
// 2. SEARCH & FILTER LOGIC
// ==========================================
$filter_status   = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_category = isset($_GET['category']) ? $_GET['category'] : 'all';
$search_query    = trim($_GET['search'] ?? '');

$query_sql = "SELECT vehicle_id, brand, model_name, plate_number, category, capacity, transmission, COALESCE(daily_rate, 0) AS daily_rate, current_mileage, status, image_url FROM vehicles WHERE 1=1";
$params = [];

if ($filter_status !== 'all') {
    $query_sql .= " AND status = :status";
    $params[':status'] = $filter_status;
}

if ($filter_category !== 'all') {
    $query_sql .= " AND category = :category";
    $params[':category'] = $filter_category;
}

if (!empty($search_query)) {
    $query_sql .= " AND (brand LIKE :search_brand OR model_name LIKE :search_model OR plate_number LIKE :search_plate)";
    $params[':search_brand'] = '%' . $search_query . '%';
    $params[':search_model'] = '%' . $search_query . '%';
    $params[':search_plate'] = '%' . $search_query . '%';
}

$query_sql .= " ORDER BY vehicle_id DESC";

$vehicles = [];
$count_total = 0;
$count_available = 0;
$count_rented = 0;
$count_maintenance = 0;

try {
    $stmt = $pdo->prepare($query_sql);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($vehicles as &$vehicle) {
        $vehicle['plate_number'] = trim((string)($vehicle['plate_number'] ?? ''));
        $vehicle['daily_rate'] = is_numeric($vehicle['daily_rate'] ?? null) ? (float) $vehicle['daily_rate'] : 0.0;
    }
    unset($vehicle);

    $count_total       = $pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn() ?? 0;
    $count_available   = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'available'")->fetchColumn() ?? 0;
    $count_rented      = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'booked'")->fetchColumn() ?? 0;
    $count_maintenance = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'maintenance'")->fetchColumn() ?? 0;

} catch (PDOException $e) {
    error_log($e->getMessage());
    $flash_message = "Database Query Failed. Ensure the 'vehicles' table and 'category' column exist.";
    $flash_type = "danger";
}
?>
<?php if ($ajax_request): ?>
<div id="vehicleTableWrapper">
<?php endif; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fleet & Vehicle Management | GRLE Car Rental</title>
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
            --grle-red-hover: #c62828;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-primary);
            min-height: 100vh;
        }

        .admin-layout { display: flex; min-height: 100vh; }
        .sidebar-wrapper { width: 260px; min-width: 260px; border-right: 1px solid var(--border-color); background-color: var(--bg-surface); }
        .main-content { flex-grow: 1; background-color: var(--bg-body); overflow-x: hidden; }

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

        .bg-dark-surface { background-color: var(--bg-surface) !important; }
        .bg-dark-card { background-color: var(--bg-card) !important; }
        .text-contrast-muted { color: var(--text-muted) !important; }

        .avatar-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: rgba(229, 57, 53, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
        }

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
        .user-profile-footer {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
        }

        .stat-card { background-color: var(--bg-card); border: 1px solid var(--border-color); border-left: 4px solid var(--grle-red); border-radius: 10px; padding: 20px; }
        .content-panel { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 22px; }

        .table-custom { --bs-table-bg: transparent !important; --bs-table-color: #ffffff !important; --bs-table-hover-bg: rgba(255, 255, 255, 0.05) !important; background-color: transparent !important; color: #ffffff !important; }
        .table-custom tr, .table-custom th, .table-custom td { background-color: transparent !important; color: #ffffff !important; border-bottom: 1px solid var(--border-color) !important; }
        .table-custom th { color: #ffffff !important; font-weight: 600; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; background-color: rgba(255, 255, 255, 0.03) !important; }

        .vehicle-thumb { width: 80px; height: 55px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border-color); background-color: #12141a; }

        .custom-dark-input, .custom-dark-select { background-color: #12141a !important; color: #ffffff !important; border: 1px solid var(--border-color) !important; }
        .custom-dark-input:focus, .custom-dark-select:focus { border-color: var(--grle-red) !important; box-shadow: 0 0 0 0.2rem rgba(229, 57, 53, 0.25) !important; color: #ffffff !important; }
        .custom-dark-select option { background-color: #12141a !important; color: #ffffff !important; }

        .modal-content-dark { background-color: var(--bg-card); border: 1px solid var(--border-color); color: #ffffff; }

        .badge-available { background-color: #2e7d32; color: #fff; }
        .badge-rented { background-color: #0288d1; color: #fff; }
        .badge-maintenance { background-color: #d32f2f; color: #fff; }

        .img-preview-box { width: 100%; height: 160px; border-radius: 8px; border: 2px dashed var(--border-color); display: flex; align-items: center; justify-content: center; overflow: hidden; background-color: #12141a; cursor: pointer; }
        .img-preview-box img { width: 100%; height: 100%; object-fit: cover; }

        .table-custom { min-width: 900px; }

        @media (max-width: 767.98px) {
            .admin-layout { display: block; }
            .sidebar-wrapper { width: 100%; min-width: 0; border-right: 0; }
            .main-content { padding: 1rem !important; }
            .content-panel, .stat-card { padding: 16px; }
            .main-content > .d-flex:first-child { align-items: flex-start !important; flex-wrap: wrap; }
            .table-custom { min-width: 900px; }
            .vehicle-thumb { width: 64px; height: 46px; }
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
                <h3 class="fw-bold mb-1 text-white"><i class="fas fa-car text-danger me-2"></i>Fleet & Vehicle Management</h3>
                <p class="text-contrast-muted small mb-0">Add new vehicles, upload photos, set pricing, mileage, and control status</p>
            </div>
            <button class="btn btn-danger fw-bold" data-bs-toggle="modal" data-bs-target="#addVehicleModal">
                <i class="fas fa-plus-circle me-2"></i>Add Vehicle
            </button>
        </div>

        <!-- Flash Alert Message -->
        <?php if (!empty($flash_message)): ?>
            <div class="alert alert-<?php echo $flash_type; ?> alert-dismissible fade show text-white fw-medium mb-4" role="alert">
                <i class="fas fa-info-circle me-2"></i> <?php echo htmlspecialchars($flash_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- METRIC STAT CARDS -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Total Fleet</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?php echo $count_total; ?></h2>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" style="border-left-color: #2e7d32;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Available</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?php echo $count_available; ?></h2>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" style="border-left-color: #0288d1;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">On Rental / Trip</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?php echo $count_rented; ?></h2>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card" style="border-left-color: #d32f2f;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">In Maintenance</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?php echo $count_maintenance; ?></h2>
                </div>
            </div>
        </div>

        <!-- SEARCH AND FILTER BAR -->
        <div class="content-panel mb-4">
            <form id="vehicleFilterForm" action="admin_vehicles.php" method="GET" class="row g-3 align-items-center">
                <div class="col-lg-4 col-md-6">
                    <div class="input-group">
                        <span class="input-group-text custom-dark-input border-end-0"><i class="fas fa-search text-contrast-muted"></i></span>
                        <input type="text" name="search" class="form-control custom-dark-input border-start-0" placeholder="Search brand, model, plate..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                </div>

                <div class="col-lg-3 col-md-6">
                    <select name="category" class="form-select custom-dark-select">
                        <option value="all" <?php echo ($filter_category === 'all') ? 'selected' : ''; ?>>All Vehicle Types</option>
                        <option value="sedan" <?php echo ($filter_category === 'sedan') ? 'selected' : ''; ?>>Sedans</option>
                        <option value="suv" <?php echo ($filter_category === 'suv') ? 'selected' : ''; ?>>SUVs</option>
                        <option value="van" <?php echo ($filter_category === 'van') ? 'selected' : ''; ?>>Vans / Minibuses</option>
                        <option value="luxury" <?php echo ($filter_category === 'luxury') ? 'selected' : ''; ?>>Luxury</option>
                        <option value="truck" <?php echo ($filter_category === 'truck') ? 'selected' : ''; ?>>Trucks</option>
                    </select>
                </div>

                <div class="col-lg-3 col-md-6">
                    <select name="status" class="form-select custom-dark-select">
                        <option value="all" <?php echo ($filter_status === 'all') ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="available" <?php echo ($filter_status === 'available') ? 'selected' : ''; ?>>Available Only</option>
                        <option value="booked" <?php echo ($filter_status === 'booked') ? 'selected' : ''; ?>>Currently Booked</option>
                        <option value="maintenance" <?php echo ($filter_status === 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="out_of_service" <?php echo ($filter_status === 'out_of_service') ? 'selected' : ''; ?>>Out of Service</option>
                    </select>
                </div>

                <div class="col-lg-2 col-md-6 d-flex gap-2">
                    <button type="submit" class="btn btn-danger w-100 fw-bold">Filter</button>
                    <?php if ($filter_status !== 'all' || $filter_category !== 'all' || !empty($search_query)): ?>
                        <a href="admin_vehicles.php" class="btn btn-outline-secondary" title="Reset"><i class="fas fa-undo"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- FLEET TABLE -->
        <div id="vehicleTableWrapper" class="content-panel">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold text-white mb-0">Vehicle Directory</h5>
                <span class="badge bg-secondary px-3 py-1"><?php echo count($vehicles); ?> Fleet Unit(s)</span>
            </div>

            <?php if (empty($vehicles)): ?>
                <div class="text-center py-5 text-contrast-muted">
                    <i class="fas fa-car-side fs-1 mb-2 text-secondary d-block"></i>
                    <p class="mb-0 fs-6">No vehicles match your search or filter requirements.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Vehicle Details</th>
                                <th>Plate No.</th>
                                <th>Specs</th>
                                <th>Current Mileage</th>
                                <th>Daily Rate</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicles as $car): ?>
                                <tr>
                                    <td>
                                        <img src="<?php echo htmlspecialchars($car['image_url'] ?? $default_vehicle_image); ?>" alt="Car Photo" class="vehicle-thumb" onerror="this.onerror=null; this.src='uploads/vehicles/default_vehicle.svg';">
                                    </td>
                                    <td>
                                        <strong class="text-white d-block fs-6"><?php echo htmlspecialchars(($car['brand'] ?? '') . ' ' . ($car['model_name'] ?? '')); ?></strong>
                                        <small class="text-contrast-muted"><?php echo htmlspecialchars($car['category'] ?? 'Uncategorized'); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark border border-secondary text-white px-2 py-1 font-monospace fs-7">
                                            <?php echo htmlspecialchars(!empty($car['plate_number']) ? $car['plate_number'] : 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-contrast-muted d-block"><i class="fas fa-users me-1"></i><?php echo $car['capacity'] ?? 4; ?> Seats</small>
                                        <small class="text-contrast-muted d-block"><i class="fas fa-cog me-1"></i><?php echo htmlspecialchars($car['transmission'] ?? 'Auto'); ?></small>
                                    </td>
                                    <td>
                                        <span class="text-white fw-semibold">
                                            <i class="fas fa-tachometer-alt me-1 text-danger"></i><?php echo number_format($car['current_mileage'] ?? 0); ?> km
                                        </span>
                                    </td>
                                    <td>
                                        <strong class="text-danger fs-6">₱<?php echo number_format((float)($car['daily_rate'] ?? 0), 2); ?></strong>
                                        <small class="text-contrast-muted d-block">per day</small>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo htmlspecialchars($car['status'] ?? 'unknown'); ?> text-uppercase fs-8 px-2 py-1">
                                            <?php echo htmlspecialchars($car['status'] ?? 'unknown'); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <!-- Edit Modal Trigger -->
                                        <button class="btn btn-sm btn-outline-light me-1" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editVehicleModal"
                                                data-id="<?php echo $car['vehicle_id'] ?? 0; ?>"
                                                data-brand="<?php echo htmlspecialchars($car['brand'] ?? ''); ?>"
                                                data-model="<?php echo htmlspecialchars($car['model_name'] ?? ''); ?>"
                                                data-plate="<?php echo htmlspecialchars($car['plate_number'] ?? ''); ?>"
                                                data-category="<?php echo htmlspecialchars($car['category'] ?? 'Sedan'); ?>"
                                                data-capacity="<?php echo $car['capacity'] ?? 4; ?>"
                                                data-transmission="<?php echo htmlspecialchars($car['transmission'] ?? 'Automatic'); ?>"
                                                data-rate="<?php echo $car['daily_rate'] ?? 0; ?>"
                                                data-mileage="<?php echo $car['current_mileage'] ?? 0; ?>"
                                                data-status="<?php echo htmlspecialchars($car['status'] ?? 'available'); ?>"
                                                data-image="<?php echo htmlspecialchars($car['image_url'] ?? ''); ?>"
                                                onclick="populateEditModal(this)">
                                            <i class="fas fa-edit me-1"></i> Edit
                                        </button>

                                        <!-- Delete Vehicle Form -->
                                        <form action="admin_vehicles.php" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to remove this vehicle from the fleet?');">
                                            <input type="hidden" name="action" value="delete_vehicle">
                                            <input type="hidden" name="vehicle_id" value="<?php echo $car['vehicle_id'] ?? 0; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Vehicle">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
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

<!-- MODAL: ADD NEW VEHICLE -->
<div class="modal fade" id="addVehicleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content modal-content-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle text-danger me-2"></i>Add Vehicle to Fleet</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="admin_vehicles.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_vehicle">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold text-contrast-muted">Vehicle Photo</label>
                            <div class="img-preview-box" onclick="document.getElementById('add_vehicle_image').click();">
                                <img id="add_img_preview" src="" style="display:none;" alt="Preview">
                                <div id="add_img_placeholder" class="text-center text-contrast-muted">
                                    <i class="fas fa-cloud-upload-alt fs-2 d-block mb-1 text-danger"></i>
                                    <span>Click to select vehicle photo (JPG, PNG, WEBP)</span>
                                </div>
                            </div>
                            <input type="file" name="vehicle_image" id="add_vehicle_image" class="d-none" accept="image/*" onchange="previewImage(this, 'add_img_preview', 'add_img_placeholder')">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-contrast-muted">Brand / Make *</label>
                            <input type="text" name="brand" class="form-control custom-dark-input" placeholder="e.g. Toyota, Mitsubishi" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-contrast-muted">Model Name *</label>
                            <input type="text" name="model_name" class="form-control custom-dark-input" placeholder="e.g. Vios, Montero Sport" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-contrast-muted">License Plate Number *</label>
                            <input type="text" name="plate_number" class="form-control custom-dark-input" placeholder="e.g. ABC 1234" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-contrast-muted">Vehicle Category</label>
                            <select name="category" class="form-select custom-dark-select">
                                <option value="sedan">Sedan</option>
                                <option value="suv">SUV</option>
                                <option value="van">Van / Minibus</option>
                                <option value="luxury">Luxury</option>
                                <option value="truck">Truck</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-contrast-muted">Passenger Capacity</label>
                            <input type="number" name="capacity" class="form-control custom-dark-input" value="5" min="1" max="30" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-contrast-muted">Transmission</label>
                            <select name="transmission" class="form-select custom-dark-select">
                                <option value="Automatic">Automatic</option>
                                <option value="Manual">Manual</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-contrast-muted">Daily Rate (₱) *</label>
                            <input type="number" step="0.01" name="daily_rate" class="form-control custom-dark-input" placeholder="2500.00" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-contrast-muted">Current Mileage (km) *</label>
                            <input type="number" step="0.1" name="current_mileage" class="form-control custom-dark-input" placeholder="e.g. 12000" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-contrast-muted">Status</label>
                            <select name="status" class="form-select custom-dark-select">
                                <option value="available">Available</option>
                                <option value="booked">Booked</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="out_of_service">Out of Service</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Save Vehicle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: EDIT VEHICLE -->
<div class="modal fade" id="editVehicleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content modal-content-dark">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit text-danger me-2"></i>Edit Fleet Vehicle</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="admin_vehicles.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_vehicle">
                <input type="hidden" name="vehicle_id" id="edit_vehicle_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-contrast-muted">Brand / Make *</label>
                            <input type="text" name="brand" id="edit_brand" class="form-control custom-dark-input" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-contrast-muted">Model Name *</label>
                            <input type="text" name="model_name" id="edit_model_name" class="form-control custom-dark-input" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-contrast-muted">License Plate Number *</label>
                            <input type="text" name="plate_number" id="edit_plate_number" class="form-control custom-dark-input" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-contrast-muted">Vehicle Category</label>
                            <select name="category" id="edit_category" class="form-select custom-dark-select">
                                <option value="sedan">Sedan</option>
                                <option value="suv">SUV</option>
                                <option value="van">Van / Minibus</option>
                                <option value="luxury">Luxury</option>
                                <option value="truck">Truck</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-contrast-muted">Passenger Capacity</label>
                            <input type="number" name="capacity" id="edit_capacity" class="form-control custom-dark-input" min="1" max="30" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-contrast-muted">Transmission</label>
                            <select name="transmission" id="edit_transmission" class="form-select custom-dark-select">
                                <option value="Automatic">Automatic</option>
                                <option value="Manual">Manual</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-contrast-muted">Daily Rate (₱) *</label>
                            <input type="number" step="0.01" name="daily_rate" id="edit_daily_rate" class="form-control custom-dark-input" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-contrast-muted">Mileage (km) *</label>
                            <input type="number" step="0.1" name="current_mileage" id="edit_current_mileage" class="form-control custom-dark-input" required>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="add_mileage" value="1" id="edit_add_mileage">
                                <label class="form-check-label text-contrast-muted" for="edit_add_mileage">Add this mileage to the current total</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-contrast-muted">Status</label>
                            <select name="status" id="edit_status" class="form-select custom-dark-select">
                                <option value="available">Available</option>
                                <option value="booked">Booked</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="out_of_service">Out of Service</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-contrast-muted">Replace Vehicle Photo</label>
                            <input type="file" name="vehicle_image" class="form-control custom-dark-input" accept="image/*">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function previewImage(input, previewId, placeholderId) {
    const preview = document.getElementById(previewId);
    const placeholder = document.getElementById(placeholderId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
            placeholder.style.display = 'none';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function populateEditModal(button) {
    document.getElementById('edit_vehicle_id').value = button.dataset.id || '';
    document.getElementById('edit_brand').value = button.dataset.brand || '';
    document.getElementById('edit_model_name').value = button.dataset.model || '';
    document.getElementById('edit_plate_number').value = button.dataset.plate || '';
    document.getElementById('edit_category').value = button.dataset.category || 'sedan';
    document.getElementById('edit_capacity').value = button.dataset.capacity || '5';
    document.getElementById('edit_transmission').value = button.dataset.transmission || 'Automatic';
    document.getElementById('edit_daily_rate').value = button.dataset.rate || '0';
    document.getElementById('edit_current_mileage').value = button.dataset.mileage || '0';
    document.getElementById('edit_add_mileage').checked = false;
    document.getElementById('edit_status').value = button.dataset.status || 'available';
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const filterForm = document.getElementById('vehicleFilterForm');
    if (!filterForm) return;

    filterForm.addEventListener('submit', function (event) {
        event.preventDefault();

        const formData = new FormData(filterForm);
        const params = new URLSearchParams(formData).toString();
        const url = 'admin_vehicles.php?' + params;

        fetch(url, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            const temp = document.createElement('div');
            temp.innerHTML = html;
            const newTable = temp.querySelector('#vehicleTableWrapper');
            const target = document.getElementById('vehicleTableWrapper');

            if (newTable && target) {
                target.innerHTML = newTable.innerHTML;
            } else {
                window.location.href = url;
            }
        })
        .catch(() => {
            window.location.href = url;
        });
    });
});
</script>
<?php if ($ajax_request): ?>
</div>
<?php endif; ?>
</body>
</html>
