<?php

declare(strict_types=1);

/**
 * db-driven-conversation-prompts PR7: `beai:prompt-set:dump-baseline` writes the
 * baseline prompt set to `database/prompt-sets/<label>.json` FROM
 * `BaselinePromptFragments`, in the format `beai:prompt-set:publish` reads.
 *
 * The committed `baseline-1.json` is a FROZEN artefact: the bootstrap migration
 * stores exactly its bytes. The last test fails the moment a baseline literal
 * changes without a new label, which is the point.
 */

use App\Enums\PromptFragmentKey;
use App\Support\Conversation\BaselinePromptFragments;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/dump-baseline-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/**
 * @param  array<string, mixed>  $options
 * @return array{int, string}
 */
function dumpBaseline(string $dir, array $options = []): array
{
    $code = Artisan::call('beai:prompt-set:dump-baseline', ['--directory' => $dir] + $options);

    return [$code, Artisan::output()];
}

test('it writes the file in the publish format, equal to the baseline for en and it', function (): void {
    [$code] = dumpBaseline($this->dir);

    $data = json_decode((string) file_get_contents($this->dir.'/baseline-1.json'), true, 16, JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and($data['label'])->toBe('baseline-1')
        ->and($data['overrides'])->toBe([])
        ->and($data['notes'])->toBeString()->not->toBe('')
        ->and(array_keys($data['fragments']))->toBe(['en', 'it'])
        ->and($data['fragments']['en'])->toEqual(BaselinePromptFragments::forLocale('en'))
        ->and($data['fragments']['it'])->toEqual(BaselinePromptFragments::forLocale('it'))
        ->and($data['fragments']['it'])->toBe($data['fragments']['en'])
        ->and(array_keys($data['fragments']['en']))->toHaveCount(count(PromptFragmentKey::cases()));
});

test('every fragment key is written in a fixed sorted order', function (): void {
    dumpBaseline($this->dir);

    $data = json_decode((string) file_get_contents($this->dir.'/baseline-1.json'), true, 16, JSON_THROW_ON_ERROR);
    $keys = array_keys($data['fragments']['en']);
    $sorted = $keys;
    sort($sorted, SORT_STRING);

    expect($keys)->toBe($sorted);
});

test('the file is byte-stable: a second run writes the same bytes', function (): void {
    dumpBaseline($this->dir);
    $first = (string) file_get_contents($this->dir.'/baseline-1.json');

    [$again, $output] = dumpBaseline($this->dir);
    $other = $this->dir.'-other';
    dumpBaseline($other);

    expect($again)->toBe(0)
        ->and($output)->toContain('already up to date')
        ->and((string) file_get_contents($this->dir.'/baseline-1.json'))->toBe($first)
        ->and((string) file_get_contents($other.'/baseline-1.json'))->toBe($first)
        ->and($first)->toEndWith("}\n");

    File::deleteDirectory($other);
});

test('it refuses to overwrite a file whose content differs and leaves it untouched', function (): void {
    File::ensureDirectoryExists($this->dir);
    file_put_contents($this->dir.'/baseline-1.json', '{"label":"baseline-1"}');

    [$code, $output] = dumpBaseline($this->dir);

    expect($code)->not->toBe(0)
        ->and($output)->toContain('already exists')
        ->and((string) file_get_contents($this->dir.'/baseline-1.json'))->toBe('{"label":"baseline-1"}');
});

test('the label picks the file name and is stored in the file', function (): void {
    [$code] = dumpBaseline($this->dir, ['--label' => 'baseline-2']);

    $data = json_decode((string) file_get_contents($this->dir.'/baseline-2.json'), true, 16, JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and($data['label'])->toBe('baseline-2')
        ->and(is_file($this->dir.'/baseline-1.json'))->toBeFalse();
});

test('a label that is not a safe file name is refused and nothing is written', function (string $label): void {
    [$code] = dumpBaseline($this->dir, ['--label' => $label]);

    expect($code)->not->toBe(0)
        ->and(is_dir($this->dir))->toBeFalse();
})->with(['../escape', 'with space', '', str_repeat('a', 65), '.hidden']);

test('the committed baseline-1.json is exactly what the baseline produces today', function (): void {
    dumpBaseline($this->dir);

    expect(is_file(database_path('prompt-sets/baseline-1.json')))->toBeTrue()
        ->and((string) file_get_contents(database_path('prompt-sets/baseline-1.json')))
        ->toBe((string) file_get_contents($this->dir.'/baseline-1.json'));
});

test('a directory that cannot be created fails and says so', function (): void {
    File::ensureDirectoryExists($this->dir);
    file_put_contents($this->dir.'/a-file', 'x');

    [$code, $output] = dumpBaseline($this->dir.'/a-file/nested');

    expect($code)->not->toBe(0)
        ->and($output)->toContain('cannot be created')
        ->and(is_dir($this->dir.'/a-file/nested'))->toBeFalse();
});

test('a file that cannot be written fails and says so', function (): void {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('root ignores directory permissions, so a read-only directory cannot refuse the write.');
    }

    File::ensureDirectoryExists($this->dir);
    chmod($this->dir, 0555);

    try {
        [$code, $output] = dumpBaseline($this->dir);
    } finally {
        chmod($this->dir, 0755);
    }

    expect($code)->not->toBe(0)
        ->and($output)->toContain('cannot be written')
        ->and(is_file($this->dir.'/baseline-1.json'))->toBeFalse();
});
