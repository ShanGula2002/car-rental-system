<?php
// Database connection parameters
$db_host = 'localhost';
$db_name = 'car_rental_db';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Fetch all vehicles from the database
    $stmt = $pdo->query("SELECT * FROM vehicles ORDER BY vehicle_id DESC");
    $vehicles = $stmt->fetchAll();
} catch (PDOException $e) {
    $vehicles = [];
    $error_message = "Database connection error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GRLE Car Rental Services | Premium Rides & Smooth Tracking</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --grle-red: #d32f2f;
            --grle-red-hover: #b71c1c;
            --grle-black: #121212;
            --grle-dark-gray: #1e1e1e;
            --grle-light-gray: #f8f9fa;
            --grle-white: #ffffff;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--grle-white);
            color: var(--grle-black);
            overflow-x: hidden;
        }

        /* Top Bar */
        .top-bar {
            background-color: var(--grle-black);
            color: var(--grle-light-gray);
            font-size: 0.85rem;
        }

        /* Navbar */
        .navbar {
            background-color: var(--grle-white);
            box-shadow: 0 3px 18px rgba(18,18,18,0.12);
            border-bottom: 1px solid rgba(18,18,18,0.06);
            z-index: 1030;
        }
        .navbar-brand {
            display: inline-flex;
            align-items: center;
        }
        .brand-image {
            display: block;
            width: 150px;
            height: auto;
        }
        .footer-brand-image {
            display: block;
            width: 170px;
            height: auto;
        }
        .nav-link {
            position: relative;
            color: var(--grle-black) !important;
            font-weight: 500;
            margin-right: 8px;
            padding: 10px 12px !important;
            border-radius: 0;
            transition: color 0.3s;
        }
        .nav-link:hover {
            color: var(--grle-red) !important;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            right: 12px;
            bottom: 3px;
            left: 12px;
            height: 2px;
            background-color: var(--grle-red);
            transform: scaleX(0);
            transform-origin: center;
            transition: transform 0.3s ease;
        }
        .nav-link:hover::after,
        .nav-link.active::after {
            transform: scaleX(1);
        }
        .nav-link.active {
            color: var(--grle-black) !important;
            font-weight: 700;
        }

        /* Custom Red Button */
        .btn-grle-red {
            background-color: var(--grle-red);
            color: var(--grle-white);
            font-weight: 600;
            border-radius: 6px;
            padding: 12px 28px;
            border: none;
            transition: all 0.3s ease;
        }
        .btn-grle-red:hover {
            background-color: var(--grle-red-hover);
            color: var(--grle-white);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(211, 47, 47, 0.4);
        }

        /* Custom Dark Button */
        .btn-grle-dark {
            background-color: var(--grle-black);
            color: var(--grle-white);
            font-weight: 600;
            border-radius: 6px;
            padding: 10px 24px;
            border: none;
            transition: all 0.3s ease;
        }
        .btn-grle-dark:hover {
            background-color: var(--grle-dark-gray);
            color: var(--grle-white);
            transform: translateY(-2px);
        }

        /* Hero Section */
        .hero-section {
            background: linear-gradient(rgba(18, 18, 18, 0.88), rgba(18, 18, 18, 0.88)), url('photo1.jpg') center/cover no-repeat;
            color: var(--grle-white);
            min-height: 680px;
            padding: 120px 0 90px;
            position: relative;
            text-align: center;
            display: flex;
            align-items: center;
        }
        .hero-title {
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.12;
            letter-spacing: -0.5px;
        }
        .hero-title span {
            color: var(--grle-red);
        }

        /* Stat Counter Badges in Hero */
        .hero-stats {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 18px 12px;
            margin-top: 44px;
        }
        .hero-stats .stat-item + .stat-item {
            border-left: 1px solid rgba(255,255,255,0.14);
        }
        .stat-item h3 {
            color: var(--grle-red);
            font-weight: 800;
            margin-bottom: 0;
            font-size: 1.6rem;
        }

        /* Section Headings */
        .section-title {
            font-weight: 700;
            position: relative;
            margin-bottom: 34px;
            letter-spacing: -0.3px;
        }
        .section-title::after {
            content: '';
            width: 60px;
            height: 4px;
            background-color: var(--grle-red);
            position: absolute;
            bottom: -10px;
            left: 0;
        }
        .text-center.section-title::after {
            left: 50%;
            transform: translateX(-50%);
        }

        /* Feature Cards */
        .feature-card {
            background-color: var(--grle-light-gray);
            border-radius: 8px;
            padding: 32px 25px;
            border-bottom: 4px solid transparent;
            transition: all 0.3s ease;
        }
        .feature-card:hover {
            border-bottom: 4px solid var(--grle-red);
            transform: translateY(-8px);
            background-color: var(--grle-white);
            box-shadow: 0 15px 30px rgba(0,0,0,0.08);
        }
        .feature-icon {
            font-size: 2.5rem;
            color: var(--grle-red);
            margin-bottom: 20px;
        }

        /* Fleet Cards */
        .car-card {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            background: var(--grle-white);
            box-shadow: 0 8px 24px rgba(18,18,18,0.06);
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        }
        .car-card:hover {
            box-shadow: 0 16px 32px rgba(18,18,18,0.14);
            border-color: var(--grle-red);
            transform: translateY(-4px);
        }
        .car-img-wrapper {
            height: 230px;
            overflow: hidden;
            background-color: #f1f1f1;
            position: relative;
        }
        .car-img-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }
        .car-card:hover .car-img-wrapper img {
            transform: scale(1.04);
        }
        .car-badge {
            background-color: var(--grle-black);
            color: var(--grle-white);
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 20px;
            letter-spacing: 0.4px;
        }
        .status-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 20px;
            text-transform: capitalize;
        }
        .car-card > .p-4 {
            display: flex;
            flex-direction: column;
            height: calc(100% - 230px);
        }
        .car-card > .p-4 > .btn {
            margin-top: auto;
        }

        /* Portal Access Section */
        .portal-card {
            background-color: var(--grle-black);
            color: var(--grle-white);
            border-radius: 8px;
            padding: 38px 28px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-top: 3px solid var(--grle-red);
            transition: all 0.3s ease;
        }
        .portal-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 30px rgba(211, 47, 47, 0.25);
            border-color: var(--grle-red);
        }
        .portal-icon {
            font-size: 3.2rem;
            color: var(--grle-red);
            margin-bottom: 20px;
        }

        /* Footer */
        footer {
            background-color: var(--grle-black);
            color: #aaaaaa;
            padding-top: 60px;
            padding-bottom: 30px;
            border-top: 4px solid var(--grle-red);
        }
        footer p {
            line-height: 1.8;
        }
        @media (min-width: 768px) {
            footer .footer-column:nth-child(2) {
                transform: translateX(84px);
            }
        }
        footer h5 {
            color: var(--grle-white);
        footer .row {
            justify-content: center;
            text-align: center;
        }
        footer .footer-column {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        footer ul {
            padding-left: 0;
        }
            font-weight: 600;
            margin-bottom: 20px;
        }
        footer a {
            color: #aaaaaa;
            text-decoration: none;
            transition: color 0.2s;
        }
        footer a:hover {
            color: var(--grle-red);
        }

        @media (max-width: 991.98px) {
            .navbar-collapse {
                padding: 12px 0 4px;
                border-top: 1px solid #eeeeee;
                margin-top: 10px;
            }

            .nav-link {
                margin-right: 0;
            }

            .hero-section {
                min-height: 620px;
                padding: 90px 0 70px;
            }

            .hero-title {
                font-size: 3rem;
            }
        }

        @media (max-width: 575.98px) {
            .navbar-brand {
                font-size: 0.9rem;
            }
            .brand-image {
                width: 105px;
            }

            .hero-section {
                min-height: 650px;
                padding: 72px 0 56px;
            }

            .hero-title {
                font-size: 2.25rem;
                line-height: 1.18;
            }

            .hero-section .badge {
                font-size: 0.7rem !important;
                line-height: 1.4;
                white-space: normal;
            }

            .hero-section .lead {
                font-size: 1rem;
                line-height: 1.7;
            }

            .hero-section .d-flex {
                flex-direction: column;
                align-items: stretch;
            }

            .hero-section .btn-grle-red,
            .hero-section .btn-outline-light {
                width: 100%;
            }

            .hero-stats {
                margin-top: 34px;
                padding: 14px 6px;
            }

            .hero-stats .stat-item + .stat-item {
                border-left: 0;
            }

            .hero-stats .stat-item:nth-child(n+3) {
                border-top: 1px solid rgba(255,255,255,0.14);
                padding-top: 10px;
            }

            .stat-item h3 {
                font-size: 1.25rem;
            }

            .section-title {
                font-size: 1.75rem;
            }

            .car-img-wrapper {
                height: 210px;
            }

            .car-card > .p-4 {
                height: calc(100% - 210px);
                padding: 1.25rem !important;
            }

            .car-card > .p-4 > .d-flex {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .car-card > .p-4 > .d-flex > span {
                flex: 1 1 30%;
                min-width: 0;
            }

            .portal-card {
                padding: 32px 22px;
            }

            footer {
                padding-top: 44px;
            }
        }
    </style>
</head>
<body>

    <!-- Top Info Bar -->
    <div class="top-bar py-2 d-none d-md-block">
        <div class="container d-flex justify-content-between align-items-center">
            <div>
                <i class="fas fa-phone-alt text-danger me-2"></i> 09088114729 - 09177006810
                <span class="ms-4"><i class="fas fa-envelope text-danger me-2"></i> support@grlecarrental.com</span>
            </div>
            <div>
                <i class="fas fa-clock text-danger me-2"></i> 24/7 Roadside Assistance & Fleet Tracking
            </div>
        </div>
    </div>

    <!-- Navigation Header -->
    <nav class="navbar navbar-expand-lg sticky-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="logo.png" alt="GRLE Car Rental Shop" class="brand-image">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item"><a class="nav-link active" href="#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#fleet">Our Fleet</a></li>
                    <li class="nav-item"><a class="nav-link" href="#features">Why Choose Us</a></li>
                    <li class="nav-item"><a class="nav-link" href="#portals">Access Portals</a></li>
                    <li class="nav-item ms-lg-3">
                        <a href="#portals" class="btn btn-grle-red text-white"><i class="fas fa-user me-2"></i>Login / Register</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <span class="badge bg-danger mb-3 px-3 py-2 text-uppercase fs-6">Premium Fleet & Live GPS Tracking</span>
                    <h1 class="hero-title mb-4">Drive in Style & Comfort with <span>GRLE</span> Mobility</h1>
                    <p class="lead mb-4 text-light mx-auto" style="max-width: 750px;">
                        Experience top-tier car rentals with automated pick-up point selection, live driver tracking, and meticulously maintained vehicles for your ultimate comfort and safety.
                    </p>
                    <div class="d-flex justify-content-center gap-3">
                        <a href="#fleet" class="btn btn-grle-red"><i class="fas fa-car me-2"></i>Explore Available Vehicles</a>
                        <a href="#portals" class="btn btn-outline-light btn-lg px-4"><i class="fas fa-key me-2"></i>Access Portals</a>
                    </div>

                    <!-- Highlighted Key Metrics Bar -->
                    <div class="hero-stats">
                        <div class="row text-center">
                            <div class="col-md-3 col-6 mb-3 mb-md-0 stat-item">
                                <h3>100%</h3>
                                <small class="text-light">Insured Vehicles</small>
                            </div>
                            <div class="col-md-3 col-6 mb-3 mb-md-0 stat-item">
                                <h3>24/7</h3>
                                <small class="text-light">Live Driver GPS</small>
                            </div>
                            <div class="col-md-3 col-6 stat-item">
                                <h3>0 Hidden</h3>
                                <small class="text-light">Booking Fees</small>
                            </div>
                            <div class="col-md-3 col-6 stat-item">
                                <h3>5★ Rated</h3>
                                <small class="text-light">Customer Service</small>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </section>

    <!-- Why Choose Us / Features -->
    <section id="features" class="py-5 bg-light">
        <div class="container py-4">
            <h2 class="text-center section-title">Why Rent with GRLE?</h2>
            <div class="row g-4 mt-2">
                <div class="col-md-4">
                    <div class="feature-card text-center h-100">
                        <i class="fas fa-map-marked-alt feature-icon"></i>
                        <h4 class="fw-bold mb-3">Live GPS Tracking</h4>
                        <p class="text-muted">Set your pickup location and track your assigned driver on an interactive map in real-time until they reach you.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card text-center h-100">
                        <i class="fas fa-shield-alt feature-icon"></i>
                        <h4 class="fw-bold mb-3">Predictive Maintenance</h4>
                        <p class="text-muted">Every car undergoes strict mileage logs and routine checks for oil, tires, and coolant to guarantee ultimate safety.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card text-center h-100">
                        <i class="fas fa-user-check feature-icon"></i>
                        <h4 class="fw-bold mb-3">Verified Drivers</h4>
                        <p class="text-muted">Our professional drivers record mileage and coordinates automatically when your trip starts and finishes.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Fleet Showcase (Dynamic Data from Database) -->
    <section id="fleet" class="py-5">
        <div class="container py-4">
            <div class="d-flex justify-content-between align-items-end mb-4">
                <div>
                    <h2 class="section-title mb-0">Our Dynamic Fleet</h2>
                    <p class="text-muted mt-2 mb-0">Select from our fleet of verified, maintained, and ready-to-go vehicles.</p>
                </div>
            </div>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-warning text-center" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <?php if (!empty($vehicles)): ?>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <?php 
                            $default_img = 'admin/uploads/vehicles/default_vehicle.svg';
                            $raw_img = trim($vehicle['image_url'] ?? '');

                            if (!empty($raw_img)) {
                                $normalized_img = ltrim($raw_img, '/');

                                if (strpos($normalized_img, 'admin/') === 0) {
                                    $vehicle_img = $normalized_img;
                                } elseif (strpos($normalized_img, 'uploads/') === 0) {
                                    $vehicle_img = 'admin/' . $normalized_img;
                                } else {
                                    $vehicle_img = 'admin/uploads/vehicles/' . $normalized_img;
                                }

                                $vehicle_img = htmlspecialchars($vehicle_img, ENT_QUOTES, 'UTF-8');
                            } else {
                                $vehicle_img = $default_img;
                            }
                            
                            // Dynamic status styling
                            $status = strtolower($vehicle['status'] ?? '');
                            $status_class = 'bg-success';
                            if ($status === 'booked') {
                                $status_class = 'bg-warning text-dark';
                            } elseif ($status === 'maintenance' || $status === 'out_of_service') {
                                $status_class = 'bg-danger';
                            }
                        ?>
                        <div class="col-lg-4 col-md-6">
                            <div class="car-card h-100">
                                <div class="car-img-wrapper">
                                    <img src="<?php echo $vehicle_img; ?>" 
                                         alt="<?php echo htmlspecialchars(($vehicle['brand'] ?? '') . ' ' . ($vehicle['model_name'] ?? '')); ?>"
                                         onerror="this.onerror=null; this.src='<?php echo $default_img; ?>';">
                                    <span class="car-badge position-absolute top-0 start-0 m-3"><?php echo htmlspecialchars($vehicle['category'] ?? ''); ?></span>
                                    <span class="status-badge position-absolute top-0 end-0 m-3 text-white <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars(ucfirst($status)); ?>
                                    </span>
                                </div>
                                <div class="p-4">
                                    <h5 class="fw-bold mb-1">
                                        <?php echo htmlspecialchars(trim(($vehicle['brand'] ?? '') . ' ' . ($vehicle['model_name'] ?? ''))); ?>
                                    </h5>
                                    <p class="text-danger fw-bold mb-3 fs-5">
                                        ₱<?php echo number_format((float)($vehicle['daily_rate'] ?? 0), 2); ?> <span class="text-muted fw-normal fs-6">/ day</span>
                                    </p>
                                    
                                    <div class="d-flex justify-content-between text-muted border-top border-bottom py-2 mb-3 fs-6">
                                        <span><i class="fas fa-user me-1 text-danger"></i> <?php echo htmlspecialchars($vehicle['capacity'] ?? 0); ?> Seats</span>
                                        <span><i class="fas fa-cog me-1 text-danger"></i> <?php echo htmlspecialchars($vehicle['transmission'] ?? ''); ?></span>
                                        <span><i class="fas fa-tachometer-alt me-1 text-danger"></i> <?php echo htmlspecialchars(number_format((float)($vehicle['current_mileage'] ?? 0))); ?> km</span>
                                    </div>
                                    
                                    <?php if ($status === 'available'): ?>
                                        <a href="login.php?role=customer&vehicle_id=<?php echo htmlspecialchars($vehicle['vehicle_id'] ?? ''); ?>" class="btn btn-grle-dark w-100">
                                            <i class="fas fa-calendar-check me-2"></i>Book Vehicle
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-secondary w-100" disabled>
                                            <i class="fas fa-ban me-2"></i>Currently Unavailable
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12 text-center py-5">
                        <i class="fas fa-car-side fa-3x text-muted mb-3"></i>
                        <p class="text-muted fs-5">No vehicles found in the database. Please add vehicles via the admin panel.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Role Portals Access -->
    <section id="portals" class="py-5 bg-light">
        <div class="container py-4">
            <h2 class="text-center section-title">System Access Portals</h2>
            <p class="text-center text-muted mb-5">Select your portal to log into the GRLE Car Rental Management System.</p>

            <div class="row g-4">
                <!-- Customer Portal -->
                <div class="col-md-4">
                    <div class="portal-card">
                        <i class="fas fa-users portal-icon"></i>
                        <h4 class="fw-bold mb-2">Customer</h4>
                        <p class="text-light opacity-75 mb-4">Book cars, set pickup coordinates, and view your driver on the live map.</p>
                        <a href="login.php?role=customer" class="btn btn-grle-red w-100">Customer Login</a>
                    </div>
                </div>

                <!-- Driver Portal -->
                <div class="col-md-4">
                    <div class="portal-card">
                        <i class="fas fa-id-card portal-icon"></i>
                        <h4 class="fw-bold mb-2">Driver</h4>
                        <p class="text-light opacity-75 mb-4">View assigned customer pickups, stream your live location, and record trip mileage.</p>
                        <a href="login.php?role=driver" class="btn btn-grle-red w-100">Driver Portal</a>
                    </div>
                </div>

                <!-- Admin Portal -->
                <div class="col-md-4">
                    <div class="portal-card">
                        <i class="fas fa-tools portal-icon"></i>
                        <h4 class="fw-bold mb-2">Admin</h4>
                        <p class="text-light opacity-75 mb-4">Manage fleet readiness, run predictive maintenance checks, and oversee bookings.</p>
                        <a href="login.php?role=admin" class="btn btn-grle-red w-100">Admin Control Panel</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row g-4 mb-4">
                <div class="col-lg-4 col-md-4 col-12 footer-column">
                    <img src="logo.png" alt="GRLE Car Rental Shop" class="footer-brand-image mb-3">
                    <p>Providing seamless, reliable vehicle rental services backed by intelligent tracking and data-driven maintenance for maximum safety.</p>
                </div>
                <div class="col-lg-4 col-md-4 col-12 footer-column">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="#home">Home</a></li>
                        <li class="mb-2"><a href="#fleet">Our Fleet</a></li>
                        <li class="mb-2"><a href="#features">Features</a></li>
                        <li class="mb-2"><a href="#portals">System Login</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 col-md-4 col-12 footer-column">
                    <h5>Contact Us</h5>
                    <p class="mb-2"><i class="fas fa-map-marker-alt text-danger me-2"></i> Barangay Pacol, Valladolid, Negros Occidental</p>
                    <p class="mb-2"><i class="fas fa-phone-alt text-danger me-2"></i> 09088114729 - 09177006810</p>
                    <p class="mb-0"><i class="fas fa-envelope text-danger me-2"></i> info@grlecarrental.com</p>
                </div>
            </div>
            <hr class="border-secondary">
            <div class="text-center pt-2">
                <p class="mb-0 small">&copy; <?php echo date('Y'); ?> GRLE Car Rental Services. All Rights Reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sectionLinks = document.querySelectorAll('.navbar .nav-link[href^="#"]');
        const sections = [...sectionLinks]
            .map((link) => document.querySelector(link.getAttribute('href')))
            .filter(Boolean);

        const setActiveSection = (sectionId) => {
            sectionLinks.forEach((link) => {
                link.classList.toggle('active', link.getAttribute('href') === `#${sectionId}`);
            });
        };

        sectionLinks.forEach((link) => {
            link.addEventListener('click', () => setActiveSection(link.getAttribute('href').slice(1)));
        });

        const sectionObserver = new IntersectionObserver((entries) => {
            const visibleSection = entries
                .filter((entry) => entry.isIntersecting)
                .sort((first, second) => second.intersectionRatio - first.intersectionRatio)[0];

            if (visibleSection) {
                setActiveSection(visibleSection.target.id);
            }
        }, {
            rootMargin: '-25% 0px -60% 0px',
            threshold: [0, 0.25, 0.5, 0.75, 1]
        });

        sections.forEach((section) => sectionObserver.observe(section));
    </script>
</body>
</html>
