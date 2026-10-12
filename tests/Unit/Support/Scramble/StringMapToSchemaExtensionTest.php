<?php

declare(strict_types=1);

use App\Support\Scramble\StringMapToSchemaExtension;
use App\Support\Scramble\StringMapType;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\StringType;

test('only a StringMapType is exported as a string map; a plain string-keyed array keeps its default mapping', function (): void {
    $extension = (new ReflectionClass(StringMapToSchemaExtension::class))->newInstanceWithoutConstructor();

    expect($extension->shouldHandle(new StringMapType(value: new StringType, key: new StringType)))->toBeTrue();
    expect($extension->shouldHandle(new ArrayType(value: new StringType, key: new StringType)))->toBeFalse();
});
