<?php

declare(strict_types=1);

namespace Breakfast\Platform\ClientPreviews;

use Breakfast\Platform\Crm\Repository;
use Breakfast\Platform\Security\Hash;
use Breakfast\Platform\Support\Uuid;

/**
 * Privacy-conscious access + form persistence for Client Previews.
 *
 * Access events store ONLY keyed hashes (never raw IPs or user agents), a coarse
 * referer host and a sanitised path. Retention is enforced by the cleanup
 * service. Form submissions are always flagged as test leads and are bulk
 * deletable per preview.
 */
final class PreviewActivityRepository extends Repository
{
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
        $this->db->run(
            'INSERT INTO preview_access_events
                (preview_uuid, version_uuid, event_type, path, status_code,
                 ip_hash, ua_hash, referer_host, correlator, occurred_at)
             VALUES
                (:preview, :version, :type, :path, :code,
                 :ip, :ua, :ref, :corr, :now)',
            [
                'preview' => $previewUuid,
                'version' => $versionUuid,
                'type'    => $eventType,
                'path'    => $path !== null ? mb_substr($path, 0, 512) : null,
                'code'    => $statusCode,
                'ip'      => $ip !== null && $ip !== '' ? Hash::ip($ip) : null,
                'ua'      => $ua !== null && $ua !== '' ? substr(Hash::correlator($ua), 0, 32) : null,
                'ref'     => $refererHost !== null ? mb_substr($refererHost, 0, 255) : null,
                'corr'    => $correlatorSeed !== null && $correlatorSeed !== '' ? Hash::correlator($correlatorSeed) : null,
                'now'     => $this->now(),
            ]
        );
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
        $views = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM preview_access_events WHERE preview_uuid = :p AND event_type = 'view'",
            ['p' => $previewUuid]
        );
        $uniques = (int) $this->db->scalar(
            "SELECT COUNT(DISTINCT ip_hash) FROM preview_access_events
             WHERE preview_uuid = :p AND event_type = 'view' AND ip_hash IS NOT NULL",
            ['p' => $previewUuid]
        );
        $passwordFailures = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM preview_access_events WHERE preview_uuid = :p AND event_type = 'password_failure'",
            ['p' => $previewUuid]
        );

        return [
            'views'             => $views,
            'approx_visitors'   => $uniques,
            'password_failures' => $passwordFailures,
        ];
    }

    public function pruneAccessBefore(string $iso): int
    {
        $stmt = $this->db->run('DELETE FROM preview_access_events WHERE occurred_at < :cut', ['cut' => $iso]);

        return $stmt->rowCount();
    }

    /**
     * Store a preview demo/lead-capture submission. Always a test lead; never
     * sent to the client.
     *
     * @return array<string,mixed>
     */
    public function recordSubmission(string $previewUuid, string $name, string $email, string $message, ?string $ip): array
    {
        $uuid = Uuid::v4();
        $this->db->run(
            'INSERT INTO preview_form_submissions
                (uuid, preview_uuid, name, email, message, is_test_lead, ip_hash, created_at)
             VALUES (:uuid, :preview, :name, :email, :message, 1, :ip, :now)',
            [
                'uuid'    => $uuid,
                'preview' => $previewUuid,
                'name'    => mb_substr($name, 0, 200),
                'email'   => mb_substr($email, 0, 255),
                'message' => mb_substr($message, 0, 2000),
                'ip'      => $ip !== null && $ip !== '' ? Hash::ip($ip) : null,
                'now'     => $this->now(),
            ]
        );

        /** @var array<string,mixed> $row */
        $row = $this->db->one('SELECT * FROM preview_form_submissions WHERE uuid = :u', ['u' => $uuid]) ?? [];

        return $row;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function submissions(string $previewUuid): array
    {
        return $this->db->all(
            'SELECT * FROM preview_form_submissions WHERE preview_uuid = :p ORDER BY created_at DESC',
            ['p' => $previewUuid]
        );
    }

    public function deleteSubmissions(string $previewUuid): void
    {
        $this->db->run('DELETE FROM preview_form_submissions WHERE preview_uuid = :p', ['p' => $previewUuid]);
    }
}
