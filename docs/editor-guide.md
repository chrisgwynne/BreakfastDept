# Editor guide

This guide is for the people who edit the Breakfast website and read enquiries —
no technical knowledge assumed. It explains how to log in, manage content, and
work with the CRM.

## Logging in

Go to `/breakfast-admin` on the site (for example
`https://breakfast.example/breakfast-admin`) and sign in with your email and
password. This is **Breakfast Admin** — our own branded control panel. (The
address is set by `BREAKFAST_ADMIN_SLUG`; the old `/panel` address no longer
works and returns a "page not found".)
If you don't have an account, ask an administrator to create one for you and
give you the right role. What you can see and do depends on your role.

## Global settings (the whole site)

Open **Site** (the top-level settings) to edit things that appear on every page:

- **Navigation** — the primary menu (`nav`) and the footer menu (`footer_nav`):
  each is a list of label + link.
- **Footer** — the sign-off copy and social links.
- **Calls to action (CTAs)** — the global CTA heading, body and up to two
  buttons, used as the default across the site.
- **Contact details** — email, enquiry email, phone and postal address.
- **Announcement / maintenance banners**, availability text, and the default
  social share image and meta description.

Change these once and they update everywhere they're used.

## Pages: projects, services and journal articles

The site's content lives in sections you can add to and edit:

- **Work** → individual **projects** (case studies): client, summary,
  challenge/approach/outcome, metrics, a testimonial, images and related work.
- **Services** → individual **services**: what it's for, deliverables, process,
  outcomes, pricing guidance, FAQs and featured projects.
- **Journal** → individual **articles**: standfirst (excerpt), cover image,
  categories/tags, the article body and related content.

To create one, open the parent (Work / Services / Journal), choose **Add**, give
it a title, and fill in the fields. The exact field list for every page type is
in [content-model.md](content-model.md) if you need the detail.

## The block editor

Longer content — article bodies, project sections, page intros — uses the
**block editor**: you build the page from a set of ready-made blocks rather than
free HTML. Available blocks include: hero, intro, text (rich text), image,
image-and-text, gallery, project grid, service grid, testimonials, logos, stats,
process, FAQ, quote, pullquote, team, journal feed, CTA, divider, video, embed,
comparison, related, callout, code and columns.

Each block offers only sensible, constrained options, and text is always
rendered safely — you don't need to worry about breaking the layout or the
site's security. Add a block with the **+** button, drag to reorder, and use the
block's own controls to edit it.

## Draft vs published

Every page has a status:

- **Draft** — only visible to logged-in editors. New pages start here.
- **Unlisted** — reachable by direct link but not listed in indexes.
- **Published (listed)** — publicly visible and included in navigation, indexes,
  the sitemap and RSS.

Work in draft, preview, then publish when you're happy. Drafts and unlisted
pages are automatically marked "noindex" so search engines don't pick them up.

## SEO fields and the "missing SEO" indicators

Each public page has an **SEO** tab: `seo_title`, `meta_description`, a canonical
URL, a "no index" toggle, and social sharing title/description/image. You don't
have to fill everything in — the site falls back sensibly (page title, excerpt,
default social image). But the Panel flags pages that are **missing an SEO title
or meta description** so you can improve them; filling those two in gives the
best result in search and social previews.

## The CRM: reading and working enquiries

Open the **CRM** area (in the Panel menu — you'll only see it if your role has
CRM access). You can:

- **Read enquiries.** Every message from the contact and start-a-project forms
  lands here with a reference (e.g. `ENQ-2026-0001`), the sender's details, a
  summary and the full submission. Each enquiry has a timeline showing
  everything that has happened to it.
- **Change status.** Move an enquiry through `new` → `reviewing` → `qualified`
  (or mark spam / closed). Every change is recorded on the timeline.
- **Add notes.** Notes are permanent timeline entries — useful for recording a
  phone call or a decision.
- **Create tasks.** Add a follow-up with a due date and priority, optionally
  linked to a contact or opportunity.
- **Move pipeline stages.** On the **Pipeline** board, drag an opportunity
  through the stages: `new` → `qualified` → `discovery` → `proposal` →
  `decision` → `won` (or `lost`, with a reason). Marking an opportunity won or
  lost is stamped automatically.

Some of these actions (changing status, adding notes, creating/moving records)
require the **manage** permission; read-only roles can view everything but not
change it. If a button doesn't work for you, you likely have a read-only role.

## Reviewing Hermes drafts

The studio's automation ("Hermes") can prepare **draft** articles, projects or
pages for you — for example from a set of notes. These always arrive as
**unpublished drafts** and are never published automatically. Treat them as a
starting point: **review and edit every Hermes-created draft, then publish it
yourself** when it's right. Hermes can never change or publish live content — a
human always makes that final call.
