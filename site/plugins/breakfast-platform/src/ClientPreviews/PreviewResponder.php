<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

/**
 * Turns a preview request into a response *description* (status, headers, body or
 * a file to stream, and an optional host-only cookie). The plugin route converts
 * this into a Kirby response — keeping this class free of framework/HTTP globals
 * so it can be unit tested.
 *
 * Every response carries the preview security header set: nosniff, no-referrer,
 * restrictive Permissions-Policy, COOP/CORP, and a CSP that blocks objects,
 * workers (service workers), cross-origin framing and non-self form submission.
 * The CSP deliberately ALLOWS inline scripts so ordinary static mock-ups run —
 * uploaded JavaScript executes client-side on this isolated origin by design,
 * confined by the separate origin (no admin/Kirby cookie is ever in scope) and
 * the header set rather than forbidden. X-Robots-Tag is noindex unless an admin
 * has explicitly made a public preview indexable. The only cookie it issues is a
 * per-preview, host-only password session.
 *
 * @phpstan-type PreviewResponse array{status:int,headers:array<string,string>,body:string,file?:string,cookie?:array{name:string,value:string,maxage:int,clear?:bool}}
 */
final class PreviewResponder
{
    public const UNLOCK_FIELD = 'preview_password';

    public function __construct(
        private readonly PreviewAccessService $access,
        private readonly PreviewPasswordService $passwords,
        private readonly PreviewRepository $previews,
        private readonly bool $isProduction,
    ) {
    }

    /**
     * The robots.txt served on the preview origin: disallow everything. Previews
     * are never meant to be crawled as a set; individual public ones rely on
     * per-response X-Robots-Tag.
     *
     * @return PreviewResponse
     */
    public function robots(): array
    {
        return [
            'status'  => 200,
            'headers' => ['Content-Type' => 'text/plain; charset=utf-8', 'X-Robots-Tag' => 'noindex'] + $this->securityHeaders(false),
            'body'    => "User-agent: *\nDisallow: /\n",
        ];
    }

    /**
     * Handle a GET request for a preview file.
     *
     * @param array{token?:?string,ip?:?string,ua?:?string,referer_host?:?string} $context
     * @return PreviewResponse
     */
    public function serve(string $slug, string $path, array $context): array
    {
        $decision = $this->access->resolve($slug, $path, $context);

        return match ($decision['type']) {
            'file'     => $this->fileResponse($decision),
            'password' => $this->passwordGate($slug, null, 401),
            'disabled' => $this->statusPage(403, 'This preview is unavailable', 'The link has been switched off. Please get back in touch with the studio.'),
            'expired'  => $this->statusPage(410, 'This preview has expired', 'This preview link is no longer available. Ask the studio for an up-to-date link.'),
            default    => $this->statusPage(404, 'Not found', 'There is nothing to see at this address.'),
        };
    }

    /**
     * Handle a POST unlock attempt for a password-protected preview.
     *
     * @return PreviewResponse
     */
    public function unlock(string $slug, string $password, ?string $ip): array
    {
        $preview = $this->previews->findBySlug($slug);
        if ($preview === null || (string) $preview['visibility'] !== PreviewStatus::VISIBILITY_PASSWORD) {
            return $this->statusPage(404, 'Not found', 'There is nothing to see at this address.');
        }

        $result = $this->passwords->attempt($preview, $password, $ip);
        if ($result['ok'] === false) {
            $status  = ($result['error'] ?? '') === 'rate_limited' ? 429 : 401;
            $message = $status === 429 ? 'Too many attempts. Please wait a little and try again.' : 'That password was not correct.';

            return $this->passwordGate($slug, $message, $status);
        }

        // Success: set the host-only session cookie and send the visitor to the
        // preview root.
        $response = $this->statusPage(303, 'Unlocked', 'One moment…');
        $response['headers']['Location'] = './';
        $response['cookie'] = [
            'name'   => self::cookieName($slug),
            'value'  => (string) ($result['token'] ?? ''),
            'maxage' => 3600,
        ];

        return $response;
    }

    public static function cookieName(string $slug): string
    {
        return 'bfpv_' . substr(hash('sha256', $slug), 0, 16);
    }

    /**
     * @param array{type:string,status:int,file?:string,mime?:string,indexable?:bool,preview_uuid?:string,version_uuid?:string,is_entry?:bool} $decision
     * @return PreviewResponse
     */
    private function fileResponse(array $decision): array
    {
        $headers = ['Content-Type' => $decision['mime'] ?? 'application/octet-stream']
            + $this->securityHeaders((bool) ($decision['indexable'] ?? false));

        return [
            'status'  => 200,
            'headers' => $headers,
            'body'    => '',
            'file'    => $decision['file'] ?? '',
        ];
    }

    /**
     * @return PreviewResponse
     */
    private function passwordGate(string $slug, ?string $error, int $status): array
    {
        $safeSlug = htmlspecialchars($slug, ENT_QUOTES);
        $notice   = $error !== null ? '<p class="err">' . htmlspecialchars($error, ENT_QUOTES) . '</p>' : '';
        $body = <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Protected preview</title>
<style>
  body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5efe3;color:#1c293c;display:flex;min-height:100vh;align-items:center;justify-content:center}
  .card{background:#fff;border:2px solid #1c293c;box-shadow:6px 6px 0 #1c293c;border-radius:10px;padding:2rem;max-width:22rem;width:calc(100% - 2rem)}
  h1{font-size:1.25rem;margin:0 0 .25rem}
  p{color:#55607a;font-size:.9rem;margin:.25rem 0 1rem}
  .err{color:#b42318;font-weight:600}
  label{display:block;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem}
  input{width:100%;padding:.6rem .7rem;border:2px solid #1c293c;border-radius:6px;font-size:1rem;box-sizing:border-box}
  button{margin-top:1rem;width:100%;padding:.7rem;border:2px solid #1c293c;background:#fdc800;font-weight:700;border-radius:6px;cursor:pointer;font-size:1rem}
</style></head>
<body><form class="card" method="post" action="./">
  <h1>This preview is protected</h1>
  <p>Enter the password the studio shared with you.</p>
  {$notice}
  <label for="pw">Password</label>
  <input id="pw" name="{$this->fieldName()}" type="password" autocomplete="off" autofocus>
  <input type="hidden" name="__preview" value="{$safeSlug}">
  <button type="submit">View preview</button>
</form></body></html>
HTML;

        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'text/html; charset=utf-8'] + $this->securityHeaders(false),
            'body'    => $body,
        ];
    }

    /**
     * @return PreviewResponse
     */
    private function statusPage(int $status, string $title, string $message): array
    {
        $t = htmlspecialchars($title, ENT_QUOTES);
        $m = htmlspecialchars($message, ENT_QUOTES);
        $body = <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$t}</title>
<style>
  body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5efe3;color:#1c293c;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center}
  .box{max-width:24rem;padding:2rem}
  h1{font-size:1.4rem;margin:0 0 .5rem}
  p{color:#55607a}
</style></head>
<body><div class="box"><h1>{$t}</h1><p>{$m}</p></div></body></html>
HTML;

        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'text/html; charset=utf-8'] + $this->securityHeaders(false),
            'body'    => $body,
        ];
    }

    private function fieldName(): string
    {
        return self::UNLOCK_FIELD;
    }

    /**
     * @return array<string,string>
     */
    private function securityHeaders(bool $indexable): array
    {
        return [
            'X-Content-Type-Options'       => 'nosniff',
            'Referrer-Policy'              => 'no-referrer',
            'Permissions-Policy'           => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy'   => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Frame-Options'              => 'SAMEORIGIN',
            'X-Robots-Tag'                 => $indexable ? 'all' : 'noindex, nofollow, noarchive, nosnippet',
            'Content-Security-Policy'      => $this->csp(),
            'Cache-Control'                => $this->isProduction ? 'private, no-cache, max-age=0, must-revalidate' : 'no-store',
        ];
    }

    private function csp(): string
    {
        // Static-mock friendly (inline styles/scripts allowed) but hardened:
        // object-src none, worker-src none (blocks service workers), framing and
        // form submission confined to the preview origin, no cross-origin
        // connections, and base-uri locked down.
        return implode('; ', [
            "default-src 'self' data: blob:",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "media-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "frame-src 'self'",
            "worker-src 'none'",
            "manifest-src 'self'",
        ]);
    }
}
