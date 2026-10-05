#!/bin/bash
# Download equirectangular world map for grayline display
IMGDIR="/var/www/html/images"
OUTFILE="$IMGDIR/world_map.jpg"

if [ -f "$OUTFILE" ]; then
    echo "Already exists: $OUTFILE"
    exit 0
fi

echo "Downloading world map..."
# Natural Earth style from Wikimedia — equirectangular, 2880px wide
curl -L --max-time 30 \
  "https://upload.wikimedia.org/wikipedia/commons/thumb/8/8f/Whole_world_-_land_and_oceans.jpg/2880px-Whole_world_-_land_and_oceans.jpg" \
  -o "$OUTFILE" 2>/dev/null

if [ ! -s "$OUTFILE" ]; then
    echo "First URL failed, trying alternate..."
    curl -L --max-time 30 \
      "https://eoimages.gsfc.nasa.gov/images/imagerecords/57000/57752/land_shallow_topo_2048.jpg" \
      -o "$OUTFILE" 2>/dev/null
fi

if [ -s "$OUTFILE" ]; then
    echo "Downloaded: $(du -h $OUTFILE | cut -f1)"
    chmod 644 "$OUTFILE"
else
    echo "FAILED — no internet or URL changed"
    rm -f "$OUTFILE"
    exit 1
fi
