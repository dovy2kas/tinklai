-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: db
-- Generation Time: Nov 25, 2025 at 03:52 PM
-- Server version: 8.4.7
-- PHP Version: 8.3.27

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `tinklai`
--

-- --------------------------------------------------------

--
-- Table structure for table `Atsiliepimas`
--

CREATE TABLE `Atsiliepimas` (
  `id` int UNSIGNED NOT NULL,
  `rezervacija` int UNSIGNED NOT NULL,
  `autorius` int UNSIGNED NOT NULL,
  `reitingas` tinyint UNSIGNED NOT NULL,
  `komentaras` text,
  `sukurta` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ElektrikoProfilis`
--

CREATE TABLE `ElektrikoProfilis` (
  `id` int UNSIGNED NOT NULL,
  `statusas` enum('LAUKIANTIS','PATVIRTINTAS','ATMESTAS') NOT NULL DEFAULT 'LAUKIANTIS',
  `cv` text,
  `nuotraukos` json DEFAULT NULL,
  `savaites_grafikas` json DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `Naudotojas`
--

CREATE TABLE `Naudotojas` (
  `id` int UNSIGNED NOT NULL,
  `el_pastas` varchar(255) NOT NULL,
  `slaptazodis` varchar(255) NOT NULL,
  `vardas` varchar(100) NOT NULL,
  `pavarde` varchar(100) NOT NULL,
  `miestas` varchar(100) NOT NULL,
  `tel` varchar(40) NOT NULL,
  `role` enum('NAUDOTOJAS','ELEKTRIKAS','ADMIN') NOT NULL DEFAULT 'NAUDOTOJAS',
  `sukurta` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atnaujinta` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `Pasiula`
--

CREATE TABLE `Pasiula` (
  `elektriko_profilis` int UNSIGNED NOT NULL,
  `paslauga` int UNSIGNED NOT NULL,
  `kaina_bazine` decimal(10,2) NOT NULL,
  `tipine_trukme_min` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `Paslauga`
--

CREATE TABLE `Paslauga` (
  `id` int UNSIGNED NOT NULL,
  `pavadinimas` varchar(150) NOT NULL,
  `aprasas` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `Rezervacija`
--

CREATE TABLE `Rezervacija` (
  `id` int UNSIGNED NOT NULL,
  `naudotojas` int UNSIGNED NOT NULL,
  `elektriko_profilis` int UNSIGNED NOT NULL,
  `paslauga` int UNSIGNED NOT NULL,
  `pradzia` datetime NOT NULL,
  `pabaiga` datetime NOT NULL,
  `statusas` enum('LAUKIA','PATVIRTINTA','ATMESTA','IVYKDYTA') NOT NULL DEFAULT 'LAUKIA',
  `pastabos` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `Atsiliepimas`
--
ALTER TABLE `Atsiliepimas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_atsiliepimas_rez` (`rezervacija`),
  ADD KEY `ix_atsiliepimas_autorius` (`autorius`);

--
-- Indexes for table `ElektrikoProfilis`
--
ALTER TABLE `ElektrikoProfilis`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `Naudotojas`
--
ALTER TABLE `Naudotojas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_naudotojas_email` (`el_pastas`);

--
-- Indexes for table `Pasiula`
--
ALTER TABLE `Pasiula`
  ADD PRIMARY KEY (`elektriko_profilis`,`paslauga`),
  ADD KEY `ix_pasiula_paslauga` (`paslauga`);

--
-- Indexes for table `Paslauga`
--
ALTER TABLE `Paslauga`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_paslauga_pavadinimas` (`pavadinimas`);

--
-- Indexes for table `Rezervacija`
--
ALTER TABLE `Rezervacija`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_rez_naudotojas` (`naudotojas`),
  ADD KEY `ix_rez_elektrikas` (`elektriko_profilis`),
  ADD KEY `ix_rez_paslauga` (`paslauga`),
  ADD KEY `ix_rez_laikas` (`pradzia`,`pabaiga`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `Atsiliepimas`
--
ALTER TABLE `Atsiliepimas`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `Naudotojas`
--
ALTER TABLE `Naudotojas`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `Paslauga`
--
ALTER TABLE `Paslauga`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `Rezervacija`
--
ALTER TABLE `Rezervacija`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `Atsiliepimas`
--
ALTER TABLE `Atsiliepimas`
  ADD CONSTRAINT `fk_atsiliepimas_autorius` FOREIGN KEY (`autorius`) REFERENCES `Naudotojas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_atsiliepimas_rez` FOREIGN KEY (`rezervacija`) REFERENCES `Rezervacija` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `ElektrikoProfilis`
--
ALTER TABLE `ElektrikoProfilis`
  ADD CONSTRAINT `fk_elektrikoprofilis_user` FOREIGN KEY (`id`) REFERENCES `Naudotojas` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT;

--
-- Constraints for table `Pasiula`
--
ALTER TABLE `Pasiula`
  ADD CONSTRAINT `fk_pasiula_elektrikas` FOREIGN KEY (`elektriko_profilis`) REFERENCES `ElektrikoProfilis` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_pasiula_paslauga` FOREIGN KEY (`paslauga`) REFERENCES `Paslauga` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `Rezervacija`
--
ALTER TABLE `Rezervacija`
  ADD CONSTRAINT `fk_rez_elektrikas` FOREIGN KEY (`elektriko_profilis`) REFERENCES `ElektrikoProfilis` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_rez_naudotojas` FOREIGN KEY (`naudotojas`) REFERENCES `Naudotojas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_rez_paslauga` FOREIGN KEY (`paslauga`) REFERENCES `Paslauga` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
