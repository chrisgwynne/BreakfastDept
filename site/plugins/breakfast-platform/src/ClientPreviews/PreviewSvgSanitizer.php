<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

/**
 * Conservative SVG sanitiser. SVG can carry active content (scripts, event
 * handlers, external references), so every uploaded SVG is rewritten here at
 * upload time. This is belt-and-braces: previews are ALSO served from an
 * isolated origin under a script-free CSP, so the two controls are independent.
 *
 * The approach is allow-list-ish removal: reject any DOCTYPE/entity declaration
 * (XXE / entity-expansion), strip <script>/<foreignObject>, every on* event
 * handler, and any href/xlink:href that is not a safe local/data reference. If
 * the document cannot be parsed, sanitisation fails (caller treats that as a
 * blocking validation error).
 *
 * Note: the preview CSP intentionally allows inline scripts so ordinary HTML
 * mock-ups work, so an SVG served top-level as image/svg+xml with inline script
 * WOULD execute — this sanitiser is therefore the primary control for SVG active
 * content, not a redundant backstop.
 */
final class PreviewSvgSanitizer
{
    private const REMOVE_ELEMENTS = ['script', 'foreignObject', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video', 'animate', 'set', 'handler', 'listener'];

    /**
     * @return array{ok:bool,svg?:string,error?:string}
     */
    public static function sanitize(string $svg): array
    {
        if (trim($svg) === '') {
            return ['ok' => false, 'error' => 'empty_svg'];
        }

        // Reject any DTD / entity declaration outright. A static SVG mock-up never
        // needs one, and allowing it enables XXE (external `file://`/`http://`
        // entities) and entity-expansion DoS. This is belt-and-braces with the
        // libxml flags below, which do NOT expand entities (no LIBXML_NOENT) and
        // forbid network access (LIBXML_NONET).
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $svg) === 1) {
            return ['ok' => false, 'error' => 'doctype_forbidden'];
        }

        $prev = libxml_use_internal_errors(true);
        $dom  = new \DOMDocument();
        // No network access and NO entity substitution — an entity reference is
        // left inert rather than resolved, so no local file can ever be read.
        $loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($loaded === false || $dom->documentElement === null) {
            return ['ok' => false, 'error' => 'unparseable_svg'];
        }

        self::scrub($dom->documentElement);

        $out = $dom->saveXML($dom->documentElement);
        if ($out === false) {
            return ['ok' => false, 'error' => 'serialise_failed'];
        }

        return ['ok' => true, 'svg' => $out];
    }

    private static function scrub(\DOMElement $element): void
    {
        // Remove dangerous elements (iterate a snapshot; we mutate the tree).
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                if (in_array(strtolower($child->localName ?? $child->nodeName), self::REMOVE_ELEMENTS, true)) {
                    $element->removeChild($child);
                    continue;
                }
                self::scrub($child);
            }
        }

        // Strip unsafe attributes on this element.
        if ($element->hasAttributes()) {
            foreach (iterator_to_array($element->attributes) as $attr) {
                /** @var \DOMAttr $attr */
                $name  = strtolower($attr->nodeName);
                $value = $attr->nodeValue ?? '';

                // Any event handler.
                if (str_starts_with($name, 'on')) {
                    $element->removeAttribute($attr->nodeName);
                    continue;
                }
                // href / xlink:href / src pointing anywhere unsafe.
                if (in_array($name, ['href', 'xlink:href', 'src'], true)) {
                    if (self::isSafeReference($value) === false) {
                        $element->removeAttribute($attr->nodeName);
                    }
                    continue;
                }
                // Inline style with url()/expression to remote is dropped.
                if ($name === 'style' && (stripos($value, 'url(') !== false || stripos($value, 'expression') !== false)) {
                    $element->removeAttribute($attr->nodeName);
                }
            }
        }
    }

    private static function isSafeReference(string $value): bool
    {
        $v = strtolower(trim($value));

        // Local fragment refs and safe raster data URIs only.
        if ($v === '' || $v[0] === '#') {
            return true;
        }
        if (str_starts_with($v, 'data:image/png') || str_starts_with($v, 'data:image/jpeg') || str_starts_with($v, 'data:image/gif') || str_starts_with($v, 'data:image/webp')) {
            return true;
        }

        // Everything else (http, https, javascript, data:image/svg, protocol-
        // relative, absolute/relative file refs) is rejected to be safe.
        return false;
    }
}
