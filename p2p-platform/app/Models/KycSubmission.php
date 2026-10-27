<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KycStatus;
use Database\Factories\KycSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A KYC submission — the identity record for one user.
 *
 * @property int $id
 * @property int $user_id
 * @property KycStatus $status
 * @property string $full_name
 * @property string $document_type
 * @property string $document_number
 * @property Carbon $date_of_birth
 * @property string $address_line
 * @property string $city
 * @property string $country
 * @property string $mobile_number
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property int|null $risk_score
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class KycSubmission extends Model
{
    /** @use HasFactory<KycSubmissionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'full_name',
        'document_type',
        'document_number',
        'date_of_birth',
        'address_line',
        'city',
        'country',
        'mobile_number',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'risk_score',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => KycStatus::class,
            'date_of_birth' => 'date',
            'reviewed_at' => 'datetime',
            'risk_score' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The identity number with all but the last four characters masked.
     */
    public function maskedDocumentNumber(): string
    {
        $number = $this->document_number;
        $visible = 4;
        $length = strlen($number);

        if ($length <= $visible) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', $length - $visible).substr($number, -$visible);
    }
}
