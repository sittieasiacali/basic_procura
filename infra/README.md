# PROCURA: source-independent Docker deployment

This is the existing Laravel application split into **five containers**, not a rewrite into business microservices:

| Service | Runs | Persistent state |
| --- | --- | --- |
| `web` | Nginx, compiled frontend, logos/static images | Public uploads, read-only |
| `app` | PHP-FPM and the complete production Laravel app | Private/public uploads |
| `worker` | Laravel queue worker, including SMTP emails | Queue stored in database |
| `scheduler` | Laravel scheduler | Application database |
| `database` | MariaDB 10.11 | Named database volume |

The app and web images contain their code and assets. There are **no source-code bind mounts**. The deployment's Compose file has no `build:` instructions and can recreate containers without the original checkout. Secrets mount from the separate deployment bundle; database data and uploads live in named Docker volumes. The original APP_KEY is preserved so existing application data can still be decrypted.

## Local source workflow

After the local `runtime.env` and `secrets/` have been initialized, this source checkout can be managed directly from `infra`. Compose automatically loads `compose.override.yaml`, which builds the app and web images from the parent project folder:

```powershell
cd "C:\xampp\htdocs\basic procura\infra"
docker compose up -d --build
docker compose logs -f
docker compose restart
docker compose down
```

`runtime.env` and `secrets/` are ignored by Git. The external `C:\Procura-deploy` bundle remains useful for production transfer, backup, or running without the source checkout, but it is not required for day-to-day local rebuilds.

On a new development machine, the local override automatically pulls `mariadb:10.11` when it is not already installed. If the machine has no internet access, transfer the exported image archive and run `docker load --input <archive>` before starting Compose.

## 1. Prepare Docker Desktop

Install Docker Desktop for Windows, enable its WSL 2 backend/Linux containers, and start it. Open a new PowerShell terminal and check:

```powershell
docker version
docker compose version
```

No Docker engine was available when these files were prepared. Building and running the containers must finish successfully before deleting any source.

The commands below bypass script execution restrictions for that PowerShell process only; they do not change the machine's execution policy.

## 2. Build and export the full release

Leave XAMPP MySQL running. Do not submit/edit requests or upload/delete files during the final export; the SQL snapshot and uploaded files must describe the same application state. Do not use XAMPP and Docker as two independent live copies after cutover.

From the source project's `infra` folder:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Build-Release.ps1 -Destination 'C:\Procura-deploy'
```

The build installs locked Composer production dependencies and builds Vite assets inside Docker. PHP includes GD for signatures/images, PDO MySQL, mbstring, zip, intl, pcntl, and OPcache. Your XAMPP database is exported logically and restored into MariaDB 10.11; the XAMPP MySQL data directory is never copied or modified.

The release directory must be **outside** the original source folder. It contains:

- `compose.yaml`, runtime scripts and this guide;
- `runtime.env`: runtime settings, including `APP_URL=http://localhost:8080`;
- `secrets/`: the original app key, generated container database passwords and copied SMTP password;
- `images-*.tar` and `release.json`: app, web and database images with hashes/IDs;
- `data/<timestamp>/database.sql`, `uploads.tar`, uploaded-file copies and a manifest;
- `snapshot.json`: database row counts and uploaded-file SHA256 checksums.

Secrets, SQL dumps, uploaded files, host dependencies and browser profiles are excluded from the Docker build context. Static images in `public` and the project's root images are included in the app image; public assets are also in the web image. Snapshots copy only `storage/app/private` and `storage/app/public`, not screenshots/browser profiles in the storage root.

The export is read-only against XAMPP. It never prints database passwords or the app key, seeds demo users, resets account passwords, or changes the original database. Existing release settings/secrets are retained; update the deployment's `runtime.env` image names if you want a different tag in an existing bundle.

### Export data before Docker is installed

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Prepare-Bundle.ps1 -Destination '.\release'
```

This creates a data/config snapshot **without images**. It is not a runnable release and is not permission to delete the source. Run Build-Release later with an external destination to create the complete deployment.

## 3. Start and verify from the exported folder

```powershell
cd C:\Procura-deploy
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Start.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Verify.ps1 -Recreate -CompareSnapshot
```

Start loads and verifies the exported images, starts MariaDB, imports only into an empty database, restores uploads, checks their hashes/row counts/app key, runs pending migrations, then starts all five services. It does not overwrite an existing database. If upload restoration fails after SQL import, a marker permits retrying the files for the same snapshot. An unrelated or partially imported unmarked database is refused rather than overwritten.

Verify recreates containers with `--no-build --pull never`, checks health, rejects source bind mounts, verifies bundled assets/PHP extensions and database access, and checks `/up`, `/login`, and the company logo. This is the source-independence check: **it does not rename or delete your source folder**.

`-CompareSnapshot` is intended immediately after initial import, before users change data. For later checks use:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Verify.ps1
```

Open **http://localhost:8080**. Existing users, password hashes, roles, requests, approvals, signatures and attachments come from the imported database/files. The database is internal to Docker; your existing XAMPP port 3306 can remain in use. Port 8080 is bound to localhost by default. For another port, build with `-Port 8081`; for LAN access, set the bundle's `HTTP_BIND` and `APP_URL` appropriately before starting.

## 4. SMTP and scheduling

Set the real mailbox/app password in `C:\Procura-deploy\secrets\smtp_password.txt` if the copied value is a placeholder. Restart app/worker/scheduler after changing secrets so their environment/config cache reloads:

```powershell
docker compose --env-file runtime.env -f compose.yaml restart app worker scheduler
```

The worker processes actual queued jobs, including existing queued email alerts preserved in the database. No test emails are generated by deployment verification. The scheduler runs whatever tasks the application schedules; no destructive maintenance commands are scheduled automatically.

## 5. Back up and restore

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Backup.ps1 -Destination 'C:\Procura-backups\release-001'
```

Backup briefly stops the web/worker/scheduler services that were running, captures a consistent database/upload snapshot, copies the deployment configuration/secrets and exported images, then restarts those same services. The backup contains credentials and user data; protect it like the live deployment. A backup stored only inside the deployment folder will not survive deletion of that folder.

To restore on a fresh Docker Desktop installation, copy the full backup directory, choose a new `COMPOSE_PROJECT_NAME` in `runtime.env` when restoring beside a live deployment, then run its `Start.ps1` and `Verify.ps1`. Existing named volumes are never automatically deleted.

## When the original source can be removed

Only after Build-Release, Start and Verify succeed, keep the complete external deployment bundle plus a separate full backup. You can then remove the original checkout without stopping the containers or removing their code/assets. Keep a private source archive if you need future code changes/rebuilds. The PHP code still exists inside the Docker images; packaging is not source obfuscation.

Do not delete Docker's volumes, run `docker compose down -v`, factory-reset Docker Desktop, or remove the external deployment bundle without a backup: application data is in volumes and recreating containers requires the bundle's configuration/secrets/images. A normal `docker compose down` retains named volumes.

## References

- [Docker Compose dependency health checks](https://docs.docker.com/compose/how-tos/startup-order/)
- [Docker Compose volumes](https://docs.docker.com/reference/compose-file/volumes/)
- [Official PHP images and extensions](https://hub.docker.com/_/php)
- [Official MariaDB images](https://hub.docker.com/_/mariadb)
