#!/bin/bash
# This script can be used with the -X option of rms-cast-recorder to
# convert a single wav file to mp3 after recording is done. 
# The wav file path is passed as an argument to the script.

# THIS SCRIPT REQUIRES THAT exiftool be installed
# $ sudo apt install -y libimage-exiftool-perl

which exiftool > /dev/null 2>&1
if [ $? -ne 0 ]; then
    echo "Error: exiftool is not installed. Please install it with 'sudo apt install -y libimage-exiftool-perl'"
    exit 1
fi

wavFile="$1"
if [ -z "$wavFile" ]; then
    echo "Usage: $0 <path_to_wav_file>"
    exit 1
fi

mp3file="${wavFile%.wav}.mp3"
title=$(exiftool -s3 -Title "$wavFile")
comment=$(exiftool -s3 -Comment "$wavFile")
echo "Converting $title: $wavFile -> $mp3file $comment"
lame --tt "$title" --tc "$comment" -V2 "$wavFile" "$mp3file" 2> /dev/null
status=$?
if [ $status -eq 0 ]; then
    rm "$wavFile"
    echo "Converted and removed original"
else
    echo "Conversion failed: $wavFile"
    rm -f "$mp3file"
fi