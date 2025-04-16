<?php
/*
** owStart.php - this page handles a submit from the main Upload page when the requested
** upload is for an OW result file. This page will be used to show the user what OW race 
** results are available to submit and ask the user what result file they want to upload.
**
** NOTE: For DEBUGGING see lib/UploadSupport.php and read about DEBUG*
**
*/
session_start();
error_reporting( E_ERROR & E_PARSE & E_NOTICE & E_CORE_ERROR & E_DEPRECATED & E_COMPILE_ERROR &
	E_RECOVERABLE_ERROR & E_ALL );
// thie following ini_set is there to allow us to read files with \r line termination!
ini_set("auto_detect_line_endings", true);

require_once "/usr/home/pacdev/Automation/PMSUpload/Code/lib/LocalSupport.php";
$localProps = LS_ReadLocalProps();
require_once $localProps[0];		// UploadSupport.php, set DEBUG

$scriptName = "OwStart.php";
if( DEBUG ) {
	error_log( "Entered $scriptName\n" );
}

// Although the normal path this this script will have already checked that the user is
// valid, we're going to check again just in case someone invoked this script directly.
$UserName = $_SESSION['UserName'];
$encrypted = $_SESSION['encrypted'];
$obUserName = $_SESSION['obUserName'];
// analyze the stored $encrypted to see if the key associated with this session is valid:
$isValidKey = US_TestValidKey( $encrypted );
if( $isValidKey > 0 ) {
	// not a valid key
	if( $isValidKey > 1 ) {
		// key too old
		if( DEBUG ) {
			error_log( "key too old!" );
		}
		US_ExpiredKey( $uploadType, $scriptName );
		exit;
	} else {
		// invalid key (probably missing)
		US_InvalidRequest( $obUserName, "", $UserName, $uploadType, $scriptName, 1 );
		exit;
	}
}





// initialization...
// get full path name to the OW properties file:
$OWPropsFullPath = $localProps[3];
// the above full path name doesn't have the correct year in it yet...
$OWPropsFullPath = str_replace( "{CurrentYear}", $yearBeingProcessed, $OWPropsFullPath );

// now read and store the 'calendar' from the OW properties file. What we'll have is the
// following array of arrays:
// $OWProps[n] = n-th result file, n starting at 0.
// $OWProps[n]["filename"] = a partial path name of the file that we'll eventually store
// $OWProps[n]["cat"] = category of race n (1 or 2)
// $OWProps[n]["date"] = date of race n
// $OWProps[n]["distance"] = distance of race n in miles
// $OWProps[n]["name"] = name of race n (e.g. "Lake Berryessa 1 Mile")
// $OWProps[n]["unique"] = Unique id for race n 
// $OWProps[n]["keyword"] = keyword for race n (e.g. "Berryessa") or empty string
// 
//... note: any of the following can be empty...
// $OWProps[n]["locFromName"] = "keyword", e.g. "Berryessa" or "Cruz"
// $OWProps[n]["distFromName"] = "distance", e.g. "1Mile" or "2.5k"
// $OWProps[n]["catFromName"] = "category", e.g. "cat 1" or "Category2"
// 
$OWProps = array();
$OWProps = ReadAndStoreCalendar( $OWPropsFullPath, $OWProps );

// Next, draw a page showing all the results we're waiting for and then let the user
// select the race for which they have results to upload:
US_GeneratePageHead( "OW" );
US_GeneratePageMiddle();

// NOTE: the $deleteButton is defined if this file is included by OW.php after a user requested
// a delete.
DrawRaces( $OWProps, $deleteButton );
US_GeneratePageEnd();

// Before we're done here we need to make all of the OWProps available to the other page handlers.
$_SESSION['OWProps'] = $OWProps;

// all done - it's up to the user now!

exit;

/*
** ReadAndStoreCalendar - read the OW properties file pointed to by the passed full path 
**		and construct an array of arrays holding the 'calendar' property.
**
** PASSED:
**	$OWPropsFullPath -
**	$OWProps - empty array which will be returned populated with data - see RETURNED below.
**
** RETURNED:
**	$OWProps - an array of arrays. See comments near the call.
**
*/
define( "WAITING_FOR_CALENDAR", 1 );
define( "PROCESSING_CALENDAR", 2 );
define( "PROCESSING_SKIP", 3 );

function ReadAndStoreCalendar( $OWPropsFullPath, array &$OWProps ) {
	$props = fopen( $OWPropsFullPath, "r" ) or die ("Unable to open " . $OWPropsFullPath . " - ABORT!" );
	$state = WAITING_FOR_CALENDAR;
	$previousState = 0;
	while( ($line = fgets( $props )) !== false ) {
		if( DEBUG > 99 ) {
			error_log( "owStart.php::ReadAndStoreCalendar(): Initial line='$line'\n" );
		}
		// remove trailing whitespace:
		$line = rtrim( $line );
		// remove comments
		$line = preg_replace( "/\s*#.*$/", "", $line );
		// remove leading space
		$line = preg_replace( "/^\s+/", "", $line );
		// remove trailing space
		$line = preg_replace( "/\s+$/", "", $line );
		if( DEBUG > 99 ) {
			error_log( "owStart.php::ReadAndStoreCalendar(): Clean Initial line='$line'\n" );
		}
		// handle a continuation line:
		while( preg_match( "/\\\s*$/", $line ) ) {
			if( DEBUG > 99 ) {
				error_log( "owStart.php::ReadAndStoreCalendar(): the line '$line' has a continuation\n" );
			}
			// this line continues with the next line
			// replace (optional) whitespace followed by continuation char with a single space
			$line = preg_replace( "/\s*\\\s*$/", " ", $line );
			// now read the continuation line
			if( ($nextLine = fgets( $props )) !== false ) {
				if( DEBUG > 99 ) {
					error_log( "owStart.php::ReadAndStoreCalendar(): Initial continuation line='$nextLine'\n" );
				}
				// remove trailing whitespace:
				$nextLine = rtrim( $nextLine );
				// remove comments
				$nextLine = preg_replace( "/\s*#.*$/", "", $nextLine );
				// remove leading space
				$nextLine = preg_replace( "/^\s+/", "", $nextLine );
				// remove trailing space
				$nextLine = preg_replace( "/\s+$/", "", $nextLine );
				// now append the continuation line with the previous line(s):
				if( DEBUG > 99 ) {
					error_log( "owStart.php::ReadAndStoreCalendar(): Final continuation line='$nextLine'\n" );
				}
				$line .= $nextLine;
				// Note that $line may end with a continuation character. In that case
				// the above while() will find it and get another line.
			} else {
				// hit an EOF - take what we've got and process it
			}
		} // end of while( preg_match( ...
		// ignore blank or empty lines
		if( preg_match( "/^\s*$/", $line ) ) continue;
		if( DEBUG > 99 ) {
			error_log( "owStart.php::ReadAndStoreCalendar(): Initial line+all continuation='$line'\n" );
		}
		// we have a non-blank, non-empty, non-comment line
		if( preg_match( "/^>skip/", $line ) ) {
			$previousState = $state;
			$state = PROCESSING_SKIP;
			continue;
		} elseif( preg_match( "/^>endskip/", $line ) ) {
			$state = $previousState;
			$previousState = 0;
			continue;
		} elseif( $state == WAITING_FOR_CALENDAR ) {
			// did we find the beginning of the calendar?
			if( preg_match( "/^>calendar/", $line ) ) {
				// yes!
				$state = PROCESSING_CALENDAR;
				if( DEBUG > 99 ) {
					error_log( "owStart.php::ReadAndStoreCalendar(): State set to PROCESSING_CALENDAR\n" );
				}
			}
		} elseif( $state == PROCESSING_CALENDAR ) {
			// since we have a non-blank, non-empty, non-comment line this must be a calendar entry
			// OR the end of the calendar block
			if( preg_match( "/^>endcalendar/", $line ) ) {
				if( DEBUG > 99 ) {
					error_log( "owStart.php::ReadAndStoreCalendar(): found end of calendar\n" );
				}
				break;
			}
			ProcessCalendarLine( $line, $OWProps );
		}
	}
	fclose( $props );
	if( DEBUG > 99 ) {
		error_log( "owStart.php::ReadAndStoreCalendar(): end.\n" );
	}
	return $OWProps;
} // end of ReadAndStoreCalendar()

/*
** ProcessCalendarLine - processed the one single calendar line and store the relevant information
**	into the passed array.
**
** PASSED:
**	$line - the calendar entry
**	$OWProps - the array into which our array of information will be stored.
**
** RETURNED:
**	The passed $OWProps is modified by this routine.
**
*/
function ProcessCalendarLine( $line, array &$OWProps ) {
	$data = array();
	$version = 1;		#default
	if( DEBUG > 98 ) {
		error_log( "owStart.php::ProcessCalendarLine(): entered with line='$line'.\n" );
	}
	// split the passed line into fields:
	$arrOfFields = preg_split( "/\s*->\s*/", $line );
	// does this line begin with a version number?
	if( preg_match( "/^\d+$/", $arrOfFields[0] ) ) {
		// yes!
		$version = $arrOfFields[0];
		array_shift( $arrOfFields );
	}
	if( $version == 1 ) {
		[$fileName, $cat, $date, $distance, $eventName, $uniqueID, $keyword] = 
			$arrOfFields;
		if( DEBUG > 98 ) {
			error_log( "owStart.php::ProcessCalendarLine(): version 1, fileName='$fileName'.\n" );
		}
		$data["filename"] = $fileName;
		$data["cat"] = $cat;
		$data["date"] = $date;
		$data["distance"] = $distance;
		$data["name"] = $eventName;
		$data["unique"] = trim( $uniqueID );
		if( ! isset( $keyword ) ) {
			$keyword = "";
		}
		$data["keyword"] = strtolower( trim( $keyword ) );
	} else if( $version == 2 ) {
		[$eventName, $cat, $date, $distance, $uniqueID, $keyword, $fileName, $link] =
			$arrOfFields;
		if( DEBUG > 98 ) {
			error_log( "owStart.php::ProcessCalendarLine(): version 2, fileName='$fileName'.\n" );
		}
		$data["filename"] = $fileName;
		$data["cat"] = $cat;
		$data["date"] = $date;
		$data["distance"] = $distance;
		$data["name"] = $eventName;
		$data["unique"] = trim( $uniqueID );
		$data["keyword"] = strtolower( trim( $keyword ) );
	} else {
		error_log( "owStart.php::ProcessCalendarLine(): invalid version: '$version'.\n" );
	}
	array_push( $OWProps, $data );
	if( DEBUG > 98 ) {
		error_log( "owStart.php::ProcessCalendarLine(): done.\n" );
	}
} // end of ProcessCalendarLine()


/*
** DrawRaces - generate the html to draw a form for the user to use to tell us for which race
**		they are uploading the results.
**
** PASSED:
**	$OWProps - array of calendar entries, each entry representing an OW race. In order of competition.
**	$deleteButton - if set then we got here after deleting an uploaded file.
**
** RETURNED:
**	n/a
**
** NOTES:
**	After calling this routine the full form, with the appropriate action and submit button, will
**	have been generated. All that's necessary is to complete the page:
**	PRIOR TO CALLING THIS ROUTINE: supply the right title, instructions, jpegs, etc.
**	AFTER CALLING THIS ROUTINE: supply the correct web page ending.
**		
*/
function DrawRaces( array $OWProps, $deleteButton ) {
	global $destinationDirArchive;
	if( DEBUG > 99 ) {
		error_log( "owStart.php::DrawRaces(): entered." );
	}
	if( isset( $deleteButton ) ) {
		?>
		<form style="margin-left:40px" id="OWResult" method="post" action="OW.php" enctype="multipart/form-data">
		<p>You have successfully deleted "<?php echo $deleteButton ?>" from the Upload location.<br>
		Next,
		Select the event for which you want to upload results:</p><?php
	} else {
		?>
		<form style="margin-left:60px " id="OWResult" method="post" action="OW.php" enctype="multipart/form-data">
		<h3>Select the event for which you want to upload results:</h3>
		<?php
	}
	
	$count = 0;
	foreach( $OWProps as $data ) {
		$label = $data['name'] . " (cat " . $data['cat'] . ") held on " . $data['date'];
		$pretag = US_ComputeSavedFilePretag( $data['unique'], $data['cat'], 
			preg_replace( "/\s/", "", $data['name'] ) );
		$pretag = US_SanatizeRegEx( $pretag );
		list( $existingFileName, $existingFileDate) = 
			US_FileExistsWithThisPretag( $pretag, $destinationDirArchive,  );
		$details = "";
		if( $existingFileName != "" ) {
			$details = "&nbsp;&nbsp;&nbsp; $existingFileName was uploaded on $existingFileDate";
		}
//		<input style="margin-bottom: 10px; margin-left: 40px" type="radio" name="eventNum" value=<?php


		// special case: no details yet for this one?
		$nodetails = "";
		$disabled = "";
		if( $data['distance'] == 0 ) {
			$nodetails = " (no details available - this event is disabled for now.)";
			$disabled = " disabled ";
		}
		$label .= " " . $nodetails . " ";
		?>
		<label>
		<input <?php print $disabled; ?>
			style="margin-bottom: 10px; margin-left: 40px" type="radio" name="eventNum" value=<?php
			print "'$count' >$label
		</label> $details<br>";
		$count++;
	}

	?>
	<p>
		<input type="submit" value="Proceed to Download" />
	</form>
	<?php

	if( DEBUG > 99 ) {
		error_log( "owStart.php::DrawRaces(): exit." );
	}
} // end of DrawRaces()





?>