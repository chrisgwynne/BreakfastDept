<?php

declare(strict_types=1);

namespace Breakfast\Platform\Onboarding;

use RuntimeException;

/** A safe, client-facing onboarding error carrying an HTTP status. */
final class OnboardingException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
