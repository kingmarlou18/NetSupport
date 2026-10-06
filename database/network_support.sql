-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 29, 2026 at 02:46 AM
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
-- Database: `network_support`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `event_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(200) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `event_id`, `action`, `ip_address`, `created_at`) VALUES
(1, 1, NULL, 'Logged in', '::1', '2026-08-28 06:28:33'),
(2, 1, NULL, 'Logged out', '::1', '2026-08-28 06:28:42'),
(3, 2, NULL, 'Logged in', '::1', '2026-08-28 06:28:50'),
(4, 2, NULL, 'Logged out', '::1', '2026-08-28 06:28:53'),
(5, 1, NULL, 'Logged in', '::1', '2026-08-28 06:32:53'),
(6, 1, NULL, 'Logged out', '::1', '2026-08-28 06:32:56'),
(7, 1, NULL, 'Logged in', '::1', '2026-08-28 06:34:08'),
(8, 1, NULL, 'Logged out', '::1', '2026-08-28 06:34:19'),
(9, 1, NULL, 'Logged in', '192.168.68.97', '2026-08-28 06:34:31'),
(10, 1, NULL, 'Logged in', '::1', '2026-08-28 06:34:52'),
(11, 1, 1, 'Created event', '::1', '2026-08-28 06:39:10'),
(12, 1, 1, 'Assigned technician', '::1', '2026-08-28 06:39:30'),
(13, 1, NULL, 'Created user account: mpberonio', '::1', '2026-08-28 06:40:12'),
(14, 1, 1, 'Assigned technician', '::1', '2026-08-28 06:40:19'),
(15, 1, NULL, 'Logged out', '::1', '2026-08-28 06:40:21'),
(16, 5, NULL, 'Logged in', '::1', '2026-08-28 06:40:26'),
(17, 5, NULL, 'Logged out', '::1', '2026-08-28 06:41:42'),
(18, 1, NULL, 'Logged in', '::1', '2026-08-28 06:41:47'),
(19, 1, NULL, 'Logged out', '::1', '2026-08-28 06:58:34'),
(20, 1, NULL, 'Logged in', '::1', '2026-08-28 06:58:47'),
(21, 1, NULL, 'Logged out', '::1', '2026-08-28 07:02:23'),
(22, 5, NULL, 'Logged in', '::1', '2026-08-28 07:02:29'),
(23, 5, NULL, 'Logged out', '::1', '2026-08-28 07:02:54'),
(24, 1, NULL, 'Logged in', '::1', '2026-08-28 07:04:27'),
(25, 1, 1, 'Changed event status to New', '::1', '2026-08-28 07:11:28'),
(26, 1, NULL, 'Updated office: CCMDO', '::1', '2026-08-28 07:21:56'),
(27, 1, NULL, 'Updated office: CCMDO', '::1', '2026-08-28 07:25:46'),
(28, 1, 1, 'Updated event #1', '::1', '2026-08-28 07:26:41'),
(29, 1, 1, 'Updated event #1', '::1', '2026-08-28 07:59:25'),
(30, 1, NULL, 'Logged in', '::1', '2026-08-28 08:39:25'),
(31, 1, 2, 'Created event', '::1', '2026-08-28 08:41:04'),
(32, 1, 2, 'Assigned technician', '::1', '2026-08-28 08:41:43'),
(33, 1, NULL, 'Logged out', '::1', '2026-08-28 08:42:58'),
(34, 5, NULL, 'Logged in', '::1', '2026-08-28 08:43:04'),
(35, 5, 2, 'Updated assignment status to Accepted', '::1', '2026-08-28 08:44:26'),
(36, 5, 2, 'Updated assignment status to Completed', '::1', '2026-08-28 08:45:03'),
(37, 5, 2, 'Updated assignment status to Completed', '::1', '2026-08-28 08:45:21'),
(38, 5, 1, 'Updated assignment status to Completed', '::1', '2026-08-28 08:45:31'),
(39, 5, NULL, 'Logged out', '::1', '2026-08-28 08:47:29'),
(40, 1, NULL, 'Logged in', '::1', '2026-08-28 08:47:37'),
(41, 1, NULL, 'Updated office: BOSS', '::1', '2026-08-28 09:08:12'),
(42, 1, 2, 'Updated event #2', '::1', '2026-08-28 09:08:30'),
(43, 1, NULL, 'Logged in', '::1', '2026-08-29 00:45:00');

-- --------------------------------------------------------

--
-- Table structure for table `equipment`
--

CREATE TABLE `equipment` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(80) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `quantity_available` int(10) UNSIGNED DEFAULT 0,
  `quantity_assigned` int(10) UNSIGNED DEFAULT 0,
  `condition_status` varchar(50) DEFAULT 'Good',
  `storage_location` varchar(120) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `equipment`
--

INSERT INTO `equipment` (`id`, `name`, `category`, `serial_number`, `quantity_available`, `quantity_assigned`, `condition_status`, `storage_location`) VALUES
(1, 'Starlink Kit', 'Internet', NULL, 2, 0, 'Good', 'ICT Store'),
(2, 'Gigabit Router', 'Network', NULL, 6, 0, 'Good', 'ICT Store'),
(3, 'Access Point', 'Network', NULL, 12, 0, 'Good', 'ICT Store'),
(4, 'Ethernet Cable', 'Cable', NULL, 50, 0, 'Good', 'ICT Store'),
(5, 'UPS', 'Power', NULL, 4, 0, 'Good', 'ICT Store'),
(6, 'Starlink Kit', 'Internet', NULL, 2, 0, 'Good', 'ICT Store'),
(7, 'Gigabit Router', 'Network', NULL, 6, 0, 'Good', 'ICT Store'),
(8, 'Access Point', 'Network', NULL, 12, 0, 'Good', 'ICT Store'),
(9, 'Ethernet Cable', 'Cable', NULL, 50, 0, 'Good', 'ICT Store'),
(10, 'UPS', 'Power', NULL, 4, 0, 'Good', 'ICT Store'),
(11, 'Starlink Kit', 'Internet', NULL, 2, 1, 'Good', 'ICT Store'),
(12, 'Gigabit Router', 'Network', NULL, 6, 0, 'Good', 'ICT Store'),
(13, 'Access Point', 'Network', NULL, 12, 0, 'Good', 'ICT Store'),
(14, 'Ethernet Cable', 'Cable', NULL, 50, 0, 'Good', 'ICT Store'),
(15, 'UPS', 'Power', NULL, 4, 0, 'Good', 'ICT Store'),
(16, 'Starlink Kit', 'Internet', NULL, 2, 0, 'Good', 'ICT Store'),
(17, 'Gigabit Router', 'Network', NULL, 6, 0, 'Good', 'ICT Store'),
(18, 'Access Point', 'Network', NULL, 12, 0, 'Good', 'ICT Store'),
(19, 'Ethernet Cable', 'Cable', NULL, 50, 0, 'Good', 'ICT Store'),
(20, 'UPS', 'Power', NULL, 4, 0, 'Good', 'ICT Store');

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `office_id` int(10) UNSIGNED NOT NULL,
  `category` varchar(60) NOT NULL,
  `location` varchar(200) NOT NULL,
  `latitude` decimal(9,6) DEFAULT NULL,
  `longitude` decimal(9,6) DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `priority` enum('Low','Normal','High','Urgent') DEFAULT 'Normal',
  `participants` int(10) UNSIGNED DEFAULT 0,
  `bandwidth_mbps` int(10) UNSIGNED DEFAULT 0,
  `primary_connection` varchar(100) DEFAULT NULL,
  `backup_connection` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('New','Pending','Assigned','In Progress','Completed','Cancelled','Rescheduled') DEFAULT 'New',
  `travel_order_status` enum('Not Created','Ongoing','Completed') NOT NULL DEFAULT 'Not Created',
  `travel_order_no` varchar(40) DEFAULT NULL,
  `travel_order_created_by` int(10) UNSIGNED DEFAULT NULL,
  `travel_order_created_at` datetime DEFAULT NULL,
  `travel_order_completed_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`id`, `title`, `description`, `office_id`, `category`, `location`, `start_at`, `end_at`, `priority`, `participants`, `bandwidth_mbps`, `primary_connection`, `backup_connection`, `notes`, `status`, `created_by`, `created_at`) VALUES
(1, 'PH CARAVAN', 'Support Internet Connection', 4, 'Other', 'Brgy. Villasol, Bayawan City — Villasol Covered Court', '2026-09-11 08:00:00', '2026-09-11 17:00:00', 'Urgent', 50, 30, 'Starlink', 'Brgy Connection', 'Starlink setup', 'Completed', 1, '2026-08-28 06:39:10'),
(2, 'Boss', 'test', 2, 'Barangay Mobile Outreach (Go-to-Brgy)', 'Brgy. Poblacion, Bayawan City — Tinago Covered Court', '2026-10-26 08:00:00', '2026-10-26 16:40:00', 'Urgent', 50, 40, 'Starlink', 'Brgy Connection', 'test', 'Completed', 1, '2026-08-28 08:41:04');

-- --------------------------------------------------------

--
-- Table structure for table `event_assignments`
--

CREATE TABLE `event_assignments` (
  `id` int(10) UNSIGNED NOT NULL,
  `event_id` int(10) UNSIGNED NOT NULL,
  `technician_id` int(10) UNSIGNED NOT NULL,
  `is_team_lead` tinyint(1) NOT NULL DEFAULT 0,
  `assigned_by` int(10) UNSIGNED NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('New Assignment','Accepted','In Progress','Completed','Cancelled') DEFAULT 'New Assignment',
  `accepted_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completion_notes` text DEFAULT NULL,
  `problems` text DEFAULT NULL,
  `actions_taken` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `event_assignments`
--

INSERT INTO `event_assignments` (`id`, `event_id`, `technician_id`, `assigned_by`, `assigned_at`, `status`, `accepted_at`, `started_at`, `completed_at`, `completion_notes`, `problems`, `actions_taken`) VALUES
(1, 1, 2, 1, '2026-08-28 06:39:30', 'New Assignment', NULL, NULL, NULL, NULL, NULL, NULL),
(2, 1, 5, 1, '2026-08-28 06:40:19', 'Completed', NULL, NULL, '2026-08-28 10:45:31', '', '', ''),
(3, 2, 5, 1, '2026-08-28 08:41:43', 'Completed', '2026-08-28 10:44:26', NULL, '2026-08-28 10:45:03', 'tested', 'tested', 'tested');

-- --------------------------------------------------------

--
-- Table structure for table `event_attachments`
--

CREATE TABLE `event_attachments` (
  `id` int(10) UNSIGNED NOT NULL,
  `event_id` int(10) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_checklists`
--

CREATE TABLE `event_checklists` (
  `id` int(10) UNSIGNED NOT NULL,
  `assignment_id` int(10) UNSIGNED NOT NULL,
  `item_name` varchar(120) NOT NULL,
  `completed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `event_checklists`
--

INSERT INTO `event_checklists` (`id`, `assignment_id`, `item_name`, `completed`) VALUES
(1, 3, 'Site Inspected', 0),
(2, 3, 'Equipment Prepared', 0),
(3, 3, 'Starlink Tested', 1),
(4, 3, 'Router Configured', 0),
(5, 3, 'Internet Connection Tested', 1),
(6, 3, 'Backup Connection Prepared', 0),
(7, 3, 'Power Source Checked', 0),
(8, 3, 'Cables Prepared', 0),
(9, 2, 'Site Inspected', 0),
(10, 2, 'Equipment Prepared', 0),
(11, 2, 'Starlink Tested', 0),
(12, 2, 'Router Configured', 0),
(13, 2, 'Internet Connection Tested', 0),
(14, 2, 'Backup Connection Prepared', 0),
(15, 2, 'Power Source Checked', 0),
(16, 2, 'Cables Prepared', 0);

-- --------------------------------------------------------

--
-- Table structure for table `event_equipment`
--

CREATE TABLE `event_equipment` (
  `event_id` int(10) UNSIGNED NOT NULL,
  `equipment_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `event_equipment`
--

INSERT INTO `event_equipment` (`event_id`, `equipment_id`, `quantity`) VALUES
(2, 11, 1);

-- --------------------------------------------------------

--
-- Table structure for table `event_reports`
--

CREATE TABLE `event_reports` (
  `id` int(10) UNSIGNED NOT NULL,
  `assignment_id` int(10) UNSIGNED NOT NULL,
  `performance` varchar(100) DEFAULT NULL,
  `downtime_minutes` int(10) UNSIGNED DEFAULT 0,
  `recommendations` text DEFAULT NULL,
  `actual_equipment` text DEFAULT NULL,
  `internet_performance` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `event_reports`
--

INSERT INTO `event_reports` (`id`, `assignment_id`, `performance`, `downtime_minutes`, `recommendations`, `actual_equipment`, `internet_performance`) VALUES
(1, 3, 'Excellent', 0, '', '', ''),
(2, 3, 'Excellent', 0, '', '', ''),
(3, 2, 'Excellent', 0, '', '', '');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `event_id` int(10) UNSIGNED DEFAULT NULL,
  `message` varchar(300) NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `event_id`, `message`, `read_at`, `created_at`) VALUES
(1, 2, 1, 'You have been assigned to support a new event.', NULL, '2026-08-28 06:39:30'),
(2, 5, 1, 'You have been assigned to support a new event.', '2026-08-28 14:40:44', '2026-08-28 06:40:19'),
(3, 5, 2, 'You have been assigned to support a new network event.', '2026-08-28 16:43:16', '2026-08-28 08:41:43');

-- --------------------------------------------------------

--
-- Table structure for table `event_categories`
--

CREATE TABLE `event_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `event_categories`
--

INSERT INTO `event_categories` (`id`, `name`, `sort_order`, `created_at`) VALUES
(1, 'Barangay Mobile Outreach (Go-to-Brgy)', 10, CURRENT_TIMESTAMP()),
(2, 'Community Outreach Services', 20, CURRENT_TIMESTAMP()),
(3, 'Official Event', 30, CURRENT_TIMESTAMP()),
(4, 'Emergency Support', 40, CURRENT_TIMESTAMP()),
(5, 'Training', 50, CURRENT_TIMESTAMP()),
(6, 'Seminar', 60, CURRENT_TIMESTAMP()),
(7, 'Meeting', 70, CURRENT_TIMESTAMP()),
(8, 'Conference', 80, CURRENT_TIMESTAMP()),
(9, 'Workshop', 90, CURRENT_TIMESTAMP()),
(10, 'Other', 100, CURRENT_TIMESTAMP());

--
-- Indexes for table `event_categories`
--
ALTER TABLE `event_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- AUTO_INCREMENT for table `event_categories`
--
ALTER TABLE `event_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

-- --------------------------------------------------------

--
-- Table structure for table `offices`
--

CREATE TABLE `offices` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `department` varchar(120) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `offices`
--

INSERT INTO `offices` (`id`, `name`, `department`, `created_at`) VALUES
(1, 'National Office', 'ICT', '2026-08-28 06:25:46'),
(2, 'BOSS', 'Administration', '2026-08-28 06:25:46'),
(3, 'Regional Office South', 'Administration', '2026-08-28 06:25:46'),
(4, 'CCMDO', 'Management', '2026-08-28 06:58:37');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `name` varchar(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`) VALUES
(2, 'Administrator'),
(3, 'Network Technician'),
(1, 'Super Administrator'),
(4, 'CCS Staff');

-- --------------------------------------------------------

--
-- Table structure for table `technician_availability`
--

CREATE TABLE `technician_availability` (
  `technician_id` int(10) UNSIGNED NOT NULL,
  `is_team_lead` tinyint(1) NOT NULL DEFAULT 0,
  `availability` enum('Available','Busy','On Leave','Unavailable') DEFAULT 'Available',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `technician_availability`
--

INSERT INTO `technician_availability` (`technician_id`, `availability`, `updated_at`) VALUES
(2, 'Available', '2026-08-28 06:58:37'),
(5, 'On Leave', '2026-08-28 08:47:24');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `username` varchar(60) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone_number` varchar(30) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `office_id` int(10) UNSIGNED DEFAULT NULL,
  `position_title` varchar(120) DEFAULT NULL,
  `role_id` tinyint(3) UNSIGNED NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `username`, `email`, `password_hash`, `office_id`, `position_title`, `role_id`, `status`, `created_at`) VALUES
(1, 'System Administrator', 'admin', 'admin@example.local', '$2y$10$k13IdQiE1Bdh4A.fKguTB.H4S9UjA.sYpAZYTaktptIvKCtiSgPNu', 1, 'IT Manager', 1, 'Active', '2026-08-28 06:25:46'),
(2, 'Amina Technician', 'amina', 'amina@example.local', '$2y$10$k13IdQiE1Bdh4A.fKguTB.H4S9UjA.sYpAZYTaktptIvKCtiSgPNu', 1, 'Network Technician', 3, 'Active', '2026-08-28 06:25:46'),
(5, 'Marlou P. Berono', 'mpberonio', 'mpberonio@gmail.com', '$2y$10$ac92Q6hGXSDAEZOGeT/BjO4kruGFrjgf2ewXToTioYzguX7n8/kZ.', NULL, NULL, 3, 'Active', '2026-08-28 06:40:12');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `event_id` (`event_id`);

--
-- Indexes for table `equipment`
--
ALTER TABLE `equipment`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `start_at` (`start_at`),
  ADD KEY `status` (`status`),
  ADD KEY `office_id` (`office_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `travel_order_status` (`travel_order_status`),
  ADD KEY `travel_order_created_by` (`travel_order_created_by`);

--
-- Indexes for table `event_assignments`
--
ALTER TABLE `event_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `assigned_by` (`assigned_by`),
  ADD KEY `technician_id` (`technician_id`,`status`);

--
-- Indexes for table `event_attachments`
--
ALTER TABLE `event_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`);

--
-- Indexes for table `event_checklists`
--
ALTER TABLE `event_checklists`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`);

--
-- Indexes for table `event_equipment`
--
ALTER TABLE `event_equipment`
  ADD PRIMARY KEY (`event_id`,`equipment_id`),
  ADD KEY `equipment_id` (`equipment_id`);

--
-- Indexes for table `event_reports`
--
ALTER TABLE `event_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `event_id` (`event_id`);

--
-- Indexes for table `offices`
--
ALTER TABLE `offices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `technician_availability`
--
ALTER TABLE `technician_availability`
  ADD PRIMARY KEY (`technician_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `office_id` (`office_id`),
  ADD KEY `role_id` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `equipment`
--
ALTER TABLE `equipment`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `event_assignments`
--
ALTER TABLE `event_assignments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `event_attachments`
--
ALTER TABLE `event_attachments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event_checklists`
--
ALTER TABLE `event_checklists`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `event_reports`
--
ALTER TABLE `event_reports`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `offices`
--
ALTER TABLE `offices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `activity_logs_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `events_ibfk_1` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`),
  ADD CONSTRAINT `events_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `events_ibfk_3` FOREIGN KEY (`travel_order_created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `event_assignments`
--
ALTER TABLE `event_assignments`
  ADD CONSTRAINT `event_assignments_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `event_assignments_ibfk_2` FOREIGN KEY (`technician_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `event_assignments_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `event_attachments`
--
ALTER TABLE `event_attachments`
  ADD CONSTRAINT `event_attachments_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `event_checklists`
--
ALTER TABLE `event_checklists`
  ADD CONSTRAINT `event_checklists_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `event_assignments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `event_equipment`
--
ALTER TABLE `event_equipment`
  ADD CONSTRAINT `event_equipment_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `event_equipment_ibfk_2` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`);

--
-- Constraints for table `event_reports`
--
ALTER TABLE `event_reports`
  ADD CONSTRAINT `event_reports_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `event_assignments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `technician_availability`
--
ALTER TABLE `technician_availability`
  ADD CONSTRAINT `technician_availability_ibfk_1` FOREIGN KEY (`technician_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
