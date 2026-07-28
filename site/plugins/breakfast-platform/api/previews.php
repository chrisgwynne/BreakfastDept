<?php

declare(strict_types=1);

use Breakfast\Platform\ClientPreviews\PreviewSlug;
use Breakfast\Platform\ClientPreviews\PreviewStatus;
use Breakfast\Platform\Security\PreviewGate;
use Kirby\Exception\PermissionException;

/**
 * Panel API routes for Client Previews. Every route runs inside the authenticated
 * Panel session AND re-checks the specific PreviewGate action server-side — the
 * admin UI hiding a control is never the access control. Blocking validation
 * errors cannot be overridden; publishing/slug/password/delete each require their
 * own grant.
 */

$gate = static function (string $action): void {
    if (PreviewGate::can(kirby()->user(), $action) === false) {
        throw new PermissionException('You do not have permission to ' . $action . ' previews.');
    }
};

$actor = static fn (): ?string => kirby()->user()?->email();

/**
 * Strip secret/internal columns from a preview row before it leaves the server.
 * The password hash must NEVER reach a client (any previews.view role could
 * otherwise crack it offline); callers get a boolean `password_protected` flag
 * instead, which is already stored separately.
 *
 * @param array<string,mixed> $preview
 * @return array<string,mixed>
 */
$present = static function (array $preview): array {
    unset($preview['password_hash']);
    $preview['password_protected'] = (int) ($preview['password_protected'] ?? 0) === 1;

    return $preview;
};

$expand = static function (array $preview) use ($present): array {
    $p = breakfast();
    $preview = $present($preview);
    $preview['versions']      = $p->previewVersions()->forPreview((string) $preview['uuid']);
    $preview['public_url']    = $p->previewUrls()->url((string) $preview['public_slug']);
    $preview['analytics']     = $p->previewAnalytics()->summary((string) $preview['uuid']);
    $preview['isolated']      = $p->previewUrls()->hasIsolatedOrigin();

    return $preview;
};

return [
    // -- List -------------------------------------------------------------
    [
        'pattern' => 'breakfast/previews',
        'method'  => 'GET',
        'action'  => function () use ($gate, $present) {
            $gate('view');

            $rows = breakfast()->previews()->all([
                'status'           => get('status', ''),
                'search'           => get('q', ''),
                'include_archived' => get('archived') === 'true',
                'limit'            => 200,
            ]);

            return ['data' => array_map($present, $rows)];
        },
    ],

    // -- Create -----------------------------------------------------------
    [
        'pattern' => 'breakfast/previews',
        'method'  => 'POST',
        'action'  => function () use ($gate, $actor) {
            $gate('create');
            $p    = breakfast();
            $slug = PreviewSlug::normalise((string) get('slug', get('name', '')));
            $check = PreviewSlug::validate($slug);
            if ($check['ok'] === false) {
                return ['status' => 'error', 'error' => 'slug_' . $check['error']];
            }
            if ($p->previews()->slugTaken($slug)) {
                return ['status' => 'error', 'error' => 'slug_taken'];
            }

            $days    = (int) ($p->previewConfig()['defaultExpiryDays'] ?? 30);
            $expires = $days > 0 ? \Breakfast\Platform\Support\Clock::now()->modify('+' . $days . ' days')->format('c') : null;

            $preview = $p->previews()->create([
                'name'                => (string) get('name', 'Untitled preview'),
                'client_display_name' => get('client_display_name'),
                'public_slug'         => $slug,
                'contact_uuid'        => get('contact_uuid'),
                'company_uuid'        => get('company_uuid'),
                'opportunity_uuid'    => get('opportunity_uuid'),
                'note'                => get('note'),
                'expires_at'          => $expires,
                'created_by'          => $actor(),
            ]);
            $p->audit()->event('preview.create', 'preview', (string) $preview['uuid'], $actor(), ['slug' => $slug]);

            return ['data' => $preview];
        },
    ],

    // -- Detail -----------------------------------------------------------
    [
        'pattern' => 'breakfast/previews/(:any)',
        'method'  => 'GET',
        'action'  => function (string $uuid) use ($gate, $expand) {
            $gate('view');
            $preview = breakfast()->previews()->find($uuid);
            if ($preview === null) {
                return ['status' => 'error', 'error' => 'not_found'];
            }

            return ['data' => $expand($preview)];
        },
    ],

    // -- Upload a new version (ZIP) --------------------------------------
    [
        'pattern' => 'breakfast/previews/(:any)/upload',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($gate, $actor) {
            $gate('upload');
            $file = $_FILES['file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || is_uploaded_file((string) $file['tmp_name']) === false) {
                return ['status' => 'error', 'error' => 'no_upload'];
            }
            $result = breakfast()->previewUpload()->uploadZip($uuid, (string) $file['tmp_name'], (string) ($file['name'] ?? 'upload.zip'), $actor());
            breakfast()->audit()->event('preview.upload', 'preview', $uuid, $actor(), ['ok' => $result['ok']]);

            return ['data' => $result];
        },
    ],

    // -- Publish / rollback ----------------------------------------------
    [
        'pattern' => 'breakfast/previews/(:any)/publish',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($gate, $actor) {
            $gate('publish');
            $result = breakfast()->previewPublisher()->publish($uuid, (string) get('version_uuid', ''), $actor());
            if ($result['ok']) {
                breakfast()->audit()->event('preview.publish', 'preview', $uuid, $actor(), ['version' => get('version_uuid')]);
                breakfast()->activities()->record('preview', $uuid, 'preview.published', 'Preview published', 'user', $actor(), []);
            }

            return $result['ok'] ? ['data' => $result['preview']] : ['status' => 'error', 'error' => $result['error']];
        },
    ],
    [
        'pattern' => 'breakfast/previews/(:any)/rollback',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($gate, $actor) {
            $gate('rollback');
            $result = breakfast()->previewPublisher()->rollback($uuid, (string) get('version_uuid', ''), $actor());
            if ($result['ok']) {
                breakfast()->audit()->event('preview.rollback', 'preview', $uuid, $actor(), ['version' => get('version_uuid')]);
            }

            return $result['ok'] ? ['data' => $result['preview']] : ['status' => 'error', 'error' => $result['error']];
        },
    ],

    // -- Change slug ------------------------------------------------------
    [
        'pattern' => 'breakfast/previews/(:any)/slug',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($gate, $actor) {
            $gate('changeSlug');
            $p       = breakfast();
            $preview = $p->previews()->find($uuid);
            if ($preview === null) {
                return ['status' => 'error', 'error' => 'not_found'];
            }
            $slug  = PreviewSlug::normalise((string) get('slug', ''));
            $check = PreviewSlug::validate($slug);
            if ($check['ok'] === false) {
                return ['status' => 'error', 'error' => 'slug_' . $check['error']];
            }
            if ($p->previews()->slugTaken($slug, $uuid)) {
                return ['status' => 'error', 'error' => 'slug_taken'];
            }
            $updated = $p->previews()->update($uuid, [
                'previous_slug' => (string) $preview['public_slug'],
                'public_slug'   => $slug,
                'updated_by'    => $actor(),
            ]);
            $p->audit()->event('preview.slug', 'preview', $uuid, $actor(), ['from' => $preview['public_slug'], 'to' => $slug]);

            return ['data' => $updated];
        },
    ],

    // -- Password (set / clear) ------------------------------------------
    [
        'pattern' => 'breakfast/previews/(:any)/password',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($gate, $actor) {
            $gate('managePassword');
            $p        = breakfast();
            $password = (string) get('password', '');
            if (get('clear') === 'true' || $password === '') {
                $p->previewPasswords()->clearPassword($uuid, $actor());
                $p->audit()->event('preview.password.clear', 'preview', $uuid, $actor(), []);

                return ['data' => ['password_protected' => false]];
            }
            if ($p->previewPasswords()->setPassword($uuid, $password, $actor()) === false) {
                return ['status' => 'error', 'error' => 'password_too_short'];
            }
            // Log that a password was set — never the password itself.
            $p->audit()->event('preview.password.set', 'preview', $uuid, $actor(), []);

            return ['data' => ['password_protected' => true]];
        },
    ],

    // -- Visibility (public/unlisted) ------------------------------------
    [
        'pattern' => 'breakfast/previews/(:any)/visibility',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($gate, $actor) {
            $gate('publish');
            $visibility = (string) get('visibility', PreviewStatus::VISIBILITY_UNLISTED);
            if (in_array($visibility, [PreviewStatus::VISIBILITY_PUBLIC, PreviewStatus::VISIBILITY_UNLISTED], true) === false) {
                return ['status' => 'error', 'error' => 'invalid_visibility'];
            }
            $updated = breakfast()->previews()->update($uuid, ['visibility' => $visibility, 'updated_by' => $actor()]);

            return ['data' => $updated];
        },
    ],

    // -- Search indexability (admin-only, explicit confirmation) ---------
    [
        'pattern' => 'breakfast/previews/(:any)/indexing',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($gate, $actor) {
            $gate('systemManage');
            if (get('confirm') !== 'true') {
                return ['status' => 'error', 'error' => 'confirmation_required'];
            }
            $indexable = get('indexable') === 'true';
            $updated   = breakfast()->previews()->update($uuid, ['indexable' => $indexable ? 1 : 0, 'updated_by' => $actor()]);
            breakfast()->audit()->event('preview.indexing', 'preview', $uuid, $actor(), ['indexable' => $indexable]);

            return ['data' => $updated];
        },
    ],

    // -- Expiry -----------------------------------------------------------
    [
        'pattern' => 'breakfast/previews/(:any)/expiry',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($gate, $actor) {
            $gate('manageExpiry');
            $expires = get('expires_at');
            $updated = breakfast()->previews()->update($uuid, [
                'expires_at' => $expires !== '' ? $expires : null,
                'updated_by' => $actor(),
            ]);
            breakfast()->audit()->event('preview.expiry', 'preview', $uuid, $actor(), ['expires_at' => $expires]);

            return ['data' => $updated];
        },
    ],

    // -- Disable / re-enable / archive -----------------------------------
    [
        'pattern' => 'breakfast/previews/(:any)/disable',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($gate, $actor) {
            $gate('disable');
            $enable  = get('enable') === 'true';
            $updated = breakfast()->previews()->update($uuid, [
                'status'      => $enable ? PreviewStatus::ACTIVE : PreviewStatus::DISABLED,
                'disabled_at' => $enable ? null : \Breakfast\Platform\Support\Clock::nowIso(),
                'updated_by'  => $actor(),
            ]);
            breakfast()->audit()->event($enable ? 'preview.enable' : 'preview.disable', 'preview', $uuid, $actor(), []);

            return ['data' => $updated];
        },
    ],
    [
        'pattern' => 'breakfast/previews/(:any)/archive',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($gate, $actor) {
            $gate('archive');
            $updated = breakfast()->previews()->update($uuid, [
                'status'      => PreviewStatus::ARCHIVED,
                'archived_at' => \Breakfast\Platform\Support\Clock::nowIso(),
                'updated_by'  => $actor(),
            ]);
            breakfast()->audit()->event('preview.archive', 'preview', $uuid, $actor(), []);

            return ['data' => $updated];
        },
    ],

    // -- Delete (removes storage + row) ----------------------------------
    [
        'pattern' => 'breakfast/previews/(:any)',
        'method'  => 'DELETE',
        'action'  => function (string $uuid) use ($gate, $actor) {
            $gate('delete');
            $p = breakfast();
            $p->previewStorage()->deleteTree($p->previewStorage()->root() . '/versions/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $uuid));
            // Flat-file delete: remove the preview and its children explicitly
            // (foreign-key cascade enforcement is off during the migration).
            $p->previewActivity()->deleteSubmissions($uuid);
            $p->previewActivity()->deleteAccessFor($uuid);
            $p->previewVersions()->deleteForPreview($uuid);
            $p->previews()->delete($uuid);
            $p->audit()->event('preview.delete', 'preview', $uuid, $actor(), []);

            return ['data' => ['ok' => true]];
        },
    ],

    // -- Read-only file browser ------------------------------------------
    [
        'pattern' => 'breakfast/previews/(:any)/files',
        'method'  => 'GET',
        'action'  => function (string $uuid) use ($gate) {
            $gate('view');
            $p       = breakfast();
            $preview = $p->previews()->find($uuid);
            $versionUuid = get('version_uuid') ?: ($preview['current_version_uuid'] ?? '');
            $version = $versionUuid !== '' ? $p->previewVersions()->find((string) $versionUuid) : null;
            if ($version === null || (string) $version['preview_uuid'] !== $uuid) {
                return ['status' => 'error', 'error' => 'version_not_found'];
            }
            $path = (string) get('path', '');
            if ($path !== '') {
                return ['data' => $p->previewFileBrowser()->readFile($version, $path)];
            }

            return ['data' => ['files' => $p->previewFileBrowser()->listFiles($version)]];
        },
    ],

    // -- Analytics --------------------------------------------------------
    [
        'pattern' => 'breakfast/previews/(:any)/analytics',
        'method'  => 'GET',
        'action'  => function (string $uuid) use ($gate) {
            $gate('viewAnalytics');

            return ['data' => breakfast()->previewAnalytics()->summary($uuid)];
        },
    ],

    // -- Preview form submissions (test leads) ---------------------------
    [
        'pattern' => 'breakfast/previews/(:any)/submissions',
        'method'  => 'GET',
        'action'  => function (string $uuid) use ($gate) {
            $gate('view');

            return ['data' => breakfast()->previewActivity()->submissions($uuid)];
        },
    ],
    [
        'pattern' => 'breakfast/previews/(:any)/submissions',
        'method'  => 'DELETE',
        'action'  => function (string $uuid) use ($gate, $actor) {
            // Deleting captured test-lead data is a mutating action — never the
            // read-only `view` grant (a read-only Analyst must not delete data).
            $gate('disable');
            breakfast()->previewActivity()->deleteSubmissions($uuid);
            breakfast()->audit()->event('preview.submissions.clear', 'preview', $uuid, $actor(), []);

            return ['data' => ['ok' => true]];
        },
    ],

    // -- Build a CRM email DRAFT (never sends) ----------------------------
    [
        'pattern' => 'breakfast/previews/(:any)/email-draft',
        'method'  => 'POST',
        'action'  => function (string $uuid) use ($gate) {
            $gate('emailDraft');
            $p       = breakfast();
            $preview = $p->previews()->find($uuid);
            if ($preview === null) {
                return ['status' => 'error', 'error' => 'not_found'];
            }
            $user  = kirby()->user();
            $draft = $p->previewEmailDraft()->build($preview, $user?->name()->value(), $user?->email());
            if ($draft['ok'] === false) {
                return ['status' => 'error', 'error' => $draft['error']];
            }
            // Log that an invitation draft (link) was prepared — not the password.
            $p->audit()->event('preview.email_draft', 'preview', $uuid, $user?->email(), ['to' => $draft['to']]);

            return ['data' => $draft];
        },
    ],
];
