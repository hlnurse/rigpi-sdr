#!/bin/sh
# WireGuard CW sender - uses WG tunnel IP instead of LAN
# Args: $1=clientIP $2=port $3=invert
sudo php /var/www/html/programs/GPIO/GPIOInt1.php $1 $2 $3 > /dev/null 2>&1 &
