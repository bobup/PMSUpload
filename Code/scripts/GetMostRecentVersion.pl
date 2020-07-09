#!/usr/bin/perl -w


# GetMostRecentVersion.pl - a program that is used to find the most recent file in a directory
#
# This program is executed with two required arguments:
#   $1:  a RE filepattern. We only consider files which case sensitively matches this RE.
#   $2:  the (full path) name of the directory to search
#
# This program will have one of three possible results:
#   - print nothing to stdout and exit with a status of 1 if no file matching the supplied pattern is found
#       in the supplied directory, or
#   - print the simple file name of the most recent file found and exit with a status of 0.
#   - print an error and exit with a status of 255 (should only happen if invoked incorrectly)

# Copyright (c) 2019 Bob Upshaw.  This software is covered under the Open Source MIT License 


my $debug = 0;

use diagnostics;
use strict;
use warnings;

use FindBin;
use File::Spec;
use File::Basename;
use Cwd 'abs_path';

use lib File::Spec->catdir( $FindBin::Bin, '..', '..', '..', 'PMSPerlModules' );
require PMSUtil;

my $appProgName;	# name of this program
my $appDirName;     # directory containing the application we're running
my $appRootDir;		# directory containing the appDirName directory
my $sourceData;		# full path of directory containing the "source data" which we process to create the generated files


BEGIN {
	# Get the name of the program we're running:
	$appProgName = basename( $0 );
	die( "Can't determine the name of the program being run - did you use/require 'File::Basename' and its prerequisites?")
		if( (!defined $appProgName) || ($appProgName eq "") );
    if( $debug ) {
        print "Starting $appProgName...\n";
    }
	
	# The program we're running is in a directory we call the "appDirName".  The files we
	# generate are all located in directories relative to the
	# appDirName directory.
	#
	$appDirName = dirname( $0 );     # directory containing the application we're running, e.g.
									# e.g. /Users/bobup/Documents/workspace/TopTen-2016
										# or ./Code/
	die( "${appProgName}:: Can't determine our running directory - did you use 'File::Basename' and its prerequisites?")
		if( (!defined $appDirName) || ($appDirName eq "") );
	# convert our application directory into a full path:
	$appDirName = abs_path( $appDirName );		# now we're sure it's a full path name that begins with a '/'

	# The 'appRootDir' is the parent directory of the appDirName:
	$appRootDir = dirname($appDirName);		# e.g. ~/Automation/Upload/Code/
	die( "${appProgName}:: The parent directory of '$appDirName' is not a directory! (A permission problem?)" )
		if( !-d $appRootDir );
    if( $debug ) {
        print "  ...with the app dir name '$appDirName' and app root of '$appRootDir'...\n";
    }
}

my $UsageString = <<bup
Usage:  
	$appProgName RE-file-pattern directory-to-search
where:
	RE-file-pattern - consider only files that match this RE
	directory-to-search - limit our search to this directory  
bup
;

# get the arguments:
my $filePattern ="";
my $fullDirName = "";

my $arg;
my $argNum = 0;
my $numErrors = 0;
while( defined( $arg = shift ) ) {
	$argNum++;
	my $value = PMSUtil::trim($arg);
	if( $argNum == 1 ) {
		$filePattern = $value;
	} elsif( $argNum == 2 ) {
		$fullDirName = $value;
	} else {
		print "${appProgName}:: ERROR:  Invalid argument (#$argNum): '$arg'\n";
		$numErrors++;
	}
} # end of while - done getting command line args

if( $filePattern eq "" ) {
	# no file to look for - abort!
	die "$appProgName: no file pattern provided - Abort!";
} elsif( $fullDirName eq "" ) {
	die "$appProgName: no directory to search - Abort!";
} elsif( $numErrors > 0 ) {
    die "Errors discovered - Abort!";
}

my $fileName = PMSUtil::GetMostRecentVersion( "$filePattern", $fullDirName );
if( !defined $fileName ) {
    exit 1;
} else {
    print basename( $fileName ) . "\n";
    exit 0;
}

# end of GetMostRecentVersion.pl