<?php
/**
 * RSS 2.0 feed for the journal.
 * @var \Kirby\Cms\Page $parent
 */
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$articles = $parent->children()->listed()->sortBy('date', 'desc')->limit(30);
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title><?= esc(site()->title()) ?> — Journal</title>
    <link><?= esc($parent->url()) ?></link>
    <atom:link href="<?= esc($parent->url()) ?>/feed.rss" rel="self" type="application/rss+xml"/>
    <description><?= esc($parent->content()->get('intro')->excerptSafe(200)) ?></description>
    <language>en-gb</language>
    <lastBuildDate><?= date(DATE_RSS) ?></lastBuildDate>
    <?php foreach ($articles as $article): ?>
    <item>
      <title><?= esc($article->title()) ?></title>
      <link><?= esc($article->url()) ?></link>
      <guid isPermaLink="true"><?= esc($article->url()) ?></guid>
      <?php if ($article->date()->isNotEmpty()): ?>
      <pubDate><?= date(DATE_RSS, $article->date()->toDate()) ?></pubDate>
      <?php endif ?>
      <description><?= esc($article->excerpt()->or($article->text()->excerptSafe(240))) ?></description>
    </item>
    <?php endforeach ?>
  </channel>
</rss>
