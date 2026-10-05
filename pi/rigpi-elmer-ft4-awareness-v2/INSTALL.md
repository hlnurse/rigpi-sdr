# RigPi Elmer FT4 decoder awareness

This version 2 update teaches Elmer that current RigPi-SDR decodes both FT8 and FT4.
It updates official RigPi Help, adds current-product guidance, rebuilds the Pi
knowledge database, and provides a matching cloud installer. The Pi installer
updates both the live Help page and Elmer's configured Help ZIP before rebuilding.

## Pi

```bash
cd deployed/pi/Elmer
sudo bash deploy/install_ft4_rigpi_awareness.sh
```

Copy `/home/pi/Elmer/output/knowledge.db` from the Pi to the extracted
`elmer_cloud_pilot` directory on the cloud host.

## Cloud

```bash
cd elmer_cloud_pilot
sudo bash deploy/install_ft4_rigpi_awareness.sh knowledge.db
```

Test with:

> What do FT4 signals look like, and how can I confirm them in RigPi?

Elmer should recommend the RigPi-SDR **FT8** side-panel tab with the Decoder
selector set to **FT4**, while describing external WSJT-X only as an option.
