<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Mail\AttachmentResolver;
use Breakfast\Platform\Mail\BrevoApiMailProvider;
use Breakfast\Platform\Mail\EmailAddress;
use Breakfast\Platform\Mail\HttpClient;
use Breakfast\Platform\Mail\HttpResponse;
use Breakfast\Platform\Mail\MailAttachment;
use Breakfast\Platform\Mail\MailMessage;
use Breakfast\Platform\Mail\MailOutcome;
use PHPUnit\Framework\TestCase;

final class BrevoProviderTest extends TestCase
{
    /** @var array{method:string,url:string,headers:array<string,string>,body:?string}|null */
    private ?array $captured = null;

    protected function tearDown(): void
    {
        HttpClient::useTransport(null);
    }

    private function provider(HttpResponse $response): BrevoApiMailProvider
    {
        HttpClient::useTransport(function (string $m, string $u, array $h, ?string $b) use ($response): HttpResponse {
            $this->captured = ['method' => $m, 'url' => $u, 'headers' => $h, 'body' => $b];

            return $response;
        });

        return new BrevoApiMailProvider(['apiKey' => 'secret-key', 'baseUrl' => 'https://api.brevo.com/v3'], new HttpClient());
    }

    private function message(): MailMessage
    {
        return new MailMessage(
            messageType: 'crm_reply',
            sender: EmailAddress::create('studio@example.com', 'Breakfast'),
            to: [EmailAddress::create('client@example.co.uk', 'Client')],
            subject: 'Hi',
            html: '<p>Hello</p>',
            text: 'Hello',
            replyTo: EmailAddress::create('reply@example.com'),
        );
    }

    public function testRequestConstruction(): void
    {
        $p = $this->provider(new HttpResponse(201, '{"messageId":"<abc@brevo>"}'));
        $p->send($this->message());

        $this->assertStringEndsWith('/smtp/email', $this->captured['url']);
        $this->assertSame('secret-key', $this->captured['headers']['api-key']);
        $body = json_decode((string) $this->captured['body'], true);
        $this->assertSame('studio@example.com', $body['sender']['email']);
        $this->assertSame('client@example.co.uk', $body['to'][0]['email']);
        $this->assertSame('reply@example.com', $body['replyTo']['email']);
        $this->assertArrayHasKey('X-Breakfast-Message', $body['headers']);
    }

    public function testAttachmentReferenceIsResolvedAndBase64EncodedIntoThePayload(): void
    {
        HttpClient::useTransport(function (string $m, string $u, array $h, ?string $b): HttpResponse {
            $this->captured = ['method' => $m, 'url' => $u, 'headers' => $h, 'body' => $b];

            return new HttpResponse(201, '{"messageId":"<x@brevo>"}');
        });

        // A resolver that turns the reference into a real (tiny) PDF at send time.
        $resolver = new class () implements AttachmentResolver {
            public function resolve(string $reference): ?MailAttachment
            {
                return $reference === 'invoice:abc'
                    ? new MailAttachment('BREAKFAST-INV-1.pdf', "%PDF-1.7\nfake", 'application/pdf')
                    : null;
            }
        };
        $provider = new BrevoApiMailProvider(['apiKey' => 'k', 'baseUrl' => 'https://api.brevo.com/v3'], new HttpClient(), $resolver);

        $message = new MailMessage(
            messageType: 'crm_reply',
            sender: EmailAddress::create('studio@example.com', 'Breakfast'),
            to: [EmailAddress::create('client@example.co.uk', 'Client')],
            subject: 'Invoice',
            html: '<p>See attached</p>',
            attachments: ['invoice:abc'],
        );
        $provider->send($message);

        $body = json_decode((string) $this->captured['body'], true);
        $this->assertArrayHasKey('attachment', $body, 'the PDF is attached at send time');
        $this->assertSame('BREAKFAST-INV-1.pdf', $body['attachment'][0]['name']);
        $this->assertSame(base64_encode("%PDF-1.7\nfake"), $body['attachment'][0]['content']);
    }

    public function testAcceptedResponseMapsMessageId(): void
    {
        $p = $this->provider(new HttpResponse(201, '{"messageId":"<abc@brevo>"}'));
        $r = $p->send($this->message());
        $this->assertSame(MailOutcome::Accepted, $r->outcome);
        $this->assertSame('<abc@brevo>', $r->providerMessageId);
    }

    public function testRateLimitIsRetryable(): void
    {
        $r = $this->provider(new HttpResponse(429, '{"code":"too_many_requests"}'))->send($this->message());
        $this->assertSame(MailOutcome::TemporaryFailure, $r->outcome);
        $this->assertTrue($r->isRetryable());
    }

    public function testServerErrorIsRetryable(): void
    {
        $r = $this->provider(new HttpResponse(503, '{"code":"server_error"}'))->send($this->message());
        $this->assertSame(MailOutcome::TemporaryFailure, $r->outcome);
    }

    public function testClientErrorIsPermanent(): void
    {
        $r = $this->provider(new HttpResponse(400, '{"code":"invalid_parameter","message":"bad recipient"}'))->send($this->message());
        $this->assertSame(MailOutcome::PermanentFailure, $r->outcome);
        $this->assertFalse($r->isRetryable());
    }

    public function testTransportErrorIsUnknown(): void
    {
        $r = $this->provider(new HttpResponse(0, '', 'timeout'))->send($this->message());
        $this->assertSame(MailOutcome::UnknownOutcome, $r->outcome);
        $this->assertTrue($r->isRetryable());
    }

    public function testTemplateSendUsesTemplateId(): void
    {
        $p = $this->provider(new HttpResponse(201, '{"messageId":"x"}'));
        $msg = new MailMessage(
            messageType: 'enquiry_ack',
            sender: EmailAddress::create('studio@example.com'),
            to: [EmailAddress::create('c@example.com')],
            subject: 'Hi',
            templateId: '7',
            params: ['contact_name' => 'Sam'],
        );
        $p->send($msg);
        $body = json_decode((string) $this->captured['body'], true);
        $this->assertSame(7, $body['templateId']);
        $this->assertSame('Sam', $body['params']['contact_name']);
        $this->assertArrayNotHasKey('htmlContent', $body);
    }

    public function testErrorStringNeverContainsApiKey(): void
    {
        $r = $this->provider(new HttpResponse(400, '{"code":"x","message":"y"}'))->send($this->message());
        $this->assertStringNotContainsString('secret-key', (string) $r->error);
    }
}
