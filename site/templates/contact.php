<?php snippet('layouts/header') ?>
<section class="section" id="form">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <div class="grid grid--2" style="align-items:start;gap:var(--s-16)">
      <div>
        <div class="section__head" style="margin-bottom:var(--s-8)">
          <h1 class="section__title"><?= esc($page->title()) ?></h1>
        </div>
        <?php if ($page->intro()->isNotEmpty()): ?><div class="blocks"><?= $page->intro()->toBlocks() ?></div><?php endif ?>
        <div class="card" style="margin-top:var(--s-8)">
          <?php if ($site->email()->isNotEmpty()): ?><p><strong><?= esc(t('breakfast.email', 'Email')) ?>:</strong> <a href="mailto:<?= esc($site->email()) ?>"><?= esc($site->email()) ?></a></p><?php endif ?>
          <?php if ($site->phone()->isNotEmpty()): ?><p><strong><?= esc(t('breakfast.phone', 'Phone')) ?>:</strong> <a href="tel:<?= esc(preg_replace('/\s+/', '', $site->phone()->value())) ?>"><?= esc($site->phone()) ?></a></p><?php endif ?>
          <?php if ($site->availability_text()->isNotEmpty()): ?><p><?= esc($site->availability_text()) ?></p><?php endif ?>
        </div>
      </div>
      <div>
        <?php snippet('forms/contact-form', ['page' => $page, 'result' => $result, 'old' => $old]) ?>
      </div>
    </div>
  </div>
</section>
<?php snippet('layouts/footer') ?>
