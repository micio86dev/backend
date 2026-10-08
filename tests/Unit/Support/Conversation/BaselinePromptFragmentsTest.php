<?php

declare(strict_types=1);

/**
 * BaselinePromptFragments is the code-side baseline of the 31 conversation
 * prompt fragments (db-driven-conversation-prompts, PR4b): today's literals,
 * stored trimmed, for `en` and `it` (the composer speaks English instructions
 * for every locale, so `it` is a verbatim copy of `en`).
 *
 * Its bytes are pinned end to end by the prompt goldens; this test pins the
 * shape: a complete key set per locale and a body that satisfies the contract.
 */

use App\DTOs\Conversation\PromptTemplateSet;
use App\Enums\PromptFragmentKey;
use App\Support\Conversation\BaselinePromptFragments;
use App\Support\Conversation\PromptFragmentContract;

test('every key of every baseline locale satisfies the placeholder contract', function (string $locale): void {
    $fragments = BaselinePromptFragments::forLocale($locale);
    $contract = new PromptFragmentContract;

    foreach (PromptFragmentKey::cases() as $key) {
        expect($fragments)->toHaveKey($key->value)
            ->and($contract->violations($key, $fragments[$key->value]))->toBe([], "{$locale} {$key->value}");
    }

    expect($fragments)->toHaveCount(count(PromptFragmentKey::cases()));
})->with(['en', 'it']);

test('the it baseline is a verbatim copy of the en baseline', function (): void {
    expect(BaselinePromptFragments::forLocale('it'))->toBe(BaselinePromptFragments::forLocale('en'));
});

test('a locale without its own rows falls back to the English baseline', function (): void {
    expect(BaselinePromptFragments::forLocale('fr'))->toBe(BaselinePromptFragments::forLocale('en'));
});

test('templateSet builds a complete, renderable set for the locale', function (): void {
    $set = BaselinePromptFragments::templateSet('it');

    expect($set)->toBeInstanceOf(PromptTemplateSet::class)
        ->and($set->render(PromptFragmentKey::Budget, ['budget' => 3]))->toBe('Ask at most 3 follow-up questions per competency.')
        ->and($set->render(PromptFragmentKey::Header, ['competency_code' => 'COL']))
        ->toBe('You are an adaptive interviewer conducting a BARS-based competency assessment for the [COL] competency.');
});

/*
 * The bytes of every baseline body, pinned by hash. The goldens pin the 15 keys the composer reads
 * today; this pins all 31, so the 16 keys that 4b-ii will start consuming cannot drift unnoticed.
 * Editing a body is a deliberate act: it changes the prompt, so regenerate the hash with the change.
 */
test('every baseline body keeps its pinned bytes', function (): void {
    $pinned = [
        'header' => '7552dfec76269dc34478ee92807656d93ac857c708033403370525bde0495e6d',
        'label.opening' => '49f1a116bac5048e0897085ac309ebe16ed7394b11f742d704588538c5b5009f',
        'label.coverage' => 'db5977a0c117f18e923ff9b17f0ff8ef3df6a0fcf0c29f2ddbcf841a693fb126',
        'label.override' => '2892eebcafafc9c2d2609b9a8004f0984c0191a89fe805df3318f5e745a7b00c',
        'label.star' => '38c93e4e359f5ecbd00e88e8ced7ba3178c0240f4f1f0be9833c338a13dad417',
        'label.follow_up' => 'a9a44f71184a4abefae5d3ff3aafa904c18e2ebe34a9c4caba0070899ec643bb',
        'label.nudge' => '0ae5fbc38eb87eca655c0860d7814ab31e6153a4676de8a857793f720cf0a963',
        'label.primary' => 'e4e3e214922b7abfbfe50f7b082e082f369288bcdfa3399048f5bc2e52c61a53',
        'label.advance' => '6b9d7533c1f0f8fad4810317e1287576fdfdd8d5b76df6b72a33079a841a20af',
        'star' => 'bb26757562e84a4be32eff59ed3e9dc2c0cd92c52dfd8dbfea1a1a5d5ca8cfc6',
        'budget' => '8ac8b8c222fdb34fc13412b9470f5bf499d83c01d3b48859f87f9da5d1a9c7ee',
        'nudge' => '942f804fc34e888b152f6c8086c073df16bbd13ebe7d767549ae4c547937a448',
        'opening.resumed_notice' => '2c0a53bd0219649c345097fa7c36aea02cd1580bec14db2459bc80b87a79bd32',
        'opening.fallback' => '5570329fa5590a934d98ca154e3b38f271dee3f0dd93a824e686106c87a7da4b',
        'opening.quoted' => '87bb12ca2162e831a82978a9c600dce6ecc04e2b58ab50109b078d617062a963',
        'opening.spoken_reask_all' => '9f50fa57d9e9ddc62530fcdd40a5b23050acf4c2f3ef135fc8484b683d8a441e',
        'opening.spoken_resumed' => 'b987e2f7c8c351570c7c51e94459650171487533252bcae712a7806c219efde2',
        'opening.spoken_fresh' => 'ea2ee353a51d75be55144f76aa7592be7cdf5ae93b7baf6b94fd68d88a22ac74',
        'opening.closing' => '5a15a12c8c5e18eb2ed0d1d3d174a52dff43e78cdc95cd5deffc96f708df987f',
        'primary.none' => 'c07710d1df824e3e5037b41baf2fadbdc54d7dac5711eedaa49fe0d457fe0acb',
        'primary.intro' => 'b51534720ea5c44f5ffa26095cf2833a8890ce401e9fcd4d415028f129e7ecfb',
        'primary.asked_before_one' => '7186f8c37fcd8d386fa4484d4d5cf6dbe7e27a14fc5ce5f2d8ddadcc7b47fd71',
        'primary.asked_before_many' => 'c83d5d6e8bfeb811e78464e08ada52374e27246612c1290facb72f8e4b93eb79',
        'primary.progress_all_asked' => '4668373446fafffb819246ee12b117d2288771bd5c120ebbc97e1a160be98161',
        'primary.progress_last' => 'c2613a9261ded8dd60a77d0f4e13dba9344645392fab42cc47d926de0e1a4f5f',
        'primary.progress_next' => 'e52b16ddd08ea540cc6b61879392e96a352e86e91819880d4551f13a9fc4d68e',
        'advance.floor_one' => 'f6918d4d0f4df2340fe488d60d3e822e298d84be652dfdfe1fb04cc1f41834b2',
        'advance.floor_many' => '8f5c67766b290a2c1de156d39366c8f63060237901aa0ee7c2c4021078589e37',
        'advance.floor_with_primaries' => 'd3455ee1b385310ab83b3aca6dd8403fa14f7cfcb3709acc00f47961ad6f94c6',
        'advance.with_phrase' => 'c98678e45672d701e3d7817ae0a3d137ec00e209714926370243e017b06362a0',
        'advance.without_phrase' => 'c7577710c14b2e2f87021266d8b00976d22a5699bf5a86d679a43d89421290df',
    ];

    $actual = array_map(
        static fn (string $body): string => hash('sha256', $body),
        BaselinePromptFragments::forLocale('en'),
    );

    expect($actual)->toBe($pinned);
});
