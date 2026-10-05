<?php

declare(strict_types=1);

/**
 * RED — scoring-retry-rt-b PR1a: the value types the authorization action (PR1b)
 * speaks in. They carry no behaviour of their own, so what is pinned here is
 * the CONTRACT other slices depend on: the machine values of the refusal reasons
 * (rendered as the 409 `reason` by PR3b and mapped to i18n keys by the
 * backoffice), the refusal carrying its reason, and the two actor shapes.
 *
 * REQ: participant-sso "Evaluation Retry Authorization" refusal guards and
 *      interim audit actor (specs/participant-sso/spec.md); design D4.
 */

use App\Actions\Participant\RetryActor;
use App\Actions\Participant\RetryAuthorization;
use App\Exceptions\Participant\EvaluationRetryRefusalReason;
use App\Exceptions\Participant\EvaluationRetryRefused;
use Illuminate\Support\Carbon;

test('the refusal reasons have exactly the five machine values, in the guard order', function (): void {
    expect(array_map(
        fn (EvaluationRetryRefusalReason $reason): string => $reason->value,
        EvaluationRetryRefusalReason::cases(),
    ))->toBe([
        'retry_already_consumed',
        'not_completed',
        'test_mode_participant',
        'evaluation_not_pending',
        'project_inaccessible',
    ]);
});

test('each refusal reason round-trips from its machine value', function (string $value, EvaluationRetryRefusalReason $reason): void {
    expect(EvaluationRetryRefusalReason::from($value))->toBe($reason);
    expect($reason->value)->toBe($value);
})->with([
    ['retry_already_consumed', EvaluationRetryRefusalReason::RetryAlreadyConsumed],
    ['not_completed', EvaluationRetryRefusalReason::NotCompleted],
    ['test_mode_participant', EvaluationRetryRefusalReason::TestModeParticipant],
    ['evaluation_not_pending', EvaluationRetryRefusalReason::EvaluationNotPending],
    ['project_inaccessible', EvaluationRetryRefusalReason::ProjectInaccessible],
]);

test('an unknown refusal reason is rejected, not silently mapped', function (): void {
    expect(fn () => EvaluationRetryRefusalReason::from('already_done'))->toThrow(ValueError::class);
    expect(EvaluationRetryRefusalReason::tryFrom('already_done'))->toBeNull();
});

test('EvaluationRetryRefused carries its reason and uses the machine value as its message', function (EvaluationRetryRefusalReason $reason): void {
    $refusal = new EvaluationRetryRefused($reason);

    expect($refusal->reason)->toBe($reason);
    expect($refusal->getMessage())->toBe($reason->value);
    expect($refusal)->toBeInstanceOf(RuntimeException::class);
})->with(EvaluationRetryRefusalReason::cases());

test('RetryActor::user() is an operator actor with no API client', function (): void {
    $actor = RetryActor::user(42);

    expect($actor->type)->toBe('user');
    expect($actor->userId)->toBe(42);
    expect($actor->apiClientId)->toBeNull();
});

test('RetryActor::user() tolerates an unresolved user id', function (): void {
    $actor = RetryActor::user(null);

    expect($actor->type)->toBe('user');
    expect($actor->userId)->toBeNull();
    expect($actor->apiClientId)->toBeNull();
});

test('RetryActor::apiClient() is an M2M actor with no user', function (): void {
    $actor = RetryActor::apiClient(7);

    expect($actor->type)->toBe('api_client');
    expect($actor->userId)->toBeNull();
    expect($actor->apiClientId)->toBe(7);
});

test('RetryActor can only be built through its named constructors', function (): void {
    $constructor = (new ReflectionClass(RetryActor::class))->getConstructor();

    expect($constructor?->isPrivate())->toBeTrue();
});

test('RetryAuthorization is an immutable carrier of the action result', function (): void {
    $expires = Carbon::parse('2026-10-06 09:00:00');
    $result = new RetryAuthorization('in_attesa', 'https://app.test/e?t=x', $expires, true, ['COM', 'INN']);

    expect($result->status)->toBe('in_attesa');
    expect($result->entryUrl)->toBe('https://app.test/e?t=x');
    expect($result->expiresAt)->toBe($expires);
    expect($result->emailSent)->toBeTrue();
    expect($result->competenciesReset)->toBe(['COM', 'INN']);
    expect((new ReflectionClass(RetryAuthorization::class))->isReadOnly())->toBeTrue();
});
