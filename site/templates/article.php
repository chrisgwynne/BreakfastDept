<?php

use Breakfast\Platform\Teletext\Registry;

snippet('layouts/header');
$ttNumber = Registry::numberFor($page, $site);
$teletextEditions = [
    'five-second-test' => [
        ['THE TEST', 'Show the homepage for five seconds. Can a stranger say what you do and what to do next? If not, the page is asking too much.'],
        ['THREE QUESTIONS', 'What is this? Is it for me? What should I do next? Answer all three before the first scroll.'],
        ['THE FIX', 'Use plain words at the top. Say what you sell, who it is for, and the one useful next step.'],
    ],
    'booking-button' => [
        ['START WITH VISIBILITY', 'Most small businesses do not need a new booking system. They need one obvious button near the top of the page.'],
        ['MAKE IT EASY', 'Keep the action visible on a phone. Send people to the calendar, phone number, or form you already use.'],
        ['BUY LATER', 'Only add dedicated software when staff, deposits, resources, or complex availability genuinely demand it.'],
    ],
    'what-a-website-costs' => [
        ['WHY IT VARIES', 'A website can be one focused page, a full brochure site, or a shop with complex bookings. The job changes the price.'],
        ['WHAT COSTS', 'The expensive part is usually thinking and writing: working out what the site must say and do.'],
        ['THE HONEST ANSWER', 'A smaller, clearer site is often cheaper than expected. A shop costs more. Get a written scope before work starts.'],
    ],
];
$teletextEdition = $teletextEditions[$page->slug()] ?? null;
?>
<article class="section tt-page tt-article-page">
  <div class="container">
    <?php snippet('partials/breadcrumbs') ?>
    <header class="article__header">
      <?php snippet('teletext/bar', ['number' => $ttNumber !== null ? 'P' . $ttNumber : '', 'title' => 'BREAKFAST JOURNAL', 'sub' => '1/4', 'as' => false]) ?>
      <h1 class="section__title"><?= esc($page->title()) ?></h1>
      <?php if ($page->excerpt()->isNotEmpty()): ?><p class="section__lead"><?= esc($page->excerpt()) ?></p><?php endif ?>
      <div class="article__meta">
        <?php if ($page->author()->isNotEmpty()): ?><span><?= esc($page->author()) ?></span><?php endif ?>
        <?php if ($page->date()->isNotEmpty()): ?><time datetime="<?= esc($page->date()->toDate('c')) ?>"><?= esc($page->date()->toDate('j F Y')) ?></time><?php endif ?>
        <span><?= $page->readingTime() ?> <?= esc(t('breakfast.minread', 'min read')) ?></span>
      </div>
    </header>

    <?php if ($cover = $page->cover()->toFile()): ?>
      <div class="article__cover container--prose" style="margin-inline:auto"><?= $cover->crop(1200, 675)->html(['alt' => esc($cover->alt()->or($page->title())), 'fetchpriority' => 'high']) ?></div>
    <?php endif ?>

    <div class="container--prose article__body" style="margin-inline:auto">
      <?php if ($page->toc_enabled()->toBool(false)): ?>
        <?php snippet('partials/toc', ['page' => $page]) ?>
      <?php endif ?>
      <?php if ($teletextEdition): ?>
        <div class="blocks blocks--teletext-edition">
          <?php foreach ($teletextEdition as [$heading, $text]): ?><section><h2><?= esc($heading) ?></h2><p><?= esc($text) ?></p></section><?php endforeach ?>
        </div>
      <?php else: ?>
        <div class="blocks"><?= $page->body()->toBlocks() ?></div>
      <?php endif ?>

      <?php if ($page->tags()->isNotEmpty()): ?>
        <p style="margin-top:var(--s-12)"><?php foreach ($page->tags()->split() as $tag): ?><span class="tag"><?= esc($tag) ?></span> <?php endforeach ?></p>
      <?php endif ?>
    </div>

    <?php snippet('partials/related', ['related' => $page->related_articles()->toPages()->merge($page->related_projects()->toPages()), 'heading' => t('breakfast.readnext', 'Read next')]) ?>
  </div>
</article>
<section class="section"><div class="container">
  <?php snippet('partials/cta-band', ['heading' => $page->cta_heading()->or($site->cta_heading()), 'text' => $page->cta_text()->or($site->cta_text())]) ?>
</div></section>
<?php snippet('layouts/footer', ['softkeys' => [
    ['label' => 'Back',    'sub' => 'P600', 'href' => page('journal') ? page('journal')->url() : url('journal')],
    ['label' => 'Work',    'sub' => 'P200', 'href' => page('work') ? page('work')->url() : url('work')],
    ['label' => 'Start',   'sub' => 'P101', 'href' => url('start-a-project')],
    ['label' => 'Contact', 'sub' => 'P700', 'href' => url('contact')],
]]) ?>
