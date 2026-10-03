<?php

defined( 'ABSPATH' ) || exit;

final class VendorPulse_Woo {
    private const CRON = 'vendorpulse_daily_digest';
    private const KEY = '_vendorpulse_';
    private const PAGE_SIZE = 25;

    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_vendorpulse_save', array( __CLASS__, 'save' ) );
        add_action( self::CRON, array( __CLASS__, 'digest' ) );
        add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce_notice' ) );
    }

    public static function activate(): void {
        if ( ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( self::CRON );
    }

    private static function available(): bool {
        return function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_order' );
    }

    public static function missing_woocommerce_notice(): void {
        if ( current_user_can( 'activate_plugins' ) && ! self::available() ) {
            echo '<div class="notice notice-warning"><p>VendorPulse needs an active WooCommerce plugin to inspect orders.</p></div>';
        }
    }

    public static function menu(): void {
        if ( self::available() ) {
            add_submenu_page( 'woocommerce', 'VendorPulse', 'VendorPulse', 'manage_woocommerce', 'vendorpulse', array( __CLASS__, 'screen' ) );
        }
    }

    private static function args( string $status, int $page, int $now ): array {
        $args = array(
            'status' => array( $status ), 'limit' => self::PAGE_SIZE, 'paged' => $page,
            'paginate' => true, 'orderby' => 'date', 'order' => 'DESC',
        );
        if ( 'failed' === $status ) {
            $args['date_created'] = '>' . ( $now - VendorPulse_Rules::FAILED_DAYS * DAY_IN_SECONDS );
        } else {
            $hours = 'processing' === $status ? VendorPulse_Rules::PROCESSING_HOURS : VendorPulse_Rules::HOLD_HOURS;
            $args['date_created'] = '<' . ( $now - $hours * HOUR_IN_SECONDS );
        }
        return $args;
    }

    /** Paginate on the server. No fixed-size sample that silently drops older orders. */
    public static function issues( int $page = 1, int $now = 0 ): array {
        if ( ! self::available() ) { return array( 'rows' => array(), 'pages' => 0 ); }
        $now = $now ?: time();
        $page = max( 1, $page );
        $rows = array();
        $pages = 0;
        foreach ( array( 'processing', 'on-hold', 'failed' ) as $status ) {
            $result = wc_get_orders( self::args( $status, $page, $now ) );
            $pages = max( $pages, (int) $result->max_num_pages );
            foreach ( $result->orders as $order ) {
                $date = $order->get_date_created();
                if ( ! $date ) { continue; }
                $issue = VendorPulse_Rules::issue( $order->get_status(), $date->getTimestamp(), $now );
                if ( ! $issue ) { continue; }
                $rows[] = array( 'order' => $order, 'issue' => $issue );
            }
        }
        usort( $rows, static function ( array $a, array $b ): int {
            return $a['issue']['priority'] <=> $b['issue']['priority']
                ?: $a['order']->get_date_created()->getTimestamp() <=> $b['order']->get_date_created()->getTimestamp();
        } );
        return array( 'rows' => $rows, 'pages' => $pages );
    }

    public static function screen(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Permission denied.', '', array( 'response' => 403 ) ); }
        if ( ! self::available() ) { wp_die( 'WooCommerce is required.' ); }
        $page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $queue = self::issues( $page );
        echo '<div class="wrap"><h1>VendorPulse</h1><p>Review orders that may need a human next step. The queue reads WooCommerce orders; it never changes payment or fulfillment status.</p>';
        if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>Follow-up saved.</p></div>'; }
        echo '<p><strong>Rules:</strong> processing for 48+ hours; on hold for 24+ hours; failed payment in the last 14 days. A daily email summary is scheduled through WordPress cron.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Order</th><th>Reason</th><th>Created</th><th>Follow-up</th><th>Next action</th></tr></thead><tbody>';
        foreach ( $queue['rows'] as $item ) {
            $order = $item['order'];
            $id = $order->get_id();
            $action = (string) $order->get_meta( self::KEY . 'action', true );
            $state = (string) $order->get_meta( self::KEY . 'state', true );
            if ( ! VendorPulse_Rules::valid_state( $state ) ) { $state = 'open'; }
            $due = (string) $order->get_meta( self::KEY . 'due', true );
            $link = $order->get_edit_order_url();
            echo '<tr><td><a href="' . esc_url( $link ) . '">#' . esc_html( (string) $id ) . '</a><br><small>' . esc_html( $order->get_formatted_billing_full_name() ) . '</small></td>';
            echo '<td>' . esc_html( $item['issue']['label'] ) . '</td><td>' . esc_html( $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) ) . '</td>';
            echo '<td><strong>' . esc_html( ucfirst( $state ) ) . '</strong>' . ( $due ? '<br>Due: ' . esc_html( $due ) : '' ) . '</td><td>';
            if ( $action ) { echo '<p>' . esc_html( $action ) . '</p>'; }
            echo '<details><summary>Update follow-up</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="vendorpulse_save"><input type="hidden" name="order_id" value="' . esc_attr( (string) $id ) . '">';
            wp_nonce_field( 'vendorpulse_save_' . $id );
            echo '<p><label>State <select name="state">';
            foreach ( array( 'open', 'watching', 'resolved' ) as $option ) {
                echo '<option value="' . esc_attr( $option ) . '" ' . selected( $state, $option, false ) . '>' . esc_html( ucfirst( $option ) ) . '</option>';
            }
            echo '</select></label></p><p><label>Next action <input name="next_action" maxlength="300" value="' . esc_attr( $action ) . '" required></label></p>';
            echo '<p><label>Follow-up date <input type="date" name="due" value="' . esc_attr( $due ) . '"></label></p>';
            echo '<p><label>Internal note <textarea name="note" maxlength="500" rows="2"></textarea></label></p><p><button class="button button-primary">Save</button></p></form></details></td></tr>';
        }
        if ( ! $queue['rows'] ) { echo '<tr><td colspan="5">No orders match the rules on this page.</td></tr>'; }
        echo '</tbody></table>';
        if ( $queue['pages'] > 1 ) {
            echo '<p>' . wp_kses_post( paginate_links( array(
                'base' => add_query_arg( 'paged', '%#%', admin_url( 'admin.php?page=vendorpulse' ) ),
                'current' => $page, 'total' => $queue['pages'],
            ) ) ) . '</p>';
        }
        echo '<p><small>Each page combines up to 25 matching orders per status. Follow-up notes are internal and stored on the order. Resolved items remain visible while their underlying order condition remains.</small></p></div>';
    }

    private static function posted( string $name ): string {
        $value = $_POST[ $name ] ?? '';
        return is_string( $value ) ? wp_unslash( $value ) : '';
    }

    public static function save(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Permission denied.', '', array( 'response' => 403 ) ); }
        if ( ! self::available() ) { wp_die( 'WooCommerce is required.' ); }
        $id = absint( self::posted( 'order_id' ) );
        check_admin_referer( 'vendorpulse_save_' . $id );
        $order = wc_get_order( $id );
        if ( ! $order ) { wp_die( 'Order not found.', '', array( 'response' => 404 ) ); }
        $state = sanitize_key( self::posted( 'state' ) );
        $action = sanitize_text_field( self::posted( 'next_action' ) );
        $due = self::posted( 'due' );
        $note = sanitize_textarea_field( self::posted( 'note' ) );
        if ( ! VendorPulse_Rules::valid_state( $state ) || '' === trim( $action ) || strlen( $action ) > 300 ||
            ! VendorPulse_Rules::valid_date( $due ) || strlen( $note ) > 500 ) {
            wp_die( 'Invalid follow-up.', '', array( 'response' => 400 ) );
        }
        self::record_followup( $order, $state, $action, $due, $note, get_current_user_id() );
        wp_safe_redirect( admin_url( 'admin.php?page=vendorpulse&saved=1' ), 303 );
        exit;
    }

    public static function record_followup( $order, string $state, string $action, string $due, string $note, int $actor ): void {
        $order->update_meta_data( self::KEY . 'state', $state );
        $order->update_meta_data( self::KEY . 'action', $action );
        $order->update_meta_data( self::KEY . 'due', $due );
        $order->save();
        $text = sprintf( 'VendorPulse: %s; next action: %s; due: %s; by user #%d.', $state, $action, $due ?: 'not set', $actor );
        if ( $note ) { $text .= ' Note: ' . $note; }
        $order->add_order_note( $text, false, false );
    }

    public static function digest(): void {
        if ( ! self::available() ) { return; }
        $queue = self::issues( 1 );
        $counts = array( 'dispatch' => 0, 'hold' => 0, 'failed' => 0 );
        foreach ( $queue['rows'] as $item ) { ++$counts[ $item['issue']['kind'] ]; }
        // Fetching one page cannot produce a truthful full-store count; label the sample explicitly.
        if ( array_sum( $counts ) ) {
            $body = "First page requiring review (up to 25 per status):\n";
            $body .= sprintf( "Dispatch: %d; on hold: %d; failed payment: %d.\n", $counts['dispatch'], $counts['hold'], $counts['failed'] );
            $body .= "Open the VendorPulse dashboard to review all pages: " . admin_url( 'admin.php?page=vendorpulse' );
            wp_mail( get_option( 'admin_email' ), 'VendorPulse daily operations review', $body );
        }
    }
}
