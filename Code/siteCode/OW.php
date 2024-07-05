<?php
/*
** OW.php - this page handles a submit from the main Upload page when the requested
** upload is for an OW result file. This page will continue to be requested for every file 
** uploaded (or attempted). It is redirected to by OwStart.php which is redirected to by
** PostAnUpload.php.
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

$scriptName = "OW.php";
if( DEBUG ) {
	error_log( "----> Entered $scriptName, yearBeingProcessed='$yearBeingProcessed', DEBUG='" .
		DEBUG . "'\n" );
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

$path = realpath( dirname( "__FILE__" ) );
$logHandle = LS_OpenLogFile( $path );
$OWProps = $_SESSION['OWProps'];
$currentDateTimeStr = $_SESSION['currentDateTimeStr'];
$eventNum = -1;

if( DEBUG ) {
	error_log( "SESSION: uploadType='$uploadType', UserName='" . substr( $UserName, 0, 3 ) . "...', " .
		"encrypted='$encrypted', UsersFullName='$UsersFullName'" );
	if( DEBUG > 1 ) {
		if( !empty( $_GET ) ) {
			error_log( "get is: " . var_export( $_GET, true ) );
		}
		if( !empty( $_POST ) ) {
			error_log( "post 1 is: " . var_export( $_POST, true ) );
		}
		if( !empty( $_FILES ) ) {
			error_log( "files is: " . var_export( $_FILES, true ) );
		}
	}
	if( DEBUG > 99 ) {
		if( !empty( $_SESSION ) ) {
			error_log( "SESSION is: " . var_export( $_SESSION, true ) );
		}
	}

}

// A list of permitted file extensions
//$allowed = array('txt', 'csv', 'xlsx','xls');
$allowed = array('csv');		// this is all we currently accept for OW result files

// If we have a OW result file that we need to test we'll need an area to store some temporary files.
// We'll define that area here, and the files, too:
// temporary directory:
$OWPointsTmpDirName = "/tmp/UploadTmpDir-" . getmypid();
error_log( "OW.php: OWPointsTmpDirName='$OWPointsTmpDirName'");

// temporary file to control our use of GenerateOWResults.pl:
$OWPointsTmpCalendarEntry = "$OWPointsTmpDirName/UploadTmpFile";
// temporary file into which we store the STDOUT of GenerateOWResults.pl if/when invoked:
$OWPointsStdout = "$OWPointsTmpDirName/Stdout";
// the GenerateOWResults.pl log file:
$OWPointsLogFileName = $OWPointsTmpDirName . "/" . $yearBeingProcessed . "PacMastersGenerateOWResultsLog.txt";

if( DEBUG > 1 ) {
	error_log( "OW.php: ready to test FILES");
}

if( isset($_FILES['files']) ) {

	////////////////////////////////////////////////////////////////////////////////////////////////
	// The user has dropped a file to be uploaded.
	////////////////////////////////////////////////////////////////////////////////////////////////

	$originalFileName = $_FILES['files']['name'][0];
	// convert the file name to replace whitespace with underscore and remove brackets, braces, and parens:
	$convertedFileName = preg_replace( "/\s/", "_", $originalFileName );
	$convertedFileName = preg_replace( "/[\(\)]/", "", $convertedFileName );
	$convertedFileName = preg_replace( "/[\{\}]/", "", $convertedFileName );
	$convertedFileName = preg_replace( "/[\[\]]/", "", $convertedFileName );
	

	if( DEBUG ) {
		error_log( "got files:");
		error_log( "get is: " . var_export( $_GET, true ) );
		error_log( "post 2 is: " . var_export( $_POST, true ) );
		error_log( "files is: " . var_export( $_FILES, true ) );
		if( $originalFileName == $convertedFileName ) {
			$sameNames="(The same)";
		} else {
			$sameNames = "(NOT the same)";
		}
		error_log( "originalFileName='$originalFileName', convertedFileName='$convertedFileName' $sameNames");
	}
	
	fwrite( $logHandle, "OW.php: got FILEs: " );
	if( $originalFileName != $convertedFileName ) {
		fwrite( $logHandle, "'$originalFileName' (aka '$convertedFileName')\n" );
	} else {
		fwrite( $logHandle, "'$originalFileName'\n" );
	}


	if( $_FILES['files']['error'][0] === UPLOAD_ERR_OK ) {
		if( DEBUG > 1 ) {
			error_log( "Uploading successfully done" );
		}
		//uploading successfully done
		// First make sure it's a file type that we're expecting:
		$extension = pathinfo($originalFileName, PATHINFO_EXTENSION);
		if(!in_array(strtolower($extension), $allowed)){
			$elements = implode( ", ", $allowed );
			if( $originalFileName != $convertedFileName ) {
				SetError( "The file $originalFileName (aka '$convertedFileName') has an invalid extension " .
					 "(only '$elements' allowed) - upload aborted!" );
			} else {
				SetError( "The file $originalFileName has an invalid extension " .
					 "(only '$elements' allowed) - upload aborted!" );
			}
			fwrite( $logHandle, "File rejected: invalid extension.\n" );
			exit;
		}
		
		// Next, do a sanity check on the file name to prevent uploading the results for one event as
		// the results for a different event (e.g. Keller results for Berryessa results)
		$eventNum = $_SESSION['eventNum'];
		//$OWProps = $_SESSION['OWProps'];
		list( $keywordFromFileEntry, $distFromFileName, $catFromFileName ) = 
			GetKeysFromFileName( $convertedFileName, $OWProps, $eventNum );
			
		if( DEBUG > 2 ) {
			error_log( "**** Uploaded results for event #$eventNum: '" . $OWProps[$eventNum]['filename'] . "',\n" .
				"    cat: " . $OWProps[$eventNum]['cat'] . ", distance: " . $OWProps[$eventNum]['distance'] . 
				" miles." );
			error_log( "Analyzed results file '$convertedFileName' and got this:" );
			error_log( "  keywordFromFileEntry: '$keywordFromFileEntry', distFromFileName: '$distFromFileName', " .
				"catFromFileName: '$catFromFileName'" );
		}
			
		$fileNameProblem = ValidateDroppedFileName( $eventNum, $OWProps, $convertedFileName, $keywordFromFileEntry, 
			$distFromFileName, $catFromFileName );
		if( $fileNameProblem ) {
			$selectedEventName = $OWProps[$eventNum]["name"];
			if( $originalFileName != $convertedFileName ) {
				$errStr = "The file '$originalFileName' (aka '$convertedFileName') DOES NOT look like it contains " .
					 "results for '$selectedEventName' - upload aborted!";
			} else {
				$errStr = "The file '$originalFileName' DOES NOT look like it contains " .
					 "results for '$selectedEventName' - THEREFORE THIS UPLOAD WAS ABORTED!";
			}
			$errStr .= "<br>$fileNameProblem";
			SetError( array( $errStr, 
				"To correct this please upload the correct file OR " .
				"rename your file to be more descriptive of the event.") );
			fwrite( $logHandle, "File rejected: file name ($originalFileName) not consistent with '$selectedEventName'.\n" );
			exit;
		}
		
		// Next, move the uploaded file from the temp location to the location we really want to use:
		$message = US_CreateDirIfNecessary( $destinationDirTmp);
		if( $message != "" ) {
			// failed to create necessary directory
			SetError( $message );
			exit;
		}

		if( ! is_uploaded_file( $_FILES['files']['tmp_name'][0] ) ) {
			// this is possibly someone trying to feed us a bogus file...
			error_log( "The attempted \"UPLOADED\" file was not a result of an HTTP Post - abort this upload!" );
			error_log( "The attempted \"UPLOADED\" file was this: " . var_export( $_FILES, true ) );
			// pretend it's our fault and that we're not hip to this mischevious user...
			SetError( "Internal Error: 4093. Upload failed." );
			exit;
		}
		
		if(move_uploaded_file($_FILES['files']['tmp_name'][0], $destinationDirTmp.$convertedFileName)) {
			// We've got the uploaded file.  Do some simple validation before we decide whether or not
			// we'll keep this file:
			list( $arrOfLines, $status ) = ValidateOWFile( $destinationDirTmp, $convertedFileName, $OWProps, $eventNum );
			array_unshift( $arrOfLines, "(Number of errors found: $status)\n\n" );
//			array_unshift( $arrOfLines, "(Number of errors found: $status)", "  " );
			$count = count( $arrOfLines );
			if( $status > 0 ) {
				SetError( $arrOfLines );
				exit;
			} elseif( $status < 0 ) {
				// something went wrong with the exec or some other internal error
				SetError( $arrOfLines );
				exit;
			} else {
				// SUCCESS!! archive this newly uploaded OW result file and make it available for points calculations
				$message = US_CreateDirIfNecessary( $destinationDirArchive);
				if( $message ) {
					$arrOfLines[] = $message;
					SetError( $arrOfLines );
				} else {
					$message = ArchiveUploadedFile( $destinationDirTmp, $convertedFileName, 
						$destinationDirArchive, $OWProps, $eventNum );
					if( $message ) {
						$arrOfLines[] = $message;
						SetError( $arrOfLines );
					} else {
						SetSuccess( $arrOfLines );
					}
				}
				exit;
			}
		} else {
			SetError( "The file " . $_FILES['files']['tmp_name'][0] . " failed to upload!" );
			exit;
		}
	} else {
		// The file failed to be uploaded:
		if( DEBUG > 1 ) {
			error_log( "The file failed to be uploaded" );
		}
		$message = US_CodeToMessage( $_FILES['files']['error'][0] );
		SetError( $message );
		exit;
	}

} // end of isset($_FILES['files'... 
elseif( !empty( $_POST ) ) {
	// Non-empty POST - the only way this should happen is if a human requested this page through
	// our custom upload html page.  We need to confirm that before letting someone upload
	// a new RSIND or OW file.
	
	// ????
	/////// authenicate here!



	// here we can either generate an email or generate a log entry or both:
	$emailAck = $_POST['emailAck'];
	if( !empty( $emailAck ) ) {
		$to = $_POST['to'];
		$subject = $_POST['subject'];
//		US_SendEmail( $to, "OW", $subject, $emailAck );
		if( DEBUG ) {
			error_log( "send an email to '$to' re '$subject'" );
		}
		exit;
	}
	fwrite( $logHandle, "OW.php: got POSTed values:\n" );
	fwrite( $logHandle, var_export( $_POST, true ) );
	fwrite( $logHandle, "\n" );

	// now process the various POSTed values:
	if( isset( $_POST['eventNum']) ) {
		$eventNum = $_POST['eventNum'];
		$_SESSION['eventNum'] = $eventNum;
	} elseif( isset( $_SESSION['eventNum'] ) ) {
		$eventNum = $_SESSION['eventNum'];
	}
	if( $eventNum >= 0 ) {
		$event=$OWProps[$eventNum];
		$msg = "OW.php: Working with event $eventNum: '" . 
			$event['name'] . "' on " . $event['date'] . " (cat " . $event['cat'] . ")\n";
		fwrite( $logHandle, $msg );
	}
	
	if( DEBUG ) {
		error_log( "No Files found but POSTed data available.");
		error_log( "Selected event: #$eventNum:");
		error_log( "get is: " . var_export( $_GET, true ) );
		error_log( "post 3 is: " . var_export( $_POST, true ) );
	}
	$uploadAgainButton = $_POST['uploadAgainButton'];
	$uploadAnotherButton = $_POST['uploadAnotherButton'];
	$uploadFixedResultButton = $_POST['uploadFixedResultButton'];
	$deleteButton = $_POST['deleteButton'];
	if( isset( $deleteButton ) ) {
		// the user has requested to delete the previous uploaded file. The value of $deleteButton
		// is the USER'S NAME of the file, not our converted name. Convert it to the form that we
		// want:
		// convert the file name to replace whitespace with underscore and remove brackets, braces, and parens:
		$convertedFileName = preg_replace( "/\s/", "_", $deleteButton );
		$convertedFileName = preg_replace( "/[\(\)]/", "", $convertedFileName );
		$convertedFileName = preg_replace( "/[\{\}]/", "", $convertedFileName );
		$convertedFileName = preg_replace( "/[\[\]]/", "", $convertedFileName );
		$pretag = US_ComputeSavedFilePretag( $event['unique'], $event['cat'], 
			preg_replace( "/\s/", "", $event['name'] ) );
		$filename = $destinationDirArchive . $pretag . $convertedFileName;
		if( file_exists( $filename ) ) {
			if( ! unlink( $filename ) ) {
				error_log( "OW.php:: ERROR: DELETE requested but unlink failed: '$filename'" );
			}
			
		} else {
			// huh? this is odd...
			error_log( "OW.php:: ERROR: DELETE requested but file doesn't exist: '$filename'" );
		}
//		$_SESSION['deleteButton'] = $deleteButton;
		include "OwStart.php";
		exit;
	} elseif( isset( $uploadAgainButton ) ) {
		include "OwStart.php";
		exit;
	} elseif( isset( $uploadAnotherButton ) ) {
		include "OwStart.php";
		exit;
	} elseif( isset( $uploadFixedResultButton ) ) {
		// we just fall thru to generate the drop window for the appropriate eventNum already
		// collected above...
	}
	

} // end of !empty( $_POST...
else {
	// no file dropped, nothing POSTed
	if( DEBUG ) {
		error_log( "No file dropped, nothing POSTed" );
	}
	fwrite( $logHandle, "OW.php: no file dropped, nothing POSTed. Probably an error...\n" );
	
	US_GeneratePageHead( "OW" );
	NoResultFileSelected();
	exit();
}
	
	
// we get here if:
//	- there was no POSTed data and no FILEs, or
//	- the POSTed data was the result of a hit on the "uploadFixedResultButton" button
// in these cases just show the Drop window and let the user give us a file:
US_GeneratePageHead( "OW" );
GenerateOWDropZone( $UsersFullName, $eventNum, $OWProps );
exit;



/*
 * NoResultFileSelected - this function is invoked if the user attempts to download without
 *	telling us which event the results are for.
 *
 */
function NoResultFileSelected( ) {
	global $yearBeingProcessed;
	if( DEBUG ) {
		error_log( "inside NoResultFileSelected" );
	}

	?>
	<style>
		.drop_zone {
			position: fixed;
			overflow: auto;
			padding-right:5px;
			margin:0;
			top:0;
			left:0;
			width: 100%;
			height: 100%;
			text-align: center;
		}
	</style>
	</head>
	<body>
	<form id="NoResultForm" method="post" action="OW.php" enctype="multipart/form-data"
		style='display:block'>
		<div id="drop_zone" class="drop_zone" >
			<h1 align="center">Upload a New OW File - Missing Result File</h1>
				<p>Please choose the results you want to upload by clicking this button and then
				selecting the desired event: 
					<button onclick='document.getElementById( "NoResultForm").submit()' name="uploadAnotherButton"
						id="uploadAnotherButton">Upload New Results</button>
				</p>
			  	
		</div>
	</form>
	</body>
	</html>
	<?php
	if( DEBUG ) {
		error_log( "EXIT when at end of NoResultFileSelected" );
	}
} // end of NoResultFileSelected()


//		list( $keywordFromFileEntry, $distFromFileName, $catFromFileName ) = 
//			GetKeysFromFileName( $convertedFileName, $OWProps, $eventNum );
/*
 * GetKeysFromFileName - do our best to find and return data related to the passed file name.
 *
 * PASSED:
 *	$convertedFileName - the file name we're analyzing
 *	$OWProps - an array containing the list of events of which we're interested
 *	$eventNum - an index into $OWProps of the event in which we're interested
 *
 * RETURNED:
 *	$keywordFromFileEntry - the keyword associated with $OWProps[$eventNum], folded to lower case
 *	$distFromFileName - the distance of the event represented by $convertedFileName
 *	$catFromFileName - the Category of the event represented by $convertedFileName
 *
 */
function GetKeysFromFileName( $convertedFileName, $OWProps, $eventNum ) {
	$lowerFileName = strtolower( $convertedFileName );
	$keywordFromFileEntry = strtolower( $OWProps[$eventNum]["keyword"] );		// either a word or an empty string
	$distFromFileName = "";
	$catFromFileName = "";
	
	// try to figure out what distance this file represents:
	$pattern = "/((\d\.\d*)|(\d)|(\.\d))\s*mi/";
	$match = preg_match( $pattern, $lowerFileName, $matches );
	if( $match === 1 ) {
		// we've found a distance in miles in the file name
		$distFromFileName = $matches[1];
	} elseif( $match === false ) {
		// we got an error - log it
		error_log( "ERROR: GetKeysFromFileName(): Pattern[1] '$pattern' failed against '$lowerFileName'" );
	}
	if( $match === 0 ) {
		// looking for distance in miles didn't work - try K
		$pattern = "/(\d+\.?\d*)\s*k/";
		$match = preg_match( $pattern, $lowerFileName, $matches );
		if( $match === 1 ) {
			// we've found a distance in K in the file name
			$distK = $matches[1];
			$distFromFileName = round( $distK / 1.609344, 3 );
		} elseif( $match === false ) {
			// we got an error - log it
			error_log( "ERROR: GetKeysFromFileName(): Pattern[2] '$pattern' failed against '$lowerFileName'" );
		}
	}
	if( $match === 0 ) {
		// didn't find a distance in miles or K - try something else
		$pattern = "/half\s*mile/";
		$match = preg_match( $pattern, $lowerFileName, $matches );
		if( $match === 1 ) {
			// we've found a distance: 1/2 mile
			$distFromFileName = .5;
		} elseif( $match === false ) {
			// we got an error - log it
			error_log( "ERROR: GetKeysFromFileName(): Pattern[3] '$pattern' failed against '$lowerFileName'" );
		}
	}
	
	// try to figure out the category from the file name:
	$pattern = "/cat[a-z_]*\s*(\d)/";
	$match = preg_match( $pattern, $lowerFileName, $matches );
	if( $match === 1 ) {
		// we found the category
		$catFromFileName = $matches[1];
	} elseif( $match === false ) {
		// we got an error - log it
		error_log( "ERROR: GetKeysFromFileName(): Pattern[4] '$pattern' failed against '$lowerFileName'" );
	}

	return array( $keywordFromFileEntry, $distFromFileName, $catFromFileName );
} // end of GetKeysFromFileName()





//		$fileNameProblem = ValidateDroppedFileName( $eventNum, $OWProps, $convertedFileName, $keywordFromFileEntry, 
//			$distFromFileName, $catFromFileName );
/* ValidateDroppedFileName  - return a non-empty string if the passed $keywordFromFileEntry, $distFromFileName, 
 * or $catFromFileName are not consistant with the event given by $eventNum,  or "" otherwise.
 *
 * PASSED:
 *	$eventNum - the event number of the event for which the passed uploaded file contains results.
 *	$OWProps - array of events. $OWProps[$eventNum] is the event we're working on.
 *	$convertedFileName - the file name of the uploaded file
 *	$keywordFromFileEntry - the keyword associated with $OWProps[$eventNum], folded to lower case. Can be ""
 *	$distFromFileName  -  the distance of the event corresponding to the uploaded file, in miles. Can be ""
 *	$catFromFileName - Category of the event corresponding to the uploaded file. Can be ""
 *
 * RETURNED:
 *	$result - empty string ("") if the passed values are consistent with the passed uploaded file name, 
 *		or a non-empty string (error message) if we found an inconsistancy.
 *
 */ 
function ValidateDroppedFileName( $eventNum, $OWProps, $convertedFileName, $keywordFromFileEntry, $distFromFileName, 
	$catFromFileName ) {
	$lowerFileName = strtolower( $convertedFileName );
	$result = "";
	
	// First, let's look at the name of the file being uploaded ($convertedFileName) and make sure it seems 
	// consistant with the event ($eventNum). For example, if the name of the file is 
	// "2022_KellerResults_Final10-4-22-2miCat1.csv" but the event is "Lake Berryessa 2 Mile" with the keyword
	// "berryessa" we're going to throw a fit.
	if( $keywordFromFileEntry != "" ) {
		foreach( $OWProps as $key => $value ) {
			if( DEBUG > 20) {
				error_log( "ValidateDroppedFileName(): key=$key" );
			}
			if( $key == $eventNum ) {
				if( DEBUG > 20) {
					error_log( "--- $key == $eventNum");
				}
				continue;
			}
			if( $OWProps[$key]['keyword'] == $keywordFromFileEntry ) {
				if( DEBUG > 20) {
					error_log( "OWProps[key]['keyword'] == keywordFromFileEntry ( "  . 
						$OWProps[$key]['keyword'] . " == "  . $keywordFromFileEntry . ")");
				}
				continue;
			}
			if( strpos( $lowerFileName, $OWProps[$key]["keyword"] ) !== false ) {
				// the file uploaded has a string in its name that matches a different event
				if( DEBUG > 20) {
					error_log( "'$lowerFileName' contains the keyword '" . $OWProps[$key]["keyword"] . "' WHICH IS A PROBLEM.");
				}
				$result = "The file you attempted to upload has a NAME that looks like it belongs to a different event.  ";			
			}
			else {
				if( DEBUG > 20) {
					error_log( "'$lowerFileName' doesn't contain the keyword '" . $OWProps[$key]["keyword"] . "'");
				}
			}
		}
	}
	
	if( $result == "" ) {
		// OK, name seems consistent as best we can determine.  How about the length of the event?
		if( $distFromFileName != "" ) {
			$distDiff = abs( $distFromFileName - $OWProps[$eventNum]["distance"] );
			if( DEBUG > 20 ) {
				error_log( "ValidateDroppedFileName(): distFromFileName='$distFromFileName', distance from OWProps='" .
					$OWProps[$eventNum]["distance"] . "'" );
			}
			if( $distDiff >= .5 ) {
				// looks like the distance gleened from the file name isn't close to the event distance
				$result = "This file looks like it contains the results for an event with the distance of " .
					"$distFromFileName miles, NOT " . $OWProps[$eventNum]["distance"] . 
					" miles.  ";
			}
		}
	}
	
	if( $result == "" ) {
		// OK, name and distance seems consistent as best we can determine. What about Category?
		if( $catFromFileName != "" ) {
			if( $catFromFileName != $OWProps[$eventNum]["cat"] ) {
				// Category doesn't match!
				$result = "This file looks like it contains Category $catFromFileName results " .
					"but it was expected to contain Category " . $OWProps[$eventNum]["cat"] . 
					" results for the '" . $OWProps[$eventNum]["name"] . "' event.  ";
			}
		}
	}
	
	if( DEBUG ) {
		$msg = " (Error with file name) ";
		if( $result == "" ) {
			$msg = " (No Error with file name) ";
		}
		error_log( "ValidateDroppedFileName(): result='$result'$msg");
	}
	
	return $result;
} // end of ValidateDroppedFileName()



// 					$message = ArchiveUploadedFile( $destinationDirTmp, $convertedFileName, 
//						$destinationDirArchive, $OWProps, $eventNum );
/*
 * ArchiveUploadedFile - move the valid OW file from our temporary working directory to our 
 *		Archive directory available for use by OW points processing.
 *
 * PASSED:
 *	$sourceDir - the tmp directory containing the file. Ends with a '/'
 *	$fileName - the simple file name of the file to be moved.
 *	$destinationDir - the archive directory.  Ends with a '/'
 *
 * RETURNED:
 *	$status - an error string if we have a problem, or an empty string if all is OK.
 *
 * Notes:
 */
function ArchiveUploadedFile( $sourceDir, $fileName, $destinationDir, $OWProps, $eventNum ) {
	$status = "";
	$fullFileName = $sourceDir . $fileName;	
//	$fullNewName = $destinationDir . $OWProps[$eventNum]['unique'] . "-" . $OWProps[$eventNum]['cat'] . "-" . $fileName;
	$pretag = US_ComputeSavedFilePretag( $OWProps[$eventNum]['unique'], $OWProps[$eventNum]['cat'], 
		preg_replace( "/\s/", "", $OWProps[$eventNum]['name'] ) );
	$fullNewName = $destinationDir . $pretag . $fileName;
	
	// we'll move '$fileName' to '$fullNewName', but first we'll make sure there isn't
	// already a '$fullNewName'.
	if( file_exists( $fullNewName ) ) {
		// already exists - remove it
		if( !unlink( $fullNewName ) ) {
			$status = " (oops...Unable to delete an old archived copy ($fullNewName))";
		}
	}
	if( $status == "" ) {
		// OK so far...
		if( !rename( $fullFileName, $fullNewName ) ) {
			$status = " (oops...Unable to rename '$fileName' to '$fullNewName')";
		}
	}
	return $status;
} // end of ArchiveUploadedFile()


// 			list( $arrOfLines, $status ) = ValidateOWFile( $destinationDirTmp, $convertedFileName, 
//				$OWProps, $eventNum );

/*
 * ValidateOWFile - Once we've uploaded a file we'll validate it here
 *
 * PASSED:
 * 	$destinationDir - the directory containing the newly uploaded file. Ends with a '/'
 * 	$fileName - the name of the file to validate
 *	$OWProps - an array containing the list of events of which we're interested and info 
 *		about each event.
 *	$eventNum - an index into $OWProps of the event in which we're interested
 *
 * RETURNED:
 * 	$message - a message (array of strings) to be sent back to the browser. An empty array 
 *		means nothing to say (i.e. usually no error.) Each string is NOT terminated with a newline.
 * 	$status - 0 if OK, non-zero if not.  Specifically, a non-zero value if we had some "internal" error
 *		that likely isn't due to bad data (e.g. exec failed, couldn't open tmp file, etc.) The absolute
 *		value will be returned, and will represent the number of errors found. Often this
 *		value represents the number of FATAL ERRORs discovered by processing the single OW file.
 *
 */
function ValidateOWFile( $destinationDir, $fileName, $OWProps, $eventNum ) {
	global $localProps;
	global $yearBeingProcessed;
	global $OWPointsTmpDirName;
	global $OWPointsTmpCalendarEntry;
	global $OWPointsStdout;
	$message = array();
	$status = 0;  // assume all ok
	$dirResult = false;
	$numBytesWritten = 0;
	$fullFileName = $destinationDir . $fileName;	// may be partial path relative to CWD
	if( DEBUG > 2 ) {
		error_log( "ValidateOWFile(): file to validate: '$fullFileName'");
	}
	// make sure we can open and read our file to be validated
	$fp = fopen( $fullFileName, "r" );
	if( ! $fp ) {
		$status = -1;
		$message[0] = "Internal error - unable to open the file just uploaded ($fullFileName) - upload aborted!";
	} else {
		// yep - we can read it.
		fclose( $fp );
		// if our temp directory already exists then remove it, then re-create it.
		if( is_dir( $OWPointsTmpDirName ) ) {
			if( FullRemoveDir( $OWPointsTmpDirName ) ) {
				if( DEBUG > 2 ) {
					error_log( "ValidateOWFile(): successfully removed $OWPointsTmpDirName\n" );
				}
			} else {
				if( DEBUG > 2 ) {
					error_log( "ValidateOWFile(): failed to remove $OWPointsTmpDirName -  keep going.\n" );
				}
				// that's not good, but we can still keep going if we just try to re-use the directory
				// we couldn't remove.  We probably can't, but we'll try...
			}
		}
		
		// we need a temp directory. If it doesn't already exist we're going to create it:
		if( is_dir( $OWPointsTmpDirName ) ) {
			$dirResult = opendir( $OWPointsTmpDirName );
			if( $dirResult == false ) {
				if( DEBUG > 2 ) {
					error_log( "ValidateOWFile(): failed to opendir $OWPointsTmpDirName\n" );
				}
				$status = -1;
				$message[0] = "Internal error - unable to open the temp directory '$OWPointsTmpDirName' - upload aborted!";
			} else {
				if( DEBUG > 2 ) {
					error_log( "ValidateOWFile(): successfully opendir $OWPointsTmpDirName\n" );
				}
			}
		} else {
			$dirResult = mkdir( $OWPointsTmpDirName, 0770 );
			if( $dirResult == false ) {
				if( DEBUG > 2 ) {
					error_log( "ValidateOWFile(): failed to mkdir $OWPointsTmpDirName\n" );
				}
				$status = -1;
				$message[0] = "Internal error - unable to create the temp directory '$OWPointsTmpDirName' - upload aborted!";
			} else {
				if( DEBUG > 2 ) {
					error_log( "ValidateOWFile(): successfully mkdir $OWPointsTmpDirName\n" );
				}
			}
		}
	}
	$fp = 0;		// make sure we don't confuse file pointers!
	if( $dirResult ) {
		// we have a temp directory - use it for exec'ing GeneratOWResults.pl
		// construct an input line for GeneratOWResults.pl running in single file mode:
		$fp = fopen( $OWPointsTmpCalendarEntry, "w" );
		if( !$fp ) {
			$status = -1;
			$message[0] = "Internal error - unable to open $OWPointsTmpCalendarEntry - upload aborted!";
		}
	}
	// we're done with our temp directory....for now:
	closedir( $dirResult );
	
	if( $fp ) {
		$cat = $OWProps[$eventNum]["cat"];
		$date = $OWProps[$eventNum]["date"];
		$distance = $OWProps[$eventNum]["distance"];
		$name = $OWProps[$eventNum]["name"];
		$unique = $OWProps[$eventNum]["unique"];
		$numBytesWritten = fwrite( $fp, $fullFileName . "  ->  $cat  ->  $date  ->  $distance  ->  $name  ->  $unique\n" );
		if( ! $numBytesWritten ) {
			$status = -1;
			$message[0] = "Internal error - writing to $OWPointsTmpCalendarEntry failed - upload aborted!";
		}
		fclose( $fp );
	}
	if( $numBytesWritten ) {
		// execute the OW processing script in 'single file' mode:
		$cmd = "/usr/bin/perl 2>&1 /usr/home/pacdev/Automation/PMSOWPoints/Code/GenerateOWResults.pl " .
			"$yearBeingProcessed -sf < $OWPointsTmpCalendarEntry -g$OWPointsTmpDirName";
		if( DEBUG > 2 ) {
			error_log( "ValidateOWFile(): cmd for exec(): '$cmd'");
		}
		$execResult = exec( $cmd, $message, $status );
		if( $status ) {
			// prepend the result code to the message and then set $status to 1 to represent
			// one error:
			array_unshift( $message, "Internal error: $status result code when trying to validate the uploaded file.\n" );
###			$message = "Internal error: $status result code when trying to validate the uploaded file.\n" . $message;
			$status = 1;
		}
		// $message is an array containing STDOUT of the executed $cmd. This may be empty 
		// (if, for example, the $cmd didn't run at all) or it may contain errors (if the
		// $cmd ran but wrote out error messages). We're going to write STDOUT to our STDOUT
		// log file.  If no error we'll write an empty $message.
		$fp = fopen( $OWPointsStdout, "w" );
		foreach( $message as $line ) {
			fwrite( $fp, $line . "\n" );
		}
		fclose( $fp );
		if( $execResult !== false ) {
			if( DEBUG > 2 ) {
				error_log( "ValidateOWFile(): result from successful exec(): '$execResult'," .
					"status=$status");
			}
			unset( $message );
		} else {
			if( DEBUG > 2 ) {
				error_log( "ValidateOWFile(): exec() failed! STDOUT from the command is " .
					"called 'message' and shown below.");
				$status = 1;
			}
		}
	}
	
	// if our processing completed successfully then we'll now look to see if there
	// were any errors detected:
	if( ! $status ) {
		global $OWPointsLogFileName;
		$status = AnalyzeLogFile( $OWPointsTmpDirName, $yearBeingProcessed, $message );
	} else {
		// something went wrong with what we exec'ed...
		//$message[0] = "Internal Error - Failed to process the OW result file.";
		//$message[1] = "  (status=$status)";
		if( DEBUG ) {
			error_log( "ValidateOWFile(): Failed to process the OW result file.  " .
				"status='$status', message:" );
			foreach( $message as $line ) {
				error_log( $line );
			}
		}
	}

	if( DEBUG ) {
		$str = var_export( $message, true );
		error_log( "ValidateOWFile(): Return with status='$status', message='$str'" );
	}
	
	$status = abs($status);
	return array( $message, $status );
} // end of ValidateOWFile()






// 			if( FullRemoveDir( $OWPointsTmpDirName ) ) {
/*
* FullRemoveDir - remove all files and subdirectories from the passed directory, and then the
*	directory.
*
* PASSED:
*	$dirName - the full path of the directory to remove.
*
* RETURNED:
*	true if OK, false otherwise.
*
*/
function FullRemoveDir( $dirName ) {
	$result = 1;		// hope for the best...
	foreach( scandir( $dirName ) as $file ) {
		if( $file == '.' || $file == '..' ) {
			continue;
		} else {
			$fullFile = "$dirName/$file";
			if( is_dir( $fullFile) ) {
				// this is a sub-directory - remove it
				$result = FullRemoveDir( $fullFile );
			} else {
				// assume a simple file!
				$result = unlink( "$fullFile" );
			}
		}
		if( !result ) {
			error_log( "FullRemoveDir(): Failed to remove $fullFile." );
			break;
		}
	} // end of foreach( ...
	if( result ) {
		$result = rmdir( $dirName );
	}
	if( $result ) {
		if( DEBUG > 2 ) {
			error_log( "FullRemoveDir(): successfully removed $dirName\n" );
		}
	} else {
		if( DEBUG > 2 ) {
			error_log( "FullRemoveDir(): failed to remove $dirName\n" );
		}
	}
	return $result;
} // end of FullRemoveDir()





// 		$status = AnalyzeLogFile( $OWPointsTmpDirName, $yearBeingProcessed );
/*
 * AnalyzeLogFile - analyze the passed log file produced by GenerateOWResults.pl when processing a single
 *		OW result file.
 *
 * PASSED:
 *	$OWPointsTmpDirName - the directory holding the log file
 *	$yearBeingProcessed - the year being processed. Used to construct the name of the log file.
 *	&$message - a reference to an array that will hold errors found, or empty array if no errors.
 *
 * RETURNED:
 *	$status - 0 if no errors found, non-zero if errors (actually the number of errors reported)
 *	&$message - an array that will hold errors found, or empty array if no errors.
 *		Each elememt of this array looks like one of:
 *			- ! FATAL ERROR: ...msg... (note that the first character is a bang)
 *			-  ...msg... (note that the first character is a space) These lines are details of the
 *				preceeding FATAL ERROR line.
 *	
 */
function AnalyzeLogFile( $OWPointsTmpDirName, $yearBeingProcessed, &$message ) {
	global $OWPointsLogFileName;
	$status = 0;
	$message = array();
	$lastLine = "";

	$fp = fopen( $OWPointsLogFileName, "r" );
	if( ! $fp ) {
		$status = 1;
		$message[0] = "Internal error - unable to open the analysis log file just uploaded - upload aborted!";
		error_log( "Internal error: AnalyzeLogFile(): unable to open log file '$OWPointsLogFileName' - upload aborted!" );
	} else {
		// process the log file
		while( ($line = fgets( $fp )) !== false ) {
			$lastLine = $line;		// this may be our last line...
			$foundIssue = 0;		// set to 1 if we found an error or warning that we want to display
			
			// look for FATAL ERROR
			$pattern = '/^\! FATAL ERROR/';
			$match = preg_match( $pattern, $line);
			if( $match === false ) {
				$status++;
				$message[0] = "Internal error - failed pattern match - upload aborted!";
				error_log( "Internal error: AnalyzeLogFile(): failed pattern match ($pattern) in log file '$OWPointsLogFileName' - upload aborted!" );
				break;
			} else if( $match ) {
				// we found a FATAL ERROR in the log file. This line, and all following up to (but not including) a line that 
				// begins with '! END FATAL ERROR' will be considered part of the fatal error message.
				$foundIssue = 1;
				$line = trim( $line );		// remove leading and trailing whitespace (including newline)
				$message[] = $line . "\n";
				$status++;
				while( ($lineFE = fgets( $fp )) !== false ) {
					$lastLine = $lineFE;		// this may be our last line...
					$match = preg_match( '/-- END FATAL ERROR/', $lineFE);
					if( $match ) {
						// end of FATAL ERROR lines
						break;
					} else {
						// we've got another FATAL ERROR line
						$lineFE = rtrim( $lineFE );		// remove trailing whitespace (including newline)
						$message[] = $lineFE . "\n";		// make this line part of the FATAL ERROR
					}
				}
			} // end of ...else if( $match )...
			// done looking for a FATAL ERROR
			
			if( $foundIssue == 0 ) {
				// no FATAL ERROR with this line - any other issue?
				// look for simple error...
				$pattern = '/^\! ERROR/';
				$match = preg_match( $pattern, $line);
				if( $match === false ) {
					$status++;
					$message[0] = "Internal error - failed pattern match - upload aborted!";
					error_log( "Internal error: AnalyzeLogFile(): failed pattern match ($pattern) in log file '$OWPointsLogFileName' - upload aborted!" );
					break;
				} else if( $match ) {
					// we found an ERROR...
					$foundIssue = 1;
					$line = trim( $line );		// remove leading and trailing whitespace (including newline)
					$message[] = $line . "\n";
					$status++;
					while( ($lineFE = fgets( $fp )) !== false ) {
						$lastLine = $lineFE;		// this may be our last line...
						$match = preg_match( '/-- END ERROR/', $lineFE);
						if( $match ) {
							// end of ERROR lines
							break;
						} else {
							// we've got another ERROR line
							$lineFE = rtrim( $lineFE );		// remove trailing whitespace (including newline)
							$message[] = $lineFE . "\n";		// make this line part of the ERROR
						}
					}
			
				} // end of ...else if( $match )...
				// done looking for a simple  ERROR
			}
			
			if( ($foundIssue == 0) && (0) ) {
				// no ERROR - how about a WARNING?
				$pattern = '/^\! WARNING/';
				$match = preg_match( $pattern, $line);
				if( $match === false ) {
					$status++;
					$message[0] = "Internal error - failed pattern match - upload aborted!";
					error_log( "Internal error: AnalyzeLogFile(): failed pattern match ($pattern) in log file '$OWPointsLogFileName' - upload aborted!" );
					break;
				} else if( $match ) {
					// we found a WARNING...
					$foundIssue = 1;
					while( ($lineFE = fgets( $fp )) !== false ) {
						$lastLine = $lineFE;		// this may be our last line...
						$match = preg_match( '/-- END WARNING/', $lineFE);
						if( $match ) {
							// end of WARNING lines
							break;
						} else {
							// we've got another WARNING line
							$lineFE = rtrim( $lineFE );		// remove trailing whitespace (including newline)
							$message[] = $lineFE . "\n";		// make this line part of the WARNING
						}
					}
			
				} // end of ...else if( $match )...
				// done looking for a simple  ERROR
				
			}

		} // end of ...while( ($line....
		if( !feof( $fp ) ) {
			array_push( $message, "Internal error: AnalyzeLogFile(): Unexpected EOF when analyzing the uploaded file - upload aborted!" );
			$status++;
		}
		// special case - look for a special last line in the log file. If we don't find it we have to assume that
		// processing didn't complete for some reason (like the OW results processor crashed!)
		if( preg_match( "/END OF SINGLE FILE PROCESSING/", $lastLine ) != 1 ) {
			$message[] = "Internal error: Incomplete log file - did something crash?";
			$message[] = "  AnalyzeLogFile(): Please report this.";
			$status++;
		}
		fclose( $fp );
	} // end of processing the log file
	if( DEBUG ) {
		error_log( "AnalyzeLogFile(): return # errors found ='$status'" );
	}
	
	return $status;
} // end of AnalyzeLogFile()







// 					$index = LocationOfFirstNonMatch( $line, $validRSINDHeader, $lengthj );
/*
 * LocationOfFirstNonMatch - return the index of the left-most character of the two
 *		passed strings where the characters differ.
 *
 * PASSED:
 *	$line1, $line2 - the two strings to compare
 *	$length - the length of the two lines
 *
 * RETURNED:
 *	$index - the index (starting at 1) of the left most characters that differ. 0 if the
 *		strings are identical.
 *
 * NOTES: The strings must be the same length. The index returned is 1 based (the left-
 *	most character of a string is character index 1)
 *
 */
function LocationOfFirstNonMatch( $line1, $line2, $length ) {
	for( $i=0; $i < $length; $i++ ) {
		if( $line1[$i] != $line2[$i] ) {
			break;
		}
	}
	if( $i >= $length ) {
		// strings are identical
		$i = 0;
	} else {
		$i++;
	}

	return $i;
} // end of LocationOfFirstNonMatch()


// 				SetSuccess( $arrOfLines );
/*
 * SetSuccess - Generate the success return if the FILE sent to our script was successfully uploaded
 *		and passed validation.
 *
 * PASSED:
 * 	$success - one of:
 * 		- a success message string.
 * 		- an array of success message strings.  
 *
 * NOTES:
 * 	
 * 	
 */

function SetSuccess( $success ) {
	if( ! is_array( $success ) ) {
		// turn our success string into an array of (1) string(s)...
		$tmp = $success;
		$success = array();
		$success[] = $tmp;
	}
	// make sure each element of the array ends with a '\n'
//	foreach( $success as &$element ) {
//		if( substr( $element, -1 ) != '\n' ) {
//			$element .= '\n';
//		}
//	}

	// make the first element of the array be "success":
	array_unshift( $success, "success" );
	$jsonResult = json_encode( $success );
	echo $jsonResult;
	if( DEBUG  ) {
		error_log( __FILE__ . ": SetSuccess(): exit with jsonResult='$jsonResult'." );
		error_log( "success[0]='$success[0]'." );
	}
} // end of SetSuccess()


/*
 * SetError - Generate the error return if the FILE sent to our script failed to be uploaded
 *		or didn't pass validation.
 *
 * PASSED:
 * 	$err - one of:
 * 		- an error string to accompany the error.  This will be displayed to the user.
 * 		- an array of error strings, all of which will be sent to the browser, but only some of
 * 			them will be displayed to the user.  
 *
 * NOTES:
 * 	
 * 	
 */
function SetError( $err ) {
	if( DEBUG  ) {
		error_log( __FILE__ . ": SetError(): entered." );
	}
	if( ! is_array( $err ) ) {
		// turn our error string into an array of (1) string(s)...
		$tmp = $err;
		$err = array();
		$err[] = $tmp;
	}
	// make the first element of the array be "error":
	array_unshift( $err, "error" );
	$jsonResult = json_encode( $err );
	echo $jsonResult;
	if( DEBUG  ) {
		error_log( __FILE__ . ": SetError(): exit with jsonResult='$jsonResult'." );
		error_log( "err[0]='$err[0]'." );
	}
} // end of SetError()






/*
 * GenerateOWDropZone -
 *
 */
function GenerateOWDropZone( $UsersFullName, $eventNum=-1, $OWProps=array() ) {
	global $yearBeingProcessed;
	global $destinationDirArchive;
	if( DEBUG ) {
		error_log( "inside GenerateOWDropZone" );
	}
	$emailRecipients = OW_EMAIL_RECIPIENTS;
	$replacement = "";
	if( $eventNum >= 0 ) {
		$pretag = US_ComputeSavedFilePretag( $OWProps[$eventNum]['unique'], $OWProps[$eventNum]['cat'], 
			preg_replace( "/\s/", "", $OWProps[$eventNum]['name'] ) );
		$pretag = US_SanatizeRegEx( $pretag );
		list( $existingFileName, $existingFileDate) = 
			US_FileExistsWithThisPretag( $pretag, $destinationDirArchive );
		if( $existingFileName != "" ) {
			$replacement = "$existingFileName was uploaded on $existingFileDate<br> " .
				"Waiting for the replacement results file to be dropped here.";
		}
	}

	// If we are going to give the results of analyzing an OW result file then we are going to
	// also give the user a pointer to the GenerateOWResults.pl input, log, and STDOUT files.
	global $OWPointsTmpDirName;
	global $OWPointsTmpCalendarEntry;
	global $OWPointsStdout;
	global $OWPointsLogFileName;

	?>
	<style>
		.drop_zone {
			position: fixed;
			overflow: auto;
			padding-right:5px;
			margin:0;
			top:0;
			left:0;
			width: 100%;
			height: 100%;
			text-align: center;
		}
		.drop_zone_border {
			box-sizing: border-box;
			border: 5px solid blue;
		}
		
		.status_area {
			display: inline-block;
			vertical-align: top;
			margin-right: 5%;
			margin-left: 5%;
			text-align:left;
			font-size: 15px;
			color: black;
			overflow-wrap: break-word;
			word-wrap: break-word;
			hyphens: auto;
			bottom: 20px;
			overflow:auto;
		}
		.status-text {
			margin: 0 auto;
			text-align:center;
		}
		.upload_msg {
			margin: 0 10% 0 10%;
		}
		.dropDiv {
			margin-right: 100px;
			margin-left: 50px;
		}
	</style>
	
	<script>
		var debug = <?php echo DEBUG; ?>;
		var replacement = "<?php echo $replacement; ?>";
		var myURL = location.href;
		if( debug ) console.log( "my url is '" + myURL + "'" );
		function dragOverHandler(ev) {
			if( debug ) console.log('File(s) in drop zone');
			// Prevent default behavior (Prevent file from being opened)
			ev.preventDefault();
			document.getElementById( "drop_zone" ).style.borderColor = "red";
			document.getElementById( "NoUploads").innerHTML = "(Testing the newly dropped result file.)";
		} // end of dragOverHandler()
		function dragOverLeaveHandler( ev ) {
			// Prevent default behavior (Prevent file from being opened)
			ev.preventDefault();
			document.getElementById( "drop_zone" ).style.borderColor = "blue";
			document.getElementById( "NoUploads").innerHTML = "(Waiting for the results file to be dropped here)";
		} // end of dragOverLeaveHandler()

		/*
		 * DropMsg - this function is executed when a user message is clicked on.  When this happens
		 * 	the corresponding div (passed id) is shown (if hidden) or hidden (if shown).
		 */
		function DropMsg( id ) {
			$('#'+id).toggle();
		}
		///////////////////////////////////////////////////
		// Once our page is loaded then execute the following:
		///////////////////////////////////////////////////
		$(function() {
			if( replacement != "" ) {
				document.getElementById( "NoUploads" ).style.display="none";		// turn off upload form
				document.getElementById( "ReplaceUpload" ).style.display="block";		// turn on replacement upload form
				document.getElementById( "ReplaceUpload" ).innerHTML = replacement;
			}
			$('#UploadForm').fileupload({
				dropZone: $('#drop_zone'),
				formData: function( form ) {
					return form.serializeArray();
				},
				add: function (e, data) {
					if( debug ) {
						console.log( "upload add: ");
						console.log( e );
						console.log( data);
					}
					// Automatically upload the file once it is added to the queue
					var jqXHR = data.submit();
				},
				done:function(e, data){
					if( debug ) {
						console.log( "upload done: ");
						console.log( e );
						console.log( data);
					}
					var fileName = data.files[0].name;
					var result = data.result;
					// result is an array of one or more lines
					// the first line is either "error" or "success"
					if( debug ) console.log( "fileName: '" + fileName + "', result: '" + result + "'" );					
					var startMsg = "Upload of the file named '" + fileName + "' FAILED!<br>\n";		// assume the worse
					var fullMsg = "";
					var errArr;
					try {
						errArr = JSON.parse( result );
					} catch( err ) {
						if( err.name == "SyntaxError" ) {
							// if necessary we may have to do something in here if we can't figure out the syntax error
							if( debug ) console.log( "JSON.parse( result ) got an err named 'SyntaxError', err.message-'" +
								err.message + "'." );
						} else {
							if( debug ) console.log( "JSON.parse( result ) got an err, err.message-'" +
								err.message + "'." );
						}
						startMsg = "Upload of '" + fileName + "' FAILED!  JSON Syntax Error.<br>\n";
						var nextId = GetNextId();
						var nextIdStr = "id" + nextId;
						errArr = Array( "error", "This is an internal error - please report it!", "  " + err.message,
							"  " + result );
					}
					var status = errArr[0];
					if( status == "success" ) {
						startMsg = "Upload of '" + fileName + "' SUCCESSFUL!<br>\n";
					}
					console.log( "Status when handling the file '" + fileName + "': " + status );
					var allErrors = GetTextOfAllMessages( errArr );
					if( debug ) console.log( "GetTextOfAllErrors() passed '" + errArr + 
						"' and returned '" + allErrors + "'" );
					fullMsg = startMsg + allErrors;
					UpdateScreenWithStatus( fullMsg, startMsg, status, fileName );
				},
				fail:function(e, data){
					// Something has gone wrong!
					var fileName = data.files[0].name;
					var startMsg = "";
					var fullMsg = "";
					if( debug ) {
						console.log( "upload error: e:" );
						console.debug( e );
						console.log( "data: ");
						console.debug( data );
					}
					startMsg = "Upload of " + fileName + " FAILED!  (Internal Error!)";
					fullMsg = "<p style='color:red'>" + startMsg + "</p>";
					UpdateScreenWithStatus( fullMsg, startMsg, "error", fileName );
				}
			});
		});
		

function ReplaceSpecialChars( line ) {
		line = line.replace( "\n\n", "<p>" );
		line = line.replace( "\n", "<br>" );
		return line;
} // end of ReplaceSpecialChars()


/*
 * GetTextOfAllMessages - return the HTML formatted messages to display to the user.
 * 
 * PASSED:
 *	arrOfString - an array of strings (messages for the user) 
 *		This routine assumes the first element is a tag for the message and will not be shown
 *			to the user. Therefore, this routine will ignore the first element.
 *		This routine assumes the second element is a heading of some sort which will be visible
 *			but will not react to a click event.
 *		This routine assumes the following elements are text to be shown to the user; some of
 *			this text is "VISIBLE", and some is "HIDDEN", where to expose the HIDDEN test 
 *			the user will need to click on the preceding VISIBLE text.  HTML is generated
 *			using the following rules:
 *		- The first element is ignored (see above)
 *		- All other elements are scanned for the following characters and, when found, replaced
 *			as indicated:
 *			\n\n - two newlines in a row.  Replaced with a <p>.
 *			\n - a single newline.  Replaced with a <br>
 *		- if a line DOESN'T begin with a space or tab character then it, and all such lines that
 *			follow it, will be shown as a list item and tied to a click action. 
 *			These lines make up a VISIBLE message.
 *		- if a line begins with a space character then it, and all such lines that follow
 *			it will be part of the VISIBLE message and tied to the same click action.
 *		- if a line begins with a tab character then it, and all such lines that follow
 *			it will be hidden and only exposed
 *			when the above click action occurs. These lines make up a HIDDEN message.
 *
 * RETURNED:
 *	result - the HTML to display these messages to the user.
 *
 * NOTES:
 *	We assume that HIDDEN messages are always preceded by a VISIBLE message.
 *
*/
function GetTextOfAllMessages( arrOfString ) {
	var len = arrOfString.length;
	var result = "";
	var nextId;
	var nextIdStr;
	for( var i = 1; i < len; i++ ) {
		var line = arrOfString[i];
		var firstChar = line.charAt(0);
		
		line = ReplaceSpecialChars( line );
		if( i == 1 ) {
			result += line;
			continue;
		}
		
		if( firstChar == "\t" ) {
			// we've got a string (or possibly strings) that make up a HIDDEN message.
			// They will be tied to the click action 
			result += "<div class='dropDiv' id='" + nextIdStr + 
				"' style=\"display:none\">" + line;
			var k;
			for( k = i+1; k < len; k++ ) {
				// include any following lines that begin with a space
				var line = arrOfString[k];
				var firstChar = line.charAt(0);
		line = ReplaceSpecialChars( line );
				if( firstChar == "\t" ) {
					// we've got another piece of the HIDDEN message
					result += line;
				} else {
					// we found the beginning of a VISIBLE message
					result += "</div>";
					i = k-1;		// re-read this line as a VISIBLE message
					break;
				}
			}
			if( k >= len ) {
				// We're all done with all the lines - finish up the HIDDEN message
				// we were working on and return.
				result += "</div>";
				i = k;
			}
		} else {
			// we've got a string (or possibly strings) that make up a VISIBLE message.
			// tie a click action to this/these string(s):
			nextId = GetNextId();
			nextIdStr = "id" + nextId;
			result += "<p onclick=\"DropMsg('" + nextIdStr + "')\" style='color:black'>" + line;
			var j;
			for( j = i+1; j < len; j++ ) {
				// include any following lines that do NOT begin with a space
				var line = arrOfString[j];
				var firstChar = line.charAt(0);
		line = ReplaceSpecialChars( line );
				if( firstChar == "\t" ) {
					// We've found the beginning of a HIDDEN message
					result += "&nbsp;&nbsp;&nbsp;";	// done with the VISIBLE message
					i = j-1;		// re-read this line as a HIDDEN message
					break;
				} else {
					// got another piece of the VISIBLE message
					result += line;
				}
			}
			if( j >= len ) {
				// We're all done with all the lines - finish up the VISIBLE message
				// we were working on and return.
				result += "&nbsp;&nbsp;&nbsp;";	// done with the VISIBLE message
				i=j;		// force our outer loop to terminate
			}
		}
	}
	// all done with our outer loop
	return result;
}  // end of GetTextOfAllMessages()






// 						var nextId = GetNextId();
function GetNextId() {
	var id=$("#count").text();
	var newId = Number(id) + 1;
	$("#count").text(newId);
	if( debug ) console.log( "GetNextId: return " + id + ", next id is " + newId );
	return id;
}
		
		
		
/*
 * 	UpdateScreenWithStatus() - invoked after an upload attempt
 *
 * PASSED:
 *	fullMsg - the full text of the upload result to be shown to the user.
 *	startMsg - the summary of the upload result
 *	status - the upload result status: success or error.
 *	fileName - the name of the file being uploaded.
 *
 */
function UpdateScreenWithStatus( fullMsg, startMsg, status, filename ) {
	// The upload is finished so turn off upload prompting...
	document.getElementById( "UploadForm" ).style.display="none";		// turn off upload form
	
	// get pointers to some interesting files that might be useful:
	var logFileFullPath = "<?php echo $OWPointsLogFileName; ?>";
	var stdoutFullPath = "<?php echo $OWPointsStdout; ?>";
	var inputFullPath = "<?php echo $OWPointsTmpCalendarEntry; ?>";



	// Now what we show the user depends on the result of the previous Upload action:
	// did the file drop get any errors? We're going to generate custom messages to the
	// user here telling them the options they have.
	if( status == "error" ) {
		// YES, we found errors...
		document.getElementById( "uploadFailure" ).style.display="block";	// turn on failure form
		document.getElementById( "uploadSuccess" ).style.display="none";	// turn off success form
		document.getElementById( "idUploadFailed").innerHTML = "Log: '" + logFileFullPath + "', STDIN: '" +
			inputFullPath + "', STDOUT: '" + stdoutFullPath + "'";
		document.getElementById( "FailureStatus_area" ).innerHTML = 
			document.getElementById( "FailureStatus_area" ).innerHTML + fullMsg;
		let emailMsg = fullMsg.replaceAll( /<p/g, "\nXX" );
		emailMsg = emailMsg.replaceAll( /<br/g, "XX" );
		emailMsg = emailMsg.replaceAll( /<div/g, "ZZ  " );
		emailMsg = emailMsg.replaceAll( /&nbsp;/g, "" );
		emailMsg = emailMsg.replaceAll( /XX[^>]*>/g, "\n" );
		emailMsg = emailMsg.replaceAll( /ZZ[^>]*>/g, "\n        " );
		emailMsg = emailMsg.replaceAll( /<\/p>/g, "" );
		emailMsg = emailMsg.replaceAll( /<\/div>/g, "" );
		emailMsg += "\n\n\n";
		
		emailMsg = encodeURIComponent( emailMsg );
		document.getElementById( "FailureStatus_email" ).innerHTML = 
			'<a href="mailto:ow_uploads@pacificmasters.org?subject=Problems with OW results upload.&body=' + emailMsg + '">' +
			"Send email to 'ow_uploads@pacificmasters.org'</a>";
	} else {
		// upload successful!
		document.getElementById( "uploadSuccess" ).style.display="block";	// turn on success form
		document.getElementById( "uploadFailure" ).style.display="none";	// turn off failure form
		document.getElementById( "idUploadSuccess").innerHTML = "Log: '" + logFileFullPath + "', STDIN: '" +
			inputFullPath + "', STDOUT: '" + stdoutFullPath + "'";
		document.getElementById( "deleteButton" ).value=filename;
		document.getElementById( "SuccessStatus-text" ).innerHTML = 
			document.getElementById( "SuccessStatus-text" ).innerHTML + fullMsg;
	}

	// send email
	var errMsg;
	if( status == "error" ) {
		errMsg = " - ERROR!";
	} else {
		errMsg = " - SUCCESS!";
	}
	
	if( debug ) {
		console.log( "UpdateScreenWithStatus(): errMsg='" + errMsg + "', fullMsg='" + fullMsg + "'" );
	}

	$.post( myURL, {"to" : <?php echo "'" . $emailRecipients . "'"; ?>, 
		"subject" : "OW file uploaded by <?php echo "'" . $UsersFullName . "'"; ?>" + errMsg,
		"emailAck" : fullMsg}, function( data ) {
	});
	
} // end of UpdateScreenWithStatus();



///////////////////////////////////////////////////////////////////////////////////////////////////////////
////////////////////////// FORMS //////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////////////////////////////////


	</script>
	</head>
	<body>
	<form id="UploadForm" method="post" action="OW.php" enctype="multipart/form-data"
		formData='{"script":"true"}' style='display:block'>
		<div id="drop_zone" class="drop_zone drop_zone_border" ondragover="dragOverHandler(event);" ondragleave="dragOverLeaveHandler(event);">
			<h1 align="center">Upload a New OW File</h1>
			  
			<?php $event=$OWProps[$eventNum];
				$name = $event['name'];
				$date = $event['date'];
				$cat = $event['cat'];
				$uploadDetails = $name . " (category $cat) held on " . $date;
				if( DEBUG ) {
					error_log( "uploadDetails=$uploadDetails, eventNum=$eventNum");
				}
			?>
			
			  <div id="UploadAFilePrompt">
			  <p style='text-align:center; font-size:22px; color:blue'>
			  	You are uploading the results for the
			  	<?php 
			  		echo $uploadDetails;
			  	?></p>
			  	
			  	
				<p>If you want to upload the results for a different event then click this button: 
					<button onclick='document.getElementById( "UploadForm").submit()' name="uploadAnotherButton"
						id="uploadAnotherButton">Upload New Results</button>
				</p>
			  	
			  	
			  	
			  <p id="UploadFollowingPrompt" style='text-align:center; font-size:20px; color:black'>
				Drag and drop the result file onto this window to validate the results and, if acceptable and you agree, 
				make them part of the Open Water Points calculations for <?php echo $yearBeingProcessed; ?> ...</p>
			</div>
			<div id="UploadStatus_area" class="status_area" >
				<h3 align="center" style='text-decoration: underline;color:black'>Upload Status</h3>
				<div id="UploadStatus-text" class="status-text">
					<p id='NoUploads' style='display:block'>(Waiting for the results file to be dropped here)</p>
					<p id='ReplaceUpload' style='display:none'>replacing</p>
				</div>
			</div>
		</div>
	</form>
	
	<!-- ------------------------------------  -->
	
	<form id="uploadFailure" method="post" action="OW.php" enctype="multipart/form-data"
		formData='{"script":"true"}' style='display:none'>
		<div class="drop_zone">
			<h1 align="center" class="UploadHeader" onclick='DropMsg( "idUploadFailed" )'>Upload Failed!</h1>
			<div id="idUploadFailed" class="status_area" style="color:black; display:none"></div>
 			<?php $event=$OWProps[$eventNum];
				$name = $event['name'];
				$date = $event['date'];
				$cat = $event['cat'];
				$uploadDetails = $name . " (category $cat) swim held on " . $date;
			?>
			
			<input type="hidden" name="eventNum" value="<?php echo $eventNum;?>">
			
			<div id="UploadFailurePrompt" class="upload_msg" style='text-align:left; font-size:20px; color:red; display:block'>
				<p> FAILURE! The result file you uploaded for the
				<?php 
					echo $uploadDetails;
				?>
				is NOT acceptable for processing. You now have the following three 
				options:
				<ul>
					<li>Fix the errors shown below and then click this button: 
						<button onclick='document.getElementById( "uploadFailure").submit()' name="uploadFixedResultButton"
							id="uploadFixedResultButton">Upload Repaired Results</button>
					</li>
					<li>Upload results for another event by clicking this button: 
						<button onclick='document.getElementById( "uploadFailure").submit()' name="uploadAnotherButton"
							id="uploadAnotherButton">Upload New Results</button>
					</li>
					<li>If you're finished uploading Open Water results close this window.</li>
				</ul
				</p>
			</div>
			<p class="upload_msg" style='text-align:left; font-size:20px; color:black'> If you do not understand or how to fix the error(s) 
			below please click on this email link: <span id="FailureStatus_email"></span>.
			</p>

			<div id="FailureStatus_area" class="status_area" style='color:black' >
				<h3 id="FailureStatus-text" align="center" style='font-size:20px; text-decoration: underline;color:red' >Upload Status</h3>
			</div>
		</div>
	</form>

	<!-- ------------------------------------  -->
	
	
	<form id="uploadSuccess" method="post" action="OW.php" enctype="multipart/form-data"
		formData='{"script":"true"}' style='display:none'>
		<div class="drop_zone">
			<h1 align="center" class="UploadHeader" onclick='DropMsg( "idUploadSuccess" )'>Upload Success!</h1>
			<div id="idUploadSuccess" class="status_area" style="color:black; display:none"></div>

			<?php $event=$OWProps[$eventNum];
				$name = $event['name'];
				$date = $event['date'];
				$cat = $event['cat'];
				$uploadDetails = $name . " (category $cat) swim held on " . $date;
			?>
			
			<div id="UploadSuccessPrompt" class="upload_msg" style='text-align:left; font-size:20px; color:blue; display:block'>
			<p> SUCCESS! The result file you uploaded for the
				<?php 
					echo $uploadDetails;
				?>
				is acceptable for processing and will be used the next time Open Water Points are calculated. 
				You now have the following three 
				options:
				<ul>
					<li>If you don't want these results processed for open water points then click this button:
						<button onclick='document.getElementById( "UploadForm").submit()' name="deleteButton" 
							id="deleteButton">Delete These Results</button>
					</li>
					<li>If you want to upload results for another event,
					 then click this button: 
						<button onclick='document.getElementById( "uploadSuccess").submit()' name="uploadAgainButton"
							id="uploadAgainButton">Upload New Results</button>
					</li>
					<li>If you're finished uploading Open Water results then close this window.</li>
				</ul
			</p>
			</div>
			<div id="Success_status_area" class="status_area" style='text-align:center'>
				<h3 id="SuccessStatus-text" align="center" style='text-decoration: underline;color:black'>Upload Status</h3>
			</div>
		</div>
	</form>

	<!-- ------------------------------------  -->
	
	
	<div id="count" style="display:none">1</div>
	</body>
	</html>
	<?php
	// Done drawing the drop zone for the user - let them decide what to do:
	if( DEBUG ) {
		error_log( "EXIT when at end of GenerateOWDropZone" );
	}
	exit;


} // end of GenerateOWDropZone()


?>