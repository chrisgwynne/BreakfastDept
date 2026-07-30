<?php

use Breakfast\Platform\Forms\FormDefinition;
use Breakfast\Platform\Forms\FormProcessor;

/**
 * Website-review controller — a lead path for businesses with an existing weak
 * site. Reuses the full secure form pipeline (CSRF, honeypot, timing, rate
 * limit, dedup, persist-before-side-effects) and creates a normal CRM lead; the
 * enquiry is flagged by its form type (website-review). All copy is editable.
 */
return function ($kirby, $site, $page) {
    $result = null;
    $old    = [];

    if ($kirby->request()->is('POST')) {
        $request = $kirby->request();
        $data    = [
            'name'     => (string) $request->get('name', ''),
            'email'    => (string) $request->get('email', ''),
            'company'  => (string) $request->get('company', ''),
            'website'  => (string) $request->get('website', ''),
            'location' => (string) $request->get('location', ''),
            'issues'   => (string) $request->get('issues', ''),
            'phone'    => (string) $request->get('phone', ''),
            'consent'  => $request->get('consent') ? '1' : '',
        ];

        $messages = [
            'name.required'      => 'Please tell me your name.',
            'email.required'     => 'I need an email to reply to.',
            'email.email'        => 'That email address doesn’t look right.',
            'website.url'        => 'Please include the full web address, starting with https://',
            'issues.required'    => 'Tell me what feels wrong with the current site.',
            'issues.min'         => 'A little more detail would help.',
            'general'            => (string) $page->form_error_message()->or('Something went wrong. Please try again.'),
            'general.rate_limit' => 'You’ve sent this a few times already — please give it a moment.',
        ];

        $context = [
            'csrf_valid'   => csrf($request->get('csrf')) === true,
            'honeypot'     => (string) $request->get('website_url', ''),
            'rendered_at'  => (int) $request->get('rendered_at', 0),
            'ip'           => $kirby->visitor()->ip(),
            'referrer'     => $kirby->request()->header('Referer'),
            'source_page'  => $page->url(),
            'landing_page' => (string) $request->get('landing_page', ''),
            'utm'          => array_filter([
                'utm_source'   => $request->get('utm_source'),
                'utm_medium'   => $request->get('utm_medium'),
                'utm_campaign' => $request->get('utm_campaign'),
            ]),
            'messages'     => $messages,
        ];

        $result = (new FormProcessor(breakfast()))->process(FormDefinition::websiteReview(), $data, $context);

        if ($result->success) {
            $thankYou = page('thank-you');
            go(($thankYou ? $thankYou->url() : $site->url()) . '?ref=' . urlencode($result->reference) . '&form=website-review');
        }

        $old = $result->old;
    }

    return ['result' => $result, 'old' => $old];
};
