<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

use RuntimeException;

/**
 * Brevo transactional email provider (typed HTTP client against the documented
 * REST API — POST /v3/smtp/email). A hand-rolled client was chosen over the
 * official SDK for testability and to avoid heavy transitive dependencies; the
 * decision is recorded in docs/architecture.md.
 *
 * Never logs the API key. Maps HTTP outcomes onto the MailResult model so the
 * queue can distinguish retryable (429/5xx/timeout) from permanent (4xx client
 * errors, invalid recipients) failures, and the genuinely-unknown case.
 */
final class BrevoApiMailProvider implements MailProvider
{
    /**
     * @param array{
     *     apiKey:string, baseUrl?:string,
     *     trackOpens?:bool, trackClicks?:bool
     * } $config
     */
    public function __construct(
        private readonly array $config,
        private readonly HttpClient $http = new HttpClient(),
        private readonly AttachmentResolver $attachments = new NullAttachmentResolver(),
    ) {
        if (($config['apiKey'] ?? '') === '') {
            throw new RuntimeException('Brevo API key is not configured');
        }
    }

    public function name(): string
    {
        return 'brevo';
    }

    public function send(MailMessage $message): MailResult
    {
        $payload  = $this->buildPayload($message);
        $response = $this->http->request(
            'POST',
            $this->baseUrl() . '/smtp/email',
            $this->headers(),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
        );

        // Transport-level failure (timeout, DNS): unknown outcome — the request
        // may or may not have reached Brevo, so treat as retryable-with-care.
        if ($response->status === 0) {
            return MailResult::unknown('transport_error: ' . ($response->error ?? 'unknown'));
        }

        if ($response->ok()) {
            $body      = $response->json();
            $messageId = isset($body['messageId']) ? (string) $body['messageId'] : null;

            return MailResult::accepted($messageId, $response->status);
        }

        // 429 (rate limit) and 5xx are retryable; 4xx are permanent.
        if ($response->status === 429 || $response->status >= 500) {
            return MailResult::temporary($this->safeError($response), $response->status);
        }

        return MailResult::permanent($this->safeError($response), $response->status);
    }

    public function healthCheck(): array
    {
        // GET /account authenticates without sending. 2xx = key valid.
        $response = $this->http->request('GET', $this->baseUrl() . '/account', $this->headers());

        if ($response->status === 0) {
            return ['ok' => false, 'detail' => 'connection failed'];
        }
        if ($response->ok()) {
            return ['ok' => true, 'detail' => 'authenticated'];
        }

        return ['ok' => false, 'detail' => 'HTTP ' . $response->status];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPayload(MailMessage $message): array
    {
        $payload = [
            'sender'  => $this->addr($message->sender),
            'to'      => array_map([$this, 'addr'], $message->to),
            'subject' => $message->subject,
        ];

        if ($message->cc !== []) {
            $payload['cc'] = array_map([$this, 'addr'], $message->cc);
        }
        if ($message->bcc !== []) {
            $payload['bcc'] = array_map([$this, 'addr'], $message->bcc);
        }
        if ($message->replyTo !== null) {
            $payload['replyTo'] = $this->addr($message->replyTo);
        }

        if ($message->usesTemplate()) {
            $payload['templateId'] = (int) $message->templateId;
            if ($message->params !== []) {
                $payload['params'] = $message->params;
            }
            // Brevo templates carry their own subject; ours is a fallback.
        } else {
            if ($message->html !== null) {
                $payload['htmlContent'] = $message->html;
            }
            if ($message->text !== null) {
                $payload['textContent'] = $message->text;
            }
        }

        if ($message->tags !== []) {
            $payload['tags'] = $message->tags;
        }
        if ($message->headers !== []) {
            $payload['headers'] = $message->headers;
        }

        // Idempotency + correlation without leaking PII.
        $payload['headers']['X-Breakfast-Message'] = $message->uuid;

        // Resolve attachment REFERENCES to bytes and base64-encode them HERE, at
        // send time — the queue only ever held the reference, never the blob.
        $attachments = [];
        foreach ($message->attachments as $ref) {
            $file = $this->attachments->resolve($ref);
            if ($file !== null) {
                $attachments[] = ['name' => $file->filename, 'content' => $file->base64()];
            }
        }
        if ($attachments !== []) {
            $payload['attachment'] = $attachments;
        }

        return $payload;
    }

    /** @return array{email:string,name?:string} */
    private function addr(EmailAddress $address): array
    {
        $out = ['email' => $address->email];
        if ($address->name !== null) {
            $out['name'] = $address->name;
        }

        return $out;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'accept'       => 'application/json',
            'content-type' => 'application/json',
            'api-key'      => (string) $this->config['apiKey'],
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['baseUrl'] ?? 'https://api.brevo.com/v3'), '/');
    }

    /** Extract a safe, key-free error string from a Brevo error body. */
    private function safeError(HttpResponse $response): string
    {
        $body = $response->json();
        $code = isset($body['code']) ? (string) $body['code'] : 'error';
        $msg  = isset($body['message']) ? (string) $body['message'] : '';

        return trim(mb_substr($code . ': ' . $msg, 0, 300));
    }
}
