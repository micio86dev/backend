<?php

declare(strict_types=1);

/**
 * Architecture guards for reusable interview links (reusable-interview-links
 * B1a.6, design AD-1 / AD-5).
 *
 * These protect conventions rather than behaviour, so they fail when a FUTURE
 * change breaks the convention even though every behavioural test still passes:
 * - the model is a `TenantModel` (admin reads can never forget an org filter)
 * - `token_hash` is hidden and not mass-assignable
 * - the token generator draws its randomness from `random_bytes()` and nothing
 *   else: a weaker source would not show up in any functional test
 * - the tenant scope may be lifted off this model only by the public redemption
 *   action, which has to look a link up by hash before any tenant is known
 */

use App\Models\ReusableInterviewLink;
use App\Models\TenantModel;
use App\Services\ReusableLinkTokenGenerator;
use Symfony\Component\Finder\Finder;

/**
 * The executable part of a PHP source: comments and docblocks removed. A class
 * doc legitimately NAMES the things these guards ban, and a name in prose is
 * not a use.
 */
function reusableLinkArchCode(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

arch('ReusableInterviewLink is a TenantModel')
    ->expect(ReusableInterviewLink::class)
    ->toExtend(TenantModel::class);

test('token_hash is hidden from serialisation and not mass-assignable', function (): void {
    $model = new ReusableInterviewLink;

    expect($model->getHidden())->toContain('token_hash');
    expect($model->getFillable())->not->toContain('token_hash');
    expect($model->isFillable('token_hash'))->toBeFalse();
});

test('the token generator draws randomness only from random_bytes()', function (): void {
    $source = (string) file_get_contents((new ReflectionClass(ReusableLinkTokenGenerator::class))->getFileName());

    $code = reusableLinkArchCode($source);

    expect($code)->toContain('random_bytes(');

    foreach (['rand(', 'mt_rand(', 'random_int(', 'uniqid(', 'Str::random(', 'Str::uuid(', 'Str::ulid(', 'shuffle(', 'lcg_value(', 'time(', 'microtime(', 'hrtime(', 'now(', 'Carbon'] as $banned) {
        expect($code)->not->toContain($banned);
    }
});

test('the tenant scope is lifted off ReusableInterviewLink only by the redemption action', function (): void {
    $offenders = [];

    // `withoutGlobalScope(s)` is called on the model, or on a query built from
    // it, so a per-file scan for the model's name next to the call is the
    // strongest portable check. Vacuous until the redemption action exists:
    // it then pins the ONE place allowed to do it.
    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $source = reusableLinkArchCode($file->getContents());

        if (! str_contains($source, 'ReusableInterviewLink')) {
            continue;
        }

        if (! preg_match('/withoutGlobalScopes?\(/', $source)) {
            continue;
        }

        if ($file->getRelativePathname() !== 'Actions/ReusableLinks/RedeemReusableInterviewLink.php') {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
