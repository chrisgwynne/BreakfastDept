<?php

use Breakfast\Platform\Forms\FormDefinition;
use Breakfast\Platform\Forms\FormProcessor;

/**
 * Contact form controller. Handles the POST with full server-side protection
 * and Post/Redirect/Get. All copy comes from the page's editable form fields.
 */
return function ($kirby, $site, $page) {
    $result = null;
    $old    = [];

    if ($kirby->request()->is('POST')) {
        $request = $kirby->request();
        $data    = [
            'name'    => (string) $request->get('name', ''),
            'email'   => (string) $request->get('email', ''),
            'company' => (string) $request->get('company', ''),
            'subject' => (string) $request->get('subject', ''),
            'message' => (string) $request->get('message', ''),
            'consent' => $request->get('consent') ? '1' : '',
        ];

        $messages = [
            'name.required'    => 'Please tell us your name.',
            'email.required'   => 'We need an email to reply to.',
            'email.email'      => 'That email address doesn’t look right.',
            'message.required' => 'Please add a short message.',
            'message.min'      => 'A little more detail would help.',
            'general'          => (string) $page->form_error_message()->or('Something went wrong. Please try again.'),
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

        $result = (new FormProcessor(breakfast()))->process(FormDefinition::contact(), $data, $context);

        if ($result->success) {
            $thankYou = page('thank-you');
            go(($thankYou ? $thankYou->url() : $site->url()) . '?ref=' . urlencode($result->reference));
        }

        $old = $result->old;
    }

    return ['result' => $result, 'old' => $old];
};
