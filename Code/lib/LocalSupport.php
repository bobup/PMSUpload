<?php

// LocalSupport.php
/*
 * This small php library file contains general support for all the Upload php programs.
 * Unlike UploadSupport.php, the constants and routines contained in this support
 * file are not "secret" - if someone reads this code they won't be able to access 
 * privileged information (unless they get user access to the PMS development directory, 
 * and in that case all is lost.)
 */

// Copyright (c) 2022 Bob Upshaw.  This software is covered under the Open Source MIT License

// define the file name of the file containing our local properties.
define( "LOCALPROPSFILE", "/usr/home/pacdev/Automation/PMSUpload/properties/LocalProps.txt" );



/*
 * LS_ReadLocalProps - read a few properties from a local file.
 *
 * returns an array of results.
 */
function LS_ReadLocalProps() {

	$props = fopen( LOCALPROPSFILE, "r" ) or die ("Unable to open " . LOCALPROPSFILE . " - ABORT!" );
	$results = array();
	while( ($line = fgets( $props )) !== false ) {
		// remove comments
		$line = preg_replace( "/#.*$/", "", $line );
		// ignore blank or empty lines
		if( preg_match( "/^\s*$/", $line ) ) continue;
		$results[] = str_replace(array("\r", "\n"), '', $line);
	}
	fclose( $props );
	return $results;
} // end of LS_ReadLocalProps()


function LS_OpenLogFile( $fullCallerPath ) {
	
	// set up a simple log file:
	$logFileName = "UploadLog.txt";
	$splFile = new SplFileInfo( $fullCallerPath );
	$logFilePath = $splFile->getPath() . "/../UploadedFiles/Logs";
	mkdir( $logFilePath, 0740, true );
	$fullLogFileName = $logFilePath . "/" . $logFileName;

	$logHandle = fopen( $fullLogFileName, "a" );

	return $logHandle ;

} // end of LS_OpenLogFile()

?>

