<?php

declare(strict_types=1);

namespace App\Support\AvatarTemplates;

// Internal notes, not published (Scramble exports docblock prose as public text):
// an enum rather than a documented string union, because the union was only a
// comment: the constructor accepted any string, the validator's match needed an
// unreachable default arm to stay safe, and a typo like 'checkox' would have
// produced a field that renders as nothing and validates as anything.
/**
 * How a template field is entered and validated: free `text`, a `number` within `min`/`max`, one of a
 * `select`'s `options`, or a `checkbox`.
 */
enum FieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Select = 'select';
    case Checkbox = 'checkbox';
}
