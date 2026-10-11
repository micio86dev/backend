<?php

declare(strict_types=1);

use App\Models\ConversationPromptFragment;
use App\Models\ConversationPromptOverride;
use App\Models\ConversationPromptSet;
use App\Models\TenantModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\Conversation\PromptTables;

uses(RefreshDatabase::class);

beforeEach(fn () => PromptTables::empty());

test('the prompt set models are plain Eloquent models, never tenant scoped', function (string $model, string $table): void {
    $instance = new $model;

    expect($instance)->not->toBeInstanceOf(TenantModel::class)
        ->and($instance->getTable())->toBe($table);
})->with([
    [ConversationPromptSet::class, 'conversation_prompt_sets'],
    [ConversationPromptFragment::class, 'conversation_prompt_fragments'],
    [ConversationPromptOverride::class, 'conversation_prompt_overrides'],
]);

test('a set exposes its fragments and overrides, and children carry created_at only', function (): void {
    $set = ConversationPromptSet::query()->create([
        'label' => 'baseline',
        'content_sha256' => str_repeat('a', 64),
    ]);
    $set->fragments()->create(['fragment_key' => 'frame.header', 'locale' => 'en', 'body' => 'Competency {{competency_code}}']);
    $set->overrides()->create(['role_code' => null, 'competency_code' => 'COL', 'locale' => 'en', 'body' => 'Probe.']);

    $set = $set->fresh();
    $fragment = $set->fragments()->firstOrFail();

    expect($set->is_active)->toBeFalse()
        ->and($set->activated_at)->toBeNull()
        ->and($set->fragments)->toHaveCount(1)
        ->and($set->overrides)->toHaveCount(1)
        ->and($fragment->created_at)->not->toBeNull()
        ->and($fragment->promptSet->is($set))->toBeTrue()
        ->and($fragment->getAttributes())->not->toHaveKey('updated_at')
        ->and($set->overrides->first()->role_code)->toBeNull();
});

test('activating a set through the model is the one write the immutability trigger allows', function (): void {
    $set = ConversationPromptSet::query()->create(['label' => 'baseline', 'content_sha256' => str_repeat('a', 64)]);

    $set->forceFill(['is_active' => true, 'activated_at' => now()])->save();

    expect(ConversationPromptSet::query()->where('is_active', true)->value('label'))->toBe('baseline')
        ->and($set->fresh()->activated_at)->not->toBeNull();
});
