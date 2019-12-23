<?php
$debug = 1;		// set to 0 to turn off debugging.  >0 logs stuff to the console and other places

/*
** RSINDUpload.php - upload a file and store it for use on our server.
**
** When this file is requested we'll first look to see if there is any FILES data.  If there
** is NOT then that means it was probably requested this way:
**		https://pacificmasters.org/whatever/blah/blah/Upload.php
** i.e. by a human via a browser.  In that case we display a drop area into which the
** user drops a file.  When that happens then this file is requested AGAIN, but this time
** there will be POSTed data.  That data will include the file name the user dropped
** in the drop area and the contents of the file.  In this case this program will store
** the file.
*/

// Copyright (c) 2019 Bob Upshaw.  This software is covered under the Open Source MIT License

require_once "/usr/home/pacdev/Automation/Upload/lib/UploadSupport.php";

// initialization...
$yearBeingProcessed = date("Y");		// the year in which we are running
$previousYear = $yearBeingProcessed-1;	// the year prior to this year

// We will put ALL uploaded files into this directory:
$destinationDir = "UploadedFiles/RSIND/";
// Note:  the above directory is relative to the location of this script in the webserver tree.

$aborted = false;		// set to true if we aborted the upload

error_reporting( E_ERROR & E_PARSE & E_NOTICE & E_CORE_ERROR & E_DEPRECATED );

// thie following ini_set is there to allow us to read files with \r line termination!
ini_set("auto_detect_line_endings", true);

// A list of permitted file extensions
//$allowed = array('txt', 'csv', 'xlsx','xls');
$allowed = array('csv');		// this is all we currently accept for RSIND files

////////////////////////////////////////////////////////////////////////////////////////////////
// See if the request of this program is from a human user or the result of a POST:
////////////////////////////////////////////////////////////////////////////////////////////////
error_log( "ready to test post");

if( !empty( $_POST ) ) {
	// Non-empty POST - the only way this should happen is if a human requested this page through
	// our Drupal RSIND Upload page.  We need to confirm that before letting someone upload
	// a new RSIND file.
	$value = $_POST{'value'};
	$email = $_POST{'emailAck'};
	if( $debug ) {
		error_log( "got a post:");
		error_log( "post is: " . var_export( $_POST, true ) );
		error_log( "value is: $value, email=$email" );
	}
	if( !empty( $value ) ) {
		list( $isValidRequest, $expectedKey, $passedKey ) = ValidateKey( $value );
		if( !$isValidRequest ) {
			// Invalid access - blow them off with a bogus error message so they don't realize they almost
			// got in!
			InvalidRequest( $value, $expectedKey, $passedKey );
			exit;
		}
	} elseif( !empty( $email ) ) {
		$to = $_POST{'to'};
		$subject = $_POST{'subject'};
		$headers = 'From: RSIND Upload <uploads@pacificmasters.org>' . "\r\n" .
			'Reply-To: PAC Webmaster <webmaster@pacificmasters.org>' . "\r\n" .
			'X-Mailer: PHP/' . phpversion();
		mail( $to, $subject, $email, $headers  );
		//error_log( "send an email to '$to' re '$subject'" );
		exit;
	}
	
	////////////////////////////////////////////////////////////////////////////////////////////////
	// The user requested this page - draw a drop area and let the user make the next move:
	////////////////////////////////////////////////////////////////////////////////////////////////

	GeneratePageHead();
	?>
	<style>
		#drop_zone {
			position: fixed;
			overflow: auto;
			padding-right:5px;
			margin:0;
			box-sizing: border-box;
			top:0;
			left:0;
			width: 100%;
			height: 100%;
			border: 5px solid blue;
			text-align: center;
		}
		#status_area {
			display: inline-block;
			vertical-align: top;
			margin-right: auto;
			margin-left: auto;
			text-align:left;
			font-size: 12px;
			color: black;
			overflow-wrap: break-word;
			word-wrap: break-word;
			hyphens: auto;
			bottom: 20px;
			overflow:auto;
		}
		#status-text {
			margin: 0 auto;
		}
		.dropDiv {
			margin-right: 100px;
			margin-left: 50px;
		}
	</style>
	
	<script>
		var debug = <?php echo $debug; ?>;
		var myURL = location.href;
		if( debug ) console.log( "my url is '" + myURL + "'" );
		function dragOverHandler(ev) {
			if( debug ) console.log('File(s) in drop zone');
			// Prevent default behavior (Prevent file from being opened)
			ev.preventDefault();
			document.getElementById( "drop_zone" ).style.borderColor = "red";
		} // end of dragOverHandler()
		function dragOverLeaveHandler( ev ) {
			// Prevent default behavior (Prevent file from being opened)
			ev.preventDefault();
			document.getElementById( "drop_zone" ).style.borderColor = "blue";
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
			$('#upload').attr( 'action', myURL );
		
			$('#upload').fileupload({
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
					// result looks something like this:   {"status":"error", "msg":"$err"}
					if( debug ) console.log( "result: '" + result + "'" );
					var obj = "JSON Failed";
					var startMsg = "";
					var fullMsg = "";
					var nextId = GetNextId();
					var nextIdStr = "id" + nextId;
					try {
						var obj = JSON.parse( result );
					} catch( err ) {
						if( err.name == "SyntaxError" ) {
							// if necessary we may have to do something in here if we can't figure out the syntax error
						}
						startMsg = "Upload of " + fileName + " FAILED!  JSON Syntax Error.";
						fullMsg = "\n<p onclick=\"DropMsg('" + nextIdStr + "\')\" style='color:red'>" +
							startMsg + "&nbsp;&nbsp;&nbsp;</p>";
						fullMsg += FormatDropMessages( nextIdStr, err.message );
						startMsg += "  " + err.message;
					}
					if( obj != "JSON Failed" ) {
						var status = obj.status;
						var msg = ExtractMsg( obj.msg );
						var hidden = ExtractHiddenMsg( obj.msg );
						var drop = ExtractDropMsg( obj.msg );
						if( status == "error" ) {
							startMsg = "Upload of " + fileName + " FAILED!  " + msg;
							fullMsg = "\n<p onclick=\"DropMsg('" + nextIdStr + "\')\" style='color:red'>" +
								startMsg;
							fullMsg += "</p>\n";
						} else {
							startMsg = "Upload of " + fileName + " SUCCESSFUL!  " + msg;
							fullMsg = "<p onclick=\"DropMsg('" + nextIdStr + "\')\" style='color:black'>" +
								startMsg;
							fullMsg += "</p>\n";
						}
						fullMsg += FormatDropMessages( nextIdStr, drop );
						fullMsg += "\n<!-- " + hidden + "-->\n";
					}
					startMsg += "\n";
					UpdateScreenWithStatus( fullMsg, e, startMsg, drop, hidden );
				},
				fail:function(e, data){
					// Something has gone wrong!
					var fileName = data.files[0].name;
					var startMsg = "";
					var fullMsg = "";
					if( debug ) {
						console.log( "upload error: " );
						console.log( data);
					}
					startMsg = "Upload of " + fileName + " FAILED!  (Internal Error!)";
					fullMsg = "<p style='color:red'>" + startMsg + "</p>";
					UpdateScreenWithStatus( fullMsg, e, startMsg, "(The $('#upload').fileupload() failed.)", "" );
				}
			});
		});
		


		// 						var nextId = GetNextId();
		function GetNextId() {
			var id=$("#count").text();
			var newId = Number(id) + 1;
			$("#count").text(newId);
			if( debug ) console.log( "GetNextId: return " + id + ", next id is " + newId );
			return id;
		}
		
		//						fullMsg += FormatDropMessages( nextIdStr, drop );
		function FormatDropMessages( idStr, drop ) {
			return "<div class='dropDiv' id='" + idStr + "' style=\"display:none\">" + drop + "</div>";
		}
		
		/*
		 * ExtractMsg - extract the user message from a JSON message possibly containing a hidden message
		 *
		 * PASSED:
		 * 	msg - a string of the form
		 * 			xxxx  yyyy zzzz....
		 * 		where
		 * 			xxxx is the user message.
		 * 			yyyy is an optional drop message
		 * 			zzzz is 0 or more hidden messages
		 * 		and
		 * 			yyyy is of the form
		 * 				[[[ drop message... ]]]
		 * 			where
		 * 				drop message is one or more strings of the form
		 * 					! WARNING....
		 * 				or
		 * 					! ERROR....
		 * 		and
		 * 			zzzz.... is of the form
		 * 				((( hidden message ))) ....
		 * 			where
		 * 				hidden message is a string not containing ((( nor ))).
		 *
		 * 		NOTE:
		 * 			There can be 0 or more hidden messages of the form '(((yyyy)))'.
		 * 		and
		 * 			Exactly one 'xxxx' must be present but all the rest are optional
		 *
		 * RETURNED:
		 * 	msg - the user message, which is the 'xxxx' above.
		 *
		 */
		function ExtractMsg( msg ) {
			msg2 = msg.replace( /\[\[\[.*$/, "" );
			if( msg2.length == msg.length ) {
				// no drop messages so we have to remove hidden messages until end of string
				msg2 = msg.replace( /\(\(\(.*$/, "" );
			}
			return msg2;
		} // end of ExtractMsg()
		
		
		
		/*
		 * ExtractHiddenMsg - extract the hidden message(s) from a JSON message
		 *
		 * PASSED:
		 * 	msg - a string of the form describe by ExtractMsg()
		 *
		 * RETURNED:
		 * 	msg - the hidden message, e.g. the set of zzzz substrings concatenated (see ExtractMsg).
		 *
		 */
		function ExtractHiddenMsg( msg ) {
			var hidden = msg.indexOf( "(((" );
			if( hidden > 0 ) {
				// extract the hidden part of the message
				msg = msg.substr( hidden );
				msg = msg.replace( /\(\(\(/g, "" );
				msg = msg.replace( /\)\)\)/g, "" );
			} else {
				msg = "";
			}
			return msg;
		} // end of ExtractHiddenMsg()
								
		
		/*
		 * ExtractDropMsg - extract the drop message from a JSON message
		 *
		 * PASSED:
		 * 	msg - a string of the form describe by ExtractMsg()
		 *
		 * RETURNED:
		 * 	msg - the drop message, e.g. the yyyy substring bounded by [[[ and ]]]
		 *
		 * NOTES:
		 * 	Since the primary reason for the drop messages is to convey errors or warnings,
		 * 	the returned msg will be modified to insert a <br> in front of every ! WARNING
		 * 	and ! ERROR.
		 *
		 */
		function ExtractDropMsg( msg ) {
			// if there are any hidden messages in the passed msg then remove them:
			msg = msg.replace( /\(\(\(.*$/, "" );
			// now extract the drop message (if any)
			var drop = msg.indexOf( "[[[" );
			if( drop > 0 ) {
				// extract the drop part of the message
				msg = msg.substr( drop );
				msg = msg.replace( /\[\[\[/g, "" );
				msg = msg.replace( /\]\]\]/g, "" );
				// add some <br>'s
				msg = msg.replace( /! ERROR/g, "<br>! ERROR" );
				msg = msg.replace( /! WARNING/g, "<br>! WARNING" );
			} else {
				msg = "";
			}
		
			return msg;
		} // end of ExtractDropMsg()
						
								
		/*
		 * 	UpdateScreenWithStatus();
		 *
		 */
		function UpdateScreenWithStatus( fullMsg, e, startMsg, drop, hidden ) {
			document.getElementById( "NoUploads" ).style.display="none";
			document.getElementById( "initialPrompt" ).style.display="none";
			document.getElementById( "followingPrompt" ).style.display="block";
			document.getElementById( "status-text" ).innerHTML = fullMsg + "<p><hr>" +
				document.getElementById( "status-text" ).innerHTML;
			dragOverLeaveHandler( e );
			
			// send email
			drop = drop.replace( /<br>/g, "\n" );
			msg = startMsg + "\n" + drop + "\n\n" + hidden;
			$.post( myURL, {"to" : "uploads@pacificmasters.org", "subject" : "RSIND file uploaded",
				   "emailAck" : msg}, function( data ) {
			});
			
		} // end of UpdateScreenWithStatus();


	</script>
	</head>
	<body>
	<form id="upload" method="post" action="filled in by jquery" enctype="multipart/form-data"
		formData='{"script":"true"}'>
			<div id="drop_zone" ondragover="dragOverHandler(event);" ondragleave="dragOverLeaveHandler(event);">
			  <h1 align="center">Upload a New RSIND File</h1>
			  <p id="initialPrompt" style='text-align:center; font-size:22px; color:blue'>
				Drag and drop a file onto this window to upload a new RSIND file ...</p>
			  <p id="followingPrompt" style='text-align:center; font-size:22px; color:blue; display:none'>
				Drag and drop another file onto this window or close this window when done.</p>				
				<div id="status_area">
					<h3 align="center" style='text-decoration: underline;color:black'>Upload Status</h3>
					<div id="status-text">
						<p id='NoUploads'>(No uploads performed yet)</p>
					</div>
				</div>
			</div>
		</div>
	</form>
	<div id="count" style="display:none">1</div>
	</body>
	</html>
	<?php
	// Done drawing the drop zone for the user - let them decide what to do:
	exit;

} else if( isset($_FILES['files']) ) {

	////////////////////////////////////////////////////////////////////////////////////////////////
	// The user has dropped a file to be uploaded.
	////////////////////////////////////////////////////////////////////////////////////////////////

	if( $debug ) {
		error_log( "got files:");
		error_log( "get is: " . var_export( $_GET, true ) );
		error_log( "post is: " . var_export( $_POST, true ) );
		error_log( "files is: " . var_export( $_FILES, true ) );
	}
	if( $_FILES['files']['error'][0] === UPLOAD_ERR_OK ) {
		//uploading successfully done
		// First make sure it's a file type that we're expecting:
		$extension = pathinfo($_FILES['files']['name'][0], PATHINFO_EXTENSION);
		if(!in_array(strtolower($extension), $allowed)){
			SetError( "The file " . $_FILES['files']['name'][0] . " has an invalid extension - upload aborted!" );
			exit;
		}
		// Next, move the uploaded file from the temp location to the location we really want to use:
		$message = CreateDirIfNecessary( $destinationDir);
		if( $message != "" ) {
			// failed to create necessary directory
			SetError( $message );
			exit;
		}
		// But, before we move it make sure it won't over-write an existing file.  We don't allow that.
		$message = FileAlreadyExists( $destinationDir, $_FILES['files']['name'][0] );
		if( $message != "" ) {
			// file already exists
			SetError( $message );
			exit;
		}
		if(move_uploaded_file($_FILES['files']['tmp_name'][0], $destinationDir.$_FILES['files']['name'][0])){
			// We've got the uploaded file.  Do some simple validation before we decide whether or not
			// we'll keep this file:
			list( $arrOfLines, $status ) = ValidateRSINDFile( $destinationDir, $_FILES['files']['name'][0] );
			$count = count( $arrOfLines );
			if( $status == 1 ) {
				// the RSIND file has a problem - move it out of the way to allow another attempt
				$message = ArchiveRSINDFile( $destinationDir, $_FILES['files']['name'][0] );
				$arrOfLines[$count] = $message;
				SetError( $arrOfLines );
				exit;
			} elseif( $status == 2 ) {
				// the RSIND file already existed in at least one destination, but copied to others where
				// it didn't exist:
				SetError( $arrOfLines );
				exit;
			} else {
				//echo '{"status":"success"}';
				SetSuccess( $arrOfLines );
				exit;
			}
		} else {
			SetError( "The file " . $_FILES['files']['tmp_name'][0] . " failed to upload!" );
			exit;
		}
	} else {
		// The file failed to be uploaded:
		$message = CodeToMessage( $_FILES['files']['error'][0] );
		SetError( $message );
		exit;
	}
}

////////////////////////////////////////////////////////////////////////////////////////////////
// If we got here there was no POST and no FILES data - assume a hack:
////////////////////////////////////////////////////////////////////////////////////////////////

InvalidRequest( "(no value)", "", "(no passed key)" );
exit;


////////////////////////////////////////////////////////////////////////////////////////////////
// END OF MAIN PROGRAM - Support PHP functions
////////////////////////////////////////////////////////////////////////////////////////////////



/*
 * GeneratePageHead - write out the HTML to start our page
 *
 */
function GeneratePageHead() {
	global $debug;
	?>
	<!DOCTYPE html>
	<html lang="en" class="no-js">
	<head>
		<meta charset="utf-8">
		<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
		<script src="/usr/home/pacdev/Automation/Upload/Code/jqUpload/assets/js/jquery.ui.widget.js"></script>
		<script src="/usr/home/pacdev/Automation/Upload/Code/jqUpload/assets/js/jquery.iframe-transport.js"></script>
		<script src="/usr/home/pacdev/Automation/Upload/Code/jqUpload/assets/js/jquery.fileupload.js"></script>

	<?php
	if( $debug ) {
		echo "<title>Upload a New RSIND File Debugging</title>\n";
	} else {
		echo "<title>Upload a New RSIND File</title>\n";
	}
} // end of GeneratePageHead()
	
/*
		<script src="jqUpload/assets/js/jquery.ui.widget.js"></script>
		<script src="jqUpload/assets/js/jquery.iframe-transport.js"></script>
		<script src="jqUpload/assets/js/jquery.fileupload.js"></script>
*/
	
	

//	list( $isValidRequest, $expectedKey, $passedKey ) = ValidateKey( $value );
/*
 * ValidateKey - validate the passed key coming from the Drupal page.
 *
 * PASSED:
 * 	$value - the full string POSTed by the Drupal page
 *
 * RETURNED:
 * 	$isValidRequest - true if the passed string is what is expected, false otherwise.
 * 	$expectedKey - the key that we expect to be contained in value
 * 	$passedKey - the key actually contained in value.  Could be an empty string (which, of course,
 * 		is invalid.)
 *
 */
function ValidateKey( $value ) {
	$isValidRequest = false;
	$expectedKey = US_GenerateValidKey();
	$passedKey = US_ExtractKeyFromValue( $value );
	if( US_KeysMatch( $passedKey, $expectedKey ) ) {
		$isValidRequest = true;
	}
	return array( $isValidRequest, $expectedKey, $passedKey );
} // end of ValidateKey()


//	InvalidRequest( $value, $expectedKey, $passedKey );
/*
 * InvalidRequest - generate the HTML response to an invalid attempt to access our
 * 		RSIND Upload page.
 *
 * PASSED:
 * 	$value - the value (string) POSTed to this script (supposedly) from our Drupal page.
 * 		Likely encrypted.  It is supposed to contain the key.  May be an empty string.
 * 	$expectedKey - the (unencrypted) key we expected to find inside the $value string.
 * 	$passedKey - the actual key we found inside the $value string.  May be an empty string.
 *
 * NOTES:
 * 	We're actually generating a 404 page so the person who caused this invalid request to be
 * 	made doesn't get a hint as to what they almost did.  The passed values are never shown
 * 	to the user - they just get logged.
 */
function InvalidRequest( $value, $expectedKey, $passedKey ) {
	global $debug;
	GeneratePageHead();
	?>
	</head>
	<body>
	<h1 align="center">404:  Page Not Found</h1>
	</body>
	</html>

	<?php
	if( $debug ) {
		error_log( __FILE__ . ": InvalidRequest(): value='$value', expectedKey='" .
				  "$expectedKey', passedKey='$passedKey'" );
	}
} // end of InvalidRequest()


/*
 * SetError - Generate the error return if the FILE sent to our script failed to be uploaded.
 *
 * PASSED:
 * 	$err - one of:
 * 		- an error string to accompany the error.  This will be displayed to the user.
 * 		- an array of error strings, all of which will be sent to the browser, but only some of
 * 			them will be displayed to the user.  
 *
 * NOTES:
 * 	All control characters will be removed (e.g. newlines, etc.)
 * 	
 */
function SetError( $err ) {
	global $debug;
	$fullMsg = "";
	if( is_array( $err ) ) {
		foreach( $err as $line ) {
			$fullMsg .= $line . " ";
		}
	} else {
		$fullMsg = $err;
	}
	$len = strlen( $fullMsg );
	$fullMsg = preg_replace('/[[:cntrl:]]/', '', $fullMsg);
	$numCtrlChars = $len - strlen( $fullMsg );
	$len = strlen( $fullMsg );
	// escape double quotes:
	$fullMsg = preg_replace( '/"/', '\"', $fullMsg );
	$numQuotes = strlen( $fullMsg ) - $len;
	if( $debug ) {
		error_log( __FILE__ . ": SetError(): $numCtrlChars ctrl chars removed, " .
				  "$numQuotes quotes escaped: $fullMsg\n>>>Done Full<<<" );
	}
	echo '{"status":"error", "msg":"' . $fullMsg . '"}';
} // end of SetError()



// 				SetSuccess( $arrOfLines );
function SetSuccess( $arrOfLines ) {
		$fullMsg = "";
		foreach( $arrOfLines as $line ) {
			$fullMsg .= $line . " ";
		}
		if( $debug ) {
			error_log( __FILE__ . ": SetSuccess(): $fullMsg\n>>>Done Full<<<" );
		}
		echo '{"status":"success", "msg":"' . $fullMsg . '"}';
} // end of SetSuccess()




/*
 * CodeToMessage - convert the error code generated when the upload failed into a text string.
 *
 * PASSED:
 * 	$code - the error code.
 *
 * RETURNED:
 * 	$message - a meaningful message representing the passed code.
 *
 */
function CodeToMessage($code) {
	switch ($code) {
		case UPLOAD_ERR_INI_SIZE:
			$message = "The uploaded file exceeds the upload_max_filesize directive in php.ini";
			break;
		case UPLOAD_ERR_FORM_SIZE:
			$message = "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form";
			break;
		case UPLOAD_ERR_PARTIAL:
			$message = "The uploaded file was only partially uploaded";
			break;
		case UPLOAD_ERR_NO_FILE:
			$message = "No file was uploaded";
			break;
		case UPLOAD_ERR_NO_TMP_DIR:
			$message = "Missing a temporary folder";
			break;
		case UPLOAD_ERR_CANT_WRITE:
			$message = "Failed to write file to disk";
			break;
		case UPLOAD_ERR_EXTENSION:
			$message = "File upload stopped by extension";
			break;
		default:
			$message = "Unknown upload error: $code";
			break;
	}
	return $message;
} // end of CodeToMessage()


// return a file pointer or false.
/*
 * CreateDirIfNecessary - create the passed directory if it doesn't already exist.
 *
 * PASSED:
 * 	$dir - the name of the directory.  Can be a simple name or partial path (relative to CWD), or a full
 * 		path name.
 *
 * RETURNED:
 * 	$status - an empty string if all is OK.  Error message if there is a failure.
 *
 */
function CreateDirIfNecessary( $dir ) {
	$status = "";
	// create the destination directory if it doesn't exist:
	$okDir = 1;
	if( !file_exists( $dir ) ) {
		$okDir = mkdir( $dir, 0700, true );
		if( !$okDir ) {
			$status = "Unable to create destination directory '$dir' - uploaded file not saved!";
		}
	}
	return $status;
} // end of CreateDirIfNecessary()




// 		$message = FileAlreadyExists( $destinationDir, $_FILES['files']['name'][0] );
/*
 * FileAlreadyExists - see if the passed file already exists
 *
 * PASSED:
 * 	$destinationDir - the directory holding the file
 * 	$fileName - the file we look for
 *
 * RETURNED:
 * 	$status - an empty string if the file does not exist in the directory, otherwise a message
 * 		saying that the file already exists.
 *
 */
function FileAlreadyExists( $destinationDir, $fileName ) {
	$status = "";
	$fullFileName = "$destinationDir/$fileName";	// may be partial path relative to CWD
	if( file_exists( $fullFileName ) ) {
		$status = "File already exists - upload Aborted!";
	}
	return $status;
} // end of FileAlreadyExists()




/*
 * ValidateRSINDFile - Once we've uploaded a file we'll validate it here
 *
 * PASSED:
 * 	$destinationDir - the directory containing the newly uploaded file
 * 	$fileName - the file
 *
 * RETURNED:
 * 	$message - a message to be sent back to the browser
 * 	$status - 0 if OK, 1 if not.
 *
 */
function ValidateRSINDFile( $destinationDir, $fileName ) {
	global $debug;
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
				$status = 1;
				$message[0] = "Invalid file header.  Header found: <br>'" . $line .
					"'<br>but this was expected: <br>'" .
					$validRSINDHeader . "'<br>";
			}
		} else {
			$status = 1;
			$message[0] = "Internal error - unable to read from the file just uploaded - upload aborted!";
		}
	} else {
		$status = 1;
		$message[0] = "Internal error - unable to open the file just uploaded - upload aborted!";
	}
	
	if( $status == 0 ) {
if( $debug ) {
	error_log( "status is 0");
}
error_log( "status is still 0");
		//$message = shell_exec( "/usr/home/pacdev/Automation/scripts/TestInstallRSIND.bash $fileName 2019 2>&1" );
		exec( "/usr/home/pacdev/Automation/scripts/TestInstallRSIND.bash $fileName 2019 2>&1",
						$message, $status );
		if( $debug ) {
			error_log( " shell_exec done, exitStatus=$status");
			error_log( "line 0: '$message[0]'");
			error_log( "line 1: '$message[1]'");
			error_log( "line 2: '$message[2]'");
			error_log( "line 3: '$message[3]'");
		}
	}
	
	return array( $message, $status );
} // end of ValidateRSINDFile()


// 				ArchiveRSINDFile( $destinationDir, $_FILES['files']['name'][0] );
function ArchiveRSINDFile( $destinationDir, $fileName ) {
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
} // end of ArchiveRSINDFile()


?>
