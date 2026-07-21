# Restore

How to restore Breakfast from the backups described in [backups.md](backups.md),
and — just as important — how to **test** a restore so you know the backups
work before you need them.

## What you need

- A database backup (`crm-*.sqlite`), verified with `PRAGMA integrity_check`.
- A content + uploads archive (`breakfast-content-*.tgz`), if content also needs
  restoring.
- The matching `.env` (or the ability to recreate it from your secrets manager).
- A known reference to verify against — e.g. an enquiry reference such as
  `ENQ-2026-0001` that you know existed at backup time.

## Restore procedure (production)

1. **Stop writers.** Put the site into maintenance and stop the queue worker
   (`queue:work`) or pause the `queue:run` cron so nothing writes to the
   database mid-restore.

2. **Verify the backup first.**

   ```bash
   sqlite3 crm-2026-07-21-0300.sqlite "PRAGMA integrity_check;"   # expect: ok
   ```

3. **Restore the database.** Move the current file aside (don't delete it yet),
   then put the backup in place, including removing stale WAL side-files:

   ```bash
   mv storage/database/crm.sqlite storage/database/crm.sqlite.broken 2>/dev/null || true
   rm -f storage/database/crm.sqlite-wal storage/database/crm.sqlite-shm
   cp crm-2026-07-21-0300.sqlite storage/database/crm.sqlite
   chown www-data:www-data storage/database/crm.sqlite
   chmod 0660 storage/database/crm.sqlite
   ```

4. **Restore content and uploads** (only if they were lost/corrupted):

   ```bash
   tar xzf breakfast-content-2026-07-21.tgz     # restores content/ and storage/uploads/
   ```

5. **Restore `.env`** if needed, then confirm the schema is current:

   ```bash
   php bin/console migrate:status   # every migration should show applied
   php bin/console migrate          # apply any that post-date the backup
   ```

6. **Boot and verify** (see the verification checklist below).

7. **Resume writers.** Restart the queue worker / re-enable the cron and lift
   maintenance. Clear the page cache if content changed
   (`storage/cache/` can be safely emptied — Kirby rebuilds it).

## Restore test (staging — do this regularly)

Prove the backups work without touching production.

1. **Restore into an isolated staging directory**, pointing the database path at
   the restored copy:

   ```bash
   mkdir -p /srv/breakfast-staging/storage/database
   sqlite3 /backups/crm-2026-07-21-0300.sqlite \
     ".backup '/srv/breakfast-staging/storage/database/crm.sqlite'"
   ```

   In the staging `.env`, set `CRM_DB_PATH` to that file and `APP_ENV=development`.

2. **Check integrity:**

   ```bash
   sqlite3 /srv/breakfast-staging/storage/database/crm.sqlite "PRAGMA integrity_check;"
   # expect: ok
   ```

3. **Check the schema is complete:**

   ```bash
   php bin/console migrate:status
   # every migration listed as applied — no pending rows
   ```

4. **Boot the app** against the restored data:

   ```bash
   php -S localhost:8010 -t public
   ```

5. **Verify a known record exists.** Confirm the reference you noted at backup
   time is present:

   ```bash
   sqlite3 /srv/breakfast-staging/storage/database/crm.sqlite \
     "SELECT reference, status, created_at FROM enquiries WHERE reference = 'ENQ-2026-0001';"
   # expect one row
   ```

   Also sanity-check row counts against expectations:

   ```bash
   sqlite3 .../crm.sqlite "SELECT
     (SELECT COUNT(*) FROM enquiries)      AS enquiries,
     (SELECT COUNT(*) FROM contacts)       AS contacts,
     (SELECT COUNT(*) FROM opportunities)  AS opportunities;"
   ```

6. **Check the health endpoint** returns `ok` and a plausible queue depth
   (Hermes must be enabled in the staging env for this route to respond, or
   check via the Panel CRM dashboard):

   ```
   GET /api/breakfast/v1/health
   ```

7. **Tear down** the staging copy. Record the date of the successful test.

## If integrity_check fails

Do **not** put a failing database into production. Fall back to the previous
good backup and re-run the test. A recoverable database can sometimes be salvaged
with:

```bash
sqlite3 broken.sqlite ".recover" | sqlite3 recovered.sqlite
sqlite3 recovered.sqlite "PRAGMA integrity_check;"
```

but treat a recovered file as suspect and prefer a clean backup where one
exists.
