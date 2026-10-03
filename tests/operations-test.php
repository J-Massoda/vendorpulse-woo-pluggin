<?php
/** Run with `php tests/operations-test.php`. WooCommerce query adapter test doubles. */
define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
require dirname( __DIR__ ) . '/includes/class-vendorpulse-rules.php';
require dirname( __DIR__ ) . '/includes/class-vendorpulse-woo.php';

function check( bool $truth, string $label ): void {
    if ( ! $truth ) { throw new RuntimeException( $label ); }
}
function wp_mail( $to, $subject, $body ): void { $GLOBALS['mail'][] = array( $to, $subject, $body ); }
function get_option( $name ): string { return 'owner@example.test'; }
function admin_url( $path ): string { return 'https://store.example.test/wp-admin/' . $path; }

final class FakeOrder {
    public array $meta = array();
    public array $notes = array();
    public int $saved = 0;
    public function __construct( public int $id, public string $status, public int $created ) {}
    public function get_id(): int { return $this->id; }
    public function get_status(): string { return $this->status; }
    public function get_date_created(): DateTimeImmutable { return ( new DateTimeImmutable() )->setTimestamp( $this->created ); }
    public function update_meta_data( $key, $value ): void { $this->meta[ $key ] = $value; }
    public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
    public function save(): void { ++$this->saved; }
    public function add_order_note( $text, $customer = false, $added_by_user = false ): void { $this->notes[] = array( $text, $customer ); }
}

$now = time();
$orders = array(
    new FakeOrder( 101, 'processing', $now - 49 * 3600 ),
    new FakeOrder( 102, 'processing', $now - 12 * 3600 ),
    new FakeOrder( 103, 'on-hold', $now - 25 * 3600 ),
    new FakeOrder( 104, 'failed', $now - 2 * 86400 ),
    new FakeOrder( 105, 'failed', $now - 20 * 86400 ),
);
function wc_get_orders( $args ) {
    global $orders;
    $GLOBALS['queries'][] = $args;
    $result = array_values( array_filter( $orders, static function ( FakeOrder $order ) use ( $args ): bool {
        $cutoff = (int) substr( $args['date_created'], 1 );
        return in_array( $order->status, $args['status'], true ) &&
            ( str_starts_with( $args['date_created'], '<' ) ? $order->created < $cutoff : $order->created > $cutoff );
    } ) );
    return (object) array( 'orders' => $args['paged'] === 1 ? $result : array(),
        'max_num_pages' => count( $result ) ? 1 : 0 );
}
function wc_get_order( $id ) { global $orders; foreach ( $orders as $order ) { if ( $order->id === $id ) { return $order; } } return false; }

check( VendorPulse_Rules::issue( 'processing', $now - 47 * 3600, $now ) === null, 'processing boundary below' );
check( VendorPulse_Rules::issue( 'processing', $now - 48 * 3600, $now )['kind'] === 'dispatch', 'processing boundary at 48 hours' );
check( VendorPulse_Rules::issue( 'failed', $now - 20 * 86400, $now ) === null, 'old failure not queued' );
check( VendorPulse_Rules::valid_date( '2026-02-30' ) === false, 'impossible due date' );
check( VendorPulse_Rules::valid_date( '2026-10-03' ), 'valid due date' );
check( ! VendorPulse_Rules::valid_state( 'paid' ), 'invalid follow-up state' );
$queue = VendorPulse_Woo::issues( 1, $now );
check( count( $queue['rows'] ) === 3, 'three matching issues' );
check( $queue['rows'][0]['issue']['priority'] === 1, 'priority first' );
check( $queue['pages'] === 1, 'pages preserved' );
check( count( $GLOBALS['queries'] ) === 3 && $GLOBALS['queries'][0]['paged'] === 1, 'WooCommerce paged query' );
check( VendorPulse_Woo::issues( 2, $now )['rows'] === array(), 'second page not repeated' );
VendorPulse_Woo::record_followup( $orders[0], 'watching', 'Check courier', '2026-10-05', 'Called warehouse', 7 );
check( $orders[0]->get_meta( '_vendorpulse_action' ) === 'Check courier' && $orders[0]->saved === 1, 'Woo order metadata saved' );
check( count( $orders[0]->notes ) === 1 && $orders[0]->notes[0][1] === false, 'internal order note' );
VendorPulse_Woo::digest();
check( count( $GLOBALS['mail'] ) === 1 && str_contains( $GLOBALS['mail'][0][2], 'First page' ), 'digest identifies limited counts' );
echo "Operations tests passed.\n";
