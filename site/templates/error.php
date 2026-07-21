<?php snippet('layouts/header') ?>
<section class="section">
  <div class="container container--prose" style="text-align:center">
    <span class="note">404</span>
    <h1 class="section__title" style="margin-top:var(--s-4)"><?= esc($page->heading()->or('Page not found')) ?></h1>
    <?php if ($page->text()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->text()) ?></p><?php endif ?>
    <p style="margin-top:var(--s-8)"><a class="btn btn--primary btn--lg" href="<?= esc($page->cta_link()->or($site->url())) ?>"><?= esc($page->cta_label()->or('Back to home')) ?></a></p>
  </div>
</section>
<?php snippet('layouts/footer') ?>
