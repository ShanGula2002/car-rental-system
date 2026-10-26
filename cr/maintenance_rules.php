<?php

function get_vehicle_maintenance_alert(PDO $pdo, int $vehicle_id): ?array
{
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

    $vehicle_stmt = $pdo->prepare('SELECT current_mileage FROM vehicles WHERE vehicle_id = :vehicle_id');
    $vehicle_stmt->execute([':vehicle_id' => $vehicle_id]);
    $current_mileage = $vehicle_stmt->fetchColumn();

    if ($current_mileage === false) {
        return null;
    }

    $latest_stmt = $pdo->prepare('
        SELECT service_type, service_date, present_mileage
        FROM maintenance
        WHERE vehicle_id = :vehicle_id
        ORDER BY service_date DESC, maintenance_id DESC
    ');
    $latest_stmt->execute([':vehicle_id' => $vehicle_id]);

    $latest_by_type = [];
    foreach ($latest_stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $service_type = trim((string) ($record['service_type'] ?? ''));
        if ($service_type !== '' && !isset($latest_by_type[$service_type])) {
            $latest_by_type[$service_type] = $record;
        }
    }

    $today = new DateTime('today');
    $current_mileage = max(0, (float) $current_mileage);
    $highest_alert = null;
    $priority_rank = ['overdue' => 0, 'upcoming' => 1, 'pending' => 2];

    foreach ($service_rules as $service_type => $rule) {
        $latest = $latest_by_type[$service_type] ?? null;
        if (!$latest) {
            $alert = [
                'status' => 'pending',
                'label' => 'Initial Check',
                'service_type' => $service_type,
                'message' => "{$service_type} requires an initial check before this vehicle is used."
            ];
        } else {
            $last_mileage = (float) ($latest['present_mileage'] ?? 0);
            $mileage_since = $last_mileage > 0 ? max(0, $current_mileage - $last_mileage) : 0;
            $service_date = $latest['service_date'] ?? null;
            $days_since = $service_date ? max(0, (int) $today->diff(new DateTime($service_date))->format('%r%a')) : 0;
            $mileage_ratio = $rule['interval_km'] > 0 ? $mileage_since / $rule['interval_km'] : 0;
            $days_ratio = $rule['interval_days'] > 0 ? $days_since / $rule['interval_days'] : 0;
            $usage_ratio = max($mileage_ratio, $days_ratio);

            if ($usage_ratio >= 1.0) {
                $description = trim((string) ($rule['description'] ?? ''));
                $alert = [
                    'status' => 'overdue',
                    'label' => 'Overdue',
                    'service_type' => $service_type,
                    'message' => $description !== ''
                        ? "{$service_type} – {$description}"
                        : "{$service_type} is overdue. This vehicle needs to be checked before starting the trip."
                ];
            } elseif ($usage_ratio >= 0.6) {
                $alert = [
                    'status' => 'upcoming',
                    'label' => 'Upcoming',
                    'service_type' => $service_type,
                    'message' => "{$service_type} is approaching its maintenance interval."
                ];
            } else {
                continue;
            }
        }

        if ($highest_alert === null || $priority_rank[$alert['status']] < $priority_rank[$highest_alert['status']]) {
            $highest_alert = $alert;
        }
    }

    return $highest_alert;
}
