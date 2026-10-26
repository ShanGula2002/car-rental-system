<?php
// sidebar.php - Reusable Sidebar Navigation Module for Admin Panel
// Usage: Set $active_page = 'dashboard' (or 'vehicles', 'users', etc.) before including this file.
$active_page = $active_page ?? 'dashboard';

$maintenance_overdue_count = intval($maintenance_overdue_count ?? 0);
$maintenance_warning_count = intval($maintenance_warning_count ?? 0);

// Shared sidebar notification badge styling for maintenance alerts
?>
<style>
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
</style>
<?php

if (($maintenance_overdue_count + $maintenance_warning_count) === 0) {
    try {
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

        // FIX: Changed variable to $sidebar_vehicles to prevent overwriting your main pages
        $sidebar_vehicles = $pdo->query("SELECT vehicle_id, brand, model_name, current_mileage FROM vehicles ORDER BY brand, model_name")->fetchAll(PDO::FETCH_ASSOC);
        $maintenance_logs = $pdo->query("SELECT vehicle_id, service_type, service_date, present_mileage FROM maintenance ORDER BY service_date DESC")->fetchAll(PDO::FETCH_ASSOC);

        $latest_service_by_vehicle_type = [];
        foreach ($maintenance_logs as $record) {
            $vehicle_id = (int)($record['vehicle_id'] ?? 0);
            $service_type = trim((string)($record['service_type'] ?? ''));
            if ($vehicle_id <= 0 || $service_type === '') {
                continue;
            }

            $record_date = strtotime($record['service_date'] ?? '1970-01-01');
            $existing = $latest_service_by_vehicle_type[$vehicle_id][$service_type] ?? null;

            if (!$existing || $record_date > strtotime($existing['service_date'] ?? '1970-01-01')) {
                $latest_service_by_vehicle_type[$vehicle_id][$service_type] = $record;
            }
        }

        $maintenance_overdue_count = 0;
        $maintenance_warning_count = 0;
        $today = new DateTime('today');

        // FIX: Loop through $sidebar_vehicles instead
        foreach ($sidebar_vehicles as $vehicle) {
            $vehicle_id = (int)($vehicle['vehicle_id'] ?? 0);
            $current_mileage = max(0, (float)($vehicle['current_mileage'] ?? 0));

            foreach ($service_rules as $service_type => $rule) {
                $latest = $latest_service_by_vehicle_type[$vehicle_id][$service_type] ?? null;
                if (!$latest) {
                    $maintenance_warning_count++;
                    continue;
                }

                $last_service_mileage = (float)($latest['present_mileage'] ?? 0);
                $last_service_date = $latest['service_date'] ?? null;
                $days_since = 0;

                if ($last_service_date) {
                    $service_dt = new DateTime($last_service_date);
                    $service_dt->setTime(0, 0, 0);
                    $days_since = max(0, (int) $today->diff($service_dt)->format('%r%a'));
                }

                $mileage_since = $last_service_mileage > 0 ? max(0, $current_mileage - $last_service_mileage) : 0;
                $mileage_ratio = $mileage_since > 0 && $rule['interval_km'] > 0 ? min(1.5, ($mileage_since / $rule['interval_km'])) : 0;
                $days_ratio = $days_since > 0 && $rule['interval_days'] > 0 ? min(1.5, ($days_since / $rule['interval_days'])) : 0;
                $usage_ratio = max($mileage_ratio, $days_ratio);

                if ($usage_ratio >= 1.0) {
                    $maintenance_overdue_count++;
                } elseif ($usage_ratio >= 0.6) {
                    $maintenance_warning_count++;
                }
            }
        }
    } catch (Exception $e) {
        $maintenance_overdue_count = 0;
        $maintenance_warning_count = 0;
    }
}
?>

<!-- Mobile Navbar Toggle Header -->
<div class="d-md-none p-3 bg-dark-surface border-bottom border-secondary d-flex justify-content-between align-items-center">
    <a href="admin_dashboard.php" class="text-white fw-bold text-decoration-none">
        <img src="../logo.png" alt="GRLE Car Rental Shop" style="display: block; width: 90px; height: auto;">
    </a>
    <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#adminSidebar">
        <i class="fas fa-bars"></i>
    </button>
</div>

<!-- Sidebar Container -->
<!-- FIX: Replaced 'collapse' with 'd-none' to stop display flickering -->
<div class="collapse d-md-block sidebar-wrapper bg-dark-surface" id="adminSidebar">
    <div class="d-flex flex-column h-100 p-3">
        
        <!-- Brand Logo Header -->
        <a href="admin_dashboard.php" class="d-none d-md-flex flex-column align-items-center text-center mb-4 text-white text-decoration-none px-2 pt-2">
            <img src="../logo.png" alt="GRLE Car Rental Shop" style="display: block; width: 175px; height: auto;">
            <small class="text-contrast-muted fs-7 mt-1 w-100">Admin Control Center</small>
        </a>

        <hr class="border-secondary my-2">

        <?php
            // FIX: Linked red_count to the actual overdue logic variable
            $maintenance_red_count = intval($maintenance_overdue_count ?? 0);
            $maintenance_yellow_count = intval($maintenance_warning_count ?? 0);
        ?>

        <!-- Navigation Links -->
        <ul class="nav nav-pills flex-column mb-auto mt-2 gap-1">
            <li class="nav-item">
                <a href="admin_dashboard.php" class="nav-link sidebar-btn <?php echo ($active_page === 'dashboard') ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line me-3"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_users.php" class="nav-link sidebar-btn <?php echo ($active_page === 'users') ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog me-3"></i> User Approvals
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_vehicles.php" class="nav-link sidebar-btn <?php echo ($active_page === 'vehicles') ? 'active' : ''; ?>">
                    <i class="fas fa-car me-3"></i> Fleet Management
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_bookings.php" class="nav-link sidebar-btn <?php echo ($active_page === 'bookings') ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt me-3"></i> Bookings & Trips
                </a>
            </li>
            <li class="nav-item">
                <a href="track_vehicles.php" class="nav-link sidebar-btn <?php echo ($active_page === 'tracking') ? 'active' : ''; ?>">
                    <i class="fas fa-location-crosshairs me-3"></i> Live Tracking
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_maintenance.php" class="nav-link sidebar-btn <?php echo ($active_page === 'maintenance') ? 'active' : ''; ?>" title="<?= $maintenance_red_count > 0 ? $maintenance_red_count . ' overdue maintenance alerts' : ($maintenance_yellow_count > 0 ? $maintenance_yellow_count . ' upcoming maintenance alerts' : 'No maintenance alerts') ?>">
                    <span class="sidebar-link-content">
                        <span class="sidebar-link-label"><i class="fas fa-tools me-3"></i> Maintenance</span>
                        <?php if (($maintenance_red_count + $maintenance_yellow_count) > 0): ?>
                            <span class="sidebar-alert-stack">
                                <span class="sidebar-inline-badge bg-danger text-white"><?= $maintenance_red_count ?></span>
                                <span class="sidebar-inline-badge bg-warning text-dark"><?= $maintenance_yellow_count ?></span>
                            </span>
                        <?php endif; ?>
                    </span>
                </a>
            </li>
            <li class="nav-item">
                <a href="report.php" class="nav-link sidebar-btn <?php echo ($active_page === 'report') ? 'active' : ''; ?>">
                    <i class="fas fa-chart-pie me-3"></i> Reports
                </a>
            </li>
        </ul>

        <hr class="border-secondary my-3">

        <!-- Current User Profile Footer -->
        <div class="user-profile-footer p-2 rounded-3 bg-dark-card d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center me-2 overflow-hidden">
                <div class="avatar-circle me-2">
                    <i class="fas fa-user-shield text-danger"></i>
                </div>
                <div class="lh-1 text-truncate">
                    <span class="fw-bold d-block text-white fs-7 text-truncate"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></span>
                    <small class="text-contrast-muted fs-8">Administrator</small>
                </div>
            </div>
            <a href="logout.php" class="btn btn-sm btn-outline-danger px-2 py-1" title="Log Out">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>

    </div>
</div>
