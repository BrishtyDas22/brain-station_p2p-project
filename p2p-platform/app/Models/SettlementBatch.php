<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\IntegerPoisha;
use Database\Factories\SettlementBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A settlement batch — a group of ledger entries submitted to a network.
 *
 * @property int $id
 * @property string $reference
 * @property string $network
 * @property string $status
 * @property int $entry_count
 * @property int $total_minor
 * @property int $fee_minor
 * @property int $net_minor
 * @property string $currency
 * @property Carbon $opened_at
 * @property Carbon|null $settled_at
 * @property string|null $failure_reason
 */
class SettlementBatch extends Model
{
    /** @use HasFactory<SettlementBatchFactory> */
    use HasFactory;

    protected $fillable = [
        'reference',
        'network',
        'status',
        'entry_count',
        'total_minor',
        'fee_minor',
        'net_minor',
        'currency',
        'opened_at',
        'settled_at',
        'failure_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_count' => 'integer',
            'total_minor' => IntegerPoisha::class,
            'fee_minor' => IntegerPoisha::class,
            'net_minor' => IntegerPoisha::class,
            'opened_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
