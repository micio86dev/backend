<?php

declare(strict_types=1);

/**
 * POST /api/reusable-links/redeem (reusable-link-visitor-identity, api-1b): what
 * a redemption stores of the identity a visitor typed.
 *
 * The name and the email are the visitor's own, self-declared and not verified.
 * They are trimmed (the platform's `TrimStrings`, exactly as on every other
 * enrolment path) and the email is lower-cased, while the link token keeps ONE
 * spelling: a token padded with whitespace is not the token.
 *
 * Validation failures (422) are in `RedeemIdentityValidationTest`; the refusals
 * that depend on the token alone are in `RedeemRefusalTest`.
 *
 * REQ: Identity Input Is Trimmed And The Email Is Normalized, Visitors Are
 *      Identified By A Self-Declared Name And Email, Each Redemption Creates One
 *      Fresh Visitor Participant
 *      (sdd/reusable-link-visitor-identity/spec/reusable-interview-links)
 */

use App\Enums\ApiKeyMode;
use App\Events\ParticipantCreated;
use App\Models\ReusableInterviewLink;
use App\Support\Participant\PlaceholderEmail;
use Illuminate\Support\Facades\Event;
use Tests\Helpers\ReusableLinkFixtures as Fx;

beforeEach(function (): void {
    config([
        'reusable_links.redeem.per_ip_per_minute' => 1000,
        'reusable_links.redeem.per_link_per_hour' => 1000,
    ]);
});

// ─── Normalisation ───────────────────────────────────────────────────────────

test('the stored name and email are the trimmed name and the trimmed lower-cased email', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('  Ada.Lovelace@Example.COM ', '  Ada Lovelace  ')))->assertOk();

    $visitor = Fx::visitorsOf($link)[0];
    expect($visitor->display_name)->toBe('Ada Lovelace')
        ->and($visitor->email)->toBe('ada.lovelace@example.com');
});

test('the name is stored verbatim: its case, its inner spaces and its markup are never altered', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();
    $name = "Zoe  O'Brien-Zizek <b>";

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('zoe@example.test', $name)))->assertOk();

    expect(Fx::visitorsOf($link)[0]->display_name)->toBe($name);
});

test('a non-ASCII address is lower-cased as a whole', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token, Fx::identity('ÀNA@Example.IT', 'Ana')))->assertOk();

    expect(Fx::visitorsOf($link)[0]->email)->toBe('àna@example.it');
});

// ─── The token keeps one spelling ────────────────────────────────────────────

test('a token padded with whitespace is not the token: the generic 404 beside a valid identity, and nothing is created', function (string $padding): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();
    $padded = str_replace('{token}', $token, $padding);

    $response = $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($padded));

    $response->assertNotFound();
    expect($response->getContent())->toBe(Fx::NOT_FOUND_BODY)
        ->and(Fx::visitorsOf($link))->toBe([])
        ->and(ReusableInterviewLink::withoutGlobalScopes()->findOrFail($link->id)->uses_count)->toBe(0);
})->with([
    'a trailing newline' => "{token}\n",
    'surrounding spaces' => '  {token}  ',
    'a leading tab' => "\t{token}",
]);

// ─── What is created ─────────────────────────────────────────────────────────

test('the visitor carries the typed identity and no placeholder address and no numbered name, whatever the link label', function (?string $label): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable(linkAttributes: ['label' => $label]);

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();
    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();

    foreach (Fx::visitorsOf($link) as $visitor) {
        expect($visitor->email)->not->toEndWith(PlaceholderEmail::DOMAIN)
            ->and($visitor->display_name)->not->toContain(' #')
            ->and($visitor->candidate_ref)->toMatch('/^rlv_[0-9A-Za-z]{26}$/');
    }
})->with(['a labelled link' => 'Milan fair stand', 'an unlabelled link' => null]);

test('every column the identity does not decide is unchanged', function (): void {
    ['org' => $org, 'project' => $project, 'link' => $link, 'token' => $token] = Fx::redeemable(
        linkAttributes: ['lang' => 'it'],
    );

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();

    $visitor = Fx::visitorsOf($link)[0];
    expect($visitor->language)->toBe('it')
        ->and($visitor->reusable_interview_link_id)->toBe($link->id)
        ->and($visitor->mode)->toBe(ApiKeyMode::Live)
        ->and($visitor->status)->toBe('in_attesa')
        ->and($visitor->role_code)->toBe($project->role_code)
        ->and($visitor->scheduling_status)->toBeNull()
        ->and($visitor->organization_id)->toBe($org->id)
        ->and($visitor->project_id)->toBe($project->id);
});

test('ParticipantCreated is dispatched once, with the visitor id', function (): void {
    ['link' => $link, 'token' => $token] = Fx::redeemable();
    Event::fake([ParticipantCreated::class]);

    $this->postJson(Fx::REDEEM_URL, Fx::redeemBody($token))->assertOk();

    $visitor = Fx::visitorsOf($link)[0];
    Event::assertDispatchedTimes(ParticipantCreated::class, 1);
    Event::assertDispatched(ParticipantCreated::class, fn (ParticipantCreated $event): bool => $event->participantId === $visitor->id);
});
