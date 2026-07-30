<?php

use Breakfast\Platform\Forms\FormDefinition;
use Breakfast\Platform\Forms\FormProcessor;

/**
 * Start-a-project enquiry controller. Same protections as contact, plus the
 * extra qualifying fields. Optional file upload is handled only when enabled.
 */
return function ($kirby, $site, $page) {
    $result = null;
    $old    = [];

    if ($kirby->request()->is('POST')) {
        $request = $kirby->request();
        $data = [];
        foreach (['name', 'email', 'phone', 'company', 'website', 'business', 'help', 'goals', 'budget', 'launch_date', 'heard_about', 'message'] as $field) {
            $data[$field] = (string) $request->get($field, '');
        }
        // services can be multiple checkboxes
        $services = $request->get('services');
        $data['services'] = is_array($services) ? implode(', ', array_map('strval', $services)) : (string) $services;
        $data['consent']  = $request->get('consent') ? '1' : '';

        $messages = [
            'name.required'  => 'Please tell us your name.',
            'email.required' => 'We need an email to reply to.',
            'email.email'    => 'That email address doesn’t look right.',
            'website.url'    => 'That web address doesn’t look right.',
            'general'        => (string) $page->form_error_message()->or('Something went wrong. Please try again.'),
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

        $result = (new FormProcessor(breakfast()))->process(FormDefinition::startProject(), $data, $context);

        if ($result->success) {
            $thankYou = page('thank-you');
            go(($thankYou ? $thankYou->url() : $site->url()) . '?ref=' . urlencode($result->reference) . '&form=start-project');
        }

        $old = $result->old;
    }

    return ['result' => $result, 'old' => $old];
};
