<?php

declare(strict_types=1);

namespace Breakfast\Platform\Screenshots;

/**
 * The production capture driver. It shells out to an external, trusted headless
 * command (configured via SCREENSHOT_CMD) that runs on the host's infrastructure
 * — a sandboxed browser is the right place for untrusted page rendering, not the
 * PHP process. This keeps live capture OFF the web request and OFF this app's
 * trust boundary.
 *
 * Contract with the command:
 *   - a JSON job is written to its stdin: {url,width,height,full_page,dark,
 *     delay_ms,dismiss,allowed_hosts},
 *   - it MUST re-validate every redirect hop against allowed_hosts + block
 *     private addresses, execute no authenticated flow, and honour the viewport,
 *   - it writes the raw PNG/JPEG bytes to stdout and exits 0.
 *
 * This driver enforces a wall-clock timeout and a hard output-size cap, and
 * verifies the returned bytes are a real image before accepting them.
 */
final class CommandCaptureDriver implements CaptureDriver
{
    public function __construct(
        private readonly string $command,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxBytes = 8 * 1024 * 1024,
    ) {
    }

    public function capture(CaptureRequest $request, array $allowedHosts): CaptureResult
    {
        if (trim($this->command) === '') {
            throw new \RuntimeException('capture_unavailable: no SCREENSHOT_CMD configured');
        }
        [$w, $h] = $request->dimensions();
        $job = json_encode([
            'url'           => $request->url,
            'width'         => $w,
            'height'        => $h,
            'full_page'     => $request->fullPage,
            'max_height'    => CaptureRequest::MAX_FULLPAGE_HEIGHT,
            'dark'          => $request->dark,
            'delay_ms'      => $request->delayMsBounded(),
            'dismiss'       => array_values($request->dismissSelectors),
            'allowed_hosts' => array_values($allowedHosts),
            'engine'        => $request->engineKey(),
        ], JSON_UNESCAPED_SLASHES) ?: '{}';

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($this->command, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('capture_failed: could not start capture command');
        }

        fwrite($pipes[0], $job);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $deadline = microtime(true) + $this->timeoutSeconds;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                proc_terminate($proc, 9);
                throw new \RuntimeException('capture_timeout');
            }
            $sec = (int) $remaining;
            $usec = (int) (($remaining - $sec) * 1_000_000);
            if (@stream_select($read, $write, $except, $sec, $usec) === false) {
                break;
            }
            $done = true;
            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                if ($chunk !== '' && $chunk !== false) {
                    if ($stream === $pipes[1]) {
                        $out .= $chunk;
                        if (strlen($out) > $this->maxBytes) {
                            proc_terminate($proc, 9);
                            throw new \RuntimeException('capture_too_large');
                        }
                    } else {
                        $err .= $chunk;
                    }
                }
                if (!feof($stream)) {
                    $done = false;
                }
            }
            if ($done && feof($pipes[1])) {
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new \RuntimeException('capture_failed: exit ' . $code . ' ' . substr(trim($err), 0, 200));
        }

        $info = @getimagesizefromstring($out);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw new \RuntimeException('capture_invalid_image');
        }

        return new CaptureResult($out, (string) $info['mime'], (int) $info[0], (int) $info[1], $request->engineKey(), false);
    }
}
