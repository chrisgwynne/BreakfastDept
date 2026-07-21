<?php

declare(strict_types=1);

namespace Breakfast\Platform\Forms;

/**
 * Structural definition of a form: which fields exist, their validation rules,
 * and how they map onto CRM records. Field *labels, options and messages* are
 * NOT defined here — they come from editable Kirby content and are passed to
 * the validator/templates separately. This class is the trustworthy, non-
 * editable structure that keeps validation and persistence consistent.
 */
final class FormDefinition
{
    /**
     * @param array<string,array<string,mixed>> $rules
     * @param list<string>                       $contactFields fields that map to the contact record
     * @param list<string>                       $summaryFields fields used to build the human summary
     */
    private function __construct(
        public readonly string $type,
        public readonly array $rules,
        public readonly array $contactFields,
        public readonly array $summaryFields
    ) {
    }

    public static function contact(): self
    {
        return new self(
            'contact',
            [
                'name'    => ['required' => true, 'max' => 120],
                'email'   => ['required' => true, 'type' => 'email', 'max' => 254],
                'company' => ['max' => 160],
                'subject' => ['max' => 160],
                'message' => ['required' => true, 'min' => 10, 'max' => 5000],
                'consent' => ['max' => 10],
            ],
            ['name', 'email', 'company'],
            ['subject', 'message']
        );
    }

    public static function startProject(): self
    {
        return new self(
            'start-project',
            [
                'name'         => ['required' => true, 'max' => 120],
                'email'        => ['required' => true, 'type' => 'email', 'max' => 254],
                'phone'        => ['type' => 'tel', 'max' => 30],
                'company'      => ['max' => 160],
                'website'      => ['type' => 'url', 'max' => 200],
                'business'     => ['max' => 2000],
                'help'         => ['max' => 2000],
                'services'     => ['max' => 400],
                'goals'        => ['max' => 2000],
                'budget'       => ['max' => 60],
                'launch_date'  => ['max' => 40],
                'heard_about'  => ['max' => 120],
                'message'      => ['max' => 5000],
                'consent'      => ['max' => 10],
            ],
            ['name', 'email', 'phone', 'company', 'website'],
            ['help', 'services', 'budget', 'message']
        );
    }

    public static function for(string $type): self
    {
        return $type === 'start-project' ? self::startProject() : self::contact();
    }

    /** @return list<string> all known field names */
    public function fields(): array
    {
        return array_keys($this->rules);
    }
}
