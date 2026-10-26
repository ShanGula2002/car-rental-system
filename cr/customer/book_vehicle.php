<?php
// customer/book_vehicle.php - Process Vehicle Booking Submission
session_start();
require_once '../db.php';

// Auth Guard: Customer access only
if (!isset($_SESSION['user_id']) || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: browse_vehicles.php');
    exit;
}

$user_id       = $_SESSION['user_id'];
$vehicle_id    = intval($_POST['vehicle_id'] ?? 0);
$booking_dates = trim($_POST['booking_dates'] ?? '');
$pickup_lat    = floatval($_POST['pickup_lat'] ?? 0);
$pickup_lng    = floatval($_POST['pickup_lng'] ?? 0);

// Basic Input Validation
if ($vehicle_id <= 0 || empty($booking_dates)) {
    $_SESSION['error'] = "Please select a vehicle and valid rental dates.";
    header('Location: browse_vehicles.php');
    exit;
}

// 1. Parse Flatpickr Date String ("YYYY-MM-DD HH:MM to YYYY-MM-DD HH:MM")
$dates = explode(" to ", $booking_dates);
$pickup_raw = trim($dates[0]);
$return_raw = isset($dates[1]) ? trim($dates[1]) : $pickup_raw;

// Validate date formats (Checking Y-m-d H:i, with a fallback just in case)
$start_dt = DateTime::createFromFormat('Y-m-d H:i', $pickup_raw);
if (!$start_dt) $start_dt = DateTime::createFromFormat('Y-m-d', $pickup_raw);

$end_dt = DateTime::createFromFormat('Y-m-d H:i', $return_raw);
if (!$end_dt) $end_dt = DateTime::createFromFormat('Y-m-d', $return_raw);

if (!$start_dt || !$end_dt || $end_dt < $start_dt) {
    $_SESSION['error'] = "Invalid date or time range selected.";
    header('Location: browse_vehicles.php');
    exit;
}

$end_dt->setTime((int) $start_dt->format('H'), (int) $start_dt->format('i'), 0);

// Format properly for MySQL DATETIME columns
$pickup_date = $start_dt->format('Y-m-d H:i:s');
$return_date = $end_dt->format('Y-m-d H:i:s');

try {
    // 2. Fetch Vehicle Info & Verify Availability
    $stmt_vehicle = $pdo->prepare("SELECT daily_rate, status FROM vehicles WHERE vehicle_id = :id LIMIT 1");
    $stmt_vehicle->execute([':id' => $vehicle_id]);
    $vehicle = $stmt_vehicle->fetch(PDO::FETCH_ASSOC);

    if (!$vehicle || $vehicle['status'] !== 'available') {
        $_SESSION['error'] = "The selected vehicle is no longer available.";
        header('Location: browse_vehicles.php');
        exit;
    }

    // 3. SERVER-SIDE OVERLAP CHECK: Prevent Double-Booking
    $overlap_sql = "
        SELECT COUNT(*) FROM bookings 
        WHERE vehicle_id = :vehicle_id 
          AND status NOT IN ('cancelled', 'rejected', 'completed')
          AND (pickup_date <= :return_date AND return_date >= :pickup_date)
    ";
    $stmt_overlap = $pdo->prepare($overlap_sql);
    $stmt_overlap->execute([
        ':vehicle_id'  => $vehicle_id,
        ':pickup_date' => $pickup_date,
        ':return_date' => $return_date
    ]);

    if ($stmt_overlap->fetchColumn() > 0) {
        $_SESSION['error'] = "This vehicle is already booked for the selected dates/times. Please choose another range.";
        header('Location: browse_vehicles.php');
        exit;
    }

    // 4. Calculate Total Amount based on strict 24-hour blocks
    $start_ts = $start_dt->getTimestamp();
    $end_ts   = $end_dt->getTimestamp();
    
    // Get total difference in seconds
    $diff_seconds = $end_ts - $start_ts;
    
    // Divide by seconds in a day (86,400) and round up using ceil()
    $total_days = ceil($diff_seconds / 86400);
    
    // Ensure minimum of 1 day charge
    $total_days = max(1, $total_days); 
    
    $total_price = $total_days * floatval($vehicle['daily_rate']);

    // 5. Insert Booking Record matching your exact table schema
    $insert_sql = "
        INSERT INTO bookings (
            user_id, 
            vehicle_id, 
            driver_id,
            pickup_date, 
            return_date, 
            pickup_latitude, 
            pickup_longitude, 
            total_price, 
            status, 
            created_at
        ) VALUES (
            :user_id, 
            :vehicle_id, 
            NULL,
            :pickup_date, 
            :return_date, 
            :pickup_latitude, 
            :pickup_longitude, 
            :total_price, 
            'pending', 
            NOW()
        )
    ";
    
    $stmt_insert = $pdo->prepare($insert_sql);
    $stmt_insert->execute([
        ':user_id'          => $user_id,
        ':vehicle_id'       => $vehicle_id,
        ':pickup_date'      => $pickup_date,
        ':return_date'      => $return_date,
        ':pickup_latitude'  => $pickup_lat,
        ':pickup_longitude' => $pickup_lng,
        ':total_price'      => $total_price
    ]);

    // Grab the ID of the booking we just created
    $booking_id = $pdo->lastInsertId();

    $_SESSION['success'] = "Booking submitted successfully! Your reservation is currently pending approval.";
    
    // Redirect to the new receipt page with the booking ID in the URL
    header("Location: receipt.php?booking_id=" . $booking_id);
    exit;

} catch (PDOException $e) {
    $_SESSION['error'] = "Database Error: " . $e->getMessage();
    header('Location: browse_vehicles.php');
    exit;
}