<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Security\Hash;
use Breakfast\Platform\Support\FileRepository;
use Breakfast\Platform\Support\Uuid;

/**
 * Privacy-conscious access + form persistence for Client Previews, stored as
 * flat files.
 *
 * Access events store ONLY keyed hashes (never raw IPs or user agents), a coarse
 * referer host and a sanitised path. Retention is enforced by the cleanup
 * service. Form submissions are always flagged as test leads and are bulk
 * deletable per preview.
 */
final class PreviewActivityRepository extends FileRepository
{
    private const SUBMISSIONS = 'preview_form_submissions';

    protected function collection(): string
    {
        return 'preview_access_events';
    }

    /**
     * Record one access event. `$ip`/`$ua` are hashed with the server key here
     * and never persisted in the clear.
     */
    public function recordAccess(
        string $previewUuid,
        ?string $versionUuid,
        string $eventType,
        ?string $path,
        int $statusCode,
        ?string $ip,
        ?string $ua,
        ?string $refererHost,
        ?string $correlatorSeed = null
    ): void {
        $this->persist([
            'uuid'         => Uuid::v4(),
            'preview_uuid' => $previewUuid,
            'version_uuid' => $versionUuid,
            'event_type'   => $eventType,
            'path'         => $path !== null ? mb_substr($path, 0, 512) : null,
            'status_code'  => $statusCode,
            'ip_hash'      => $ip !== null && $ip !== '' ? Hash::ip($ip) : null,
            'ua_hash'      => $ua !== null && $ua !== '' ? substr(Hash::correlator($ua), 0, 32) : null,
            'referer_host' => $refererHost !== null ? mb_substr($refererHost, 0, 255) : null,
            'correlator'   => $correlatorSeed !== null && $correlatorSeed !== '' ? Hash::correlator($correlatorSeed) : null,
            'occurred_at'  => $this->now(),
        ]);
    }

    /**
     * Aggregate, privacy-preserving analytics for a preview. Returns view counts
     * and a coarse unique-visitor estimate (distinct keyed IP hashes) — never a
     * claim that a specific person viewed the page.
     *
     * @return array<string,int>
     */
    public function summary(string $previewUuid): array
    {
        $events = array_filter(
            $this->records(),
            static fn (array $r): bool => (string) ($r['preview_uuid'] ?? '') === $previewUuid
        );

        $views    = 0;
        $failures = 0;
        $ipHashes = [];
        foreach ($events as $e) {
            $type = (string) ($e['event_type'] ?? '');
            if ($type === 'view') {
                $views++;
                if (($e['ip_hash'] ?? null) !== null) {
                    $ipHashes[(string) $e['ip_hash']] = true;
                }
            } elseif ($type === 'password_failure') {
                $failures++;
            }
        }

        return [
            'views'             => $views,
            'approx_visitors'   => count($ipHashes),
            'password_failures' => $failures,
        ];
    }

    public function pruneAccessBefore(string $iso): int
    {
        $pruned = 0;
        foreach ($this->records() as $row) {
            if ((string) ($row['occurred_at'] ?? '') < $iso) {
                $this->store->delete($this->collection(), (string) ($row['uuid'] ?? ''));
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * Store a preview demo/lead-capture submission. Always a test lead; never
     * sent to the client.
     *
     * @return array<string,mixed>
     */
    public function recordSubmission(string $previewUuid, string $name, string $email, string $message, ?string $ip): array
    {
        return $this->store->put(self::SUBMISSIONS, [
            'uuid'         => Uuid::v4(),
            'preview_uuid' => $previewUuid,
            'name'         => mb_substr($name, 0, 200),
            'email'        => mb_substr($email, 0, 255),
            'message'      => mb_substr($message, 0, 2000),
            'is_test_lead' => 1,
            'ip_hash'      => $ip !== null && $ip !== '' ? Hash::ip($ip) : null,
            'created_at'   => $this->now(),
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function submissions(string $previewUuid): array
    {
        $rows = array_values(array_filter(
            $this->store->all(self::SUBMISSIONS),
            static fn (array $r): bool => (string) ($r['preview_uuid'] ?? '') === $previewUuid
        ));
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $rows;
    }

    /** Remove all access events for a preview (used when the preview is deleted). */
    public function deleteAccessFor(string $previewUuid): void
    {
        foreach ($this->records() as $row) {
            if ((string) ($row['preview_uuid'] ?? '') === $previewUuid) {
                $this->store->delete($this->collection(), (string) ($row['uuid'] ?? ''));
            }
        }
    }

    public function deleteSubmissions(string $previewUuid): void
    {
        foreach ($this->store->all(self::SUBMISSIONS) as $row) {
            if ((string) ($row['preview_uuid'] ?? '') === $previewUuid) {
                $this->store->delete(self::SUBMISSIONS, (string) ($row['uuid'] ?? ''));
            }
        }
    }
}
