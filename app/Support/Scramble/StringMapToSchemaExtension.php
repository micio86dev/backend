<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Exports a string-keyed array as a JSON object (`type: object` with
 * `additionalProperties` set to the value schema). Scramble's default maps every
 * array to a JSON array, so a map of string keys reached the SDKs as a list.
 *
 * Only arrays whose key type is exactly `string` match. Integer-keyed lists and
 * array shapes keep Scramble's own mapping.
 */
final class StringMapToSchemaExtension extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ArrayType && $type->key instanceof StringType;
    }

    /**
     * @param  ArrayType  $type
     */
    public function toSchema(Type $type): OpenApi\Type
    {
        $object = new OpenApi\ObjectType;
        $object->additionalProperties = $this->openApiTransformer->transform($type->value);

        return $object;
    }
}
