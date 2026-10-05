<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
$tUserName = $_SESSION["myUsername"];
$tCall = $_SESSION["myCall"];
$dRoot = "/var/www/html";
require_once $dRoot . "/classes/Membership.php";
$membership = new Membership();
$membership->confirm_Member($tUserName);
require $dRoot . "/programs/GetMyRadioFunc.php";
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html lang="en">
<head>
	<meta charset="utf-8">

	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">

	<title><?php echo $tCall; ?> RigPi User Editor</title>
	<meta name="RigPi Account Editor" content="">
	<meta name="author" content="Howard Nurse, W6HN">

	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
	<!-- Bootstrap CSS -->
	<link rel="stylesheet" href="./Bootstrap/bootstrap.min.css">
	<script src="/Bootstrap/jquery.min.js" ></script>
	<script defer src="./awe/js/all.js" ></script>
	<link href="./awe/css/all.css" rel="stylesheet">
	<link href="./awe/css/fontawesome.css" rel="stylesheet">
	<link href="./awe/css/solid.css" rel="stylesheet">
	<!-- Replace favicon.ico & apple-touch-icon.png in the root of your domain and delete these references -->
	<link rel="shortcut icon" href="./favicon.ico">
	<link rel="apple-touch-icon" href="./favicon.ico">
	<style>
		.input-group .form-control,
		.input-group .input-group-text,
		.input-group .btn {
			min-height: 30px;
		}

		.input-group-btn .btn {
			height: 36px;
		}
		.service-message {
			display: block;
			margin-top: 8px;
			padding: 7px 9px;
			border: 1px solid rgba(255,255,255,.28);
			border-radius: 4px;
			background: #173b55;
			color: #fff !important;
			font-weight: 600;
			line-height: 1.35;
			white-space: normal;
			word-break: break-word;
		}
		.service-message:empty { display: none; }
	</style>
	<?php
 ini_set("error_reporting", E_ALL);
 ini_set("display_errors", 1);
 require $dRoot . "/includes/styles.php";
 require $dRoot . "/programs/sqldata.php";
 require $dRoot . "/programs/timeDateFunc.php";
 require $dRoot . "/programs/GetSettingsFunc.php";
 require_once $dRoot . "/classes/MysqliDb.php";
 $id = $_GET["id"];
 $tMyRadio = $id;
 $main1 = "0";
 $db = new MysqliDb(
     "localhost",
     $sql_radio_username,
     $sql_radio_password,
     $sql_radio_database
 );
 if ($id > 0) {
     $db->where("uID", $id);
     $row = $db->getOne("Users");
     if ($row) {
         $tID = $row["uID"];
         $call = $row["MyCall"];
         $rpiUser = $row["Username"];
         $mWSJTXPort = $row["WSJTXPort"];
         $access = $row["Access_Level"];
         $accessEdit = "";
         if ($tID == 1) {
             $accessEdit = "readonly";
         }
         $fName = $row["FirstName"];
         $lName = $row["LastName"];
         $password = "";
         $qrzPWD = $row["qrzPWD"];
         $hamqthUser = $row["hamqthUser"] ?? "";
         $hamqthPWD = $row["hamqthPWD"] ?? "";
         $qrzUser = $row["qrzUser"];
         $googleAPIKey = $row["GoogleAPIKey"] ?? "";
         $qth = $row["QTH"];
         $country = $row["MyCountry"];
         $licenseClass = $row["My_LicenseClass"] ?? "";
         $state = $row["MyState"];
         $county = $row["MyCounty"];
         $city = $row["MyCity"];
         $zip = $row["MyZIP"];
         $continent = $row["MyContinent"];
         $email = $row["My_Email"];
         $phone = $row["My_Phone"];
         $lat = $row["My_Latitude"];
         $lon = $row["My_Longitude"];
         $grid = $row["My_Grid"];
         $mlat = $row["Mobile_Lat"];
         $mlon = $row["Mobile_Lon"];
         $mgrid = $row["Mobile_Grid"];
         $logFldigi = $row["LogFldigi"];
         $logWSJTX = $row["LogWSJTX"];
         $theme = $row["Theme"];
         $deadman = $row["DeadMan"];
         $inactivity = $row["Inactivity"];
         $sdrHost = isset($row["SDRHost"])
             ? $row["SDRHost"]
             : gethostname() . ".local";
         $sdrHostRemote = isset($row["SDRHostRemote"])
             ? $row["SDRHostRemote"]
             : "";
         $pushoverToken = isset($row["PushoverToken"])
             ? $row["PushoverToken"]
             : "";
         $pushoverUser = isset($row["PushoverUser"])
             ? $row["PushoverUser"]
             : "";
         $pushoverNotify = isset($row["PushoverNotify"])
             ? $row["PushoverNotify"]
             : "none";
         $pushoverDelay = isset($row["PushoverDelay"])
             ? (int) $row["PushoverDelay"]
             : 60;

         $sdrPort = isset($row["SDRPort"]) ? (int) $row["SDRPort"] : 8001;
         $sdrPosition = isset($row["SDRPosition"])
             ? $row["SDRPosition"]
             : "none";
         $sdrPage = isset($row["SDRPage"]) ? $row["SDRPage"] : "none";
     }
 } else {
     $cols = ["uID"];
     $db->orderBy("uID", "asc");
     $users = $db->get("Users", null, $cols);
     $numID = $db->count;
     $lastID = 0;
     $realID = 0;
     $radioID = 0;
     //				if ($numID>0){ //this reuses empty users
     $radioID = $radioID + 1;
     foreach ($users as $value) {
         $t = $value["uID"] - $lastID;
         if ($t > 1) {
             break;
         } else {
             $lastID = $value["uID"];
         }
     }
     $realID = $lastID;
     //				}
     $tID = $realID + 1;
     $call = "";
     $rpiUser = "";
     $access = "1";
     $mWSJTXPort = 2333;
     $accessEdit = "";
     $fName = "";
     $lName = "";
     $password = "";
     $licenseClass = "";
     $qth = "";
     $country = "";
     $state = "";
     $county = "";
     $city = "";
     $email = "";
     $phone = "";
     $lat = "";
     $lon = "";
     $grid = "";
     $mlat = "";
     $mlon = "";
     $mgrid = "";
     $qrzPWD = "";
     $qrzUser = "";
     $hamqthUser = "";
     $hamqthPWD = "";
     $googleAPIKey = "";
     $zip = "";
     $continent = "";
     $logFldigi = "";
     $logWSJTX = "";
     $theme = "0";
     $deadman = 10;
     $inactivity = 0;
     $sdrHost = gethostname() . ".local";
     $sdrHostRemote = "";
     $pushoverToken = "";
     $pushoverUser = "";
     $pushoverNotify = "none";
     $pushoverDelay = 60;
     $sdrPort = 8001;
     $sdrPosition = "none";
     $sdrPage = "none";
 }
 ?>
	<script type="text/javascript">
		var tMyCall="<?php echo $tCall; ?>";
		var tCall=tMyCall
		var tUser='';
		var tUserName="<?php echo $tUserName; ?>";
		var tID=<?php echo "'" . $tID . "'"; ?>;
		var $el, tdx='';
		var oldCall=<?php echo "'" . $tCall . "'"; ?>;
		var tLogFldigi=<?php echo "'" . $logFldigi . "'"; ?>;
		var tLogWSJTX=<?php echo "'" . $logWSJTX . "'"; ?>;
		var tWSJTXPort = <?php echo "'" . $mWSJTXPort . "'"; ?>;
		var oldUser=tUserName;
		var mustReenter=false, tBandWidth;
		var tTheme=<?php echo "'" . $theme . "'"; ?>;
		var tStamp=Date.now(),tAData;
		  $(document).ready(function(){

				  $(document).on('click', '#searchButton', function()
				  {
					  tUserName="<?php echo $tUserName; ?>";
					  tdx=$('#searchText').val().toUpperCase();
					  if (tdx.length==0 || tdx.indexOf('*')>-1){
						  return;
					  }
					  $.post('/programs/GetUserField.php',{un: tUserName, field: 'uID'}, function(response) {
						  tUser=response;
						  $.post("/programs/GetCallbook.php", {call: tdx, what: 'QRZData', user: tUser, un: tUserName},function(response){
							  $(".modal-body").html(response);
						  $.post("/programs/GetCallbook.php", {call: tdx, what: 'QRZpix', user: tUser, un: tUserName},function(response){
						  $.post("/programs/SetSettings.php", {field: "waitReset", radio: tMyRadio, data: 1, table: "RadioInterface"}, function (response1){
							  var aPix=response.split('|');
							  var h=aPix[1];
							  var w=aPix[2];
							  if (h>0){
								if (aPix[3]==="flag"){
									$(".modal-pix").addClass("flag-pix").css({"width":"auto","max-width":"150px","margin":"10px auto","display":"block"});
								}else{
									var wP=(aPix[2]/400);
									var tW=w/wP;
									var tH=h/wP;
									$(".modal-pix").attr("height",tH+"px");
									$(".modal-pix").attr("width",tW+"px");
									$(".modal-pix").css({"display":"","margin":""});
								}
								$(".modal-pix").attr("src",aPix[0]);
							  }else{
								$(".modal-pix").attr("height","0px");
								$(".modal-pix").attr("width","0px");
								$(".modal-pix").attr("src","about:blank");
							  }
							  $('.modal-title').html(tdx);
							  $('#myModal').modal({show:true});
							  $.post("/programs/SetSettings.php", {field: "waitReset", radio: tMyRadio, data: 1, table: "RadioInterface"});
							  });
						  });
						  $.post("/programs/SetSettings.php", {field: "DX", radio: tMyRadio, data: tdx, table: "MySettings"});
					  })
				  });
			  });

			  var idV=<?php print isset($_GET["id"]) ? intval($_GET["id"]) : 0; ?>;
			$.post('/programs/GetSelectedRadio.php', {un:tUserName}, function(response)
			{
				tMyRadio=response;

$(document).on('click', '.myaccess', function() {
	if (tID>1){
	var text =$(this).text().substring(0, $(this).text().indexOf(":"));
	$('#accessValue').val(text);
};
});

$(document).keydown(function(e){
var t=e.key;
e.multiple
var w=e.which;
if (w==191)
{
	if (e.shiftKey){
		<?php require $dRoot . "/includes/shortcutsOther.php"; ?>
		$("#modalCO-body").html(tSh);
		$("#modalCO-title").html("Shortcut Keys");
		  $("#myModalCancelOnly").modal({show:true});
		  return false;
	}else{
		var tS1=document.activeElement.tagName;
		if (tS1=='INPUT'){
			return true;
		}else{
			$("#searchText").focus();
			return false;
		}
	}
};
if (w == 27) {
	document.getElementById('closeModal').click();
}
	if (e.altKey){
		switch(w){
		case 69: // e
			showSettings();
			e.preventDefault();
			break;
		case 72: // h
			showHelp();
			e.preventDefault();
			break;
		case 75: //k
			showKeyer();
			e.preventDefault();
			break;
		case 76: //l
			showLog();
			e.preventDefault();
			break;

		case 83: // s
			showSpots();
			e.preventDefault();
			break;
		case 84: // t
			showTuner();
			e.preventDefault();
			break;
		case 87: // w
			showWeb();
			e.preventDefault();
			break;
		};
	};
$.post('/programs/GetUserField.php',{un: tUserName, field: 'uID'}, function(response) {
	tUser=response;
})


});
function doSearch(){
	 tDX=$('#searchText').val().toUpperCase();
	 $('#searchText').val(tDX);
	searchCall=tDX;
	if (tDX.length==0){
		getLog(tDX,"1",true,filteredByNow);
		return;
	}
	if (!~tDX.indexOf("*")&&!~tDX.indexOf("=")){
		$.post("./programs/GetCallbook.php", {call: tDX, what: 'QRZData', user: tUser, un: tUserName},function(response){
			$(".modal-body").html(response);
			$.post("./programs/GetCallbook.php", {call: tDX, what: 'QRZpix', user: tUser, un: tUserName},function(response){
				var aPix=response.split('|');
				var h=aPix[1];
				var w=aPix[2];
				if (h>0){
					if (aPix[3]==="flag"){
						$(".modal-pix").addClass("flag-pix").css({"width":"auto","max-width":"150px","margin":"10px auto","display":"block"});
						$(".modal-pix").attr("src",aPix[0]);
					}else{
						var wP = aPix[2] / 400;
						var tW = w / wP;
						var tH = h / wP;
						$(".modal-pix").attr("height", tH + "px");
						$(".modal-pix").attr("width", tW + "px");
						$(".modal-pix").css({"display":"","margin":""});
						$(".modal-pix").attr("src", aPix[0]);
					}
				}else{
					$(".modal-pix").attr("height","0px");
					$(".modal-pix").attr("width","0px");
					$(".modal-pix").attr("src","about:blank");
				}
				$('.modal-title').html(tDX);
				  $('#myModal').modal({show:true});
				  $('#myModal').focus();
			  });
		});
		$.post("./programs/SetSettings.php", {field: "DX", radio: tMyRadio, data: tDX, table: "MySettings"});
	}
}
				 $("input").bind("keydown", function(event)
				{
					if (!event) return true;
					// track enter key
					var keycode = (event.keyCode ? event.keyCode : (event.which ? event.which : event.charCode));
					var f = document.getElementById('searchText');
					if (document.activeElement==f && keycode==13){
						doSearch();
					}
					if (keycode == 13) { // keycode for enter key
						event.preventDefault();
						var $this=$(event.target);
						var index = parseFloat($this.attr('data-index'));
						$('[data-index="' + (index+1).toString() + '"]').focus();
						if ($('#searchText').val()==''){
							return false;
						}
						var tDX=$('#searchText').val().toUpperCase();
						$('#searchText').val(tDX);
						document.getElementById('searchButton').click();
						$.post("./programs/SetSettings.php", {field: "DX", radio: tMyRadio, data: tDX, table: "MySettings"});
						return false;
					} else  {
						return true;
					}
				});

				$.post('/programs/GetSetting.php',{radio: tMyRadio, field: 'DX', table: 'MySettings'}, function(response)
				{
					$('#searchText').val(response);
				});
			})

		var tUpdate = setInterval(updateTimer,100);
					function updateTimer(){
						$.post('/programs/GetInterfaceIn.php',{radio: tMyRadio, un: tUserName, myCall:tMyCall }, function(response)
							{
							tAData=response.split('`');
							tTrx=tAData[9];
							tPTT=tAData[7];
							var tBW=tAData[17];
							if (tBW!==tBandWidth){
								tBandWidth=tBW;
							}

							tMode=tAData[3];//.substr(0,tAData[3].indexOf(" ")); mode is separate from bw
								if (tMode=="PKTUSB"){
								tMode="USB-D";
							}
							if (tMode=="PKTLSB"){
								tMode="LSB-D";
							}
							tMain=tAData[0];
						});
					}
			$(document).on('click', '#userCancel', function() {
				window.open("/users.php");
			});

			$(document).on('click', '.mycont', function() {
				var text = $(this).text();
				$("#continentVal").val(text);
			});

			$(document).on('click', '.mytheme', function() {
				var text = $(this).text();
				  $("#curTheme").val(text);
				  if (text=="Orange"){
					  tTheme=0;
				  }else if (text=="Night"){
					  tTheme=1;
				  }else if (text=="LCD"){
					  tTheme=2;
				}else if (text=="High Contrast"){
					  tTheme=3;
				}else if (text=="Green"){
					  tTheme=4;
				  }
			});

			var textT = '';
			if (tTheme==0){
				textT="Orange";
			}else if (tTheme==1){
				textT="Night";
			}else if (tTheme==2){
				textT="LCD";
			}else if (tTheme==3){
				textT="High Contrast";
			}else if (tTheme==4){
				textT="Green";
			}
			$("#curTheme").val(textT);

			$(document).on('click', '.mysdrpos', function() {
				var text = $(this).text().trim();
				$('#sdrPositionValue').val(text);
			});

			$(document).on('click', '.mysdrpage', function() {
				var text = $(this).text().trim();
				$('#sdrPageValue').val(text);
			});
function applyNotifyCheckboxes(val) {
						$('#notifyLogin').prop('checked', val=='login'||val=='both'||val=='both+spots'||val=='login+spots');
						$('#notifyLogout').prop('checked', val=='logout'||val=='both'||val=='both+spots'||val=='logout+spots');
						$('#notifySpots').prop('checked', val=='spots'||val=='both+spots'||val=='login+spots'||val=='logout+spots');
						updateNotifyDisplay();
						}
function updateNotifyDisplay() {
					var val = $('#pushoverNotifyValue').val();
					$('#notify-login').html((val=='login'||val=='both'||val=='both+spots'||val=='login+spots'?'&#x2611;':'&#x2610;') + ' Login');
					$('#notify-logout').html((val=='logout'||val=='both'||val=='both+spots'||val=='logout+spots'?'&#x2611;':'&#x2610;') + ' Logout');
					$('#notify-spots').html((val=='spots'||val=='both+spots'||val=='login+spots'||val=='logout+spots'?'&#x2611;':'&#x2610;') + ' Needed Spots');
			}
			updateNotifyDisplay();
			$(document).on('click', '.mypushovernotify', function(e) {
					e.stopPropagation();
					var id = $(this).attr('id');
					var val = $('#pushoverNotifyValue').val();
					var login = (val=='login'||val=='both'||val=='both+spots'||val=='login+spots');
					var logout = (val=='logout'||val=='both'||val=='both+spots'||val=='logout+spots');
					var spots = (val=='spots'||val=='both+spots'||val=='login+spots'||val=='logout+spots');
					if (id=='notify-login') login = !login;
					if (id=='notify-logout') logout = !logout;
					if (id=='notify-spots') spots = !spots;
					var newval = 'none';
					if (login && logout && spots) newval = 'both+spots';
					else if (login && logout) newval = 'both';
					else if (login && spots) newval = 'login+spots';
					else if (logout && spots) newval = 'logout+spots';
					else if (login) newval = 'login';
					else if (logout) newval = 'logout';
					else if (spots) newval = 'spots';
					$('#pushoverNotifyValue').val(newval);
					updateNotifyDisplay();
			});
			applyNotifyCheckboxes($('#pushoverNotifyValue').val());

			$(document).on('change', '.pushover-check', function() {
					var login = $('#notifyLogin').is(':checked');
					var logout = $('#notifyLogout').is(':checked');
					var spots = $('#notifySpots').is(':checked');
					var val = 'none';
					if (login && logout && spots) val = 'both+spots';
					else if (login && logout) val = 'both';
					else if (login && spots) val = 'login+spots';
					else if (logout && spots) val = 'logout+spots';
					else if (login) val = 'login';
					else if (logout) val = 'logout';
					else if (spots) val = 'spots';
					$('#pushoverNotifyValue').val(val);
			});
			$(document).on('click', '#fillUser', function(){
				if ($('#callValue').val()=='ADMIN'){
					alert("Please change ADMIN in the Call field to your call and try again.");
				}else{
					$.post("/programs/GetCallbook.php", {call:$('#callValue').val(), what: 'QRZData', user: tID,
						un: tUserName },function(response){
						$.post("./programs/GetCallbookField.php", {uID: tID, field: 'His_Name'},function(response){
								var fName=response.substr(0, response.indexOf(" "));
								$("#fnameValue").val(fName);
						});
						$.post("./programs/GetCallbookField.php",
						  {uID: tID, field: 'LicenseClass'},
						  function(response){
							response = (response || '').trim();
							if (response.length) {
							  $("#licenseClassValue").val(response);
							}
						  }
						);
						$.post("./programs/GetCallbookField.php", {uID: tID, field: 'His_State'},function(response){
								var fName=response;
								$("#stateValue").val(fName);
						});
						$.post("./programs/GetCallbookField.php", {uID: tID, field: 'His_Grid'},function(response){
								var fName=response;
								$("#gridValue").val(fName);
						});
						$.post("./programs/GetCallbookField.php", {uID: tID, field: 'His_Name'},function(response){
								var fName=response.substr(response.lastIndexOf(" ")+1);
								$("#lnameValue").val(fName);
						});
						$.post("./programs/GetCallbookField.php", {uID: tID, field: 'His_County'},function(response){
								var fName=response;
								$("#countyValue").val(fName);
						});
						$.post("./programs/GetCallbookField.php", {uID: tID, field: 'His_Street'},function(response){
								var fName=response;
								$("#qthValue").val(fName);
						});
						$.post("./programs/GetCallbookField.php", {uID: tID, field: 'His_City'},function(response){
								var fName=response;
								$("#cityValue").val(fName);
						});
						$.post("./programs/GetCallbookField.php", {uID: tID, field: 'His_Country'},function(response){
								var fName=response;
								$("#countryValue").val(fName);
						});
						$.post("./programs/GetCallbookField.php", {uID: tID, field: 'His_Zip'},function(response){
								var fName=response;
								$("#zipValue").val(fName);
						});
						$.post("./programs/GetCallbookField.php", {uID: tID, field: 'His_Continent'},function(response){
								var fName=response;
								$("#continentVal").val(fName);
						});
						$.post("./programs/GetCallbookField.php", {uID: tID, field: 'His_Email'},function(response){
								var fName=response;
								$("#emailValue").val(fName);
						});
						$.post("./programs/GetCallbookField.php", {uID: tID, field: 'His_Latitude'},function(response){
								var fName=response;
								$("#latValue").val(fName);
						});
						$.post("./programs/GetCallbookField.php", {uID: tID, field: 'His_Longitude'},function(response){
								var fName=response;
								$("#lonValue").val(fName);
						});
					});
				};
			});

			$(document).on('click', '#userSave', function() {
				if ($('#callValue').val().length==0){
					$("#modalA-body").html("Please enter a callsign.");
					$("#modalA-title").html("Account Editor");
					  $("#myModalAlert").modal({show:true});
					return;
				}
				if ($('#userValue').val().length==0){
					$("#modalA-body").html("Please enter a unique Username.");
					$("#modalA-title").html("Account Editor");
					  $("#myModalAlert").modal({show:true});
					return;
				}
				if ($('#userValue').val().indexOf(" ")>0){
					$("#modalA-body").html("Usernames must not contain a space.");
					$("#modalA-title").html("Account Editor");
					  $("#myModalAlert").modal({show:true});
					return;
				}
				if ($('#callValue').val().length==0 || $('#callValue').val().toUpperCase()=='ADMIN'){
					$("#modalA-body").html("Please enter a valid callsign in the Call field (it cannot be blank or 'ADMIN').");
					$("#modalA-title").html("Account Editor");
					  $("#myModalAlert").modal({show:true});
					return;
				}
				if ($('#callValue').val()!=oldCall || $('#userValue').val()!=oldUser){
					mustReenter=true;
				}else{
					mustReenter=false;
				}

				var tFl=document.getElementById("logFldigi").checked
				  if (tFl){
					  tFl=1;
				  }else{
					  tFl=0;
				  }

				  var tWS=document.getElementById("logWSJTX").checked
				  if (tWS){
					  tWS=1;
				  }else{
					  tWS=0;
				  }
				var tAccessLevel=$('#accessValue').val();
				var data = {
					'refID':idV,
					'ID':tID,
					'MyCall':$('#callValue').val().toUpperCase(),
					'Username':$('#userValue').val(),
					'Access_Level':tAccessLevel,
					'FirstName':$('#fnameValue').val(),
					'LastName':$('#lnameValue').val(),
					'Password':$('#passwdValue').val(),
					'qrzUser':$('#qrzUserValue').val(),
					'qrzPWD':$('#qrzPWDValue').val(),
					'hamqthUser':$('#hamqthUserValue').val(),
					'hamqthPWD':$('#hamqthPWDValue').val(),
					'MyContinent':$('#continentVal').val(),
					'MyCountry':$('#countryValue').val(),
					'My_LicenseClass':$('#licenseClassValue').val(),
					'MyState':$('#stateValue').val(),
					'GoogleAPIKey':$('#googleAPIKeyValue').val(),
					'QTH':$('#qthValue').val(),
					'MyCounty':$('#countyValue').val(),
					'MyCity':$('#cityValue').val(),
					'MyZIP':$('#zipValue').val(),
					'My_Email':$('#emailValue').val(),
					'My_Phone':$('#phoneValue').val(),
					'My_Latitude':$('#latValue').val(),
					'My_Longitude':$('#lonValue').val(),
					'My_Grid':$('#gridValue').val(),
					'Mobile_Lat':$('#mlatValue').val(),
					'Mobile_Lon':$('#mlonValue').val(),
					'Mobile_Grid':$('#mgridValue').val(),
					'LogFldigi':tFl,
					'LogWSJTX':tWS,
					'WSJTXPort':$('#mWSJTXPort').val(),
					'Theme':tTheme,
					'LastVisit':tStamp,
					'DeadMan':$('#deadmanValue').val(),
					'Inactivity':$('#inactivityValue').val(),
					'SDRHost':$('#sdrHostValue').val(),
									'SDRHostRemote':$('#sdrHostRemoteValue').val(),
					'SDRPort':$('#sdrPortValue').val(),
					'SDRPosition':$('#sdrPositionValue').val(),
					'SDRPage':$('#sdrPageValue').val(),
					'PushoverToken':$('#pushoverToken').val(),
					'PushoverUser':$('#pushoverUser').val(),
					'PushoverNotify':$('#pushoverNotifyValue').val(),
					'PushoverDelay':$('#pushoverDelay').val()
				};
				var acc = [];
				$.each(data, function(index, value) {
					acc.push(index + ': ' + value);
				});

				$.post('/programs/SetUsers.php',data, function(response) {
					$("#modalA-body").html(response);
					$("#modalA-title").html("Account Editor");
					  $("#myModalAlert").modal({show:true});
					  if (response.indexOf('unique')>0){
						  mustReenter=false;
					  }
				});

				  $( "#myModalAlert" ).on("hidden.bs.modal", function(e) {
					if (mustReenter==false){
						window.open("./users.php","_self");
					}else{
						  $.post('./login.php',{status:'loggedout'});
						  window.location.replace("/login.php");
					}
				});
			});



			if (tLogWSJTX!=1){
				document.getElementById("logWSJTX").checked=false;
			}else{
				document.getElementById("logWSJTX").checked=true;
			}
			if (tLogFldigi!=1){
				document.getElementById("logFldigi").checked=false;
			}else{
				document.getElementById("logFldigi").checked=true;
			}
		})
		function updateFooter() {
		if (tAData.indexOf("NG")==-1) {

		  var tBW=tAData[17];
			var tRadioUpdate = tAData[8];
			if (tRadioUpdate.length != 0) {
			  $("#modalA-body").html(tRadioUpdate);
			  $(".modalA-title").html("RigPi Report");
			  $("#myModalAlert").modal({ show: true });
			  $.post("/programs/SetSettings.php", {
				field: "RadioData",
				radio: tMyRadio,
				data: "",
				table: "RadioInterface",
			  });
			}
		  }
		  if (!$.isNumeric(tAData[0])) {
			tAData[0] = "00000000";
			tAData[3] = "";
			tAData[2] = "00000000";
		  }
		  var cFreq2m = ("0000000000" + tAData[0]).slice(-10);
		  var tF = addPeriods(cFreq2m);
		  var tSplit = tAData[1];
		  tSplitOn = tSplit;
		  var cFreq2s = ("0000000000" + tAData[2]).slice(-10);
		  var tFs = addPeriods(cFreq2s);
		  $("#fPanel4").text("User: " + tCall + " (" + (typeof tIsGuest !== "undefined" && tIsGuest ? "guest/"+tUserName : tUserName) + ")");
		  if (tAData[0] == "00000000") {
			$("#fPanel1").html("&nbsp;No Radio");
			$("#fPanel2").text("");
			$("#fPanel3").text("");
			$("#fPanel1").attr("style", "background-color:red");
		  } else {
			tF = tF.trim();
			tFs = tFs.trim();
			if (tF.length < 9) {
			  tF = tF.substr(1);
			  tFs = tFs.substr(1);
			  if (tF.substr(0, 1) == "0") {
				tF = tF.substr(1);
			  }
			  if (tFs.substr(0, 1) == "0") {
				tFs = tFs.substr(1);
			  }
			  $("#fPanel1").text("Main: " + tF + " kHz");
			  if (tSplitOn == 1) {
				$("#fPanel2").text("Sub: " + tFs + " kHz");
			  } else {
				$("#fPanel2").text("");
			  }
			} else {
			  $("#fPanel1").text("Main: " + tF + " MHz");
			  if (tSplitOn == 1) {
				$("#fPanel2").text("Sub: " + tFs + " MHz");
			  } else {
				$("#fPanel2").text("");
			  }
			}
			var tM = tAData[3];
			if (tM == "PKTUSB") {
			  tM = "USB-D";
			}
			if (tM=="PKTLSB"){
			  tM="LSB-D";
			}
			$("#fPanel3").text("Mode: " + tM + " - BW: "+tBW);
			$("#fPanel1").attr("style", "background-color:black");
		  }
		 }
		function addPeriods(nStr) {
		  nStr += "";
		  x = nStr.split(".");
		  x1 = x[0];
		  x2 = x.length > 1 ? "." + x[1] : "";
		  var rgx = /(\d+)(\d{3})/;
		  while (rgx.test(x1)) {
			x1 = x1.replace(rgx, "$1" + "." + "$2");
		  }
		  var newF = x1 + x2;
		  if (newF.length > 12) {
			newF = newF.replace(".", "");
		  }
		  var tF = newF;
		  var tFS = "";
		  if (newF != "0000.000.000") {
			while (tF.charAt(0) == "0") {
			  tF = tF.substring(1);
			  tFS = tFS + " ";
			  newF = tFS + tF;
			}
		  }
		  return newF;
		}

		function updateFreq(which)
		{
			if (tDisconnected==1){
				showConnectAlert();
				return false;
			}
			tButtonWait=1;
			var num=which;
			if (tSplitOn==0){
				switch (tLine1){
					case ld1:
						tMain=tMain.substring(0, tMain.length-1)+ num ;
						break;
					case ld2:
						tMain=tMain.substring(0, tMain.length-2)+ num + tMain.substring(tMain.length-1);
						doDigit(1,1);
						break;
					case ld3:
						tMain=tMain.substring(0, tMain.length-3)+ num + tMain.substring(tMain.length-2);
						doDigit(10,1);
						break;
					case ld4:
						tMain=tMain.substring(0, tMain.length-4)+ num + tMain.substring(tMain.length-3);
						doDigit(100,1);
						break;
					case ld5:
						tMain=tMain.substring(0, tMain.length-5)+ num + tMain.substring(tMain.length-4);
						doDigit(1000,1);
						break;
					case ld6:
						tMain=tMain.substring(0, tMain.length-6)+ num + tMain.substring(tMain.length-5);
						doDigit(10000,1);
						break;
					case ld7:
						tMain=tMain.substring(0, tMain.length-7)+ num + tMain.substring(tMain.length-6);
						doDigit(100000,1);
						break;
					case ld8:
						tMain=tMain.substring(0, tMain.length-8)+ num + tMain.substring(tMain.length-7);
						doDigit(1000000,1);
						break;
					case ld9:
						doDigit(10000000,1);
						tMain=tMain.substring(0, tMain.length-9)+ num + tMain.substring(tMain.length-8);
						break;
					case ld10:
						doDigit(100000000,1);
						tMain=tMain.substring(0, tMain.length-10)+ num + tMain.substring(tMain.length-9);
						break;

				}
				var cFreq2m=("0000000000" + tMain).slice(-10);
				var tMain1=addPeriods(cFreq2m);
				var tF=tMain;
				var cMain=ppanel.getByName("Main");
				cMain.setText(tMain1);
				cMain.setNeedRepaint(true);
				cMain.refreshElement();
				$.post("/programs/SetSettings.php", {field: "MainOut", radio: tMyRadio, data: tMain, table: "RadioInterface"}, function(response){
						tButtonWait=0;
					}
				);
			}else{
				switch (stLine1){
					case sld1:
						tSub=tSub.substring(0, tSub.length-1)+num;
						break;
					case sld2:
						tSub=tSub.substring(0, tSub.length-2) +num +  tSub.substring(tSub.length-1);
						doSubDigit(1,1);
						break;
					case sld3:
						tSub=tSub.substring(0, tSub.length-3) +num +  tSub.substring(tSub.length-2);
						doSubDigit(10,1);
						break;
					case sld4:
						tSub=tSub.substring(0, tSub.length-4) +num +  tSub.substring(tSub.length-3);
						doSubDigit(100,1);
						break;
					case sld5:
						tSub=tSub.substring(0, tSub.length-5) +num +  tSub.substring(tSub.length-4);
						doSubDigit(1000,1);
						break;
					case sld6:
						tSub=tSub.substring(0, tSub.length-6) +num +  tSub.substring(tSub.length-5);
						doSubDigit(10000,1);
						break;
					case sld7:
						tSub=tSub.substring(0, tSub.length-7) +num +  tSub.substring(tSub.length-6);
						doSubDigit(100000,1);
						break;
					case sld8:
						tSub=tSub.substring(0, tSub.length-8) +num +  tSub.substring(tSub.length-7);
						doSubDigit(1000000,1);
						break;
					case sld9:
						doSubDigit(10000000,1);
						tSub=tSub.substring(0, tSub.length-9) +num +  tSub.substring(tSub.length-8);
						break;
					case sld10:
					doSubDigit(100000000,1);
					tSub=tSub.substring(0, tSub.length-9) +num +  tSub.substring(tSub.length-9);
					break;

				}
				var cFreq2s=("0000000000" + tSub).slice(-10);
				var tSub1=addPeriods(cFreq2s);
				var cSub=ppanel.getByName("Sub");
				cSub.setText(tSub1);
				cSub.setNeedRepaint(true);
				cSub.refreshElement();
				$.post("/programs/SetSettings.php", {field: "SubOut", radio: tMyRadio, data: tSub, table: "RadioInterface"}, function(response){
						tButtonWait=0;
					}
				);

			}
			waitRefresh=4;

		}
		$.getScript("/js/modalLoad.js");

				$(document).on('click', '#logoutButton', function()
				{
					openWindowWithPost("/login.php", {
						status: "loggedout",
						username: ''});
				});

				function openWindowWithPost(url, data) {
					var form = document.createElement("form");
					form.target = "_self";
					form.method = "POST";
					form.action = url;
					form.style.display = "none";

					for (var key in data) {
						var input = document.createElement("input");
						input.type = "hidden";
						input.name = key;
						input.value = data[key];
						form.appendChild(input);
					}

					document.body.appendChild(form);
					window.open("/login.php","_self");
					form.submit();
				};

				function updateFreq(which)
				{
					if (tDisconnected==1){
						showConnectAlert();
						return false;
					}
					tButtonWait=1;
					var num=which;
					if (tSplitOn==0){
						switch (tLine1){
							case ld1:
								tMain=tMain.substring(0, tMain.length-1)+ num ;
								break;
							case ld2:
								tMain=tMain.substring(0, tMain.length-2)+ num + tMain.substring(tMain.length-1);
								doDigit(1,1);
								break;
							case ld3:
								tMain=tMain.substring(0, tMain.length-3)+ num + tMain.substring(tMain.length-2);
								doDigit(10,1);
								break;
							case ld4:
								tMain=tMain.substring(0, tMain.length-4)+ num + tMain.substring(tMain.length-3);
								doDigit(100,1);
								break;
							case ld5:
								tMain=tMain.substring(0, tMain.length-5)+ num + tMain.substring(tMain.length-4);
								doDigit(1000,1);
								break;
							case ld6:
								tMain=tMain.substring(0, tMain.length-6)+ num + tMain.substring(tMain.length-5);
								doDigit(10000,1);
								break;
							case ld7:
								tMain=tMain.substring(0, tMain.length-7)+ num + tMain.substring(tMain.length-6);
								doDigit(100000,1);
								break;
							case ld8:
								tMain=tMain.substring(0, tMain.length-8)+ num + tMain.substring(tMain.length-7);
								doDigit(1000000,1);
								break;
							case ld9:
								doDigit(10000000,1);
								tMain=tMain.substring(0, tMain.length-9)+ num + tMain.substring(tMain.length-8);
								break;
							case ld10:
								doDigit(100000000,1);
								tMain=tMain.substring(0, tMain.length-10)+ num + tMain.substring(tMain.length-9);
								break;

						}
						var cFreq2m=("0000000000" + tMain).slice(-10);
						var tMain1=addPeriods(cFreq2m);
						var tF=tMain;
						var cMain=ppanel.getByName("Main");
						cMain.setText(tMain1);
						cMain.setNeedRepaint(true);
						cMain.refreshElement();
						$.post("/programs/SetSettings.php", {field: "MainOut", radio: tMyRadio, data: tMain, table: "RadioInterface"}, function(response){
								tButtonWait=0;
							}
						);
					}else{
						switch (stLine1){
							case sld1:
								tSub=tSub.substring(0, tSub.length-1)+num;
								break;
							case sld2:
								tSub=tSub.substring(0, tSub.length-2) +num +  tSub.substring(tSub.length-1);
								doSubDigit(1,1);
								break;
							case sld3:
								tSub=tSub.substring(0, tSub.length-3) +num +  tSub.substring(tSub.length-2);
								doSubDigit(10,1);
								break;
							case sld4:
								tSub=tSub.substring(0, tSub.length-4) +num +  tSub.substring(tSub.length-3);
								doSubDigit(100,1);
								break;
							case sld5:
								tSub=tSub.substring(0, tSub.length-5) +num +  tSub.substring(tSub.length-4);
								doSubDigit(1000,1);
								break;
							case sld6:
								tSub=tSub.substring(0, tSub.length-6) +num +  tSub.substring(tSub.length-5);
								doSubDigit(10000,1);
								break;
							case sld7:
								tSub=tSub.substring(0, tSub.length-7) +num +  tSub.substring(tSub.length-6);
								doSubDigit(100000,1);
								break;
							case sld8:
								tSub=tSub.substring(0, tSub.length-8) +num +  tSub.substring(tSub.length-7);
								doSubDigit(1000000,1);
								break;
							case sld9:
								doSubDigit(10000000,1);
								tSub=tSub.substring(0, tSub.length-9) +num +  tSub.substring(tSub.length-8);
								break;
							case sld10:
							doSubDigit(100000000,1);
							tSub=tSub.substring(0, tSub.length-9) +num +  tSub.substring(tSub.length-9);
							break;

						}
						var cFreq2s=("0000000000" + tSub).slice(-10);
						var tSub1=addPeriods(cFreq2s);
						var cSub=ppanel.getByName("Sub");
						cSub.setText(tSub1);
						cSub.setNeedRepaint(true);
						cSub.refreshElement();
						$.post("/programs/SetSettings.php", {field: "SubOut", radio: tMyRadio, data: tSub, table: "RadioInterface"}, function(response){
								tButtonWait=0;
							}
						);

					}
					waitRefresh=4;

				}

			var tUpdate = setInterval(updateTimer,1000);
			function updateTimer(){
			   $.post('/programs/GetInterfaceIn.php',{radio: tMyRadio, un: tUserName, myCall:<?php echo "'" .
          $tCall .
          "'"; ?>}, function(response)
				{
					updateFooter();
				});
				var now = new Date();
				var now_hours=now.getUTCHours();
				now_hours=("00" + now_hours).slice(-2);
				var now_minutes=now.getUTCMinutes();
				now_minutes=("00" + now_minutes).slice(-2);
				$("#fPanel5").text(now_hours+":"+now_minutes+'z');
			  }

	</script>
</head>

<body class="body-black-scroll" >
	<?php require $dRoot . "/includes/header.php"; ?>
	<div class="container-fluid">
		<div class="row"  style="margin-bottom:10px;">
			<div class="col-12  col-md-4 btn-padding">
			</div>
			<div class="col-6 col-md-4 text-center">
				<div class="label label-success text-white pageLabel" style="margin-top:10px;">Account Editor (User: <?php echo $tUserName; ?>)</div>
			</div>
			<div class="col-6 col-md-4 btn-padding">
				<button class='btn btn-color' id="userCancel" type='button'>
					<i class="fas fa-ban fa-lg"></i>
				</button>
				<button class='btn btn-color' id="userSave" type='button'>
					<i class="fas fa-cloud-upload-alt fa-lg"></i>
				</button>
			</div>
		</div>
		<hr>
		<div class="row">
			<div class="col-md-4 text-spacer">
						<div class="input-group">
							<div class="input-group-prepend">
								<span class="input-group-text">Access</span>
							</div>
							<input type="text" class="form-control disable-text" value="<?php echo htmlentities(
           $access
       ); ?>" <?php echo htmlentities(
    $accessEdit
); ?> onfocus="this.select();" data-index="3" id="accessValue" aria-lable="time" placeholder="" aria-describedby="access-addon">

							<span class="input-group-btn">
								<div class="dropdown">
									<button class="btn btn-primary dropdown-toggle" data-index="8" id="accessSel" data-size="3" type="button" title="Access List" data-toggle="dropdown"><i class="fas fa-list-alt fa-lg"></i>
									</button>
									<ul class="dropdown-menu dropdown-menu-right menu-scroll" id="contList">
										<div class='myaccess' id='1'><li><a class='dropdown-item' href='#'>1: Admin</a></li></div>
									<div class='myaccess' id='2'><li><a class='dropdown-item' href='#'>2: No Account or System Settings</a></li></div>
									<div class='myaccess' id='3'><li><a class='dropdown-item' href='#'>3: No Settings</a></li></div>
									<div class='myaccess' id='4'><li><a class='dropdown-item' href='#'>4: No Transmit</a></li></div>
									<div class='myaccess' id='5'><li><a class='dropdown-item' href='#'>10: PTT Only</a></li></div>
									 </ul>
								</div>
							</span>
						</div>
					</div>

			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text input-sm" >Call</span>
					</div>
					<input type="text" class="form-control text-uppercase"  value="<?php echo htmlentities(
         $call
     ); ?>" onfocus="this.select();" data-index="4"  id="callValue" placeholder="Enter user's callsign" title="Callsign for this account, need not be unique" aria-lable="call" aria-describedby="call-addon">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">Username</span>
					</div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $rpiUser
     ); ?>" onfocus="this.select();" data-index="5" id="userValue" aria-lable="user"  placeholder="Enter username" title="Unique username for this account" aria-describedby="user-addon">
				</div>
			</div>
		</div>

		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">RigPi PWD</span>
					</div>
					<input type="password" class="form-control" value="<?php echo htmlentities(
         $password
     ); ?>" onfocus="this.select();" data-index="6" id="passwdValue"  placeholder="Enter new password" title="Leave blank so no password required, not blank when open to Internet" aria-lable="time" aria-describedby="time-addon">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">Deadman</span>
					</div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $deadman
     ); ?>" onfocus="this.select();" data-index="7" id="deadmanValue"  placeholder="Enter transmit max mins" title="Use 0 for no limit, or number of minutes allowed for transmit" aria-lable="deadman" aria-describedby="deadman-addon">
				</div>
			</div>
			<div>
			</div>
			<div class="col-md-4 text-spacer">
						<div class="input-group">
							<div class="input-group-prepend">
								<span class="input-group-text">Inactivity</span>
							</div>
							<input type="text" class="form-control" value="<?php echo htmlentities(
           $inactivity
       ); ?>" onfocus="this.select();" data-index="7" id="inactivityValue"  placeholder="Enter inactivity disconnect (seconds)" title="Use 0 for no limit, or number of seconds allowed for inactivity" aria-lable="inactivity" aria-describedby="inactivity-addon">
						</div>
					</div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">Theme</span>
					</div>
					<input type="text" class="form-control disable-text" readonly id="curTheme"  title="Theme" aria-lable="theme" aria-describedby="theme-addon">
					<span class="input-group-btn">
						<div class="dropdown">
							<button class="btn btn-primary dropdown-toggle" data-index="8" id="themeSel" data-size="3" type="button" title="Theme List" data-toggle="dropdown"><i class="fas fa-list-alt fa-lg"></i>
							</button>
							<ul class="dropdown-menu dropdown-menu-right menu-scroll" id="keyerList">
								<div class='mytheme' id='or'><li><a class='dropdown-item' href='#'>Orange</a></li></div>
								<div class='mytheme' id='ni'><li><a class='dropdown-item' href='#'>Night</a></li></div>
								<div class='mytheme' id='lc'><li><a class='dropdown-item' href='#'>LCD</a></li></div>
								<div class='mytheme' id='hc'><li><a class='dropdown-item' href='#'>High Contrast</a></li></div>
								<div class='mytheme' id='gr'><li><a class='dropdown-item' href='#'>Green</a></li></div>
							</ul>
						 </div>
					</span>
				</div>
			</div>
			<div class="col-md-4 text-spacer">
			</div>
			<div class="col-md-4 text-spacer">
			</div>
		</div>
		<div class="row">
		</div>
		<div class="row">
			<div class="col-12 text-spacer text-center">
				<button class="btn btn-outline-success btn-sm my-2 my-sm-0 text-white" data-index="9" id="fillUser"  title="Click to fill fields from Callbook" type="button">
					Click to fill below from Callbook
				</button>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">First</span>
					</div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $fName
     ); ?>" onfocus="this.select();" data-index="10" id="fnameValue" aria-lable="name"  placeholder="First name" title="Account owner's first name" aria-describedby="name-addon">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">Last</span>
					</div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $lName
     ); ?>" onfocus="this.select();" data-index="11" id="lnameValue" aria-lable="last"  placeholder="Last name" title="Account owner's last name" aria-describedby="last-addon">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">Street</span>
					</div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $qth
     ); ?>" onfocus="this.select();" data-index="12" id="qthValue"  placeholder="Owner's street name and number" title="Account owner's home street address" aria-lable="time" aria-describedby="main-addon">
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">City</span>
					</div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $city
     ); ?>" onfocus="this.select();" data-index="13" id="cityValue"  placeholder="Owner's city name" title="Account owner's home city" aria-lable="time" aria-describedby="split-addon">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text" >County</span>
					</div>
					<input type="text" class="form-control"  value="<?php echo htmlentities(
         $county
     ); ?>" onfocus="this.select();" data-index="14" id="countyValue"  placeholder="Owner's county name" title="Account owner's home county" aria-lable="band" aria-describedby="call-addon">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text" >State</span>
					</div>
					<input type="text" class="form-control"  value="<?php echo htmlentities(
         $state
     ); ?>" onfocus="this.select();" data-index="15" id="stateValue"  placeholder="Owner's state name" title="Account owner's home state" aria-lable="rsts" aria-describedby="rsts-addon">
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-md-3 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text" >Country</span>
					</div>
					<input type="text" class="form-control"  value="<?php echo htmlentities(
         $country
     ); ?>" onfocus="this.select();" data-index="16" id="countryValue"  placeholder="Owner's country name" title="Account owner's home country" aria-lable="rstr" aria-describedby="rstr-addon">
				</div>
			</div>
			<div class="col-md-3 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">Class</span>
					</div>
					<input
						type="text"
						class="form-control"
						id="licenseClassValue"
						value="<?php echo htmlentities($licenseClass); ?>"
						maxlength="40"
						placeholder="General, Technician, Foundation, etc."
						title="Amateur radio license class">
				</div>
			</div>
			<div class="col-md-3 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text" >ZIP</span>
					</div>
					<input type="text" class="form-control"  value="<?php echo htmlentities(
         $zip
     ); ?>" onfocus="this.select();" data-index="17" id="zipValue"  placeholder="Owner's ZIP code" title="Account owner's home ZIP code" aria-lable="rstr" aria-describedby="rstr-addon">
				</div>
			</div>
			<div class="col-md-3 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">Continent</span>
					</div>
					<input type="text" class="form-control disable-text" readonly id="continentVal" value="<?php echo htmlentities(
         $continent
     ); ?>"  title="Account holder Continent" aria-lable="cont" aria-describedby="cont-addon">
					<span class="input-group-btn">
						<div class="dropdown">
							<button class="btn btn-primary dropdown-toggle" data-index="8" id="contSel" data-size="3" type="button" title="Continent List" data-toggle="dropdown"><i class="fas fa-list-alt fa-lg"></i>
							</button>
							<ul class="dropdown-menu dropdown-menu-right menu-scroll" id="contList">
								<div class='mycont' id='na'><li><a class='dropdown-item' href='#'>NA</a></li></div>
								<div class='mycont' id='eu'><li><a class='dropdown-item' href='#'>EU</a></li></div>
								<div class='mycont' id='sa'><li><a class='dropdown-item' href='#'>SA</a></li></div>
								<div class='mycont' id='af'><li><a class='dropdown-item' href='#'>AF</a></li></div>
								<div class='mycont' id='as'><li><a class='dropdown-item' href='#'>AS</a></li></div>
								<div class='mycont' id='oc'><li><a class='dropdown-item' href='#'>OC</a></li></div>
								<div class='mycont' id='an'><li><a class='dropdown-item' href='#'>AN</a></li></div>
							 </ul>
						</div>
					</span>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text" >Email</span>
					</div>
					<input type="text" class="form-control"  value="<?php echo htmlentities(
         $email
     ); ?>" onfocus="this.select();" data-index="19" id="emailValue"  placeholder="Owner's email address" title="Account owner's home email address" aria-lable="rstr" aria-describedby="rstr-addon">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">Phone</span>
					</div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $phone
     ); ?>" onfocus="this.select();" data-index="20" id="phoneValue"  placeholder="Owner's phone number" title="Account owner's home telephone number" aria-lable="name" aria-describedby="name-addon">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">Grid Sq</span>
					</div>
					<input type="text" class="form-control"  value="<?php echo htmlentities(
         $grid
     ); ?>" onfocus="this.select();" data-index="21" id="gridValue"  placeholder="Owner's Maidenhead grid" title="Account owner's home Maidenhead gridsquare" aria-lable="grid" aria-describedby="grid-addon">
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text" >Latitude</span>
					</div>
					<input type="text" class="form-control"  value="<?php echo htmlentities(
         $lat
     ); ?>" onfocus="this.select();" data-index="22" id="latValue"  placeholder="Owner's latitude" title="Account owner's home latitude" aria-lable="qsls" aria-describedby="qsls-addon">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">Longitude</span>
					</div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $lon
     ); ?>" onfocus="this.select();" data-index="23" id="lonValue"  placeholder="Owner's longitude" title="Account owner's home longitude" aria-lable="qslr" aria-describedby="qslr-addon">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text" >M Lat</span>
					</div>
					<input type="text" class="form-control"  value="<?php echo htmlentities(
         $mlat
     ); ?>" onfocus="this.select();" data-index="24" id="mlatValue"  placeholder="Owner's mobile latitude" title="Account owner's mobile latitude" aria-lable="ituz" aria-describedby="ituz-addon">
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">M Lon</span>
					</div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $mlon
     ); ?>" onfocus="this.select();" data-index="25" id="mlonValue"  placeholder="Owner's mobile longitude" title="Account owner's home longitude" aria-lable="wpx" aria-describedby="wpx-addon">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">M Grid</span>
					</div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $mgrid
     ); ?>" onfocus="this.select();" data-index="26" id="mgridValue"  placeholder="Owner's mobile Maidenhead grid" title="Account owner's mobile Maidenhead gridsquare" aria-lable="dxcc" aria-describedby="dxcc-addon">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
								<div class="input-group">
									<div class="input-group-prepend">
										<span class="input-group-text" >W Port</span>
									</div>
									<input type="text" class="form-control"  value="<?php echo htmlentities(
             $mWSJTXPort
         ); ?>" onfocus="this.select();" data-index="24" id="mWSJTXPort"  placeholder="WSJTX Port for Log Sync" title="WSJTX Port for Log Syncß" aria-lable="WPort" aria-describedby="Wport-addon">
								</div>
							</div>

		</div>
		<div class="row">
			 <div class="col-md-6 text-center text-spacer">
				  <div class="form-check form-check-inline">
					  <label class="form-check-label  text-white text-center">
					  <input type="checkbox" onfocus="this.select();" data-index="28" id="logFldigi" title="The log used by this account syncs with Fldigi" value="" class="form-check-input">
						  Sync Fldigi Log
					  </input>
					  </label>
				  </div>
			  </div>

						  <div class="col-md-6 text-center text-spacer"><div class="form-check form-check-inline"><label class="form-check-label text-white text-center"><input type="checkbox" onfocus="this.select();" data-index="29" id="logWSJTX" title="Sync with WSJT-X" value="" class="form-check-input">Sync WSJT-X Log</label></div></div>
		</div>
		<div class="row">
			<div class="col-12 text-center">
				<span class="label label-success text-white" style="margin-top:20px;">SDR Settings</span>
				<hr>
			</div>
		</div>
<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">SDR Host</span>
					</div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $sdrHost
     ); ?>"
						onfocus="this.select();" id="sdrHostValue"
						placeholder="<?php echo gethostname(); ?>.local or IP address"
						title="SDR server hostname or IP address. Your SDR is accessed at this host + /sdrN where N is your user ID. Example: http://rigpi5.local/sdr2">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">SDR Remote</span>
					</div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $sdrHostRemote
     ); ?>"
						onfocus="this.select();" id="sdrHostRemoteValue"
						autocomplete="off" autocapitalize="none" spellcheck="false"
						placeholder="Optional remote hostname or URL"
						title="SDR Remote hostname or IP for access outside your local network. Use your WAN IP, domain name, or Cloudflare Tunnel URL (e.g. https://sdr.rigpi.org). Append /sdrN for your SDR path.">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">SDR Port</span>
					</div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $sdrPort
     ); ?>"
						onfocus="this.select();" id="sdrPortValue"
						placeholder="8001"
						title="SDR server port number">
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">SDR Panel</span>
					</div>
					<input type="text" class="form-control disable-text" readonly id="sdrPositionValue"
						value="<?php echo htmlentities($sdrPosition); ?>"
						title="SDR panel position in RigPi interface">
					<span class="input-group-btn">
						<div class="dropdown">
							<button class="btn btn-primary dropdown-toggle" id="sdrPosSel"
								type="button" title="SDR Panel Position" data-toggle="dropdown">
								<i class="fas fa-list-alt fa-lg"></i>
							</button>
							<div class="dropdown-menu dropdown-menu-right" id="sdrPosList">
								<div class='mysdrpos' id='snone'><li><a class='dropdown-item' href='#'>none</a></li></div>
								<div class='mysdrpos' id='stop'><li><a class='dropdown-item' href='#'>top</a></li></div>
								<div class='mysdrpos' id='smiddle'><li><a class='dropdown-item' href='#'>middle</a></li></div>
								<div class='mysdrpos' id='sbottom'><li><a class='dropdown-item' href='#'>bottom</a></li></div>
							</div>
						</div>
					</span>
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text">SDR Page</span>
					</div>
					<input type="text" class="form-control disable-text" readonly id="sdrPageValue"
						value="<?php echo htmlentities($sdrPage); ?>"
						title="Which pages show the SDR panel">
					<span class="input-group-btn">
						<div class="dropdown">
							<button class="btn btn-primary dropdown-toggle" id="sdrPageSel"
								type="button" title="SDR Page" data-toggle="dropdown">
								<i class="fas fa-list-alt fa-lg"></i>
							</button>
							<div class="dropdown-menu dropdown-menu-right" id="sdrPageList">
								<div class='mysdrpage' id='pnone'><li><a class='dropdown-item' href='#'>none</a></li></div>
								<div class='mysdrpage' id='ptuner'><li><a class='dropdown-item' href='#'>tuner</a></li></div>
								<div class='mysdrpage' id='pkeyer'><li><a class='dropdown-item' href='#'>keyer</a></li></div>
								<div class='mysdrpage' id='pboth'><li><a class='dropdown-item' href='#'>both</a></li></div>
							</div>
						</div>
					</span>
				</div>
			</div>
			<div class="col-md-4 text-spacer"></div>
		</div>
<?php if ((int) $level === 1): ?>
		<div class="row" id="elmerServiceSettings">
			<div class="col-12 text-center">
				<span class="label label-success text-white" style="margin-top:20px;">RigPi Elmer Service</span>
				<hr>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Elmer</span></div>
					<input type="text" class="form-control disable-text" readonly id="elmerServiceStatus" value="Checking…" title="RigPi Elmer Service connection status">
					<div class="input-group-append"><button type="button" id="elmerConfigureBtn" class="btn btn-primary">Configure</button></div>
				</div>
			</div>
			<div class="col-md-8 text-spacer" style="background:transparent;"><span id="elmerServiceSummary" style="background:transparent !important;color:#f2f0e6 !important;">Checking this RigPi’s Elmer connection…</span></div>
		</div>
		<div id="elmerConfiguration" style="display:none;">
			<div class="row">
				<div class="col-md-4 text-spacer">
					<div class="input-group"><div class="input-group-prepend"><span class="input-group-text">Station</span></div><input type="text" class="form-control disable-text" readonly id="elmerStationName" value=""></div>
				</div>
				<div class="col-md-4 text-spacer">
					<div class="input-group"><div class="input-group-prepend"><span class="input-group-text">Plan</span></div><input type="text" class="form-control disable-text" readonly id="elmerPlan" value=""></div>
				</div>
				<div class="col-md-4 text-spacer">
					<div class="input-group"><div class="input-group-prepend"><span class="input-group-text">Remaining</span></div><input type="text" class="form-control disable-text" readonly id="elmerRemaining" value=""></div>
				</div>
			</div>
			<div class="row">
				<div class="col-md-8 text-spacer">
					<div class="input-group">
						<div class="input-group-prepend"><span class="input-group-text">Voice</span></div>
						<select class="form-control" id="elmerVoiceChoice" title="Voice used by Ask Elmer and Elmer Control">
							<option value="">Default (Marin)</option>
							<option value="alloy">Alloy</option><option value="ash">Ash</option>
							<option value="ballad">Ballad</option><option value="coral">Coral</option>
							<option value="echo">Echo</option><option value="fable">Fable</option>
							<option value="nova">Nova</option><option value="onyx">Onyx</option>
							<option value="sage">Sage</option><option value="shimmer">Shimmer</option>
							<option value="verse">Verse</option><option value="marin">Marin</option>
							<option value="cedar">Cedar</option>
						</select>
						<div class="input-group-append">
							<button type="button" id="elmerVoicePreviewBtn" class="btn btn-outline-secondary">Preview</button>
							<button type="button" id="elmerVoiceSaveBtn" class="btn btn-primary">Save</button>
						</div>
					</div>
				</div>
				<div class="col-md-4 text-spacer" style="background:transparent;"><span id="elmerVoiceMessage" class="small service-message">Used by Ask Elmer and Elmer Control.</span></div>
			</div>
			<div class="row" id="elmerPairingDetails" style="display:none;">
<div class="col-12 text-spacer" style="color:#fff;"><strong>Email W6HN to request Elmer access</strong><p>Send the approval code below to <a href="mailto:support@cmmsft.com" style="color:#9dd8ff;">support@cmmsft.com</a>. You must send this email so W6HN can approve your request. After approval, return here and click <strong>Check Approval</strong>.</p><button type="button" id="elmerCopyApprovalCode" class="btn btn-outline-light btn-sm">Copy code</button> <a id="elmerEmailApproval" class="btn btn-primary btn-sm" href="mailto:support@cmmsft.com">Email W6HN</a><small style="display:block;margin-top:8px;">Email W6HN opens a draft with your code. Please send it from your email app.</small></div>
				<div class="col-md-4 text-spacer"><div class="input-group"><div class="input-group-prepend"><span class="input-group-text">Approval Code</span></div><input type="text" class="form-control disable-text" readonly id="elmerPairingCode" value="" style="font-weight:bold;letter-spacing:.12em;"></div></div>
				<div class="col-md-8 text-spacer"><span id="elmerPairingExpiry"></span><br><small style="color:#f2f0e6 !important;">Administrator approval may take up to 24 hours. You may leave this page and return later; RigPi checks the request again when you return.</small></div>
			</div>
			<div class="row">
				<div class="col-12 text-spacer">
					<button type="button" id="elmerDisconnectBtn" class="btn btn-outline-danger btn-sm" style="display:none;">Disconnect</button>
					<a id="elmerStatsLink" class="btn btn-outline-secondary btn-sm" href="/elmer-stats.php" style="display:none;">View Usage</a>
					<span id="elmerServiceMessage" class="small service-message"></span>
					<p class="small mb-0 mt-2" style="background:transparent;color:#f2f0e6;">No ChatGPT subscription, OpenAI account, or API key is required. The protected station credential remains on this RigPi and is never shown in the browser.</p>
				</div>
			</div>
		</div>
		<?php endif; ?>
		<div class="row">
			<div class="col-12 text-center">
				<span class="label label-success text-white" style="margin-top:20px;">Pushover Notifications</span>
				<hr>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Pushover</span></div>
					<input type="text" class="form-control disable-text" readonly id="pushoverServiceStatus" value="Checking…">
					<div class="input-group-append"><button type="button" class="btn btn-primary" id="pushoverConfigureBtn">Configure</button></div>
				</div>
			</div>
			<div class="col-md-8 text-spacer" style="background:transparent;color:#f2f0e6;">
				Receive visitor and needed-spot notifications on your phone or desktop.
			</div>
		</div>
		<div id="pushoverConfiguration" style="display:none;">
			<div class="row">
				<?php if (isset($tID) ? $tID == 1 : $realID == 1): ?>
				<div class="col-md-4 text-spacer">
					<div class="input-group">
						<div class="input-group-prepend"><span class="input-group-text">App Token</span></div>
						<input type="password" class="form-control" id="pushoverToken" value="<?php echo htmlentities(
          $pushoverToken
      ); ?>" placeholder="Pushover application token" autocomplete="new-password">
					</div>
				</div>
				<?php else: ?>
				<input type="hidden" id="pushoverToken" value="">
				<?php endif; ?>
				<div class="col-md-4 text-spacer">
					<div class="input-group">
						<div class="input-group-prepend"><span class="input-group-text">User Key</span></div>
						<input type="password" class="form-control" id="pushoverUser" value="<?php echo htmlentities(
          $pushoverUser
      ); ?>" placeholder="Pushover user key" autocomplete="new-password">
					</div>
				</div>
				<div class="col-md-4 text-spacer">
					<div class="input-group">
						<div class="input-group-prepend"><span class="input-group-text">Min Delay</span></div>
						<input type="number" min="0" max="86400" class="form-control" id="pushoverDelay" value="<?php echo (int) $pushoverDelay; ?>" title="Minimum seconds between notifications">
						<div class="input-group-append"><span class="input-group-text">sec</span></div>
					</div>
				</div>
			</div>
			<div class="row">
				<div class="col-md-8 text-spacer" style="background:transparent;color:#f2f0e6;">
					<input type="hidden" id="pushoverNotifyValue" value="<?php echo htmlentities(
         $pushoverNotify
     ); ?>">
					<?php if (isset($tID) ? $tID == 1 : $realID == 1): ?>
					<label class="mr-3"><input class="pushover-check" type="checkbox" id="notifyLogin"> Login</label>
					<label class="mr-3"><input class="pushover-check" type="checkbox" id="notifyLogout"> Logout</label>
					<?php endif; ?>
					<label class="mr-3"><input class="pushover-check" type="checkbox" id="notifySpots"> Needed spots</label>
				</div>
				<div class="col-md-4 text-spacer">
					<button type="button" class="btn btn-primary btn-sm" id="pushoverSaveBtn">Save</button>
					<button type="button" class="btn btn-outline-secondary btn-sm" id="pushoverTestBtn">Test</button>
					<button type="button" class="btn btn-outline-danger btn-sm" id="pushoverDisconnectBtn">Disconnect</button>
					<span class="small service-message" id="pushoverServiceMessage"></span>
				</div>
			</div>
			<div class="row"><div class="col-md-12 text-spacer" style="background:transparent;color:#f2f0e6;">
				The administrator supplies the station application token. Each user supplies a personal Pushover user key. Saved credentials are hidden while this panel is closed.
				<a href="https://pushover.net/" target="_blank" rel="noopener noreferrer" style="color:#9fd8ff;">Open Pushover.net</a>
			</div></div>
		</div>
		<div class="row">
			<div class="col-12 text-center">
				<span class="label label-success text-white" style="margin-top:20px;">QRZ Callbook</span>
				<hr>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">QRZ</span></div>
					<input type="text" class="form-control disable-text callbook-status" readonly data-provider="qrz" value="<?php echo $qrzUser !==
         "" && $qrzPWD !== ""
         ? "Configured"
         : "Not configured"; ?>">
					<div class="input-group-append"><button type="button" class="btn btn-primary callbook-configure" data-provider="qrz">Configure</button></div>
				</div>
			</div>
			<div class="col-md-8 text-spacer" style="background:transparent;color:#f2f0e6;">QRZ XML supplies current callsign details, biographies, and approved profile images to RigPi and Ask Elmer.</div>
		</div>
		<div id="qrzConfiguration" class="callbook-configuration" style="display:none;">
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Username</span></div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $qrzUser
     ); ?>" data-index="65" id="qrzUserValue" placeholder="QRZ username for XML access" title="QRZ username for XML access">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Password</span></div>
					<input type="password" class="form-control" value="<?php echo htmlentities(
         $qrzPWD
     ); ?>" data-index="65" id="qrzPWDValue" placeholder="QRZ password for XML access" title="QRZ password for XML access">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<button type="button" class="btn btn-primary btn-sm callbook-save" data-provider="qrz">Save</button>
				<button type="button" class="btn btn-outline-secondary btn-sm callbook-test" data-provider="qrz">Test</button>
				<button type="button" class="btn btn-outline-danger btn-sm callbook-disconnect" data-provider="qrz">Disconnect</button>
				<span class="small service-message callbook-message" data-provider="qrz"></span>
			</div>
		</div>
		</div>
		<div class="row">
			<div class="col-12 text-center">
				<span class="label label-success text-white" style="margin-top:20px;">HamQTH Callbook (QRZ fallback)</span>
				<hr>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">HamQTH</span></div>
					<input type="text" class="form-control disable-text callbook-status" readonly data-provider="hamqth" value="<?php echo $hamqthUser !==
         "" && $hamqthPWD !== ""
         ? "Configured"
         : "Not configured"; ?>">
					<div class="input-group-append"><button type="button" class="btn btn-primary callbook-configure" data-provider="hamqth">Configure</button></div>
				</div>
			</div>
			<div class="col-md-8 text-spacer" style="background:transparent;color:#f2f0e6;">HamQTH is used automatically as a fallback when QRZ XML is not configured or unavailable.</div>
		</div>
		<div id="hamqthConfiguration" class="callbook-configuration" style="display:none;">
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Username</span></div>
					<input type="text" class="form-control" value="<?php echo htmlentities(
         $hamqthUser
     ); ?>" id="hamqthUserValue" placeholder="HamQTH username" title="HamQTH username — used as QRZ fallback">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Password</span></div>
					<input type="password" class="form-control" value="<?php echo htmlentities(
         $hamqthPWD
     ); ?>" id="hamqthPWDValue" placeholder="HamQTH password" title="HamQTH password">
				</div>
			</div>
			<div class="col-md-4 text-spacer">
				<button type="button" class="btn btn-primary btn-sm callbook-save" data-provider="hamqth">Save</button>
				<button type="button" class="btn btn-outline-secondary btn-sm callbook-test" data-provider="hamqth">Test</button>
				<button type="button" class="btn btn-outline-danger btn-sm callbook-disconnect" data-provider="hamqth">Disconnect</button>
				<span class="small service-message callbook-message" data-provider="hamqth"></span>
			</div>
		</div>
		</div>
		<input type="hidden" id="googleAPIKeyValue" value="<?php echo htmlentities(
      $googleAPIKey
  ); ?>">
		<?php if ((int) $level === 1): ?>
		<div class="row">
			<div class="col-12 text-center">
				<span class="label label-success text-white" style="margin-top:20px;">Email Notifications</span>
				<hr>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer">
				<div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Email</span></div>
					<input type="text" class="form-control disable-text" readonly id="emailServiceStatus" value="Checking…">
					<div class="input-group-append"><button type="button" class="btn btn-primary" id="emailConfigureBtn">Configure</button></div>
				</div>
			</div>
			<div class="col-md-8 text-spacer" style="background:transparent;color:#f2f0e6;">
				Send RigPi email through Gmail, Outlook, Yahoo, your hosting provider, or another SMTP server.
			</div>
		</div>
		<div id="emailConfiguration" style="display:none;">
			<div class="row">
				<div class="col-md-4 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Provider</span></div>
					<select class="form-control" id="emailProvider"><option value="custom">Custom SMTP</option><option value="gmail">Gmail</option><option value="outlook">Outlook / Microsoft 365</option><option value="yahoo">Yahoo</option></select>
				</div></div>
				<div class="col-md-4 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Server</span></div>
					<input type="text" class="form-control" id="emailSMTPHost" placeholder="smtp.example.com" autocomplete="off">
				</div></div>
				<div class="col-md-4 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Port</span></div>
					<input type="number" min="1" max="65535" class="form-control" id="emailSMTPPort" value="587">
					<select class="form-control" id="emailSMTPEncryption"><option value="starttls">STARTTLS</option><option value="tls">TLS/SSL</option><option value="none">None</option></select>
				</div></div>
			</div>
			<div class="row">
				<div class="col-md-4 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Username</span></div>
					<input type="text" class="form-control" id="emailSMTPUsername" placeholder="Full email address" autocomplete="username">
				</div></div>
				<div class="col-md-4 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Password</span></div>
					<input type="password" class="form-control" id="emailSMTPPassword" placeholder="App password" autocomplete="new-password">
				</div></div>
				<div class="col-md-4 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Recipient</span></div>
					<input type="text" class="form-control disable-text" readonly id="emailRecipient" value="<?php echo htmlentities(
         $email
     ); ?>" placeholder="Use the account Email field">
				</div></div>
			</div>
			<div class="row">
				<div class="col-md-4 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">From Email</span></div>
					<input type="email" class="form-control" id="emailFromAddress" placeholder="station@example.com">
				</div></div>
				<div class="col-md-4 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">From Name</span></div>
					<input type="text" class="form-control" id="emailFromName" value="RigPi" placeholder="RigPi">
				</div></div>
				<div class="col-md-4 text-spacer">
					<button type="button" class="btn btn-primary btn-sm" id="emailSaveBtn">Save</button>
					<button type="button" class="btn btn-outline-secondary btn-sm" id="emailTestBtn">Test</button>
					<button type="button" class="btn btn-outline-danger btn-sm" id="emailDisconnectBtn">Disconnect</button>
					<span class="small service-message" id="emailServiceMessage"></span>
				</div>
			</div>
			<div class="row"><div class="col-md-12 text-spacer" style="background:transparent;color:#f2f0e6;">
				The outgoing SMTP account is shared by this RigPi. Test messages go to the Email address near the top of this user account. Gmail and Yahoo generally require an app password rather than the normal account password.
			</div></div>
		</div>
		<div class="row">
			<div class="col-12 text-center">
				<span class="label label-success text-white" style="margin-top:20px;">HTTPS Remote Access</span>
				<hr>
			</div>
		</div>
		<div class="row"><div class="col-md-12 text-spacer" style="background:transparent;color:#f2f0e6;">
			Choose one method. <strong>Native HTTPS (DuckDNS)</strong> is free and straightforward when you can forward router port 443. Choose <strong>Cloudflare Tunnel</strong> when port forwarding is unavailable, the station is behind CGNAT, or you prefer not to expose its public IP address.
		</div></div>
		<div class="row">
			<div class="col-md-4 text-spacer"><div class="input-group">
				<div class="input-group-prepend"><span class="input-group-text">DuckDNS</span></div>
				<input type="text" class="form-control disable-text" readonly id="duckdnsServiceStatus" value="Checking…">
				<div class="input-group-append"><button type="button" class="btn btn-primary" id="duckdnsConfigureBtn">Configure</button></div>
			</div></div>
			<div class="col-md-8 text-spacer" style="background:transparent;color:#f2f0e6;">
				<strong>Native HTTPS</strong> — Free permanent hostname and automatically renewed Let&rsquo;s Encrypt certificate. Requires router port forwarding and exposes the station&rsquo;s public IP address.
			</div>
		</div>
		<div id="duckdnsConfiguration" style="display:none;">
			<div class="row">
				<div class="col-md-4 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Name</span></div>
					<input type="text" class="form-control" id="duckdnsSubdomain" placeholder="your-rigpi" autocomplete="off">
					<div class="input-group-append"><span class="input-group-text">.duckdns.org</span></div>
				</div></div>
				<div class="col-md-4 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Email</span></div>
					<input type="email" class="form-control" id="duckdnsEmail" value="<?php echo htmlentities(
         $email
     ); ?>" placeholder="Certificate notices">
				</div></div>
				<div class="col-md-4 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Token</span></div>
					<input type="password" class="form-control" id="duckdnsToken" placeholder="DuckDNS account token" autocomplete="new-password">
				</div></div>
			</div>
			<div class="row">
				<div class="col-md-8 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Remote URL</span></div>
					<input type="text" class="form-control disable-text" readonly id="duckdnsRemoteURL" value="" placeholder="Not configured">
					<div class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="duckdnsOpenBtn" disabled>Open</button></div>
				</div></div>
				<div class="col-md-4 text-spacer">
					<button type="button" class="btn btn-primary btn-sm" id="duckdnsProvisionBtn">Set Up</button>
					<button type="button" class="btn btn-outline-secondary btn-sm" id="duckdnsSetTokenBtn">Update Token</button>
					<button type="button" class="btn btn-outline-secondary btn-sm" id="duckdnsRefreshBtn">Refresh IP</button>
					<button type="button" class="btn btn-outline-secondary btn-sm" id="duckdnsEnableBtn">Enable</button>
					<button type="button" class="btn btn-outline-secondary btn-sm" id="duckdnsDisableBtn">Disable</button>
					<button type="button" class="btn btn-outline-danger btn-sm" id="duckdnsRemoveBtn">Remove</button>
					<span class="small service-message" id="duckdnsServiceMessage"></span>
				</div>
			</div>
			<div class="row"><div class="col-md-12 text-spacer" style="background:transparent;color:#f2f0e6;">
				Create the hostname at <a href="https://www.duckdns.org/" target="_blank" rel="noopener noreferrer" style="color:#9fd8ff;">DuckDNS</a>, then enter its name, your certificate email, and the account token. After setup, forward external TCP port <strong>443</strong> to TCP port <strong>443</strong> on this RigPi; do not forward port 80. The token is stored in a root-only file and is never returned to this page.
			</div></div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer"><div class="input-group">
				<div class="input-group-prepend"><span class="input-group-text">Cloudflare</span></div>
				<input type="text" class="form-control disable-text" readonly id="cloudflareServiceStatus" value="Checking…">
				<div class="input-group-append"><button type="button" class="btn btn-primary" id="cloudflareConfigureBtn">Configure</button></div>
			</div></div>
			<div class="col-md-8 text-spacer" style="background:transparent;color:#f2f0e6;">
				<strong>Cloudflare Tunnel</strong> — Secure HTTPS without router port forwarding. Works behind CGNAT and hides the station&rsquo;s public IP address, but requires Cloudflare setup and normally a domain.
			</div>
		</div>
		<div id="cloudflareConfiguration" style="display:none;">
			<div class="row">
				<div class="col-md-4 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Remote URL</span></div>
					<input type="text" class="form-control disable-text" readonly id="cloudflareRemoteURL" value="<?php echo htmlentities(
         $sdrHostRemote
     ); ?>" placeholder="Set SDR Remote above">
				</div></div>
				<div class="col-md-8 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Connector Token</span></div>
					<input type="password" class="form-control" id="cloudflareConnectorToken" value="" placeholder="Paste the long eyJ… token (often about 180 characters)" autocomplete="new-password">
				</div></div>
			</div>
			<div class="row">
				<div class="col-md-8 text-spacer" style="background:transparent;color:#f2f0e6;">
					For an existing tunnel: sign in at Cloudflare, open <strong>Networking → Tunnels</strong>, select the tunnel, and choose <strong>Add a connector</strong>. Copy the manual command into Notes or Notepad, extract its long <code>eyJ…</code> token, and paste only that token above. The tunnel’s published hostname should route to <code>http://localhost:80</code>.
					<a href="https://one.dash.cloudflare.com/" target="_blank" rel="noopener noreferrer" style="color:#9fd8ff;">Open Cloudflare</a>
					· <a href="/Help/cloudflare.html" target="_blank" style="color:#9fd8ff;">Setup Help</a>
				</div>
				<div class="col-md-4 text-spacer">
					<button type="button" class="btn btn-primary btn-sm" id="cloudflareConnectBtn">Connect</button>
					<button type="button" class="btn btn-outline-secondary btn-sm" id="cloudflareRestartBtn">Restart</button>
					<button type="button" class="btn btn-outline-danger btn-sm" id="cloudflareDisconnectBtn">Disconnect</button>
					<span class="small service-message" id="cloudflareServiceMessage"></span>
				</div>
			</div>
			<div class="row"><div class="col-md-12 text-spacer" style="background:transparent;color:#f2f0e6;">
				The connector token is stored in a root-only file and is never returned to the browser. Set each account’s <strong>SDR Remote</strong> URL above to the correct hostname and <code>/sdrN</code> path.
			</div></div>
		</div>
		<div class="row">
			<div class="col-12 text-center">
				<span class="label label-success text-white" style="margin-top:20px;">Remote Support Access</span>
				<hr>
			</div>
		</div>
		<div class="row">
			<div class="col-md-4 text-spacer"><div class="input-group">
				<div class="input-group-prepend"><span class="input-group-text">Support</span></div>
				<input type="text" class="form-control disable-text" readonly id="supportAccessStatus" value="Checking…">
				<div class="input-group-append"><button type="button" class="btn btn-primary" id="supportAccessConfigureBtn">Configure</button></div>
			</div></div>
			<div class="col-md-8 text-spacer" style="background:transparent;color:#f2f0e6;">
				Temporarily allow a trusted RigPi support person to sign in by SSH. No support key is installed at the factory.
			</div>
		</div>
		<div id="supportAccessConfiguration" style="display:none;">
			<div class="row">
				<div class="col-md-12 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Public Key</span></div>
					<input type="password" class="form-control" id="supportAccessPublicKey" value="" placeholder="Paste the ssh-ed25519 public key supplied by support" autocomplete="new-password">
				</div></div>
			</div>
			<div class="row">
				<div class="col-md-8 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Connection</span></div>
					<select class="form-control" id="supportAccessConnectionMode">
						<option value="quick" selected>Temporary address — no domain required</option>
						<option value="existing">Existing secure station address</option>
					</select>
				</div></div>
				<div class="col-md-4 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Expires</span></div>
					<select class="form-control" id="supportAccessHours">
						<option value="1">1 hour</option><option value="4" selected>4 hours</option>
						<option value="24">24 hours</option><option value="72">72 hours</option>
					</select>
				</div></div>
			</div>
			<div class="row">
				<div class="col-md-12 text-spacer"><div class="input-group">
					<div class="input-group-prepend"><span class="input-group-text">Support URL</span></div>
					<input type="text" class="form-control disable-text" readonly id="supportAccessURL" value="" placeholder="Configure Native HTTPS or Cloudflare Remote Access">
					<div class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="supportAccessCopyURLBtn" disabled>Copy</button></div>
				</div></div>
			</div>
			<div class="row">
				<div class="col-md-8 text-spacer" style="background:transparent;color:#f2f0e6;">
					The recommended temporary address is created automatically—no Cloudflare account, domain, token, DNS change, or router port forwarding is required. Enable access only while working with someone you trust. The key cannot forward ports or authentication credentials, expires automatically, and is removed by Factory Reset.
				</div>
				<div class="col-md-4 text-spacer">
					<button type="button" class="btn btn-primary btn-sm" id="supportAccessEnableBtn">Enable Remote Support</button>
					<button type="button" class="btn btn-outline-danger btn-sm" id="supportAccessRevokeBtn">Revoke Now</button>
					<span class="small service-message" id="supportAccessMessage"></span>
				</div>
			</div>
		</div>
		<?php endif; ?>
		<div class="row">
			<div id = "result"></div>
		</div>
	</div>
</div>
	<?php require $dRoot . "/includes/modal.txt"; ?>
	<?php require $dRoot . "/includes/footer.php"; ?>
	<?php require $dRoot . "/includes/modalAlert.txt"; ?>
<?php require $dRoot . "/includes/modalCancelOnly.txt"; ?>

<script src="./Bootstrap/popper.min.js"</script>
<link rel="stylesheet" href="./Bootstrap/jquery-ui.css">
<script src="./Bootstrap/jquery-ui.js"></script>
<script src="./Bootstrap/bootstrap.min.js"></script>
<?php if ((int) $level === 1): ?>
<script>
(function () {
	const statusInput = document.getElementById('elmerServiceStatus');
	if (!statusInput) return;
	const summary = document.getElementById('elmerServiceSummary');
	const configuration = document.getElementById('elmerConfiguration');
	const details = document.getElementById('elmerPairingDetails');
	const code = document.getElementById('elmerPairingCode');
	const expiry = document.getElementById('elmerPairingExpiry');
	const stationName = document.getElementById('elmerStationName');
	const plan = document.getElementById('elmerPlan');
	const remainingInput = document.getElementById('elmerRemaining');
	const configure = document.getElementById('elmerConfigureBtn');
	const disconnect = document.getElementById('elmerDisconnectBtn');
	const stats = document.getElementById('elmerStatsLink');
	const message = document.getElementById('elmerServiceMessage');
    function updateElmerApprovalEmail() {
        const approvalCode = code.value.trim();
        const body = ['Hello W6HN,', '', 'Please approve Elmer access for my RigPi.', 'Approval code: ' + approvalCode, '', 'Thank you.'].join(String.fromCharCode(13, 10));
        const href = 'mailto:support@cmmsft.com?subject=' + encodeURIComponent('RigPi Elmer access request') + '&body=' + encodeURIComponent(body);
        document.querySelectorAll('#elmerPairingDetails a[href^="mailto:"]').forEach(link => { link.href = href; });
        return approvalCode;
    }
    document.querySelectorAll('#elmerPairingDetails a[href^="mailto:"]').forEach(link => {
        link.addEventListener('click', event => {
            if (!updateElmerApprovalEmail()) {
                event.preventDefault();
                message.textContent = 'Please generate an approval code before emailing W6HN.';
            }
        });
    });

    document.getElementById('elmerCopyApprovalCode').addEventListener('click', async function () {
        if (!code.value) { message.textContent = 'No approval code is available yet.'; return; }
        try {
            if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(code.value); }
            else { code.focus(); code.select(); if (!document.execCommand('copy')) throw new Error('Copy unavailable'); }
            message.textContent = 'Code copied. Email it to support@cmmsft.com.';
        } catch (error) { code.focus(); code.select(); message.textContent = 'Code selected. Copy it and email it to support@cmmsft.com.'; }
    });
	const voiceChoice = document.getElementById('elmerVoiceChoice');
	const voicePreview = document.getElementById('elmerVoicePreviewBtn');
	const voiceSave = document.getElementById('elmerVoiceSaveBtn');
	const voiceMessage = document.getElementById('elmerVoiceMessage');
	const voiceAudio = typeof window.Audio === 'function' ? new Audio() : null;
	let voiceAudioURL = '';
	let currentStatus = 'checking';
	let pollTimer = null;

	async function service(action, extra) {
		const response = await fetch('/programs/ElmerService.php', {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-Elmer-Action': 'service-config'},
			body: JSON.stringify(Object.assign({action: action}, extra || {}))
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(data.error || 'RigPi could not manage the Elmer service.');
		return data;
	}

	async function preference(action, extra) {
		const response = await fetch('/programs/ElmerPreferences.php', {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-Elmer-Action': 'preferences'},
			body: JSON.stringify(Object.assign({action: action}, extra || {}))
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(data.error || 'RigPi could not manage the Elmer voice.');
		return data;
	}

	async function loadVoice() {
		try {
			const data = await preference('status');
			voiceChoice.value = [...voiceChoice.options].some(option => option.value === data.voice) ? data.voice : '';
		} catch (error) { voiceMessage.textContent = error.message; }
	}

	function show(data) {
		currentStatus = data.status || 'error';
		statusInput.value = currentStatus === 'connected' ? 'Connected' : currentStatus === 'pending' ? 'Approval pending' : currentStatus === 'disconnected' ? 'Not connected' : 'Unavailable';
		details.style.display = currentStatus === 'pending' ? '' : 'none';
		disconnect.style.display = currentStatus === 'connected' ? '' : 'none';
		stats.style.display = currentStatus === 'connected' ? '' : 'none';
		configure.textContent = currentStatus === 'pending' ? 'Check Approval' : 'Configure';
		if (currentStatus === 'connected') {
			const remaining = data.remaining !== '' && data.remaining !== undefined ? ' · ' + data.remaining + ' questions remaining this month' : '';
			summary.textContent = (data.station_name || 'This RigPi') + (data.plan ? ' · ' + data.plan + ' plan' : '') + remaining;
			stationName.value = data.station_name || 'This RigPi';
			plan.value = data.plan || '';
			remainingInput.value = data.remaining !== undefined ? data.remaining : '';
		} else if (currentStatus === 'pending') {
			configuration.style.display = '';
			code.value = data.code || '';
            updateElmerApprovalEmail();
			expiry.textContent = data.expires_at ? 'Expires: ' + data.expires_at : '';
			summary.textContent = 'Email your approval code to support@cmmsft.com, then click Check Approval after W6HN approves it.';
			stationName.value = '';
			plan.value = '';
			remainingInput.value = '';
		} else if (currentStatus === 'disconnected') {
			configuration.style.display = 'none';
			summary.textContent = 'Connect this RigPi to enable Ask Elmer, Elmer Control, and live captions.';
			stationName.value = '';
			plan.value = '';
			remainingInput.value = '';
		} else {
			summary.textContent = data.error || 'The Elmer service status is unavailable.';
		}
		if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
		if (currentStatus === 'pending') pollTimer = setTimeout(refresh, 3500);
	}

	async function refresh() {
		try { show(await service('status')); }
		catch (error) { show({status: 'error', error: error.message}); }
	}

	configure.addEventListener('click', async function () {
		message.textContent = '';
		if (currentStatus === 'connected') {
			configuration.style.display = configuration.style.display === 'none' ? '' : 'none';
			if (configuration.style.display !== 'none') await refresh();
			return;
		}
		if (currentStatus === 'pending') { configuration.style.display = ''; await refresh(); return; }
		const suggested = <?php echo json_encode(
      gethostname(),
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  ); ?>;
		const stationName = window.prompt('Name this RigPi for the Elmer administrator:', suggested);
		if (stationName === null) return;
		const callsignInput = window.prompt('Operator callsign (required):', '');
		if (callsignInput === null) return;
		const callsign = callsignInput.trim().toUpperCase();
		if (!/^[A-Z0-9]{1,3}[A-Z0-9/]{1,9}$/.test(callsign)) { window.alert('Enter a valid amateur-radio callsign.'); return; }
		const contactEmailInput = window.prompt('Email for registration codes, account messages, alerts, and support (required):', '');
		if (contactEmailInput === null) return;
		const contactEmail = contactEmailInput.trim().toLowerCase();
		if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(contactEmail)) { window.alert('Enter a valid email address.'); return; }
		const serviceAlerts = window.confirm('Receive optional Elmer service alerts at this email?');
		const productUpdates = window.confirm('Receive occasional RigPi/Elmer new-feature announcements?');
		configure.disabled = true;
		try { show(await service('start', {station_name: stationName, callsign: callsign, contact_email: contactEmail, service_alerts: serviceAlerts, product_updates: productUpdates})); }
		catch (error) { show({status: 'error', error: error.message}); }
		finally { configure.disabled = false; }
	});

	disconnect.addEventListener('click', async function () {
		if (!window.confirm('Disconnect this RigPi from the hosted Elmer service? Ask Elmer and Elmer Control will no longer use its station allowance.')) return;
		disconnect.disabled = true;
		try { show(await service('disconnect')); message.textContent = 'Elmer was disconnected.'; }
		catch (error) { message.textContent = error.message; }
		finally { disconnect.disabled = false; }
	});

	voicePreview.addEventListener('click', async function () {
		voicePreview.disabled = true;
		voiceMessage.textContent = 'Preparing preview…';
		try {
			const label = voiceChoice.options[voiceChoice.selectedIndex].textContent.replace(/^Default \(|\)$/g, '');
			const payload = {text: 'Hello from Elmer. This is the ' + label + ' voice.', lead_silence_ms: 100};
			payload.voice = voiceChoice.value || 'marin';
			const response = await fetch('/elmer-api/speech', {
				method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
				body: JSON.stringify(payload)
			});
			if (!response.ok) throw new Error('That voice preview is unavailable.');
			const blob = await response.blob();
			if (!voiceAudio) throw new Error('Audio playback is unavailable in this browser.');
			if (voiceAudioURL) URL.revokeObjectURL(voiceAudioURL);
			voiceAudioURL = URL.createObjectURL(blob);
			voiceAudio.src = voiceAudioURL;
			await voiceAudio.play();
			voiceMessage.textContent = 'Preview only — click Save to keep this voice.';
		} catch (error) { voiceMessage.textContent = error.message; }
		finally { voicePreview.disabled = false; }
	});

	voiceSave.addEventListener('click', async function () {
		voiceSave.disabled = true;
		try {
			await preference('voice', {voice: voiceChoice.value});
			voiceMessage.textContent = (voiceChoice.value ? voiceChoice.options[voiceChoice.selectedIndex].textContent : 'Default Marin') + ' saved for this user.';
		} catch (error) { voiceMessage.textContent = error.message; }
		finally { voiceSave.disabled = false; }
	});
	refresh();
	loadVoice();
})();
</script>
<script>
(function () {
	const status = document.getElementById('duckdnsServiceStatus');
	const configure = document.getElementById('duckdnsConfigureBtn');
	const panel = document.getElementById('duckdnsConfiguration');
	const subdomain = document.getElementById('duckdnsSubdomain');
	const email = document.getElementById('duckdnsEmail');
	const token = document.getElementById('duckdnsToken');
	const remoteURL = document.getElementById('duckdnsRemoteURL');
	const open = document.getElementById('duckdnsOpenBtn');
	const provision = document.getElementById('duckdnsProvisionBtn');
	const setToken = document.getElementById('duckdnsSetTokenBtn');
	const refresh = document.getElementById('duckdnsRefreshBtn');
	const enable = document.getElementById('duckdnsEnableBtn');
	const disable = document.getElementById('duckdnsDisableBtn');
	const remove = document.getElementById('duckdnsRemoveBtn');
	const message = document.getElementById('duckdnsServiceMessage');
	let current = {configured: false, enabled: false};
	if (!status || !configure || !panel) return;

	async function service(action, extra) {
		const response = await fetch('/programs/DuckDNSService.php', {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-RigPi-Action': 'duckdns-service'},
			body: JSON.stringify(Object.assign({action: action}, extra || {}))
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(data.error || 'RigPi could not manage native HTTPS.');
		return data;
	}
	function show(data) {
		current = data;
		status.value = data.enabled ? 'Connected' : data.configured ? 'Disabled' : 'Not configured';
		remoteURL.value = data.remote_url || '';
		open.disabled = !data.enabled || !data.remote_url;
		setToken.style.display = data.configured ? '' : 'none';
		refresh.style.display = data.configured ? '' : 'none';
		enable.style.display = data.configured && !data.enabled ? '' : 'none';
		disable.style.display = data.enabled ? '' : 'none';
		remove.style.display = data.configured ? '' : 'none';
		provision.textContent = data.configured ? 'Replace Setup' : 'Set Up';
		if (data.hostname) subdomain.value = data.hostname.replace(/\.duckdns\.org$/i, '');
		status.title = [data.dns_address ? 'DNS ' + data.dns_address : '', data.certificate_expires ? 'Certificate expires ' + data.certificate_expires : ''].filter(Boolean).join(' · ');
	}
	async function perform(action, extra, progress) {
		message.textContent = progress || 'Working…';
		try {
			const data = await service(action, extra);
			show(data); token.value = '';
			message.textContent = data.message || 'Complete.';
		} catch (error) { message.textContent = error.message; }
	}
	configure.addEventListener('click', function () {
		panel.style.display = panel.style.display === 'none' ? '' : 'none';
	});
	provision.addEventListener('click', async function () {
		if (!subdomain.value.trim() || !email.value.trim() || !token.value.trim()) {
			message.textContent = 'Enter the DuckDNS name, certificate email, and token.'; return;
		}
		if (current.configured && !window.confirm('Replace the current native HTTPS setup? Use Update Token if only the DuckDNS token changed.')) return;
		provision.disabled = true;
		await perform('provision', {subdomain: subdomain.value.trim(), email: email.value.trim(), token: token.value.trim()}, 'Requesting and installing the HTTPS certificate…');
		provision.disabled = false;
	});
	setToken.addEventListener('click', async function () {
		if (!token.value.trim()) { message.textContent = 'Enter the new DuckDNS token.'; return; }
		setToken.disabled = true;
		await perform('set_token', {token: token.value.trim()}, 'Verifying the new token…');
		setToken.disabled = false;
	});
	refresh.addEventListener('click', async function () {
		refresh.disabled = true; await perform('update_ip', {}, 'Refreshing the DuckDNS address…'); refresh.disabled = false;
	});
	enable.addEventListener('click', async function () {
		enable.disabled = true; await perform('enable', {}, 'Enabling native HTTPS…'); enable.disabled = false;
	});
	disable.addEventListener('click', async function () {
		if (!window.confirm('Disable native HTTPS and automatic DuckDNS address updates? The certificate and settings will be retained.')) return;
		disable.disabled = true; await perform('disable', {}, 'Disabling native HTTPS…'); disable.disabled = false;
	});
	remove.addEventListener('click', async function () {
		if (!window.confirm('Remove the native HTTPS certificate and DuckDNS token from this RigPi? The hostname will remain in your DuckDNS account.')) return;
		remove.disabled = true; await perform('remove', {}, 'Removing native HTTPS from this RigPi…'); remove.disabled = false;
	});
	open.addEventListener('click', function () {
		if (remoteURL.value) window.open(remoteURL.value, '_blank', 'noopener');
	});
	service('status').then(show).catch(function (error) {
		status.value = 'Unavailable'; message.textContent = error.message;
	});
})();
</script>
<script>
(function () {
	const status = document.getElementById('cloudflareServiceStatus');
	const configure = document.getElementById('cloudflareConfigureBtn');
	const panel = document.getElementById('cloudflareConfiguration');
	const token = document.getElementById('cloudflareConnectorToken');
	const remoteURL = document.getElementById('cloudflareRemoteURL');
	const message = document.getElementById('cloudflareServiceMessage');
	const connect = document.getElementById('cloudflareConnectBtn');
	const restart = document.getElementById('cloudflareRestartBtn');
	const disconnect = document.getElementById('cloudflareDisconnectBtn');
	if (!status || !configure || !panel) return;

	async function service(action) {
		const response = await fetch('/programs/CloudflareService.php', {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-RigPi-Action': 'cloudflare-service'},
			body: JSON.stringify({action: action, token: action === 'connect' ? token.value.trim() : ''})
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(data.error || 'RigPi could not manage Cloudflare Tunnel.');
		return data;
	}
	function show(data) {
		status.value = data.status === 'connected' ? 'Connected'
			: data.installed === false ? 'Not installed' : 'Not connected';
		if (data.remote_url !== undefined) remoteURL.value = data.remote_url;
		restart.style.display = data.active ? '' : 'none';
		disconnect.style.display = data.active ? '' : 'none';
		connect.textContent = data.active ? 'Replace Connector' : 'Connect';
		status.title = data.version ? 'cloudflared ' + data.version : '';
	}
	configure.addEventListener('click', function () {
		panel.style.display = panel.style.display === 'none' ? '' : 'none';
	});
	connect.addEventListener('click', async function () {
		if (!token.value.trim()) { message.textContent = 'Paste the connector token supplied by Cloudflare.'; return; }
		if (status.value === 'Connected' && !window.confirm('Replace the connector credential and reconnect this RigPi?')) return;
		connect.disabled = true; message.textContent = 'Connecting to Cloudflare…';
		try {
			const data = await service('connect'); token.value = ''; show(data);
			message.textContent = data.message || 'Connected.';
		} catch (error) { message.textContent = error.message; }
		finally { connect.disabled = false; }
	});
	restart.addEventListener('click', async function () {
		restart.disabled = true; message.textContent = 'Restarting connector…';
		try { const data = await service('restart'); show(data); message.textContent = data.message || 'Restarted.'; }
		catch (error) { message.textContent = error.message; }
		finally { restart.disabled = false; }
	});
	disconnect.addEventListener('click', async function () {
		if (!window.confirm('Disconnect this RigPi from Cloudflare and remove its local connector token? The tunnel and DNS records will remain in your Cloudflare account.')) return;
		disconnect.disabled = true; message.textContent = 'Disconnecting…';
		try { const data = await service('disconnect'); show(data); message.textContent = data.message || 'Disconnected.'; }
		catch (error) { message.textContent = error.message; }
		finally { disconnect.disabled = false; }
	});
service('status').then(show).catch(function (error) {
		status.value = 'Unavailable'; message.textContent = error.message;
	});
})();
</script>
<script>
(function () {
	const status = document.getElementById('supportAccessStatus');
	const configure = document.getElementById('supportAccessConfigureBtn');
	const panel = document.getElementById('supportAccessConfiguration');
	const publicKey = document.getElementById('supportAccessPublicKey');
	const supportURL = document.getElementById('supportAccessURL');
	const copyURL = document.getElementById('supportAccessCopyURLBtn');
	const connectionMode = document.getElementById('supportAccessConnectionMode');
	const hours = document.getElementById('supportAccessHours');
	const enable = document.getElementById('supportAccessEnableBtn');
	const revoke = document.getElementById('supportAccessRevokeBtn');
	const message = document.getElementById('supportAccessMessage');
	let supportEnabled = false;
	if (!status || !configure || !panel) return;
	const configuredRemoteURL = <?php echo json_encode((string) $sdrHostRemote); ?>;
	let secureStationURL = configuredRemoteURL;
	function buildSupportURL() {
		try {
			const candidate = /^https?:\/\//i.test(secureStationURL)
				? secureStationURL : 'https://' + secureStationURL;
			const parsed = new URL(candidate);
			return parsed.protocol === 'https:' ? 'wss://' + parsed.host + '/support-ssh' : '';
		} catch (_) { return ''; }
	}
	async function discoverSecureStationURL() {
		try {
			const response = await fetch('/programs/DuckDNSService.php', {
				method: 'POST', credentials: 'same-origin',
				headers: {'Content-Type': 'application/json', 'X-RigPi-Action': 'duckdns-service'},
				body: JSON.stringify({action: 'status'})
			});
			const data = await response.json().catch(() => ({}));
			if (response.ok && data.enabled && data.remote_url) secureStationURL = data.remote_url;
		} catch (_) {}
		updateURLPreview();
	}
	function displayedSupportURL(data) {
		return data && data.connection_mode === 'quick'
			? (data.support_url || '') : buildSupportURL();
	}
	function updateURLPreview() {
		if (!supportURL) return;
		supportURL.value = connectionMode.value === 'existing' ? buildSupportURL() : '';
		copyURL.disabled = !supportEnabled || !supportURL.value;
		supportURL.placeholder = connectionMode.value === 'quick'
			? 'Created automatically when support is enabled'
			: 'Configure Native HTTPS or Cloudflare Remote Access';
	}
	updateURLPreview();
	discoverSecureStationURL();
	connectionMode.addEventListener('change', updateURLPreview);

	async function service(action) {
		const response = await fetch('/programs/SupportAccessService.php', {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-RigPi-Action': 'support-access'},
			body: JSON.stringify({
				action: action,
				public_key: action === 'enable' ? publicKey.value.trim() : '',
				hours: Number(hours.value),
				connection_mode: connectionMode.value
			})
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(data.error || 'RigPi could not manage support access.');
		return data;
	}
	function show(data) {
		supportEnabled = Boolean(data.enabled);
		if (data.connection_mode === 'quick' || data.connection_mode === 'existing') {
			connectionMode.value = data.connection_mode;
		}
		const ready = data.bridge_active &&
			(data.connection_mode !== 'quick' || Boolean(data.support_url));
		status.value = data.enabled
			? (ready ? 'Remote support ready'
				: (data.connection_mode === 'quick' && data.quick_tunnel_active === false
					? 'Support address unavailable' : 'Creating secure address…'))
			: 'Disabled';
		if (data.enabled && supportURL) supportURL.value = displayedSupportURL(data);
		else if (!data.enabled) updateURLPreview();
		copyURL.disabled = !supportEnabled || !supportURL.value;
		connectionMode.disabled = supportEnabled;
		hours.disabled = supportEnabled;
		enable.disabled = supportEnabled;
		revoke.style.display = data.enabled ? '' : 'none';
		status.title = data.fingerprint ? 'Support key ' + data.fingerprint : '';
		if (data.enabled && data.expires_at) {
			const expires = new Date(data.expires_at);
			message.textContent = 'Support access expires ' + expires.toLocaleString() + '.';
		} else if (!data.message) message.textContent = '';
	}
	async function waitForQuickAddress(data) {
		if (data.connection_mode !== 'quick' || data.support_url) return data;
		for (let attempt = 0; attempt < 30; attempt++) {
			await new Promise(resolve => setTimeout(resolve, 1000));
			data = await service('status'); show(data);
			if (!data.enabled || data.support_url) return data;
		}
		throw new Error('The temporary support address was not created. Try Revoke Now, then enable support again.');
	}
	configure.addEventListener('click', function () {
		panel.style.display = panel.style.display === 'none' ? '' : 'none';
	});
	copyURL.addEventListener('click', async function () {
		if (!supportURL.value) return;
		try {
			if (navigator.clipboard && window.isSecureContext) {
				await navigator.clipboard.writeText(supportURL.value);
			} else {
				supportURL.focus(); supportURL.select();
				document.execCommand('copy');
			}
			message.textContent = 'Support URL copied.';
		} catch (_) { message.textContent = 'Unable to copy automatically. Select and copy the Support URL.'; }
	});
	enable.addEventListener('click', async function () {
		if (!publicKey.value.trim()) { message.textContent = 'Paste the public key supplied by support.'; return; }
		if (connectionMode.value === 'existing' && !buildSupportURL()) {
			message.textContent = 'Configure Native HTTPS or Cloudflare Remote Access first, or use the temporary address.'; return;
		}
		if (!window.confirm('Enable temporary SSH access to this RigPi for the selected time?')) return;
		enable.disabled = true; message.textContent = connectionMode.value === 'quick'
			? 'Creating a secure temporary support address…' : 'Enabling temporary support access…';
		try {
			let data = await service('enable'); publicKey.value = ''; show(data);
			data = await waitForQuickAddress(data); show(data);
			message.textContent = (data.message || 'Enabled.') + ' Expires ' + new Date(data.expires_at).toLocaleString() + '.';
		} catch (error) { message.textContent = error.message; }
		finally { enable.disabled = supportEnabled; }
	});
	revoke.addEventListener('click', async function () {
		if (!window.confirm('Revoke temporary remote support access now?')) return;
		revoke.disabled = true; message.textContent = 'Revoking support access…';
		try { const data = await service('revoke'); show(data); message.textContent = data.message || 'Revoked.'; }
		catch (error) { message.textContent = error.message; }
		finally { revoke.disabled = false; }
	});
	service('status').then(async function (data) {
		show(data);
		if (data.enabled && data.connection_mode === 'quick' && !data.support_url) {
			try { show(await waitForQuickAddress(data)); }
			catch (error) { message.textContent = error.message; }
		}
	}).catch(function (error) {
		status.value = 'Unavailable'; message.textContent = error.message;
	});
	setInterval(function () {
		service('status').then(show).catch(function () {});
	}, 15000);
})();
</script>
<?php endif; ?>
<script>
(function () {
	const targetUserId = <?php echo (int) $tID; ?>;
	const fields = {
		qrz: {username: document.getElementById('qrzUserValue'), password: document.getElementById('qrzPWDValue')},
		hamqth: {username: document.getElementById('hamqthUserValue'), password: document.getElementById('hamqthPWDValue')}
	};
	const element = (selector, provider) => document.querySelector(selector + '[data-provider="' + provider + '"]');

	async function request(provider, action) {
		const values = fields[provider];
		const response = await fetch('/programs/CallbookService.php', {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-RigPi-Action': 'callbook-service'},
			body: JSON.stringify({
				provider: provider, action: action, user_id: targetUserId,
				username: values.username.value.trim(), password: values.password.value
			})
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(data.error || 'RigPi could not manage the callbook service.');
		return data;
	}

	function setStatus(provider, value) {
		const status = element('.callbook-status', provider);
		if (status) status.value = value === 'connected' ? 'Connected' : value === 'configured' ? 'Configured' : 'Not configured';
	}

	document.querySelectorAll('.callbook-configure').forEach(button => button.addEventListener('click', function () {
		const provider = this.dataset.provider;
		const panel = document.getElementById(provider + 'Configuration');
		panel.style.display = panel.style.display === 'none' ? '' : 'none';
	}));

	document.querySelectorAll('.callbook-save').forEach(button => button.addEventListener('click', async function () {
		const provider = this.dataset.provider;
		const message = element('.callbook-message', provider);
		this.disabled = true; message.textContent = 'Saving…';
		try { const data = await request(provider, 'save'); setStatus(provider, data.status); message.textContent = 'Saved.'; }
		catch (error) { message.textContent = error.message; }
		finally { this.disabled = false; }
	}));

	document.querySelectorAll('.callbook-test').forEach(button => button.addEventListener('click', async function () {
		const provider = this.dataset.provider;
		const message = element('.callbook-message', provider);
		this.disabled = true; message.textContent = 'Testing…';
		try { const data = await request(provider, 'test'); setStatus(provider, data.status); message.textContent = data.message || 'Connection succeeded.'; }
		catch (error) { message.textContent = error.message; }
		finally { this.disabled = false; }
	}));

	document.querySelectorAll('.callbook-disconnect').forEach(button => button.addEventListener('click', async function () {
		const provider = this.dataset.provider;
		const label = provider === 'qrz' ? 'QRZ' : 'HamQTH';
		if (!window.confirm('Disconnect ' + label + ' and remove its saved credentials from this RigPi user?')) return;
		const message = element('.callbook-message', provider);
		this.disabled = true; message.textContent = 'Disconnecting…';
		try {
			const data = await request(provider, 'disconnect');
			fields[provider].username.value = ''; fields[provider].password.value = '';
			setStatus(provider, data.status); message.textContent = 'Disconnected.';
		} catch (error) { message.textContent = error.message; }
		finally { this.disabled = false; }
	}));
})();
</script>
<script>
(function () {
	const targetUserId = <?php echo (int) $tID; ?>;
	const status = document.getElementById('pushoverServiceStatus');
	const configure = document.getElementById('pushoverConfigureBtn');
	const panel = document.getElementById('pushoverConfiguration');
	const token = document.getElementById('pushoverToken');
	const user = document.getElementById('pushoverUser');
	const notify = document.getElementById('pushoverNotifyValue');
	const delay = document.getElementById('pushoverDelay');
	const message = document.getElementById('pushoverServiceMessage');
	const save = document.getElementById('pushoverSaveBtn');
	const test = document.getElementById('pushoverTestBtn');
	const disconnect = document.getElementById('pushoverDisconnectBtn');
	if (!status || !configure || !panel || targetUserId < 1) return;

	async function service(action) {
		const response = await fetch('/programs/PushoverService.php', {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-RigPi-Action': 'pushover-service'},
			body: JSON.stringify({
				action: action, user_id: targetUserId,
				token: token ? token.value.trim() : '', user: user.value.trim(),
				notify: notify.value || 'none', delay: Number(delay.value) || 0
			})
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(data.error || 'RigPi could not manage Pushover.');
		return data;
	}

	function showStatus(value) {
		status.value = value === 'connected' ? 'Connected'
			: value === 'configured' ? 'Configured' : 'Not configured';
	}
	function syncChecks(value) {
		const has = name => value === name || value === 'both+spots'
			|| (name === 'login' && value === 'both')
			|| (name === 'logout' && value === 'both')
			|| (name === 'login' && value === 'login+spots')
			|| (name === 'logout' && value === 'logout+spots')
			|| (name === 'spots' && /spots/.test(value));
		const login = document.getElementById('notifyLogin');
		const logout = document.getElementById('notifyLogout');
		const spots = document.getElementById('notifySpots');
		if (login) login.checked = has('login');
		if (logout) logout.checked = has('logout');
		if (spots) spots.checked = has('spots');
	}
	configure.addEventListener('click', function () {
		panel.style.display = panel.style.display === 'none' ? '' : 'none';
	});
	save.addEventListener('click', async function () {
		save.disabled = true; message.textContent = 'Saving…';
		try { const data = await service('save'); showStatus(data.status); message.textContent = data.message || 'Saved.'; }
		catch (error) { message.textContent = error.message; }
		finally { save.disabled = false; }
	});
	test.addEventListener('click', async function () {
		test.disabled = true; message.textContent = 'Sending test…';
		try { const data = await service('test'); showStatus(data.status); message.textContent = data.message || 'Test sent.'; }
		catch (error) { message.textContent = error.message; }
		finally { test.disabled = false; }
	});
	disconnect.addEventListener('click', async function () {
		if (!window.confirm('Disconnect Pushover and remove this user’s saved Pushover configuration?')) return;
		disconnect.disabled = true; message.textContent = 'Disconnecting…';
		try {
			const data = await service('disconnect');
			if (token) token.value = ''; user.value = ''; notify.value = 'none'; delay.value = '60';
			syncChecks('none');
			showStatus(data.status); message.textContent = 'Disconnected.';
		} catch (error) { message.textContent = error.message; }
		finally { disconnect.disabled = false; }
	});
	service('status').then(function (data) {
		showStatus(data.status);
		if (data.notify) notify.value = data.notify;
		if (Number.isFinite(Number(data.delay))) delay.value = String(data.delay);
		syncChecks(notify.value);
	}).catch(function (error) {
		status.value = 'Unavailable'; message.textContent = error.message;
	});
})();
</script>
<?php if ((int) $level === 1): ?>
<script>
(function () {
	const targetUserId = <?php echo (int) $tID; ?>;
	const status = document.getElementById('emailServiceStatus');
	const configure = document.getElementById('emailConfigureBtn');
	const panel = document.getElementById('emailConfiguration');
	const provider = document.getElementById('emailProvider');
	const host = document.getElementById('emailSMTPHost');
	const port = document.getElementById('emailSMTPPort');
	const encryption = document.getElementById('emailSMTPEncryption');
	const username = document.getElementById('emailSMTPUsername');
	const password = document.getElementById('emailSMTPPassword');
	const fromEmail = document.getElementById('emailFromAddress');
	const fromName = document.getElementById('emailFromName');
	const recipient = document.getElementById('emailRecipient');
	const message = document.getElementById('emailServiceMessage');
	const save = document.getElementById('emailSaveBtn');
	const test = document.getElementById('emailTestBtn');
	const disconnect = document.getElementById('emailDisconnectBtn');
	if (!status || !configure || !panel || targetUserId < 1) return;

	const presets = {
		gmail: {host: 'smtp.gmail.com', port: 587, encryption: 'starttls'},
		outlook: {host: 'smtp.office365.com', port: 587, encryption: 'starttls'},
		yahoo: {host: 'smtp.mail.yahoo.com', port: 465, encryption: 'tls'}
	};
	async function service(action) {
		const response = await fetch('/programs/EmailService.php', {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-RigPi-Action': 'email-service'},
			body: JSON.stringify({
				action: action, user_id: targetUserId, provider: provider.value,
				host: host.value.trim(), port: Number(port.value), encryption: encryption.value,
				username: username.value.trim(), password: password.value,
				from_email: fromEmail.value.trim(), from_name: fromName.value.trim()
			})
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(data.error || 'RigPi could not manage outgoing email.');
		return data;
	}
	function showStatus(value) {
		status.value = value === 'connected' ? 'Connected'
			: value === 'configured' ? 'Configured' : 'Not configured';
	}
	function show(data) {
		showStatus(data.status);
		if (data.provider) provider.value = data.provider;
		if (data.host !== undefined) host.value = data.host;
		if (Number(data.port)) port.value = String(data.port);
		if (data.encryption) encryption.value = data.encryption;
		if (data.username !== undefined) username.value = data.username;
		if (data.from_email !== undefined) fromEmail.value = data.from_email;
		if (data.from_name !== undefined) fromName.value = data.from_name || 'RigPi';
		if (data.recipient !== undefined) recipient.value = data.recipient;
		password.value = '';
		password.placeholder = data.password_configured ? 'Saved — leave blank to keep' : 'App password';
	}
	provider.addEventListener('change', function () {
		const preset = presets[this.value];
		if (!preset) return;
		host.value = preset.host;
		port.value = String(preset.port);
		encryption.value = preset.encryption;
		if (!fromEmail.value && username.value.indexOf('@') > 0) fromEmail.value = username.value;
	});
	username.addEventListener('change', function () {
		if (!fromEmail.value && this.value.indexOf('@') > 0) fromEmail.value = this.value.trim();
	});
	configure.addEventListener('click', function () {
		panel.style.display = panel.style.display === 'none' ? '' : 'none';
	});
	save.addEventListener('click', async function () {
		save.disabled = true; message.textContent = 'Saving…';
		try {
			const data = await service('save');
			showStatus(data.status); password.value = ''; password.placeholder = 'Saved — leave blank to keep';
			message.textContent = data.message || 'Saved.';
		} catch (error) { message.textContent = error.message; }
		finally { save.disabled = false; }
	});
	test.addEventListener('click', async function () {
		test.disabled = true; message.textContent = 'Sending test…';
		try {
			const data = await service('test'); showStatus(data.status);
			message.textContent = data.message || 'Test sent.';
		} catch (error) { message.textContent = error.message; }
		finally { test.disabled = false; }
	});
	disconnect.addEventListener('click', async function () {
		if (!window.confirm('Disconnect outgoing email and remove the saved SMTP credentials from this RigPi?')) return;
		disconnect.disabled = true; message.textContent = 'Disconnecting…';
		try {
			const data = await service('disconnect');
			provider.value = 'custom'; host.value = ''; port.value = '587'; encryption.value = 'starttls';
			username.value = ''; password.value = ''; fromEmail.value = ''; fromName.value = 'RigPi';
			showStatus(data.status); message.textContent = 'Disconnected.';
		} catch (error) { message.textContent = error.message; }
		finally { disconnect.disabled = false; }
	});
	service('status').then(show).catch(function (error) {
		status.value = 'Unavailable'; message.textContent = error.message;
	});
})();
</script>
<?php endif; ?>
<script>
// Browsers may restore an old form value from their back/forward cache after
// PHP renders the current database value. Keep SDR Remote authoritative.
(function () {
	const savedSDRRemote = <?php echo json_encode(
     (string) $sdrHostRemote,
     JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
 ); ?>;
	window.addEventListener('pageshow', function () {
		const input = document.getElementById('sdrHostRemoteValue');
		if (input) input.value = savedSDRRemote;
	});
})();
</script>
</body>
</html>
