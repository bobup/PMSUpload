<?php
/*
** rsind.php - this page handles a submit from the main Upload page when the requested
** upload is for an RSIND file. This page will continue to be requested for every file 
** uploaded (or attempted). It is redirected to by PostAnUpload.php.
**
** NOTE: For DEBUGGING see lib/UploadSupport.php and read about DEBUG*
**
*/

session_start();
error_reporting( E_ERROR & E_PARSE & E_NOTICE & E_CORE_ERROR & E_DEPRECATED & E_COMPILE_ERROR &
	E_RECOVERABLE_ERROR & E_ALL );
// thie following ini_set is there to allow us to read files with \r line termination!
ini_set("auto_detect_line_endings", true);

$scriptName = $_SERVER['SCRIPT_NAME'];

require_once "/usr/home/pacdev/Automation/PMSUpload/Code/lib/LocalSupport.php";
$localProps = LS_ReadLocalProps();
require_once $localProps[0];
if( DEBUG ) {
	error_log( "Entered $scriptName, DEBUG=" . DEBUG . "\n" );
}


// $NOCOPY is set to non-empty if we don't really want to copy RSIND files to the appropriate
// destination locations. This is useful during debugging. During normal operations we want
// to set $NOCOPY to an empty string.
$NOCOPY = NO_COPY_RSIND;


// initialization...
$yearBeingProcessed = date("Y");		// the year in which we are running
$previousYear = $yearBeingProcessed-1;	// the year prior to this year

$uploadType = $_SESSION['uploadType'];
$UserName = $_SESSION['UserName'];
$encrypted = $_SESSION['encrypted'];
$UsersFullName = $_SESSION['UsersFullName'];
$obUserName = $_SESSION['obUserName'];


/// before validating the key let's see what we got:
	if( DEBUG ) {
		error_log( "Rsind.php: not sure about the key yet, but...:");
		error_log( "get is: " . var_export( $_GET, true ) );
		error_log( "post is: " . var_export( $_POST, true ) );
		error_log( "files is: " . var_export( $_FILES, true ) );
}

/////////////////////////////////////// TEST KEY ////////////////////////////////////
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
		if( DEBUG ) {
			error_log( "Exit $scriptName - expired key\n" );
		}
		exit;
	} else {
		// invalid key (probably missing)
		US_InvalidRequest( $obUserName, "", "", $uploadType, $scriptName, 1 );
	if( DEBUG ) {
		error_log( "Exit $scriptName - invalid (or missing) key\n" );
	}
		exit;
	}
}
///////////////////////////////////// END TEST KEY ////////////////////////////////////


// We will put ALL uploaded files into this directory:
$destinationDirPartial = "../UploadedFiles/RSIND/";
// Note:  the above directory is relative to the location of this script in the webserver tree.
$destinationDir = realpath( $destinationDirPartial ) . "/";
if( DEBUG ) {
	error_log( "Upload destination (archive) directory is '$destinationDir'\n" );
}

// A list of permitted file extensions
//$allowed = array('txt', 'csv', 'xlsx','xls');
$allowed = array('csv');		// this is all we currently accept for RSIND files


if( DEBUG > 1 ) {
	error_log( "rsind.php: ready to test FILES");
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
		error_log( "Rsind.php: got files:");
		error_log( "get is: " . var_export( $_GET, true ) );
		error_log( "post is: " . var_export( $_POST, true ) );
		error_log( "files is: " . var_export( $_FILES, true ) );
		if( $originalFileName == $convertedFileName ) {
			$sameNames="(The same)";
		} else {
			$sameNames = "(NOT the same)";
		}
		error_log( "originalFileName='$originalFileName', convertedFileName='$convertedFileName' $sameNames");
	}

	if( $_FILES['files']['error'][0] === UPLOAD_ERR_OK ) {
		if( DEBUG > 1 ) {
			error_log( "Rsind.php: Uploading successfully done" );
		}
		//uploading successfully done
		// First make sure it's a file type that we're expecting:
		$extension = pathinfo($originalFileName, PATHINFO_EXTENSION);
		if(!in_array(strtolower($extension), $allowed)){
			US_SetError( "The file $originalFileName (aka '$convertedFileName')  has an invalid extension " .
					 "- upload aborted!" );
if( DEBUG ) {
	error_log( "Exit $scriptName after calling and returning from US_SetError() - see error above\n" );
}
			exit;
		}
		// Next, move the uploaded file from the temp location to the location we really want to use:
		$message = US_CreateDirIfNecessary( $destinationDir);
		if( $message != "" ) {
			// failed to create necessary directory
			US_SetError( $message );
if( DEBUG ) {
	error_log( "Exit $scriptName - see error above\n" );
}
			exit;
		}

		if( ! DEBUG_RSIND ) {
			// But, before we move it make sure it won't over-write an existing file.  We don't allow that.
			$message = US_FileAlreadyExists( $destinationDir, $convertedFileName );
			if( $message != "" ) {
				// file already exists
				US_SetError( $message );
if( DEBUG ) {
	error_log( "Exit $scriptName - see error above\n" );
}
				exit;
			}
		}

		if(move_uploaded_file($_FILES['files']['tmp_name'][0], $destinationDir.$convertedFileName)) {
			// We've got the uploaded file.  Do some simple validation before we decide whether or not
			// we'll keep this file:
			list( $arrOfLines, $status ) = ValidateRSINDFile( $destinationDir, $convertedFileName );
			array_push( $arrOfLines, "(exec status: $status )" );
			$count = count( $arrOfLines );
			if( $status == 1 ) {
				// the RSIND file has a problem - move it out of the way to allow another attempt
				$message = ArchiveUploadedFile( $destinationDir, $convertedFileName );
				$arrOfLines[$count] = $message;
				US_SetError( $arrOfLines );
if( DEBUG ) {
	error_log( "Exit $scriptName - see error above\n" );
}
				exit;
			} elseif( $status == 2 ) {
				// the RSIND file already existed in at least one destination, but copied to others where
				// it didn't exist:
				US_SetError( $arrOfLines );
if( DEBUG ) {
	error_log( "Exit $scriptName - see error above\n" );
}
				exit;
			} elseif( $status != 0 ) {
				// something went wrong with the exec
				US_SetError( $arrOfLines );
if( DEBUG ) {
	error_log( "Exit $scriptName - see error above\n" );
}
				exit;
			} else {
				//echo '{"status":"success"}';
				SetSuccess( $arrOfLines );
if( DEBUG ) {
	error_log( "Exit $scriptName - see SUCCESS above\n" );
}
				exit;
			}
		} else {

			US_SetError( "The file " . $_FILES['files']['tmp_name'][0] . " failed to upload!" );
if( DEBUG ) {
	error_log( "Exit $scriptName - see error above\n" );
}
			exit;
		}
	} else {
		// The file failed to be uploaded:
		if( DEBUG > 1 ) {
			error_log( "The file failed to be uploaded" );
		}
		$message = US_CodeToMessage( $_FILES['files']['error'][0] );
		US_SetError( $message );
if( DEBUG ) {
	error_log( "Exit $scriptName - see error above\n" );
}
		exit;
	}

} // end of isset($_FILES['files'... 
elseif( !empty( $_POST ) ) {
	// Non-empty POST - the only way this should happen is if a human requested this page through
	// our custom upload html page.  We need to confirm that before letting someone upload
	// a new RSIND or OW file.
	$email = $_POST['emailAck'];

	if( DEBUG ) {
		error_log( "No Files found:");
		error_log( "get is: " . var_export( $_GET, true ) );
		error_log( "post is: " . var_export( $_POST, true ) );
		error_log( "files is: " . var_export( $_FILES, true ) );
		error_log( "uploadType is: '$uploadType', UserName='$UserName', email='$email'" );
	}
	
	if( !empty( $email ) ) {
		$to = $_POST['to'];
		$subject = $_POST['subject'];
		US_SendEmail( $to, "RSIND", $subject, $email );
		if( DEBUG ) {
			error_log( "send an email to '$to' re '$subject'" );
		}
if( DEBUG ) {
	error_log( "Exit $scriptName - see email sent above\n" );
}
		exit;
	}
} // end of !empty( $_POST...
	
	
// we get here if there was no POSTed data and there were no FILEs...
// in that case just show the Drop window and let the user give us a file:
US_GeneratePageHead( "RSIND" );
US_GenerateDropZone( $UsersFullName, "RSIND" );
if( DEBUG ) {
	error_log( "Exit $scriptName - after US_GenerateDropZone()\n" );
}
exit;







// 				ArchiveUploadedFile( $destinationDir, $_FILES['files']['name'][0] );
/*
 * ArchiveUploadedFile - rename the archived RSIND file to something that will allow us to re-upload the same file.
 *
 * PASSED:
 *	$destinationDir - the directory containing the file
 *	$fileName - the simple file name of the file to be renamed.
 *
 * RETURNED:
 *	$status - an error string if we have a problem, or an empty string if all is OK.
 *
 * Notes:
 *	Normally an uploaded RSIND file is never allowed to be uploaded again, but if there was an error
 *	with that file that prevented it from being uploaded successfully to any of the appropriate application
 * 	directories (as per the TestInstallRSIND.bash script) then we want to "move" this uploaded file out of the
 *	way so the user can try again.  We do this by renaming it with a ".bad" extension.  If that file already
 *	exists we delete it and then create another.
 */
function ArchiveUploadedFile( $destinationDir, $fileName ) {
	$status = "";
	$fullFileName = "$destinationDir/$fileName";	// may be partial path relative to CWD
	$newName = $fileName . ".bad";
	$fullNewName = "$destinationDir/$newName";		// may be partial path relative to CWD
	// we'll rename '$fileName' to '$newName', but first we'll make sure there isn't
	// already a '$newName'.
	if( file_exists( $fullNewName ) ) {
		// already exists - remove it
		if( !unlink( $fullNewName ) ) {
			$status = " (oops...Unable to delete an old archived copy ($newName))";
		}
	}
	if( $status == "" ) {
		// OK so far...
		if( !rename( $fullFileName, $fullNewName ) ) {
			$status = " (oops...Unable to rename '$fileName' to '$newName')";
		}
	}
	return $status;
} // end of ArchiveUploadedFile()



/*
 * ValidateRSINDFile - Once we've uploaded a file we'll validate it here
 *
 * PASSED:
 * 	$destinationDir - the directory containing the newly uploaded file
 * 	$fileName - the file
 *
 * RETURNED:
 * 	$message - a message (array of strings) to be sent back to the browser
 * 	$status - 0 if OK, 1 if not.
 *
 */
function ValidateRSINDFile( $destinationDir, $fileName ) {
	global $yearBeingProcessed;
	global $localProps;
	global $NOCOPY;
	$message = array();
	$status = 0;  // assume all ok
	$fullFileName = "$destinationDir/$fileName";	// may be partial path relative to CWD
	// we expect the header line to look like this:
	$validRSINDHeader="ClubAbbr,SwimmerID,FirstName,MI,LastName,Address1,City,StateAbbr,Zip,Country," .
		"BirthDate,Sex,RegDate,EMailAddress,RegNumber";
	$fp = fopen( $fullFileName, "r" );
	if( $fp ) {
		// got the file open - read the header line
		$line = fgets( $fp );
		if( $line ) {
			$line = rtrim( $line );
			if( $line != $validRSINDHeader ) {
				// bad header in supplied file - let's figure out why...
				$status = 1;
				$lineLen = strlen( $line );
				$validLen = strlen( $validRSINDHeader );
				if( $lineLen != $validLen ) {
					// expected header is a different length than the supplied header
					$message[1] = "The length of the header found ($lineLen) is different " .
						"from the length of the expected header ($validLen)<br>";
				} else {
					// there are one or more characters not matching between the headers
					$index = LocationOfFirstNonMatch( $line, $validRSINDHeader, $lineLen );
					$message[1] = "The two headers differ starting at character #$index<br>";
				}
				$message[0] = "Invalid file header.  Header found: <br>'" . $line .
					"'<br>but this was expected: <br>'" .
					$validRSINDHeader . "'<br>";
				if( DEBUG > 2 ) {
					for( $i = 0; $i < $lineLen; $i++ ) {
						error_log( "Line[$i] = $line[$i]" );
					}
					for( $i = 0; $i < $validLen; $i++ ) {
						error_log( "validRSINDHeader[$i] = $validRSINDHeader[$i]" );
					}
				}
			}
		} else {
			$status = 1;
			$message[0] = "Internal error - unable to read from the file just uploaded ($fullFileName) - upload aborted!";
		}
	} else {
		$status = 1;
		$message[0] = "Internal error - unable to open the file just uploaded ($fullFileName) - upload aborted!";
	}
	
	if( $status == 0 ) {
		if( DEBUG ) {
			error_log( "ValidateRSINDFile(): about to exec($localProps[1]" .
			 " $destinationDir '$fileName' $yearBeingProcessed " . DEBUG . 
			 " $NOCOPY 2>&1, $message, $status)" );
		}
		exec( $localProps[1] .
			" $destinationDir '$fileName' $yearBeingProcessed " . DEBUG .
			" $NOCOPY 2>&1", $message, $status );

		if( DEBUG ) {
			error_log( "exec done for the year $yearBeingProcessed, exitStatus=$status, NOCOPY=$NOCOPY");
			error_log( "line 0: '$message[0]'");
			error_log( "line 1: '$message[1]'");
			error_log( "line 2: '$message[2]'");
			error_log( "line 3: '$message[3]'");
		}
	}
	
	return array( $message, $status );
} // end of ValidateRSINDFile()


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
function SetSuccess( $arrOfLines ) {
		$fullMsg = "";
		foreach( $arrOfLines as $line ) {
			$fullMsg .= $line . " ";
		}
		if( DEBUG ) {
			error_log( __FILE__ . ": SetSuccess(): $fullMsg\n>>>Done Full<<<" );
		}
		echo '{"status":"success", "msg":"' . $fullMsg . '"}';
} // end of SetSuccess()





?>