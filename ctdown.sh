#!/bin/bash

# Params
# Path to temporary data (lock file)
TMPPATH=/var/tmp
# Watched file (data)
FILE=~/ctdown.lst
# Destination dir (empty for current directory)
DSTPATH=~/Video
# Be verbose?
VERBOSE=0
# Constants
LOCKNAME=ctdown.lock
# getct script
GETCT=~/bin/getct.sh

# Fetch one video
# Params:
# $1 destination name (without mp4 extension)
# $2 link
# $3 [optional] quality (288, 404, 576), 404 is default
function one()
{
    local QUALITY=404
    local V=""
    if [ "x$3" != "x" ]; then
	QUALITY=$3
    fi
    if [ "$VERBOSE" == "2" ]; then
        V="-v"
    fi
    $GETCT -o "$DSTPATH/$1.mp4" -q $QUALITY -l -t 3 $V "$2"
}

# Fetch one article video
# Params:
# $1 destination name (without mp4 extension)
# $2 link
# $3 [optional] quality (288, 404, 576), 288 is default
function oneArticle()
{
    local QUALITY=288
    local V=""
    if [ "x$3" != "x" ]; then
	QUALITY=$3
    fi
    if [ "$VERBOSE" == "2" ]; then
        V="-v"
    fi
    $GETCT -o "$DSTPATH/$1.mp4" -q $QUALITY -a -l $V "$2"
}

# Process params
while [ "x$1" != "x" ]; do
    case "$1" in
	-v)
	    VERBOSE=1
	    ;;
	-vv)
	    VERBOSE=2
	    ;;
    esac
    shift
done

# Is running?
if [ -f "$TMPPATH/$LOCKNAME" ]; then
    exit 1
fi

# Fetch the 1st arg
if [ ! -f "$FILE" ]; then
    # No file - nothing to do
    exit 0
fi
LINES=`wc -l "$FILE" | cut -d " " -f 1`
if [ "x$LINES" == "x0" ]; then
    rm -f "$FILE"
    exit 0
fi

# Create lock
touch "$TMPPATH/$LOCKNAME"

if [ -z "$DSTPATH" ]; then
    DSTPATH=`pwd`
fi

# Process the first line
CMD=`cat "$FILE" | head -n 1`
if [ "x$CMD" != "x" ]; then
    echo "$CMD" | {
    read DST URL QUAL P4
    URL=`echo $URL | sed -e 's/\r//g' -e 's/\([^/]\)$/\1\//'`
    if [ "$VERBOSE" == "1" ]; then
	date +"%c:"
	echo "$URL -> $DST"
    fi
    if [ "x$DST" == "xa:" ]; then
	oneArticle "$URL" "$QUAL" "$P4"
    else
	one "$DST" "$URL" "$QUAL"
    fi
}
fi
# Store rest (the 2nd line and following)
TMPNAME="$TMPPATH/ctdown.$$"
cat "$FILE" | tail -n +2 > "$TMPNAME"
mv -f "$TMPNAME" "$FILE"
# Delete lock file
rm -f "$TMPPATH/$LOCKNAME"

