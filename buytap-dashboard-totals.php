<?php
/*
Plugin Name: BuyTap Dashboard Totals
Description: Shortcodes for Earnings, Expenses, and Net based on buytap_order posts.
Version: 1.0.0
Author: BuyTap
*/

if (!defined('ABSPATH')) exit;

class BuyTap_Dashboard_Totals {
    public function __construct() {
        add_shortcode('buytap_earnings', [$this, 'sc_earnings']);
        add_shortcode('buytap_expenses', [$this, 'sc_expenses']);
        add_shortcode('buytap_net',      [$this, 'sc_net']);
    }

    /** ===== Helpers ===== */

    private function fmt_money($amount, $prefix = 'Ksh ') {
        // Use WP i18n formatting if available
        if (function_exists('number_format_i18n')) {
            return esc_html($prefix . number_format_i18n((float)$amount));
        }
        return esc_html($prefix . number_format((float)$amount));
    }

    /**
     * Sum a meta key for current user's buytap_order posts filtered by status (meta).
     * @param string[] $statuses  e.g. ['Closed']
     * @param string   $meta_key  e.g. 'expected_amount'
     * @param callable|null $transform  optional transform per-order before summing
     */
    private function sum_for_user($statuses, $meta_key, $transform = null) {
        if (!is_user_logged_in()) return 0.0;

        $user_id = get_current_user_id();

        $q = get_posts([
            'post_type'      => 'buytap_order',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'author'         => $user_id,
            'meta_query'     => [
                [
                    'key'     => 'status',
                    'value'   => array_map('strval', (array)$statuses),
                    'compare' => 'IN'
                ],
            ],
            'no_found_rows'  => true,
        ]);

        $total = 0.0;

        foreach ($q as $order_id) {
            $val = get_post_meta($order_id, $meta_key, true);

            // Fallbacks (helps in cases where expected_amount isn't set but amount_to_make is)
            if ($meta_key === 'expected_amount' && $val === '' ) {
                $val = get_post_meta($order_id, 'amount_to_make', true);
            }

            $num = (float)$val;

            if (is_callable($transform)) {
                $num = (float) call_user_func($transform, $num, $order_id);
            }

            $total += $num;
        }

        return $total;
    }

    /** ===== Shortcodes ===== */

    // [buytap_earnings prefix="Ksh " statuses="Closed"]
    public function sc_earnings($atts = []) {
        $atts = shortcode_atts([
            'prefix'   => 'Ksh ',
            'statuses' => 'Closed', // default: money realized
        ], $atts, 'buytap_earnings');

        $statuses = array_filter(array_map('trim', explode(',', $atts['statuses'])));
        // Earnings: sum expected_amount (fallback to amount_to_make) for Closed orders
        $sum = $this->sum_for_user($statuses, 'expected_amount');

        return $this->fmt_money($sum, $atts['prefix']);
    }

    // [buytap_expenses prefix="Ksh " statuses="Pending,paired,Active,Closed"]
    public function sc_expenses($atts = []) {
        $atts = shortcode_atts([
            'prefix'   => 'Ksh ',
            // By default we count all orders the user has paid/will pay into:
            'statuses' => 'Pending,paired,Active,Closed',
        ], $atts, 'buytap_expenses');

        $statuses = array_filter(array_map('trim', explode(',', $atts['statuses'])));

        // Expenses: sum amount_to_send for the chosen statuses
        $sum = $this->sum_for_user($statuses, 'amount_to_send');

        return $this->fmt_money($sum, $atts['prefix']);
    }

    // [buytap_net prefix="Ksh " earn_statuses="Closed" exp_statuses="Pending,paired,Active,Closed"]
    public function sc_net($atts = []) {
        $atts = shortcode_atts([
            'prefix'        => 'Ksh ',
            'earn_statuses' => 'Closed',
            'exp_statuses'  => 'Pending,paired,Active,Closed',
        ], $atts, 'buytap_net');

        $earn_statuses = array_filter(array_map('trim', explode(',', $atts['earn_statuses'])));
        $exp_statuses  = array_filter(array_map('trim', explode(',', $atts['exp_statuses'])));

        $earn = $this->sum_for_user($earn_statuses, 'expected_amount');
        $exp  = $this->sum_for_user($exp_statuses,  'amount_to_send');

        $net = $earn - $exp;

        return $this->fmt_money($net, $atts['prefix']);
    }
}

new BuyTap_Dashboard_Totals();



