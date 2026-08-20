<?php

use Breakfast\Platform\Support\Runtime;
use Breakfast\Platform\Teletext\Registry;

/** Site footer, soft keys, cookie banner (only when required), scripts. */
$nonce     = Runtime::security()->nonce();
$analytics = $site->analytics();
$footerNav = $site->footer_nav()->toStructure();
$social    = $site->social()->toStructure();

// Ticker messages: editable via site.footer_ticker (one per line); a sane
// fallback keeps the ticker meaningful even before an editor fills it in.
$tickerLines = $site->footer_ticker()->split("\n");
if (empty($tickerLines)) {
    $tickerLines = [
        'GOOD WEBSITES. PLAIN ENGLISH. MADE IN NORTH WALES.',
        'WORDS BEFORE DECORATION.',
        'NEED A WEBSITE? START AT 101.',
    ];
}
?>
  </main>

  <?php /* A template can call snippet('layouts/footer', ['softkeys' => [...]])
          before this to override the 4 default soft keys for its page. */ ?>
  <?php snippet('teletext/softkeys', ['softkeys' => $softkeys ?? null]) ?>

  <footer class="site-footer">
    <div class="tt-ticker" data-tt-ticker data-tt-messages="<?= esc(json_encode($tickerLines, JSON_UNESCAPED_SLASHES)) ?>"><?= esc($tickerLines[0]) ?></div>
    <div class="container">
      <div class="footer__inner">
        <div class="footer__brand">
          <a class="logo" href="<?= esc($site->url()) ?>" aria-label="<?= esc($site->title()) ?> home">
            <span class="logo__word"><?= esc($site->title()->or('Breakfast')) ?> TEXT</span>
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
          <p class="tt-display-toggle" style="margin-top:16px">
            DISPLAY:
            <button type="button" data-tt-display="clean" aria-pressed="true">CLEAN</button>
            <button type="button" data-tt-display="crt" aria-pressed="false">CRT</button>
          </p>
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
        <p>BREAKFAST TEXT · <?= esc(Registry::numberFor($page, $site) !== null ? 'P' . Registry::numberFor($page, $site) : 'P—') ?></p>
      </div>
    </div>
  </footer>

  <?php /* Go-to-page overlay + secret-page discovery toast — markup only,
          behaviour lives in teletext/navigation.js and easter-eggs.js. */ ?>
  <div class="tt-goto" data-tt-goto role="dialog" aria-modal="true" aria-label="Go to page">
    <div class="tt-goto__box">
      <p class="tt-goto__label">GO TO PAGE</p>
      <p class="tt-goto__value" data-tt-goto-value>P---</p>
      <p class="tt-goto__hint">Type a number · Enter to go · Esc to cancel</p>
    </div>
  </div>
  <div class="tt-toast" data-tt-toast role="status" aria-live="polite"></div>
  <?php /* Secret pages live at /text/{number} — the slug IS the number, so
          discovery tracking never needs the number written anywhere else. */ ?>
  <?php $textParent = $site->find('text'); ?>
  <?php if ($textParent !== null && $page->parent() !== null && $page->parent()->is($textParent) && ctype_digit($page->slug())): ?>
    <span hidden data-tt-secret="<?= esc($page->slug()) ?>" data-tt-secret-title="<?= esc($page->title()) ?>"></span>
  <?php endif ?>
  <script type="application/json" id="tt-registry"><?= Registry::toPublicJson($site) ?></script>

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
  <?php /* P777 jackpot interaction — only ever loaded on its own page. */ ?>
  <?php if ($page->intendedTemplate()->name() === 'jackpot'): ?>
  <script src="<?= esc(url('assets/js/teletext/jackpot.js')) ?>?v=<?= (int) (@filemtime($kirby->root('assets') . '/js/teletext/jackpot.js') ?: 1) ?>" defer></script>
  <?php endif ?>
  <?php
    $ttScripts = ['clock', 'navigation', 'display-mode', 'easter-eggs', 'ticker', 'form-transmit'];
    foreach ($ttScripts as $ttScript):
      $ttPath = "assets/js/teletext/{$ttScript}.js";
      $ttVer  = (int) (@filemtime($kirby->root('assets') . "/js/teletext/{$ttScript}.js") ?: 1);
  ?>
  <script src="<?= esc(url($ttPath)) ?>?v=<?= $ttVer ?>" defer></script>
  <?php endforeach ?>
</body>
</html>
