<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An append-only audit record.
 *
 * The table has no `updated_at`, and the application never issues an UPDATE
 * or DELETE against it.
 *
 * @property int $id
 * @property int $sequence
 * @property int|null $actor_user_id
 * @property string $actor
 * @property string $action
 * @property string|null $target_type
 * @property string|null $target_id
 * @property string $result
 * @property array<string, mixed>|null $context
 * @property Carbon $occurred_at
 */
class AuditRecord extends Model
{
    protected $fillable = [
        'sequence',
        'actor_user_id',
        'actor',
        'action',
        'target_type',
        'target_id',
        'result',
        'context',
        'occurred_at',
    ];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
