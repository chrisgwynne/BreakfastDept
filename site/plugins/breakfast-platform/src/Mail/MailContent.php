<?php

declare(strict_types=1);

namespace Breakfast\Platform\Mail;

/**
 * Application-rendered email bodies (HTML + plain text) used as a fallback when
 * no provider template id is configured. ALL interpolated values are escaped;
 * internal-notification emails clearly separate trusted interface text from the
 * visitor's submitted content.
 */
final class MailContent
{
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string,scalar> $fields  the visitor's submitted fields (untrusted)
     * @return array{html:string,text:string}
     */
    public static function enquiryInternal(string $reference, string $formType, array $fields): array
    {
        $rows = '';
        $textRows = '';
        foreach ($fields as $label => $value) {
            $l = self::e(ucfirst(str_replace('_', ' ', (string) $label)));
            $v = self::e((string) $value);
            $rows .= "<tr><td style=\"padding:4px 8px;font-weight:bold;vertical-align:top\">{$l}</td><td style=\"padding:4px 8px\">{$v}</td></tr>";
            $textRows .= $label . ': ' . (string) $value . "\n";
        }

        $ref = self::e($reference);
        $ft  = self::e($formType);

        $html = "<h2>New {$ft} enquiry</h2>"
            . "<p style=\"color:#555\">Reference <strong>{$ref}</strong>. The details below were <em>submitted by the visitor</em> and are not trusted interface text.</p>"
            . "<table style=\"border-collapse:collapse\">{$rows}</table>";

        $text = "New {$formType} enquiry — {$reference}\n"
            . "(submitted by the visitor)\n\n" . $textRows;

        return ['html' => $html, 'text' => $text];
    }

    /**
     * @return array{html:string,text:string}
     */
    public static function enquiryAck(string $contactName, string $studioName): array
    {
        $name   = self::e($contactName !== '' ? $contactName : 'there');
        $studio = self::e($studioName);

        $html = "<p>Hi {$name},</p>"
            . '<p>Thanks for getting in touch — your message has reached us and a real person will read it and reply. '
            . "We only take on a small number of projects at a time, so we try to answer thoughtfully rather than instantly.</p>"
            . "<p>— {$studio}</p>";

        $text = "Hi {$contactName},\n\n"
            . "Thanks for getting in touch — your message has reached us and a real person will read it and reply.\n\n"
            . "— {$studioName}";

        return ['html' => $html, 'text' => $text];
    }

    /**
     * A general CRM reply body from a plain-text message an operator typed.
     *
     * @return array{html:string,text:string}
     */
    public static function crmReply(string $bodyText): array
    {
        $paras = array_filter(array_map('trim', preg_split('/\n{2,}/', $bodyText) ?: []));
        $html  = '';
        foreach ($paras as $p) {
            $html .= '<p>' . nl2br(self::e($p)) . '</p>';
        }

        return ['html' => $html !== '' ? $html : '<p></p>', 'text' => $bodyText];
    }
}
