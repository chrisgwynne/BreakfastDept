<?php snippet('layouts/header') ?>
<section class="section" id="form">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <div class="section__head" style="max-width:46rem">
      <span class="kicker">P101</span>
      <h1 class="section__title">LET'S MAKE SOMETHING GREAT</h1>
      <p class="section__lead"><?= esc($page->title()) ?></p>
    </div>
    <?php if ($page->intro()->isNotEmpty()): ?><div class="blocks" style="margin-bottom:var(--s-8)"><?= $page->intro()->toBlocks() ?></div><?php endif ?>
    <div class="enquiry-next reveal" aria-labelledby="next-heading">
      <h2 id="next-heading">What happens next</h2>
      <ol>
        <li><strong>You send the rough outline.</strong><span>A few useful lines are enough; you do not need a finished brief.</span></li>
        <li><strong>I check the fit.</strong><span>I reply with an honest view of the useful scope and whether I am the right person for it.</span></li>
        <li><strong>You get a clear next step.</strong><span>If it makes sense to continue, the work is scoped and quoted in writing before anything starts.</span></li>
      </ol>
    </div>
    <?php snippet('forms/project-form', ['page' => $page, 'result' => $result, 'old' => $old]) ?>
  </div>
</section>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Back',     'sub' => 'P100', 'href' => url('/')],
    ['label' => 'Services', 'sub' => 'P300', 'href' => page('services') ? page('services')->url() : url('services')],
    ['label' => 'Review',   'sub' => 'P110', 'href' => url('website-review')],
    ['label' => 'Contact',  'sub' => 'P700', 'href' => url('contact')],
]]) ?>
