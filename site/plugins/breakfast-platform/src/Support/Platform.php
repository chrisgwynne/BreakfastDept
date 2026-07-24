<?php

declare(strict_types=1);

namespace Breakfast\Platform\Support;

use Breakfast\Platform\Analytics\Analytics;
use Breakfast\Platform\Crm\ActivityRepository;
use Breakfast\Platform\Crm\CompanyRepository;
use Breakfast\Platform\Crm\ContactRepository;
use Breakfast\Platform\Crm\Crm;
use Breakfast\Platform\Crm\EnquiryRepository;
use Breakfast\Platform\Crm\OpportunityRepository;
use Breakfast\Platform\Crm\TaskRepository;
use Breakfast\Platform\Hermes\AuditLog;
use Breakfast\Platform\Hermes\WebhookDispatcher;
use Breakfast\Platform\Mail\EnquiryMailFactory;
use Breakfast\Platform\Mail\MailProvider;
use Breakfast\Platform\Mail\MailProviderFactory;
use Breakfast\Platform\Mail\MailService;
use Breakfast\Platform\Mail\OutboundMessageRepository;
use Breakfast\Platform\Mail\SuppressionService;
use Breakfast\Platform\Mail\TemplateRegistry;
use Breakfast\Platform\Queue\Queue;
use Breakfast\Platform\Security\RateLimiter;

/**
 * Central service container for the Breakfast platform.
 *
 * Instantiated once at plugin load with resolved filesystem roots and config,
 * then reused. Kept free of any hard Kirby dependency so the platform can be
 * exercised in unit tests with a temp database.
 */
final class Platform
{
    private static ?self $instance = null;

    private ?Database $db = null;
    private ?Logger $logger = null;

    /** @var array<string,object> lazily built services */
    private array $services = [];

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        private readonly string $baseDir,
        private readonly array $config
    ) {
    }

    /** @param array<string,mixed> $config */
    public static function boot(string $baseDir, array $config): self
    {
        return self::$instance = new self($baseDir, $config);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Platform has not been booted');
        }

        return self::$instance;
    }

    public static function booted(): bool
    {
        return self::$instance !== null;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    public function storageDir(): string
    {
        return $this->config('storageDir') ?? $this->baseDir . '/storage';
    }

    public function dbPath(): string
    {
        return $this->config('dbPath') ?? $this->storageDir() . '/database/crm.sqlite';
    }

    public function migrationsDir(): string
    {
        return __DIR__ . '/../../migrations';
    }

    public function uploadsDir(): string
    {
        return $this->config('uploadsDir') ?? $this->storageDir() . '/uploads';
    }

    public function db(): Database
    {
        return $this->db ??= Database::instance($this->dbPath());
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger($this->storageDir() . '/logs');
    }

    public function migrator(): Migrator
    {
        return new Migrator($this->db(), $this->migrationsDir());
    }

    /**
     * Memoise a service instance by key.
     *
     * @param callable():object $factory
     */
    private function service(string $class, callable $factory): object
    {
        return $this->services[$class] ??= $factory();
    }

    public function contacts(): ContactRepository
    {
        return $this->service(ContactRepository::class, fn () => new ContactRepository($this->db()));
    }

    public function companies(): CompanyRepository
    {
        return $this->service(CompanyRepository::class, fn () => new CompanyRepository($this->db()));
    }

    public function enquiries(): EnquiryRepository
    {
        return $this->service(EnquiryRepository::class, fn () => new EnquiryRepository($this->db()));
    }

    public function opportunities(): OpportunityRepository
    {
        return $this->service(OpportunityRepository::class, fn () => new OpportunityRepository($this->db()));
    }

    public function tasks(): TaskRepository
    {
        return $this->service(TaskRepository::class, fn () => new TaskRepository($this->db()));
    }

    public function activities(): ActivityRepository
    {
        return $this->service(ActivityRepository::class, fn () => new ActivityRepository($this->db()));
    }

    public function crm(): Crm
    {
        return $this->service(Crm::class, fn () => new Crm(
            $this->contacts(),
            $this->companies(),
            $this->enquiries(),
            $this->opportunities(),
            $this->tasks(),
            $this->activities(),
            $this->config('pipelineStages', Crm::DEFAULT_STAGES)
        ));
    }

    public function queue(): Queue
    {
        return $this->service(Queue::class, fn () => new Queue($this->db(), $this->logger()));
    }

    public function invoices(): \Breakfast\Platform\Invoicing\Invoices
    {
        return $this->service(\Breakfast\Platform\Invoicing\Invoices::class, fn () => new \Breakfast\Platform\Invoicing\Invoices($this->db()));
    }

    public function proposals(): \Breakfast\Platform\Proposals\Proposals
    {
        return $this->service(\Breakfast\Platform\Proposals\Proposals::class, fn () => new \Breakfast\Platform\Proposals\Proposals($this->db()));
    }

    public function contracts(): \Breakfast\Platform\Contracts\Contracts
    {
        return $this->service(\Breakfast\Platform\Contracts\Contracts::class, fn () => new \Breakfast\Platform\Contracts\Contracts($this->db()));
    }

    public function contractDocuments(): \Breakfast\Platform\Contracts\ContractDocumentService
    {
        return $this->service(\Breakfast\Platform\Contracts\ContractDocumentService::class, fn () => new \Breakfast\Platform\Contracts\ContractDocumentService(
            $this->contracts(),
            new \Breakfast\Platform\Contracts\ContractPdfRenderer(),
            $this->storageDir() . '/contracts'
        ));
    }

    public function projects(): \Breakfast\Platform\Projects\Projects
    {
        return $this->service(\Breakfast\Platform\Projects\Projects::class, fn () => new \Breakfast\Platform\Projects\Projects($this->db()));
    }

    public function projectConversion(): \Breakfast\Platform\Projects\ProjectConversion
    {
        return $this->service(\Breakfast\Platform\Projects\ProjectConversion::class, fn () => new \Breakfast\Platform\Projects\ProjectConversion($this));
    }

    public function projectTemplates(): \Breakfast\Platform\Projects\ProjectTemplates
    {
        return $this->service(\Breakfast\Platform\Projects\ProjectTemplates::class, fn () => new \Breakfast\Platform\Projects\ProjectTemplates($this->db()));
    }

    public function milestones(): \Breakfast\Platform\Projects\Milestones
    {
        return $this->service(\Breakfast\Platform\Projects\Milestones::class, fn () => new \Breakfast\Platform\Projects\Milestones($this->db()));
    }

    public function projectTasks(): \Breakfast\Platform\Projects\ProjectTasks
    {
        return $this->service(\Breakfast\Platform\Projects\ProjectTasks::class, fn () => new \Breakfast\Platform\Projects\ProjectTasks($this->db()));
    }

    public function stripeSettings(): \Breakfast\Platform\Payments\StripeSettings
    {
        return $this->service(\Breakfast\Platform\Payments\StripeSettings::class, fn () => new \Breakfast\Platform\Payments\StripeSettings(
            $this->settings(),
            $this->audit()
        ));
    }

    public function payments(): \Breakfast\Platform\Payments\PaymentsService
    {
        return $this->service(\Breakfast\Platform\Payments\PaymentsService::class, fn () => new \Breakfast\Platform\Payments\PaymentsService(
            $this,
            $this->stripeSettings()
        ));
    }

    public function proposalConversion(): \Breakfast\Platform\Proposals\ProposalConversion
    {
        return $this->service(\Breakfast\Platform\Proposals\ProposalConversion::class, fn () => new \Breakfast\Platform\Proposals\ProposalConversion($this));
    }

    public function proposalDocuments(): \Breakfast\Platform\Proposals\ProposalDocumentService
    {
        return $this->service(\Breakfast\Platform\Proposals\ProposalDocumentService::class, fn () => new \Breakfast\Platform\Proposals\ProposalDocumentService(
            $this->proposals(),
            new \Breakfast\Platform\Proposals\ProposalPdfRenderer(),
            $this->db(),
            $this->storageDir() . '/proposals'
        ));
    }

    public function invoiceDocuments(): \Breakfast\Platform\Invoicing\InvoiceDocumentService
    {
        return $this->service(\Breakfast\Platform\Invoicing\InvoiceDocumentService::class, fn () => new \Breakfast\Platform\Invoicing\InvoiceDocumentService(
            $this->invoices(),
            new \Breakfast\Platform\Invoicing\InvoicePdfRenderer(),
            new \Breakfast\Platform\Invoicing\InvoiceDocumentStore($this->db(), $this->storageDir() . '/invoices'),
            $this->activities(),
            $this->audit()
        ));
    }

    /**
     * The environment/config-sourced mail settings (no application overlay).
     *
     * @return array<string,mixed>
     */
    public function rawMailConfig(): array
    {
        $config = $this->config('mail', []);

        return is_array($config) ? $config : [];
    }

    /**
     * The effective mail config: environment defaults with any application-managed
     * Brevo settings (key, sender, base URL) overlaid on top, so a key configured
     * from Settings actually takes effect. Falls back to the raw config if the
     * settings store isn't available yet (e.g. before the schema exists).
     *
     * @return array<string,mixed>
     */
    public function mailConfig(): array
    {
        $config = $this->rawMailConfig();
        try {
            $overlay = $this->brevoSettings()->effectiveMailOverlay();
        } catch (\Throwable) {
            $overlay = [];
        }

        return array_merge($config, $overlay);
    }

    public function isProduction(): bool
    {
        return (bool) $this->config('production', false);
    }

    public function mailProvider(): MailProvider
    {
        return $this->service(MailProvider::class, fn () => MailProviderFactory::make($this->mailConfig(), $this->isProduction(), $this->attachmentResolver()));
    }

    /**
     * Resolves persisted attachment references (e.g. "invoice:<uuid>") to real
     * file bytes at send time. Wired into the mail provider so the queue only
     * ever stores a reference, never a base64 blob.
     */
    public function attachmentResolver(): \Breakfast\Platform\Mail\AttachmentResolver
    {
        return $this->service(\Breakfast\Platform\Mail\AttachmentResolver::class, fn () => new \Breakfast\Platform\Mail\CompositeAttachmentResolver(
            new \Breakfast\Platform\Invoicing\InvoiceAttachmentResolver(
                new \Breakfast\Platform\Invoicing\InvoiceDocumentStore($this->db(), $this->storageDir() . '/invoices')
            ),
            new \Breakfast\Platform\Contracts\ContractAttachmentResolver($this->contractDocuments())
        ));
    }

    public function outbound(): OutboundMessageRepository
    {
        return $this->service(OutboundMessageRepository::class, fn () => new OutboundMessageRepository($this->db()));
    }

    public function suppressions(): SuppressionService
    {
        return $this->service(SuppressionService::class, fn () => new SuppressionService($this->db(), $this->activities()));
    }

    public function templates(): TemplateRegistry
    {
        $ids = $this->mailConfig()['templateIds'] ?? [];

        return $this->service(TemplateRegistry::class, fn () => new TemplateRegistry(is_array($ids) ? $ids : []));
    }

    public function mailService(): MailService
    {
        return $this->service(MailService::class, fn () => new MailService(
            $this->outbound(),
            $this->mailProvider(),
            $this->suppressions(),
            $this->queue(),
            $this->activities()
        ));
    }

    public function crmMail(): \Breakfast\Platform\Mail\CrmMailService
    {
        return $this->service(\Breakfast\Platform\Mail\CrmMailService::class, fn () => new \Breakfast\Platform\Mail\CrmMailService(
            $this->mailService(),
            $this->suppressions(),
            $this->templates(),
            [
                'senderEmail' => (string) ($this->mailConfig()['senderEmail'] ?? $this->mailConfig()['from'] ?? 'studio@example.com'),
                'senderName'  => (string) ($this->mailConfig()['senderName'] ?? $this->mailConfig()['fromName'] ?? 'Breakfast'),
            ]
        ));
    }

    public function enquiryMail(): EnquiryMailFactory
    {
        return $this->service(EnquiryMailFactory::class, fn () => new EnquiryMailFactory([
            'senderEmail' => (string) ($this->mailConfig()['senderEmail'] ?? $this->mailConfig()['from'] ?? 'studio@example.com'),
            'senderName'  => (string) ($this->mailConfig()['senderName'] ?? $this->mailConfig()['fromName'] ?? 'Breakfast'),
            'enquiriesTo' => (string) ($this->mailConfig()['enquiriesTo'] ?? $this->mailConfig()['from'] ?? 'studio@example.com'),
        ], $this->templates()));
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->service(RateLimiter::class, fn () => new RateLimiter($this->db()));
    }

    public function audit(): AuditLog
    {
        return $this->service(AuditLog::class, fn () => new AuditLog($this->db()));
    }

    public function calendar(): \Breakfast\Platform\Calendar\CalendarService
    {
        return $this->service(\Breakfast\Platform\Calendar\CalendarService::class, fn () => new \Breakfast\Platform\Calendar\CalendarService(
            $this->db(),
            $this->activities()
        ));
    }

    public function settings(): \Breakfast\Platform\Settings\SettingsStore
    {
        return $this->service(\Breakfast\Platform\Settings\SettingsStore::class, fn () => new \Breakfast\Platform\Settings\SettingsStore(
            $this->db(),
            new \Breakfast\Platform\Settings\SecretBox($this->storageDir())
        ));
    }

    public function brevoSettings(): \Breakfast\Platform\Settings\BrevoSettings
    {
        return $this->service(\Breakfast\Platform\Settings\BrevoSettings::class, fn () => new \Breakfast\Platform\Settings\BrevoSettings(
            $this->settings(),
            $this->audit(),
            $this->rawMailConfig()
        ));
    }

    public function webhooks(): WebhookDispatcher
    {
        return $this->service(WebhookDispatcher::class, fn () => new WebhookDispatcher(
            $this->db(),
            $this->queue(),
            (string) ($this->config('webhookSecret') ?? '')
        ));
    }

    public function analytics(): Analytics
    {
        return $this->service(Analytics::class, fn () => new Analytics($this->config('analytics', [])));
    }

    /** @return array<string,mixed> */
    public function retentionConfig(): array
    {
        $config = $this->config('retention', []);

        return is_array($config) ? $config : [];
    }

    public function retention(): RetentionService
    {
        return $this->service(RetentionService::class, fn () => new RetentionService($this, $this->retentionConfig()));
    }

    // ------------------------------------------------------------------
    // Client Previews.
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function previewConfig(): array
    {
        $config = $this->config('previews', []);

        return is_array($config) ? $config : [];
    }

    public function previewStorageDir(): string
    {
        $dir = $this->previewConfig()['storageDir'] ?? null;

        return is_string($dir) && $dir !== '' ? $dir : $this->storageDir() . '/client-previews';
    }

    public function previews(): \Breakfast\Platform\ClientPreviews\PreviewRepository
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewRepository::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewRepository($this->db()));
    }

    public function previewVersions(): \Breakfast\Platform\ClientPreviews\PreviewVersionRepository
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewVersionRepository::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewVersionRepository($this->db()));
    }

    public function previewActivity(): \Breakfast\Platform\ClientPreviews\PreviewActivityRepository
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewActivityRepository::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewActivityRepository($this->db()));
    }

    public function previewStorage(): \Breakfast\Platform\ClientPreviews\PreviewStorage
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewStorage::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewStorage($this->previewStorageDir()));
    }

    public function previewUrls(): \Breakfast\Platform\ClientPreviews\PreviewUrlGenerator
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewUrlGenerator::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewUrlGenerator($this->previewConfig()));
    }

    public function previewUpload(): \Breakfast\Platform\ClientPreviews\PreviewUploadService
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewUploadService::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewUploadService(
            $this->previews(),
            $this->previewVersions(),
            $this->previewStorage(),
            new \Breakfast\Platform\ClientPreviews\PreviewArchiveValidator(),
            new \Breakfast\Platform\ClientPreviews\PreviewManifestBuilder(),
            $this->previewConfig()
        ));
    }

    public function previewPublisher(): \Breakfast\Platform\ClientPreviews\PreviewPublisher
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewPublisher::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewPublisher(
            $this->previews(),
            $this->previewVersions(),
            $this->previewStorage(),
            $this->db(),
            $this->previewConfig()
        ));
    }

    public function previewPasswords(): \Breakfast\Platform\ClientPreviews\PreviewPasswordService
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewPasswordService::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewPasswordService(
            $this->previews(),
            $this->rateLimiter(),
            $this->previewSecret()
        ));
    }

    public function previewAccess(): \Breakfast\Platform\ClientPreviews\PreviewAccessService
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewAccessService::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewAccessService(
            $this->previews(),
            $this->previewVersions(),
            $this->previewStorage(),
            $this->previewPasswords(),
            $this->previewActivity(),
            $this->previewConfig(),
            $this->activities()
        ));
    }

    public function previewAnalytics(): \Breakfast\Platform\ClientPreviews\PreviewAnalyticsService
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewAnalyticsService::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewAnalyticsService(
            $this->previewActivity(),
            $this->previewConfig()
        ));
    }

    public function previewResponder(): \Breakfast\Platform\ClientPreviews\PreviewResponder
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewResponder::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewResponder(
            $this->previewAccess(),
            $this->previewPasswords(),
            $this->previews(),
            $this->isProduction()
        ));
    }

    public function previewExpiry(): \Breakfast\Platform\ClientPreviews\PreviewExpiryService
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewExpiryService::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewExpiryService($this->previews()));
    }

    public function previewCleanup(): \Breakfast\Platform\ClientPreviews\PreviewCleanupService
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewCleanupService::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewCleanupService(
            $this->previews(),
            $this->previewStorage(),
            $this->previewAnalytics(),
            $this->previewExpiry(),
            $this->previewPublisher(),
            $this->previewConfig()
        ));
    }

    public function previewFileBrowser(): \Breakfast\Platform\ClientPreviews\PreviewFileBrowser
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewFileBrowser::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewFileBrowser($this->previewStorage()));
    }

    public function previewEmailDraft(): \Breakfast\Platform\ClientPreviews\PreviewEmailDraft
    {
        return $this->service(\Breakfast\Platform\ClientPreviews\PreviewEmailDraft::class, fn () => new \Breakfast\Platform\ClientPreviews\PreviewEmailDraft(
            $this->previewUrls(),
            $this->contacts(),
            $this->companies()
        ));
    }

    /**
     * Stable server secret used to sign preview password-session tokens. Prefers
     * the outbound webhook secret; falls back to an install-stable value so
     * development still works. Never returned to any client.
     */
    private function previewSecret(): string
    {
        $secret = (string) ($this->config('webhookSecret') ?? '');

        return $secret !== '' ? 'preview:' . $secret : 'preview:' . hash('sha256', $this->dbPath());
    }

    /** @internal test seam */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
