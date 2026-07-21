# Backups

Breakfast keeps editorial content in flat files and all operational data in a
single SQLite database. Both, plus a handful of supporting directories, must be
backed up. This document says what to copy, how to copy the database safely, and
how to keep the backups secure.

## What to back up

| Path | Contains | Priority |
|---|---|---|
| `content/` | All editorial content (pages, text files, referenced media source) | Critical |
| `storage/database/*.sqlite` (+ `-wal` / `-shm`) | The CRM, queue, webhooks and audit trail | Critical |
| `storage/uploads/` | Files attached to enquiries (stored outside the docroot) | Critical (if uploads are enabled) |
| `storage/client-previews/` | Published client-preview files (the `client_previews` DB rows reference these) | Important — see note |
| `.env` | Configuration and secrets | Critical — back up **securely and separately** |
| `storage/accounts/` (a.k.a. `site/accounts`) | Panel user accounts, if used | Important |
| `storage/logs/` | Application + queue logs | Optional (useful for audit) |

You do **not** need to back up `vendor/`, `public/media/` (Kirby regenerates
thumbnails) or `storage/cache/` and `storage/sessions/` (transient). These are
rebuilt from source and the database.

## Backing up the SQLite database safely

The database runs in **WAL** mode, so recent writes may live in the `-wal` file
rather than the main `.sqlite` file. Do not copy `crm.sqlite` alone with a
plain `cp` while the site is live — you may capture a torn or stale state.

**Preferred — use SQLite's online backup**, which is consistent even under
concurrent writes:

```bash
sqlite3 storage/database/crm.sqlite \
  ".backup '/backups/crm-$(date +%F-%H%M).sqlite'"
```

**Alternative — file copy with a checkpoint.** Checkpoint the WAL into the main
file first, then copy all three parts together:

```bash
sqlite3 storage/database/crm.sqlite "PRAGMA wal_checkpoint(TRUNCATE);"
cp storage/database/crm.sqlite      /backups/crm-$(date +%F-%H%M).sqlite
# If -wal / -shm still exist, copy them alongside the main file.
```

The `.backup` command is simplest and safest — prefer it.

Verify each backup is well-formed before trusting it:

```bash
sqlite3 /backups/crm-2026-07-21-0300.sqlite "PRAGMA integrity_check;"
# expect: ok
```

## Backing up content and uploads

`content/` and `storage/uploads/` are ordinary files; a filesystem-level copy is
fine. Snapshot them together with the database backup so the set is coherent:

```bash
tar czf /backups/breakfast-content-$(date +%F).tgz content storage/uploads storage/client-previews
```

**Client previews:** the `client_previews` table (in the database backup) points
at version files under `storage/client-previews/`. Include that directory in the
snapshot above so a restore is coherent. If you choose **not** to back it up
(previews are disposable mock-ups), a restored database will have preview rows
whose files are missing — those previews will 404 until re-uploaded. Decide which
you want and keep the database and this directory in the same snapshot either way.

## Frequency

- **Database:** at least daily; hourly is reasonable for an active pipeline.
  Always take an extra backup **immediately before a deploy or migration**.
- **Content + uploads:** daily, or after significant editorial work.
- **`.env`:** whenever it changes (it changes rarely). Keep it version-tracked
  in a secrets manager, not in the repository.

## Offsite and retention

- **Offsite.** Keep at least one copy off the server (object storage or another
  host). A backup that only exists on the machine you are protecting is not a
  backup.
- **Retention.** A sensible default: keep hourly backups for 48 hours, daily for
  30 days, and monthly for 12 months. Adjust to the studio's obligations. Note
  that IP hashes are pruned from old enquiries after ~30 days (see
  [crm.md](crm.md)); very old backups will still contain them, which is another
  reason to age backups out.

## Encryption

Backups contain personal data (contacts, enquiries) and, in the case of `.env`,
live secrets. Encrypt them at rest:

```bash
# Example: age (or gpg) before uploading offsite.
age -r <recipient-key> -o crm-2026-07-21.sqlite.age crm-2026-07-21.sqlite
```

Store the `.env` backup separately from the data backups so that a single
leaked archive does not contain both the data and the keys to it.

## Restricting access

- Restrict the backup directory to the backup user (`chmod 0700`).
- Give the offsite bucket the narrowest possible credentials (write-only /
  append-only where the provider supports it).
- Treat access to backups as equivalent to access to the live database — the
  same GDPR obligations apply.

## Test your restores

A backup you have never restored is a hope, not a plan. Follow
[restore.md](restore.md) periodically to prove the backups actually work.
