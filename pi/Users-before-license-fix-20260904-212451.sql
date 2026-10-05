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
-- Table structure for table `Users`
--

DROP TABLE IF EXISTS `Users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `Users` (
  `uID` int(11) NOT NULL,
  `Access_Level` int(2) NOT NULL DEFAULT 1,
  `MyCall` varchar(20) NOT NULL,
  `CurrentIP` varchar(60) NOT NULL DEFAULT '0',
  `Active` varchar(1) NOT NULL DEFAULT '0',
  `SelectedRadio` int(2) NOT NULL DEFAULT 1,
  `rigctldPort` int(5) NOT NULL DEFAULT 4532,
  `rigDoPID` int(6) NOT NULL DEFAULT 0,
  `FirstName` varchar(20) DEFAULT NULL,
  `LastName` varchar(20) DEFAULT NULL,
  `Username` varchar(20) DEFAULT NULL,
  `Password` char(32) DEFAULT NULL,
  `qrzUser` varchar(20) DEFAULT NULL,
  `qrzPWD` varchar(30) DEFAULT NULL,
  `qrzKey` varchar(50) DEFAULT NULL,
  `QTH` varchar(60) DEFAULT NULL,
  `MyCountry` varchar(20) DEFAULT NULL,
  `MyState` varchar(20) DEFAULT NULL,
  `MyCounty` varchar(30) DEFAULT NULL,
  `MyCity` varchar(30) DEFAULT NULL,
  `MyZIP` varchar(8) DEFAULT NULL,
  `MyContinent` varchar(2) DEFAULT NULL,
  `My_Email` varchar(30) DEFAULT NULL,
  `My_Phone` varchar(20) DEFAULT NULL,
  `My_Latitude` varchar(20) DEFAULT NULL,
  `My_Longitude` varchar(20) DEFAULT NULL,
  `My_Grid` varchar(10) DEFAULT NULL,
  `My_Section` varchar(5) DEFAULT NULL,
  `Mobile_Lat` varchar(20) NOT NULL,
  `Mobile_Lon` varchar(20) NOT NULL,
  `Mobile_Grid` varchar(10) DEFAULT NULL,
  `Club` varchar(20) DEFAULT NULL,
  `My_LicenseClass` varchar(6) DEFAULT NULL,
  `My_Contest_Class` varchar(5) DEFAULT NULL,
  `Contest_ID` varchar(10) DEFAULT NULL,
  `LastVisit` int(11) NOT NULL DEFAULT 0,
  `Macros` varchar(2000) DEFAULT NULL,
  `Count` varchar(7) DEFAULT NULL,
  `LogFldigi` tinyint(4) NOT NULL DEFAULT 0,
  `LogWSJTX` tinyint(4) NOT NULL DEFAULT 0,
  `WSJTXPort` varchar(10) NOT NULL DEFAULT '2333',
  `Theme` varchar(2) NOT NULL DEFAULT '0',
  `DeadMan` int(4) NOT NULL DEFAULT 10,
  `Inactivity` varchar(10) NOT NULL DEFAULT '0',
  `BandEnable` varchar(36) NOT NULL DEFAULT '1,1,1,1,1,1,1,1,1,1,1,1,1,1,1',
  `ModeEnable` varchar(36) NOT NULL DEFAULT '1,1,1,1,1,1,1,1,1,1,1,1,1,1,1',
  `BusyBlock` int(1) NOT NULL DEFAULT 0,
  `SDRHost` varchar(200) NOT NULL DEFAULT 'http://rigpi5.local/sdr1',
  `SDRHostRemote` varchar(100) CHARACTER SET latin1 COLLATE latin1_spanish_ci NOT NULL,
  `SDRPort` int(5) NOT NULL DEFAULT 8001,
  `SDRPosition` varchar(10) NOT NULL DEFAULT 'none',
  `SDRPage` varchar(10) NOT NULL DEFAULT 'none',
  `PushoverToken` varchar(50) DEFAULT '',
  `PushoverUser` varchar(50) DEFAULT '',
  `PushoverNotify` varchar(10) DEFAULT 'none',
  `PushoverDelay` int(4) DEFAULT 60,
  `PushoverLast` int(11) DEFAULT 0,
  `hamqthUser` varchar(20) NOT NULL,
  `hamqthPWD` varchar(30) NOT NULL,
  `GoogleAPIKey` varchar(20) NOT NULL,
  UNIQUE KEY `ID` (`uID`),
  UNIQUE KEY `Username` (`Username`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `Users`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `Users` WRITE;
/*!40000 ALTER TABLE `Users` DISABLE KEYS */;
INSERT INTO `Users` VALUES
(1,1,'W6HN','','1',1,4532,0,'','','admin','','','','','','','','','','','','','','','','','','','','','','','','',1788570574,'','1',0,0,'2333','0',10,'0','1,1,1,1,1,1,1,1,1,1,1,1,1,1,1','1,1,1,1,1,1,1,1,1,1,1,1,1,1,1',0,'http://rigpi5.local/sdr1','',8001,'top','both','','','none',60,0,'','',''),
(2,3,'GUEST1','0','0',2,4532,0,'','','guest1','4180b5120ca2e09eaa3bd2ebf4b53667','','',NULL,'','','','','','','','','','','','',NULL,'','','',NULL,NULL,NULL,NULL,1785155692,NULL,NULL,0,0,'2333','0',10,'0','1,1,1,1,1,1,1,1,1,1,1,1,1,1,1','1,1,1,1,1,1,1,1,1,1,1,1,1,1,1',0,'http://rigpi5.local/sdr2','',8005,'top','tuner','','','none',60,0,'','',''),
(3,3,'GUEST2','0','1',3,4532,0,'','','guest2','82273dfbbc9cc64149d6e6d52d3104fa','','',NULL,'','','','','','','','','','','','',NULL,'','','',NULL,NULL,NULL,NULL,1777755425,NULL,NULL,0,0,'2333','0',10,'0','1,1,1,1,1,1,1,1,1,1,1,1,1,1,1','1,1,1,1,1,1,1,1,1,1,1,1,1,1,1',0,'http://rigpi5.local/sdr3','',8003,'top','tuner','','','none',60,0,'','',''),
(4,3,'GUEST3','0','0',4,4532,0,'','','guest3','88cf91a1aef212f3c2cd12406983427d','','',NULL,'','','','','','','','','','','','',NULL,'','','',NULL,NULL,NULL,NULL,1777643555,NULL,NULL,0,0,'2333','0',10,'0','1,1,1,1,1,1,1,1,1,1,1,1,1,1,1','1,1,1,1,1,1,1,1,1,1,1,1,1,1,1',0,'http://rigpi5.local/sdr4','',8004,'top','tuner','','','none',60,0,'','','');
/*!40000 ALTER TABLE `Users` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-09-04 21:24:51
