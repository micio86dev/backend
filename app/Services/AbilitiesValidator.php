<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Validates M2M API-client ability arrays against the canonical set (C5).
 *
 * The canonical set lives in config/m2m_abilities.php — never hardcoded here.
 * REQ-6 / design §Abilities canonicalization
 */
final class AbilitiesValidator
{
    /**
     * Returns true if all abilities in the given array are in the canonical set.
     *
     * An empty array is valid (no abilities granted).
     *
     * @param  string[]  $abilities
     */
    public static function validate(array $abilities): bool
    {
        $allowed = config('m2m_abilities.allowed', []);

        foreach ($abilities as $ability) {
            if (! in_array($ability, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
