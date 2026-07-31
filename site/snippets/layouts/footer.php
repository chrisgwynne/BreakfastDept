<?php

use Breakfast\Platform\Support\Runtime;

/** Site footer, cookie banner (only when required), scripts. */
$nonce     = Runtime::security()->nonce();
$analytics = $site->analytics();
$footerNav = $site->footer_nav()->toStructure();
$social    = $site->social()->toStructure();
?>
  </main>

  <footer class="site-footer">
    <div class="container">
      <div class="footer__inner">
        <div class="footer__brand">
          <a class="logo" href="<?= esc($site->url()) ?>" aria-label="<?= esc($site->title()) ?> home">
            <span class="logo__word"><?= esc($site->title()->or('Breakfast')) ?></span>
            <span class="logo__egg" aria-hidden="true"><span class="logo__white"><span class="logo__yolk"></span></span></span>
          </a>
          <?php if ($site->tagline()->isNotEmpty()): ?>
            <p class="footer__tagline"><?= esc($site->tagline()) ?></p>
          <?php endif ?>
          <?php if ($social->isNotEmpty()): ?>
            <ul class="footer__social" aria-label="Social links" style="display:flex;gap:12px;margin-top:16px">
              <?php foreach ($social as $s): ?>
                <li><a href="<?= esc($s->url()) ?>" rel="me noopener"><?= esc($s->platform()) ?></a></li>
              <?php endforeach ?>
            </ul>
          <?php endif ?>
        </div>

        <?php if ($footerNav->isNotEmpty()): ?>
        <nav class="footer__col" aria-label="Footer">
          <h3><?= esc(t('breakfast.footer.explore', 'Explore')) ?></h3>
          <ul>
            <?php foreach ($footerNav as $item): ?>
              <?php $t = $item->link()->toPage(); $href = $t ? $t->url() : $item->link()->value(); ?>
              <li><a href="<?= esc($href) ?>"><?= esc($item->label()) ?></a></li>
            <?php endforeach ?>
          </ul>
        </nav>
        <?php endif ?>

        <div class="footer__col">
          <h3><?= esc(t('breakfast.footer.contact', 'Get in touch')) ?></h3>
          <ul>
            <?php if ($site->email()->isNotEmpty()): ?>
              <li><a href="mailto:<?= esc($site->email()) ?>"><?= esc($site->email()) ?></a></li>
            <?php endif ?>
            <?php if ($site->phone()->isNotEmpty()): ?>
              <li><a href="tel:<?= esc(preg_replace('/\s+/', '', $site->phone()->value())) ?>"><?= esc($site->phone()) ?></a></li>
            <?php endif ?>
            <?php if ($site->availability_text()->isNotEmpty()): ?>
              <li><?= esc($site->availability_text()) ?></li>
            <?php endif ?>
          </ul>
        </div>
      </div>

      <div class="footer__base">
        <?php /* One copyright line only. Prefer the editable footer_copy; fall back to year + brand. */ ?>
        <p><?= $site->footer_copy()->or('© ' . date('Y') . ' ' . esc($site->title()) . '. Independent web design in Wales.') ?></p>
      </div>
    </div>
  </footer>

  <?php if ($analytics->enabled() && $analytics->requiresConsent()): ?>
  <div class="cookie-banner" data-cookie-banner role="dialog" aria-live="polite" aria-label="Cookie choices">
    <p><?= esc($site->cookie_message()->or('I use privacy-respecting analytics. Accept cookies?')) ?></p>
    <div class="cookie-banner__actions">
      <button class="btn" data-consent="decline"><?= esc(t('breakfast.cookies.decline', 'Decline')) ?></button>
      <button class="btn btn--primary" data-consent="accept"><?= esc(t('breakfast.cookies.accept', 'Accept')) ?></button>
    </div>
  </div>
  <?php snippet('layouts/consent-analytics', ['nonce' => $nonce, 'analytics' => $analytics]) ?>
  <?php endif ?>

  <script src="<?= esc(url('assets/js/app.js')) ?>?v=6" defer></script>
  <?php /* Case-study enhancements (before/after slider, pan, parallax, strip
          keyboard) load only on project pages and degrade fully without JS. */ ?>
  <?php if ($page->intendedTemplate()->name() === 'project'): ?>
  <script src="<?= esc(url('assets/js/case-study.js')) ?>?v=<?= (int) (@filemtime($kirby->root('assets') . '/js/case-study.js') ?: 1) ?>" defer></script>
  <?php endif ?>
</body>
</html>
