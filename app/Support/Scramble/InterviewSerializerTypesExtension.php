<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use App\PublicApi\Serializers\InterviewSerializer;
use Dedoc\Scramble\Infer\Extensions\Event\StaticMethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\StaticMethodReturnTypeExtension;
use Dedoc\Scramble\Infer\Services\ReferenceTypeResolver;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Reference\StaticMethodCallReferenceType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Documents two entries of `InterviewSerializer::toArray()` with the types their
 * docblock states, through DOCUMENTATION only, never a runtime branch:
 *
 * - `metadata` as a string map (`additionalProperties: string`). Scramble infers
 *   it from `$participant->metadata ?? []` and exports an untyped array, which the
 *   SDKs type as `Array<any>`.
 * - `progress` as a list of competencies, each with its answers. Scramble infers
 *   a union with `string`, which the SDKs cannot type.
 *
 * Same shape as {@see InterviewHostedUrlNullableExtension}: resolve the call as
 * Scramble normally would, then patch only the two entries. The re-entrancy guard
 * lets the inner resolution fall through to Scramble's default exactly once.
 */
final class InterviewSerializerTypesExtension implements StaticMethodReturnTypeExtension
{
    private bool $resolving = false;

    public function shouldHandle(string $name): bool
    {
        return $name === InterviewSerializer::class;
    }

    public function getStaticMethodReturnType(StaticMethodCallEvent $event): ?Type
    {
        if ($event->name !== 'toArray' || $this->resolving) {
            return null;
        }

        $this->resolving = true;

        try {
            $resolved = ReferenceTypeResolver::getInstance()->resolve(
                $event->scope,
                new StaticMethodCallReferenceType($event->callee, $event->name, $event->arguments->all()),
            );
        } finally {
            $this->resolving = false;
        }

        if (! $resolved instanceof KeyedArrayType) {
            return null;
        }

        // A deep copy first: the resolver may hand out a shared instance, and patching it in
        // place would leak these types into other resolutions of the same call. The copy keeps
        // every item's attributes (descriptions included), which rebuilding the shape would drop.
        $resolved = $resolved->clone();

        foreach ($resolved->items as $item) {
            if ($item->key === 'metadata') {
                $item->value = new StringMapType(value: new StringType, key: new StringType);
            }

            if ($item->key === 'progress') {
                $item->value = $this->progressType();
            }
        }

        return $resolved;
    }

    private function progressType(): ArrayType
    {
        $answer = new KeyedArrayType([
            new ArrayItemType_('question_index', new IntegerType),
            new ArrayItemType_('answered_at', new StringType),
        ]);

        $competency = new KeyedArrayType([
            new ArrayItemType_('competency_code', new StringType),
            new ArrayItemType_('answers', new ArrayType(value: $answer)),
        ]);

        return new ArrayType(value: $competency);
    }
}
