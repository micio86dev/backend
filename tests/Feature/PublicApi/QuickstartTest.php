<?php

declare(strict_types=1);

/**
 * The developer quickstart is itself a test (public-api SPEC.md §6, step 11):
 * every fenced block in `docs/quickstart.md` tagged `quickstart-step=N` is
 * parsed and executed, in order, through the Laravel HTTP test client against
 * a TEST-mode organization. The run must end with the interview `completed`
 * and its evaluation readable — so a snippet with a wrong path, header, body
 * field or capture breaks the build instead of misleading an integrator.
 *
 * Block format (info string after ```http):
 *   quickstart-step=N expect=<status> [capture=json.path:PLACEHOLDER,...] [assert=json.path=value,...]
 * Body: `METHOD /path`, header lines, blank line, optional JSON body.
 * Placeholders `{{NAME}}` are substituted from `{{API_KEY}}`/`{{PROJECT_ID}}`
 * (fixtures) and from earlier steps' `capture=` values. Paths are relative to
 * the `/api` mount, exactly as the public docs show them.
 */

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\AvatarTemplate;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\Role;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return list<array{step: int, expect: int, capture: array<string, string>, assert: array<string, string>, method: string, path: string, headers: array<string, string>, body: array<string, mixed>|null}>
 */
function quickstartSteps(string $markdown): array
{
    preg_match_all('/```http\s+quickstart-step=(\d+)([^\n]*)\n(.*?)\n```/s', $markdown, $blocks, PREG_SET_ORDER);

    $steps = [];

    foreach ($blocks as [, $number, $attributes, $snippet]) {
        preg_match_all('/(\w+)=(\S+)/', $attributes, $pairs, PREG_SET_ORDER);
        $attrs = [];
        foreach ($pairs as [, $key, $value]) {
            $attrs[$key] = $value;
        }

        [$head, $rawBody] = array_pad(explode("\n\n", $snippet, 2), 2, '');
        $lines = explode("\n", trim($head));
        [$method, $path] = explode(' ', array_shift($lines), 2);

        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }

        $keyValueMap = static function (?string $raw, string $separator): array {
            $map = [];
            foreach (array_filter(explode(',', (string) $raw)) as $entry) {
                [$key, $value] = explode($separator, $entry, 2);
                $map[$key] = $value;
            }

            return $map;
        };

        $steps[] = [
            'step' => (int) $number,
            'expect' => (int) ($attrs['expect'] ?? 200),
            'capture' => $keyValueMap($attrs['capture'] ?? null, ':'),
            'assert' => $keyValueMap($attrs['assert'] ?? null, '='),
            'method' => $method,
            'path' => $path,
            'headers' => $headers,
            'body' => trim($rawBody) === '' ? null : json_decode(trim($rawBody), true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    usort($steps, static fn (array $a, array $b): int => $a['step'] <=> $b['step']);

    return $steps;
}

function quickstartSubstitute(mixed $value, array $vars): mixed
{
    if (is_string($value)) {
        return preg_replace_callback('/\{\{(\w+)\}\}/', static function (array $m) use ($vars): string {
            expect(array_key_exists($m[1], $vars))
                ->toBeTrue('quickstart placeholder {{'.$m[1].'}} is used before any step captures it');

            return (string) $vars[$m[1]];
        }, $value);
    }

    if (is_array($value)) {
        return array_map(static fn (mixed $item): mixed => quickstartSubstitute($item, $vars), $value);
    }

    return $value;
}

function quickstartProject(Organization $org): Project
{
    return TenantContextScope::runFor($org->id, function () use ($org): Project {
        $avatarTemplate = AvatarTemplate::create(['name' => 'Quickstart avatar', 'provider' => 'heygen', 'config' => []]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'avatar_template_id' => $avatarTemplate->id,
            'status' => 'active',
        ]);

        $role = Role::factory()->create(['code' => $project->role_code]);
        $competency = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'position' => 0,
        ]);

        $indicator = new BarsIndicator;
        $indicator->forceFill([
            'role_id' => $role->id,
            'competency_id' => $competency->id,
            'text' => ['en' => 'quickstart indicator'],
            'anchor_5' => ['en' => 'Excellent'],
            'anchor_3' => ['en' => 'Adequate'],
            'anchor_1' => ['en' => 'Insufficient'],
            'position' => 0,
        ]);
        $indicator->save();

        ProjectQuestion::create([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'text' => ['en' => 'quickstart question'],
            'position' => 0,
        ]);

        return $project;
    });
}

test('the quickstart snippets run against a test-mode organization and reach completed', function (): void {
    $docsDir = dirname(base_path()).'/docs';
    $quickstart = $docsDir.'/quickstart.md';

    if (! is_dir($docsDir)) {
        test()->markTestSkipped('The wrapper docs/ directory is unreachable: api is checked out on its own.');
    }

    expect(is_file($quickstart))->toBeTrue('docs/quickstart.md is missing');

    $steps = quickstartSteps((string) file_get_contents($quickstart));
    expect($steps)->not->toBeEmpty('docs/quickstart.md declares no quickstart-step blocks');

    config(['interview.candidate_app_url' => 'https://interview.example.com']);

    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Test);
    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'abilities' => ['interviews:write', 'interviews:read'],
    ]);
    $project = quickstartProject($org);

    Http::fake();

    $vars = ['API_KEY' => $rawKey, 'PROJECT_ID' => PublicId::encode($project)];
    $lastResponse = null;

    foreach ($steps as $step) {
        $headers = quickstartSubstitute($step['headers'], $vars);
        $body = quickstartSubstitute($step['body'], $vars);
        $path = quickstartSubstitute($step['path'], $vars);

        $lastResponse = test()->withHeaders($headers)->json($step['method'], '/api'.$path, $body ?? []);

        expect($lastResponse->getStatusCode())
            ->toBe($step['expect'], "step {$step['step']} ({$step['method']} {$step['path']}) returned {$lastResponse->getStatusCode()}: {$lastResponse->getContent()}");

        foreach ($step['capture'] as $jsonPath => $placeholder) {
            $captured = $lastResponse->json($jsonPath);
            expect($captured)->not->toBeNull("step {$step['step']} response has no `{$jsonPath}` to capture");
            $vars[$placeholder] = $captured;
        }

        foreach ($step['assert'] as $jsonPath => $expected) {
            $actual = Arr::get($lastResponse->json(), $jsonPath);
            expect(is_bool($actual) ? ($actual ? 'true' : 'false') : (string) $actual)
                ->toBe($expected, "step {$step['step']} expected `{$jsonPath}` to be `{$expected}`");
        }
    }

    Http::assertNothingSent();
    expect($lastResponse?->json('competencies'))->not->toBeEmpty();
});
