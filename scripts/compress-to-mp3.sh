#!/bin/bash

DIR="/opt/recordings/"   # directory passed as argument, default current directory

# This script can be setup as a cronjob to automatically convert your wav files into mp3
# mp3, ogg, and wav are all supported by the php frontend

# THIS SCRIPT REQUIRES THAT exiftool be installed
# $ sudo apt install -y libimage-exiftool-perl

find "$DIR" -type f -iname "*.wav" | while IFS= read -r wavfile; do
    mp3file="${wavfile%.wav}.mp3"

    title=$(exiftool -s3 -Title $wavfile)
    comment=$(exiftool -s3 -Comment $wavfile)
    echo "Converting $title: $wavfile -> $mp3file $comment"
    lame --tt "$title" --tc "$comment" -V2 "$wavfile" "$mp3file" 2> /dev/null
    status=$?

    if [ $status -eq 0 ]; then
        rm "$wavfile"
        echo "Converted and removed original"
    else
        echo "Conversion failed: $wavfile"
        rm -f "$mp3file"
    fi
done