<?php
// admin_maintenance.php - Vehicle Maintenance Management
session_start();
require_once '../db.php';

// Auth Guard: Admin access only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php?role=admin');
    exit;
}

$active_page = 'maintenance';

// Defined Service Types based on MySQL ENUM definition
$allowed_service_types = [
    'Oil and Filter Change',
    'Tire',
    'Battery',
    'Belts and Hoses',
    'Brake Inspection',
    'Spark Plugs',
    'Transmission and Differential Fluids'
];

// Flash message handling via Session (PRG Pattern)
$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type    = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// ==========================================
// 1. DYNAMIC ACTION HANDLERS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ACTION: Add Maintenance Record
    if ($_POST['action'] === 'add_maintenance') {
        $vehicle_id      = intval($_POST['vehicle_id'] ?? 0);
        $service_type    = trim($_POST['service_type'] ?? '');
        $description     = trim($_POST['description'] ?? '');
        $cost            = floatval($_POST['cost'] ?? 0);
        $service_date    = trim($_POST['service_date'] ?? date('Y-m-d'));
        $present_mileage = intval($_POST['present_mileage'] ?? 0);

        if ($vehicle_id > 0 && in_array($service_type, $allowed_service_types)) {
            try {
                $pdo->beginTransaction();

                // 1. Insert the maintenance record
                $stmt = $pdo->prepare("
                    INSERT INTO maintenance (vehicle_id, service_type, description, cost, service_date, present_mileage, created_at)
                    VALUES (:vehicle_id, :service_type, :description, :cost, :service_date, :present_mileage, NOW())
                ");
                $stmt->execute([
                    ':vehicle_id'      => $vehicle_id,
                    ':service_type'    => $service_type,
                    ':description'     => $description,
                    ':cost'            => $cost,
                    ':service_date'    => $service_date,
                    ':present_mileage' => $present_mileage
                ]);

                // 2. Update current_mileage in the vehicles table to match the input present mileage
                if ($present_mileage > 0) {
                    $upd_vehicle = $pdo->prepare("UPDATE vehicles SET current_mileage = :mileage WHERE vehicle_id = :id");
                    $upd_vehicle->execute([
                        ':mileage' => $present_mileage,
                        ':id'      => $vehicle_id
                    ]);
                }

                $pdo->commit();
                $_SESSION['flash_message'] = "Maintenance record added successfully and vehicle mileage updated.";
                $_SESSION['flash_type']    = "success";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $_SESSION['flash_message'] = "Database Error: " . $e->getMessage();
                $_SESSION['flash_type']    = "danger";
            }
        } else {
            $_SESSION['flash_message'] = "Please select a valid service type and vehicle.";
            $_SESSION['flash_type']    = "danger";
        }
        header("Location: admin_maintenance.php");
        exit;
    }

    // ACTION: Update Maintenance Record
    if ($_POST['action'] === 'update_maintenance') {
        $maintenance_id  = intval($_POST['maintenance_id'] ?? 0);
        $vehicle_id      = intval($_POST['vehicle_id'] ?? 0);
        $service_type    = trim($_POST['service_type'] ?? '');
        $description     = trim($_POST['description'] ?? '');
        $cost            = floatval($_POST['cost'] ?? 0);
        $service_date    = trim($_POST['service_date'] ?? date('Y-m-d'));
        $present_mileage = intval($_POST['present_mileage'] ?? 0);

        if ($maintenance_id > 0 && $vehicle_id > 0 && in_array($service_type, $allowed_service_types)) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    UPDATE maintenance 
                    SET vehicle_id = :vehicle_id, 
                        service_type = :service_type, 
                        description = :description, 
                        cost = :cost, 
                        service_date = :service_date, 
                        present_mileage = :present_mileage 
                    WHERE maintenance_id = :id
                ");
                $stmt->execute([
                    ':vehicle_id'      => $vehicle_id,
                    ':service_type'    => $service_type,
                    ':description'     => $description,
                    ':cost'            => $cost,
                    ':service_date'    => $service_date,
                    ':present_mileage' => $present_mileage,
                    ':id'              => $maintenance_id
                ]);

                // Update vehicle current mileage
                if ($present_mileage > 0) {
                    $upd_vehicle = $pdo->prepare("UPDATE vehicles SET current_mileage = :mileage WHERE vehicle_id = :id");
                    $upd_vehicle->execute([
                        ':mileage' => $present_mileage,
                        ':id'      => $vehicle_id
                    ]);
                }

                $pdo->commit();
                $_SESSION['flash_message'] = "Maintenance record #{$maintenance_id} updated successfully.";
                $_SESSION['flash_type']    = "success";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $_SESSION['flash_message'] = "Database Error: " . $e->getMessage();
                $_SESSION['flash_type']    = "danger";
            }
        } else {
            $_SESSION['flash_message'] = "Invalid input or missing fields.";
            $_SESSION['flash_type']    = "danger";
        }
        header("Location: admin_maintenance.php");
        exit;
    }

    // ACTION: Delete Maintenance Record
    if ($_POST['action'] === 'delete_maintenance') {
        $maintenance_id = intval($_POST['maintenance_id'] ?? 0);
        if ($maintenance_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM maintenance WHERE maintenance_id = :id");
                $stmt->execute([':id' => $maintenance_id]);

                $_SESSION['flash_message'] = "Maintenance record #{$maintenance_id} deleted successfully.";
                $_SESSION['flash_type']    = "success";
            } catch (PDOException $e) {
                $_SESSION['flash_message'] = "Database Error: " . $e->getMessage();
                $_SESSION['flash_type']    = "danger";
            }
        }
        header("Location: admin_maintenance.php");
        exit;
    }
}

// ==========================================
// 2. FILTERS & SEARCH
// ==========================================
$search              = trim($_GET['search'] ?? '');
$service_type_filter = trim($_GET['type_filter'] ?? 'all');

$where_clauses = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_clauses[] = "(m.description LIKE :s_desc OR v.brand LIKE :s_brand OR v.model_name LIKE :s_model OR v.plate_number LIKE :s_plate)";
    $wildcard = "%{$search}%";
    $params[':s_desc']  = $wildcard;
    $params[':s_brand'] = $wildcard;
    $params[':s_model'] = $wildcard;
    $params[':s_plate'] = $wildcard;
}

if ($service_type_filter !== 'all' && in_array($service_type_filter, $allowed_service_types)) {
    $where_clauses[] = "m.service_type = :stype";
    $params[':stype'] = $service_type_filter;
}

$where_sql = implode(' AND ', $where_clauses);

// ==========================================
// 3. FETCH METRICS & DATA
// ==========================================
try {
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total_records,
            AVG(cost) as avg_cost,
            SUM(cost) as total_spent
        FROM maintenance
    ")->fetch(PDO::FETCH_ASSOC);

    // Fetch vehicles for dropdown selectors
    $vehicles = $pdo->query("SELECT vehicle_id, brand, model_name, plate_number, current_mileage FROM vehicles ORDER BY brand, model_name")->fetchAll(PDO::FETCH_ASSOC);

    $query = "
        SELECT 
            m.maintenance_id,
            m.vehicle_id,
            m.description,
            m.cost,
            m.service_date,
            m.created_at,
            m.service_type,
            m.present_mileage,
            v.brand, v.model_name, v.plate_number, v.current_mileage as vehicle_current_mileage
        FROM maintenance m
        LEFT JOIN vehicles v ON m.vehicle_id = v.vehicle_id
        WHERE {$where_sql}
        ORDER BY m.maintenance_id DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $maintenance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $log_page = max(1, intval($_GET['log_page'] ?? 1));
    $log_per_page = 5;
    $total_log_records = count($maintenance_records);
    $total_log_pages = max(1, (int) ceil($total_log_records / $log_per_page));
    if ($log_page > $total_log_pages) {
        $log_page = $total_log_pages;
    }
    $maintenance_page_records = array_slice($maintenance_records, ($log_page - 1) * $log_per_page, $log_per_page);

    // Predictive calculations must always use the complete maintenance history,
    // not the currently filtered records shown in the log table.
    $predictive_records = $pdo->query("SELECT maintenance_id, vehicle_id, service_type, service_date, present_mileage FROM maintenance ORDER BY service_date DESC, maintenance_id DESC")->fetchAll(PDO::FETCH_ASSOC);

    $service_rules = [
        'Oil and Filter Change' => [
            'interval_km' => 5000,
            'interval_days' => 180,
            'description' => 'Engine overheating, increased engine wear, engine knocking, poor performance, and engine failure.'
        ],
        'Tire' => [
            'interval_km' => 12000,
            'interval_days' => 365,
            'description' => 'Poor handling, reduced braking performance, uneven tire wear, tire blowout, and accidents.'
        ],
        'Battery' => [
            'interval_km' => 30000,
            'interval_days' => 365,
            'description' => 'Difficulty starting, slow engine cranking, electrical problems, and complete starting failure.'
        ],
        'Belts and Hoses' => [
            'interval_km' => 40000,
            'interval_days' => 365,
            'description' => 'Coolant leaks, engine overheating, loss of power steering, charging problems, and engine damage.'
        ],
        'Brake Inspection' => [
            'interval_km' => 10000,
            'interval_days' => 180,
            'description' => 'Longer stopping distance, brake noise, reduced braking power, and brake failure.'
        ],
        'Spark Plugs' => [
            'interval_km' => 30000,
            'interval_days' => 365,
            'description' => 'Hard starting, engine misfires, rough idling, poor acceleration, and increased fuel consumption.'
        ],
        'Transmission and Differential Fluids' => [
            'interval_km' => 40000,
            'interval_days' => 365,
            'description' => 'Hard shifting, transmission overheating, gear wear, unusual noises, and transmission or differential failure.'
        ]
    ];

    $vehicle_lookup = [];
    foreach ($vehicles as $vehicle) {
        $vehicle_lookup[(int)($vehicle['vehicle_id'] ?? 0)] = $vehicle;
    }

    $latest_service_by_vehicle_type = [];
    foreach ($predictive_records as $record) {
        $vehicle_id = (int)($record['vehicle_id'] ?? 0);
        $service_type = trim((string)($record['service_type'] ?? ''));
        if ($vehicle_id <= 0 || $service_type === '') {
            continue;
        }

        $record_date = strtotime($record['service_date'] ?? '1970-01-01');
        $existing = $latest_service_by_vehicle_type[$vehicle_id][$service_type] ?? null;

        if (!$existing) {
            $latest_service_by_vehicle_type[$vehicle_id][$service_type] = $record;
            continue;
        }

        $existing_date = strtotime($existing['service_date'] ?? '1970-01-01');
        if ($record_date > $existing_date || ($record_date === $existing_date && (int)($record['maintenance_id'] ?? 0) > (int)($existing['maintenance_id'] ?? 0))) {
            $latest_service_by_vehicle_type[$vehicle_id][$service_type] = $record;
        }
    }

    $predictive_suggestions = [];
    $today = new DateTime('today');
    $priority_rank = ['danger' => 0, 'warning' => 1, 'success' => 2];

    foreach ($vehicles as $vehicle) {
        $vehicle_id = (int)($vehicle['vehicle_id'] ?? 0);
        $current_mileage = max(0, (float)($vehicle['current_mileage'] ?? 0));
        $vehicle_name = trim((string)($vehicle['brand'] ?? '')) . ' ' . trim((string)($vehicle['model_name'] ?? ''));
        $vehicle_name = trim($vehicle_name) ?: 'Unnamed Vehicle';
        $plate_number = (string)($vehicle['plate_number'] ?? 'N/A');

        foreach ($service_rules as $service_type => $rule) {
            $latest = $latest_service_by_vehicle_type[$vehicle_id][$service_type] ?? null;
            $last_service_mileage = (float)($latest['present_mileage'] ?? 0);
            $last_service_date = $latest['service_date'] ?? null;
            $days_since = null;

            if ($last_service_date) {
                $service_dt = new DateTime($last_service_date);
                $service_dt->setTime(0, 0, 0);
                $interval = $today->diff($service_dt);
                $days_since = max(0, (int) $interval->format('%r%a'));
            }

            $mileage_since = $last_service_mileage > 0 ? max(0, $current_mileage - $last_service_mileage) : null;
            $km_remaining = null;
            $days_remaining = null;
            $status = 'checked';
            $priority = 'success';
            $reason = 'Service is within the recommended window.';

            if (!$latest) {
                $km_remaining = max(0, $rule['interval_km'] - $current_mileage);
                $days_remaining = $rule['interval_days'];

                if ($current_mileage >= $rule['interval_km']) {
                    $status = 'overdue';
                    $priority = 'danger';
                    $reason = 'The vehicle has reached the service interval and has no prior maintenance record.';
                } else {
                    $status = 'pending';
                    $priority = 'warning';
                    $reason = 'No prior maintenance record exists yet. This item should be reviewed during the first inspection.';
                }
            } else {
                $km_remaining = max(0, $rule['interval_km'] - ($mileage_since ?? 0));
                $days_remaining = max(0, $rule['interval_days'] - ($days_since ?? 0));

                $mileage_ratio = $mileage_since !== null && $rule['interval_km'] > 0 ? min(1.5, ($mileage_since / $rule['interval_km'])) : 0;
                $days_ratio = $days_since !== null && $rule['interval_days'] > 0 ? min(1.5, ($days_since / $rule['interval_days'])) : 0;
                $usage_ratio = max($mileage_ratio, $days_ratio);

                if ($usage_ratio >= 1.0) {
                    $status = 'overdue';
                    $priority = 'danger';
                    $reason = trim((string) ($rule['description'] ?? '')) !== ''
                        ? "{$service_type} – {$rule['description']}"
                        : 'The service interval has been exceeded.';
                } elseif ($usage_ratio >= 0.6) {
                    $status = 'upcoming';
                    $priority = 'warning';
                    $reason = 'This service is approaching its recommended interval.';
                }
            }

            $predictive_suggestions[] = [
                'vehicle_id' => $vehicle_id,
                'vehicle_name' => $vehicle_name,
                'plate_number' => $plate_number,
                'service_type' => $service_type,
                'status' => $status,
                'priority' => $priority,
                'reason' => $reason,
                'km_remaining' => $km_remaining,
                'days_remaining' => $days_remaining
            ];
        }
    }

    usort($predictive_suggestions, function ($a, $b) use ($priority_rank) {
        $rank_a = $priority_rank[$a['priority']] ?? 99;
        $rank_b = $priority_rank[$b['priority']] ?? 99;
        if ($rank_a !== $rank_b) {
            return $rank_a <=> $rank_b;
        }

        return strcmp($a['vehicle_name'], $b['vehicle_name']);
    });

    $all_predictive_suggestions = $predictive_suggestions;
    $predictive_suggestions = array_slice($all_predictive_suggestions, 0, 8);

    $maintenance_overdue_count = 0;
    $maintenance_warning_count = 0;
    foreach ($all_predictive_suggestions as $suggestion) {
        if (($suggestion['status'] ?? '') === 'overdue') {
            $maintenance_overdue_count++;
        } elseif (in_array(($suggestion['status'] ?? ''), ['upcoming', 'pending'], true)) {
            $maintenance_warning_count++;
        }
    }

    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 5;
    $upcoming_maintenance = array_values(array_filter($all_predictive_suggestions, function ($item) {
        return in_array(($item['status'] ?? ''), ['overdue', 'upcoming', 'pending'], true);
    }));

    $total_upcoming_records = count($upcoming_maintenance);
    $total_upcoming_pages = max(1, (int) ceil($total_upcoming_records / $per_page));
    if ($page > $total_upcoming_pages) {
        $page = $total_upcoming_pages;
    }
    $upcoming_page_records = array_slice($upcoming_maintenance, ($page - 1) * $per_page, $per_page);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Maintenance | GRLE Car Rental</title>
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

    .user-profile-footer {
        background-color: var(--bg-card);
        border: 1px solid var(--border-color);
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

    .custom-dark-select, .custom-dark-input, .custom-dark-textarea {
        background-color: #12141a !important;
        color: #ffffff !important;
        border: 1px solid var(--border-color) !important;
    }
    .custom-dark-select option {
        background-color: #12141a !important;
        color: #ffffff !important;
    }
    .custom-dark-input::placeholder, .custom-dark-textarea::placeholder {
        color: var(--text-muted);
    }

    .modal-content {
        background-color: var(--bg-card);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
    }
    .modal-header, .modal-footer {
        border-color: var(--border-color);
    }
    .fs-8 { font-size: 0.75rem; }

    .status-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
    }
    .status-dot-checked { background-color: #2e7d32; }
    .status-dot-upcoming { background-color: #f57c00; }
    .status-dot-overdue { background-color: #d32f2f; }

    .status-card-checked {
        border-left: 4px solid #2e7d32;
        background: rgba(46, 125, 50, 0.12);
    }
    .status-card-upcoming {
        border-left: 4px solid #f57c00;
        background: rgba(245, 124, 0, 0.12);
    }
    .status-card-overdue {
        border-left: 4px solid #d32f2f;
        background: rgba(211, 47, 47, 0.12);
    }

    .badge-orange {
        background-color: #f57c00;
        color: #fff;
    }

    .predictive-card {
        cursor: pointer;
        transition: all 0.2s ease-in-out;
    }
    .predictive-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
        border-color: var(--text-muted) !important;
    }

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
    
    <?php include 'sidebar.php'; ?>

    <div class="main-content p-4">
        
        <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom border-secondary">
            <div>
                <h3 class="fw-bold mb-1 text-white">Vehicle Maintenance</h3>
                <p class="text-contrast-muted small mb-0">Track fleet servicing, mileage readings, and repair expenses.</p>
            </div>
            <div>
                <button type="button" class="btn btn-danger fw-bold" data-bs-toggle="modal" data-bs-target="#addMaintenanceModal">
                    <i class="fas fa-plus me-1"></i> Add Maintenance Record
                </button>
            </div>
        </div>

        <?php if (!empty($flash_message)): ?>
            <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show text-white fw-medium mb-4" role="alert">
                <i class="fas fa-info-circle me-2"></i> <?= htmlspecialchars($flash_message) ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (($maintenance_overdue_count ?? 0) > 0): ?>
            <div class="alert alert-danger d-flex align-items-center justify-content-between gap-3 mb-4" role="alert">
                <span><i class="fas fa-triangle-exclamation me-2"></i><strong>Maintenance notification:</strong> <?= (int) $maintenance_overdue_count ?> service suggestion<?= $maintenance_overdue_count === 1 ? '' : 's' ?> has reached its recommended interval and needs attention.</span>
                <a href="#predictive-maintenance" class="alert-link text-nowrap">Review now</a>
            </div>
        <?php elseif (($maintenance_warning_count ?? 0) > 0): ?>
            <div class="alert alert-warning d-flex align-items-center justify-content-between gap-3 mb-4" role="alert">
                <span><i class="fas fa-circle-exclamation me-2"></i><strong>Maintenance reminder:</strong> <?= (int) $maintenance_warning_count ?> service suggestion<?= $maintenance_warning_count === 1 ? '' : 's' ?> is upcoming or awaiting its initial check.</span>
                <a href="#predictive-maintenance" class="alert-link text-nowrap">Review now</a>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-4">
                <div class="stat-card">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Total Logs</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?= number_format($stats['total_records'] ?? 0) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-4">
                <div class="stat-card" style="border-left-color: #0288d1;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Avg. Cost Per Service</span>
                    <h2 class="fw-bold text-white mb-0 mt-1">₱<?= number_format($stats['avg_cost'] ?? 0, 2) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-4">
                <div class="stat-card" style="border-left-color: #388e3c;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Total Expenses</span>
                    <h2 class="fw-bold text-white mb-0 mt-1">₱<?= number_format($stats['total_spent'] ?? 0, 2) ?></h2>
                </div>
            </div>
        </div>

        <div class="content-panel mb-4" id="predictive-maintenance">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold text-white mb-0"><i class="fas fa-brain text-danger me-2"></i>Predictive Maintenance Suggestions</h5>
                <span class="badge bg-secondary px-2 py-1">Rule-based insights</span>
            </div>

            <div class="d-flex flex-wrap gap-3 mb-3">
                <span class="small text-contrast-muted"><span class="status-dot status-dot-checked me-1"></span>Checked (Green)</span>
                <span class="small text-contrast-muted"><span class="status-dot status-dot-upcoming me-1"></span>Upcoming / Initial Check (Orange)</span>
                <span class="small text-contrast-muted"><span class="status-dot status-dot-overdue me-1"></span>Overdue (Red)</span>
            </div>

            <?php if (empty($predictive_suggestions)): ?>
                <div class="text-center py-4 text-contrast-muted">
                    <i class="fa-solid fa-check-circle fa-2x mb-2 d-block"></i>
                    <p class="mb-0">No urgent maintenance suggestions detected right now.</p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($predictive_suggestions as $suggestion): ?>
                        <?php
                            $status_class = $suggestion['status'] === 'overdue' ? 'status-card-overdue' : ($suggestion['status'] === 'upcoming' || $suggestion['status'] === 'pending' ? 'status-card-upcoming' : 'status-card-checked');
                            $status_label = $suggestion['status'] === 'overdue' ? 'Overdue' : ($suggestion['status'] === 'upcoming' ? 'Upcoming' : ($suggestion['status'] === 'pending' ? 'Initial Check' : 'Checked'));
                            $status_badge_class = $suggestion['status'] === 'overdue' ? 'bg-danger' : ($suggestion['status'] === 'upcoming' || $suggestion['status'] === 'pending' ? 'badge-orange' : 'bg-success');
                            $status_icon = $suggestion['status'] === 'overdue' ? 'fa-circle-exclamation' : ($suggestion['status'] === 'upcoming' || $suggestion['status'] === 'pending' ? 'fa-circle-exclamation' : 'fa-circle-check');
                        ?>
                        <div class="col-12 col-lg-6">
                            <div class="border rounded-3 p-3 h-100 <?= $status_class ?> predictive-card" 
                                 style="border-color: var(--border-color);"
                                 data-bs-toggle="modal" 
                                 data-bs-target="#addMaintenanceModal"
                                 data-vehicle_id="<?= $suggestion['vehicle_id'] ?>"
                                 data-service_type="<?= htmlspecialchars($suggestion['service_type'], ENT_QUOTES) ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="fw-bold text-white mb-1"><?= htmlspecialchars($suggestion['service_type']) ?></h6>
                                        <div class="small text-contrast-muted">
                                            <?= htmlspecialchars($suggestion['vehicle_name']) ?> • <?= htmlspecialchars($suggestion['plate_number']) ?>
                                        </div>
                                    </div>
                                    <span class="badge <?= $status_badge_class ?> px-2 py-1">
                                        <i class="fas <?= $status_icon ?> me-1"></i><?= $status_label ?>
                                    </span>
                                </div>
                                <p class="small text-contrast-muted mb-2"><?= htmlspecialchars($suggestion['reason']) ?></p>
                                <div class="small text-contrast-muted">
                                    <i class="fas fa-road me-1"></i><?= $suggestion['km_remaining'] !== null ? number_format($suggestion['km_remaining']) . ' km remaining' : 'N/A' ?>
                                    <span class="mx-2">•</span>
                                    <i class="fas fa-calendar-alt me-1"></i><?= $suggestion['days_remaining'] !== null ? $suggestion['days_remaining'] . ' days remaining' : 'N/A' ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="content-panel mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold text-white mb-0"><i class="fas fa-clipboard-list text-danger me-2"></i>Upcoming Maintenance</h5>
                <span class="badge bg-secondary px-2 py-1"><?= $total_upcoming_records ?> Scheduled</span>
            </div>

            <?php if (empty($upcoming_page_records)): ?>
                <div class="text-center py-4 text-contrast-muted">
                    <i class="fa-solid fa-calendar-check fa-2x mb-2 d-block"></i>
                    <p class="mb-0">No upcoming or overdue maintenance items found.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Vehicle</th>
                                <th>Service</th>
                                <th>Status</th>
                                <th>KM Remaining</th>
                                <th>Days Remaining</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming_page_records as $item): ?>
                                <?php
                                    $status_badge = $item['status'] === 'overdue' ? 'bg-danger' : ($item['status'] === 'upcoming' ? 'badge-orange' : 'bg-warning text-dark');
                                    $status_text = $item['status'] === 'overdue' ? 'Overdue' : ($item['status'] === 'upcoming' ? 'Upcoming' : 'Initial Check');
                                ?>
                                <tr>
                                    <td>
                                        <span class="fw-semibold text-white d-block"><?= htmlspecialchars($item['vehicle_name']) ?></span>
                                        <small class="text-contrast-muted"><?= htmlspecialchars($item['plate_number']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark border border-secondary text-info px-2 py-1 fs-8">
                                            <i class="fa-solid fa-wrench me-1"></i><?= htmlspecialchars($item['service_type']) ?>
                                        </span>
                                    </td>
                                    <td><span class="badge <?= $status_badge ?> px-2 py-1"><?= $status_text ?></span></td>
                                    <td><?= $item['km_remaining'] !== null ? number_format($item['km_remaining']) . ' km' : 'N/A' ?></td>
                                    <td><?= $item['days_remaining'] !== null ? $item['days_remaining'] . ' days' : 'N/A' ?></td>
                                    <td><small class="text-contrast-muted"><?= htmlspecialchars($item['reason']) ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_upcoming_pages > 1): ?>
                    <?php $pagination_query = ['search' => $search, 'type_filter' => $service_type_filter]; ?>
                    <nav aria-label="Upcoming maintenance pagination" class="mt-3">
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($pagination_query, ['page' => max(1, $page - 1)])) ?>">Previous</a>
                            </li>
                            <?php for ($i = 1; $i <= $total_upcoming_pages; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($pagination_query, ['page' => $i])) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $total_upcoming_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($pagination_query, ['page' => min($total_upcoming_pages, $page + 1)])) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="content-panel mb-4">
            <form method="GET" action="admin_maintenance.php" class="row g-2">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text custom-dark-select border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control custom-dark-input border-start-0" placeholder="Search description, vehicle, or plate..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="type_filter" class="form-select custom-dark-select" onchange="this.form.submit()">
                        <option value="all" <?= $service_type_filter === 'all' ? 'selected' : '' ?>>All Service Types</option>
                        <?php foreach ($allowed_service_types as $stype): ?>
                            <option value="<?= $stype ?>" <?= $service_type_filter === $stype ? 'selected' : '' ?>><?= $stype ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-danger fw-bold"><i class="fa-solid fa-filter me-1"></i> Filter</button>
                </div>
            </form>
        </div>

        <div class="content-panel">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold text-white mb-0"><i class="fas fa-tools text-danger me-2"></i>Maintenance Logs</h5>
                <span class="badge bg-secondary px-2 py-1"><?= $total_log_records ?> Records</span>
            </div>

            <?php if (empty($maintenance_page_records)): ?>
                <div class="text-center py-4 text-contrast-muted">
                    <i class="fa-solid fa-inbox fa-2x mb-2 d-block"></i>
                    <p class="mb-0">No maintenance records found matching criteria.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Vehicle</th>
                                <th>Service Type</th>
                                <th>Description</th>
                                <th>Service Date</th>
                                <th>Total Run Mileage</th>
                                <th>Cost</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($maintenance_page_records as $m): ?>
                                <tr>
                                    <td class="fw-bold text-danger">#<?= $m['maintenance_id'] ?></td>
                                    <td>
                                        <span class="fw-semibold text-white d-block"><?= htmlspecialchars(($m['brand'] ?? 'N/A') . ' ' . ($m['model_name'] ?? '')) ?></span>
                                        <span class="badge bg-secondary text-uppercase fs-8"><?= htmlspecialchars($m['plate_number'] ?? 'N/A') ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark border border-secondary text-info px-2 py-1 fs-8">
                                            <i class="fa-solid fa-wrench me-1"></i><?= htmlspecialchars($m['service_type'] ?: 'Unspecified') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-contrast-muted"><?= htmlspecialchars($m['description'] ?: 'No details recorded') ?></small>
                                    </td>
                                    <td>
                                        <div class="small text-contrast-muted">
                                            <i class="fa-regular fa-calendar me-1 text-danger"></i><?= date('M d, Y', strtotime($m['service_date'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-contrast-muted small fw-semibold">
                                            <?= $m['present_mileage'] > 0 ? number_format($m['present_mileage']) . ' km' : 'N/A' ?>
                                        </span>
                                    </td>
                                    <td class="fw-bold text-success">
                                        ₱<?= number_format($m['cost'], 2) ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-light px-2 py-1 me-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editMaintenanceModal" 
                                                    data-id="<?= $m['maintenance_id'] ?>"
                                                    data-vehicle_id="<?= $m['vehicle_id'] ?>"
                                                    data-service_type="<?= htmlspecialchars($m['service_type'] ?? '', ENT_QUOTES) ?>"
                                                    data-description="<?= htmlspecialchars($m['description'] ?? '', ENT_QUOTES) ?>"
                                                    data-cost="<?= $m['cost'] ?>"
                                                    data-service_date="<?= $m['service_date'] ?>"
                                                    data-present_mileage="<?= $m['present_mileage'] ?>">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteMaintenanceModal" 
                                                    data-id="<?= $m['maintenance_id'] ?>">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_log_pages > 1): ?>
                    <?php $log_pagination_query = ['search' => $search, 'type_filter' => $service_type_filter, 'page' => $page]; ?>
                    <nav aria-label="Maintenance logs pagination" class="mt-3">
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <li class="page-item <?= $log_page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($log_pagination_query, ['log_page' => max(1, $log_page - 1)])) ?>">Previous</a>
                            </li>
                            <?php for ($i = 1; $i <= $total_log_pages; $i++): ?>
                                <li class="page-item <?= $i === $log_page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($log_pagination_query, ['log_page' => $i])) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $log_page >= $total_log_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($log_pagination_query, ['log_page' => min($total_log_pages, $log_page + 1)])) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- MODAL: Add Maintenance -->
<div class="modal fade" id="addMaintenanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="admin_maintenance.php">
                <input type="hidden" name="action" value="add_maintenance">
                
                <div class="modal-header">
                    <h5 class="modal-title text-white"><i class="fas fa-plus-circle text-danger me-2"></i>Add Maintenance Record</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-contrast-muted">Select Vehicle</label>
                            <select name="vehicle_id" id="add_vehicle_select" class="form-select custom-dark-select" required>
                                <option value="">Choose vehicle...</option>
                                <?php foreach ($vehicles as $v): ?>
                                    <option value="<?= $v['vehicle_id'] ?>" data-current-mileage="<?= $v['current_mileage'] ?? 0 ?>">
                                        <?= htmlspecialchars($v['brand'] . ' ' . $v['model_name'] . ' (' . $v['plate_number'] . ') - Current: ' . number_format($v['current_mileage'] ?? 0) . ' km') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-contrast-muted">Service Type</label>
                            <select name="service_type" class="form-select custom-dark-select" required>
                                <option value="">Choose service type...</option>
                                <?php foreach ($allowed_service_types as $stype): ?>
                                    <option value="<?= $stype ?>"><?= $stype ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-contrast-muted">Cost (₱)</label>
                            <input type="number" step="0.01" min="0" name="cost" class="form-control custom-dark-input" placeholder="0.00" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-contrast-muted">Service Date</label>
                            <input type="date" name="service_date" class="form-control custom-dark-input" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label text-contrast-muted">Total Run Mileage (Present Mileage Input)</label>
                            <input type="number" name="present_mileage" id="add_present_mileage" class="form-control custom-dark-input" placeholder="e.g. 45000" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-contrast-muted">Description / Notes</label>
                            <textarea name="description" rows="3" class="form-control custom-dark-textarea" placeholder="Detailed notes regarding work performed or replacement parts..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-bold">Save Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: Edit Maintenance -->
<div class="modal fade" id="editMaintenanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="admin_maintenance.php">
                <input type="hidden" name="action" value="update_maintenance">
                <input type="hidden" name="maintenance_id" id="edit_maintenance_id">
                
                <div class="modal-header">
                    <h5 class="modal-title text-white"><i class="fas fa-pen text-danger me-2"></i>Edit Maintenance Record</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-contrast-muted">Select Vehicle</label>
                            <select name="vehicle_id" id="edit_vehicle_id" class="form-select custom-dark-select" required>
                                <option value="">Choose vehicle...</option>
                                <?php foreach ($vehicles as $v): ?>
                                    <option value="<?= $v['vehicle_id'] ?>" data-current-mileage="<?= $v['current_mileage'] ?? 0 ?>">
                                        <?= htmlspecialchars($v['brand'] . ' ' . $v['model_name'] . ' (' . $v['plate_number'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-contrast-muted">Service Type</label>
                            <select name="service_type" id="edit_service_type" class="form-select custom-dark-select" required>
                                <option value="">Choose service type...</option>
                                <?php foreach ($allowed_service_types as $stype): ?>
                                    <option value="<?= $stype ?>"><?= $stype ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-contrast-muted">Cost (₱)</label>
                            <input type="number" step="0.01" min="0" name="cost" id="edit_cost" class="form-control custom-dark-input" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-contrast-muted">Service Date</label>
                            <input type="date" name="service_date" id="edit_service_date" class="form-control custom-dark-input" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label text-contrast-muted">Total Run Mileage (km)</label>
                            <input type="number" name="present_mileage" id="edit_present_mileage" class="form-control custom-dark-input" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-contrast-muted">Description / Notes</label>
                            <textarea name="description" id="edit_description" rows="3" class="form-control custom-dark-textarea"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-bold">Update Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: Delete Maintenance -->
<div class="modal fade" id="deleteMaintenanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="admin_maintenance.php">
                <input type="hidden" name="action" value="delete_maintenance">
                <input type="hidden" name="maintenance_id" id="delete_maintenance_id">
                
                <div class="modal-header">
                    <h5 class="modal-title text-white"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Delete Record</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="fas fa-trash-alt fa-3x text-danger mb-3"></i>
                    <h6 class="text-white fw-bold">Are you sure you want to delete this maintenance log?</h6>
                    <p class="text-contrast-muted small mb-0">Record <span id="delete_record_display" class="fw-bold text-danger">#0</span> will be permanently removed.</p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-bold px-4">Confirm Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Dynamic Data Population JS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Populate Edit Modal
    const editModal = document.getElementById('editMaintenanceModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function(event) {
            const btn = event.relatedTarget;
            if (!btn) return;

            document.getElementById('edit_maintenance_id').value = btn.getAttribute('data-id') || '';
            document.getElementById('edit_vehicle_id').value     = btn.getAttribute('data-vehicle_id') || '';
            document.getElementById('edit_service_type').value   = btn.getAttribute('data-service_type') || '';
            document.getElementById('edit_description').value    = btn.getAttribute('data-description') || '';
            document.getElementById('edit_cost').value           = btn.getAttribute('data-cost') || '0.00';
            document.getElementById('edit_service_date').value   = btn.getAttribute('data-service_date') || '';
            document.getElementById('edit_present_mileage').value = btn.getAttribute('data-present_mileage') || '';
        });
    }

    // Populate Delete Modal
    const deleteModal = document.getElementById('deleteMaintenanceModal');
    if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', function(event) {
            const btn = event.relatedTarget;
            if (!btn) return;

            const recordId = btn.getAttribute('data-id');
            document.getElementById('delete_maintenance_id').value = recordId;
            document.getElementById('delete_record_display').textContent = '#' + recordId;
        });
    }
});
</script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const addModal = document.getElementById('addMaintenanceModal');
    
    if (addModal) {
        addModal.addEventListener('show.bs.modal', function (event) {
            const triggerElement = event.relatedTarget;
            const form = addModal.querySelector('form');
            
            if (triggerElement && triggerElement.classList.contains('predictive-card')) {
                const vehicleId = triggerElement.getAttribute('data-vehicle_id');
                const serviceType = triggerElement.getAttribute('data-service_type');
                
                if (vehicleId) {
                    form.querySelector('[name="vehicle_id"]').value = vehicleId;
                }
                
                if (serviceType) {
                    form.querySelector('[name="service_type"]').value = serviceType;
                }
                
                form.querySelector('[name="description"]').value = '';
                form.querySelector('[name="cost"]').value = '';
                form.querySelector('[name="present_mileage"]').value = '';
                
                const dateInput = form.querySelector('[name="service_date"]');
                if (dateInput) dateInput.value = new Date().toISOString().split('T')[0];
            } else if (triggerElement && !triggerElement.hasAttribute('data-id')) {
                form.reset();
                const dateInput = form.querySelector('[name="service_date"]');
                if (dateInput) dateInput.value = new Date().toISOString().split('T')[0];
            }
        });
    }
});
</script>
</body>
</html>