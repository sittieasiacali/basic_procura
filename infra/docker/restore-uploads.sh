#!/bin/sh
set -eu
archive=${1:?An uploads archive is required}
tar -tf "$archive" | while IFS= read -r member; do
    case "$member" in /*|../*|*/../*|*/..) echo 'Unsafe archive path' >&2; exit 1;; esac
    case "$member" in private|private/|private/*|public|public/|public/*) ;; *) echo 'Unexpected upload archive entry' >&2; exit 1;; esac
done
# Only archives produced by Prepare-Bundle.ps1, whose SHA256 is checked before
# import, are accepted. Export rejects symlinks and directory junctions.
tar -xf "$archive" -C /var/www/html/storage/app --no-same-owner --no-same-permissions
find storage/app/public -type d -exec chmod 0755 {} +
find storage/app/public -type f -exec chmod 0644 {} +
find storage/app/private -type d -exec chmod 0700 {} +
find storage/app/private -type f -exec chmod 0600 {} +
