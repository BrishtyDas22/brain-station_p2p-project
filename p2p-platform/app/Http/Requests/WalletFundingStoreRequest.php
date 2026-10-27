<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FundingMethod;
use App\Support\DemoAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an "Add Money" attempt.
 *
 * ## The client never states the outcome
 *
 * The accepted keys are an explicit allow-list. A request may carry the method, the
 * amount, the method-specific details and an idempotency key — and **nothing else**. In
 * particular there is no key for a status, a balance, a credited amount or a reference, so
 * a client cannot assert "this payment succeeded" or "credit me this much".
 *
 * `prepareForValidation()` also strips those keys before validation runs, so a hostile
 * payload never reaches the validator or the service.
 */
class WalletFundingStoreRequest extends FormRequest
{
    /**
     * The only keys this request accepts.
     *
     * @var list<string>
     */
    public const ALLOWED_KEYS = [
        'method',
        'amount',
        'idempotency_key',
        'mobile_number',
        'bank',
        'account_number',
        'card_number',
        'card_expiry',
        'card_cvv',
        'card_holder',
    ];

    /**
     * The keys that are stripped outright, because a client must never be able to state
     * an outcome, a balance or a reference.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'status',
        'balance_minor',
        'credited_minor',
        'amount_minor',
        'reference',
        'owner_user_id',
        'wallet_id',
        'transaction_id',
        'account_id',
        'pin',
        'otp',
        'password',
        'cvv',
    ];

    public function authorize(): bool
    {
        // Eligibility (approved KYC, an existing wallet) is enforced in the service,
        // where it can be audited. The request only confirms the user is signed in.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::in(FundingMethod::values())],
            'amount' => ['required', 'string', 'max:16'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'bank' => ['nullable', 'string', 'max:64'],
            'account_number' => ['nullable', 'string', 'max:32'],
            'card_number' => ['nullable', 'string', 'max:32'],
            'card_expiry' => ['nullable', 'string', 'max:8'],
            'card_cvv' => ['nullable', 'string', 'max:8'],
            'card_holder' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'method.required' => 'Choose a payment method.',
            'method.in' => 'Choose a payment method.',
            'amount.required' => 'Enter an amount.',
            'amount.max' => 'That amount is too large.',
        ];
    }

    /**
     * Strip anything that is not on the allow-list.
     *
     * This runs before validation, so a forged field never reaches the validator.
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();

        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            unset($input[$forbidden]);
        }

        $allowed = [];

        foreach (self::ALLOWED_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $allowed[$key] = $input[$key];
            }
        }

        $this->replace($allowed);
    }

    /**
     * The method-specific inputs, for the service.
     *
     * @return array<string, mixed>
     */
    public function fundingPayload(): array
    {
        return array_filter(
            $this->validated(),
            fn (string $key): bool => ! in_array($key, ['method', 'amount', 'idempotency_key'], true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The idempotency key, when the client supplied one.
     */
    public function idempotencyKey(): ?string
    {
        $key = $this->validated('idempotency_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * Whether the amount parses to a positive, representable amount.
     *
     * The service does the authoritative parse; this is a friendly early rejection so the
     * user sees the error without a round trip to the service.
     */
    public function passesAmountFormat(): bool
    {
        return DemoAmount::isValid((string) $this->validated('amount'));
    }
}
