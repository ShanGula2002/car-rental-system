<?php
// customer/receipt.php
session_start();
require_once '../db.php';

// Auth Guard
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get the booking ID from the URL
$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

if ($booking_id <= 0) {
    die("Invalid Booking ID.");
}

try {
    // Fetch booking and vehicle details matching your exact database schema
    $sql = "
        SELECT 
            b.*, 
            v.brand, 
            v.model_name, 
            v.year, 
            v.plate_number,
            v.transmission,
            v.capacity
        FROM bookings b
        JOIN vehicles v ON b.vehicle_id = v.vehicle_id
        WHERE b.booking_id = :booking_id AND b.user_id = :user_id
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':booking_id' => $booking_id,
        ':user_id'    => $_SESSION['user_id']
    ]);
    
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$receipt) {
        die("Receipt not found or you do not have permission to view it.");
    }

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Receipt #<?php echo $receipt['booking_id']; ?></title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f9; padding: 20px; }
        .receipt-container { max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .receipt-header { text-align: center; border-bottom: 2px dashed #ddd; padding-bottom: 20px; margin-bottom: 20px; }
        .receipt-header h1 { margin: 0; color: #333; }
        .receipt-header p { color: #28a745; font-weight: bold; margin-top: 10px; }
        .receipt-details { margin-bottom: 20px; }
        .receipt-details table { width: 100%; border-collapse: collapse; }
        .receipt-details th, .receipt-details td { padding: 12px 10px; border-bottom: 1px solid #eee; text-align: left; }
        .receipt-details th { width: 40%; color: #555; }
        .total-row th, .total-row td { font-size: 1.2em; font-weight: bold; color: #000; border-top: 2px solid #333; border-bottom: none; }
        .actions { text-align: center; margin-top: 30px; }
        .btn { padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 0 10px; border: none; cursor: pointer; }
        .btn-secondary { background: #6c757d; }
        @media (max-width: 575.98px) {
            body { padding: 12px; }
            .receipt-container { padding: 20px 16px; }
            .receipt-details { overflow-x: auto; }
            .receipt-details table { min-width: 430px; }
            .receipt-details th, .receipt-details td { padding: 10px 8px; }
            .actions { display: flex; flex-direction: column; gap: 10px; }
            .actions .btn { margin: 0; }
        }
        @media print {
            .actions { display: none; }
            body { background-color: #fff; padding: 0; }
            .receipt-container { box-shadow: none; border: 1px solid #ccc; }
        }
    </style>
</head>
<body>

<div class="receipt-container">
    <div class="receipt-header">
        <h1>Booking Receipt</h1>
        <p>Status: <?php echo ucfirst(htmlspecialchars($receipt['status'])); ?></p>
        <small>Booking Reference: #<?php echo $receipt['booking_id']; ?></small>
    </div>

    <div class="receipt-details">
        <table>
            <tr>
                <th>Vehicle:</th>
                <td><?php echo htmlspecialchars($receipt['year'] . ' ' . $receipt['brand'] . ' ' . $receipt['model_name']); ?></td>
            </tr>
            <tr>
                <th>Plate Number:</th>
                <td><?php echo htmlspecialchars($receipt['plate_number']); ?></td>
            </tr>
            <tr>
                <th>Specs:</th>
                <td><?php echo htmlspecialchars($receipt['transmission']) . ' (' . htmlspecialchars($receipt['capacity']) . ' seats)'; ?></td>
            </tr>
            <tr>
                <th>Pickup Date & Time:</th>
                <td><?php echo date('F j, Y - g:i A', strtotime($receipt['pickup_date'])); ?></td>
            </tr>
            <tr>
                <th>Return Date & Time:</th>
                <td><?php echo date('F j, Y - g:i A', strtotime($receipt['return_date'])); ?></td>
            </tr>
            <tr>
                <th>Booking Date:</th>
                <td><?php echo date('F j, Y', strtotime($receipt['created_at'])); ?></td>
            </tr>
            <tr class="total-row">
                <th>Total Amount:</th>
                <td>₱<?php echo number_format($receipt['total_price'], 2); ?></td>
            </tr>
        </table>
    </div>

    <div class="actions">
        <button onclick="window.print()" class="btn">Print Receipt</button>
        <a href="my_bookings.php" class="btn btn-secondary">Go to My Bookings</a>
    </div>
</div>

</body>
</html>