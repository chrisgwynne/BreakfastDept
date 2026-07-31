<?php

declare(strict_types=1);

namespace Breakfast\Platform\Screenshots;

/**
 * The human privacy-review checklist for a screenshot before it may be
 * published. Automation cannot clear these — a person confirms them and the
 * confirmation is recorded via {@see ScreenshotService::markPrivacyReviewed()}.
 */
final class PrivacyChecklist
{
    /** @var array<string,string> id => human prompt */
    public const ITEMS = [
        'personal_data'   => 'No personal data (names, addresses, order details) is visible.',
        'contact_details' => 'No email addresses or phone numbers are exposed.',
        'accounts'        => 'No account names or logged-in user is shown.',
        'browser_chrome'  => 'No browser tabs, bookmarks, extensions or address bar leak anything.',
        'admin'           => 'No admin bar, CMS controls or dashboards are visible.',
        'test_data'       => 'No test/placeholder data is on screen.',
        'tracking'        => 'No analytics IDs, tracking parameters or preview tokens in view.',
        'internal'        => 'No local URLs, file paths or internal hostnames are shown.',
        'messages'        => 'No private messages or credentials are visible.',
    ];

    /** @return list<string> the checklist ids */
    public static function ids(): array
    {
        return array_keys(self::ITEMS);
    }
}
