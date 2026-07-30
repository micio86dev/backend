<?php

declare(strict_types=1);
use App\Support\Notifications\OperatorRecipientResolver;

/**
 * Architecture backstop: recipient queries live in exactly one class (C12, D2).
 *
 * This is deliberately the BACKSTOP, not the mechanism. The mechanism is
 * OperatorRecipientResolver::forOrganization(int $organizationId) — a mandatory,
 * non-nullable, un-defaulted argument, so omitting the tenant filter is a
 * PHPStan L8 type error rather than a cross-tenant disclosure delivered by
 * email. An arch test can only say "you wrote a forbidden string"; it cannot
 * say which filter would have been correct.
 *
 * It is still worth having, because the failure it catches is someone
 * hand-rolling a recipient query somewhere else and getting the org filter
 * subtly wrong — which no type system will notice.
 */
test('user-role recipient queries appear only in OperatorRecipientResolver', function (): void {
    $allowed = 'app/Support/Notifications/OperatorRecipientResolver.php';

    $forbidden = [
        // Spatie's role scope on the User model, anywhere else.
        'User::query()->role(',
        'User::role(',
    ];

    $violations = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

        if ($relative === $allowed) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        foreach ($forbidden as $needle) {
            if (str_contains($source, $needle)) {
                $violations[] = $relative.' contains '.$needle;
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "Recipient resolution must go through OperatorRecipientResolver:\n  - %s",
        implode("\n  - ", $violations)
    ));
});

test('the resolver requires an organization id that cannot be omitted or nulled', function (): void {
    // The mechanism itself, asserted rather than assumed. If someone ever adds
    // a default or widens the type to ?int, the compile-time guarantee this
    // whole design rests on quietly becomes a runtime hazard.
    $method = new ReflectionMethod(
        OperatorRecipientResolver::class,
        'forOrganization'
    );

    expect($method->getNumberOfRequiredParameters())->toBe(1);

    $param = $method->getParameters()[0];
    expect($param->isDefaultValueAvailable())->toBeFalse();

    $type = $param->getType();
    expect($type)->toBeInstanceOf(ReflectionNamedType::class);
    /** @var ReflectionNamedType $type */
    expect($type->getName())->toBe('int');
    expect($type->allowsNull())->toBeFalse();
});
