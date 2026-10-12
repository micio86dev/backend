<?php

declare(strict_types=1);

use App\Support\Scramble\StringMapToSchemaExtension;
use App\Support\Scramble\StringMapType;
use Dedoc\Scramble\Infer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\StringType;

test('only a StringMapType is exported as a string map; a plain string-keyed array keeps its default mapping', function (): void {
    $extension = (new ReflectionClass(StringMapToSchemaExtension::class))->newInstanceWithoutConstructor();

    expect($extension->shouldHandle(new StringMapType(value: new StringType, key: new StringType)))->toBeTrue();
    expect($extension->shouldHandle(new ArrayType(value: new StringType, key: new StringType)))->toBeFalse();
});

test('a StringMapType is exported as an object whose values are strings', function (): void {
    $infer = app(Infer::class);
    $context = new OpenApiContext(new OpenApi('3.1.0'), Scramble::getGeneratorConfig('v1'));
    $transformer = new TypeTransformer($infer, $context);
    $extension = new StringMapToSchemaExtension($infer, $transformer, new Components);

    $schema = $extension->toSchema(new StringMapType(value: new StringType, key: new StringType))->toArray();

    expect($schema['type'])->toBe('object');
    expect($schema['additionalProperties'])->toBe(['type' => 'string']);
});
