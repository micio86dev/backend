<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use Dedoc\Scramble\Support\Type\ArrayType;

/**
 * A JSON object whose keys are strings and whose values share one type.
 *
 * Scramble has no shape for it: a plain `array<string, V>` is exported as an
 * untyped array. {@see InterviewSerializerTypesExtension} substitutes this type
 * for a specific response entry, and {@see StringMapToSchemaExtension} exports
 * only this type as `type: object`. Any other array keeps Scramble's mapping.
 */
final class StringMapType extends ArrayType {}
