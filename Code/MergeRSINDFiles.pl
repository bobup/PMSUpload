#!/usr/bin/perl -w


# MergeRSINDFiles.pl - a program that is used to merge two RSIND files $1 and $2.
#   The $1 RSIND file is considered the "master" RSIND file.  It's (probably) larger than $2.  It's older than $2.
#   The $2 RSIND file is considered the "newer" RSIND file.  
#		- It (probably) contains swimmers already contained in $1 
#		- It (possibly) contains swimmers not contained in $1
#		- It (possibly) contains swimmers who are not registered for the passed season
#   The result of this program is a copy of "master" with the swimmers unique to "newer" who
#		are registered for the passed season added to the result.  If a swimmer is not unique to
#		"newer" (it's in both files) the result is the row with the oldest registration date still
#		in the passed season.
#   The result of this program is written to stdout.
#   The exit status:
#       0 - all OK
#       1 - error.  See stderr
#
# NOTE:
#	"Registered for the passed season":  If the passed season is "X", then for a swimmer to be registered
#	for the passed season they must have a reg date of November 1, (X-1) -> Dec 31, X.  For example, to be
#	registered for 2017 they must have a reg date of November 1, 2016 through Dec 31, 2017.  Strictly speaking, if
#	a swimmer registers anytime between Nov 1, 2016 and Dec 31, 2017 then they are registered for the 2017 
#	season (USMS rules.) 
#	In this program we assume the $1 RSIND file (the "master") contains only swimmers registered for the passed
#	season, thus all are part of the result UNLESS the "newer" RSIND file has the same swimmer with a 
#	older reg date.  We will never add swimmers to the result from the "newer" RSIND
#	files unless those swimmers registered between November 1, (X-1) and Dec 31, X, where "X" is the passed season.
#
# Real Example:  It's 2019.  We need the RSIND file for the 2017 season.  So we ask the PMS Administrator (Chris) and
# she gives us the RSIND file for 2017 and also for 2018.  Reason:
#	- the RSIND file for 2017 contains swimmers who registered between Nov 1, 2016 and Oct 31, 2017, inclusive.
#	- the RSIND file for 2018 contains swimmers who registered between Nov 1, 2017 and Oct 31, 2018, inclusive.  We
#		need to include those swimmers who registered between Nov 1, 2017 and Dec 31, 2017.
# We then generate the 2017RSIND_Merged.csv file by:
#				MergeRSINDFiles.pl  2017Rsind.csv  2018Rsind.csv  2017   > 2017RSIND_Merged.csv
#
# This program is executed with three required arguments:
#   $1:  the (full path) name of the "master" RSIND file.
#   $2:  the (full path) name of the "newer" RSIND file
#   $3:  the season being processed
#
# Copyright (c) 2019 Bob Upshaw.  This software is covered under the Open Source MIT License 


my $debug = 1;
my $debugSwimmerId = "xxxxxxxx";



use diagnostics;
use strict;
use warnings;

#use Data::Dumper;		# used to dump hashs to stdout for debugging
use Spreadsheet::Read;

use FindBin;
use File::Spec;
use lib File::Spec->catdir( $FindBin::Bin, '..', '..', 'PMSPerlModules' );
require PMS_ImportPMSData;


my $masterFileName = $ARGV[0];
my $newerFileName = $ARGV[1];
my $seasonBeingProcessed = $ARGV[2];

if( !defined $seasonBeingProcessed ) {
    print STDERR "Usage:  MergeRSINDFiles.pl  MASTER_RSIND_FILE   NEWER_RSIND_FILE  SeasonBeingProcessed\n";
    exit 1;
}

# we are only interested in swimmers whose registration date falls between the following two dates, inclusive:
my $minSeasonDate = ($seasonBeingProcessed-1) . "-11-01";
my $maxSeasonDate = "$seasonBeingProcessed-12-31";

# open up the two spreadsheets
my $masterFullSheet = ReadData( $masterFileName );  # reference to full spreadsheet
my $masterSheet = $masterFullSheet->[1];            # reference to the hashtable representing the first sheet
my $numRowsInMaster = $masterSheet->{maxrow};       # number of rows in RSIDN file
my $numColumnsInMaster = $masterSheet->{maxcol};

my $newerFullSheet = ReadData( $newerFileName );  # reference to full spreadsheet
my $newerSheet = $newerFullSheet->[1];            # reference to the hashtable representing the first sheet
my $numRowsInNewer = $newerSheet->{maxrow};       # number of rows in RSIDN file
my $numColumnsInNewer = $newerSheet->{maxcol};

# Next, read all the swimmer records from the newer RSIND file and populate this hashtable:
# HOWEVER, DO NOT store swimmers whose reg date is outside the passed season.
my %swimmers = ();          # %swimmers{swimmerId} = row from RSIND file for that swimmer, or empty string
							# %swimmers{swimmerId-regDate} = reg date of this swimmer
my $newerRowNum;
my $newerRowRef = {};
for( $newerRowNum = 2; $newerRowNum <= $numRowsInNewer; $newerRowNum++ ) {
	if( ($newerRowNum % 1000) == 0 ) {
		print STDERR "...working on $newerFileName, row $newerRowNum...\n";
	}
    my @row = Spreadsheet::Read::row( $newerSheet, $newerRowNum );
    my $rowString = PMSUtil::ConvertArrayIntoString( \@row );
	PMS_ImportPMSData::GetRSINDRow( $newerRowRef, $newerRowNum, $newerSheet, $seasonBeingProcessed, $newerFileName );
	# NOTE: $newerRowRef - populated with the contents of the row, fields of which are:
	#		club, swimmerId, first, middle, last, address1, city, state, zip, country, dob, gender, regDate,
	#		email, reg
    my $swimmerId = $newerRowRef->{'swimmerId'};
	if( $swimmerId eq $debugSwimmerId ) {
		print STDERR "Found $debugSwimmerId: row #$newerRowNum: '$rowString'\n";
	}
    my $regDate = $newerRowRef->{'regDate'};		# in form yyyy-mm-dd
    if( defined $swimmers{$swimmerId} ) {
        print STDERR "Error: Found two (or more) rows for swimmerid '$swimmerId'\n";
    }
    if( ($regDate ge $minSeasonDate) && ($regDate le $maxSeasonDate) ) {
	    $swimmers{$swimmerId} = $rowString;
	    $swimmers{"$swimmerId-regDate"} = $regDate;		# in form yyyy-mm-dd
    }
} # end of for...

# we now have an array of all swimmers from "newer" that registered for the passed season.
my $numberOfNewerSwimmers = (keys %swimmers) / 2;
#print STDERR "Number of 'newer' swimmers who registered for $seasonBeingProcessed: $numberOfNewerSwimmers\n";


# Next, pass through our master RSIND file and for every record read:
#   if the record is for a swimmer whose swimmerId was found in the newer RSIND file
#	AND the reg date for the newer record is older than the reg date for the master record
#       write out the newer record, else write out the master record.
# Then, for all records in the newer RSIND file that did NOT match any records in the master
#	RSIND file, write out those records.
# But, before we start, write out the header row:
my @row;
@row = Spreadsheet::Read::row( $masterSheet, 1 );
my $rowString;
$rowString = PMSUtil::ConvertArrayIntoString( \@row );
print $rowString . "\n";		# column headers
my $masterRowNum;
my $masterRowRef = {};
for( $masterRowNum = 2; $masterRowNum <= $numRowsInMaster; $masterRowNum++ ) {
	if( ($masterRowNum % 1000) == 0 ) {
		print STDERR "...working on $masterFileName, row $masterRowNum...\n";
	}
    @row = Spreadsheet::Read::row( $masterSheet, $masterRowNum );
    $rowString = PMSUtil::ConvertArrayIntoString( \@row );
	PMS_ImportPMSData::GetRSINDRow( $masterRowRef, $masterRowNum, $masterSheet, $seasonBeingProcessed, $masterFileName );

    my $swimmerId = $masterRowRef->{'swimmerId'};
    # pretend the reg dates are birth dates and convert them into a form we can easily compare:
    my $ISOMasterRegDate = $masterRowRef->{'regDate'};			# in form yyyy-mm-dd
    my $ISONewerRegDate = $swimmers{"$swimmerId-regDate"};		# in form yyyy-mm-dd
    
    if( defined $swimmers{$swimmerId} ) {
    	if( $ISONewerRegDate lt $ISOMasterRegDate ) {
	    	#print "--- NEWER: ";
	        print $swimmers{$swimmerId} . "\n";
	    } else {
	    	#print "---Master: ";
	        print $rowString . "\n";
	    }
	    # we're done with this record in the newer RSIND file - delete it:
	    delete( $swimmers{$swimmerId} );
	    delete( $swimmers{"$swimmerId-regDate"} );
    } else {
    	print $rowString . "\n";
    }
} # end of for...

foreach my $key (keys %swimmers ) {
	next if( $key =~ m/.*-.*/ );
#	print STDERR "Adding '$swimmers{$key}'\n";
	print $swimmers{$key} . "\n";
}



exit 0;

# end of MergeRSINDFiles.pl



