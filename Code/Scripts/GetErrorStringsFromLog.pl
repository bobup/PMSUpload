#!/usr/bin/perl -w


# GetErrorStringsFromLog.pl - Read through the passed log file and find all the error 
#	and warning messages.
#
# Usage:
#	GetErrorStringsFromLog.pl {E[rror]|W[arning]} LogFileName
# where
#	{E[rror]|W[arning]} - one of 'E' or 'W' (any case) which can be followed by any number
#		of non-blank characters.
#	LogFileName - the log file to be processed.
#
#
# Print 0 or more lines, where each line represents all information about a single error/warning.
#
# Error messages are of the form:
#	! ERROR: [line #6]:
#    PMS_ImportPMSData::GetRSINDRow(): Invalid gender.
#  -- END ERROR
# Warning messages are of the form:
#	! WARNING: [line #5]:
#    PMS_ImportPMSData::GetRSINDRow(): Missing email.
#  -- END WARNING
# In the above example, a single error/warning begins with the character after the : in 
#	the ! ERROR/! WARNING line,
#	and ends with the line prior to the -- END ERROR / -- END WARNING line.  All newlines are
#	removed so that a single error/warning message will be displayed as a single line.
#

use diagnostics;
use strict;
use warnings;

use POSIX qw(strftime);
use File::Basename;

my $appProgName;	# name of this program

BEGIN {
	# Get the name of the program we're running:
	$appProgName = basename( $0 );
	die( "Can't determine the name of the program being run - did you use/require 'File::Basename' and its prerequisites?")
		if( (!defined $appProgName) || ($appProgName eq "") );
}

my $LogFileName = "";
my $resultErrorLines = "";
my $ErrorStringBeginning = "! ERROR";
my $ErrorStringEnd = "-- END ERROR";
my $WarningStringBeginning = "! WARNING";
my $WarningStringEnd = "-- END WARNING";
my $SearchStringBeginning = "";
my $SearchStringEnd = "";


my $arg;
my $argNum = 0;
my $numErrors = 0;
while( defined( $arg = shift ) ) {
	$argNum++;
	my $value = $arg;
	$value =~ s/^\s+|\s+$//g;

	if( $argNum == 2 ) {
		$LogFileName = $value;
	} elsif( $argNum == 1 ) {
		my $letter = uc( substr( $value, 0, 1 ) );
		if( $letter eq 'E' ) {
			# looking for errors
			$SearchStringBeginning = $ErrorStringBeginning;
			$SearchStringEnd = $ErrorStringEnd;
		} elsif( $letter eq "W" ) {
			# looking for warnings
			$SearchStringBeginning = $WarningStringBeginning;
			$SearchStringEnd = $WarningStringEnd;
		} else {
			print "${appProgName}:: ERROR:  Invalid ERROR/WARNING argument (#$argNum): '$arg'\n";
			$numErrors++;
		}
	} else {
		print "${appProgName}:: ERROR:  Invalid argument (#$argNum): '$arg'\n";
		$numErrors++;
	}
} # end of while - done getting command line args

if( $LogFileName eq "" ) {
	die "GetErrorStringsFromLog.pl: missing log file name\n";
}

if( $SearchStringBeginning eq "" ) {
	die "GetErrorStringsFromLog.pl: missing error/warning argument\n";
}

open( FileHandle, '<', $LogFileName ) or die "GetErrorStringsFromLog.pl: Failed to " .
	"open '$LogFileName': $!\n";
	
my $state = "NoErrorYet";
while( my $line = <FileHandle> ) {
	# trim leading and trailing whitespace from this line:
	$line =~ s/^\s+|\s+$//g;
	# process the line:
	if( $state eq "NoErrorYet" ) {
		# does line begin an error/warning message?
		if( index( $line, $SearchStringBeginning ) != -1 ) {
			# beginning of error/warning string
			$state = "FoundError";
			$resultErrorLines .= $line;
		} # else we ignore this log file line
	} elsif( $state eq "FoundError" ) {
		# we're in the middle of an error/warning message...is it the end?
		if( index( $line, $SearchStringEnd ) != -1 ) {
			# end of error/warning message
			$resultErrorLines .= "\n";
			$state = "NoErrorYet";
		} else {
			# add this line to our collection of lines for this single error/warning message
			$resultErrorLines .= $line;
		}
	}
	# else there is no other possibility for $state
}  # end of while(...

close( FileHandle );

if( $resultErrorLines ne "" ) {
	print $resultErrorLines;
}

# end of GetErrorStringsFromLog.pl
