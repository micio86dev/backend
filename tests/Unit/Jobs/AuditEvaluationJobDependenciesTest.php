<?php

declare(strict_types=1);

/**
 * RED — P3a.1: AuditEvaluationJob's dependencies (scoring-audit-jev, design
 * D2/D12). The job depends on `App\Contracts\AuditJudge`, never on
 * `LLMProvider` — mirrors the scoring spec's own "The audit call site never
 * constructs an LLMProvider prompt string" requirement, proven here at the
 * type-reflection level rather than only by behaviour.
 */

use App\Contracts\AuditJudge;
use App\Contracts\LLMProvider;
use App\Jobs\AuditEvaluationJob;
use Illuminate\Contracts\Queue\ShouldQueue;

test('the constructor signature is (int $evaluationId, ?int $requestedByUserId = null, ?string $lockOwner = null)', function (): void {
    $reflection = new ReflectionClass(AuditEvaluationJob::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();

    expect($params)->toHaveCount(3)
        ->and($params[0]->getName())->toBe('evaluationId')
        ->and((string) $params[0]->getType())->toBe('int')
        ->and($params[1]->getName())->toBe('requestedByUserId')
        ->and((string) $params[1]->getType())->toBe('?int')
        ->and($params[1]->isOptional())->toBeTrue()
        ->and($params[2]->getName())->toBe('lockOwner')
        ->and((string) $params[2]->getType())->toBe('?string')
        ->and($params[2]->isOptional())->toBeTrue();
});

test('the handle() method depends on AuditJudge, never on LLMProvider', function (): void {
    $reflection = new ReflectionClass(AuditEvaluationJob::class);
    $handle = $reflection->getMethod('handle');

    $paramTypes = array_map(
        fn (ReflectionParameter $p): string => (string) $p->getType(),
        $handle->getParameters()
    );

    expect($paramTypes)->toContain(AuditJudge::class)
        ->and($paramTypes)->not->toContain(LLMProvider::class);
});

test('no property of the job is typed LLMProvider', function (): void {
    $reflection = new ReflectionClass(AuditEvaluationJob::class);

    foreach ($reflection->getProperties() as $property) {
        $type = $property->getType();
        expect($type === null ? '' : (string) $type)->not->toBe(LLMProvider::class);
    }
});

test('AuditEvaluationJob implements ShouldQueue and declares its own $tries and $timeout', function (): void {
    $reflection = new ReflectionClass(AuditEvaluationJob::class);

    expect($reflection->implementsInterface(ShouldQueue::class))->toBeTrue()
        ->and($reflection->getProperty('tries')->getDeclaringClass()->getName())->toBe(AuditEvaluationJob::class)
        ->and($reflection->getProperty('timeout')->getDeclaringClass()->getName())->toBe(AuditEvaluationJob::class);

    $instance = $reflection->newInstanceWithoutConstructor();

    expect($instance->tries)->toBe(1)
        // C-F/D7: 18 x 30s x 1.1 = 594 -> 600. Must stay strictly below
        // queue.runtime.worker_timeout (1260, config/queue.php).
        ->and($instance->timeout)->toBe(600);
});
