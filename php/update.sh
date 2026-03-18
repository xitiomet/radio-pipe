#!/bin/bash
# Just for updating my dev stuff please ignore or delete
scp -4 openstatic.org:openstatic.org/icecast/recordings/index.php recordings.php
scp 192.168.34.238:/var/www/html/rtl_sdr.php .
scp 192.168.34.238:/var/www/html/install_rtl_sdr_watchdog.sh ../scripts/
scp 192.168.34.238:/var/www/html/rtl_sdr_watchdog_tick.sh ../scripts/
