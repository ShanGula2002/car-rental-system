<?php
// customer/customer_dashboard.php - Customer Portal Dashboard
session_start();
require_once '../db.php';

// Auth Guard: Customer access only
if (!isset($_SESSION['user_id']) || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')) {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$active_page = 'dashboard';
$flash_message = '';
$flash_type = '';

// ==========================================
// 1. DYNAMIC ACTION HANDLERS (e.g. Cancel Booking)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'cancel_booking') {
        $booking_id = intval($_POST['booking_id'] ?? 0);
        
        if ($booking_id > 0) {
            try {
                // Verify the booking belongs to this customer and is pending/confirmed
                $stmt = $pdo->prepare("SELECT * FROM bookings WHERE booking_id = :id AND user_id = :user_id");
                $stmt->execute([':id' => $booking_id, ':user_id' => $user_id]);
                $booking = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($booking && in_array($booking['status'], ['pending', 'confirmed'])) {
                    // Update booking status to cancelled
                    $updateStmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE booking_id = :id");
                    $updateStmt->execute([':id' => $booking_id]);

                    // Free up the vehicle
                    $pdo->prepare("UPDATE vehicles SET status = 'available' WHERE vehicle_id = :vid")->execute([':vid' => $booking['vehicle_id']]);

                    $flash_message = "Booking #{$booking_id} has been successfully cancelled.";
                    $flash_type = "success";
                } else {
                    $flash_message = "Invalid booking or it cannot be cancelled at this stage.";
                    $flash_type = "danger";
                }
            } catch (PDOException $e) {
                $flash_message = "Database Error: " . $e->getMessage();
                $flash_type = "danger";
            }
        }
    }
}

// ==========================================
// 2. FETCH CUSTOMER DATA & STATS
// ==========================================
try {
    // Fetch customer profile details
    $userStmt = $pdo->prepare("SELECT first_name, last_name, email, phone FROM users WHERE user_id = :id");
    $userStmt->execute([':id' => $user_id]);
    $customer = $userStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch customer metrics
    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_bookings,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_bookings,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as active_bookings,
            SUM(CASE WHEN status IN ('confirmed', 'completed') THEN total_price ELSE 0 END) as total_spent
        FROM bookings 
        WHERE user_id = :user_id
    ");
    $statsStmt->execute([':user_id' => $user_id]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch customer bookings
    $bookingsStmt = $pdo->prepare("
        SELECT 
            b.*,
            v.brand, v.model_name, v.plate_number
        FROM bookings b
        LEFT JOIN vehicles v ON b.vehicle_id = v.vehicle_id
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
    <title>Customer Dashboard | GRLE Car Rental</title>
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

    /* Top Navbar Styling */
    .navbar-custom {
        background-color: var(--bg-surface);
        border-bottom: 1px solid var(--border-color);
    }
    .navbar-brand {
        font-weight: 700;
        letter-spacing: 0.5px;
    }
    .brand-logo-image {
        display: block;
        width: 115px;
        height: auto;
    }
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

    /* Utility Classes */
    .bg-dark-surface { background-color: var(--bg-surface) !important; }
    .bg-dark-card { background-color: var(--bg-card) !important; }
    .text-contrast-muted { color: var(--text-muted) !important; }

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

    /* Modal Dark Overrides */
    .modal-content {
        background-color: var(--bg-card);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
    }
    .modal-header, .modal-footer {
        border-color: var(--border-color);
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
        .stat-card {
            padding: 16px;
        }

        .table-custom {
            min-width: 680px;
        }

        .modal-footer {
            flex-wrap: wrap;
        }

        .modal-footer .btn {
            flex: 1 1 auto;
        }
    }
</style>
</head>
<body>

    <!-- TOP NAVIGATION BAR (Instead of Sidebar) -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top py-3">
        <div class="container">
            <!-- Brand Logo -->
            <a class="navbar-brand d-flex align-items-center text-white" href="customer_dashboard.php">
                <img src="../logo.png" alt="GRLE Car Rental Shop" class="brand-logo-image">
            </a>

            <!-- Mobile Toggle Button -->
            <button class="navbar-toggler border-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Navbar Nav Links -->
            <div class="collapse navbar-collapse justify-content-between" id="navbarNav">
                <ul class="navbar-nav mx-auto gap-1">
                    <li class="nav-item">
                        <a href="customer_dashboard.php" class="nav-link nav-link-custom active">
                            <i class="fa-solid fa-gauge me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="browse_vehicles.php" class="nav-link nav-link-custom">
                            <i class="fa-solid fa-car me-1"></i> Browse Vehicles
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="my_bookings.php" class="nav-link nav-link-custom">
                            <i class="fa-solid fa-calendar-check me-1"></i> My Reservations
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link nav-link-custom">
                            <i class="fa-solid fa-user-gear me-1"></i> Account Profile
                        </a>
                    </li>
                </ul>

                <!-- User Info & Sign Out -->
                <div class="d-flex align-items-center gap-3 mt-3 mt-lg-0">
                    <span class="text-white small fw-semibold">
                        <i class="fa-solid fa-user-circle text-danger me-1"></i> <?= htmlspecialchars(($customer['first_name'] ?? 'Valued') . ' ' . ($customer['last_name'] ?? 'Customer')) ?>
                    </span>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm fw-bold px-3">
                        <i class="fa-solid fa-right-from-bracket me-1"></i> Sign Out
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- MAIN CONTENT CONTAINER -->
    <div class="container py-4">
        
        <!-- Header Welcome Banner -->
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 pb-3 border-bottom border-secondary gap-3">
            <div>
                <h3 class="fw-bold mb-1 text-white">Welcome back, <?= htmlspecialchars($customer['first_name'] ?? 'Customer') ?>!</h3>
                <p class="text-contrast-muted small mb-0">Manage your active vehicle reservations and explore new cars for your next trip.</p>
            </div>
            <div>
                <a href="browse_vehicles.php" class="btn btn-danger fw-bold shadow-sm">
                    <i class="fas fa-car me-1"></i> Book a Vehicle Now
                </a>
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
            <div class="col-12 col-sm-6 col-xl-4">
                <div class="stat-card">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Total Bookings</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?= number_format($stats['total_bookings'] ?? 0) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-4">
                <div class="stat-card" style="border-left-color: #0288d1;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Active Rentals</span>
                    <h2 class="fw-bold text-white mb-0 mt-1"><?= number_format($stats['active_bookings'] ?? 0) ?></h2>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-4">
                <div class="stat-card" style="border-left-color: #388e3c;">
                    <span class="text-contrast-muted small fw-bold text-uppercase d-block">Total Spent</span>
                    <h2 class="fw-bold text-white mb-0 mt-1">₱<?= number_format($stats['total_spent'] ?? 0, 2) ?></h2>
                </div>
            </div>
        </div>

        <!-- Recent Reservations Panel -->
        <div class="content-panel">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold text-white mb-0"><i class="fas fa-calendar-check text-danger me-2"></i>Your Recent Reservations</h5>
                <a href="browse_vehicles.php" class="small text-danger text-decoration-none fw-semibold">View All Cars &rarr;</a>
            </div>

            <?php if (empty($bookings)): ?>
                <div class="text-center py-5 text-contrast-muted">
                    <i class="fa-solid fa-car-side fa-3x mb-3 d-block text-secondary"></i>
                    <h6 class="text-white fw-bold">No reservations found</h6>
                    <p class="small mb-3">You haven't booked any vehicles yet. Explore our fleet and start your journey today!</p>
                    <a href="browse_vehicles.php" class="btn btn-danger btn-sm fw-bold">Browse Available Vehicles</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Vehicle</th>
                                <th>Pickup / Return Dates</th>
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
                                    $days   = $pickup->diff($return)->days ?: 1;

                                    $badge_class = [
                                        'pending'   => 'bg-warning text-dark',
                                        'confirmed' => 'bg-primary',
                                        'completed' => 'bg-success',
                                        'cancelled' => 'bg-secondary'
                                    ][$b['status']] ?? 'bg-secondary';
                                ?>
                                <tr>
                                    <td class="fw-bold text-danger">#<?= $b['booking_id'] ?></td>
                                    <td>
                                        <span class="fw-semibold text-white d-block"><?= htmlspecialchars(($b['brand'] ?? 'N/A') . ' ' . ($b['model_name'] ?? '')) ?></span>
                                        <span class="badge bg-secondary text-uppercase fs-8"><?= htmlspecialchars($b['plate_number'] ?? 'N/A') ?></span>
                                    </td>
                                    <td>
                                        <div class="small text-contrast-muted">
                                            <i class="fa-regular fa-calendar-check text-success me-1"></i><?= date('M d, Y', strtotime($b['pickup_date'])) ?><br>
                                            <i class="fa-regular fa-calendar-xmark text-danger me-1"></i><?= date('M d, Y', strtotime($b['return_date'])) ?>
                                            <span>(<?= $days ?> <?= $days > 1 ? 'days' : 'day' ?>)</span>
                                        </div>
                                    </td>
                                    <td class="fw-bold text-success">
                                        ₱<?= number_format($b['total_price'], 2) ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $badge_class ?> text-uppercase fs-8 px-2 py-1">
                                            <?= htmlspecialchars($b['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <?php if (in_array($b['status'], ['pending', 'confirmed'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#cancelBookingModal" 
                                                    data-id="<?= $b['booking_id'] ?>">
                                                <i class="fa-solid fa-ban me-1"></i> Cancel
                                            </button>
                                        <?php else: ?>
                                            <span class="text-contrast-muted small">No actions</span>
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

<!-- MODAL: Cancel Booking -->
<div class="modal fade" id="cancelBookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="customer_dashboard.php">
                <input type="hidden" name="action" value="cancel_booking">
                <input type="hidden" name="booking_id" id="cancel_booking_id">
                
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Cancel Reservation</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-contrast-muted">
                    Are you sure you want to cancel this booking? This action will release the vehicle reservation.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Booking</button>
                    <button type="submit" class="btn btn-danger fw-bold">Confirm Cancellation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const cancelModal = document.getElementById('cancelBookingModal');
        if(cancelModal) {
            cancelModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                document.getElementById('cancel_booking_id').value = button.getAttribute('data-id');
            });
        }
    });
</script>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
