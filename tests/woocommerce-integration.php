<?php
/**
 * Run with wp eval-file on a disposable WooCommerce site only.
 * Writes and permanently deletes fictional orders.
 */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'wc_create_order' ) ) {
    throw new RuntimeException( 'Load this test in WordPress with active WooCommerce.' );
}

function vp_assert( bool $condition, string $message ): void {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$now = time();
$created = array();
try {
    $cases = array(
        array( 'processing', 72 * HOUR_IN_SECONDS, 'dispatch' ),
        array( 'on-hold', 36 * HOUR_IN_SECONDS, 'hold' ),
        array( 'failed', 2 * DAY_IN_SECONDS, 'failed' ),
    );
    foreach ( $cases as $case ) {
        $order = wc_create_order();
        if ( is_wp_error( $order ) ) { throw new RuntimeException( $order->get_error_message() ); }
        $created[] = $order->get_id();
        $order->set_billing_first_name( 'Fictional' );
        $order->set_billing_last_name( 'Example' );
        $order->set_status( $case[0] );
        $order->set_date_created( $now - $case[1] );
        $order->save();
        $date = $order->get_date_created();
        vp_assert( VendorPulse_Rules::issue( $order->get_status(), $date->getTimestamp(), $now )['kind'] === $case[2], 'Rule did not match ' . $case[0] );
    }
    $queue = VendorPulse_Woo::issues( 1, $now );
    $found = array();
    foreach ( $queue['rows'] as $item ) { $found[] = $item['order']->get_id(); }
    foreach ( $created as $id ) { vp_assert( in_array( $id, $found, true ), 'WooCommerce query omitted order ' . $id ); }
    wp_set_current_user( 1 );
    ob_start();
    VendorPulse_Woo::screen();
    $html = ob_get_clean();
    vp_assert( str_contains( $html, 'Dispatch follow-up' ) && str_contains( $html, '#' . $created[0] ), 'Administrator dashboard omitted sample order' );
    $first = wc_get_order( $created[0] );
    VendorPulse_Woo::record_followup( $first, 'watching', 'Ask warehouse for update', '2026-10-05', 'Internal demo note', 1 );
    $reloaded = wc_get_order( $created[0] );
    vp_assert( 'watching' === $reloaded->get_meta( '_vendorpulse_state', true ), 'WooCommerce order metadata not saved' );
    vp_assert( 'Ask warehouse for update' === $reloaded->get_meta( '_vendorpulse_action', true ), 'Next action not saved' );
    $notes = wc_get_order_notes( array( 'order_id' => $created[0], 'type' => 'internal' ) );
    vp_assert( count( $notes ) > 0, 'Internal order note missing' );
    echo 'Real WooCommerce integration passed; ' . count( $created ) . " sample orders cleaned up.\n";
} finally {
    foreach ( $created as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
}
