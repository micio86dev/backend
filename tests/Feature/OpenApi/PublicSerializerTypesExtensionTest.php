<?php

declare(strict_types=1);

/**
 * Direct tests of `PublicSerializerTypesExtension::patched()`: the pure type surgery behind the
 * extension, with hand-built Scramble types instead of a full document export. The export-level
 * behaviour is pinned by `PublicApiScrambleOverridesTest`; this file covers the branches an
 * export never reaches (a resolution that is not an array shape, a union-typed `project`, a
 * `project` that is not a shape at all) and proves the input type is never mutated.
 */

use App\PublicApi\Serializers\InterviewSerializer;
use App\PublicApi\Serializers\OrganizationSerializer;
use App\PublicApi\Serializers\ProjectSerializer;
use App\Support\Scramble\PublicSerializerTypesExtension;
use App\Support\Scramble\StringMapType;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Union;

function itemValue(KeyedArrayType $shape, string $key): mixed
{
    foreach ($shape->items as $item) {
        if ($item->key === $key) {
            return $item->value;
        }
    }

    return null;
}

function untypedCompetencies(): ArrayType
{
    return new ArrayType;
}

test('shouldHandle accepts exactly the three public serializers', function (): void {
    $extension = new PublicSerializerTypesExtension;

    expect($extension->shouldHandle(InterviewSerializer::class))->toBeTrue();
    expect($extension->shouldHandle(OrganizationSerializer::class))->toBeTrue();
    expect($extension->shouldHandle(ProjectSerializer::class))->toBeTrue();
    expect($extension->shouldHandle(stdClass::class))->toBeFalse();
});

test('patched answers null for a resolution that is not an array shape', function (): void {
    $extension = new PublicSerializerTypesExtension;

    expect($extension->patched(new StringType, InterviewSerializer::class))->toBeNull();
});

test('patched types the entries of each serializer and leaves the others alone', function (): void {
    $extension = new PublicSerializerTypesExtension;

    $interview = $extension->patched(new KeyedArrayType([
        new ArrayItemType_('id', new StringType),
        new ArrayItemType_('hosted_url', new NullType),
        new ArrayItemType_('metadata', new ArrayType),
        new ArrayItemType_('progress', new StringType),
    ]), InterviewSerializer::class);
    expect(itemValue($interview, 'hosted_url'))->toBeInstanceOf(Union::class);
    expect(array_map(fn ($type): string => $type::class, itemValue($interview, 'hosted_url')->types))->toBe([StringType::class, NullType::class]);
    expect(itemValue($interview, 'metadata'))->toBeInstanceOf(StringMapType::class);
    expect(itemValue($interview, 'progress'))->toBeInstanceOf(ArrayType::class);
    expect(itemValue($interview, 'id'))->toBeInstanceOf(StringType::class);

    $organization = $extension->patched(new KeyedArrayType([
        new ArrayItemType_('allowed_domains', new ArrayType),
        new ArrayItemType_('name', new StringType),
    ]), OrganizationSerializer::class);
    expect(itemValue($organization, 'allowed_domains')->value)->toBeInstanceOf(StringType::class);
    expect(itemValue($organization, 'name'))->toBeInstanceOf(StringType::class);

    $project = $extension->patched(new KeyedArrayType([
        new ArrayItemType_('competencies', untypedCompetencies()),
    ]), ProjectSerializer::class);
    $competency = itemValue($project, 'competencies')->value;
    expect($competency)->toBeInstanceOf(KeyedArrayType::class);
    expect(array_map(fn (ArrayItemType_ $item): string => (string) $item->key, $competency->items))->toBe(['code', 'name', 'type']);
});

test('patched never mutates the type it was given', function (): void {
    $extension = new PublicSerializerTypesExtension;
    $original = new KeyedArrayType([new ArrayItemType_('allowed_domains', new ArrayType)]);

    $extension->patched($original, OrganizationSerializer::class);

    expect(itemValue($original, 'allowed_domains')->value)->not->toBeInstanceOf(StringType::class);
});

test('patched types the competencies of an expanded project, alone or inside a union', function (): void {
    $extension = new PublicSerializerTypesExtension;
    $shape = fn (): KeyedArrayType => new KeyedArrayType([
        new ArrayItemType_('name', new StringType),
        new ArrayItemType_('competencies', untypedCompetencies()),
    ]);

    $direct = $extension->patched(new KeyedArrayType([new ArrayItemType_('project', $shape())]), InterviewSerializer::class);
    expect(itemValue(itemValue($direct, 'project'), 'competencies')->value)->toBeInstanceOf(KeyedArrayType::class);

    $union = $extension->patched(new KeyedArrayType([
        new ArrayItemType_('project', new Union([$shape(), new NullType])),
    ]), InterviewSerializer::class);
    $members = itemValue($union, 'project')->types;
    expect(itemValue($members[0], 'competencies')->value)->toBeInstanceOf(KeyedArrayType::class);
    expect($members[1])->toBeInstanceOf(NullType::class);
});

test('patched leaves a project that is not an array shape untouched', function (): void {
    $extension = new PublicSerializerTypesExtension;

    $result = $extension->patched(new KeyedArrayType([
        new ArrayItemType_('project', new IntegerType),
    ]), InterviewSerializer::class);

    expect(itemValue($result, 'project'))->toBeInstanceOf(IntegerType::class);
});

test('patched ignores a project shape that has no competencies entry', function (): void {
    $extension = new PublicSerializerTypesExtension;

    $result = $extension->patched(new KeyedArrayType([
        new ArrayItemType_('project', new KeyedArrayType([new ArrayItemType_('name', new StringType)])),
    ]), InterviewSerializer::class);

    expect(itemValue(itemValue($result, 'project'), 'name'))->toBeInstanceOf(StringType::class);
});
