#!/bin/bash
#
# TestInstallRSIND.bash - test and then (if OK) install a new RSIND file for use by the appropriate
#   applications.
#
# This script is executed with three required arguments and one optional argument:
#	$1:  the directory containing the newly uploaded RSIND file
#   $2:  the (simple) name of the newly uploaded RSIND file
#   $3:  the year being processed
#	$4:  (optional) if supplied this tells the script to do everything it would normally do
#		EXCEPT the actual copy. Useful when debugging.
#
# Exit Status:
#   0 - file uploaded and copied to destination directories correctly, or in the case where $4
#		is supplied, this means no problems found.
#   1 - there was a problem and the file was NOT uploaded to any destination directory
#   2 - the file already existed in at least one of the destination directories so it was not
#       re-copied.  It was copied to whatever directories didn't already have the file.
#
# In addition, the first 3 lines produced by this script are used by the calling program.
# These lines may be empty but usually are not.  They are named:
#	- User Message - a message designed to be seen by the user of the calling program.
#	- Drop Message - details for the user.  Usually empty unless there are errors or
#		warnings.
#	- Hidden Message - visible only if you look at the generated source of the page, which
#		you can't really see without a web developer plug-in for your browser (since the
#		page is dynamically generated, and 'Show Source' of a browser only shows the 
#		initial static contents of the page.)

#set -x

USER_MESSAGE=
DROP_MESSAGE=
HIDDEN_MESSAGE=
WARNING_STRINGS=

TEMP_FILE=/tmp/TestInstallRSIND.$$
LOG_FILE=$TEMP_FILE.LOG

echo >$TEMP_FILE "$0 ran on `date`"
echo >$LOG_FILE "$0 ran on `date`"

SIMPLE_SCRIPT_NAME=`basename $0`
# compute the full path name of the directory holding this script.  We'll find
# other files we need using this path:
SCRIPT_DIR=$(dirname $0)
cd $SCRIPT_DIR
SCRIPT_DIR=`pwd -P`
echo >>$LOG_FILE "SCRIPT_DIR = '$SCRIPT_DIR'";

# calculate today's date and a few other dates for this year:
TODAY_MD=$(date +"%m%d")        # today's date in form MMDD, e.g. 0513 for May 13
OCT15_MD="1015"                 # Oct 15 in above format
JUN1_MD="0601"                  # June 1 in above format

# USMS allows members to register for NEXT year starting Nov 1 of THIS year.  We care about this because
# we want THIS year's AGSOTY and OW competitors to be registered for THIS year.  We will use NEXT year's registration
# for AGSOTY and OW but we definately don't want o use ONLY NEXT year's registrations to determine OW and AGSOTY
# points for THIS year.
GOT_NEXT_YEARS_RSIND=0          # set to 1 if we think the newly uploaded RSIND file contains next year's registrations only

# One way we'll recognize NEXT year's RSIND is by comparing the size of a newly uploaded RSIND with the previously
# installed (in AGSOTY) RSIND file and if the size of the newly uploaded file is "significantly" smaller we'll use
# that as a hint that maybe it's for NEXT year.  "Significantly" will be determined by using the following constant
# to dictate the minumum difference in the size of the two files, in lines:
LINE_DIFF_TRIGGER=100



function Usage {
    echo "Usage: $0 Destination_dir RSIND_file_name Year_being_processed"
    echo "    (e.g. TestInstallRSIND.bash UploadedFiles/RSIND/  \"USMS-RSIND_03-11-2019 (1).csv\"   2019  )"
}


# TestFileType - analyze the file type of the passed file (full path name) and print an empty string
#   if the file type is something we will allow for a RSIND file, or print an error string if it is not.
# This function should be executed like this:
#       result="$(TestFileType 'full path name')
# and the caller can then analyze $result.
#
function TestFileType {
local fileType="$(file '$2')"
if [[ $fileType = *text* ]] ; then
    # OK - at least it's a text file
    echo discard this line...
else
    # not a text file
    echo $fileType
fi
    
}

# if all looks good do we really copy the file to the appropriate destination directories?
NOCOPY="$4"		# If non-empty we will NOT actually do the copy.

# did we get the RSIND file name and year?
RSIND_FILE_NAME="$2"        # simple name of newly uploaded RSIND file - we may change it below
ORIGINAL_RSIND_FILE_NAME=$RSIND_FILE_NAME       # remember the original name in case we need to change it
YEAR_BEING_PROCESSED="$3"
if [ .$YEAR_BEING_PROCESSED = . ] ; then
    echo "ERROR: Missing Year Being Processed  (Passed: '$1' '$2' '$3').  Abort!"
    Usage;
    exit 1;
fi

if [ $YEAR_BEING_PROCESSED -lt 2019 ] || [ $YEAR_BEING_PROCESSED -gt 2030 ] ; then
    echo "ERROR: Invalid Year Being Processed (must be 2019 <= year <= 2030).  Abort!"
    Usage;
    exit 1;
fi

# compute the year for "next year" - used if we have to populate next year's AGSOTY, too
NEXT_YEAR_TO_BE_PROCESSED=$(( YEAR_BEING_PROCESSED + 1 ))

# go to the directory containing the uploaded RSIND file that we want to use (if OK)
pushd $1 2>&1 >/dev/null
UPLOADED_RSIND_DIR=`pwd -P`
FULL_RSIND_FILE_NAME="$UPLOADED_RSIND_DIR/$RSIND_FILE_NAME"
if [[ ! -r "$RSIND_FILE_NAME" ]] ; then
    echo "ERROR: The uploaded RSIND file is not readable.  Abort!"
    echo "(((ERROR: '$FULL_RSIND_FILE_NAME' is not readable.)))"
    exit 1;
fi
popd 2>&1 >/dev/null
# cwd is now SCRIPT_DIR

# calculate the size of the newly uploaded RSIND file
NUM_ROWS_IN_NEW_RSIND_FILE=`wc -l <"$FULL_RSIND_FILE_NAME"`

# compute destination AGSOTY directory and size of current RSIND file:
DESTDIR=$SCRIPT_DIR/../../../PMSTopTen/SeasonData/Season-$YEAR_BEING_PROCESSED/PMSSwimmerData
echo >>$LOG_FILE "DESTDIR for AGSOTY RSIND file = '$DESTDIR' (NOCOPY=$NOCOPY)";

# remember this directory for clean up later (at the end of this script):
AGSOTY_THISYEAR_DIRECTORY=$DESTDIR
FULL_DEST_FILE=$DESTDIR/$RSIND_FILE_NAME
# get the size of the RSIND file in this directory:
EXISTING_RSIND_SIZE=0
EXISTING_RSIND_FILE=`$SCRIPT_DIR/GetMostRecentVersion.pl '^(.*RSIND.*)|(.*RSIDN.*)$' $DESTDIR`
STATUS_GetMostRecentVersion=$?
if [ $STATUS_GetMostRecentVersion -eq 0 ] ; then
    EXISTING_RSIND_SIZE=`wc -l <"$DESTDIR/$EXISTING_RSIND_FILE"`
fi

#####
#####  Modify newly uploaded RSIND file if necessary
#####



# we need to be careful near the end of the year.  On Nov 1 when an RSIND file is generated by the PMS administrator
# the results generated by USMS give registrations for NEXT year only!  So between Nov 1 and Dec 31 when we get
# an RSIND file we need to MERGE it with the previous one.  The exact rules for merging are given with that code.
# Because we have no control on how USMS works we'll be conservative and start looking for the change to next
# year's RSIND file earlier than Nov 1.  We'll detect it by comparing the size of the newly uploaded RSIND 
# file with the previous RSIND file, and if it's "significantly" smaller we'll assume we have next 
# year's RSIND file.  The "previous" RSIND file will be taken from the AGSOTY files.
if [ $TODAY_MD -gt $OCT15_MD ] ; then
    # This could be an RSIND file for NEXT year only!
    if [ $STATUS_GetMostRecentVersion -eq 0 ] && \
        [ $NUM_ROWS_IN_NEW_RSIND_FILE -lt $((EXISTING_RSIND_SIZE - LINE_DIFF_TRIGGER )) ] ; then
        # The newly uploaded file is significantly smaller than the previous RSIND file, so we're going to
        # assume that the newly uploaded file is for next year ONLY!  Merge the new file with the old one:
        GOT_NEXT_YEARS_RSIND=1
        MERGE_SIMPLE_NAME=MergeRSINDFiles
        MERGE_PROG=$SCRIPT_DIR/../$MERGE_SIMPLE_NAME.pl
		echo >>$LOG_FILE "MERGE_PROG for RSIND file = '$MERGE_PROG'";

        # Since we are constructing a different uploaded RSIND file that is different from the one the user uploaded
        # we are going to change its name:
        RSIND_FILE_NAME=Merged_$RSIND_FILE_NAME
        MERGED_FULL_RSIND_FILE_NAME=$UPLOADED_RSIND_DIR/$RSIND_FILE_NAME
        $MERGE_PROG "$DESTDIR/$EXISTING_RSIND_FILE" $FULL_RSIND_FILE_NAME $YEAR_BEING_PROCESSED \
            > $MERGED_FULL_RSIND_FILE_NAME
        # the "new" uploaded RSIND file is now named "Merged_xxx", where "xxx" is the name of the newly
        # uploaded RSIND file supplied by the user.
        FULL_RSIND_FILE_NAME=$MERGED_FULL_RSIND_FILE_NAME
        DROP_MESSAGE="$DROP_MESSAGE <br>MERGED '$ORIGINAL_RSIND_FILE_NAME' with '$EXISTING_RSIND_FILE' and installed $RSIND_FILE_NAME instead."
    # else the newly uploaded RSIND file is the one we want
    fi
# else it's pretty early in the year - assume the newly uploaded RSIND file is the one we want
fi

###!!!  the above fails if: on oct 1 we upload full rsind file. Then
###     on oct 16 we upload next year's so decide to merge,
###     then on oct 20 we upload a new file and it's still next years but we use it whole without
###     merging because it's not significantly smaller than previous
### still much smaller than one uploaded 

#####
#####  VALIDATION
#####


# analyze the RSIND file before we copy it
VALIDATE_SIMPLE_NAME=ValidateNewRSINDFile
VALIDATE_PROG=$SCRIPT_DIR/../$VALIDATE_SIMPLE_NAME.pl
VALIDATE_LOG_DIR=$SCRIPT_DIR/../Logs/
echo >>$LOG_FILE "VALIDATE_PROG = '$VALIDATE_PROG', VALIDATE_LOG_DIR='$VALIDATE_LOG_DIR'";

mkdir -p $VALIDATE_LOG_DIR
VALIDATE_LOG=$VALIDATE_LOG_DIR${VALIDATE_SIMPLE_NAME}Log-$YEAR_BEING_PROCESSED.txt
rm -f $VALIDATE_LOG
$VALIDATE_PROG "$FULL_RSIND_FILE_NAME" $YEAR_BEING_PROCESSED 2>&1 >>$TEMP_FILE
if [ ! -e $VALIDATE_LOG ] ; then
    echo "Unable to execute $VALIDATE_SIMPLE_NAME - RSIND file not installed!"
    echo "(((prog: $VALIDATE_PROG , log: $VALIDATE_LOG, stderr/out: $TEMP_FILE)))"
    exit 1
fi
rm -f $TEMP_FILE
NUM_ERRORS=`grep -c '! ERROR' $VALIDATE_LOG`
NUM_WARNINGS=`grep -c '! WARNING' $VALIDATE_LOG`
if [ $NUM_ERRORS -gt 0 ] ; then
    ERROR_STRINGS=`grep '! ERROR' $VALIDATE_LOG`
    echo "$NUM_ERRORS errors discovered - RSIND file not installed!"
    echo "[[[$DROP_MESSAGE $ERROR_STRINGS]]]"
    echo "(((See $VALIDATE_LOG for more details)))"
    exit 1;
fi

NUM_ROWS_IN_NEW_RSIND_FILE=`wc -l <"$FULL_RSIND_FILE_NAME"`
EXIT_STATUS=0
if [ $NUM_WARNINGS -gt 0 ] ; then
    WARNING_STRINGS=`grep '! WARNING' $VALIDATE_LOG`
    USER_MESSAGE="$USER_MESSAGE $NUM_WARNINGS warnings discovered"
    HIDDEN_MESSAGE="(((See $VALIDATE_LOG for more details)))"
fi


#####
#####  AGSOTY
#####

# If all ok copy the newly uploaded RSIND file to the appropriate AGSOTY directory:
if [ -e "$FULL_DEST_FILE" ] ; then
    # huh?  somehow this file already exists at the destination - refuse to copy it again.
    USER_MESSAGE="$USER_MESSAGE; This file already exists for AGSOTY"
    DROP_MESSAGE="$DROP_MESSAGE <br>$FULL_DEST_FILE already exists for AGSOTY - not copied again."
    HIDDEN_MESSAGE="$HIDDEN_MESSAGE; ((('cp $FULL_RSIND_FILE_NAME $DESTDIR' wasn't tried because file already exists))); "
    EXIT_STATUS=2
elif [ "$NOCOPY" != "" ] ; then		 # unless NOCOPY
    USER_MESSAGE="$USER_MESSAGE; No actual copy performed for AGSOTY"
    DROP_MESSAGE="$DROP_MESSAGE <br>$FULL_DEST_FILE looks good but, as requested, it was not copied."
    HIDDEN_MESSAGE="$HIDDEN_MESSAGE; (((The passed NOCOPY was '$NOCOPY'))); "
else
    # do the copy!
	echo >>$LOG_FILE "AGSOTY (this year) DESTDIR = '$DESTDIR'";
    if cp "$FULL_RSIND_FILE_NAME" $DESTDIR ; then
        DROP_MESSAGE="$DROP_MESSAGE <br>$RSIND_FILE_NAME ($NUM_ROWS_IN_NEW_RSIND_FILE rows) copied successfully for $YEAR_BEING_PROCESSED AGSOTY"
        if [ $STATUS_GetMostRecentVersion -eq 0 ] ; then
            DROP_MESSAGE="$DROP_MESSAGE <br>   --Replacing $EXISTING_RSIND_FILE ($EXISTING_RSIND_SIZE rows)"
            if [ $(( EXISTING_RSIND_SIZE - NUM_ROWS_IN_NEW_RSIND_FILE )) -gt $LINE_DIFF_TRIGGER ] ; then
                # Oh oh - this looks like a problem...
                DROP_MESSAGE="$DROP_MESSAGE <br>   ++NOTE: The newly updated RSIND file is significantly SMALLER than the previous one!"
            fi
        else
            DROP_MESSAGE="$DROP_MESSAGE <br>   --(There was no prior RSIND file.)"
        fi
        HIDDEN_MESSAGE="$HIDDEN_MESSAGE; (((Copied to $DESTDIR for $YEAR_BEING_PROCESSED AGSOTY))); "
        # Yet another special case:  If it's past June 1 then the next year's SCY season has started.  We will
        # have to also copy this AGSOTY file to that year's AGSOTY directory too...
        if [ $TODAY_MD -ge $JUN1_MD ] ; then
            DESTDIR=$SCRIPT_DIR/../../../PMSTopTen/SeasonData/Season-$NEXT_YEAR_TO_BE_PROCESSED/PMSSwimmerData
			echo >>$LOG_FILE "AGSOTY (next year) DESTDIR = '$DESTDIR'";


            # remember this directory for clean up later (at the end of this script):
            AGSOTY_NEXTYEAR_DIRECTORY=$DESTDIR
            if [ ! -d "$DESTDIR" ] ; then
                # so message below is correct:
                STATUS_GetMostRecentVersion=1
            fi
            mkdir -m go-rwx -p $DESTDIR
            FULL_DEST_FILE=$DESTDIR/$RSIND_FILE_NAME
            if cp "$FULL_RSIND_FILE_NAME" $DESTDIR ; then
                DROP_MESSAGE="$DROP_MESSAGE <br>$RSIND_FILE_NAME ($NUM_ROWS_IN_NEW_RSIND_FILE rows) copied successfully for $NEXT_YEAR_TO_BE_PROCESSED AGSOTY"
                if [ $STATUS_GetMostRecentVersion -eq 0 ] ; then
                    DROP_MESSAGE="$DROP_MESSAGE <br>   --Replacing $EXISTING_RSIND_FILE ($EXISTING_RSIND_SIZE rows)"
                else
                    DROP_MESSAGE="$DROP_MESSAGE <br>   --(There was no prior RSIND file.)"
                fi
                HIDDEN_MESSAGE="$HIDDEN_MESSAGE; (((Copied to $DESTDIR for $NEXT_YEAR_TO_BE_PROCESSED AGSOTY))); "
            else
                USER_MESSAGE="$USER_MESSAGE; Failed to cp $RSIND_FILE_NAME for $NEXT_YEAR_TO_BE_PROCESSED AGSOTY"
                HIDDEN_MESSAGE="$HIDDEN_MESSAGE; ((('cp $FULL_RSIND_FILE_NAME $DESTDIR' failed))); "
                EXIT_STATUS=1
            fi
        fi
    else
        USER_MESSAGE="$USER_MESSAGE; Failed to cp $RSIND_FILE_NAME for $YEAR_BEING_PROCESSED AGSOTY"
        HIDDEN_MESSAGE="$HIDDEN_MESSAGE; ((('cp $FULL_RSIND_FILE_NAME $DESTDIR' failed))); "
        EXIT_STATUS=1
    fi
fi



#####
#####  Open Water
#####

# (sigh...)  Yet another special case!  We will install the newly uploaded RSIND file for OW ONLY if it is earlier
# than Oct 15.  This is based on past history:  the OW season is finished by mid-late September.
# In addition, if the newly uploaded RSIND file is NEXT year's file we definately DO NOT want to use it for
# this year's OW.
if [ $TODAY_MD -lt $OCT15_MD ] && [ $GOT_NEXT_YEARS_RSIND == 0 ] ; then
    # compute destination OW directory:
    DESTDIR=$SCRIPT_DIR/../../../PMSOWPoints/SourceData/PMSSwimmerData/
	echo >>$LOG_FILE "OW DESTDIR = '$DESTDIR'";
    OW_DIRECTORY=$DESTDIR
    FULL_DEST_FILE=$DESTDIR/$RSIND_FILE_NAME
    # get the size of the RSIND file in this directory:
    EXISTING_RSIND_SIZE=0
    EXISTING_RSIND_FILE=`$SCRIPT_DIR/GetMostRecentVersion.pl '^(.*RSIND.*)|(.*RSIDN.*)$' $DESTDIR`
    STATUS_GetMostRecentVersion=$?
    if [ $STATUS_GetMostRecentVersion -eq 0 ] ; then
        EXISTING_RSIND_SIZE=`wc -l <"$DESTDIR/$EXISTING_RSIND_FILE"`
    fi
    if [ -e "$FULL_DEST_FILE" ] ; then
        # huh?  somehow this file already exists at the destination - refuse to copy it again.
        USER_MESSAGE="$USER_MESSAGE; This file already exists for OW"
        DROP_MESSAGE="$DROP_MESSAGE <br>$FULL_DEST_FILE already exists for OW - not copied again."
        HIDDEN_MESSAGE="$HIDDEN_MESSAGE; ((('cp $FULL_RSIND_FILE_NAME $DESTDIR' wasn't tried because file already exists))); "
        if [ $EXIT_STATUS = 0 ] ; then
            EXIT_STATUS=2
        fi
	elif [ "$NOCOPY" != "" ] ; then		 # unless NOCOPY
		USER_MESSAGE="$USER_MESSAGE; No actual copy performed for OW"
		DROP_MESSAGE="$DROP_MESSAGE <br>$FULL_DEST_FILE looks good but, as requested, it was not copied."
		HIDDEN_MESSAGE="$HIDDEN_MESSAGE; (((The passed NOCOPY was '$NOCOPY'))); "
    elif cp "$FULL_RSIND_FILE_NAME" $DESTDIR ; then
        DROP_MESSAGE="$DROP_MESSAGE <br>$RSIND_FILE_NAME ($NUM_ROWS_IN_NEW_RSIND_FILE rows) copied successfully for OW"
        if [ $STATUS_GetMostRecentVersion -eq 0 ] ; then
            DROP_MESSAGE="$DROP_MESSAGE <br>   --Replacing $EXISTING_RSIND_FILE ($EXISTING_RSIND_SIZE rows)"
            if [ $(( EXISTING_RSIND_SIZE - NUM_ROWS_IN_NEW_RSIND_FILE )) -gt $LINE_DIFF_TRIGGER ] ; then
                # Oh oh - this looks like a problem...
                DROP_MESSAGE="$DROP_MESSAGE <br>   ++NOTE: The newly updated RSIND file is significantly SMALLER than the previous one!"
            fi
        else
            DROP_MESSAGE="$DROP_MESSAGE <br>   --(There was no prior RSIND file.)"
        fi
       HIDDEN_MESSAGE="$HIDDEN_MESSAGE; (((Copied to $DESTDIR for OW)))"
    else
        USER_MESSAGE="$USER_MESSAGE; Failed to cp for OW"
        HIDDEN_MESSAGE="$HIDDEN_MESSAGE; ((('cp $FULL_RSIND_FILE_NAME $DESTDIR' failed)))"
        EXIT_STATUS=1
    fi
else
    DROP_MESSAGE="$DROP_MESSAGE <br>$RSIND_FILE_NAME ($NUM_ROWS_IN_NEW_RSIND_FILE rows) NOT copied for OW"
    DROP_MESSAGE="$DROP_MESSAGE <br>  (OW season over or the uploaded RSIND file appears to be for next year.)"
fi



#####
#####  Done!
#####

# clean up old files ...
# Uploads directory
cd $UPLOADED_RSIND_DIR 2>&1 >/dev/null
ls -tp | grep -v '/$' | tail -n +61 | xargs -I {} rm -- {}

# this year's AGSOTY directory...
cd $AGSOTY_THISYEAR_DIRECTORY 2>&1 >/dev/null
ls -tp | grep -v '/$' | tail -n +20 | xargs -I {} rm -- {}

# next year's AGSOTY directory...
if [ -n "$AGSOTY_NEXTYEAR_DIRECTORY" ] ; then
    cd "$AGSOTY_NEXTYEAR_DIRECTORY" 2>&1 >/dev/null
    ls -tp | grep -v '/$' | tail -n +20 | xargs -I {} rm -- {}
fi

# Open Water directory...
if [ -n "$OW_DIRECTORY" ] ; then
    cd $OW_DIRECTORY 2>&1 >/dev/null
    ls -tp | grep -v '/$' | tail -n +20 | xargs -I {} rm -- {}
fi

# log dirs
cd $VALIDATE_LOG_DIR 2>&1 >/dev/null
ls -tp | grep -v '/$' | tail -n +6 | xargs -I {} rm -- {}

# generate the results:
echo $USER_MESSAGE
echo "[[[$DROP_MESSAGE $WARNING_STRINGS]]]"
echo $HIDDEN_MESSAGE
exit $EXIT_STATUS
