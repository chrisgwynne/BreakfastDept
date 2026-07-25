<?php
/**
 * Inline line-icon set for the public site. Stroke icons on currentColor,
 * decorative (aria-hidden handled by the caller). Unknown names fall back to a
 * spark so a card never renders an empty box.
 *
 * @var string $name
 */
$name = $name ?? 'spark';
$paths = [
    'globe'  => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 2.5 15 0 18M12 3c-2.5 2.5-2.5 15 0 18"/>',
    'cart'   => '<path d="M4 5h2l1.5 10h10L20 8H7"/><circle cx="9" cy="19" r="1.4"/><circle cx="17" cy="19" r="1.4"/>',
    'wrench' => '<path d="M15 4a5 5 0 0 0-6.5 6.3L4 14.8 6.2 17l4.5-4.5A5 5 0 0 0 17 6l-2.6 2.6-2-2L15 4z"/>',
    'spark'  => '<path d="M12 3v6M12 15v6M3 12h6M15 12h6"/>',
    'egg'    => '<path d="M12 3c3.5 0 6 5 6 9a6 6 0 0 1-12 0c0-4 2.5-9 6-9z"/>',
];
$d = $paths[$name] ?? $paths['spark'];
?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $d ?></svg>
