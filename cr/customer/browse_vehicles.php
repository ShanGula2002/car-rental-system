<?php
// customer/browse_vehicles.php - Browse & Filter Available Vehicles
session_start();
require_once '../db.php';

// Auth Guard: Customer access only
if (!isset($_SESSION['user_id']) || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')) {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$active_page = 'browse';

// Fetch customer details for welcome banner & navbar
$customer = [];
try {
    $stmt_cust = $pdo->prepare("SELECT first_name, last_name FROM users WHERE user_id = :user_id");
    $stmt_cust->execute([':user_id' => $user_id]);
    $customer = $stmt_cust->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $customer = [];
}

// ==========================================
// ALGORITHM: DYNAMIC FILTERING & SORTING
// ==========================================
$search       = trim($_GET['search'] ?? '');
$transmission = trim($_GET['transmission'] ?? '');
$max_price    = floatval($_GET['max_price'] ?? 0);
$sort         = trim($_GET['sort'] ?? 'newest');

// Base query: Only show available vehicles
$where_clauses = ["status = 'available'"];
$params = [];

// 1. Keyword Search Filter (Brand or Model Name)
if (!empty($search)) {
    $where_clauses[] = "(brand LIKE :search_brand OR model_name LIKE :search_model)";
    $params[':search_brand'] = "%{$search}%";
    $params[':search_model'] = "%{$search}%";
}

// 2. Transmission Type Filter
if (!empty($transmission) && in_array($transmission, ['Automatic', 'Manual'])) {
    $where_clauses[] = "transmission = :transmission";
    $params[':transmission'] = $transmission;
}

// 3. Maximum Daily Rate Filter
if ($max_price > 0) {
    $where_clauses[] = "daily_rate <= :max_price";
    $params[':max_price'] = $max_price;
}

// Construct WHERE SQL string safely
$sql = "SELECT * FROM vehicles";
if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

// 4. Sorting Algorithm Logic
switch ($sort) {
    case 'price_low':
        $sql .= " ORDER BY daily_rate ASC";
        break;
    case 'price_high':
        $sql .= " ORDER BY daily_rate DESC";
        break;
    case 'name':
        $sql .= " ORDER BY brand ASC, model_name ASC";
        break;
    case 'newest':
    default:
        $sql .= " ORDER BY vehicle_id DESC";
        break;
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// ==========================================
// FETCH BOOKED DATES FOR APPOINTMENT BLOCKING
// ==========================================
$booked_dates = [];
try {
    $stmt_bookings = $pdo->query("
    SELECT vehicle_id, 
           DATE_FORMAT(pickup_date, '%Y-%m-%d %H:%i') as start_date, 
           DATE_FORMAT(return_date, '%Y-%m-%d %H:%i') as end_date 
    FROM bookings 
    WHERE status NOT IN ('cancelled', 'rejected', 'completed')
");
    while ($row = $stmt_bookings->fetch(PDO::FETCH_ASSOC)) {
        $booked_dates[$row['vehicle_id']][] = [
            'from' => $row['start_date'],
            'to'   => $row['end_date']
        ];
    }
} catch (PDOException $e) {
    $booked_dates = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Vehicles | GRLE Car Rental</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Flatpickr CSS (Date Range Picker) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- Leaflet CSS (Interactive Map) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

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
    .text-contrast-muted { color: var(--text-muted) !important; }

    /* Filter Panel & Vehicle Cards */
    .filter-panel {
        background-color: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 20px;
    }

    .vehicle-card {
        background-color: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        transition: transform 0.2s ease, border-color 0.2s ease;
        overflow: hidden;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .vehicle-card:hover {
        transform: translateY(-4px);
        border-color: var(--grle-red);
    }
    .vehicle-img-wrapper {
        height: 190px;
        background-color: #272b36;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-muted);
        position: relative;
    }

    /* Form Controls Dark Theme Overrides */
    .form-control, .form-select {
        background-color: var(--bg-body) !important;
        border: 1px solid var(--border-color) !important;
        color: var(--text-primary) !important;
    }
    .form-control:focus, .form-select:focus {
        border-color: var(--grle-red) !important;
        box-shadow: 0 0 0 0.25rem rgba(229, 57, 53, 0.25) !important;
    }

    /* Modal Dark Theme */
    .modal-content {
        background-color: var(--bg-card);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }
    .modal-header, .modal-footer {
        border-color: var(--border-color);
    }
    #pickupMap {
        height: 260px;
        width: 100%;
        border-radius: 8px;
        border: 1px solid var(--border-color);
    }

    .vehicle-card .btn-open-booking {
        white-space: nowrap;
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

        .filter-panel {
            padding: 16px;
        }

        .vehicle-img-wrapper {
            height: 175px;
        }

        .vehicle-card .p-3 {
            padding: 1rem !important;
        }

        .vehicle-card .btn-open-booking {
            padding-left: 0.65rem !important;
            padding-right: 0.65rem !important;
        }

        .modal-dialog {
            margin: 0.5rem;
        }

        .modal-body {
            padding: 1rem !important;
        }

        #pickupMap {
            height: 220px;
        }

        .modal-footer {
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

    <!-- TOP NAVIGATION BAR -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center text-white" href="customer_dashboard.php">
                <img src="../logo.png" alt="GRLE Car Rental Shop" class="brand-logo-image">
            </a>

            <button class="navbar-toggler border-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse justify-content-between" id="navbarNav">
                <ul class="navbar-nav mx-auto gap-1">
                    <li class="nav-item">
                        <a href="customer_dashboard.php" class="nav-link nav-link-custom">
                            <i class="fa-solid fa-gauge me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="browse_vehicles.php" class="nav-link nav-link-custom active">
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

    <!-- MAIN CONTAINER -->
    <div class="container py-4">
        
        <!-- Flash Alert Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> <?= htmlspecialchars($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Header Banner -->
        <div class="mb-4 pb-3 border-bottom border-secondary">
            <h3 class="fw-bold mb-1 text-white">
                <i class="fa-solid fa-car text-danger me-2"></i>Welcome, <?= htmlspecialchars($customer['first_name'] ?? 'Valued Customer') ?>! Available Fleet
            </h3>
            <p class="text-contrast-muted small mb-0">Search, filter, and choose from our wide selection of ready-to-rent vehicles.</p>
        </div>

        <!-- FILTER & SEARCH PANEL -->
        <div class="filter-panel mb-4">
            <form method="GET" action="browse_vehicles.php" class="row g-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold text-contrast-muted">Search Brand or Model</label>
                    <div class="input-group">
                        <span class="input-group-text bg-body border-secondary text-contrast-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="e.g. Toyota, Civic..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label small fw-semibold text-contrast-muted">Transmission</label>
                    <select name="transmission" class="form-select">
                        <option value="">All Transmissions</option>
                        <option value="Automatic" <?= $transmission === 'Automatic' ? 'selected' : '' ?>>Automatic</option>
                        <option value="Manual" <?= $transmission === 'Manual' ? 'selected' : '' ?>>Manual</option>
                    </select>
                </div>

                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label small fw-semibold text-contrast-muted">Sort By</label>
                    <select name="sort" class="form-select">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest Arrivals</option>
                        <option value="price_low" <?= $sort === 'price_low' ? 'selected' : '' ?>>Price: Low to High</option>
                        <option value="price_high" <?= $sort === 'price_high' ? 'selected' : '' ?>>Price: High to Low</option>
                        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Vehicle Name (A-Z)</option>
                    </select>
                </div>

                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-danger fw-bold flex-grow-1">
                        <i class="fa-solid fa-filter me-1"></i> Apply Filters
                    </button>
                    <a href="browse_vehicles.php" class="btn btn-outline-secondary px-3" title="Reset Filters">
                        <i class="fa-solid fa-rotate-right"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- VEHICLES GRID LISTING -->
        <?php if (empty($vehicles)): ?>
            <div class="content-panel text-center py-5">
                <i class="fa-solid fa-car-slash fa-3x mb-3 text-secondary d-block"></i>
                <h5 class="text-white fw-bold">No vehicles match your criteria</h5>
                <p class="text-contrast-muted small mb-3">Try adjusting your filters or keyword search to find available cars.</p>
                <a href="browse_vehicles.php" class="btn btn-sm btn-danger fw-bold">Clear Filters</a>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($vehicles as $v): ?>
                    <?php 
                        $image_filename = basename($v['image_url'] ?? '');
                        $resolved_image_path = !empty($image_filename) ? "../admin/uploads/vehicles/" . $image_filename : "";
                    ?>
                    <div class="col-12 col-sm-6 col-xl-4">
                        <div class="vehicle-card shadow-sm">
                            <div class="vehicle-img-wrapper">
                                <?php if (!empty($resolved_image_path)): ?>
                                    <img src="<?= htmlspecialchars($resolved_image_path ?: '../admin/uploads/vehicles/default_vehicle.svg') ?>" 
                                         alt="Vehicle Image" 
                                         class="w-100 h-100 object-fit-cover"
                                         onerror="this.onerror=null; this.src='../admin/uploads/vehicles/default_vehicle.svg';">
                                <?php else: ?>
                                    <div class="text-center">
                                        <i class="fa-solid fa-car fa-3x mb-2 text-secondary"></i>
                                        <div class="small text-contrast-muted">No Image Preview</div>
                                    </div>
                                <?php endif; ?>
                                <span class="badge bg-danger position-absolute top-0 end-0 m-3 text-uppercase fs-8 px-2 py-1">
                                    <?= htmlspecialchars($v['transmission']) ?>
                                </span>
                            </div>

                            <div class="p-3 d-flex flex-column flex-grow-1">
                                <span class="text-contrast-muted small text-uppercase fw-bold"><?= htmlspecialchars($v['brand']) ?></span>
                                <h5 class="fw-bold text-white mb-2"><?= htmlspecialchars($v['model_name']) ?></h5>
                                
                                <div class="row g-1 text-contrast-muted small mb-3">
                                    <div class="col-6"><i class="fa-solid fa-chair text-danger me-1"></i> <?= intval($v['seats'] ?? 5) ?> Seats</div>
                                    <div class="col-6"><i class="fa-solid fa-id-card text-danger me-1"></i> <?= htmlspecialchars($v['plate_number']) ?></div>
                                </div>

                                <div class="mt-auto pt-3 border-top border-secondary d-flex align-items-center justify-content-between">
                                    <div>
                                        <span class="text-contrast-muted small d-block">Daily Rate</span>
                                        <span class="fs-5 fw-bold text-success">₱<?= number_format($v['daily_rate'], 2) ?></span>
                                    </div>
                                    <!-- Book Now button triggering modal -->
                                    <button type="button" 
                                            class="btn btn-sm btn-danger fw-bold px-3 py-2 btn-open-booking" 
                                            data-id="<?= $v['vehicle_id'] ?>"
                                            data-name="<?= htmlspecialchars($v['brand'] . ' ' . $v['model_name']) ?>"
                                            data-rate="<?= htmlspecialchars($v['daily_rate']) ?>"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#bookingModal">
                                        <i class="fa-solid fa-calendar-plus me-1"></i> Book Now
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- ========================================== -->
    <!-- BOOKING MODAL (DATE PICKER & LEAFLET MAP) -->
    <!-- ========================================== -->
    <div class="modal fade" id="bookingModal" tabindex="-1" aria-labelledby="bookingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form action="book_vehicle.php" method="POST" id="bookingForm">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="bookingModalLabel">
                            <i class="fa-solid fa-calendar-check text-danger me-2"></i>Book Vehicle: <span id="modalVehicleName" class="text-danger"></span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-4">
                        <input type="hidden" name="vehicle_id" id="modalVehicleId">
                        <input type="hidden" name="pickup_lat" id="pickup_lat">
                        <input type="hidden" name="pickup_lng" id="pickup_lng">

                        <!-- 1. DATE & TIME RANGE SELECTION -->
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                <i class="fa-solid fa-calendar-days text-danger me-1"></i> Select Rental Dates & Times
                            </label>
                            <input type="text" 
                                   name="booking_dates" 
                                   id="bookingDateRange" 
                                   class="form-control" 
                                   placeholder="Select start and return date & time..." 
                                   required readonly>
                            <div class="form-text text-contrast-muted small mt-1">
                                <i class="fa-solid fa-circle-info me-1"></i> Includes date and time selection. Dates highlighted in gray are already booked.
                            </div>
                        </div>

                        <!-- 2. LEAFLET MAP PICKUP PIN SELECTION WITH SEARCH BAR -->
                        <div class="mb-2">
                            <label class="form-label fw-semibold">
                                <i class="fa-solid fa-location-dot text-danger me-1"></i> Select Pickup Location
                            </label>
                            
                            <!-- Search Bar for Map Location -->
                            <div class="input-group mb-2">
                                <input type="text" id="locationSearchInput" class="form-control" placeholder="Search address, landmark, or city (e.g. Cebu City, Ayala Center)...">
                                <button type="button" id="locationSearchBtn" class="btn btn-outline-danger px-3">
                                    <i class="fa-solid fa-magnifying-glass me-1"></i> Search
                                </button>
                            </div>

                            <div id="pickupMap"></div>
                            <div class="form-text text-contrast-muted small mt-1">
                                <i class="fa-solid fa-hand-pointer me-1"></i> Search a location or drag the red marker to the exact spot where you want to be picked up.
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger fw-bold px-4">
                            <i class="fa-solid fa-check me-1"></i> Confirm & Proceed
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Flatpickr JS -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// JSON array of booked date ranges from database
const bookedDatesByVehicle = <?= json_encode($booked_dates) ?>;

let flatpickrInstance = null;
let map = null;
let marker = null;

/**
 * Initializes Flatpickr with time selection and explicit range separator.
 */
function initBookingTimePicker(vehicleId) {
    const disabledRanges = bookedDatesByVehicle[vehicleId] || [];

    if (flatpickrInstance) {
        flatpickrInstance.destroy();
    }

    flatpickrInstance = flatpickr("#bookingDateRange", {
        mode: "range",
        minDate: "today",
        enableTime: true,
        dateFormat: "Y-m-d H:i",
        rangeSeparator: " to ", // Fixed separator string to prevent parsing mismatches in PHP
        time_24hr: false,
        disable: disabledRanges,
        showMonths: 1,
        onChange: function(selectedDates, dateStr, instance) {
            if (selectedDates.length === 2) {
                const pickupTime = selectedDates[0];
                const returnTime = selectedDates[1];

                returnTime.setHours(pickupTime.getHours(), pickupTime.getMinutes(), 0, 0);
                instance.setDate(selectedDates, false);
            }
        }
    });
}

// Initialize when clicking any "Book Now" button
document.querySelectorAll('.btn-open-booking').forEach(button => {
    button.addEventListener('click', function() {
        const vehicleId = this.getAttribute('data-id');
        const vehicleName = this.getAttribute('data-name');
        
        document.getElementById('modalVehicleId').value = vehicleId;
        document.getElementById('modalVehicleName').textContent = vehicleName;

        initBookingTimePicker(vehicleId);
    });
});

// Function to handle location search query using Nominatim
function searchMapLocation() {
    const query = document.getElementById('locationSearchInput').value.trim();
    if (!query) return;

    fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}`)
        .then(response => response.json())
        .then(data => {
            if (data && data.length > 0) {
                const lat = parseFloat(data[0].lat);
                const lon = parseFloat(data[0].lon);

                map.setView([lat, lon], 15);
                marker.setLatLng([lat, lon]);
                document.getElementById('pickup_lat').value = lat;
                document.getElementById('pickup_lng').value = lon;
            } else {
                alert('Location not found. Please try a different keyword or address.');
            }
        })
        .catch(err => {
            console.error('Geocoding error:', err);
        });
}

// Initialize Leaflet Map when Bootstrap modal is shown
const bookingModalEl = document.getElementById('bookingModal');
bookingModalEl.addEventListener('shown.bs.modal', function () {
    const defaultLat = 14.5995;
    const defaultLng = 120.9842;

    if (!map) {
        map = L.map('pickupMap').setView([defaultLat, defaultLng], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        marker = L.marker([defaultLat, defaultLng], { draggable: true }).addTo(map);

        marker.on('dragend', function (event) {
            const position = marker.getLatLng();
            document.getElementById('pickup_lat').value = position.lat;
            document.getElementById('pickup_lng').value = position.lng;
        });

        document.getElementById('pickup_lat').value = defaultLat;
        document.getElementById('pickup_lng').value = defaultLng;

        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(position => {
                const userLat = position.coords.latitude;
                const userLng = position.coords.longitude;
                map.setView([userLat, userLng], 14);
                marker.setLatLng([userLat, userLng]);
                document.getElementById('pickup_lat').value = userLat;
                document.getElementById('pickup_lng').value = userLng;
            });
        }
    }

    setTimeout(() => {
        map.invalidateSize();
    }, 200);
});

document.getElementById('locationSearchBtn').addEventListener('click', searchMapLocation);
document.getElementById('locationSearchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        searchMapLocation();
    }
});
</script>
</body>
</html>
