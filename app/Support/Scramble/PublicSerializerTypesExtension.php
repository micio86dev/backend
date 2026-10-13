<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use App\PublicApi\Serializers\InterviewSerializer;
use App\PublicApi\Serializers\OrganizationSerializer;
use App\PublicApi\Serializers\ProjectSerializer;
use Dedoc\Scramble\Infer\Extensions\Event\StaticMethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\StaticMethodReturnTypeExtension;
use Dedoc\Scramble\Infer\Services\ReferenceTypeResolver;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\Reference\StaticMethodCallReferenceType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;

/**
 * Documents entries of the public serializers with the types their docblocks state,
 * through DOCUMENTATION only, never a runtime branch. Scramble infers these entries
 * from Eloquent casts and collection pipelines, and exports an untyped array that the
 * SDKs type as `Array<any>`:
 *
 * - `InterviewSerializer`: `hosted_url` as `string|null`. It is genuinely, unconditionally
 *   `null` on every plain read (a read never has a fresh session token to embed one for), so
 *   Scramble infers the literal `null`; neither a docblock nor a typed local changes that,
 *   because it traces the method body whenever the body is traceable code. `metadata` as a string map (`additionalProperties: string`)
 *   and `progress` as a list of competencies, each with its answers (inferred as a
 *   union with `string`, which the SDKs cannot type).
 * - `OrganizationSerializer`: `allowed_domains` as a list of strings.
 * - `ProjectSerializer`: `competencies` as a list of `{code, name, type}`. The nested
 *   `Interview.project` is built by the same call, so it follows.
 *
 * Resolves the call as Scramble normally would, then patches only the named entries. The re-entrancy guard lets the inner
 * resolution fall through to Scramble's default exactly once.
 */
final class PublicSerializerTypesExtension implements StaticMethodReturnTypeExtension
{
    private bool $resolving = false;

    public function shouldHandle(string $name): bool
    {
        return in_array($name, [InterviewSerializer::class, OrganizationSerializer::class, ProjectSerializer::class], true);
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

        return $this->patched($resolved, $event->callee);
    }

    /**
     * Returns a copy of a resolved `toArray()` shape with this serializer's entries typed,
     * or null when the resolution is not an array shape and Scramble's default stands.
     */
    public function patched(Type $resolved, string $serializer): ?Type
    {
        if (! $resolved instanceof KeyedArrayType) {
            return null;
        }

        // A deep copy first: the resolver may hand out a shared instance, and patching it in
        // place would leak these types into other resolutions of the same call. The copy keeps
        // every item's attributes (descriptions included), which rebuilding the shape would drop.
        $resolved = $resolved->clone();

        foreach ($resolved->items as $item) {
            $override = $this->override($serializer, (string) $item->key);

            if ($override !== null) {
                $item->value = $override;
            }

            // The expanded `project` is built by `ProjectSerializer::toArray()` inside a
            // conditional, so it arrives here already resolved and needs the same patch.
            if ($serializer === InterviewSerializer::class && $item->key === 'project') {
                $this->typeProjectCompetencies($item->value);

                // Only present with `expand=project`; Scramble reads the key off the conditional
                // assignment as always present, so the SDKs would type it required.
                $item->isOptional = true;
            }
        }

        return $resolved;
    }

    private function typeProjectCompetencies(Type $type): void
    {
        if ($type instanceof Union) {
            foreach ($type->types as $member) {
                $this->typeProjectCompetencies($member);
            }

            return;
        }

        if (! $type instanceof KeyedArrayType) {
            return;
        }

        foreach ($type->items as $item) {
            if ($item->key === 'competencies') {
                $item->value = $this->competenciesType();
            }
        }
    }

    private function override(string $serializer, string $key): ?Type
    {
        return match ([$serializer, $key]) {
            [InterviewSerializer::class, 'hosted_url'] => new Union([new StringType, new NullType]),
            [InterviewSerializer::class, 'metadata'] => new StringMapType(value: new StringType, key: new StringType),
            [InterviewSerializer::class, 'progress'] => $this->progressType(),
            [OrganizationSerializer::class, 'allowed_domains'] => new ArrayType(value: new StringType),
            [ProjectSerializer::class, 'competencies'] => $this->competenciesType(),
            default => null,
        };
    }

    private function competenciesType(): ArrayType
    {
        return new ArrayType(value: new KeyedArrayType([
            new ArrayItemType_('code', new StringType),
            new ArrayItemType_('name', new StringType),
            new ArrayItemType_('type', new StringType),
        ]));
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
