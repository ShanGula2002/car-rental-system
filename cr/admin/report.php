<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php?role=admin');
    exit;
}

$active_page = 'report';
$report_type = $_GET['type'] ?? '';
if (!in_array($report_type, ['', 'financial', 'maintenance'], true)) {
    $report_type = '';
}

function normalize_date_range(string $range, ?string &$start_date, ?string &$end_date): void
{
    $today = new DateTime('today');

    if ($range === 'this_week') {
        $start_date = (clone $today)->modify('monday this week')->format('Y-m-d');
        $end_date = (clone $today)->modify('sunday this week')->format('Y-m-d');
        return;
    }

    if ($range === 'this_month') {
        $start_date = $today->format('Y-m-01');
        $end_date = $today->format('Y-m-t');
        return;
    }

    if ($range === 'custom') {
        if (!empty($start_date) && !empty($end_date)) {
            $start_obj = new DateTime($start_date);
            $end_obj = new DateTime($end_date);
            if ($end_obj < $start_obj) {
                [$start_date, $end_date] = [$end_date, $start_date];
            }
        }
        return;
    }

    $start_date = $today->format('Y-m-01');
    $end_date = $today->format('Y-m-t');
}

$range = $_GET['range'] ?? 'this_month';
$start_date = trim((string) ($_GET['start_date'] ?? ''));
$end_date = trim((string) ($_GET['end_date'] ?? ''));

normalize_date_range($range, $start_date, $end_date);

if ($range !== 'custom') {
    $start_date = $start_date ?: date('Y-m-01');
    $end_date = $end_date ?: date('Y-m-t');
}

if ($range === 'custom' && ($start_date === '' || $end_date === '')) {
    $range = 'this_month';
    normalize_date_range($range, $start_date, $end_date);
}

$bookings = [];
$maintenance_records = [];
$maintenance_summary = [];
$total_bookings = 0;
$revenue_total = 0.0;
$active_revenue_total = 0.0;
$completed_count = 0;
$avg_booking = 0.0;

try {
    if ($report_type === 'maintenance') {
        $maintenance_stmt = $pdo->prepare(" 
            SELECT maintenance_id, vehicle_id, service_type, description, cost, service_date, present_mileage
            FROM maintenance
            WHERE service_date >= :start_date AND service_date <= :end_date
            ORDER BY service_date DESC, maintenance_id DESC
        ");
        $maintenance_stmt->execute([
            ':start_date' => $start_date,
            ':end_date' => $end_date
        ]);
        $maintenance_records = $maintenance_stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary_stmt = $pdo->prepare(" 
            SELECT
                COUNT(*) AS total_records,
                COALESCE(SUM(cost), 0) AS total_cost,
                COALESCE(AVG(cost), 0) AS average_cost,
                COUNT(DISTINCT service_type) AS service_types
            FROM maintenance
            WHERE service_date >= :start_date AND service_date <= :end_date
        ");
        $summary_stmt->execute([
            ':start_date' => $start_date,
            ':end_date' => $end_date
        ]);
        $maintenance_summary = $summary_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } elseif ($report_type === 'financial') {
        $bookings_stmt = $pdo->prepare(" 
            SELECT
                b.booking_id,
                b.pickup_date,
                b.return_date,
                b.total_price,
                b.status,
                u.first_name,
                u.last_name,
                v.brand,
                v.model_name,
                v.plate_number,
                d.first_name AS driver_first_name,
                d.last_name AS driver_last_name
            FROM bookings b
            LEFT JOIN users u ON u.user_id = b.user_id
            LEFT JOIN vehicles v ON v.vehicle_id = b.vehicle_id
            LEFT JOIN users d ON d.user_id = b.driver_id
            WHERE b.status != 'cancelled'
              AND b.pickup_date >= :start_date
              AND b.pickup_date <= :end_date
            ORDER BY b.pickup_date DESC
        ");
        $bookings_stmt->execute([
            ':start_date' => $start_date . ' 00:00:00',
            ':end_date' => $end_date . ' 23:59:59'
        ]);
        $bookings = $bookings_stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary_stmt = $pdo->prepare(" 
            SELECT
                COUNT(*) AS total_bookings,
                SUM(CASE WHEN status = 'completed' THEN total_price ELSE 0 END) AS completed_revenue,
                SUM(CASE WHEN status IN ('approved', 'ongoing', 'completed') THEN total_price ELSE 0 END) AS active_revenue,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count
            FROM bookings
            WHERE status != 'cancelled'
              AND pickup_date >= :start_date
              AND pickup_date <= :end_date
        ");
        $summary_stmt->execute([
            ':start_date' => $start_date . ' 00:00:00',
            ':end_date' => $end_date . ' 23:59:59'
        ]);
        $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_bookings' => 0,
            'completed_revenue' => 0,
            'active_revenue' => 0,
            'completed_count' => 0
        ];

        $revenue_total = (float) ($summary['completed_revenue'] ?? 0);
        $active_revenue_total = (float) ($summary['active_revenue'] ?? 0);
        $total_bookings = (int) ($summary['total_bookings'] ?? 0);
        $completed_count = (int) ($summary['completed_count'] ?? 0);
        $avg_booking = $total_bookings > 0 ? $revenue_total / $total_bookings : 0;
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    $bookings = [];
    $maintenance_records = [];
    $maintenance_summary = [];
    $summary = [
        'total_bookings' => 0,
        'completed_revenue' => 0,
        'active_revenue' => 0,
        'completed_count' => 0
    ];
    $revenue_total = 0;
    $active_revenue_total = 0;
    $total_bookings = 0;
    $completed_count = 0;
    $avg_booking = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $report_type === '' ? 'Generate Report' : ($report_type === 'maintenance' ? 'Maintenance Report' : 'Financial Report') ?> | GRLE Car Rental</title>
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
            --success: #22c55e;
            --warning: #f59e0b;
            --info: #38bdf8;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
        }

        .admin-layout { display: flex; min-height: 100vh; }
        .sidebar-wrapper {
            width: 260px;
            min-width: 260px;
            background: var(--bg-surface);
            border-right: 1px solid var(--border-color);
        }
        .main-content { flex: 1; overflow-x: hidden; }

        .sidebar-btn {
            color: var(--text-muted) !important;
            font-weight: 500;
            padding: 11px 16px;
            border-radius: 8px;
            transition: all 0.2s ease-in-out;
        }
        .sidebar-btn:hover {
            color: var(--text-primary) !important;
            background: rgba(255,255,255,0.08);
        }
        .sidebar-btn.active {
            color: #fff !important;
            background: var(--grle-red) !important;
            font-weight: 600;
        }

        .bg-dark-surface { background: var(--bg-surface) !important; }
        .bg-dark-card { background: var(--bg-card) !important; }
        .text-contrast-muted { color: var(--text-muted) !important; }

        .header-panel,
        .stat-card,
        .content-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }

        .main-content {
            background: var(--bg-body);
        }

        .header-panel {
            padding: 20px 22px;
            background: linear-gradient(180deg, rgba(31,34,43,0.96), rgba(22,24,30,0.96));
        }

        .stat-card {
            border-left: 4px solid var(--grle-red);
            padding: 18px 20px;
            min-height: 108px;
        }

        .stat-card.info { border-left-color: var(--info); }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }

        .custom-dark-input,
        .custom-dark-select {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 8px;
        }
        .custom-dark-select option {
            background-color: var(--bg-card);
            color: var(--text-primary);
        }
        .custom-dark-input::placeholder { color: rgba(255,255,255,0.4); }
        .custom-dark-input:focus,
        .custom-dark-select:focus {
            background: rgba(255,255,255,0.03);
            border-color: rgba(229,57,53,0.8);
            box-shadow: 0 0 0 0.2rem rgba(229,57,53,0.15);
            color: var(--text-primary);
        }

        .table-custom {
            color: var(--text-primary);
            border-collapse: collapse;
        }
        .table-custom thead th {
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
            background: rgba(255,255,255,0.02);
            font-size: 0.78rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 0.9rem 0.75rem;
        }
        .table-custom tbody td {
            border-bottom: 1px solid var(--border-color);
            padding: 0.8rem 0.75rem;
            vertical-align: middle;
            color: var(--text-primary);
            background: rgba(17, 19, 25, 0.78);
        }
        .table-custom tbody tr {
            background: rgba(17, 19, 25, 0.78);
        }
        .table-custom tbody tr:hover {
            background: rgba(255,255,255,0.04);
        }

        .badge-soft {
            border-radius: 999px;
            padding: 0.45rem 0.7rem;
            font-weight: 600;
            letter-spacing: 0.03em;
        }

        .avatar-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(229,57,53,0.15);
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

        @media (max-width: 767.98px) {
            .admin-layout { display: block; }
            .sidebar-wrapper { width: 100%; min-width: 0; border-right: 0; }
            .main-content { padding: 1rem !important; }
            .header-panel { padding: 18px 16px; }
            .table-custom { min-width: 760px; }
        }

        @media print {
            @page { size: A4 portrait; margin: 14mm; }

            body {
                background: #fff !important;
                color: #111 !important;
                font-size: 11pt;
            }
            .sidebar-wrapper,
            .btn,
            .form-controls,
            .d-print-none,
            .page-actions,
            .content-panel.mb-4.p-3,
            .row.g-3.mb-4,
            .header-panel .page-actions {
                display: none !important;
            }
            .main-content {
                padding: 0 !important;
                max-width: 100% !important;
                background: #fff !important;
            }
            .header-panel,
            .stat-card,
            .content-panel {
                background: #fff !important;
                color: #111 !important;
                border: 1px solid #d8d8d8 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }
            .header-panel {
                padding: 0 0 10px !important;
                border: none !important;
                margin-bottom: 12px !important;
            }
            h3, h5, h2, .fw-bold, .text-white, .text-primary, .text-contrast-muted {
                color: #111 !important;
            }
            .table-custom thead th,
            .table-custom tbody td {
                color: #111 !important;
                border-color: #d9d9d9 !important;
                background: #fff !important;
                padding-top: 8px !important;
                padding-bottom: 8px !important;
            }
            .table-custom thead th {
                font-size: 9pt !important;
                letter-spacing: 0.08em !important;
                text-transform: uppercase !important;
                font-weight: 700 !important;
            }
            .table-custom tbody td {
                font-size: 10pt !important;
            }
            .badge-soft {
                border-radius: 999px;
                padding: 3px 8px;
                font-size: 9pt;
            }
        }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php include 'sidebar.php'; ?>

    <main class="main-content p-4">
        <?php if ($report_type === ''): ?>
        <div class="header-panel mb-4">
            <h3 class="fw-bold mb-1 text-white"><i class="fas fa-chart-pie text-danger me-2"></i>Generate Report</h3>
            <p class="text-contrast-muted small mb-0">Choose the report you want to generate.</p>
        </div>

        <div class="content-panel p-4">
            <form method="GET" action="report.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-8">
                    <label for="reportType" class="form-label text-contrast-muted small mb-1">Report Type</label>
                    <select id="reportType" name="type" class="form-select custom-dark-select" required>
                        <option value="" selected disabled>Select a report</option>
                        <option value="financial">Financial Report</option>
                        <option value="maintenance">Maintenance Report</option>
                    </select>
                </div>
                <div class="col-12 col-md-4 d-grid">
                    <button type="submit" class="btn btn-danger fw-bold">
                        <i class="fa-solid fa-arrow-right me-1"></i> Proceed to Report
                    </button>
                </div>
            </form>
        </div>
        <?php else: ?>
        <div class="header-panel mb-4">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div>
                    <h3 class="fw-bold mb-1 text-white">
                        <i class="<?= $report_type === 'maintenance' ? 'fas fa-tools' : 'fas fa-chart-line' ?> text-danger me-2"></i><?= $report_type === 'maintenance' ? 'Maintenance Report' : 'Financial Report' ?>
                    </h3>
                    <p class="text-contrast-muted small mb-0"><?= $report_type === 'maintenance' ? 'Review maintenance records and service costs by service date.' : 'Track booking revenue and booking volume by selected date range.' ?></p>
                </div>
                <div class="page-actions d-flex gap-2 align-items-center">
                    <a href="report.php" class="btn btn-outline-light btn-sm">
                        <i class="fa-solid fa-chevron-left me-1"></i> Change Report
                    </a>
                    <button type="button" class="btn btn-outline-light btn-sm" onclick="window.print()">
                        <i class="fa-solid fa-print me-1"></i> Print
                    </button>
                </div>
            </div>
        </div>

        <div class="content-panel mb-4 p-3">
            <form method="GET" action="report.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label text-contrast-muted small mb-1">Quick Range</label>
                    <div class="btn-group w-100" role="group">
                        <a href="report.php?type=<?= htmlspecialchars($report_type) ?>&amp;range=this_week" class="btn btn-sm <?= $range === 'this_week' ? 'btn-danger' : 'btn-outline-light' ?>">This Week</a>
                        <a href="report.php?type=<?= htmlspecialchars($report_type) ?>&amp;range=this_month" class="btn btn-sm <?= $range === 'this_month' ? 'btn-danger' : 'btn-outline-light' ?>">This Month</a>
                    </div>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label text-contrast-muted small mb-1">Start Date</label>
                    <input type="date" name="start_date" class="form-control custom-dark-input" value="<?= htmlspecialchars($start_date) ?>">
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label text-contrast-muted small mb-1">End Date</label>
                    <input type="date" name="end_date" class="form-control custom-dark-input" value="<?= htmlspecialchars($end_date) ?>">
                </div>

                <div class="col-12 col-md-3 d-grid">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($report_type) ?>">
                    <input type="hidden" name="range" value="custom">
                    <button type="submit" class="btn btn-danger fw-bold">
                        <i class="fa-solid fa-filter me-1"></i> Apply Filter
                    </button>
                </div>
            </form>
        </div>

        <?php if ($report_type === 'financial'): ?>
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Total Bookings</span>
                    <h2 class="fw-bold text-white mb-0 mt-2"><?= number_format($total_bookings) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card info">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Completed Revenue</span>
                    <h2 class="fw-bold text-white mb-0 mt-2">₱<?= number_format($revenue_total, 2) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card success">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Active Revenue</span>
                    <h2 class="fw-bold text-white mb-0 mt-2">₱<?= number_format($active_revenue_total, 2) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card warning">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Average Booking</span>
                    <h2 class="fw-bold text-white mb-0 mt-2">₱<?= number_format($avg_booking, 2) ?></h2>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Maintenance Records</span>
                    <h2 class="fw-bold text-white mb-0 mt-2"><?= number_format((int) ($maintenance_summary['total_records'] ?? 0)) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card info">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Total Maintenance Cost</span>
                    <h2 class="fw-bold text-white mb-0 mt-2">₱<?= number_format((float) ($maintenance_summary['total_cost'] ?? 0), 2) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card success">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Average Service Cost</span>
                    <h2 class="fw-bold text-white mb-0 mt-2">₱<?= number_format((float) ($maintenance_summary['average_cost'] ?? 0), 2) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card warning">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Service Types</span>
                    <h2 class="fw-bold text-white mb-0 mt-2"><?= number_format((int) ($maintenance_summary['service_types'] ?? 0)) ?></h2>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($report_type === 'financial'): ?>
        <div class="content-panel p-3">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold text-white mb-0"><i class="fa-solid fa-list-check text-danger me-2"></i>Revenue Details</h5>
                <span class="badge bg-secondary px-2 py-1"><?= count($bookings) ?> records</span>
            </div>

            <?php if (empty($bookings)): ?>
                <div class="text-center py-5 text-contrast-muted">
                    <i class="fa-solid fa-calendar-xmark fa-2x mb-2 d-block"></i>
                    <p class="mb-0">No bookings found for this date range.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Booking</th>
                                <th>Customer</th>
                                <th>Vehicle</th>
                                <th>Driver</th>
                                <th>Pickup</th>
                                <th>Return</th>
                                <th>Status</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $booking): ?>
                                <?php
                                    $customer_name = trim((string) (($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? '')));
                                    $driver_name = trim((string) (($booking['driver_first_name'] ?? '') . ' ' . ($booking['driver_last_name'] ?? '')));
                                    $status_label = ucfirst((string) ($booking['status'] ?? 'pending'));
                                    $status_class = match ($booking['status'] ?? 'pending') {
                                        'completed' => 'bg-success',
                                        'approved' => 'bg-info text-dark',
                                        'ongoing' => 'bg-warning text-dark',
                                        'cancelled' => 'bg-danger',
                                        default => 'bg-secondary'
                                    };
                                ?>
                                <tr>
                                    <td class="fw-bold text-danger">#<?= (int) ($booking['booking_id'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars($customer_name ?: 'Unknown Customer') ?></td>
                                    <td>
                                        <?= htmlspecialchars(trim((string) (($booking['brand'] ?? '') . ' ' . ($booking['model_name'] ?? '')) ?: 'Unknown Vehicle')) ?><br>
                                        <small class="text-contrast-muted"><?= htmlspecialchars($booking['plate_number'] ?? 'N/A') ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($driver_name ?: 'Unassigned') ?></td>
                                    <td><?= date('M d, Y', strtotime($booking['pickup_date'])) ?></td>
                                    <td><?= date('M d, Y', strtotime($booking['return_date'])) ?></td>
                                    <td><span class="badge-soft <?= $status_class ?>"><?= htmlspecialchars($status_label) ?></span></td>
                                    <td class="text-end fw-bold text-success">₱<?= number_format((float) ($booking['total_price'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="content-panel p-3">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold text-white mb-0"><i class="fa-solid fa-screwdriver-wrench text-danger me-2"></i>Maintenance Details</h5>
                <span class="badge bg-secondary px-2 py-1"><?= count($maintenance_records ?? []) ?> records</span>
            </div>

            <?php if (empty($maintenance_records)): ?>
                <div class="text-center py-5 text-contrast-muted">
                    <i class="fa-solid fa-clipboard-list fa-2x mb-2 d-block"></i>
                    <p class="mb-0">No maintenance records found for this date range.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Record</th>
                                <th>Vehicle ID</th>
                                <th>Service Date</th>
                                <th>Service Type</th>
                                <th>Description</th>
                                <th class="text-end">Mileage</th>
                                <th class="text-end">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($maintenance_records as $record): ?>
                                <tr>
                                    <td class="fw-bold text-danger">#<?= (int) ($record['maintenance_id'] ?? 0) ?></td>
                                    <td><?= (int) ($record['vehicle_id'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars(date('M d, Y', strtotime($record['service_date'] ?? ''))) ?></td>
                                    <td><?= htmlspecialchars($record['service_type'] ?? 'Unspecified') ?></td>
                                    <td><?= htmlspecialchars(trim((string) ($record['description'] ?? '')) ?: 'N/A') ?></td>
                                    <td class="text-end"><?= number_format((int) ($record['present_mileage'] ?? 0)) ?></td>
                                    <td class="text-end fw-bold text-success">₱<?= number_format((float) ($record['cost'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
