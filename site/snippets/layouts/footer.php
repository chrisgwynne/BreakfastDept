<?php

use Breakfast\Platform\Support\Runtime;
use Breakfast\Platform\Teletext\Registry;

/** Site footer, soft keys, cookie banner (only when required), scripts. */
$nonce     = Runtime::security()->nonce();
$analytics = $site->analytics();
$footerNumber = Registry::displayNumberFor($page, $site);

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
    </div>
  </main>

  <?php /* A template can call snippet('layouts/footer', ['softkeys' => [...]])
          before this to override the 4 default soft keys for its page. */ ?>
  <?php /* Soft keys are rendered inside the final service-information end-cap. */ ?>

  <footer class="site-footer">
    <div class="tt-service-footer">
      <div class="tt-service-footer__head"><strong>BREAKFAST</strong><span>SERVICE INFORMATION</span><b>P900</b></div>
      <nav class="tt-service-footer__index" aria-label="Breakfast service index">
        <?php $indexLinks = [
          ['100', 'INDEX', url('/')], ['110', 'WEBSITE REVIEW', url('website-review')],
          ['200', 'OUR WORK', page('work')?->url() ?? url('work')], ['300', 'SERVICES', page('services')?->url() ?? url('services')],
          ['500', 'ABOUT', page('about')?->url() ?? url('about')],
          ['600', 'JOURNAL', page('journal')?->url() ?? url('journal')], ['700', 'CONTACT', page('contact')?->url() ?? url('contact')],
        ]; foreach ($indexLinks as [$number, $label, $href]): ?>
          <a href="<?= esc($href) ?>"><b><?= esc($number) ?></b><span><?= esc($label) ?></span></a>
        <?php endforeach ?>
      </nav>
      <div class="tt-service-footer__status">
        <span class="tt-service-footer__availability"><i aria-hidden="true"></i><?= esc($site->availability_text()->or('Taking on new projects')) ?></span>
        <?php if ($site->email()->isNotEmpty()): ?><a href="mailto:<?= esc($site->email()) ?>">MAIL <?= esc($site->email()) ?></a><?php endif ?>
      </div>
      <div class="tt-service-footer__utility">
        <nav aria-label="Utility pages"><a href="<?= esc(url('privacy')) ?>"><b>901</b> PRIVACY</a><a href="<?= esc(url('accessibility')) ?>"><b>902</b> ACCESSIBILITY</a><a href="<?= esc(url('terms')) ?>"><b>903</b> TERMS</a></nav>
        <p class="tt-display-toggle"><span>DISPLAY MODE:</span><button type="button" data-tt-display="clean" aria-pressed="true">CLEAN</button><button type="button" data-tt-display="crt" aria-pressed="false">CRT</button></p>
      </div>
    </div>
    <?php snippet('teletext/softkeys', ['softkeys' => $softkeys ?? null]) ?>
    <div class="tt-ticker tt-ticker--final" data-tt-ticker data-tt-messages="<?= esc(json_encode($tickerLines, JSON_UNESCAPED_SLASHES)) ?>"><span>BREAKFAST DEPT. LTD · <?= esc($tickerLines[0]) ?></span><b><?= $footerNumber !== null ? 'P' . esc($footerNumber) : 'P—' ?></b></div>
  </footer>

  <?php if ($page->isHomePage() === false): ?>
      </div>
    </div>
  </div>
  <nav class="tt-subpage-controls" data-tt-subpage-controls aria-label="Teletext subpage controls">
    <button class="tt-subpage-controls__prev" type="button" data-tt-subpage-prev><b>RED</b> PREV</button>
    <span data-tt-subpage-label>P---/01</span>
    <button class="tt-subpage-controls__hold" type="button" data-tt-subpage-hold>HOLD</button>
    <button class="tt-subpage-controls__next" type="button" data-tt-subpage-next><b>GREEN</b> NEXT</button>
  </nav>
  <?php endif ?>

  <?php /* Go-to-page overlay + secret-page discovery toast — markup only,
          behaviour lives in teletext/navigation.js and easter-eggs.js. */ ?>
  <div class="tt-acquire" data-tt-acquire role="status" aria-live="polite" aria-label="Acquiring Teletext page">
    <div class="tt-acquire__holder" data-tt-acquire-holder>
      <div class="tt-acquire__screen" data-tt-acquire-screen>
        <div class="tt-acquire__header"><span data-tt-acquire-received>P100</span><strong>BREAKFAST</strong><time data-tt-clock>00:00:00</time></div>
        <div class="tt-acquire__band">BREAKFAST TEXT &middot; PAGE ACQUISITION</div>
        <div class="tt-acquire__field">
          <p>REQUESTED PAGE</p>
          <strong data-tt-acquire-requested>P---</strong>
          <span data-tt-acquire-title>SEARCHING TRANSMISSION</span>
        </div>
        <div class="tt-acquire__meter" aria-hidden="true"><i data-tt-acquire-meter></i></div>
        <p class="tt-acquire__status" data-tt-acquire-status>WAITING FOR PAGE HEADER...</p>
        <p class="tt-acquire__clue" data-tt-acquire-clue>CLUE: THE INDEX DOES NOT SHOW EVERYTHING.</p>
        <p class="tt-acquire__hint"><b>H</b> HOLD&nbsp;&nbsp; <b>ESC</b> CANCEL&nbsp;&nbsp; DATA SERVICE ONLINE</p>
      </div>
    </div>
  </div>

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
  <?php if ($page->isHomePage()): ?>
  <script src="<?= esc(url('assets/js/teletext/home-screen.js')) ?>?v=<?= (int) (@filemtime($kirby->root('assets') . '/js/teletext/home-screen.js') ?: 1) ?>" defer></script>
  <?php else: ?>
  <script src="<?= esc(url('assets/js/teletext/page-screen.js')) ?>?v=<?= (int) (@filemtime($kirby->root('assets') . '/js/teletext/page-screen.js') ?: 1) ?>" defer></script>
  <?php endif ?>
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
    $ttScripts = ['clock', 'acquisition', 'navigation', 'display-mode', 'easter-eggs', 'ticker', 'form-transmit'];
    foreach ($ttScripts as $ttScript):
      $ttPath = "assets/js/teletext/{$ttScript}.js";
      $ttVer  = (int) (@filemtime($kirby->root('assets') . "/js/teletext/{$ttScript}.js") ?: 1);
  ?>
  <script src="<?= esc(url($ttPath)) ?>?v=<?= $ttVer ?>" defer></script>
  <?php endforeach ?>
</body>
</html>
