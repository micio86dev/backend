<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One third-party voice bound on LiveAvatar, keyed `(engine, provider_voice_id)`
 * (heygen-third-party-voices H2). The ledger that makes the non-idempotent
 * LiveAvatar bind idempotent: see the `create_heygen_voice_ledger_tables`
 * migration. A PLATFORM row, kept after any template that names it is deleted.
 *
 * @property int $id
 * @property string $engine
 * @property string $provider_voice_id
 * @property string $secret_id
 * @property string $voice_id
 */
class HeygenBoundVoice extends Model
{
    /** @var list<string> */
    protected $fillable = ['engine', 'provider_voice_id', 'secret_id', 'voice_id'];
}
