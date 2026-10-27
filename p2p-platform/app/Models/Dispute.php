<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DisputeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A dispute raised against a transfer.
 *
 * @property int $id
 * @property int $transaction_id
 * @property int $opened_by
 * @property int|null $assigned_to
 * @property string $subject
 * @property string $category
 * @property string $status
 * @property string $priority
 * @property string|null $description
 * @property Carbon|null $resolved_at
 */
class Dispute extends Model
{
    /** @use HasFactory<DisputeFactory> */
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'opened_by',
        'assigned_to',
        'subject',
        'category',
        'status',
        'priority',
        'description',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
