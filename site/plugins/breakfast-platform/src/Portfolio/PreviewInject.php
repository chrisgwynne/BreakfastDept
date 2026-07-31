<?php

declare(strict_types=1);

namespace Breakfast\Platform\Portfolio;

/** Small, pure HTML post-processors for the preview routes. */
final class PreviewInject
{
    /**
     * Force the reduced-motion, fully-revealed state so the Preview Studio can
     * show the reduced-motion experience regardless of the viewer's OS setting.
     * Injects one inline stylesheet before </head> — no scripts, nothing else
     * touched.
     */
    public static function reducedMotion(string $html): string
    {
        $style = '<style id="bf-force-rm">*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}'
            . '.cs .reveal{opacity:1!important;transform:none!important;clip-path:none!important}</style>';
        $pos = stripos($html, '</head>');
        if ($pos === false) {
            return $style . $html;
        }

        return substr($html, 0, $pos) . $style . substr($html, $pos);
    }
}
