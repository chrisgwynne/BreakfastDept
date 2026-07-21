<?php

declare(strict_types=1);

namespace Breakfast\Platform\Crm;

use Breakfast\Platform\Support\Clock;
use InvalidArgumentException;

/**
 * CRM service layer.
 *
 * Coordinates the repositories, enforces pipeline rules, writes the immutable
 * activity trail for every meaningful change, and produces the dashboard
 * metrics. Panel areas, the form pipeline and the Hermes API all go through
 * here rather than touching repositories directly, so business rules and audit
 * logging live in one place.
 */
final class Crm
{
    public const DEFAULT_STAGES = ['new', 'qualified', 'discovery', 'proposal', 'decision', 'won', 'lost'];

    /** @param list<string> $stages */
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly EnquiryRepository $enquiries,
        private readonly OpportunityRepository $opportunities,
        private readonly TaskRepository $tasks,
        private readonly ActivityRepository $activities,
        private readonly array $stages = self::DEFAULT_STAGES
    ) {
    }

    public function contacts(): ContactRepository
    {
        return $this->contacts;
    }

    public function companies(): CompanyRepository
    {
        return $this->companies;
    }

    public function enquiries(): EnquiryRepository
    {
        return $this->enquiries;
    }

    public function opportunities(): OpportunityRepository
    {
        return $this->opportunities;
    }

    public function tasks(): TaskRepository
    {
        return $this->tasks;
    }

    public function activities(): ActivityRepository
    {
        return $this->activities;
    }

    /** @return list<string> */
    public function stages(): array
    {
        return $this->stages;
    }

    // -- Notes -------------------------------------------------------------

    /**
     * Attach a note to any entity as an immutable activity record.
     *
     * @return array<string,mixed>
     */
    public function addNote(
        string $entityType,
        string $entityUuid,
        string $note,
        string $actorType = 'user',
        ?string $actorRef = null
    ): array {
        $note = trim($note);

        if ($note === '') {
            throw new InvalidArgumentException('Note cannot be empty');
        }

        return $this->activities->record(
            $entityType,
            $entityUuid,
            'note.added',
            $note,
            $actorType,
            $actorRef,
            ['length' => mb_strlen($note)]
        );
    }

    // -- Enquiries ---------------------------------------------------------

    /**
     * Classify an enquiry (service interest, urgency, spam, summary). Used by
     * the Hermes triage workflow. Never deletes or silently rejects a lead —
     * marking as spam only sets status/flags, the record remains.
     *
     * @param array{status?:string,spam_status?:string,risk_score?:int,summary?:string,services?:list<string>} $data
     * @return array<string,mixed>|null
     */
    public function classifyEnquiry(string $uuid, array $data, string $actorType = 'user', ?string $actorRef = null): ?array
    {
        $enquiry = $this->enquiries->find($uuid);

        if ($enquiry === null) {
            return null;
        }

        $patch = array_intersect_key($data, array_flip(['status', 'spam_status', 'risk_score', 'summary']));
        $updated = $this->enquiries->update($uuid, $patch);

        $this->activities->record(
            'enquiry',
            $uuid,
            'enquiry.classified',
            'Enquiry classified: ' . ($data['status'] ?? $enquiry['status']),
            $actorType,
            $actorRef,
            array_intersect_key($data, array_flip(['status', 'spam_status', 'risk_score', 'services']))
        );

        return $updated;
    }

    /** @return array<string,mixed>|null */
    public function setEnquiryStatus(string $uuid, string $status, string $actorType = 'user', ?string $actorRef = null): ?array
    {
        $enquiry = $this->enquiries->find($uuid);

        if ($enquiry === null) {
            return null;
        }

        $updated = $this->enquiries->update($uuid, ['status' => $status]);

        $this->activities->record(
            'enquiry',
            $uuid,
            'status.changed',
            'Status changed from ' . $enquiry['status'] . ' to ' . $status,
            $actorType,
            $actorRef,
            ['from' => $enquiry['status'], 'to' => $status]
        );

        return $updated;
    }

    // -- Opportunities -----------------------------------------------------

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function createOpportunity(array $data, string $actorType = 'user', ?string $actorRef = null): array
    {
        $opp = $this->opportunities->create($data);

        $this->activities->record(
            'opportunity',
            (string) $opp['uuid'],
            'opportunity.created',
            'Opportunity created: ' . $opp['title'],
            $actorType,
            $actorRef,
            ['stage' => $opp['stage'], 'value' => $opp['estimated_value']]
        );

        return $opp;
    }

    /**
     * Move an opportunity to a new pipeline stage, validating the stage and
     * stamping won/lost timestamps. Returns the updated opportunity.
     *
     * @return array<string,mixed>|null
     */
    public function moveOpportunity(
        string $uuid,
        string $stage,
        string $actorType = 'user',
        ?string $actorRef = null,
        ?string $lostReason = null
    ): ?array {
        if (in_array($stage, $this->stages, true) === false) {
            throw new InvalidArgumentException('Unknown pipeline stage: ' . $stage);
        }

        $opp = $this->opportunities->find($uuid);

        if ($opp === null) {
            return null;
        }

        $from  = (string) $opp['stage'];
        $patch = ['stage' => $stage];

        if ($stage === 'won') {
            $patch['won_at']      = Clock::nowIso();
            $patch['probability'] = 100;
        } elseif ($stage === 'lost') {
            $patch['lost_at']      = Clock::nowIso();
            $patch['probability']  = 0;
            $patch['lost_reason']  = $lostReason;
        }

        $updated = $this->opportunities->update($uuid, $patch);

        $this->activities->record(
            'opportunity',
            $uuid,
            'stage.changed',
            'Stage changed from ' . $from . ' to ' . $stage,
            $actorType,
            $actorRef,
            ['from' => $from, 'to' => $stage, 'lost_reason' => $lostReason]
        );

        return $updated;
    }

    // -- Tasks -------------------------------------------------------------

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function createTask(array $data, string $actorType = 'user', ?string $actorRef = null): array
    {
        $task = $this->tasks->create($data);

        $entityType = 'task';
        $entityUuid = (string) $task['uuid'];

        // Also log against the related entity so it shows on that timeline.
        if (!empty($task['opportunity_uuid'])) {
            $entityType = 'opportunity';
            $entityUuid = (string) $task['opportunity_uuid'];
        } elseif (!empty($task['contact_uuid'])) {
            $entityType = 'contact';
            $entityUuid = (string) $task['contact_uuid'];
        }

        $this->activities->record(
            $entityType,
            $entityUuid,
            'task.created',
            'Task created: ' . $task['title'],
            $actorType,
            $actorRef,
            ['task_uuid' => $task['uuid'], 'due_date' => $task['due_date']]
        );

        return $task;
    }

    // -- Dashboard ---------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    public function dashboard(): array
    {
        return [
            'new_enquiries'       => $this->enquiries->count('new'),
            'qualified_enquiries' => $this->enquiries->count('qualified'),
            'total_enquiries'     => $this->enquiries->count(),
            'open_opportunities'  => $this->opportunities->countOpen(),
            'pipeline_value'      => $this->opportunities->openPipelineValue(),
            'pipeline_by_stage'   => $this->opportunities->pipelineByStage(),
            'overdue_tasks'       => $this->tasks->countOverdue(),
            'upcoming_tasks'      => $this->tasks->countUpcoming(),
            'contacts'            => $this->contacts->count(),
            'companies'           => $this->companies->count(),
            'by_source'           => $this->enquiries->countBySource(),
            'recent_activity'     => $this->activities->recent(15),
        ];
    }
}
