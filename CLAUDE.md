# Working preferences

## Commits

- **Commit often — one commit per logical change, as the work happens.**
  Do not batch many changes into a single large commit, and do not squash
  history unless explicitly asked. Each self-contained step (a fix, a feature
  slice, a doc update, a test) gets its own commit with a clear message.
- **Author commits as Chris Gwynne <chrisgwynne@gmail.com>.**

## PR monitoring

- **Never schedule PR check-ins to poll whether a PR has merged or closed.**
  It wastes time and resources. Rely on the webhook activity that already
  arrives for the PR; do not set `send_later`/scheduled wake-ups for this.
