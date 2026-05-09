<?php
/**
 * Plugin Name: BuyTap Referral Reward Reports V5
 * Description: Adds referral reward history, payout marking, daily referral tracking, referred-user details, payout summaries, CSV export, and frontend popup referral cards, fraud flags, duplicate M-Pesa blocking, and self-referral protection, admin approve/reject review workflow, and admin fraud reason hints for the existing BuyTap referral system.
 * Version: 5.4.0
 * Author: Philip Osir / OpenAI
 * Text Domain: buytap-referral-reward-reports
 */

if (!defined('ABSPATH')) {
    exit;
}

class BuyTap_Referral_Reward_Reports_V5 {
    const OPTION_REWARD_AMOUNT = 'buytap_referral_reward_amount';
    const OPTION_CURRENCY = 'buytap_referral_reward_currency';
    const OPTION_RATE_HISTORY = 'buytap_referral_reward_rate_history';
    const NONCE_ACTION_SAVE_RATE = 'buytap_save_referral_reward_rate';
    const NONCE_ACTION_MARK_REWARDED = 'buytap_mark_referrals_rewarded';
    const NONCE_ACTION_MARK_PENDING = 'buytap_mark_referrals_pending';

    const META_REWARDED = '_buytap_referral_reward_redeemed';
    const META_REWARDED_AT = '_buytap_referral_reward_redeemed_at';
    const META_REWARDED_BY = '_buytap_referral_reward_redeemed_by';
    const META_REWARDED_AMOUNT = '_buytap_referral_reward_redeemed_amount';
    const META_REWARDED_CURRENCY = '_buytap_referral_reward_redeemed_currency';
    const META_REWARDED_BATCH = '_buytap_referral_reward_batch';

    const META_SIGNUP_IP = '_buytap_signup_ip';
    const META_DEVICE_HASH = '_buytap_device_hash';
    const META_FRAUD_FLAGS = '_buytap_referral_fraud_flags';
    const META_FRAUD_FLAGGED_AT = '_buytap_referral_fraud_flagged_at';

    const NONCE_ACTION_REVIEW_REFERRAL = 'buytap_review_referral_reward';
    const META_REVIEW_STATUS = '_buytap_referral_review_status';
    const META_REVIEWED_AT = '_buytap_referral_reviewed_at';
    const META_REVIEWED_BY = '_buytap_referral_reviewed_by';
    const META_REVIEW_NOTE = '_buytap_referral_review_note';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'maybe_initialize_rate_history']);
        add_action('admin_post_buytap_save_referral_reward_rate', [$this, 'save_reward_rate']);
        add_action('admin_post_buytap_export_referrals_csv', [$this, 'export_csv']);
        add_action('admin_post_buytap_mark_referrals_rewarded', [$this, 'mark_referrals_rewarded']);
        add_action('admin_post_buytap_mark_referrals_pending', [$this, 'mark_referrals_pending']);
        add_action('admin_post_buytap_review_referral_reward', [$this, 'review_referral_reward']);
        add_shortcode('buytap_my_referrals_details', [$this, 'shortcode_my_referrals_details']);
        add_shortcode('buytap_referral_champion', [$this, 'shortcode_referral_champion']);

        // Security: block clear abuse during registration and keep fraud flags for admin review.
        add_filter('registration_errors', [$this, 'validate_registration_security'], 10, 3);
        add_action('user_register', [$this, 'capture_registration_security_data'], 50, 1);
        add_action('profile_update', [$this, 'refresh_user_fraud_flags'], 50, 1);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }

    public static function activate() {
        $currency = get_option(self::OPTION_CURRENCY, 'KES');
        if (!$currency) {
            update_option(self::OPTION_CURRENCY, 'KES');
        }

        $history = get_option(self::OPTION_RATE_HISTORY, []);
        if (!is_array($history) || empty($history)) {
            $existing_amount = get_option(self::OPTION_REWARD_AMOUNT, 10);
            $existing_amount = floatval($existing_amount);
            if ($existing_amount <= 0) {
                $existing_amount = 10;
            }

            update_option(self::OPTION_RATE_HISTORY, [
                [
                    'amount' => $existing_amount,
                    'currency' => $currency ?: 'KES',
                    'effective_from' => '1970-01-01 00:00:00',
                    'created_at' => current_time('mysql'),
                ]
            ]);
            update_option(self::OPTION_REWARD_AMOUNT, $existing_amount);
        }
    }

    public function maybe_initialize_rate_history() {
        self::activate();
    }

    public function register_admin_menu() {
        add_menu_page(
            'BuyTap Referral Rewards',
            'Referral Rewards',
            'manage_options',
            'buytap-referral-rewards',
            [$this, 'render_admin_page'],
            'dashicons-groups',
            58
        );
    }

    private function sanitize_amount($value) {
        $value = floatval($value);
        return $value < 0 ? 0 : $value;
    }

    private function get_currency() {
        $currency = get_option(self::OPTION_CURRENCY, 'KES');
        return $currency ? sanitize_text_field($currency) : 'KES';
    }

    private function normalize_code($code) {
        $code = (string) $code;
        $code = trim($code);
        $code = strtolower($code);
        $code = str_replace([' ', '_'], ['-', '-'], $code);
        return $code;
    }

    private function normalize_phone($phone) {
        $phone = preg_replace('/\D+/', '', (string) $phone);
        if (strpos($phone, '254') === 0 && strlen($phone) === 12) {
            $phone = '0' . substr($phone, 3);
        }
        return $phone;
    }

    private function get_post_value($keys) {
        foreach ((array) $keys as $key) {
            if (isset($_POST[$key])) {
                return sanitize_text_field(wp_unslash($_POST[$key]));
            }
        }
        return '';
    }

    private function current_signup_ip() {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ip_keys as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $raw = sanitize_text_field(wp_unslash($_SERVER[$key]));
            $ip = trim(explode(',', $raw)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return '';
    }

    private function current_device_hash() {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $lang = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])) : '';
        if (!$ua && !$lang) {
            return '';
        }
        return hash('sha256', strtolower($ua . '|' . $lang));
    }

    private function find_user_by_referral_code($code) {
        $code = $this->normalize_code($code);
        if (!$code) {
            return null;
        }

        $users = get_users([
            'fields' => ['ID', 'display_name', 'user_email'],
            'number' => -1,
            'meta_query' => [[
                'key' => 'my_referral_code',
                'compare' => 'EXISTS',
            ]],
        ]);

        foreach ($users as $user) {
            $user_code = get_user_meta($user->ID, 'my_referral_code', true);
            if ($this->normalize_code($user_code) === $code) {
                return $user;
            }
        }
        return null;
    }

    private function phone_used_by_other_user($phone, $exclude_user_id = 0) {
        $phone = $this->normalize_phone($phone);
        if (!$phone) {
            return 0;
        }

        $users = get_users([
            'fields' => ['ID'],
            'number' => -1,
            'meta_query' => [[
                'key' => 'mobile_number',
                'compare' => 'EXISTS',
            ]],
        ]);

        foreach ($users as $user) {
            if (intval($user->ID) === intval($exclude_user_id)) {
                continue;
            }
            $existing = $this->normalize_phone(get_user_meta($user->ID, 'mobile_number', true));
            if ($existing && $existing === $phone) {
                return intval($user->ID);
            }
        }
        return 0;
    }

    private function email_similarity_base($email) {
        $email = strtolower(trim((string) $email));
        if (strpos($email, '@') === false) {
            return '';
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = preg_replace('/\+.*/', '', $local); // remove gmail-style plus aliases
        $local = preg_replace('/[0-9._-]+/', '', $local); // catch names like john1, john2, john.3
        return $local . '@' . $domain;
    }

    private function calculate_user_fraud_flags($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return [];
        }

        $flags = [];
        $phone = $this->normalize_phone(get_user_meta($user_id, 'mobile_number', true));
        $promo_code = get_user_meta($user_id, 'promo_code', true);
        $own_code = get_user_meta($user_id, 'my_referral_code', true);
        $signup_ip = get_user_meta($user_id, self::META_SIGNUP_IP, true);
        $device_hash = get_user_meta($user_id, self::META_DEVICE_HASH, true);

        if ($phone) {
            if ($this->phone_used_by_other_user($phone, $user_id)) {
                $flags[] = 'Duplicate M-Pesa number';
            }
            if (preg_match('/(\d)\1{5,}/', $phone) || preg_match('/^(0?7)0+$/', $phone) || strlen($phone) < 10) {
                $flags[] = 'Suspicious M-Pesa pattern';
            }
        }

        if ($promo_code && $own_code && $this->normalize_code($promo_code) === $this->normalize_code($own_code)) {
            $flags[] = 'Self-referral attempt';
        }

        $referrer = $this->find_user_by_referral_code($promo_code);
        if ($referrer && strtolower($referrer->user_email) === strtolower($user->user_email)) {
            $flags[] = 'Self-referral email match';
        }

        if ($signup_ip) {
            $same_ip_users = get_users([
                'fields' => ['ID'],
                'number' => 4,
                'exclude' => [$user_id],
                'meta_query' => [[
                    'key' => self::META_SIGNUP_IP,
                    'value' => $signup_ip,
                    'compare' => '=',
                ]],
            ]);
            if (count($same_ip_users) >= 2) {
                $flags[] = 'Many accounts from same IP';
            }
        }

        if ($device_hash) {
            $same_device_users = get_users([
                'fields' => ['ID'],
                'number' => 4,
                'exclude' => [$user_id],
                'meta_query' => [[
                    'key' => self::META_DEVICE_HASH,
                    'value' => $device_hash,
                    'compare' => '=',
                ]],
            ]);
            if (count($same_device_users) >= 2) {
                $flags[] = 'Many accounts from same device/browser';
            }
        }

        $base = $this->email_similarity_base($user->user_email);
        if ($base) {
            $similar = 0;
            $users = get_users(['fields' => ['ID', 'user_email'], 'number' => -1, 'exclude' => [$user_id]]);
            foreach ($users as $other) {
                if ($this->email_similarity_base($other->user_email) === $base) {
                    $similar++;
                }
            }
            if ($similar >= 2) {
                $flags[] = 'Similar email pattern';
            }
        }

        $flags = array_values(array_unique($flags));
        update_user_meta($user_id, self::META_FRAUD_FLAGS, $flags);
        if (!empty($flags)) {
            update_user_meta($user_id, self::META_FRAUD_FLAGGED_AT, current_time('mysql'));
        } else {
            delete_user_meta($user_id, self::META_FRAUD_FLAGGED_AT);
        }
        return $flags;
    }

    public function validate_registration_security($errors, $sanitized_user_login, $user_email) {
        $phone = $this->get_post_value(['mobile_number', 'mpesa_number', 'mpesa', 'phone', 'billing_phone']);
        $promo_code = $this->get_post_value(['promo_code', 'referral_code', 'ref_code']);

        if ($phone && $this->phone_used_by_other_user($phone)) {
            $errors->add('buytap_duplicate_mpesa', __('This M-Pesa number is already linked to another account. Please use your own unique M-Pesa number.', 'buytap-referral-reward-reports'));
        }

        if ($promo_code) {
            $referrer = $this->find_user_by_referral_code($promo_code);
            if ($referrer && strtolower($referrer->user_email) === strtolower($user_email)) {
                $errors->add('buytap_self_referral', __('You cannot register using your own referral code.', 'buytap-referral-reward-reports'));
            }
        }

        return $errors;
    }

    public function capture_registration_security_data($user_id) {
        $ip = $this->current_signup_ip();
        $device_hash = $this->current_device_hash();
        if ($ip) {
            update_user_meta($user_id, self::META_SIGNUP_IP, $ip);
        }
        if ($device_hash) {
            update_user_meta($user_id, self::META_DEVICE_HASH, $device_hash);
        }

        // If your registration form submitted these values before another plugin saved them, keep a safe copy.
        $phone = $this->get_post_value(['mobile_number', 'mpesa_number', 'mpesa', 'phone', 'billing_phone']);
        $promo_code = $this->get_post_value(['promo_code', 'referral_code', 'ref_code']);
        if ($phone && !get_user_meta($user_id, 'mobile_number', true)) {
            update_user_meta($user_id, 'mobile_number', $phone);
        }
        if ($promo_code && !get_user_meta($user_id, 'promo_code', true)) {
            update_user_meta($user_id, 'promo_code', $promo_code);
        }

        $this->calculate_user_fraud_flags($user_id);
    }

    public function refresh_user_fraud_flags($user_id) {
        $this->calculate_user_fraud_flags($user_id);
    }

    private function get_user_fraud_flags($user_id) {
        $flags = get_user_meta($user_id, self::META_FRAUD_FLAGS, true);
        if (!is_array($flags)) {
            $flags = [];
        }
        return array_values(array_filter(array_map('sanitize_text_field', $flags)));
    }


    private function get_fraud_reason_hints($flags) {
        $flags = is_array($flags) ? $flags : [];
        $hints = [];

        foreach ($flags as $flag) {
            $flag = sanitize_text_field($flag);
            switch ($flag) {
                case 'Duplicate M-Pesa number':
                    $hints[] = 'High risk: this M-Pesa number is already linked to another account. Usually reject unless it was a clear data-entry correction.';
                    break;
                case 'Suspicious M-Pesa pattern':
                    $hints[] = 'Check phone number: the M-Pesa number looks incomplete, repeated, or unrealistic. Confirm before approval.';
                    break;
                case 'Self-referral attempt':
                case 'Self-referral email match':
                    $hints[] = 'High risk: possible self-referral. Usually reject unless admin confirms it was a genuine mistake.';
                    break;
                case 'Many accounts from same IP':
                    $hints[] = 'Medium risk: several accounts came from the same internet connection. This may happen when someone helps friends register; verify names and M-Pesa numbers.';
                    break;
                case 'Many accounts from same device/browser':
                    $hints[] = 'Medium risk: several accounts used the same phone/browser. Could be assisted registration; approve only if each user has unique real details.';
                    break;
                case 'Similar email pattern':
                    $hints[] = 'Medium risk: emails look similar, such as repeated names or numbered accounts. Check if the users appear genuine.';
                    break;
                default:
                    $hints[] = 'Review needed: check user details before approving or rejecting.';
                    break;
            }
        }

        return array_values(array_unique($hints));
    }

    private function get_fraud_review_recommendation($flags) {
        $flags = is_array($flags) ? $flags : [];

        $high_risk = ['Duplicate M-Pesa number', 'Self-referral attempt', 'Self-referral email match'];
        foreach ($flags as $flag) {
            if (in_array($flag, $high_risk, true)) {
                return 'Recommended: Reject unless you have strong proof it is genuine.';
            }
        }

        if (in_array('Suspicious M-Pesa pattern', $flags, true)) {
            return 'Recommended: Verify the M-Pesa number first.';
        }

        if (in_array('Many accounts from same device/browser', $flags, true) || in_array('Many accounts from same IP', $flags, true)) {
            return 'Recommended: Possible assisted registration. Approve if details are unique and genuine.';
        }

        if (in_array('Similar email pattern', $flags, true)) {
            return 'Recommended: Check whether the emails look intentionally duplicated.';
        }

        return empty($flags) ? 'No concern detected.' : 'Recommended: Review manually.';
    }

    private function render_admin_reason_hint($flags) {
        $flags = is_array($flags) ? $flags : [];
        if (empty($flags)) {
            return '<span style="color:#15803d;font-weight:600;">No issue detected</span>';
        }

        $recommendation = $this->get_fraud_review_recommendation($flags);
        $hints = $this->get_fraud_reason_hints($flags);

        ob_start();
        ?>
        <div style="max-width:360px;line-height:1.45;">
            <strong style="color:#b45309;display:block;margin-bottom:4px;"><?php echo esc_html($recommendation); ?></strong>
            <details style="margin-top:4px;">
                <summary style="cursor:pointer;color:#2271b1;font-weight:600;">View reason hint</summary>
                <ul style="margin:6px 0 0 18px;">
                    <?php foreach ($hints as $hint): ?>
                        <li><?php echo esc_html($hint); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        </div>
        <?php
        return ob_get_clean();
    }

    private function normalize_datetime($date, $time = '00:00') {
        $date = sanitize_text_field($date);
        $time = sanitize_text_field($time);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = current_time('Y-m-d');
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '00:00';
        }

        return $date . ' ' . $time . ':00';
    }

    private function get_rate_history() {
        $history = get_option(self::OPTION_RATE_HISTORY, []);
        if (!is_array($history)) {
            $history = [];
        }

        $clean = [];
        foreach ($history as $item) {
            if (!is_array($item) || !isset($item['amount'], $item['effective_from'])) {
                continue;
            }
            $amount = floatval($item['amount']);
            if ($amount < 0) {
                continue;
            }
            $clean[] = [
                'amount' => $amount,
                'currency' => isset($item['currency']) ? sanitize_text_field($item['currency']) : $this->get_currency(),
                'effective_from' => sanitize_text_field($item['effective_from']),
                'created_at' => isset($item['created_at']) ? sanitize_text_field($item['created_at']) : '',
            ];
        }

        usort($clean, function($a, $b) {
            return strtotime($a['effective_from']) <=> strtotime($b['effective_from']);
        });

        if (empty($clean)) {
            $clean[] = [
                'amount' => 10,
                'currency' => 'KES',
                'effective_from' => '1970-01-01 00:00:00',
                'created_at' => current_time('mysql'),
            ];
        }

        return $clean;
    }

    private function get_current_reward_amount() {
        $history = $this->get_rate_history();
        $last = end($history);
        return floatval($last['amount']);
    }

    private function get_reward_for_datetime($datetime) {
        $history = $this->get_rate_history();
        $target_ts = strtotime($datetime);
        $matched = $history[0];

        foreach ($history as $item) {
            $effective_ts = strtotime($item['effective_from']);
            if ($effective_ts !== false && $effective_ts <= $target_ts) {
                $matched = $item;
            }
        }

        return floatval($matched['amount']);
    }

    public function save_reward_rate() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to update reward settings.');
        }

        check_admin_referer(self::NONCE_ACTION_SAVE_RATE);

        $amount = isset($_POST['reward_amount']) ? $this->sanitize_amount(wp_unslash($_POST['reward_amount'])) : 10;
        $currency = isset($_POST['currency_label']) ? sanitize_text_field(wp_unslash($_POST['currency_label'])) : 'KES';
        $effective_date = isset($_POST['effective_date']) ? sanitize_text_field(wp_unslash($_POST['effective_date'])) : current_time('Y-m-d');
        $effective_time = isset($_POST['effective_time']) ? sanitize_text_field(wp_unslash($_POST['effective_time'])) : '00:00';
        $effective_from = $this->normalize_datetime($effective_date, $effective_time);

        $history = $this->get_rate_history();
        $updated_existing = false;

        foreach ($history as &$item) {
            if ($item['effective_from'] === $effective_from) {
                $item['amount'] = $amount;
                $item['currency'] = $currency ?: 'KES';
                $item['created_at'] = current_time('mysql');
                $updated_existing = true;
                break;
            }
        }
        unset($item);

        if (!$updated_existing) {
            $history[] = [
                'amount' => $amount,
                'currency' => $currency ?: 'KES',
                'effective_from' => $effective_from,
                'created_at' => current_time('mysql'),
            ];
        }

        usort($history, function($a, $b) {
            return strtotime($a['effective_from']) <=> strtotime($b['effective_from']);
        });

        update_option(self::OPTION_RATE_HISTORY, $history);
        update_option(self::OPTION_REWARD_AMOUNT, $amount);
        update_option(self::OPTION_CURRENCY, $currency ?: 'KES');

        wp_safe_redirect(admin_url('admin.php?page=buytap-referral-rewards&updated=1'));
        exit;
    }

    private function get_referrers_map() {
        $users = get_users([
            'fields' => ['ID', 'display_name', 'user_email', 'user_registered'],
            'number' => -1,
        ]);

        $map = [];
        foreach ($users as $user) {
            $code = get_user_meta($user->ID, 'my_referral_code', true);
            if (!$code) {
                continue;
            }

            $map[$this->normalize_code($code)] = [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'registered' => $user->user_registered,
                'code' => $code,
                'mobile' => get_user_meta($user->ID, 'mobile_number', true),
            ];
        }

        return $map;
    }

    private function referral_status_for_user($user_id) {
        $redeemed = get_user_meta($user_id, self::META_REWARDED, true);
        if ($redeemed === 'yes') {
            return 'redeemed';
        }

        $review_status = get_user_meta($user_id, self::META_REVIEW_STATUS, true);
        if ($review_status === 'approved') {
            return 'pending'; // cleared by admin and now payable
        }
        if ($review_status === 'rejected') {
            return 'rejected'; // confirmed invalid and never payable
        }

        $flags = $this->get_user_fraud_flags($user_id);
        return !empty($flags) ? 'flagged' : 'pending';
    }

    private function get_review_status_label($status) {
        if ($status === 'approved') {
            return 'Approved';
        }
        if ($status === 'rejected') {
            return 'Rejected';
        }
        return '';
    }

    private function get_referred_users($from_date = '', $to_date = '') {
        $referrers = $this->get_referrers_map();
        $users = get_users([
            'fields' => ['ID', 'display_name', 'user_email', 'user_registered'],
            'number' => -1,
            'meta_query' => [
                [
                    'key' => 'promo_code',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $rows = [];
        foreach ($users as $user) {
            $registered_ts = strtotime($user->user_registered);
            if (!$registered_ts) {
                continue;
            }

            if ($from_date) {
                $from_ts = strtotime($from_date . ' 00:00:00');
                if ($registered_ts < $from_ts) {
                    continue;
                }
            }

            if ($to_date) {
                $to_ts = strtotime($to_date . ' 23:59:59');
                if ($registered_ts > $to_ts) {
                    continue;
                }
            }

            $promo_code = get_user_meta($user->ID, 'promo_code', true);
            if (!$promo_code) {
                continue;
            }

            $normalized = $this->normalize_code($promo_code);
            $referrer = isset($referrers[$normalized]) ? $referrers[$normalized] : null;
            $reward_amount = $this->get_reward_for_datetime($user->user_registered);
            $status = $this->referral_status_for_user($user->ID);
            $fraud_flags = $this->get_user_fraud_flags($user->ID);

            $rows[] = [
                'date' => date_i18n('Y-m-d', $registered_ts),
                'time' => date_i18n('H:i', $registered_ts),
                'registered_raw' => $user->user_registered,
                'reward_amount' => $reward_amount,
                'reward_status' => $status,
                'fraud_flags' => $fraud_flags,
                'fraud_reason_hints' => $this->get_fraud_reason_hints($fraud_flags),
                'fraud_recommendation' => $this->get_fraud_review_recommendation($fraud_flags),
                'fraud_flagged_at' => get_user_meta($user->ID, self::META_FRAUD_FLAGGED_AT, true),
                'review_status' => get_user_meta($user->ID, self::META_REVIEW_STATUS, true),
                'reviewed_at' => get_user_meta($user->ID, self::META_REVIEWED_AT, true),
                'review_note' => get_user_meta($user->ID, self::META_REVIEW_NOTE, true),
                'rewarded_at' => get_user_meta($user->ID, self::META_REWARDED_AT, true),
                'rewarded_amount' => get_user_meta($user->ID, self::META_REWARDED_AMOUNT, true),
                'rewarded_batch' => get_user_meta($user->ID, self::META_REWARDED_BATCH, true),
                'referred_id' => $user->ID,
                'referred_name' => $user->display_name,
                'referred_email' => $user->user_email,
                'referred_mobile' => get_user_meta($user->ID, 'mobile_number', true),
                'promo_code_used' => $promo_code,
                'referrer_id' => $referrer ? $referrer['id'] : 0,
                'referrer_name' => $referrer ? $referrer['name'] : 'Unknown / code not matched',
                'referrer_email' => $referrer ? $referrer['email'] : '',
                'referrer_mobile' => $referrer ? $referrer['mobile'] : '',
                'referrer_code' => $referrer ? $referrer['code'] : '',
            ];
        }

        usort($rows, function($a, $b) {
            return strcmp($b['date'] . $b['time'], $a['date'] . $a['time']);
        });

        return $rows;
    }

    private function build_summary($rows) {
        $summary = [];

        foreach ($rows as $row) {
            $key = $row['referrer_id'] ? 'user_' . $row['referrer_id'] : 'unknown_' . $this->normalize_code($row['promo_code_used']);

            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'referrer_id' => $row['referrer_id'],
                    'referrer_name' => $row['referrer_name'],
                    'referrer_email' => $row['referrer_email'],
                    'referrer_mobile' => $row['referrer_mobile'],
                    'referrer_code' => $row['referrer_code'] ?: $row['promo_code_used'],
                    'count' => 0,
                    'pending_count' => 0,
                    'redeemed_count' => 0,
                    'flagged_count' => 0,
                    'rejected_count' => 0,
                    'amount' => 0,
                    'pending_amount' => 0,
                    'redeemed_amount' => 0,
                    'people' => [],
                    'pending_ids' => [],
                ];
            }

            $amount = floatval($row['reward_amount']);
            $summary[$key]['count']++;
            $summary[$key]['amount'] += $amount;
            $summary[$key]['people'][] = $row['referred_name'];

            if ($row['reward_status'] === 'redeemed') {
                $summary[$key]['redeemed_count']++;
                $summary[$key]['redeemed_amount'] += $amount;
            } elseif ($row['reward_status'] === 'flagged') {
                $summary[$key]['flagged_count']++;
            } elseif ($row['reward_status'] === 'rejected') {
                $summary[$key]['rejected_count']++;
            } else {
                $summary[$key]['pending_count']++;
                $summary[$key]['pending_amount'] += $amount;
                $summary[$key]['pending_ids'][] = intval($row['referred_id']);
            }
        }

        uasort($summary, function($a, $b) {
            return $b['pending_count'] <=> $a['pending_count'];
        });

        return $summary;
    }

    private function get_filter_dates() {
        $today = current_time('Y-m-d');
        $from = isset($_GET['from_date']) ? sanitize_text_field(wp_unslash($_GET['from_date'])) : $today;
        $to = isset($_GET['to_date']) ? sanitize_text_field(wp_unslash($_GET['to_date'])) : $today;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = $today;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = $today;
        }

        return [$from, $to];
    }

    public function mark_referrals_rewarded() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to mark rewards.');
        }
        check_admin_referer(self::NONCE_ACTION_MARK_REWARDED);

        $from = isset($_POST['from_date']) ? sanitize_text_field(wp_unslash($_POST['from_date'])) : current_time('Y-m-d');
        $to = isset($_POST['to_date']) ? sanitize_text_field(wp_unslash($_POST['to_date'])) : current_time('Y-m-d');
        $referrer_id = isset($_POST['referrer_id']) ? intval($_POST['referrer_id']) : 0;
        $currency = $this->get_currency();

        $rows = $this->get_referred_users($from, $to);
        $marked = 0;
        $amount = 0;
        $batch = 'BT-REF-' . current_time('Ymd-His') . '-' . wp_rand(100, 999);

        foreach ($rows as $row) {
            if (intval($row['referrer_id']) !== $referrer_id || $row['reward_status'] !== 'pending') {
                continue;
            }
            update_user_meta($row['referred_id'], self::META_REWARDED, 'yes');
            update_user_meta($row['referred_id'], self::META_REWARDED_AT, current_time('mysql'));
            update_user_meta($row['referred_id'], self::META_REWARDED_BY, get_current_user_id());
            update_user_meta($row['referred_id'], self::META_REWARDED_AMOUNT, floatval($row['reward_amount']));
            update_user_meta($row['referred_id'], self::META_REWARDED_CURRENCY, $currency);
            update_user_meta($row['referred_id'], self::META_REWARDED_BATCH, $batch);
            $marked++;
            $amount += floatval($row['reward_amount']);
        }

        wp_safe_redirect(admin_url('admin.php?page=buytap-referral-rewards&from_date=' . rawurlencode($from) . '&to_date=' . rawurlencode($to) . '&marked=' . intval($marked) . '&marked_amount=' . rawurlencode(number_format($amount, 2, '.', ''))));
        exit;
    }

    public function mark_referrals_pending() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to reverse rewards.');
        }
        check_admin_referer(self::NONCE_ACTION_MARK_PENDING);

        $from = isset($_POST['from_date']) ? sanitize_text_field(wp_unslash($_POST['from_date'])) : current_time('Y-m-d');
        $to = isset($_POST['to_date']) ? sanitize_text_field(wp_unslash($_POST['to_date'])) : current_time('Y-m-d');
        $referrer_id = isset($_POST['referrer_id']) ? intval($_POST['referrer_id']) : 0;

        $rows = $this->get_referred_users($from, $to);
        $changed = 0;
        foreach ($rows as $row) {
            if (intval($row['referrer_id']) !== $referrer_id || $row['reward_status'] !== 'redeemed') {
                continue;
            }
            delete_user_meta($row['referred_id'], self::META_REWARDED);
            delete_user_meta($row['referred_id'], self::META_REWARDED_AT);
            delete_user_meta($row['referred_id'], self::META_REWARDED_BY);
            delete_user_meta($row['referred_id'], self::META_REWARDED_AMOUNT);
            delete_user_meta($row['referred_id'], self::META_REWARDED_CURRENCY);
            delete_user_meta($row['referred_id'], self::META_REWARDED_BATCH);
            $changed++;
        }

        wp_safe_redirect(admin_url('admin.php?page=buytap-referral-rewards&from_date=' . rawurlencode($from) . '&to_date=' . rawurlencode($to) . '&pending_restored=' . intval($changed)));
        exit;
    }

    public function review_referral_reward() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to review referrals.');
        }
        check_admin_referer(self::NONCE_ACTION_REVIEW_REFERRAL);

        $user_id = isset($_POST['referred_id']) ? intval($_POST['referred_id']) : 0;
        $decision = isset($_POST['review_decision']) ? sanitize_text_field(wp_unslash($_POST['review_decision'])) : '';
        $from = isset($_POST['from_date']) ? sanitize_text_field(wp_unslash($_POST['from_date'])) : current_time('Y-m-d');
        $to = isset($_POST['to_date']) ? sanitize_text_field(wp_unslash($_POST['to_date'])) : current_time('Y-m-d');
        $note = isset($_POST['review_note']) ? sanitize_text_field(wp_unslash($_POST['review_note'])) : '';

        if (!$user_id || !in_array($decision, ['approved', 'rejected', 'reset'], true)) {
            wp_die('Invalid review request.');
        }

        if ($decision === 'approved') {
            update_user_meta($user_id, self::META_REVIEW_STATUS, 'approved');
            update_user_meta($user_id, self::META_REVIEW_NOTE, $note ?: 'Cleared by admin review');
        } elseif ($decision === 'rejected') {
            update_user_meta($user_id, self::META_REVIEW_STATUS, 'rejected');
            update_user_meta($user_id, self::META_REVIEW_NOTE, $note ?: 'Rejected by admin review');
            // Rejected referrals must not remain marked as rewarded.
            delete_user_meta($user_id, self::META_REWARDED);
            delete_user_meta($user_id, self::META_REWARDED_AT);
            delete_user_meta($user_id, self::META_REWARDED_BY);
            delete_user_meta($user_id, self::META_REWARDED_AMOUNT);
            delete_user_meta($user_id, self::META_REWARDED_CURRENCY);
            delete_user_meta($user_id, self::META_REWARDED_BATCH);
        } else {
            delete_user_meta($user_id, self::META_REVIEW_STATUS);
            delete_user_meta($user_id, self::META_REVIEW_NOTE);
        }

        update_user_meta($user_id, self::META_REVIEWED_AT, current_time('mysql'));
        update_user_meta($user_id, self::META_REVIEWED_BY, get_current_user_id());

        wp_safe_redirect(admin_url('admin.php?page=buytap-referral-rewards&from_date=' . rawurlencode($from) . '&to_date=' . rawurlencode($to) . '&reviewed=1'));
        exit;
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        list($from, $to) = $this->get_filter_dates();
        $rows = $this->get_referred_users($from, $to);
        $summary = $this->build_summary($rows);
        $currency = $this->get_currency();
        $current_reward = $this->get_current_reward_amount();
        $total_payout = array_sum(array_map(function($row) { return floatval($row['reward_amount']); }, $rows));
        $pending_payout = array_sum(array_map(function($row) { return $row['reward_status'] === 'pending' ? floatval($row['reward_amount']) : 0; }, $rows));
        $redeemed_payout = $total_payout - $pending_payout;
        $history = $this->get_rate_history();
        ?>
        <div class="wrap buytap-referral-rewards">
            <h1>BuyTap Referral Reward Reports</h1>
            <p>This report uses existing referral fields: <code>my_referral_code</code> and <code>promo_code</code>. Rewards are calculated using the rate active when each referred user registered. Flagged referrals are shown for review and are not payable until cleared.</p>

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Reward rate saved successfully.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['marked'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(intval($_GET['marked'])); ?> referrals marked as redeemed. Amount: <?php echo esc_html($currency . ' ' . number_format(floatval($_GET['marked_amount'] ?? 0), 2)); ?>.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['pending_restored'])): ?>
                <div class="notice notice-warning is-dismissible"><p><?php echo esc_html(intval($_GET['pending_restored'])); ?> referrals restored to pending.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['reviewed'])): ?>
                <div class="notice notice-success is-dismissible"><p>Referral review decision saved successfully.</p></div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #dcdcde;padding:16px;margin:16px 0;max-width:980px;">
                <h2>Reward Settings</h2>
                <p><strong>Current reward:</strong> <?php echo esc_html($currency . ' ' . number_format($current_reward, 2)); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="buytap_save_referral_reward_rate" />
                    <?php wp_nonce_field(self::NONCE_ACTION_SAVE_RATE); ?>
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="reward_amount">New reward per referred user</label></th><td><input type="number" step="0.01" min="0" id="reward_amount" name="reward_amount" value="<?php echo esc_attr($current_reward); ?>" /> <span><?php echo esc_html($currency); ?></span></td></tr>
                        <tr><th scope="row"><label for="currency_label">Currency Label</label></th><td><input type="text" id="currency_label" name="currency_label" value="<?php echo esc_attr($currency); ?>" /></td></tr>
                        <tr><th scope="row">Effective From</th><td><input type="date" name="effective_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" /> <input type="time" name="effective_time" value="00:00" /><p class="description">Old referrals keep the old rate. New rate starts from this date/time.</p></td></tr>
                    </table>
                    <?php submit_button('Save New Reward Rate'); ?>
                </form>

                <h3>Reward Rate History</h3>
                <table class="widefat striped" style="max-width:720px;">
                    <thead><tr><th>Effective From</th><th>Reward</th><th>Saved At</th></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($history) as $item): ?>
                        <tr><td><?php echo esc_html($item['effective_from']); ?></td><td><strong><?php echo esc_html(($item['currency'] ?: $currency) . ' ' . number_format(floatval($item['amount']), 2)); ?></strong></td><td><?php echo esc_html($item['created_at']); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="background:#fff;border:1px solid #dcdcde;padding:16px;margin:16px 0;max-width:980px;">
                <h2>Filter Report</h2>
                <form method="get">
                    <input type="hidden" name="page" value="buytap-referral-rewards" />
                    <label>From: <input type="date" name="from_date" value="<?php echo esc_attr($from); ?>" /></label>
                    &nbsp;&nbsp;
                    <label>To: <input type="date" name="to_date" value="<?php echo esc_attr($to); ?>" /></label>
                    &nbsp;&nbsp;
                    <?php submit_button('Apply Filter', 'secondary', '', false); ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=buytap-referral-rewards')); ?>">Today</a>
                    <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=buytap_export_referrals_csv&from_date=' . rawurlencode($from) . '&to_date=' . rawurlencode($to)), 'buytap_export_referrals_csv')); ?>">Export CSV</a>
                </form>
            </div>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0;">
                <div style="background:#fff;border:1px solid #dcdcde;padding:16px;min-width:220px;"><strong>Total Referrals</strong><br><span style="font-size:28px;font-weight:700;"><?php echo esc_html(count($rows)); ?></span></div>
                <div style="background:#fff;border:1px solid #dcdcde;padding:16px;min-width:220px;"><strong>Pending Amount</strong><br><span style="font-size:28px;font-weight:700;color:#b45309;"><?php echo esc_html($currency . ' ' . number_format($pending_payout, 2)); ?></span></div>
                <div style="background:#fff;border:1px solid #dcdcde;padding:16px;min-width:220px;"><strong>Redeemed Amount</strong><br><span style="font-size:28px;font-weight:700;color:#15803d;"><?php echo esc_html($currency . ' ' . number_format($redeemed_payout, 2)); ?></span></div>
                <div style="background:#fff;border:1px solid #dcdcde;padding:16px;min-width:220px;"><strong>Total Amount</strong><br><span style="font-size:28px;font-weight:700;"><?php echo esc_html($currency . ' ' . number_format($total_payout, 2)); ?></span></div>
            </div>

            <h2>Payout Summary</h2>
            <table class="widefat striped">
                <thead><tr><th>Referrer</th><th>Email</th><th>M-Pesa</th><th>Referral Code</th><th>Total</th><th>Pending</th><th>Flagged</th><th>Rejected</th><th>Redeemed</th><th>Pending Amount</th><th>Action</th></tr></thead>
                <tbody>
                <?php if (empty($summary)): ?>
                    <tr><td colspan="9">No referrals found for this date range.</td></tr>
                <?php else: foreach ($summary as $item): ?>
                    <tr>
                        <td><?php echo esc_html($item['referrer_name']); ?></td>
                        <td><?php echo esc_html($item['referrer_email']); ?></td>
                        <td><?php echo esc_html($item['referrer_mobile']); ?></td>
                        <td><code><?php echo esc_html($item['referrer_code']); ?></code></td>
                        <td><?php echo esc_html($item['count']); ?></td>
                        <td><strong style="color:#b45309;"><?php echo esc_html($item['pending_count']); ?></strong></td>
                        <td><strong style="color:#dc2626;"><?php echo esc_html($item['flagged_count']); ?></strong></td>
                        <td><strong style="color:#7f1d1d;"><?php echo esc_html($item['rejected_count']); ?></strong></td>
                        <td><strong style="color:#15803d;"><?php echo esc_html($item['redeemed_count']); ?></strong></td>
                        <td><strong><?php echo esc_html($currency . ' ' . number_format($item['pending_amount'], 2)); ?></strong></td>
                        <td>
                            <?php if ($item['referrer_id'] && $item['pending_count'] > 0): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('Mark these pending referral rewards as redeemed?');">
                                    <input type="hidden" name="action" value="buytap_mark_referrals_rewarded" />
                                    <input type="hidden" name="referrer_id" value="<?php echo esc_attr($item['referrer_id']); ?>" />
                                    <input type="hidden" name="from_date" value="<?php echo esc_attr($from); ?>" />
                                    <input type="hidden" name="to_date" value="<?php echo esc_attr($to); ?>" />
                                    <?php wp_nonce_field(self::NONCE_ACTION_MARK_REWARDED); ?>
                                    <button class="button button-primary">I have awarded them</button>
                                </form>
                            <?php else: ?>
                                <span style="color:#15803d;font-weight:700;">All redeemed</span>
                            <?php endif; ?>
                            <?php if ($item['referrer_id'] && $item['redeemed_count'] > 0): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:6px;" onsubmit="return confirm('Reverse redeemed status back to pending for this date range?');">
                                    <input type="hidden" name="action" value="buytap_mark_referrals_pending" />
                                    <input type="hidden" name="referrer_id" value="<?php echo esc_attr($item['referrer_id']); ?>" />
                                    <input type="hidden" name="from_date" value="<?php echo esc_attr($from); ?>" />
                                    <input type="hidden" name="to_date" value="<?php echo esc_attr($to); ?>" />
                                    <?php wp_nonce_field(self::NONCE_ACTION_MARK_PENDING); ?>
                                    <button class="button">Undo</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:30px;">Referral Details</h2>
            <table class="widefat striped">
                <thead><tr><th>Date</th><th>Time</th><th>Referrer</th><th>Referrer M-Pesa</th><th>Referred Person</th><th>Referred Email</th><th>Reward</th><th>Status</th><th>Fraud Flags</th><th>Reason Hint</th><th>Review</th><th>Redeemed At</th><th>Code Used</th></tr></thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="10">No referral details found.</td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row['date']); ?></td>
                        <td><?php echo esc_html($row['time']); ?></td>
                        <td><?php echo esc_html($row['referrer_name']); ?></td>
                        <td><?php echo esc_html($row['referrer_mobile']); ?></td>
                        <td><?php echo esc_html($row['referred_name']); ?></td>
                        <td><?php echo esc_html($row['referred_email']); ?></td>
                        <td><strong><?php echo esc_html($currency . ' ' . number_format($row['reward_amount'], 2)); ?></strong></td>
                        <td><?php echo $row['reward_status'] === 'redeemed' ? '<strong style="color:#15803d;">Redeemed</strong>' : ($row['reward_status'] === 'flagged' ? '<strong style="color:#dc2626;">Under Review</strong>' : ($row['reward_status'] === 'rejected' ? '<strong style="color:#7f1d1d;">Rejected</strong>' : '<strong style="color:#b45309;">Pending</strong>')); ?></td>
                        <td><?php echo empty($row['fraud_flags']) ? '<span style="color:#15803d;">Clear</span>' : '<strong style="color:#dc2626;">' . esc_html(implode(', ', $row['fraud_flags'])) . '</strong>'; ?><?php if (!empty($row['review_note'])): ?><br><small><?php echo esc_html($row['review_note']); ?></small><?php endif; ?></td>
                        <td><?php echo $this->render_admin_reason_hint($row['fraud_flags']); ?></td>
                        <td>
                            <?php if ($row['reward_status'] === 'flagged'): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('Approve this flagged referral and make it payable?');">
                                    <input type="hidden" name="action" value="buytap_review_referral_reward" />
                                    <input type="hidden" name="review_decision" value="approved" />
                                    <input type="hidden" name="referred_id" value="<?php echo esc_attr($row['referred_id']); ?>" />
                                    <input type="hidden" name="from_date" value="<?php echo esc_attr($from); ?>" />
                                    <input type="hidden" name="to_date" value="<?php echo esc_attr($to); ?>" />
                                    <?php wp_nonce_field(self::NONCE_ACTION_REVIEW_REFERRAL); ?>
                                    <button class="button button-primary">Approve</button>
                                </form>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:4px;" onsubmit="return confirm('Reject this referral as invalid?');">
                                    <input type="hidden" name="action" value="buytap_review_referral_reward" />
                                    <input type="hidden" name="review_decision" value="rejected" />
                                    <input type="hidden" name="referred_id" value="<?php echo esc_attr($row['referred_id']); ?>" />
                                    <input type="hidden" name="from_date" value="<?php echo esc_attr($from); ?>" />
                                    <input type="hidden" name="to_date" value="<?php echo esc_attr($to); ?>" />
                                    <?php wp_nonce_field(self::NONCE_ACTION_REVIEW_REFERRAL); ?>
                                    <button class="button">Reject</button>
                                </form>
                            <?php elseif ($row['reward_status'] === 'rejected'): ?>
                                <strong style="color:#7f1d1d;">Rejected</strong>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:4px;" onsubmit="return confirm('Move this referral back to review?');">
                                    <input type="hidden" name="action" value="buytap_review_referral_reward" />
                                    <input type="hidden" name="review_decision" value="reset" />
                                    <input type="hidden" name="referred_id" value="<?php echo esc_attr($row['referred_id']); ?>" />
                                    <input type="hidden" name="from_date" value="<?php echo esc_attr($from); ?>" />
                                    <input type="hidden" name="to_date" value="<?php echo esc_attr($to); ?>" />
                                    <?php wp_nonce_field(self::NONCE_ACTION_REVIEW_REFERRAL); ?>
                                    <button class="button">Reset</button>
                                </form>
                            <?php elseif ($row['review_status'] === 'approved'): ?>
                                <strong style="color:#15803d;">Approved</strong>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($row['rewarded_at']); ?></td>
                        <td><code><?php echo esc_html($row['promo_code_used']); ?></code></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function export_csv() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to export this report.');
        }

        check_admin_referer('buytap_export_referrals_csv');

        $from = isset($_GET['from_date']) ? sanitize_text_field(wp_unslash($_GET['from_date'])) : current_time('Y-m-d');
        $to = isset($_GET['to_date']) ? sanitize_text_field(wp_unslash($_GET['to_date'])) : current_time('Y-m-d');
        $rows = $this->get_referred_users($from, $to);
        $currency = $this->get_currency();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=buytap-referral-report-' . $from . '-to-' . $to . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Time', 'Referrer Name', 'Referrer Email', 'Referrer M-Pesa', 'Referral Code', 'Referred Name', 'Referred Email', 'Referred M-Pesa', 'Code Used', 'Reward Currency', 'Reward Amount', 'Reward Status', 'Fraud Flags', 'Fraud Reason Hints', 'Admin Recommendation', 'Review Status', 'Review Note', 'Redeemed At', 'Batch']);

        foreach ($rows as $row) {
            fputcsv($out, [$row['date'], $row['time'], $row['referrer_name'], $row['referrer_email'], $row['referrer_mobile'], $row['referrer_code'], $row['referred_name'], $row['referred_email'], $row['referred_mobile'], $row['promo_code_used'], $currency, number_format(floatval($row['reward_amount']), 2, '.', ''), $row['reward_status'], implode(' | ', $row['fraud_flags']), implode(' | ', $row['fraud_reason_hints']), $row['fraud_recommendation'], $row['review_status'], $row['review_note'], $row['rewarded_at'], $row['rewarded_batch']]);
        }

        fclose($out);
        exit;
    }

    public function enqueue_frontend_assets() {
        $css = "
            .bt-ref-widget,.bt-ref-widget *{box-sizing:border-box;}
            .bt-ref-widget{font-family:inherit;color:#fff;width:100%;max-width:100%;display:block;}
            .bt-ref-open{border:0;background:linear-gradient(135deg,#00ffc2,#7c3cff);color:#fff;border-radius:999px;padding:13px 22px;font-weight:900;cursor:pointer;box-shadow:0 0 22px rgba(0,255,190,.25);transition:.25s ease;width:auto;max-width:100%;}
            .bt-ref-open:hover{transform:translateY(-2px);filter:brightness(1.08);}
            .bt-ref-mini{width:100%;max-width:none;margin:0;background:linear-gradient(145deg,rgba(6,6,6,.96),rgba(17,17,17,.96));border:1px solid rgba(0,255,190,.35);border-radius:20px;padding:22px;color:#fff;box-shadow:0 0 24px rgba(0,255,190,.12);display:grid;grid-template-columns:1.1fr 1.4fr auto;align-items:center;gap:18px;}
            .bt-ref-mini h3{margin:0 0 8px;font-size:clamp(24px,3vw,34px);font-weight:900;color:#fff;line-height:1.05;}
            .bt-ref-code{display:inline-flex;width:max-content;max-width:100%;font-size:12px;border:1px solid rgba(255,255,255,.18);border-radius:999px;padding:7px 11px;color:#00ffc2;background:rgba(0,255,190,.08);white-space:nowrap;}
            .bt-ref-mini-grid{display:grid;grid-template-columns:repeat(3,minmax(110px,1fr));gap:12px;margin:0;}
            .bt-ref-mini-stat{background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:14px;min-height:76px;display:flex;flex-direction:column;justify-content:center;}
            .bt-ref-mini-label{font-size:12px;color:#aaa;margin-bottom:5px;}
            .bt-ref-mini-value{font-size:22px;font-weight:900;color:#00ffc2;line-height:1;}
            .bt-ref-mini-reward{margin:0;color:#bbb;font-size:14px;text-align:right;white-space:nowrap;}
            .bt-ref-actions{display:flex;flex-direction:column;align-items:flex-end;gap:10px;}
            .bt-ref-overlay{position:fixed;inset:0;background:rgba(0,0,0,.78);backdrop-filter:blur(8px);display:none;z-index:999999;align-items:center;justify-content:center;padding:20px;}
            .bt-ref-overlay.is-open{display:flex;}
            .bt-ref-modal{width:min(980px,96vw);max-height:88vh;overflow:auto;background:linear-gradient(145deg,#050505,#111);border:1px solid rgba(0,255,190,.35);border-radius:22px;box-shadow:0 0 40px rgba(0,255,190,.2);padding:22px;color:#fff;}
            .bt-ref-modal-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:16px;}
            .bt-ref-title{font-size:30px;font-weight:900;margin:0;color:#fff;}
            .bt-ref-close{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:#fff;border-radius:999px;width:38px;height:38px;font-size:22px;cursor:pointer;line-height:1;}
            .bt-ref-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:16px 0;}
            .bt-ref-stat{background:rgba(255,255,255,.045);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:12px;}
            .bt-ref-label{font-size:12px;color:#aaa;margin-bottom:4px;}
            .bt-ref-value{font-size:20px;font-weight:900;color:#00ffc2;}
            .bt-ref-value.pending{color:#fbbf24;}.bt-ref-value.redeemed{color:#22c55e;}
            .bt-ref-list{display:flex;flex-direction:column;gap:10px;margin-top:16px;}
            .bt-ref-row{display:grid;grid-template-columns:100px minmax(180px,1fr) 120px 120px;gap:10px;align-items:center;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:12px;}
            .bt-ref-date{font-size:12px;color:#bbb;}.bt-ref-name{font-weight:800;color:#fff;}.bt-ref-email{font-size:12px;color:#bbb;word-break:break-all;}.bt-ref-amount{font-weight:900;color:#00ffc2;}
            .bt-ref-badge{display:inline-flex;justify-content:center;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:900;}
            .bt-ref-badge.pending{background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.35);}
            .bt-ref-badge.redeemed{background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.35);}
            .bt-ref-badge.flagged{background:rgba(239,68,68,.12);color:#f87171;border:1px solid rgba(239,68,68,.35);}
            .bt-ref-badge.rejected{background:rgba(127,29,29,.2);color:#fca5a5;border:1px solid rgba(127,29,29,.5);}
            .bt-ref-empty{color:#ccc;background:rgba(255,255,255,.04);padding:14px;border-radius:14px;}
            .bt-champ-widget,.bt-champ-widget *{box-sizing:border-box;}
            .bt-champ-widget{width:100%;max-width:100%;font-family:inherit;color:#fff;}
            .bt-champ-card{width:100%;background:linear-gradient(145deg,rgba(6,6,6,.96),rgba(17,17,17,.96));border:1px solid rgba(0,255,190,.35);border-radius:22px;padding:24px;color:#fff;box-shadow:0 0 28px rgba(0,255,190,.14);overflow:hidden;}
            .bt-champ-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:18px;}
            .bt-champ-title{margin:0;font-size:clamp(24px,3vw,38px);font-weight:900;line-height:1.05;color:#fff;}
            .bt-champ-sub{margin:8px 0 0;color:#b7b7b7;font-size:14px;}
            .bt-champ-pill{display:inline-flex;align-items:center;gap:7px;border:1px solid rgba(0,255,190,.35);background:rgba(0,255,190,.08);color:#00ffc2;border-radius:999px;padding:8px 12px;font-size:12px;font-weight:800;white-space:nowrap;}
            .bt-champ-hero{display:grid;grid-template-columns:minmax(220px,.9fr) minmax(260px,1.4fr);gap:18px;align-items:stretch;}
            .bt-champ-winner{background:rgba(255,255,255,.045);border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:18px;display:flex;flex-direction:column;justify-content:center;min-height:170px;}
            .bt-champ-crown{font-size:34px;line-height:1;margin-bottom:8px;}
            .bt-champ-label{color:#aaa;font-size:12px;text-transform:uppercase;letter-spacing:.08em;font-weight:800;}
            .bt-champ-name{font-size:clamp(22px,3vw,34px);font-weight:900;color:#fff;margin:8px 0 4px;line-height:1.05;}
            .bt-champ-count{font-size:15px;color:#00ffc2;font-weight:900;}
            .bt-champ-list{display:flex;flex-direction:column;gap:10px;}
            .bt-champ-row{display:grid;grid-template-columns:38px minmax(120px,1fr) 58px;gap:12px;align-items:center;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:12px;}
            .bt-champ-rank{width:32px;height:32px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:rgba(0,255,190,.12);color:#00ffc2;font-weight:900;font-size:13px;}
            .bt-champ-person{min-width:0;}
            .bt-champ-person strong{display:block;color:#fff;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
            .bt-champ-bar-wrap{height:8px;background:rgba(255,255,255,.09);border-radius:999px;overflow:hidden;margin-top:7px;}
            .bt-champ-bar{height:100%;background:linear-gradient(90deg,#00ffc2,#7c3cff);border-radius:999px;min-width:8px;}
            .bt-champ-num{text-align:right;color:#fff;font-weight:900;font-size:16px;}
            .bt-champ-empty{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:18px;color:#ccc;}
            @media(max-width:760px){.bt-champ-head{flex-direction:column}.bt-champ-hero{grid-template-columns:1fr}.bt-champ-card{padding:18px}.bt-champ-row{grid-template-columns:34px 1fr 48px}}
            @media(max-width:900px){.bt-ref-mini{grid-template-columns:1fr;align-items:stretch}.bt-ref-actions{align-items:stretch}.bt-ref-mini-reward{text-align:left;white-space:normal}.bt-ref-open{width:100%;}.bt-ref-mini-grid{grid-template-columns:repeat(3,1fr)}}
            @media(max-width:760px){.bt-ref-stats{grid-template-columns:1fr 1fr}.bt-ref-row{grid-template-columns:1fr}.bt-ref-title{font-size:24px}}
            @media(max-width:520px){.bt-ref-widget{width:100%;}.bt-ref-mini{width:100%;border-radius:18px;padding:18px;gap:14px}.bt-ref-mini-grid{grid-template-columns:1fr}.bt-ref-mini-stat{min-height:70px}.bt-ref-modal{width:96vw;padding:16px}.bt-ref-stats{grid-template-columns:1fr}.bt-ref-overlay{padding:12px}.bt-ref-open{padding:14px 18px}}
        ";
        wp_register_style('buytap-referral-reward-reports-front-v4', false);
        wp_enqueue_style('buytap-referral-reward-reports-front-v4');
        wp_add_inline_style('buytap-referral-reward-reports-front-v4', $css);

        $js = "
            document.addEventListener('click', function(e){
                var openBtn = e.target.closest('[data-bt-ref-open]');
                if(openBtn){
                    var id = openBtn.getAttribute('data-bt-ref-open');
                    var modal = document.getElementById(id);
                    if(modal){ modal.classList.add('is-open'); document.body.style.overflow='hidden'; }
                }
                var closeBtn = e.target.closest('[data-bt-ref-close]');
                if(closeBtn){
                    var overlay = closeBtn.closest('.bt-ref-overlay');
                    if(overlay){ overlay.classList.remove('is-open'); document.body.style.overflow=''; }
                }
                if(e.target.classList && e.target.classList.contains('bt-ref-overlay')){
                    e.target.classList.remove('is-open'); document.body.style.overflow='';
                }
            });
            document.addEventListener('keydown', function(e){
                if(e.key === 'Escape'){
                    document.querySelectorAll('.bt-ref-overlay.is-open').forEach(function(el){ el.classList.remove('is-open'); });
                    document.body.style.overflow='';
                }
            });
        ";
        wp_register_script('buytap-referral-reward-reports-front-v4', '', [], false, true);
        wp_enqueue_script('buytap-referral-reward-reports-front-v4');
        wp_add_inline_script('buytap-referral-reward-reports-front-v4', $js);
    }

    public function shortcode_my_referrals_details($atts = []) {
        if (!is_user_logged_in()) {
            return '<p>Please log in to view your referrals.</p>';
        }

        $atts = shortcode_atts([
            'limit' => 50,
            'show_email' => 'yes',
            'button_text' => 'View My Referrals',
            'mode' => 'popup',
        ], $atts, 'buytap_my_referrals_details');

        $limit = max(1, intval($atts['limit']));
        $show_email = strtolower($atts['show_email']) !== 'no';
        $mode = strtolower(sanitize_text_field($atts['mode']));
        $button_text = sanitize_text_field($atts['button_text']);

        $current_user = wp_get_current_user();
        $my_code = get_user_meta($current_user->ID, 'my_referral_code', true);
        if (!$my_code) {
            return '<p>No referral code found.</p>';
        }

        $all_rows = $this->get_referred_users('', '');
        $my_rows = array_values(array_filter($all_rows, function($row) use ($current_user) {
            return intval($row['referrer_id']) === intval($current_user->ID);
        }));

        $currency = $this->get_currency();
        $total = array_sum(array_map(function($row) { return floatval($row['reward_amount']); }, $my_rows));
        $pending_total = array_sum(array_map(function($row) { return $row['reward_status'] === 'pending' ? floatval($row['reward_amount']) : 0; }, $my_rows));
        $redeemed_total = $total - $pending_total;
        $pending_count = count(array_filter($my_rows, function($row) { return $row['reward_status'] === 'pending'; }));
        $redeemed_count = count($my_rows) - $pending_count;
        $display_rows = array_slice($my_rows, 0, $limit);
        $modal_id = 'bt-ref-modal-' . esc_attr($current_user->ID) . '-' . wp_rand(1000, 9999);

        ob_start();
        ?>
        <div class="bt-ref-widget">
            <div class="bt-ref-mini">
                <h3>My Referrals</h3>
                <div class="bt-ref-code"><?php echo esc_html($my_code); ?></div>
                <div class="bt-ref-mini-grid">
                    <div class="bt-ref-mini-stat"><div class="bt-ref-mini-label">Total</div><div class="bt-ref-mini-value"><?php echo esc_html(count($my_rows)); ?></div></div>
                    <div class="bt-ref-mini-stat"><div class="bt-ref-mini-label">Pending</div><div class="bt-ref-mini-value" style="color:#fbbf24;"><?php echo esc_html($pending_count); ?></div></div>
                    <div class="bt-ref-mini-stat"><div class="bt-ref-mini-label">Redeemed</div><div class="bt-ref-mini-value" style="color:#22c55e;"><?php echo esc_html($redeemed_count); ?></div></div>
                </div>
                <div class="bt-ref-actions"><p class="bt-ref-mini-reward">Pending reward: <strong style="color:#fbbf24;"><?php echo esc_html($currency . ' ' . number_format($pending_total, 2)); ?></strong></p>
                <?php if ($mode === 'inline'): ?>
                    <div class="bt-ref-list">
                        <?php echo $this->render_frontend_rows($display_rows, $currency, $show_email); // escaped in method ?>
                    </div>
                <?php else: ?>
                    <button type="button" class="bt-ref-open" data-bt-ref-open="<?php echo esc_attr($modal_id); ?>"><?php echo esc_html($button_text); ?></button>
                <?php endif; ?>
                </div>
            </div>

            <?php if ($mode !== 'inline'): ?>
                <div id="<?php echo esc_attr($modal_id); ?>" class="bt-ref-overlay" aria-hidden="true">
                    <div class="bt-ref-modal" role="dialog" aria-modal="true">
                        <div class="bt-ref-modal-head">
                            <div>
                                <h3 class="bt-ref-title">My Referrals</h3>
                                <span class="bt-ref-code"><?php echo esc_html($my_code); ?></span>
                            </div>
                            <button type="button" class="bt-ref-close" data-bt-ref-close="1" aria-label="Close">×</button>
                        </div>
                        <div class="bt-ref-stats">
                            <div class="bt-ref-stat"><div class="bt-ref-label">Total referred</div><div class="bt-ref-value"><?php echo esc_html(count($my_rows)); ?></div></div>
                            <div class="bt-ref-stat"><div class="bt-ref-label">Pending</div><div class="bt-ref-value pending"><?php echo esc_html($currency . ' ' . number_format($pending_total, 2)); ?></div></div>
                            <div class="bt-ref-stat"><div class="bt-ref-label">Redeemed</div><div class="bt-ref-value redeemed"><?php echo esc_html($currency . ' ' . number_format($redeemed_total, 2)); ?></div></div>
                            <div class="bt-ref-stat"><div class="bt-ref-label">All-time reward</div><div class="bt-ref-value"><?php echo esc_html($currency . ' ' . number_format($total, 2)); ?></div></div>
                        </div>
                        <div class="bt-ref-list">
                            <?php echo $this->render_frontend_rows($display_rows, $currency, $show_email); // escaped in method ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function mask_public_name($full_name) {
        $full_name = trim((string) $full_name);
        $parts = preg_split('/\s+/', $full_name);
    
        if (empty($parts[0])) {
            return 'User';
        }
    
        $first = $parts[0];
    
        if (empty($parts[1])) {
            return $first;
        }
    
        $second_initial = strtoupper(substr($parts[1], 0, 1));
    
        return $first . ' ' . $second_initial . '*****';
    }
    public function shortcode_referral_champion($atts = []) {
        $atts = shortcode_atts([
            'limit' => 5,
            'title' => 'Referral Champion of the Day',
            'date' => '',
            'show_counts' => 'yes',
        ], $atts, 'buytap_referral_champion');

        $limit = max(1, intval($atts['limit']));
        $title = sanitize_text_field($atts['title']);
        $date = sanitize_text_field($atts['date']);
        $show_counts = strtolower($atts['show_counts']) !== 'no';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = current_time('Y-m-d');
        }

        $rows = $this->get_referred_users($date, $date);
        $summary = [];

        foreach ($rows as $row) {
            if (empty($row['referrer_id'])) {
                continue;
            }

            $key = 'user_' . intval($row['referrer_id']);
            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'name' => $row['referrer_name'],
                    'code' => $row['referrer_code'],
                    'count' => 0,
                ];
            }
            $summary[$key]['count']++;
        }

        uasort($summary, function($a, $b) {
            return intval($b['count']) <=> intval($a['count']);
        });

        $items = array_slice(array_values($summary), 0, $limit);
        $total_today = count($rows);
        $top_count = !empty($items) ? max(1, intval($items[0]['count'])) : 0;
        $champion = !empty($items) ? $items[0] : null;

        ob_start();
        ?>
        <div class="bt-champ-widget">
            <div class="bt-champ-card">
                <div class="bt-champ-head">
                    <div>
                        <h3 class="bt-champ-title"><?php echo esc_html($title); ?></h3>
                        <!--<p class="bt-champ-sub">Today’s referral trend based on new sign-ups.</p>-->
                    </div>
                    <div class="bt-champ-pill">📅 <?php echo esc_html(date_i18n('M j, Y', strtotime($date))); ?> · <?php echo esc_html($total_today); ?> total</div>
                </div>

                <?php if (empty($items)): ?>
                    <div class="bt-champ-empty">No referrals recorded today yet. The daily champion will appear here once referrals start coming in.</div>
                <?php else: ?>
                    <div class="bt-champ-hero">
                        <div class="bt-champ-winner">
                            <div class="bt-champ-crown">🏆</div>
                            <div class="bt-champ-label">Champion Referral Today</div>
                            <div class="bt-champ-name"><?php echo esc_html($this->mask_public_name($champion['name'])); ?></div>
                            <?php if ($show_counts): ?>
                                <div class="bt-champ-count"><?php echo esc_html($champion['count']); ?> referral<?php echo intval($champion['count']) === 1 ? '' : 's'; ?> today</div>
                            <?php endif; ?>
                        </div>

                        <div class="bt-champ-list">
                            <?php foreach ($items as $index => $item):
                                $count = intval($item['count']);
                                $width = $top_count > 0 ? max(8, round(($count / $top_count) * 100)) : 0;
                            ?>
                                <div class="bt-champ-row">
                                    <div class="bt-champ-rank"><?php echo esc_html($index + 1); ?></div>
                                    <div class="bt-champ-person">
                                        <strong><?php echo esc_html($this->mask_public_name($item['name'])); ?></strong>
                                        <div class="bt-champ-bar-wrap"><div class="bt-champ-bar" style="width:<?php echo esc_attr($width); ?>%;"></div></div>
                                    </div>
                                    <?php if ($show_counts): ?>
                                        <div class="bt-champ-num"><?php echo esc_html($count); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_frontend_rows($rows, $currency, $show_email) {
        ob_start();
        if (empty($rows)) {
            echo '<div class="bt-ref-empty">You have not referred anyone yet.</div>';
        } else {
            foreach ($rows as $row) {
                $status = in_array($row['reward_status'], ['redeemed', 'flagged', 'rejected'], true) ? $row['reward_status'] : 'pending';
                $label = $status === 'redeemed' ? 'Redeemed' : ($status === 'flagged' ? 'Under Review' : ($status === 'rejected' ? 'Rejected' : 'Pending'));
                ?>
                <div class="bt-ref-row">
                    <div class="bt-ref-date"><?php echo esc_html($row['date']); ?></div>
                    <div>
                        <div class="bt-ref-name"><?php echo esc_html($row['referred_name']); ?></div>
                        <?php if ($show_email): ?><div class="bt-ref-email"><?php echo esc_html($row['referred_email']); ?></div><?php endif; ?>
                    </div>
                    <div class="bt-ref-amount"><?php echo esc_html($currency . ' ' . number_format(floatval($row['reward_amount']), 2)); ?></div>
                    <div><span class="bt-ref-badge <?php echo esc_attr($status); ?>"><?php echo esc_html($label); ?></span></div>
                </div>
                <?php
            }
        }
        return ob_get_clean();
    }
}

register_activation_hook(__FILE__, ['BuyTap_Referral_Reward_Reports_V5', 'activate']);
new BuyTap_Referral_Reward_Reports_V5();
