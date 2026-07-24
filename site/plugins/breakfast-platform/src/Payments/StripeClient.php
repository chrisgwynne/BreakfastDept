<?php

declare(strict_types=1);

namespace Breakfast\Platform\Payments;

use Breakfast\Platform\Mail\HttpClient;

/**
 * A thin, typed client for the Stripe REST API (form-encoded), built on the
 * platform's HttpClient so it shares the test transport seam — no live network
 * in automated tests. The secret key is passed as a bearer token and never
 * logged. Only the few endpoints the deposit workflow needs are wrapped:
 * Checkout Sessions (create/retrieve) and Refunds.
 */
final class StripeClient implements StripeGateway
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl = 'https://api.stripe.com/v1',
        private readonly HttpClient $http = new HttpClient(),
    ) {
    }

    /**
     * Create a Checkout Session. Amount is in minor units and computed by the
     * caller (never the client). Returns the decoded session.
     *
     * @param array<string,mixed> $params flat form params (supports one level of nesting via bracket keys)
     * @return array<string,mixed>
     */
    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->post('/checkout/sessions', $params, $idempotencyKey);
    }

    /** @return array<string,mixed> */
    public function retrieveCheckoutSession(string $id): array
    {
        return $this->get('/checkout/sessions/' . rawurlencode($id));
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function createRefund(array $params, string $idempotencyKey): array
    {
        return $this->post('/refunds', $params, $idempotencyKey);
    }

    /** @return array<string,mixed> */
    public function retrieveAccount(): array
    {
        return $this->get('/account');
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function post(string $path, array $params, string $idempotencyKey): array
    {
        $headers = $this->headers();
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $response = $this->http->request('POST', $this->baseUrl . $path, $headers, $this->formEncode($params));

        return $this->decode($response->status, $response->body, $response->error);
    }

    /** @return array<string,mixed> */
    private function get(string $path): array
    {
        $response = $this->http->request('GET', $this->baseUrl . $path, $this->headers());

        return $this->decode($response->status, $response->body, $response->error);
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Stripe-Version' => '2024-06-20',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(int $status, string $body, ?string $error): array
    {
        if ($status === 0) {
            throw new PaymentException(502, 'Could not reach the payment provider.');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new PaymentException(502, 'The payment provider returned an unexpected response.');
        }
        if ($status >= 400) {
            $message = (string) ($data['error']['message'] ?? 'The payment provider rejected the request.');
            // Never surface a raw key; Stripe messages are safe but keep it short.
            throw new PaymentException($status >= 500 ? 502 : 422, $message);
        }

        return $data;
    }

    /**
     * Form-encode nested params in Stripe's bracket style
     * (e.g. line_items[0][price_data][currency]).
     *
     * @param array<string,mixed> $params
     */
    private function formEncode(array $params): string
    {
        $pairs = [];
        $walk = function (string $prefix, mixed $value) use (&$walk, &$pairs): void {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $key = $prefix === '' ? (string) $k : $prefix . '[' . $k . ']';
                    $walk($key, $v);
                }
            } else {
                $pairs[] = rawurlencode($prefix) . '=' . rawurlencode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        };
        $walk('', $params);

        return implode('&', $pairs);
    }
}
