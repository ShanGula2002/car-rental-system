<?php
// update_location.php - Receives live GPS coordinates and updates current location
session_start();

require_once '../db.php'; // Adjusted to match driver_dashboard.php path (change if your db.php is elsewhere)

header('Content-Type: application/json');

// 1. Authorization & Role Verification
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['driver', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$driver_id = intval($_SESSION['user_id']);
$role      = $_SESSION['role'] ?? '';

// 2. Process Location Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $booking_id = intval($_POST['booking_id'] ?? 0);
    $new_lat    = (isset($_POST['latitude']) && $_POST['latitude'] !== '') ? floatval($_POST['latitude']) : 0.0;
    $new_lng    = (isset($_POST['longitude']) && $_POST['longitude'] !== '') ? floatval($_POST['longitude']) : 0.0;

    if ($booking_id > 0 && $new_lat != 0.0 && $new_lng != 0.0) {
        try {
            // Build update query scoped to active trips
            $query = "
                UPDATE driver_locations 
                SET current_latitude = :lat, 
                    current_longitude = :lng, 
                    updated_at = NOW() 
                WHERE booking_id = :booking_id 
                  AND status = 'en_route'
            ";

            $params = [
                ':lat'        => $new_lat,
                ':lng'        => $new_lng,
                ':booking_id' => $booking_id
            ];

            // If the user is a driver, enforce ownership check
            if ($role === 'driver') {
                $query .= " AND driver_id = :driver_id";
                $params[':driver_id'] = $driver_id;
            }

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);

            if ($stmt->rowCount() > 0) {
                echo json_encode([
                    'success'   => true, 
                    'message'   => 'Location updated successfully',
                    'latitude'  => $new_lat,
                    'longitude' => $new_lng
                ]);
                exit;
            } else {
                echo json_encode([
                    'success' => false, 
                    'message' => 'No active en_route location record found for this booking or unauthorized access'
                ]);
                exit;
            }
        } catch (PDOException $e) {
            error_log("Update Location Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error occurred while updating location']);
            exit;
        }
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid parameters provided']);