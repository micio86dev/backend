<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The LiveAvatar secret BEAI holds for one TTS vendor's platform key
 * (heygen-third-party-voices H2). A PLATFORM row: no organization, never read
 * through a tenant surface. Holds an opaque LiveAvatar id, never the key.
 *
 * @property int $id
 * @property string $engine
 * @property string $secret_id
 */
class HeygenVendorSecret extends Model
{
    /** @var list<string> */
    protected $fillable = ['engine', 'secret_id'];
}
