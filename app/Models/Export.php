<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyMode;
use App\Enums\ExportFormat;
use App\Enums\ExportScope;
use App\Enums\ExportStatus;
use App\Models\Concerns\HasPublicId;
use App\Support\PublicApi\PubliclyIdentifiable;
use Database\Factories\ExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped async bulk-export job row (public-api step 8, SPEC.md §3.3
 * "Exports", `openapi.yaml`'s `Export` schema).
 *
 * `organization_id` is auto-stamped by `TenantScoped::creating` and MUST NOT
 * be `$fillable` — same discipline as `WebhookDelivery`'s own docblock.
 * `mode` IS `$fillable` (unlike `organization_id`) because nothing stamps it
 * automatically: `App\Http\Controllers\PublicApi\ExportController::store()`
 * is the only caller that ever creates a row, and it always sets `mode`
 * explicitly from the requesting key's own `App\Support\PublicApi\ApiMode`
 * — never from raw request input (`CreateExportRequest`'s validated body has
 * no `mode` field at all), so there is no mass-assignment surface this
 * needs protecting against.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organization_id
 * @property ApiKeyMode $mode
 * @property ExportScope $scope
 * @property ExportFormat $format
 * @property Carbon|null $from_at
 * @property Carbon|null $to_at
 * @property bool $include_transcripts
 * @property bool $include_scoring
 * @property bool $include_audio
 * @property ExportStatus $status
 * @property int|null $record_count
 * @property string|null $object_key
 * @property int|null $size_bytes
 * @property string|null $checksum_sha256
 * @property string|null $failure_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $completed_at
 */
class Export extends TenantModel implements PubliclyIdentifiable
{
    /** @use HasFactory<ExportFactory> */
    use HasFactory;

    use HasPublicId;

    /**
     * organization_id is intentionally excluded — mode IS fillable, see
     * class doc.
     *
     * @var list<string>
     */
    protected $fillable = [
        'mode',
        'scope',
        'format',
        'from_at',
        'to_at',
        'include_transcripts',
        'include_scoring',
        'include_audio',
        'status',
        'record_count',
        'object_key',
        'size_bytes',
        'checksum_sha256',
        'failure_reason',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ApiKeyMode::class,
            'scope' => ExportScope::class,
            'format' => ExportFormat::class,
            'status' => ExportStatus::class,
            'from_at' => 'datetime',
            'to_at' => 'datetime',
            'include_transcripts' => 'boolean',
            'include_scoring' => 'boolean',
            'include_audio' => 'boolean',
            'record_count' => 'integer',
            'size_bytes' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public static function publicIdPrefix(): string
    {
        return 'exp_';
    }
}
