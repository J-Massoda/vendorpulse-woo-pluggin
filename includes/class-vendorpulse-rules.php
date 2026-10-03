<?php

defined( 'ABSPATH' ) || exit;

final class VendorPulse_Rules {
    public const PROCESSING_HOURS = 48;
    public const HOLD_HOURS = 24;
    public const FAILED_DAYS = 14;

    /** The service only reads order status and age; it never changes fulfillment. */
    public static function issue( string $status, int $created, int $now ): ?array {
        $age = max( 0, $now - $created );
        if ( 'processing' === $status && $age >= self::PROCESSING_HOURS * HOUR_IN_SECONDS ) {
            return array( 'kind' => 'dispatch', 'label' => 'Dispatch follow-up', 'priority' => 2 );
        }
        if ( 'on-hold' === $status && $age >= self::HOLD_HOURS * HOUR_IN_SECONDS ) {
            return array( 'kind' => 'hold', 'label' => 'Payment or hold review', 'priority' => 1 );
        }
        if ( 'failed' === $status && $age <= self::FAILED_DAYS * DAY_IN_SECONDS ) {
            return array( 'kind' => 'failed', 'label' => 'Failed payment review', 'priority' => 1 );
        }
        return null;
    }

    public static function valid_date( string $date ): bool {
        if ( '' === $date ) { return true; }
        $value = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
        return $value && $value->format( 'Y-m-d' ) === $date;
    }

    public static function valid_state( string $state ): bool {
        return in_array( $state, array( 'open', 'watching', 'resolved' ), true );
    }
}
