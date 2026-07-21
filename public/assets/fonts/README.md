# Fonts

The design uses **Inter** (body/UI), **JetBrains Mono** (labels/eyebrows) and
**Caveat** (handwritten accents). All three are open-source (OFL) and safe to
self-host.

The CSS in `../css/app.css` references these `.woff2` files but falls back to
`local()` and then the system font stack, so the site works with the files
absent (no external requests, privacy-friendly).

To self-host (recommended for production performance + privacy), drop the
subsetted `.woff2` files here with these exact names:

- `inter-variable.woff2`
- `jetbrains-mono.woff2`
- `caveat.woff2`

Get them from https://github.com/rsms/inter, https://www.jetbrains.com/lp/mono/,
and https://fonts.google.com/specimen/Caveat (or via `google-webfonts-helper`).
Do not hotlink Google Fonts — self-host to keep the CSP `font-src 'self'`.
