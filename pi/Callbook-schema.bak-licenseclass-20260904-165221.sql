/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.8.6-MariaDB, for debian-linux-gnu (aarch64)
--
-- Host: localhost    Database: station
-- ------------------------------------------------------
-- Server version	11.8.6-MariaDB-0+deb13u1 from Debian

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `Callbook`
--

DROP TABLE IF EXISTS `Callbook`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `Callbook` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `User` int(4) NOT NULL,
  `Callsign` varchar(16) DEFAULT NULL,
  `Aliases` varchar(30) DEFAULT NULL,
  `DXCC` int(11) DEFAULT NULL,
  `His_Grid` varchar(6) DEFAULT NULL,
  `His_Name` varchar(100) DEFAULT NULL,
  `His_Street` varchar(100) DEFAULT NULL,
  `His_City` varchar(100) DEFAULT NULL,
  `Note` varchar(1000) DEFAULT NULL,
  `His_State` varchar(2) DEFAULT NULL,
  `His_Latitude` varchar(20) DEFAULT '',
  `His_Longitude` varchar(20) DEFAULT '',
  `His_Distance_Mi` varchar(5) DEFAULT NULL,
  `His_Distance_KM` varchar(5) DEFAULT NULL,
  `His_Email` varchar(40) DEFAULT NULL,
  `His_URL` varchar(50) DEFAULT NULL,
  `His_County` varchar(20) DEFAULT NULL,
  `His_Section` varchar(50) DEFAULT NULL,
  `His_Country` varchar(30) DEFAULT NULL,
  `His_QTH` varchar(100) DEFAULT NULL,
  `Abbreviation` varchar(5) DEFAULT NULL,
  `His_Entity` varchar(30) DEFAULT NULL,
  `His_FIPS` varchar(6) DEFAULT '',
  `His_Continent` varchar(2) DEFAULT NULL,
  `His_Zip` varchar(20) DEFAULT NULL,
  `IOTA` varchar(6) DEFAULT NULL,
  `CQZone` varchar(40) DEFAULT NULL,
  `ITUZone` varchar(40) DEFAULT NULL,
  `WPX_Prefix` varchar(10) DEFAULT NULL,
  `Ten_Ten` varchar(10) DEFAULT NULL,
  `VE_Province` varchar(2) DEFAULT NULL,
  `His_Power` varchar(4) DEFAULT NULL,
  `Beam_Heading` varchar(3) DEFAULT NULL,
  `LoTW` varchar(1) DEFAULT NULL,
  `eQSL` varchar(1) DEFAULT NULL,
  `mQSL` varchar(1) DEFAULT NULL,
  `ClubLogQSL` int(1) DEFAULT 0,
  `QRZQSL` int(1) DEFAULT NULL,
  `Club` varchar(20) DEFAULT NULL,
  `My_Section` varchar(5) DEFAULT NULL,
  `My_Contest_Class` varchar(4) DEFAULT NULL,
  `Contest_ID` varchar(10) DEFAULT NULL,
  `LicenseClass` varchar(6) DEFAULT NULL,
  `ImageURL` varchar(100) DEFAULT NULL,
  `ImageH` varchar(6) DEFAULT NULL,
  `ImageW` varchar(6) DEFAULT NULL,
  `TimeZone` varchar(40) DEFAULT NULL,
  `DST` varchar(1) DEFAULT NULL,
  `GMTOffset` varchar(6) DEFAULT NULL,
  `QSLMgr` varchar(100) DEFAULT NULL,
  `Transmitter` varchar(20) DEFAULT '',
  `Receiver` varchar(20) DEFAULT '',
  `Amplifier` varchar(10) DEFAULT NULL,
  `TX_Power` varchar(3) DEFAULT '',
  `TX_Antenna` varchar(10) DEFAULT NULL,
  `RX_Antenna` varchar(10) DEFAULT NULL,
  `Sat_Name` varchar(10) DEFAULT NULL,
  `Sat_Mode` varchar(10) DEFAULT NULL,
  `Propagation_Mode` varchar(10) DEFAULT NULL,
  `His_Bio` text DEFAULT NULL,
  `qrzError` varchar(200) DEFAULT NULL,
  UNIQUE KEY `ID_2` (`ID`),
  KEY `ID` (`ID`)
) ENGINE=MyISAM AUTO_INCREMENT=21 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-09-04 16:52:21
