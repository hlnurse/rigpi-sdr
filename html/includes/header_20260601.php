<?php
$tUserName = $_SESSION["myUsername"];
$tCall = $_SESSION["myCall"];
$dRoot = "/var/www/html";
require_once "/var/www/html/classes/MysqliDb.php";
require "/var/www/html/programs/sqldata.php";
$db = new MysqliDb(
    "localhost",
    $sql_radio_username,
    $sql_radio_password,
    $sql_radio_database
);
$db->where("Username", $tUserName);
$row = $db->getOne("Users");
$level = "1";
// Only set SDR vars if not already set by the page (e.g. index.php sets them early)
if (!isset($sdrHost)) {
    $sdrHost = "http://" . gethostname() . ".local";
}
if (!isset($sdrPort)) {
    $sdrPort = 8001;
}
if (!isset($sdrPosition)) {
    $sdrPosition = "none";
}
if (!isset($sdrPage)) {
    $sdrPage = "none";
}
if ($row) {
    $level = $row["Access_Level"];
    if (!isset($sdrHost) || $sdrHost === "http://" . gethostname() . ".local") {
        $sdrHost = isset($row["SDRHost"])
            ? $row["SDRHost"]
            : "http://" . gethostname() . ".local";
    }
    if (!isset($sdrPort) || $sdrPort === 8001) {
        $sdrPort = isset($row["SDRPort"]) ? (int) $row["SDRPort"] : 8001;
    }
    if (!isset($sdrPosition)) {
        $sdrPosition = isset($row["SDRPosition"])
            ? $row["SDRPosition"]
            : "none";
    }
    if (!isset($sdrPage)) {
        $sdrPage = isset($row["SDRPage"]) ? $row["SDRPage"] : "none";
    }
}
?>
<nav class="navbar navbar-expand-lg navbar-light nav-pills sticky-top id="myTabs" style="width:101%; background-color: #e3f2fd;">
	<div class="container-fluid">
	<div class="padding2">
		<a target="_blank" rel="noopener noreferrer" id="rigpi" href="https://www.rigpi.com" title="Go to https://www.rigpi.com">
			<img src="./Images/RigPiW.png" alt="RigPi" title="Go to https://www.rigpi.com" style="width:30px;height:30px;">
	</a>
	</div>
	<a target="_blank" rel="noopener noreferrer" class="navbar-brand" href="https://www.rigpi.com" title="Go to https://www.rigpi.com" style="color:red;">RigPi</a>

	<button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
		<span class="navbar-toggler-icon"></span>
	</button>
	<div class="collapse navbar-collapse navbar-toggleable-lg" id="navbarSupportedContent">
		<ul class="navbar-nav mr-auto">
<li class="nav-item">
	<a class="nav-link" id="tunerB" onfocus="this.select();" href="/index.php"<span class="sr-only"><u>T</u>UNER</span></a>
</li>
<li class="nav-item">
	<?php if ($level < 4) {
     echo "<a class='nav-link' id='keyer' href='/keyer.php'><u>K</u>EYER</a>";
 } ?>
</li>
<li class="nav-item">
	<a class="nav-link" id="log" href="/log.php"<span class="sr-only"><u>L</u>OG</span></a>
</li>

<li class="nav-item">
	<a class="nav-link" id="spots" href="/spots.php"<span class="sr-only"><u>S</u>POTS</span></a>
</li>
<li class="nav-item">
	<a class="nav-link" id="calendar_main" href="/calendar_main.php"<span class="sr-only"><u>C</u>ALENDAR</span></a>
</li>
<li class="nav-item">
	<a class="nav-link" id="elmer" href="/elmer.php"<span class="sr-only">EL<u>M</u>ER</span></a></li>	<li class="nav-item">
<?php if (isset($level) && $level == 1) { ?>
<li class="nav-item">
	<a class="nav-link" id="sdr4" href="/index_4.php"<span class="sr-only">SD<u>R</u>4</span></a>
</li>
<?php } ?>
<li class="nav-item">
	<a class="nav-link" id="web" href="/web.php"<span class="sr-only"><u>W</u>EB</span></a>
</li>
<li class="nav-item dropdown">

	<?php if ($level < 3) {
     echo "<a class='nav-link menu-dropdown-toggle' data-toggle='dropdown' id='Preview' role='button' aria-haspopup='true' aria-expanded='false'>S<u>E</u>TTINGS</a>";
 } ?>
	<ul class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
	<?php if ($level <= 2) {
     echo "<li><a class='dropdown-item' href='/users.php'<span class='sr-only'>Accounts</span></a></li>";
 } ?>
	<li><a class="dropdown-item" href="/settings.php"<span class='sr-only'>Basic Radio</span></a></li>
	<li><a class="dropdown-item" href="/settingsAdvanced.php"<span class='sr-only'>Advanced Radio</span></a></li>
	<li><a class="dropdown-item" href="/rotorSettings.php"<span class='sr-only'>Rotor</span></a></li>
	<li><a class="dropdown-item" href="/keyerSettings.php"<span class='sr-only'>Keyer</span></a></li>
	<li><a class="dropdown-item" href="/logDesigner.php"<span class='sr-only'>Log Designer</span></a></li>
	<li><a class="dropdown-item" href="/spotsSettings.php"<span class='sr-only'>Spots</span></a></li>
	<li><a class="dropdown-item" href="/modeFilter.php"<span class='sr-only'>Mode Filter</span></a></li>
	<li><a class="dropdown-item" href="/bandFilter.php"<span class='sr-only'>Band Filter</span></a></li>
	<li><a class="dropdown-item" href="/calendar.php"<span class='sr-only'>Scheduler</span></a></li>
	<li><a class="dropdown-item" href="/sliderOverride.php"<span class='sr-only'>Slider Overrides</span></a></li>
	<li><a class="dropdown-item" href="/macros.php"<span class='sr-only'>Macros</span></a></li>

	<?php if ($level <= 2) {
     echo "<li><a class='dropdown-item' href='./system.php'" .
         "<span class='sr-only'>System</span></a></li>";
 } ?>
	</ul>
</li>
<li class="nav-item dropdown">
		<a class="nav-link menu-dropdown-toggle" data-toggle="dropdown" id="help" role="button" aria-haspopup="true" aria-expanded="false"><u>H</u>ELP</a>
	<ul class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
		<li><a class="dropdown-item" href="/help.php" >RigPi Help</a></li>
		<li><a class="dropdown-item" href="https://rigpi.groups.io" target="_blank">RigPi Forum</a></li>
		<li><a class="dropdown-item" href="https://rigpi.com" target="_blank">RigPi Home Page</a></li>
		<li><a class="dropdown-item" href="https://k1elsystems.com" target="_blank">K1EL Systems Home Page</a></li>
		<li><a class="dropdown-item" href="/license.php"<span class='sr-only'>RSS License</span></a></li>
		<li><a class="dropdown-item" href="/acknowledgments.php"<span class="sr-only">Legal Acknowledgments</span></a></li>
		<li><a class="dropdown-item" href="/about.php"<span class="sr-only">About</span></a></li>
	</ul>
</li>
</ul>
			<form class="form-inline my-2 my-lg-0">
	<input class="form-control mr-sm-2  text-uppercase" style="width:100px;" onfocus="this.select();" data-index ="1" id="searchText" type="text"  title="Show info for this call" placeholder="Search for Call" aria-label="SEARCH">
	<button class="btn btn-outline-success my-2 my-sm-0" id="searchButton"  data-index="2" title="Show call info" type="button">
		<i class='fas fa-search fa-fw fa-lg'></i>
	</button>
	&nbsp;
	<button class="btn btn-outline-normal my-2 my-sm-0" name='logout' title="Log Out from RigPi" id="logoutButton" type="button">
		<i class='fas fa-sign-out-alt fa-fw fa-lg'></i>
	</button>
</form>
	</div>
</nav>
<script>
function showSettings(){
		document.querySelectorAll('.nav-item').forEach(function(el){el.classList.remove('active');});
		var set=document.getElementById('Preview');
		set.click();
		setTimeout(function(){
			var first=document.querySelector('.dropdown-menu.show .dropdown-item');
			if(first) first.focus();
		},200);
	}

	function showHelp(){
		document.querySelectorAll('.nav-item').forEach(function(el){el.classList.remove('active');});
		var set=document.getElementById('help');
		set.click();
		setTimeout(function(){
			var first=document.querySelector('.dropdown-menu.show .dropdown-item');
			if(first) first.focus();
		},200);
	}

	function showTuner(){
		var set=document.getElementById('tunerB');
		set.click();
	}

	function showKeyer(){
		var set=document.getElementById('keyer');
		set.click();
	}

	function showLog(){
		var set=document.getElementById('log');
		set.click();
	}

	function showWeb(){
		var set=document.getElementById('web');
		set.click();
	}

	function showCalendar(){
		var set=document.getElementById('calendar_main');

		set.click();
	}

	function showSpots(){
		var set=document.getElementById('spots');
		set.click();
	}
	function showSdr4(){
		var set=document.getElementById('sdr4');
		if(set) set.click();
	}
	function showElmer(){
		var set=document.getElementById('elmer');
		set.click();
	}
	document.addEventListener('keydown', function(e) {
		if (!e.altKey || e.ctrlKey || e.metaKey) return;
		if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return;
		e.preventDefault();
		e.stopPropagation();
		const _code = e.code;
		switch(_code) {
			case 'KeyT': showTuner();    break;
			case 'KeyK': showKeyer();    break;
			case 'KeyL': showLog();      break;
			case 'KeyS': if(!window.location.pathname.includes('spots')) showSpots(); break;
			case 'KeyC': showCalendar(); break;
			case 'KeyM': showElmer();    break;
			case 'KeyE': showSettings(); break;
			case 'KeyH': showHelp();     break;
			case 'KeyR': showSdr4();   break;
			case 'KeyW': showWeb();      break;
		}
	}, true);
</script>