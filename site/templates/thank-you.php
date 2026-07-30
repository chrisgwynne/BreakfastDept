<?php snippet('layouts/header') ?>
<section class="section">
  <div class="container container--prose" style="text-align:center">
    <span class="note"><?= esc(t('breakfast.thanks', 'Thank you')) ?></span>
    <h1 class="section__title" style="margin-top:var(--s-4)"><?= esc($page->heading()->or($page->title())) ?></h1>
    <?php if ($page->text()->isNotEmpty()): ?><div class="blocks" style="margin-top:var(--s-6)"><?= $page->text()->toBlocks() ?></div><?php endif ?>
    <?php $ref = trim((string) get('ref')); $enquiry = $ref !== '' ? breakfast()->enquiries()->findByReference($ref) : null; if ($enquiry): ?><p class="form-status" data-enquiry-complete data-form="<?= esc((string) ($enquiry['form_type'] ?? 'contact')) ?>" data-reference="<?= esc($ref) ?>" style="margin-top:var(--s-8);display:inline-block"><?= esc(t('breakfast.reference', 'Your reference')) ?>: <strong><?= esc($ref) ?></strong></p><?php endif ?>
    <p style="margin-top:var(--s-8)"><a class="btn btn--primary btn--lg" href="<?= esc($page->back_link()->or($site->url())) ?>"><?= esc($page->back_label()->or('Back to home')) ?></a></p>
  </div>
</section>
<?php snippet('layouts/footer') ?>
