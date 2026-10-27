<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The authorisation role of a user.
 *
 * `USER` is the default and is what every self-registered account receives.
 * `ADMIN` is granted by an operator; it is never granted by the registration
 * flow, so "every newly registered user is an admin" is not a reachable
 * state.
 */
enum UserRole: string
{
    case USER = 'USER';
    case ADMIN = 'ADMIN';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::USER => 'Customer',
            self::ADMIN => 'Administrator',
        };
    }
}
