<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use App\PublicApi\Serializers\InterviewSerializer;
use Dedoc\Scramble\Infer\Extensions\Event\StaticMethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\StaticMethodReturnTypeExtension;
use Dedoc\Scramble\Infer\Services\ReferenceTypeResolver;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\Reference\StaticMethodCallReferenceType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;

/**
 * Documents `App\PublicApi\Serializers\InterviewSerializer::toArray()`'s
 * `hosted_url` entry as `string|null` in the exported OpenAPI schema (step 6
 * review follow-up, Part A item 4) — through DOCUMENTATION, never a runtime
 * branch.
 *
 * `hosted_url` is genuinely, unconditionally `null` on every plain read
 * (`InterviewSerializer::toArray()`'s own docblock: a read never has a
 * fresh session token to embed one for) — no reachable code path ever
 * returns a string. Scramble's schema inference for an array-literal VALUE
 * reads that value expression's own inferred type; a bare `null` literal
 * infers as the type `null` only, and neither an `@return` array-shape
 * docblock nor a typed `@var` local changes that — both were tried and
 * still empirically exported `type: "null"`, because
 * `JsonResourceTypeToSchema`/`ReferenceTypeResolver` trace the METHOD BODY,
 * never a docblock, whenever the body is itself traceable code (which this
 * one always is). The PREVIOUS approach worked around this with a
 * `public_api.interview_hosted_url_override` config key `hostedUrl()` read
 * from — a genuinely reachable branch existing ONLY to give the inferrer
 * something to widen from, never set in any real environment. Removed:
 * this extension documents the type directly instead.
 *
 * Scoped to EXACTLY the `InterviewSerializer::toArray` static call
 * (`shouldHandle()`/`$event->name`) — every other static call on every
 * other class is untouched, falling through to Scramble's own default
 * resolution (returning `null` here means "no override"). The override
 * itself resolves the call exactly as Scramble normally would (delegating
 * back into `ReferenceTypeResolver` with the SAME scope and arguments the
 * original call carried), then patches ONLY the `hosted_url` entry of the
 * resulting array shape — every OTHER key keeps exactly what normal
 * inference already produces correctly, so this extension can never drift
 * out of sync with the real fields the way a fully hand-written override
 * type would.
 *
 * Re-entrancy guard (`$resolving`): the delegated `ReferenceTypeResolver::
 * resolve()` call below re-walks the SAME `InterviewSerializer::toArray`
 * reference this extension is itself registered against — without the
 * guard, that inner resolution would immediately re-invoke this extension,
 * which would resolve again, forever. The guard makes the inner call see
 * "no override in progress" and fall through to Scramble's genuine default
 * (body-tracing) resolution, exactly once.
 */
final class InterviewHostedUrlNullableExtension implements StaticMethodReturnTypeExtension
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

        foreach ($resolved->items as $item) {
            if ($item->key === 'hosted_url') {
                $item->value = new Union([new StringType, new NullType]);
            }
        }

        return $resolved;
    }
}
