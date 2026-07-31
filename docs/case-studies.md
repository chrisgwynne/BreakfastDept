# Case studies & art direction

Every published case study can look genuinely bespoke — its own hero, palette,
type, pacing, image treatments and motion — while still sitting inside the
Breakfast design system (shared nav, footer, type families, focus states,
spacing logic, accessibility and reduced-motion behaviour). Nothing here exposes
raw CSS or JavaScript: the whole system is a set of **curated, validated
controls**.

## How it fits together

| Piece | Where |
|---|---|
| Art-direction resolver (curated tokens → safe CSS vars + data-attrs) | `src/Content/ArtDirection.php` |
| Editor controls (preset + settings) | project blueprint, **Art direction** tab |
| Stylesheet (heroes, blocks, transitions, animation, reduced motion) | `public/assets/css/case-study.css` (loaded only on project pages) |
| Behaviour (before/after, pan, parallax, strip keys) | `public/assets/js/case-study.js` (project pages only) |
| Template + hero/ending snippets | `site/templates/project.php`, `site/snippets/case-study/` |
| Layout blocks | `site/blueprints/blocks/cs-*.yml` + `site/snippets/blocks/cs-*.php` |
| Screenshot capture library | `src/Screenshots/`, `src/Support/SafeUrl.php` |
| Editor warnings | `src/Content/CaseStudyWarnings.php` (surfaced by `content:audit`) |

## Art direction

The **Art direction** tab on a project starts from a **preset** and then lets you
refine curated settings. Colours are chosen from a fixed palette (by name, never
raw hex); type, corner, border, density, image scale, rotation, caption and
pull-quote style, animation character, section transition, motif, hero and ending
are all whitelisted options with safe defaults. A bad or missing value always
falls back to a safe default — an editor can never inject CSS or break the page.

**Presets** (each seeds a coherent starting look you can still override):
Bold Editorial · Playful Collage · Quiet Premium · Image First · Device Showcase ·
Before & After · Typography Led · Colour Led · Story Driven. The default is bold,
not timid.

**Heroes** (preview each before choosing): cinematic · layered-devices ·
editorial · collage · minimal · split. **Endings** vary too (giant screenshot,
testimonial, results, lessons, mobile row, motif, visit) so no two case studies
end the same way.

## Layout blocks

Compose the story in the **Body** field from art-directed blocks — mix and
reorder freely:

- **Screenshots**: oversized, rotated, stacked, device arrangement, full-page
  (clipped strip / scrollable frame / slow pan), full-bleed, horizontal sequence,
  before/after (slider or split), annotated.
- **Editorial**: project statement (bold narration — *not* a client quote),
  editorial text, numbered section, typographic interlude, big pull quote,
  project facts.
- **Brand & colour**: colour block, font specimen, colour palette.

Every block is responsive (rotation calms and overlaps unwind on mobile),
keyboard-accessible where interactive, and degrades without JavaScript. No block
causes horizontal overflow.

## Authoring in Breakfast Admin

The whole workflow runs in the standalone **Breakfast Admin** (Portfolio) — not
the Kirby Panel and not the CLI. Kirby remains the underlying flat-file content
store; the owner never has to touch it.

Portfolio → **New case study** → the editor opens with tabs:

- **Details** — title, summary, live URL, and the *approved capture hosts + paths*.
- **Art direction** — preset + every curated control.
- **Blocks** — add, reorder and remove art-directed blocks.
- **Screenshots** — capture desktop/laptop/tablet/mobile (viewport or full page),
  then per-shot **approve public use**, complete the **privacy review**, and
  **attach** — attaching copies the capture into the project's media so blocks can
  use it. Failures show a human message (host not approved, private address,
  timeout, driver unavailable, …) — never a shell command or server path.
- **Composition** — the layered-composition editor: add desktop/mobile/annotation
  layers, reorder, set bounded scale + rotation, and per-breakpoint (tablet/mobile)
  visibility. Everything is clamped by `CompositionSanitizer`; nothing can escape
  the canvas or emit raw CSS.
- **Crop** — pick a source image and produce a nondestructive derivative (card /
  hero / social / mobile / detail), with optional 90° source rotation. The
  original is never modified.

### Preview Studio

The Preview tab renders the **real public template with the current unpublished
draft** in a same-origin iframe (no duplicate renderer):

- desktop / tablet / mobile widths + a **reduced-motion** toggle,
- a breakpoint indicator, block navigator and art-direction summary,
- an image-weight / performance estimate and the accessibility + privacy
  warnings,
- publication blockers (a case study can't be published until they clear),
- **refresh**, **open in new tab**, and **copy secure preview link**.

The secure link is signed, expiring and revocable — shareable without an admin
session, never indexable, never cached, and it carries no admin cookies or
internal fields. Revoking bumps a per-page counter that invalidates every link
already issued.

## Screenshots from a live site

Live capture runs on **your own infrastructure** via a trusted headless command,
never inside the web request. Configure it with `SCREENSHOT_CMD` (see
`.env.example`); when unset, a no-network stub driver is used so the workflow is
safe everywhere.

Capture is guarded end to end by `Support\SafeUrl`:

- **https only** (http only if you deliberately enable it), no `user:pass@`,
  standard ports only;
- the host must match the project's approved host allow-list (exact host or a
  dot-boundary subdomain — `www.acme.com` matches `acme.com`, `notacme.com` does
  not);
- **every** resolved A/AAAA address must be publicly routable — private,
  loopback, link-local (incl. `169.254.169.254` cloud metadata), CGNAT, reserved
  and IPv4-in-IPv6 smuggling are all blocked. It **fails closed**.

The headless command re-validates every redirect hop, enforces a timeout and a
hard output-size cap, and never touches authenticated pages.

### Library, versioning and the two gates

Each capture is stored on disk with a **versioned** metadata record. Recapture
creates a new version and supersedes (never overwrites) the previous one; the
full history is retained. A capture is **publishable only when a human has both**:

1. **approved it for public use**, and
2. **completed the privacy review** (`Screenshots\PrivacyChecklist` — personal
   data, accounts, browser chrome, admin bars, tracking IDs, internal URLs, …).

Both gates are independent and mandatory. Every action is written to the audit
log.

Operate it from the CLI:

```bash
php bin/console screenshots:capture --project=<uuid> --url=https://acme.com/about \
    --hosts=acme.com,www.acme.com --viewport=mobile --page=about [--full]
php bin/console screenshots:list    --project=<uuid>
php bin/console screenshots:approve --id=<uuid>     # public-use gate
php bin/console screenshots:privacy --id=<uuid> --notes="checked — nothing personal"
```

Viewports: `desktop` · `laptop` · `tablet` · `mobile`. Full-page captures are
height-capped and optimised (strip / frame / pan) — the raw natural height is
never rendered.

### Verifying capture on a deployment

`SCREENSHOT_CMD` should point at a trusted headless command that reads a JSON job
on stdin (`{url,width,height,full_page,dark,delay_ms,dismiss,allowed_hosts}`),
re-validates every redirect hop against `allowed_hosts`, blocks private
addresses, honours the viewport, and writes PNG/JPEG bytes to stdout.

After configuring it, verify on the target infrastructure:

```bash
php bin/console portfolio:capture:check --read-only
# then a real, cleaned-up capture against an approved public URL:
php bin/console portfolio:capture:check --url=https://your-approved-host.example/ --confirm
```

The read-only check reports the driver, browser/binary availability, storage
permissions and host configuration. The `--confirm` run performs ONE real
capture and deletes it; it only reports **LIVE CAPTURE CHECK PASSED** if a real
image actually came back — a stub driver reports **INCONCLUSIVE** instead, so a
green result always means live capture genuinely works on that host.

## Editor warnings

`content:audit` surfaces actionable, **non-blocking** warnings per case study
(`Content\CaseStudyWarnings`): image-transfer over the performance budget
(default 6 MB), oversized single images, missing alt text, and too many
heavy/animated or full-page blocks. These never block publication — that is
reserved for genuine accessibility, privacy or technical failures.

## Accessibility & motion

DOM order is the reading order regardless of visual position; large pull quotes
are semantic `blockquote`s only when genuinely quoted; images carry alt text;
interactive pieces (before/after, horizontal strip) are keyboard-operable; and
**all** animation, panning and parallax is disabled under
`prefers-reduced-motion: reduce`, with every revealed element shown. Rotation is
bounded so text never becomes unreadable and calms further on small screens.

## Performance

Images use Kirby's responsive derivatives (WebP where supported) and lazy
loading, with priority only on hero media. Long captures are segmented or panned
rather than shipped whole. The per-page image budget is enforced as a warning so
one case study can't quietly transfer tens of megabytes.
