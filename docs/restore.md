# Restore

How to restore Breakfast from the backups described in [backups.md](backups.md),
and — just as important — how to **test** a restore so you know the backups
work before you need them.

There is no database: all data is flat files under `storage/data/`, so a restore
is a file copy. No schema, no migrations, no integrity-repair tooling.

## What you need

- A data-tree archive (`breakfast-data-*.tgz` or `breakfast-files-*.tgz`).
- A content + uploads archive, if content also needs restoring (may be the same
  archive).
- The matching `storage/vault-keys/` (needed to decrypt vaulted secrets) and
  `.env` (or the ability to recreate them from your secrets manager).
- A known reference to verify against — e.g. an enquiry reference such as
  `ENQ-2026-0001` that you know existed at backup time.

## Restore procedure (production)

1. **Stop writers.** Put the site into maintenance and stop the queue worker
   (`queue:work`) or pause the `queue:run` cron so nothing writes to
   `storage/data/` mid-restore.

2. **Verify the backup first.** Confirm every record is valid JSON:

   ```bash
   tar xzf breakfast-data-2026-07-21-0300.tgz -C /tmp/restore-check
   find /tmp/restore-check/storage/data -name '*.json' -exec sh -c \
     'jq -e . "$1" >/dev/null || echo "CORRUPT: $1"' _ {} \;
   # no output = every record parsed cleanly
   ```

3. **Restore the data tree.** Move the current tree aside (don't delete it yet),
   then put the backup in place:

   ```bash
   mv storage/data storage/data.broken 2>/dev/null || true
   tar xzf breakfast-data-2026-07-21-0300.tgz     # restores storage/data/
   chown -R www-data:www-data storage/data
   chmod -R u+rwX,g+rwX storage/data
   ```

4. **Restore content, uploads and generated files** (only if they were
   lost/corrupted) — including the vault keys, or vaulted secrets stay
   unreadable:

   ```bash
   tar xzf breakfast-files-2026-07-21.tgz
   # restores content/, storage/uploads/, storage/client-previews/,
   # storage/client-files/, storage/invoices/ and storage/vault-keys/
   ```

5. **Restore `.env`** if needed.

6. **Boot and verify** (see the verification checklist below).

7. **Resume writers.** Restart the queue worker / re-enable the cron and lift
   maintenance. Clear the page cache if content changed
   (`storage/cache/` can be safely emptied — Kirby rebuilds it).

## Restore test (staging — do this regularly)

Prove the backups work without touching production.

1. **Restore into an isolated staging directory** and point the platform's
   storage root at it. In the staging `.env`, set `APP_ENV=development` and start
   the app from that directory so `storage/data/` resolves to the restored copy:

   ```bash
   mkdir -p /srv/breakfast-staging
   tar xzf /backups/breakfast-files-2026-07-21.tgz -C /srv/breakfast-staging
   ```

2. **Check integrity** — every record parses:

   ```bash
   find /srv/breakfast-staging/storage/data -name '*.json' -exec sh -c \
     'jq -e . "$1" >/dev/null || echo "CORRUPT: $1"' _ {} \;
   # no output = ok
   ```

3. **Boot the app** against the restored data:

   ```bash
   php -S localhost:8010 -t public
   ```

4. **Verify a known record exists.** Confirm the reference you noted at backup
   time is present in the enquiries collection:

   ```bash
   grep -l '"reference": *"ENQ-2026-0001"' \
     /srv/breakfast-staging/storage/data/enquiries/*.json
   # expect one matching file
   ```

   Also sanity-check record counts against expectations:

   ```bash
   for c in enquiries contacts opportunities; do
     printf '%-14s %s\n' "$c" "$(ls /srv/breakfast-staging/storage/data/$c/*.json 2>/dev/null | wc -l)"
   done
   ```

5. **Check the health command** reports a sane store path and queue depth:

   ```bash
   php bin/console health
   ```

6. **Tear down** the staging copy. Record the date of the successful test.

## If a record fails to parse

Do **not** put a corrupt tree into production. Fall back to the previous good
backup and re-run the test. A single corrupt JSON file only affects its own
record — the rest of the store still loads — so if just one file is bad you can
delete it (losing that one record) or hand-repair it, then re-verify. Prefer a
clean backup where one exists.
