<?php

declare(strict_types=1);

/**
 * Creates the LocalMarkers portfolio entry through the SAME Portfolio service
 * methods the admin API uses — nothing is hand-written into the database. It is
 * an in-house product (not a client project): the client field carries
 * "In-house project", and no testimonial, quote or results figure is invented.
 *
 * Idempotent: if a record with the "localmarkers" slug already exists it does
 * nothing, so it is safe to run again (including in production — this is real
 * content, not a dev fixture).
 *
 * Invoked by `php bin/console portfolio:seed-localmarkers`.
 *
 * @var \Breakfast\Platform\Support\Platform $platform  (in scope from bin/console)
 * @var string                               $base      (in scope from bin/console)
 */

use Breakfast\Platform\Portfolio\PortfolioStatus;

$platform->migrator()->migrate();

$portfolio = $platform->portfolio();
$actor     = 'chris@breakfast';
$slug      = 'localmarkers';

// Idempotency guard: the slug is already taken (a record or a retired redirect).
if ($portfolio->uniqueSlug($slug) !== $slug) {
    out('portfolio:seed-localmarkers — "' . $slug . '" already exists. Nothing to do.');
    return;
}

$cover = $base . '/site/plugins/breakfast-platform/seed/assets/localmarkers-cover.png';
if (!is_file($cover)) {
    fail('Cover image missing at ' . $cover);
}

// 1. Create the record (draft). Only the columns create() accepts.
$rec = $portfolio->create([
    'internal_name'       => 'LocalMarkers',
    'slug'                => $slug,
    'display_title'       => 'LocalMarkers',
    'card_title'          => 'LocalMarkers',
    'case_study_headline' => 'A Google Business Profile service, built end to end',
    'client_display_name' => 'In-house project',
    'summary'             => 'A done-for-you Google Business Profile service for UK tradespeople, built end to end.',
    'project_type'        => 'In-house product',
    'location'            => 'North Wales',
    'website_url'         => 'https://localmarkers.co.uk',
    'link_label'          => 'Visit LocalMarkers',
], $actor);
$uuid = (string) $rec['uuid'];
out('Created record ' . $uuid);

// 2. Story sections (first person singular). Headings match the brief.
$portfolio->upsertStorySection($uuid, 'problem', [
    'heading' => 'The brief',
    'body'    => '<p>UK tradespeople rely on Google Business Profile for local enquiries, and almost none of them have the time to manage one properly. LocalMarkers turns that into a service someone else runs for them.</p>'
        . '<p>It needed a site that could sell to fifty-six different trades without feeling like fifty-six templates, and a back end that could actually run the service day to day.</p>',
    'visible' => true,
], $actor);

$portfolio->upsertStorySection($uuid, 'work', [
    'heading' => 'What I built',
    'body'    => '<p>I built it end to end:</p>'
        . '<ul>'
        . '<li>A fifty-six page trade section, each page written for the specific trade rather than spun from a template.</li>'
        . '<li>A matching free audit landing page for every trade, so paid and organic traffic each have somewhere to land.</li>'
        . '<li>A client portal where customers see what\'s being done to their profile.</li>'
        . '<li>An admin CRM for running the service side.</li>'
        . '<li>A fifty-point Google Business Profile audit framework that the whole service is built around.</li>'
        . '</ul>'
        . '<p>The stack: Kirby CMS, PHP, SQLite, vanilla CSS and JavaScript. No frameworks, no build step.</p>',
    'visible' => true,
], $actor);

$portfolio->upsertStorySection($uuid, 'outcome', [
    'heading' => 'What it proves',
    'body'    => '<p>This is the same approach behind everything I build. Words first, then the design around them. Fast by default, because there\'s nothing loaded that isn\'t doing a job.</p>',
    'visible' => true,
], $actor);
out('Added 3 story sections');

// 3. Cover image — through the real media pipeline (validate → secure original →
//    EXIF-stripped public variants), then record explicit public-use approval.
$media = $portfolio->attachMedia($uuid, [
    'kind' => 'image',
    'role' => 'cover',
    'alt'  => 'The LocalMarkers homepage — a Google Business Profile service for UK tradespeople.',
], $actor);
$mediaUuid = (string) $media['uuid'];

$result = $platform->portfolioMedia()->process($cover, 'localmarkers-cover.png', $mediaUuid, 'image/png');
$portfolio->setMediaProcessed($mediaUuid, $result['variants'], (int) $result['width'], (int) $result['height'], $actor);
$portfolio->approveMedia($mediaUuid, true, [
    'rights_source' => 'Own product',
    'rights_note'   => 'In-house Breakfast product (LocalMarkers). Screenshot of my own site.',
], $actor);
out('Processed + approved cover (' . implode(', ', array_keys($result['variants'])) . ')');
foreach ($result['warnings'] as $w) {
    out('  warning: ' . $w);
}

// 4. Link a service taxonomy (required to publish) + set SEO overrides and the
//    cover. create() doesn't take SEO/cover, so this rides on update().
$serviceTax = null;
foreach ($portfolio->taxonomies('service') as $t) {
    if (($t['slug'] ?? '') === 'website-design-build') {
        $serviceTax = $t;
        break;
    }
}
if ($serviceTax === null) {
    $serviceTax = $portfolio->createTaxonomy([
        'kind'         => 'service',
        'label'        => 'Website design & build',
        'slug'         => 'website-design-build',
        'service_slug' => 'new-website',
    ], $actor);
}
$portfolio->setRecordTaxonomies($uuid, [(string) $serviceTax['uuid']], $actor);

$portfolio->update($uuid, [
    'cover_media_uuid' => $mediaUuid,
    'seo_title'        => 'LocalMarkers — Google Business Profile service, built end to end',
    'seo_description'  => 'I built LocalMarkers end to end — a done-for-you Google Business Profile service for UK tradespeople. Kirby, PHP and vanilla CSS.',
    'og_title'         => 'LocalMarkers — a Google Business Profile service I built end to end',
    'og_description'   => 'A done-for-you Google Business Profile service for UK tradespeople, built end to end on Kirby, PHP and vanilla CSS.',
], (int) $rec['revision'], $actor);
out('Linked service taxonomy + set SEO + cover');

// 5. Validate, then walk the real state machine to published (draft → ready →
//    published). Publishing materialises the public snapshot.
$blockers = $portfolio->validateForPublish($uuid);
if ($blockers !== []) {
    fail('Refusing to publish — unresolved blockers: ' . implode(' | ', $blockers));
}
$portfolio->transition($uuid, PortfolioStatus::READY, $actor);
$portfolio->transition($uuid, PortfolioStatus::PUBLISHED, $actor);

out('Published. Live at /work/' . $slug . ' and listed at /work. OK');
