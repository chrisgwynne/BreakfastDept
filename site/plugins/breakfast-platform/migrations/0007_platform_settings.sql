-- Application-managed settings + secrets.
--
-- A single key/value store for settings the user manages from the standalone
-- admin (e.g. Brevo email configuration). Secret values (API keys) are stored
-- ENCRYPTED at rest via the application SecretBox — the ciphertext lives here,
-- the encryption key never does. is_secret marks rows whose value is ciphertext
-- and must never be returned to a client in full.

CREATE TABLE IF NOT EXISTS platform_settings (
    name       TEXT PRIMARY KEY,
    value      TEXT NOT NULL DEFAULT '',
    is_secret  INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT,
    updated_by TEXT
);
