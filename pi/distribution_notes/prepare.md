# RigPi Release Process

**Version:** 1.0
**Last Updated:** July 2026

This document describes the procedure used to create a production RigPi image for distribution.

---

# 1. Prepare the Master System

Update the master system as required.

Clean temporary files.

```bash
sudo apt clean
sudo journalctl --vacuum-time=1d
rm -rf ~/.cache/*
sudo sync
sudo fstrim -av
```

Remove:

- Previous image files
- Previous ZIP files
- Temporary SDR recordings
- Build artifacts no longer required

Verify available space.

```bash
df -h /
sudo du -xh --max-depth=1 / | sort -h
```

---

# 2. Enable First-Boot Expansion

Enable the RigPi first boot expansion service.

```bash
sudo rm -f /var/lib/rigpi/rootfs-expanded
sudo systemctl enable rigpi-firstboot-expand.service
```

Verify:

```bash
systemctl is-enabled rigpi-firstboot-expand.service
```

Expected:

```
enabled
```

---

# 3. Create the Release SD Card

Write the master image to the release SD card.

Boot the SD card.

Verify:

- Boot completes
- Login works
- RigPi starts
- Radio control works
- SDR operates normally
- Network functions correctly

Shutdown.

```bash
sudo shutdown -h now
```

Remove the SD card.

---

# 4. Create the Release Image (Mac)

Insert the SD card into the Mac.

Identify the device.

```bash
diskutil list
```

Unmount (do not eject):

```bash
diskutil unmountDisk /dev/diskX
```

Create the raw image using the raw device.

```bash
sudo dd if=/dev/rdiskX \
of=/Volumes/xSSD/rigpi5.img \
bs=16m
```

Wait for completion.

```bash
sync
```

---

# 5. Shrink the Image

Run PiShrink.

```bash
pishrink rigpi5.img
```

Verify the resulting image size.

Typical release image:

```
20–30 GB
```

---

# 6. Test the Image

Restore the PiShrink image to another SD card.

Boot.

Verify:

- Automatic filesystem expansion
- Successful reboot
- Full card capacity available

```bash
df -h /
lsblk
```

Verify RigPi operation.

---

# 7. Generate SHA256

```bash
shasum -a 256 rigpi5.img > rigpi5.img.sha256
```

---

# 8. Compress (optional)

For archive storage:

```
zstd -19
```

or

```
xz -T0
```

---

# 9. Upload

Upload:

- rigpi5.img
- rigpi5.img.sha256

to:

```
rigpi.download
```

---

# 10. Release Checklist

- [ ] Image boots
- [ ] First-boot expansion works
- [ ] Radio control verified
- [ ] SDR verified
- [ ] Audio verified
- [ ] Cloudflare verified
- [ ] ZeroTier verified
- [ ] Database intact
- [ ] SHA256 generated
- [ ] Uploaded
- [ ] Release notes published

---

# Notes

## Mac Imaging

Always use:

```
/dev/rdiskX
```

instead of:

```
/dev/diskX
```

The raw device is significantly faster.

---

## Never Image a Live Development System

Whenever possible:

- Build the release SD card.
- Boot and verify.
- Shutdown.
- Image the SD card offline.

This guarantees a consistent filesystem.

---

## Common Problems

### PiShrink reports

```
Partition Table: unknown
```

Cause:

A partition was imaged instead of the entire device.

Correct:

```
/dev/mmcblk0
```

Incorrect:

```
/dev/mmcblk0p2
```

---

### Filesystem Does Not Expand

Verify:

```
rigpi-firstboot-expand.service
```

is enabled before the image is created.

---

## Release Philosophy

Always release from a verified, bootable image.

The release image should never be the first boot of an untested build.

Test first.

Release second.
