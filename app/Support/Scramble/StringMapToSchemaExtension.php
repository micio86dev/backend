<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Exports a {@see StringMapType} as a JSON object (`type: object` with
 * `additionalProperties` set to the value schema). Every other array, including
 * string-keyed ones that were not marked, keeps Scramble's own mapping.
 */
final class StringMapToSchemaExtension extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof StringMapType;
    }

    /**
     * @param  StringMapType  $type
     */
    public function toSchema(Type $type): OpenApi\Type
    {
        $object = new OpenApi\ObjectType;
        $object->additionalProperties = $this->openApiTransformer->transform($type->value);

        return $object;
    }
}
