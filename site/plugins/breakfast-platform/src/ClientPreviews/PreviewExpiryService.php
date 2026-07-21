<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Support\Clock;

/**
 * Transitions active previews whose expiry has passed into the EXPIRED state, so
 * the responder serves a neutral "expired" page. Idempotent and safe to run on a
 * schedule (queue housekeeping or cron).
 */
final class PreviewExpiryService
{
    public function __construct(private readonly PreviewRepository $previews)
    {
    }

    /**
     * @return array{expired:int,uuids:list<string>}
     */
    public function expireDue(): array
    {
        $now  = Clock::nowIso();
        $due  = $this->previews->dueForExpiry($now);
        $done = [];

        foreach ($due as $preview) {
            $uuid = (string) $preview['uuid'];
            $this->previews->update($uuid, [
                'status'      => PreviewStatus::EXPIRED,
                'disabled_at' => $now,
            ]);
            $done[] = $uuid;
        }

        return ['expired' => count($done), 'uuids' => $done];
    }
}
