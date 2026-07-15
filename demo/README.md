# FenPing Screenshot Demo

This directory is the versioned source for the synthetic screenshot environment.

- `db.json` contains the complete version 1.6 demo data. Its `restore.timestamp_shift` metadata shifts activity timestamps to the restore time so recent views remain populated.
- `netboot/` contains harmless example iPXE files.
- `manifest.json` and `netboot-index.json` describe the generated backup.

Build, deploy, and restore the demo with:

```bash
./fenping.sh demo
```

The command writes `data/backups/fenping-demo.tgz`, first saves the current installation as a timestamped `fenping-before-demo-*.tgz`, then restores the demo archive. Restoring changes the active SQLite data, netboot files, and generated dnsmasq host configuration.
