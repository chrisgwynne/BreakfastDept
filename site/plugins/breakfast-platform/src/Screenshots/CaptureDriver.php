<?php

declare(strict_types=1);

namespace Breakfast\Platform\Screenshots;

/**
 * Captures a validated request into image bytes. Implementations MUST:
 *   - re-validate every redirect hop against the allow-list + SSRF guard,
 *   - enforce a timeout and a maximum response size,
 *   - never execute against authenticated / private pages.
 *
 * The URL passed in has already cleared {@see \Breakfast\Platform\Support\SafeUrl}
 * for its FIRST hop; redirect safety is the driver's responsibility.
 */
interface CaptureDriver
{
    /**
     * @param list<string> $allowedHosts hosts every redirect hop must stay within
     * @throws \RuntimeException on timeout, oversize, unsafe redirect or capture failure
     */
    public function capture(CaptureRequest $request, array $allowedHosts): CaptureResult;
}
