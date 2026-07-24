<?php

declare(strict_types=1);

namespace Breakfast\Platform\Onboarding;

/**
 * Bounded, declarative visibility conditions — evaluated SERVER-SIDE so hidden
 * questions are never validated or accepted. A condition is a JSON group:
 *
 *   { "op": "and"|"or", "rules": [ rule|group, ... ] }
 *   rule: { "q": "<qkey>", "cmp": "equals|not_equals|contains|selected|
 *           not_selected|gt|lt|answered|unanswered", "value": <scalar> }
 *
 * An empty condition means "always visible". Nesting is bounded (depth-limited)
 * and references to unknown questions evaluate to false rather than throwing.
 */
final class OnboardingConditions
{
    private const MAX_DEPTH = 5;

    /** @param array<string,mixed> $answers qkey => value (scalar or list) */
    public static function visible(string $conditionJson, array $answers): bool
    {
        $conditionJson = trim($conditionJson);
        if ($conditionJson === '') {
            return true;
        }
        $group = json_decode($conditionJson, true);
        if (!is_array($group)) {
            return true;
        }

        return self::evalGroup($group, $answers, 0);
    }

    /**
     * @param array<string,mixed> $group
     * @param array<string,mixed> $answers
     */
    private static function evalGroup(array $group, array $answers, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return false;
        }
        $op = ($group['op'] ?? 'and') === 'or' ? 'or' : 'and';
        $rules = is_array($group['rules'] ?? null) ? $group['rules'] : [];
        if ($rules === []) {
            return true;
        }
        $results = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $results[] = isset($rule['op']) ? self::evalGroup($rule, $answers, $depth + 1) : self::evalRule($rule, $answers);
        }
        if ($results === []) {
            return true;
        }

        return $op === 'or' ? in_array(true, $results, true) : !in_array(false, $results, true);
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $answers
     */
    private static function evalRule(array $rule, array $answers): bool
    {
        $q = (string) ($rule['q'] ?? '');
        $cmp = (string) ($rule['cmp'] ?? 'answered');
        $expected = $rule['value'] ?? null;
        $has = array_key_exists($q, $answers);
        $value = $answers[$q] ?? null;

        switch ($cmp) {
            case 'answered':
                return $has && !self::isEmpty($value);
            case 'unanswered':
                return !$has || self::isEmpty($value);
            case 'equals':
                return (string) $value === (string) $expected;
            case 'not_equals':
                return (string) $value !== (string) $expected;
            case 'contains':
                return is_string($value) && $expected !== null && str_contains(strtolower($value), strtolower((string) $expected));
            case 'selected':
                return is_array($value) ? in_array((string) $expected, array_map('strval', $value), true) : (string) $value === (string) $expected;
            case 'not_selected':
                return is_array($value) ? !in_array((string) $expected, array_map('strval', $value), true) : (string) $value !== (string) $expected;
            case 'gt':
                return is_numeric($value) && is_numeric($expected) && (float) $value > (float) $expected;
            case 'lt':
                return is_numeric($value) && is_numeric($expected) && (float) $value < (float) $expected;
            default:
                return false;
        }
    }

    private static function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) ($value ?? '')) === '';
    }

    /**
     * Detect a circular visibility dependency among questions: does question $a
     * (transitively, via the questions its condition references) depend on
     * itself? $conditionsByKey maps qkey => list of referenced qkeys.
     *
     * @param array<string,list<string>> $refs
     */
    public static function hasCircularVisibility(array $refs): bool
    {
        foreach (array_keys($refs) as $start) {
            if (self::reaches((string) $start, (string) $start, $refs, [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,list<string>> $refs
     * @param array<string,bool> $seen
     */
    private static function reaches(string $from, string $target, array $refs, array $seen): bool
    {
        foreach ($refs[$from] ?? [] as $next) {
            if ($next === $target) {
                return true;
            }
            if (!isset($seen[$next])) {
                $seen[$next] = true;
                if (self::reaches($next, $target, $refs, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }
}
