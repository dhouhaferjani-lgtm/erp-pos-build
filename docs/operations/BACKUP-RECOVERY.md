# Backup & Recovery

> PostgreSQL 16 on Docker Swarm (Dokploy). Last updated: 2026-03-20.

---

## 1. Daily Automated Backups (pg_dump)

### Backup Script

Place at `/opt/backups/pg_backup.sh` on the Docker host:

```bash
#!/usr/bin/env bash
set -euo pipefail

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/opt/backups/postgresql"
S3_BUCKET="s3://syneriva-backups/postgresql"
CONTAINER_NAME="erp-postgres"  # adjust to your Dokploy service name
DB_NAME="erp"
DB_USER="erp"

mkdir -p "$BACKUP_DIR"/{daily,weekly,monthly}

# Dump via docker exec (custom format for parallel restore)
docker exec "$CONTAINER_NAME" \
  pg_dump -U "$DB_USER" -Fc --no-owner --no-acl "$DB_NAME" \
  > "$BACKUP_DIR/daily/${DB_NAME}_${TIMESTAMP}.dump"

# Compress a plain-text copy for portability
docker exec "$CONTAINER_NAME" \
  pg_dump -U "$DB_USER" --no-owner --no-acl "$DB_NAME" \
  | gzip > "$BACKUP_DIR/daily/${DB_NAME}_${TIMESTAMP}.sql.gz"

echo "[$(date)] Backup completed: ${DB_NAME}_${TIMESTAMP}"
```

### Cron Schedule

```cron
# Daily at 03:00
0 3 * * * /opt/backups/pg_backup.sh >> /var/log/pg_backup.log 2>&1
```

### Retention Policy

| Tier    | Keep | Rotation Logic                                    |
|---------|------|---------------------------------------------------|
| Daily   | 7    | Delete dailies older than 7 days                   |
| Weekly  | 4    | Copy Sunday's daily to `weekly/`, keep 4           |
| Monthly | 12   | Copy 1st-of-month daily to `monthly/`, keep 12     |

Add this rotation script to run after the backup:

```bash
#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="/opt/backups/postgresql"

# Promote weekly (Sundays)
if [ "$(date +%u)" -eq 7 ]; then
  cp "$BACKUP_DIR"/daily/*_"$(date +%Y%m%d)"*.dump "$BACKUP_DIR/weekly/" 2>/dev/null || true
fi

# Promote monthly (1st of month)
if [ "$(date +%d)" -eq "01" ]; then
  cp "$BACKUP_DIR"/daily/*_"$(date +%Y%m%d)"*.dump "$BACKUP_DIR/monthly/" 2>/dev/null || true
fi

# Purge old backups
find "$BACKUP_DIR/daily"   -type f -mtime +7   -delete
find "$BACKUP_DIR/weekly"  -type f -mtime +28  -delete
find "$BACKUP_DIR/monthly" -type f -mtime +365 -delete
```

---

## 2. WAL Archiving (Point-in-Time Recovery)

WAL archiving enables restoring to any specific second, not just the last pg_dump snapshot.

### PostgreSQL Configuration

Add to `postgresql.conf` (or via Dokploy environment variables):

```ini
wal_level = replica
archive_mode = on
archive_command = 'test ! -f /archive/%f && cp %p /archive/%f'
archive_timeout = 300   # force archive every 5 min even with low traffic
```

In Docker Compose, mount the archive volume:

```yaml
services:
  postgres:
    volumes:
      - pg_data:/var/lib/postgresql/data
      - pg_archive:/archive
```

### WAL Backup to S3

```cron
# Sync WAL archive to S3 every 15 minutes
*/15 * * * * aws s3 sync /archive/ s3://syneriva-backups/wal-archive/ --delete >> /var/log/wal_sync.log 2>&1
```

---

## 3. Off-site Copy (S3-Compatible Storage)

Upload backups to S3 (or MinIO, Wasabi, Backblaze B2) after each run:

```bash
# Append to pg_backup.sh
aws s3 cp "$BACKUP_DIR/daily/${DB_NAME}_${TIMESTAMP}.dump" \
  "$S3_BUCKET/daily/${DB_NAME}_${TIMESTAMP}.dump" \
  --storage-class STANDARD_IA

aws s3 cp "$BACKUP_DIR/daily/${DB_NAME}_${TIMESTAMP}.sql.gz" \
  "$S3_BUCKET/daily/${DB_NAME}_${TIMESTAMP}.sql.gz" \
  --storage-class STANDARD_IA
```

Configure S3 lifecycle rules to match retention:

| Prefix            | Transition to Glacier | Delete after |
|-------------------|-----------------------|--------------|
| `daily/`          | --                    | 7 days       |
| `weekly/`         | 14 days               | 28 days      |
| `monthly/`        | 30 days               | 365 days     |

---

## 4. Recovery Procedures

### 4a. Full Restore from pg_dump

```bash
# 1. Stop the application (prevent writes)
docker service scale erp_app=0

# 2. Drop and recreate the database
docker exec -i erp-postgres psql -U erp -d postgres -c "DROP DATABASE IF EXISTS erp;"
docker exec -i erp-postgres psql -U erp -d postgres -c "CREATE DATABASE erp OWNER erp;"

# 3. Restore from custom-format dump (parallel for speed)
docker exec -i erp-postgres pg_restore \
  -U erp -d erp --no-owner --no-acl -j 4 \
  < /opt/backups/postgresql/daily/erp_20260320_030000.dump

# 4. Or restore from plain SQL
gunzip -c /opt/backups/postgresql/daily/erp_20260320_030000.sql.gz \
  | docker exec -i erp-postgres psql -U erp -d erp

# 5. Verify
docker exec erp-postgres psql -U erp -d erp -c "SELECT count(*) FROM documents;"

# 6. Restart application
docker service scale erp_app=2
```

### 4b. Point-in-Time Recovery (PITR)

Use when you need to recover to a specific moment (e.g., just before an accidental DELETE).

```bash
# 1. Stop PostgreSQL
docker stop erp-postgres

# 2. Clear current data directory (back it up first!)
mv /var/lib/docker/volumes/pg_data/_data /var/lib/docker/volumes/pg_data/_data.broken

# 3. Restore base backup
cp -r /opt/backups/postgresql/base_backup/* /var/lib/docker/volumes/pg_data/_data/

# 4. Create recovery.signal and configure recovery target
cat > /var/lib/docker/volumes/pg_data/_data/recovery.signal <<EOF
EOF

cat >> /var/lib/docker/volumes/pg_data/_data/postgresql.auto.conf <<EOF
restore_command = 'cp /archive/%f %p'
recovery_target_time = '2026-03-20 14:30:00+00'
recovery_target_action = 'promote'
EOF

# 5. Start PostgreSQL — it will replay WAL up to the target time
docker start erp-postgres

# 6. Verify data, then remove recovery.signal
docker exec erp-postgres psql -U erp -d erp -c "SELECT max(created_at) FROM documents;"
```

### Important PITR Prerequisite

You need periodic base backups for PITR. Schedule weekly:

```bash
# Weekly base backup (Sundays at 02:00)
0 2 * * 0 docker exec erp-postgres pg_basebackup \
  -U erp -D /tmp/base_backup -Ft -z -P \
  && mv /tmp/base_backup /opt/backups/postgresql/base_backup_$(date +\%Y\%m\%d)
```

---

## 5. Monthly Backup Verification

Test restores monthly. Automate with a disposable container:

```bash
#!/usr/bin/env bash
# /opt/backups/verify_backup.sh — run monthly via cron

LATEST_DUMP=$(ls -t /opt/backups/postgresql/daily/*.dump | head -1)

# Spin up a temporary PostgreSQL container
docker run -d --name pg-verify \
  -e POSTGRES_USER=erp \
  -e POSTGRES_PASSWORD=verify_temp \
  -e POSTGRES_DB=erp \
  postgres:16-alpine

sleep 5

# Restore
docker exec -i pg-verify pg_restore \
  -U erp -d erp --no-owner -j 2 < "$LATEST_DUMP"
RESTORE_EXIT=$?

# Basic integrity check
ROW_COUNT=$(docker exec pg-verify psql -U erp -d erp -t -c "SELECT count(*) FROM documents;")

# Cleanup
docker rm -f pg-verify

if [ $RESTORE_EXIT -ne 0 ]; then
  echo "BACKUP VERIFICATION FAILED — restore exit code: $RESTORE_EXIT" | \
    mail -s "ALERT: Backup verify failed" ops@syneriva.com
  exit 1
fi

echo "[$(date)] Backup verified OK. Documents: $ROW_COUNT"
```

```cron
# 1st of each month at 04:00
0 4 1 * * /opt/backups/verify_backup.sh >> /var/log/backup_verify.log 2>&1
```

---

## 6. Docker-Specific Notes

| Task | Command |
|------|---------|
| Find container name | `docker ps --filter name=postgres --format '{{.Names}}'` |
| Interactive psql | `docker exec -it erp-postgres psql -U erp -d erp` |
| Check central DB size | `docker exec erp-postgres psql -U autoerp -d synerivia_central -c "SELECT pg_size_pretty(pg_database_size('synerivia_central'));"` |
| List tenant databases | `docker exec erp-postgres psql -U autoerp -d synerivia_central -c "SELECT datname FROM pg_database WHERE datname LIKE 'tenant_%';"` |

### Multi-Tenant Considerations (post-flip, 2026-05-28)

> **NOTE:** AutoERP flipped to **database-per-tenant** on 2026-05-28 (T6 Phase 0b, PRs #141–#146 + #148). The schema-per-tenant assumption that drove the historical scripts in this doc no longer holds — each tenant is now a separate PostgreSQL **database** (`tenant_<tenant-uuid>`), not a schema in a shared database.

**Use the app-level commands for per-tenant backup/restore** (see [`POST-FLIP-OPS.md`](POST-FLIP-OPS.md) for the full runbook):

```bash
# Per-tenant backup (pg_dump custom format, records metadata in central tenant_backups)
php artisan tenant:backup acme
php artisan tenant:backup --all

# Per-tenant restore (drop + recreate + pg_restore + sha256 verification)
php artisan tenant:restore acme /path/to/<uuid>/<timestamp>.dump
```

The central database (`synerivia_central`) still benefits from a single host-level `pg_dump` for the tenant directory + auth + plans + backup metadata, but tenant data must be backed up per-DB via the artisan commands.
