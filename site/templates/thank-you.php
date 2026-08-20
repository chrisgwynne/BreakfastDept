<?php snippet('layouts/header') ?>
<section class="section" style="border-bottom:0">
  <div class="container container--prose tt-egg" style="text-align:center">
    <p class="kicker" style="justify-content:center">P102</p>
    <h1 class="hero__title" style="margin-top:var(--s-4);font-size:clamp(1.8rem,5vw,2.8rem);color:var(--tt-green)">MESSAGE RECEIVED</h1>
    <?php if ($page->heading()->isNotEmpty() && $page->heading()->value() !== $page->title()->value()): ?>
      <p class="section__lead" style="margin-inline:auto"><?= esc($page->heading()) ?></p>
    <?php endif ?>
    <?php if ($page->text()->isNotEmpty()): ?><div class="blocks" style="margin-top:var(--s-6);text-align:left"><?= $page->text()->toBlocks() ?></div><?php endif ?>

    <?php $ref = trim((string) get('ref')); $enquiry = $ref !== '' ? breakfast()->enquiries()->findByReference($ref) : null; ?>
    <?php if ($enquiry): ?>
      <div class="card" data-enquiry-complete data-form="<?= esc((string) ($enquiry['form_type'] ?? 'contact')) ?>" data-reference="<?= esc($ref) ?>" style="margin-top:var(--s-8);display:inline-block;text-align:left">
        <p class="kicker">Reference</p>
        <p style="margin-top:var(--s-2);font-family:var(--mono);font-size:1.3rem;font-weight:700;color:var(--tt-yellow)"><?= esc($ref) ?></p>
      </div>
    <?php endif ?>
    <p style="margin-top:var(--s-8)"><a class="btn btn--primary btn--lg" href="<?= esc($page->back_link()->or($site->url())) ?>"><?= esc($page->back_label()->or('Back to home')) ?></a></p>
  </div>
</section>
<?php snippet('layouts/footer') ?>
