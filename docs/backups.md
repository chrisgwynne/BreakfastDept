# Backups

Breakfast keeps **everything** in flat files: editorial content under `content/`
and all operational data as JSON records under `storage/data/`. There is no
database. Both trees, plus a handful of supporting directories, must be backed
up. This document says what to copy, how to copy it safely, and how to keep the
backups secure.

## What to back up

| Path | Contains | Priority |
|---|---|---|
| `content/` | All editorial content (pages, text files, referenced media source) | Critical |
| `storage/data/` | Every operational record — CRM, projects, invoicing, portal, queue, webhooks, audit trail — one JSON file per record | Critical |
| `storage/uploads/` | Files attached to enquiries (stored outside the docroot) | Critical (if uploads are enabled) |
| `storage/client-previews/` | Published client-preview files (the `client_previews` records reference these) | Important — see note |
| `storage/client-files/`, `storage/invoices/`, `storage/vault-keys/` | Client file library bytes, generated invoice/contract PDFs, and the vault encryption keys | Critical |
| `.env` | Configuration and secrets | Critical — back up **securely and separately** |
| `storage/accounts/` (a.k.a. `site/accounts`) | Panel user accounts, if used | Important |
| `storage/logs/` | Application + queue logs | Optional (useful for audit) |

You do **not** need to back up `vendor/`, `public/media/` (Kirby regenerates
thumbnails) or `storage/cache/` and `storage/sessions/` (transient). These are
rebuilt from source and the data tree.

## Backing up the data tree safely

`storage/data/` is a directory of plain JSON files. Each record is written
atomically (a temp file is written, then `rename()`d into place), so a file is
never captured half-written — a filesystem-level copy of the tree is always
consistent per record. There is no WAL, no checkpoint and no online-backup step:
a simple archive is safe even under concurrent writes.

```bash
tar czf /backups/breakfast-data-$(date +%F-%H%M).tgz storage/data
```

For a point-in-time snapshot of an actively-written tree, prefer a filesystem
snapshot (LVM/ZFS/btrfs) or `rsync --link-dest` so the whole set is coherent;
but a plain `tar` is fine at a solo-studio's write volume.

Verify a backup is well-formed by confirming every file is valid JSON:

```bash
find /backups/extracted/storage/data -name '*.json' -exec sh -c \
  'jq -e . "$1" >/dev/null || echo "CORRUPT: $1"' _ {} \;
# no output = every record parsed cleanly
```

## Backing up content, uploads and generated files

`content/`, `storage/uploads/`, `storage/client-previews/`, `storage/client-files/`,
`storage/invoices/` and `storage/vault-keys/` are ordinary files. Snapshot them
together with the data tree so the set is coherent:

```bash
tar czf /backups/breakfast-files-$(date +%F).tgz \
  content storage/data storage/uploads storage/client-previews \
  storage/client-files storage/invoices storage/vault-keys
```

**Vault keys are critical.** Vault secrets under `storage/data/vault_item_fields/`
are encrypted; the keys that decrypt them live in `storage/vault-keys/`. Back up
both together — the ciphertext is useless without the keys, and the keys are
useless without the ciphertext. Keep them in the same snapshot.

**Client previews:** the `client_previews` records point at version files under
`storage/client-previews/`. Include that directory in the snapshot so a restore
is coherent. If you choose **not** to back it up (previews are disposable
mock-ups), a restored data tree will have preview records whose files are missing
— those previews will 404 until re-uploaded. Keep the data tree and this
directory in the same snapshot either way.

## Frequency

- **Data tree (`storage/data/`):** at least daily; hourly is reasonable for an
  active pipeline. Always take an extra backup **immediately before a deploy**.
- **Content + uploads + generated files:** daily, or after significant work.
- **`.env` + `storage/vault-keys/`:** whenever they change (rarely). Keep them
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

Backups contain personal data (contacts, enquiries), the vault keys, and — in
the case of `.env` — live secrets. Encrypt them at rest:

```bash
# Example: age (or gpg) before uploading offsite.
age -r <recipient-key> -o breakfast-data-2026-07-21.tgz.age breakfast-data-2026-07-21.tgz
```

Store the `.env` and `storage/vault-keys/` backups separately from the data
backups so that a single leaked archive does not contain both the data and the
keys to it.

## Restricting access

- Restrict the backup directory to the backup user (`chmod 0700`).
- Give the offsite bucket the narrowest possible credentials (write-only /
  append-only where the provider supports it).
- Treat access to backups as equivalent to access to the live data — the same
  GDPR obligations apply.

## Test your restores

A backup you have never restored is a hope, not a plan. Follow
[restore.md](restore.md) periodically to prove the backups actually work.
