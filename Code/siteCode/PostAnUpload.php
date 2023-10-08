<?php
/* 
 * PostAnUpload.php - this is the handler for all file uploads (RSIND and OW results). It is 
 * requested by Upload.html after the user fills out a simple form supplying:
 *		- the desired type of file(s) to be uploaded, and
 *		- the user's credentials.
 * This program will validate the type of file to be uploaded, and then confirm that a 
 * user with the supplied credentials has the right to do the requested upload. If both
 * tests are passed then this program will redirect the user's browser to the appropriate
 * program to perform one or more uploads.
**
** When this file is requested we'll first look to see if there is any POST data.  If there
** is NOT then that means it was probably requested this way:
**		https://HostingPacmastersServer.org/whatever/blah/blah/PostAnUpload.php
** i.e. by a human via a browser.  In that case this is recognized as an illegal access
** (i.e. a hack) and we'll give the user an error that pretends that what they are doing
** just isn't recognized. If there is POST data we'll confirm the correct data is supplied
** as described above. If the POSTed data is not valid then we'll assume a hack.
 */

// Copyright (c) 2022 Bob Upshaw.  This software is covered under the Open Source MIT License

error_reporting( E_ERROR & E_PARSE & E_NOTICE & E_CORE_ERROR & E_DEPRECATED & E_COMPILE_ERROR &
	E_RECOVERABLE_ERROR & E_ALL );

//define( "DEBUG", "1" );		// 0=no debugging, >0 turn on debugging
session_start();
require_once "/usr/home/pacdev/Automation/PMSUpload/Code/lib/LocalSupport.php";
$localProps = LS_ReadLocalProps();
require_once $localProps[0];		// e.g. UploadSupport.php from somewhere... defines DEBUG

$scriptName = $_SERVER['SCRIPT_NAME'];
if( DEBUG ) {
	error_log( "----> Entered $scriptName\n" );
}


// initialization...
// If we get far enough along with this process where we have a good idea of who the user is that
// is trying to upload a file we will remember their full name here:
$UsersFullName = "(unknown user)";		// full name of valid UserName (if valid)

// thie following ini_set is there to allow us to read files with \r line termination!
ini_set("auto_detect_line_endings", true);

// A list of permitted file extensions
//$allowed = array('txt', 'csv', 'xlsx','xls');
// moved...  $allowed = array('csv');		// this is all we currently accept for RSIND files

// what is the current date and time?
$timeZoneObj = new DateTimeZone( "EST" );
$currentDateTimeObj = new DateTime( "now", $timeZoneObj );
$currentDateTimeStr = $currentDateTimeObj->format( "jMY g:i:sA e");

// set up a simple log file:
$logHandle = LS_OpenLogFile( __FILE__ );
fwrite( $logHandle, "\n\n--> Begin New Entry: $currentDateTimeStr\n" );

////////////////////////////////////////////////////////////////////////////////////////////////
// See if the request of this program is from a human user or the result of a POST:
////////////////////////////////////////////////////////////////////////////////////////////////

if( DEBUG ) {
	error_log( "ready to test post");
}

if( !empty( $_POST ) ) {
	// Non-empty POST - the only way this should happen is if a human requested this page through
	// our custom upload html page.  We need to confirm that before letting someone upload
	// a new RSIND or OW file.
	$uploadType = $_POST['uploadType'];
	if( ! isset( $uploadType ) ) {
		$uploadType = "(unspecified)";
	}
	$UserName = $_POST['UserName'];		// this is really the user's login key, e.g. "keyy23"
	$year = $_POST['year'];		// only available for OW upload
	if( DEBUG ) {
		error_log( "got a post:");
		error_log( "post is: " . var_export( $_POST, true ) );
		error_log( "uploadType is: '$uploadType', UserName='$UserName'" );
	}
	if( !empty( $UserName ) ) {
		if( DEBUG ) {
			error_log( "got a User Name: '$UserName'");
		}
		$UsersFullName = $UserName;			// initialize
		list( $isValidRequest, $fullName, $obUserName ) = US_ValidateUserName( $UserName, $uploadType );
		if( DEBUG ) {
			error_log( "Valid request? '$isValidRequest', fullName='$fullName'");
		}
		if( $fullName != "" ) {
			$UsersFullName = $fullName;
		}
		if( !$isValidRequest ) {
			// Invalid access - blow them off with a bogus error message so they don't realize they almost
			// got in!
			US_InvalidRequest( $obUserName, "", $UserName, $uploadType, $scriptName . "-invalid user name", 1 );
			exit;
		} else {
			if( DEBUG ) {
				error_log( "This is a valid request, UsersFullName='$UsersFullName', " .
					"obUserName='$obUserName'\n" );
			}
			fwrite( $logHandle, "This is a valid request, UsersFullName='$UsersFullName', " .
					"obUserName='$obUserName', uploadType='$uploadType'\n" );
			
			// now generate a valid key for this user...
			$validKey = US_GenerateValidKey();
			// ...and encrypt it:
			$encryptedValidKey = US_GenerateBuriedKey( $validKey, true );
			// Done analyzing the POSTed data - fall through and handle the request
		}
	} else {
		# if we got here then there was a post but nothing recognized was posted
		if( DEBUG ) {
			error_log( "The post had no UserName!\n" );
		}
		US_InvalidRequest( "(no value)", "", "(no passed key)", $uploadType, $scriptName . "-no user name", 1 );
		exit;
	}
	
	////////////////////////////////////////////////////////////////////////////////////////////////
	// The user requested this page - draw a drop area and let the user make the next move:
	////////////////////////////////////////////////////////////////////////////////////////////////
	$_SESSION['currentDateTimeStr'] = $currentDateTimeStr;
	if( $uploadType == "RSIND" ) {
		if( DEBUG > 1 ) {
			error_log( "Redirect the user to RSIND\n" );
		}
		$_SESSION['uploadType'] = "RSIND";
		$_SESSION['UserName'] = $UserName;
		$_SESSION['UsersFullName'] = $UsersFullName;
		$_SESSION['encrypted'] = $encryptedValidKey;
		$_SESSION['obUserName'] = $obUserName;
		US_GeneratePageHead( "RSIND" );
		?>
		<script>
			window.location.replace( "Rsind.php" );
		</script>
		</head>
		<body> </body>
		</html>
		<?php
		exit;
	} elseif( $uploadType == "OW" ) {
		if( DEBUG > 1 ) {
			error_log( "Redirect the user to OW (OwStart.php)\n" );
		}
		$_SESSION['uploadType'] = "OW";
		$_SESSION['UserName'] = $UserName;
		$_SESSION['year'] = $year;
		$_SESSION['UsersFullName'] = $UsersFullName;
		$_SESSION['encrypted'] = $encryptedValidKey;
		US_GeneratePageHead( "OW" );
		?>
		<script>
			window.location.replace( "OwStart.php" );
		</script>
		</head>
		<body> </body>
		</html>
		<?php
		exit;
	}
}
////////////////////////////////////////////////////////////////////////////////////////////////
// If we got here there was no POST data - assume a hack:
////////////////////////////////////////////////////////////////////////////////////////////////

US_InvalidRequest( "(no value)", "", "(no passed data)", $uploadType, $scriptName . "-nothing posted", 1 );
exit;


////////////////////////////////////////////////////////////////////////////////////////////////
// END OF MAIN PROGRAM - Support PHP functions
////////////////////////////////////////////////////////////////////////////////////////////////








?>
