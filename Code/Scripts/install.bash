#!/bin/bash
#
# install.bash - Install all the important web files for the Upload program.  All of these
#		files come from PMSUpload/Code/siteCode/ and are installed to 
#		~/public_html/pacmdev.org/points/Upload.
#		Note that files from siteCode/ to Upload/ are copied with no warnings or checking to
#		make sure the files in Upload/ are older than the ones in siteCode/. User beware.
#
# This script is executed with no arguments.
#
# Exit Status:
#	0  - all OK

# Here is a list of files to be installed:
#	~/Automation/PMSUpload/Code/siteCode/
#		.user.ini
#		OW.php
#		OwStart.php
#		PostAnUpload.php
#		Rsind.php
#		Upload.html
#		info.php
#		jqUpload/*



EXIT_STATUS=0       # assume all OK
SIMPLE_SCRIPT_NAME=`basename $0`
# compute the full path name of the directory holding this script.  We'll find
# other files we need using this path:
SCRIPT_DIR=`pwd -P`

# full path name of directory to which we install our files:
DEST_DIR='/usr/home/pacdev/public_html/pacmdev.org/sites/default/files/comp/points/Upload'
# create the destination directory if necessary:
mkdir -p "$DEST_DIR"

# full path name of siteCode/:
pushd ../siteCode 2>&1 >/dev/null
CODE_DIR=`pwd -P`
echo "Installing to $DEST_DIR"

for fn in `ls -a` ; do
	if [ "$fn" != '.' ] && [ "$fn" != '..' ] ; then
		echo -n "Installing '$fn' "
		if [ -d "$fn" ] ; then
			echo "(a DIRECTORY)"
			cp -f -r --preserve=timestamps  "$fn" "$DEST_DIR"
		else
			echo  "(a simple FILE)"
			cp -f --preserve=timestamps "$fn" "$DEST_DIR"
		fi
	fi
done

exit

exit $EXIT_STATUS

