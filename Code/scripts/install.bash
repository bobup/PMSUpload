#!/bin/bash
#
# install.bash - make sure the PMSUpload tree is consistent with https://pacmdev.org/points/
#       aka ~/public_html/pacmdev.org/points/
#
# This script is executed with no arguments:
#
# Exit Status:
#   0 - https://pacmdev.org/points/ is consistent with PMSUpload
#   1 - there were inconsistencies found.  See results on stderr
#

EXIT_STATUS=0       # assume all OK
SIMPLE_SCRIPT_NAME=`basename $0`
# compute the full path name of the directory holding this script.  We'll find
# other files we need using this path:
SCRIPT_DIR=$(dirname $0)
cd $SCRIPT_DIR
SCRIPT_DIR=`pwd -P`

# full path name of RSINDUpload.php in PMSUpload/:
pushd ../ 2>&1 >/dev/null
CODE_DIR=`pwd -P`
FULL_RSINDUPLOAD_FILE_NAME=$CODE_DIR/RSINDUpload.php

# full path name of RSINDUpload.php in https://pacmdev.org/points/
cd ../../../public_html/pacmdev.org/points/ 2>&1 >/dev/null
INSTALL_DIR=`pwd -P`
popd 2>&1 >/dev/null
INSTALLED_RSINDUpload=$INSTALL_DIR/RSINDUpload.php

# is FULL_RSINDUPLOAD_FILE_NAME the same as the copy we have in https://pacmdev.org/points/ ?
diff -q $FULL_RSINDUPLOAD_FILE_NAME $INSTALLED_RSINDUpload 2>&1 >/dev/null
diff_RSINDUPLOAD=$?
if [ $diff_RSINDUPLOAD != 0 ] ; then
    1>&2 echo "$FULL_RSINDUPLOAD_FILE_NAME is different than INSTALLED_RSINDUpload.  Try this:"
    1>&2 echo "diff \"$FULL_RSINDUPLOAD_FILE_NAME\" \"$INSTALLED_RSINDUpload\""
    EXIT_STATUS=1
fi

# full path name of the jqUpload directory in PMSUpload/:
FULL_jqUpload_FILE_NAME=$CODE_DIR/jqUpload

# full path name of the jqUpload directory in https://pacmdev.org/points/ direcotry
INSTALLED_jqUpload=$INSTALL_DIR/jqUpload

# is the installed jqUpload directory consistent with our copy in PMSUpdate?
diff -qr $FULL_jqUpload_FILE_NAME $INSTALLED_jqUpload 2>&1 >/dev/null
diff_jqUpload=$?
if [ $diff_jqUpload != 0 ] ; then
    1>&2 echo "$FULL_jqUpload_FILE_NAME is different than $INSTALLED_jqUpload.  Try this:"
    1>&2 echo "diff -r \"$FULL_jqUpload_FILE_NAME\" \"$INSTALLED_jqUpload\""
    EXIT_STATUS=1
fi

if [ $EXIT_STATUS == 0 ] ; then
    1>&2 echo "PMSUpload is installed correctly."
fi

exit $EXIT_STATUS

