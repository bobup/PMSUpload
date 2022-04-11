<?php
$debug = 0;		// set to 0 to turn off debugging.  >0 logs stuff to the console and other places


if( $debug ) {
	error_log( "Entered RSINDUpload.php\n" );
}
/*
** RSINDUpload.php - upload a file and store it for use on our server.
**
** When this file is requested we'll first look to see if there is any FILES data.  If there
** is NOT then that means it was probably requested this way:
**		https://HostingPacmastersServer.org/whatever/blah/blah/RSINDUpload.php
** i.e. by a human via a browser.  In that case we display a drop area into which the
** user drops a file.  When that happens then this file is requested AGAIN, but this time
** there will be POSTed data.  That data will include the file name the user dropped
** in the drop area and the contents of the file.  In this case this program will attempt to store
** the file in an archive directory and also all directories that need it (depends on the TestInstallRSIND.bash
** script.)
*/

// Copyright (c) 2019 Bob Upshaw.  This software is covered under the Open Source MIT License


require_once "/usr/home/pacdev/Automation/PMSUpload/lib/UploadSupport.php";

// initialization...
$yearBeingProcessed = date("Y");		// the year in which we are running
$previousYear = $yearBeingProcessed-1;	// the year prior to this year

// $emailRecipients is the recipient of all emails sent by this program:
$emailRecipients = "uploads@pacificmasters.org";
$emailRecipients = "bobup@acm.org";			// for testing...

// If we get far enough along with this process where we have a good idea of who the user is that
// is trying to upload a file we will remember their full name here:
$UsersFullName = "(?)";		// full name of valid UserName (if valid)


// We will put ALL uploaded files into this directory:
$destinationDirPartial = "../UploadedFiles/RSIND/";
// Note:  the above directory is relative to the location of this script in the webserver tree.
$destinationDir = realpath( $destinationDirPartial ) . "/";
if( $debug ) {
	error_log( "Upload destination (archive) directory is '$destinationDir'\n" );
}

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
	$UserName = $_POST{'UserName'};
	if( $debug ) {
		error_log( "got a post:");
		error_log( "post is: " . var_export( $_POST, true ) );
		error_log( "value is: '$value', email='$email', UserName='$UserName'" );
	}
	if( !empty( $value ) ) {
		list( $isValidRequest, $expectedKey, $passedKey ) = ValidateKey( $value );
		if( !$isValidRequest ) {
			// Invalid access - blow them off with a bogus error message so they don't realize they almost
			// got in!
			InvalidRequest( $value, $expectedKey, $passedKey );
			exit;
		} else {
			if( $debug ) {
				error_log( "This is a valid request\n" );
			}
		}
	} elseif( !empty( $email ) ) {
		$to = $_POST{'to'};
		$subject = $_POST{'subject'};
		SendEmail( $to, $subject, $email );
		error_log( "send an email to '$to' re '$subject'" );
		exit;
	} elseif( !empty( $UserName ) ) {
		if( $debug ) {
			error_log( "got a User Name: '$UserName'");
		}
		list( $isValidRequest, $fullName, $obUserName ) = US_ValidateUserName( $UserName );
		if( $fullName != "" ) {
			$UsersFullName = $fullName;
		}
		if( !$isValidRequest ) {
			// Invalid access - blow them off with a bogus error message so they don't realize they almost
			// got in!
			InvalidRequest( $obUserName, "", "" );
			exit;
		} else {
			if( $debug ) {
				error_log( "This is a valid request\n" );
			}
		}
	} else {
		# if we got here then there was a post but nothing recognized was posted
		$hdrs = GetHeaderList();
		InvalidRequest( "(no value)", "", "(no passed key)", $hdrs );
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
					if( debug ) console.log( "fileName: '" + fileName + "', result: '" + result + "'" );
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
						var msg2 = ExtractMsg( obj.msg );
						var hidden = ExtractHiddenMsg( obj.msg );
						var drop = ExtractDropMsg( obj.msg );
						if( status == "error" ) {
							startMsg = "Upload of " + fileName + " FAILED!  " + msg2;
							fullMsg = "\n<p onclick=\"DropMsg('" + nextIdStr + "\')\" style='color:red'>" +
								startMsg;
							fullMsg += "</p>\n";
						} else {
							startMsg = "Upload of " + fileName + " SUCCESSFUL!  " + msg2;
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
			$.post( myURL, {"to" : <?php echo "'" . $emailRecipients . "'"; ?>, 
				"subject" : "RSIND file uploaded by <?php echo "'" . $UsersFullName . "'"; ?> ",
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

	$originalFileName = $_FILES['files']['name'][0];
	// convert the file name to replace whitespace with underscore and remove brackets, braces, and parens:
	$convertedFileName = preg_replace( "/\s/", "_", $originalFileName );
	$convertedFileName = preg_replace( "/[\(\)]/", "", $convertedFileName );
	$convertedFileName = preg_replace( "/[\{\}]/", "", $convertedFileName );
	$convertedFileName = preg_replace( "/[\[\]]/", "", $convertedFileName );
	
	if( $debug ) {
		error_log( "got files:");
		error_log( "get is: " . var_export( $_GET, true ) );
		error_log( "post is: " . var_export( $_POST, true ) );
		error_log( "files is: " . var_export( $_FILES, true ) );
		error_log( "originalFileName='$originalFileName', convertedFileName='$convertedFileName'");
	}
	if( $_FILES['files']['error'][0] === UPLOAD_ERR_OK ) {
		//uploading successfully done
		// First make sure it's a file type that we're expecting:
		$extension = pathinfo($originalFileName, PATHINFO_EXTENSION);
		if(!in_array(strtolower($extension), $allowed)){
			SetError( "The file $originalFileName (aka '$convertedFileName')  has an invalid extension " .
					 "- upload aborted!" );
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
		$message = FileAlreadyExists( $destinationDir, $convertedFileName );
		if( $message != "" ) {
			// file already exists
			SetError( $message );
			exit;
		}

		if(move_uploaded_file($_FILES['files']['tmp_name'][0], $destinationDir.$convertedFileName)) {
			// We've got the uploaded file.  Do some simple validation before we decide whether or not
			// we'll keep this file:
			list( $arrOfLines, $status ) = ValidateRSINDFile( $destinationDir, $convertedFileName );
			array_push( $arrOfLines, "(exec status: $status )" );
			$count = count( $arrOfLines );
			if( $status == 1 ) {
				// the RSIND file has a problem - move it out of the way to allow another attempt
				$message = ArchiveRSINDFile( $destinationDir, $convertedFileName );
				$arrOfLines[$count] = $message;
				SetError( $arrOfLines );
				exit;
			} elseif( $status == 2 ) {
				// the RSIND file already existed in at least one destination, but copied to others where
				// it didn't exist:
				SetError( $arrOfLines );
				exit;
			} elseif( $status != 0 ) {
				// something went wrong with the exec
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

$hdrs = GetHeaderList();
InvalidRequest( "(no value)", "", "(no passed data)", $hdrs );
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
	if( $debug ) {
		error_log( "inside GeneratePageHead" );
	}
	?>
	<!DOCTYPE html>
	<html lang="en" class="no-js">
	<head>
		<meta charset="utf-8">
		<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
		<script src="jqUpload/assets/js/jquery.ui.widget.js"></script>
		<script src="jqUpload/assets/js/jquery.iframe-transport.js"></script>
		<script src="jqUpload/assets/js/jquery.fileupload.js"></script>

	<?php
	if( $debug ) {
		echo "<title>Upload a New RSIND File Debugging</title>\n";
	} else {
		echo "<title>Upload a New RSIND File</title>\n";
	}
} // end of GeneratePageHead()
	
	

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
 *	$forEmail - an associative array to be sent with email.
 *
 * NOTES:
 * 	We're actually generating a 404 page so the person who caused this invalid request to be
 * 	made doesn't get a hint as to what they almost did.  The passed values are never shown
 * 	to the user - they just get logged.
 */
function InvalidRequest( $value, $expectedKey, $passedKey, $forEmail = 0 ) {
	global $debug;
	global $emailRecipients;
	global $UsersFullName;
	$encrypted = US_GenerateBuriedKey( "$value.$expectedKey.$passedKey.", false );
	GeneratePageHead();
	?>
	</head>
	<body>
	<h1 align="center">404:  Page Not Found</h1>
	<!-- Last updated 12/8/2019 , 0:-1 -->
	<!-- <p hidden> (Error code <?php echo $encrypted; ?> ) -->
	<p> (Error code <?php echo $encrypted; ?> )
	</body>
	</html>

	<?php
	$msg =  "Invalid attempt to authenticate an Upload - $UsersFullName\n" .
		"Error code: '$encrypted'\n";
		
	if( $forEmail != 0 ) {
		$msg .= "Client headers:\n";
    	foreach ($forEmail as $hdrName => $hdrValue) {
    		$msg .= "$hdrName: '$hdrValue'\n";
    	}
	}
	$msg .= "(Last updated 12/8/2019 , 0:-1)";
	SendEmail( $emailRecipients, "Invalid Request", $msg );	
	if( $debug ) {
		error_log( __FILE__ . ": InvalidRequest(): value='$value', expectedKey='" .
				  "$expectedKey', passedKey='$passedKey', msg='$msg'" );
	}
} // end of InvalidRequest()



function SendEmail( $to, $subject, $email ) {
	$headers = 'From: RSIND Upload <uploads@pacificmasters.org>' . "\r\n" .
		'Reply-To: PAC Webmaster <webmaster@pacificmasters.org>' . "\r\n" .
		'X-Mailer: PHP/' . phpversion();
	mail( $to, $subject, $email, $headers  );
} // end of SendEmail()

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




// 		$message = FileAlreadyExists( $destinationDir, $convertedFileName );
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
	global $yearBeingProcessed;
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
			$message[0] = "Internal error - unable to read from the file just uploaded ($fullFileName) - upload aborted!";
		}
	} else {
		$status = 1;
		$message[0] = "Internal error - unable to open the file just uploaded ($fullFileName) - upload aborted!";
	}
	
	if( $status == 0 ) {
		exec( "/usr/home/pacdev/Automation/PMSUpload/Code/scripts/TestInstallRSIND.bash " .
			"$destinationDir '$fileName' $yearBeingProcessed 2>&1", $message, $status );

		if( $debug ) {
			error_log( "exec done for the year $yearBeingProcessed, exitStatus=$status");
			error_log( "line 0: '$message[0]'");
			error_log( "line 1: '$message[1]'");
			error_log( "line 2: '$message[2]'");
			error_log( "line 3: '$message[3]'");
		}
	}
	
	return array( $message, $status );
} // end of ValidateRSINDFile()


// 				ArchiveRSINDFile( $destinationDir, $_FILES['files']['name'][0] );
/*
 * ArchiveRSINDFile - rename the archived RSIND file to something that will allow us to re-upload the same file.
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


/*
 * GetHeaderList - get a list of headers sent along with a client request to us.
 *
 */
function GetHeaderList() {
    //create an array to put our header info into.
    $headerList = array();
    //loop through the $_SERVER superglobals array.
    foreach ($_SERVER as $name => $value) {
        //if the name starts with HTTP_, it's a request header.
        if (preg_match('/^HTTP_/',$name)) {
            //convert HTTP_HEADER_NAME to the typical "Header-Name" format.
            //$name = strtr(substr($name,5), '_', ' ');
            //$name = ucwords(strtolower($name));
            //$name = strtr($name, ' ', '-');
            //Add the header to our array.
            $headerList[$name] = $value;
        }
    }
    //Return the array.
    return $headerList;
} // end of GetHeaderList()

?>
