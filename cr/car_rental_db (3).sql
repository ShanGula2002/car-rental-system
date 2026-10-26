-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 27, 2026 at 03:20 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `car_rental_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `booking_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `pickup_date` datetime NOT NULL,
  `pickup_time` time DEFAULT '08:00:00',
  `return_date` datetime NOT NULL,
  `return_time` time DEFAULT '17:00:00',
  `pickup_latitude` decimal(10,8) DEFAULT NULL,
  `pickup_longitude` decimal(11,8) DEFAULT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `status` enum('pending','approved','ongoing','completed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`booking_id`, `user_id`, `vehicle_id`, `driver_id`, `pickup_date`, `pickup_time`, `return_date`, `return_time`, `pickup_latitude`, `pickup_longitude`, `total_price`, `status`, `created_at`) VALUES
(35, 6, 5, 5, '2026-08-14 12:00:00', '08:00:00', '2026-08-15 12:00:00', '17:00:00', 10.53017760, 122.83870750, 1000.00, 'completed', '2026-08-15 16:59:23'),
(36, 6, 5, NULL, '2026-08-19 12:00:00', '08:00:00', '2026-08-21 12:00:00', '17:00:00', 10.53167399, 122.84308076, 2000.00, 'cancelled', '2026-08-19 12:16:17'),
(37, 6, 6, 5, '2026-08-26 12:00:00', '08:00:00', '2026-08-28 12:00:00', '17:00:00', 10.53072466, 122.83916473, 2222222.00, 'completed', '2026-08-26 08:07:23'),
(38, 6, 1, 5, '2026-08-26 12:00:00', '08:00:00', '2026-08-28 12:00:00', '17:00:00', 10.53262332, 122.83839226, 5000.00, 'completed', '2026-08-26 08:37:47'),
(39, 6, 7, 5, '2026-08-26 12:00:00', '08:00:00', '2026-08-28 12:00:00', '17:00:00', 10.53085124, 122.83925056, 2000.00, 'approved', '2026-08-26 08:42:04');

-- --------------------------------------------------------

--
-- Table structure for table `driver_locations`
--

CREATE TABLE `driver_locations` (
  `location_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `start_latitude` decimal(10,8) DEFAULT NULL,
  `start_longitude` decimal(11,8) DEFAULT NULL,
  `current_latitude` decimal(10,8) NOT NULL,
  `current_longitude` decimal(11,8) NOT NULL,
  `end_latitude` decimal(10,8) DEFAULT NULL,
  `end_longitude` decimal(11,8) DEFAULT NULL,
  `total_mileage` decimal(10,2) DEFAULT 0.00,
  `status` enum('idle','en_route','arrived','completed') DEFAULT 'en_route',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `driver_locations`
--

INSERT INTO `driver_locations` (`location_id`, `booking_id`, `vehicle_id`, `driver_id`, `customer_id`, `start_latitude`, `start_longitude`, `current_latitude`, `current_longitude`, `end_latitude`, `end_longitude`, `total_mileage`, `status`, `updated_at`, `created_at`) VALUES
(23, 35, 5, 5, 6, 10.54372595, 122.84546722, 10.54332648, 122.84549980, 10.54332648, 122.84549980, 0.00, 'completed', '2026-08-16 14:42:10', '2026-08-15 17:00:18'),
(24, 37, 6, 5, 6, 0.00000000, 0.00000000, 10.53867174, 122.83537851, 10.53867174, 122.83537851, 5000.00, 'completed', '2026-08-26 08:09:21', '2026-08-26 08:08:39'),
(25, 38, 1, 5, 6, 0.00000000, 0.00000000, 10.53867174, 122.83537851, 10.53867174, 122.83537851, 5000.00, 'completed', '2026-08-26 08:39:52', '2026-08-26 08:39:08');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance`
--

CREATE TABLE `maintenance` (
  `maintenance_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `service_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `service_type` enum('Oil and Filter Change','Tire','Battery','Belts and Hoses','Brake Inspection','Spark Plugs','Transmission and Differential Fluids') DEFAULT NULL,
  `present_mileage` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `maintenance`
--

INSERT INTO `maintenance` (`maintenance_id`, `vehicle_id`, `description`, `cost`, `service_date`, `created_at`, `service_type`, `present_mileage`) VALUES
(42, 2, '', 0.00, '2026-08-16', '2026-08-16 15:19:21', 'Oil and Filter Change', 0),
(43, 2, '0', 0.00, '2026-08-16', '2026-08-16 15:19:24', 'Tire', 0),
(44, 2, '0', 0.00, '2026-08-16', '2026-08-16 15:19:27', 'Battery', 0),
(45, 2, '0', 0.00, '2026-08-16', '2026-08-16 15:19:30', 'Belts and Hoses', 0),
(46, 2, '0', 0.00, '2026-08-16', '2026-08-16 15:19:33', 'Brake Inspection', 0),
(47, 2, '0', 0.00, '2026-08-16', '2026-08-16 15:19:38', 'Spark Plugs', 0),
(48, 2, '0', 0.00, '2026-08-16', '2026-08-16 15:19:41', 'Transmission and Differential Fluids', 0),
(49, 4, '0', 0.00, '2026-08-16', '2026-08-16 15:19:44', 'Oil and Filter Change', 0),
(50, 4, '0', 0.00, '2026-08-16', '2026-08-16 15:19:46', 'Tire', 0),
(51, 4, '', 0.00, '2026-08-16', '2026-08-16 15:19:49', 'Battery', 0),
(52, 4, '', 0.00, '2026-08-16', '2026-08-16 15:19:51', 'Belts and Hoses', 0),
(53, 4, '', 0.00, '2026-08-16', '2026-08-16 15:19:53', 'Brake Inspection', 0),
(54, 4, '', 0.00, '2026-08-16', '2026-08-16 15:19:56', 'Spark Plugs', 0),
(55, 4, '', 0.00, '2026-08-16', '2026-08-16 15:19:58', 'Transmission and Differential Fluids', 0),
(56, 3, '', 0.00, '2026-08-16', '2026-08-16 15:20:01', 'Oil and Filter Change', 0),
(57, 3, '', 0.00, '2026-08-16', '2026-08-16 15:20:03', 'Tire', 0),
(58, 3, '', 0.00, '2026-08-16', '2026-08-16 15:20:05', 'Battery', 0),
(59, 3, '', 0.00, '2026-08-16', '2026-08-16 15:20:07', 'Belts and Hoses', 0),
(60, 3, '', 0.00, '2026-08-16', '2026-08-16 15:20:09', 'Brake Inspection', 0),
(61, 3, '', 0.00, '2026-08-16', '2026-08-16 15:20:12', 'Spark Plugs', 0),
(62, 3, '', 0.00, '2026-08-16', '2026-08-16 15:20:14', 'Transmission and Differential Fluids', 0),
(63, 1, '', 0.00, '2026-08-16', '2026-08-16 15:20:16', 'Oil and Filter Change', 0),
(64, 1, '', 0.00, '2026-08-16', '2026-08-16 15:20:18', 'Tire', 0),
(65, 1, '', 0.00, '2026-08-16', '2026-08-16 15:20:20', 'Battery', 0),
(66, 1, '', 0.00, '2026-08-16', '2026-08-16 15:20:22', 'Belts and Hoses', 0),
(67, 1, '', 0.00, '2026-08-16', '2026-08-16 15:20:25', 'Brake Inspection', 0),
(68, 1, '', 0.00, '2026-08-16', '2026-08-16 15:20:26', 'Spark Plugs', 0),
(69, 1, '', 0.00, '2026-08-16', '2026-08-16 15:20:28', 'Transmission and Differential Fluids', 0),
(70, 5, '', 0.00, '2026-08-16', '2026-08-16 15:20:31', 'Oil and Filter Change', 0),
(71, 5, '', 0.00, '2026-08-16', '2026-08-16 15:20:34', 'Tire', 0),
(72, 5, '', 0.00, '2026-08-16', '2026-08-16 15:20:36', 'Battery', 0),
(73, 5, '', 0.00, '2026-08-16', '2026-08-16 15:20:38', 'Belts and Hoses', 0),
(74, 5, '', 0.00, '2026-08-16', '2026-08-16 15:20:40', 'Brake Inspection', 0),
(75, 5, '', 0.00, '2026-08-16', '2026-08-16 15:20:42', 'Spark Plugs', 0),
(76, 5, '', 0.00, '2026-08-16', '2026-08-16 15:20:44', 'Transmission and Differential Fluids', 0),
(77, 6, '', 0.00, '2026-08-26', '2026-08-26 07:41:14', 'Oil and Filter Change', 0),
(78, 6, '', 0.00, '2026-08-26', '2026-08-26 07:41:25', 'Tire', 0),
(79, 6, '', 0.00, '2026-08-26', '2026-08-26 07:48:35', 'Battery', 0),
(80, 6, '', 0.00, '2026-08-26', '2026-08-26 07:48:39', 'Belts and Hoses', 0),
(81, 6, '', 0.00, '2026-08-26', '2026-08-26 07:48:43', 'Brake Inspection', 0),
(82, 6, '', 0.00, '2026-08-26', '2026-08-26 07:48:48', 'Spark Plugs', 0),
(83, 6, '', 0.00, '2026-08-26', '2026-08-26 07:48:52', 'Transmission and Differential Fluids', 0),
(84, 5, '', 5000.00, '2026-08-26', '2026-08-26 08:01:10', 'Oil and Filter Change', 5000);

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_data`
--

CREATE TABLE `maintenance_data` (
  `maintenance_data_id` int(11) NOT NULL,
  `maintenance_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `mileage_difference` int(11) NOT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `role` enum('admin','driver','customer') NOT NULL DEFAULT 'customer',
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `status` enum('pending','active','inactive','banned') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `role`, `first_name`, `last_name`, `email`, `password_hash`, `phone`, `status`, `created_at`, `updated_at`) VALUES
(2, 'customer', 'shan', 'gula', 'shangula@gmail.com', '$2y$10$VA2E8o4x4ifzZfXAqiDVkeSl0bGJMCxzJ8Y6ckv2vzpOreiDVEV7m', '09551244262', 'active', '2026-07-29 09:19:31', '2026-07-29 09:36:49'),
(4, 'admin', 'System', 'Admin', 'admin@gmail.com', '$2y$10$WBjl7Ph8tadxc1/.jst76eC5iZMjRSNkn85zbxrLrctMbazhTTiM.', '09123456789', 'active', '2026-07-29 09:30:29', '2026-07-29 09:30:29'),
(5, 'driver', 'sam', 'sam', 'sam@gmail.com', '$2y$10$Er71zbCFEQn0Kp8a/70Yv.eCBq1.ighDNecspX989yZ8EQsdI2vVS', '095323434', 'active', '2026-07-29 09:39:52', '2026-07-29 13:33:04'),
(6, 'customer', 'eman', 'emanuel', 'eman@gmail.com', '$2y$10$.nY9mj9huoLc20Q3TDFiCukyE7I10cVo8Wpwd5PmqZDMVRnOwbDs.', '094343234532', 'active', '2026-08-07 10:41:27', '2026-08-16 14:52:28');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `vehicle_id` int(11) NOT NULL,
  `brand` varchar(50) NOT NULL,
  `model_name` varchar(50) NOT NULL,
  `year` int(11) NOT NULL,
  `plate_number` varchar(20) NOT NULL,
  `category` enum('sedan','suv','van','luxury','truck') NOT NULL DEFAULT 'sedan',
  `capacity` int(11) NOT NULL DEFAULT 5,
  `transmission` varchar(20) NOT NULL DEFAULT 'Automatic',
  `daily_rate` decimal(10,2) NOT NULL,
  `status` enum('available','booked','maintenance','out_of_service') NOT NULL DEFAULT 'available',
  `current_mileage` int(11) NOT NULL DEFAULT 0,
  `image_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`vehicle_id`, `brand`, `model_name`, `year`, `plate_number`, `category`, `capacity`, `transmission`, `daily_rate`, `status`, `current_mileage`, `image_url`, `created_at`) VALUES
(1, 'Toyota', 'Vios', 0, 'ABC 143', 'sedan', 5, 'Automatic', 2500.00, 'available', 5000, 'uploads/vehicles/vehicle_1786081912_6a757278acb67.jpg', '2026-07-29 09:55:01'),
(2, 'Honda', 'Civic', 0, 'CBA 123', 'sedan', 5, 'Automatic', 3000.00, 'available', 20000, 'uploads/vehicles/vehicle_1786081899_6a75726b622fe.jpg', '2026-07-30 04:05:07'),
(3, 'Missubibi`', 'Ewan', 0, 'AGH 123', 'sedan', 8, 'Manual', 4000.00, 'available', 5000, 'uploads/vehicles/vehicle_1786081905_6a757271b0659.jpg', '2026-07-30 11:07:40'),
(4, 'Hummer', 'Hummer', 0, 'AFG 231', 'sedan', 5, 'Automatic', 5000.00, 'available', 5000, 'uploads/vehicles/vehicle_1786090109_6a75927dd28a8.png', '2026-08-07 08:08:29'),
(5, 'sample', 'sample', 0, 'sample', 'sedan', 5, 'Automatic', 1000.00, 'available', 5000, 'uploads/vehicles/vehicle_1786100044_6a75b94c8fa0d.jpg', '2026-08-07 10:54:04'),
(6, 'sample', 'sample', 0, '3213213', 'suv', 5, 'Automatic', 1111111.00, 'available', 0, 'uploads/vehicles/vehicle_1786912229_6a821de508446.png', '2026-08-16 20:30:29'),
(7, 'd1', 'd1', 0, 'd1', 'sedan', 5, 'Automatic', 1000.00, 'available', 10000, 'uploads/vehicles/default_vehicle.svg', '2026-08-26 08:02:27');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`booking_id`),
  ADD KEY `customer_id` (`user_id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `driver_id` (`driver_id`);

--
-- Indexes for table `driver_locations`
--
ALTER TABLE `driver_locations`
  ADD PRIMARY KEY (`location_id`),
  ADD KEY `fk_driver_loc_booking` (`booking_id`),
  ADD KEY `fk_driver_loc_vehicle` (`vehicle_id`),
  ADD KEY `fk_drivers_location_customer` (`customer_id`);

--
-- Indexes for table `maintenance`
--
ALTER TABLE `maintenance`
  ADD PRIMARY KEY (`maintenance_id`),
  ADD KEY `vehicle_id` (`vehicle_id`);

--
-- Indexes for table `maintenance_data`
--
ALTER TABLE `maintenance_data`
  ADD PRIMARY KEY (`maintenance_data_id`),
  ADD KEY `fk_maintenance_data_maintenance` (`maintenance_id`),
  ADD KEY `fk_maintenance_data_vehicle` (`vehicle_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`vehicle_id`),
  ADD UNIQUE KEY `license_plate` (`plate_number`),
  ADD UNIQUE KEY `plate_number` (`plate_number`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `booking_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `driver_locations`
--
ALTER TABLE `driver_locations`
  MODIFY `location_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `maintenance`
--
ALTER TABLE `maintenance`
  MODIFY `maintenance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=85;

--
-- AUTO_INCREMENT for table `maintenance_data`
--
ALTER TABLE `maintenance_data`
  MODIFY `maintenance_data_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `vehicle_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_3` FOREIGN KEY (`driver_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `driver_locations`
--
ALTER TABLE `driver_locations`
  ADD CONSTRAINT `fk_driver_loc_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_driver_loc_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_drivers_location_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `maintenance`
--
ALTER TABLE `maintenance`
  ADD CONSTRAINT `maintenance_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE;

--
-- Constraints for table `maintenance_data`
--
ALTER TABLE `maintenance_data`
  ADD CONSTRAINT `fk_maintenance_data_maintenance` FOREIGN KEY (`maintenance_id`) REFERENCES `maintenance` (`maintenance_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_maintenance_data_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
