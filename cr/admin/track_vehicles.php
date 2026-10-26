<?php
// track_vehicles.php - Admin live driver and vehicle location monitor
session_start();
require_once '../db.php';
require_once '../maintenance_rules.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    if (($_GET['action'] ?? '') === 'locations') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit;
    }
    header('Location: ../login.php?role=admin');
    exit;
}

function fetch_live_locations(PDO $pdo): array
{
    $stmt = $pdo->query("
         SELECT dl.location_id, dl.booking_id, dl.driver_id, dl.vehicle_id,
             dl.current_latitude, dl.current_longitude, dl.end_latitude,
             dl.end_longitude, dl.total_mileage,
               dl.status AS trip_status, dl.updated_at, b.status AS booking_status,
               v.brand, v.model_name, v.year, v.plate_number, v.category,
               d.first_name AS driver_first_name, d.last_name AS driver_last_name,
               d.email AS driver_email, d.phone AS driver_phone
        FROM driver_locations dl
        INNER JOIN (
            SELECT booking_id, MAX(location_id) AS latest_location_id
            FROM driver_locations
            GROUP BY booking_id
        ) latest ON latest.latest_location_id = dl.location_id
        LEFT JOIN bookings b ON b.booking_id = dl.booking_id
        LEFT JOIN vehicles v ON v.vehicle_id = dl.vehicle_id
        LEFT JOIN users d ON d.user_id = dl.driver_id AND d.role = 'driver'
        WHERE dl.current_latitude <> 0 AND dl.current_longitude <> 0
        ORDER BY dl.updated_at DESC, dl.location_id DESC
    ");

    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($locations as &$location) {
        $vehicle_id = (int) ($location['vehicle_id'] ?? 0);
        $has_end_latitude = $location['end_latitude'] !== null && $location['end_latitude'] !== '' && (float) $location['end_latitude'] !== 0.0;
        $has_end_longitude = $location['end_longitude'] !== null && $location['end_longitude'] !== '' && (float) $location['end_longitude'] !== 0.0;
        $location['tracking_type'] = $has_end_latitude && $has_end_longitude ? 'completed' : 'ongoing';

        $location['maintenance_alert'] = $vehicle_id > 0 ? get_vehicle_maintenance_alert($pdo, $vehicle_id) : null;
    }
    unset($location);

    return $locations;
}

try {
    $locations = fetch_live_locations($pdo);
    $page_error = '';
} catch (PDOException $e) {
    error_log('Live tracking query failed: ' . $e->getMessage());
    $locations = [];
    $page_error = 'Live location data is temporarily unavailable.';
}

if (($_GET['action'] ?? '') === 'locations') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['success' => $page_error === '', 'locations' => $locations, 'message' => $page_error ?: null]);
    exit;
}

$active_page = 'tracking';
$locations_json = json_encode($locations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Vehicle Tracking | GRLE Car Rental</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root { --bg-body: #0d0e12; --bg-surface: #16181e; --bg-card: #1f222b; --border-color: #2e323e; --text-primary: #fff; --text-muted: #c5c6d0; --grle-red: #e53935; }
        body { font-family: 'Poppins', sans-serif; background: var(--bg-body); color: var(--text-primary); min-height: 100vh; }
        .admin-layout { display: flex; min-height: 100vh; }
        .sidebar-wrapper { width: 260px; min-width: 260px; border-right: 1px solid var(--border-color); background: var(--bg-surface); }
        .main-content { flex-grow: 1; overflow-x: hidden; }
        .sidebar-btn { color: var(--text-muted) !important; font-weight: 500; padding: 11px 16px; border-radius: 8px; transition: all .2s ease-in-out; }
        .sidebar-btn:hover { color: var(--text-primary) !important; background: rgba(255,255,255,.08); }
        .sidebar-btn.active { color: #fff !important; background: var(--grle-red) !important; font-weight: 600; }
        .bg-dark-surface { background: var(--bg-surface) !important; } .bg-dark-card { background: var(--bg-card) !important; } .text-contrast-muted { color: var(--text-muted) !important; }
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
        .content-panel { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 22px; }
        .stat-card { background: var(--bg-card); border: 1px solid var(--border-color); border-left: 4px solid var(--grle-red); border-radius: 10px; padding: 20px; }
        #trackingMap { height: 560px; min-height: 360px; border-radius: 8px; z-index: 1; }
        .driver-row { border-bottom: 1px solid var(--border-color); padding: 14px 0; cursor: pointer; } .driver-row:last-child { border-bottom: 0; }
        .tracking-marker { display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; border: 3px solid #fff; border-radius: 50% 50% 50% 0; box-shadow: 0 2px 6px rgba(0,0,0,.45); transform: rotate(-45deg); }
        .tracking-marker i { transform: rotate(45deg); color: #fff; font-size: 15px; }
        .tracking-marker.ongoing { background: #0288d1; animation: marker-pulse 1.4s ease-in-out infinite; }
        .tracking-marker.completed { background: #2e7d32; }
        @keyframes marker-pulse { 0%, 100% { box-shadow: 0 2px 6px rgba(0,0,0,.45), 0 0 0 0 rgba(2,136,209,.65); } 50% { box-shadow: 0 2px 6px rgba(0,0,0,.45), 0 0 0 10px rgba(2,136,209,0); } }
        .leaflet-popup-content-wrapper, .leaflet-popup-tip { background: #1f222b; color: #fff; } .leaflet-popup-content { margin: 14px; }
        @media (max-width: 767.98px) {
            .admin-layout { display: block; }
            .sidebar-wrapper { width: 100%; min-width: 0; border-right: 0; }
            .main-content { padding: 1rem !important; }
            .content-panel, .stat-card { padding: 16px; }
            #trackingMap { height: 360px; }
            .main-content > .d-flex:first-child { align-items: flex-start !important; flex-wrap: wrap; }
            #connectionStatus { display: inline-block; }
        }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php include 'sidebar.php'; ?>
    <main class="main-content p-4">
        <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom border-secondary gap-3">
            <div><h3 class="fw-bold mb-1 text-white"><i class="fas fa-location-crosshairs text-danger me-2"></i>Live Vehicle Tracking</h3><p class="text-contrast-muted small mb-0">Monitor active driver locations, assigned vehicles, and trip status.</p></div>
            <span class="badge bg-success px-3 py-2 text-nowrap"><i class="fas fa-signal me-1"></i><span id="connectionStatus">Live</span></span>
        </div>
        <?php if ($page_error): ?><div class="alert alert-danger text-white"><i class="fas fa-circle-exclamation me-2"></i><?= htmlspecialchars($page_error) ?></div><?php endif; ?>
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-4"><div class="stat-card"><span class="text-contrast-muted small fw-bold text-uppercase d-block">Tracked Drivers</span><h2 class="fw-bold mb-0 mt-1" id="trackedCount"><?= count($locations) ?></h2></div></div>
            <div class="col-12 col-sm-4"><div class="stat-card" style="border-left-color:#0288d1"><span class="text-contrast-muted small fw-bold text-uppercase d-block">Ongoing</span><h2 class="fw-bold mb-0 mt-1" id="enRouteCount"><?= count(array_filter($locations, fn($location) => $location['tracking_type'] === 'ongoing')) ?></h2></div></div>
            <div class="col-12 col-sm-4"><div class="stat-card" style="border-left-color:#37c871"><span class="text-contrast-muted small fw-bold text-uppercase d-block">Last Refresh</span><h2 class="fw-bold mb-0 mt-1 fs-5" id="lastRefresh">Just now</h2></div></div>
        </div>
        <div class="row g-4">
            <div class="col-xl-8"><div class="content-panel p-2"><div id="trackingMap" aria-label="Live map of driver locations"></div></div></div>
            <div class="col-xl-4"><div class="content-panel h-100"><div class="d-flex justify-content-between align-items-center mb-2"><h5 class="fw-bold mb-0"><i class="fas fa-users text-danger me-2"></i>Active Signals</h5><span class="badge bg-secondary" id="signalCount"><?= count($locations) ?></span></div><div id="driverList"></div></div></div>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const initialLocations = <?= $locations_json ?: '[]' ?>;
const map = L.map('trackingMap').setView([10.53, 122.84], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'}).addTo(map);
const markerLayer = L.layerGroup().addTo(map);

function escapeHtml(value) { const element = document.createElement('div'); element.textContent = value ?? ''; return element.innerHTML; }

function renderLocations(locations, fitMap = false) {
    markerLayer.clearLayers();
    const list = document.getElementById('driverList');
    document.getElementById('trackedCount').textContent = locations.length;
    document.getElementById('signalCount').textContent = locations.length;
    document.getElementById('enRouteCount').textContent = locations.filter(item => item.tracking_type === 'ongoing').length;
    list.innerHTML = '';
    const bounds = [];
    locations.forEach(location => {
        const lat = Number(location.current_latitude); const lng = Number(location.current_longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng) || (lat === 0 && lng === 0)) return;
        bounds.push([lat, lng]);
        const driver = `${location.driver_first_name || 'Unassigned'} ${location.driver_last_name || ''}`.trim();
        const vehicle = `${location.brand || 'Vehicle'} ${location.model_name || ''}`.trim();
        const vehicleDetails = `${location.year || ''} ${location.category || ''}`.trim();
        const trackingType = location.tracking_type === 'completed' ? 'completed' : 'ongoing';
        const markerIcon = L.divIcon({className: '', html: `<span class="tracking-marker ${trackingType}"><i class="fas ${trackingType === 'completed' ? 'fa-check' : 'fa-car'}"></i></span>`, iconSize: [34, 34], iconAnchor: [17, 34], popupAnchor: [0, -34]});
        const marker = L.marker([lat, lng], {icon: markerIcon}).bindPopup(`<strong>${escapeHtml(vehicle)}</strong>${vehicleDetails ? `<br>${escapeHtml(vehicleDetails)}` : ''}<br>Plate: ${escapeHtml(location.plate_number || 'N/A')}<br>Driver: ${escapeHtml(driver)}<br>${escapeHtml(location.driver_phone || 'No phone')} &middot; ${escapeHtml(location.driver_email || 'No email')}<br>Tracking: ${escapeHtml(trackingType)}<br><small>Updated: ${escapeHtml(location.updated_at || 'N/A')}</small>`);
        markerLayer.addLayer(marker);
        const row = document.createElement('div'); row.className = 'driver-row';
        const maintenanceAlert = location.maintenance_alert || null;
        const maintenanceStatus = maintenanceAlert ? (maintenanceAlert.status === 'overdue' ? 'bg-danger' : 'bg-warning text-dark') : 'bg-secondary';
        const maintenanceLabel = maintenanceAlert ? (maintenanceAlert.status === 'overdue' ? 'Overdue' : 'Upcoming') : 'OK';
        const maintenanceMessage = maintenanceAlert ? `${escapeHtml(maintenanceAlert.service_type || 'Maintenance')} – ${escapeHtml(maintenanceAlert.message || '')}` : '';

        row.innerHTML = `<div class="d-flex justify-content-between gap-2"><strong class="text-white">${escapeHtml(driver)}</strong><span class="badge ${trackingType === 'ongoing' ? 'bg-info text-dark' : 'bg-success'} text-uppercase">${escapeHtml(trackingType)}</span></div><div class="small text-contrast-muted mt-1"><i class="fas fa-car text-danger me-1"></i>${escapeHtml(vehicle)}${vehicleDetails ? ` (${escapeHtml(vehicleDetails)})` : ''} &middot; ${escapeHtml(location.plate_number || 'No plate')}</div><div class="small text-contrast-muted"><i class="fas fa-address-card me-1"></i>${escapeHtml(location.driver_phone || 'No phone')} &middot; ${escapeHtml(location.driver_email || 'No email')}</div><div class="small text-contrast-muted"><i class="fas fa-map-pin text-danger me-1"></i>${lat.toFixed(5)}, ${lng.toFixed(5)} &middot; Booking #${escapeHtml(String(location.booking_id))}</div><div class="small text-contrast-muted"><i class="fas fa-clock me-1"></i>${escapeHtml(location.updated_at || 'No update time')}</div>${maintenanceAlert ? `<div class="mt-2"><span class="badge ${maintenanceStatus}">${maintenanceLabel}</span><div class="small text-contrast-muted mt-1">${maintenanceMessage}</div></div>` : ''}`;
        row.addEventListener('click', () => { map.setView([lat, lng], 16); marker.openPopup(); }); list.appendChild(row);
    });
    if (!locations.length) list.innerHTML = '<div class="text-center text-contrast-muted py-5"><i class="fas fa-location-dot fs-2 mb-2 d-block"></i>No driver location signals found.</div>';
    if (fitMap && bounds.length) map.fitBounds(bounds, {padding: [30, 30], maxZoom: 15});
}

async function refreshLocations() {
    try {
        const response = await fetch('track_vehicles.php?action=locations', {cache: 'no-store'}); const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Refresh failed');
        renderLocations(data.locations); document.getElementById('connectionStatus').textContent = 'Live'; document.getElementById('lastRefresh').textContent = new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    } catch (error) { document.getElementById('connectionStatus').textContent = 'Offline'; }
}

renderLocations(initialLocations, true); setInterval(refreshLocations, 10000);
</script>
</body>
</html>