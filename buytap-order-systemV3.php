<?php
/*
Plugin Name: BuyTap Order System
Description: Custom plugin to handle token purchases and order creation for BuyTap.
Version: 1.0
Author: Philip Osir
*/

// --- Simple logger (writes to wp-content/debug.log when WP_DEBUG_LOG is true)
if (!function_exists('buytap_log')) {
    function buytap_log($msg, $ctx = []) {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) return;
        $line = '[BuyTap] ' . $msg;
        if (!empty($ctx)) $line .= ' ' . wp_json_encode($ctx);
        error_log($line);
    }
}

// =============================================
// PLAN MANAGEMENT - FETCH ACTIVE PLANS
// =============================================
function buytap_get_active_plans() {
    $raw = get_option('buytap_active_plans', "4:30\n8:65\n12:95");
    $lines = array_filter(array_map('trim', explode("\n", $raw)));

    $plans = [];
    foreach ($lines as $line) {
        if (preg_match('/^(\d+)\s*:\s*(\d+)$/', $line, $m)) {
            $plans[] = [
                'days'   => intval($m[1]),
                'profit' => intval($m[2]),
            ];
        }
    }

    if (empty($plans)) {
        $plans = [
            ['days' => 4, 'profit' => 30],
            ['days' => 8, 'profit' => 65],
            ['days' => 12, 'profit' => 95],
        ];
    }

    return $plans;
}

// ===== ADMIN: Search/Filter by Order ID and add an "Order #" column =====

// 1) Add "Order #" column (sortable)
add_filter('manage_buytap_order_posts_columns', function($cols) {
    $new = [];
    $new['buytap_order_id'] = 'Order #';
    return $new + $cols; // keep existing columns
});

add_action('manage_buytap_order_posts_custom_column', function($col, $post_id) {
    if ($col !== 'buytap_order_id') return;
    echo '<strong>#' . intval($post_id) . '</strong> ';
    echo '<button class="button button-small" type="button"
            onclick="navigator.clipboard.writeText(\'' . intval($post_id) . '\')">Copy</button>';
}, 10, 2);

// Make column sortable by ID
add_filter('manage_edit-buytap_order_sortable_columns', function($cols) {
    $cols['buytap_order_id'] = 'ID';
    return $cols;
});
add_action('pre_get_posts', function($q){
    if (!is_admin() || !$q->is_main_query()) return;
    if ($q->get('post_type') !== 'buytap_order') return;
    if ($q->get('orderby') === 'ID') {
        $q->set('orderby', 'ID');
    }
});

// 2) Add an ID filter box above the list table
add_action('restrict_manage_posts', function($post_type){
    if ($post_type !== 'buytap_order') return;
    $val = isset($_GET['order_id']) ? intval($_GET['order_id']) : '';
    echo '<input type="number" name="order_id" placeholder="Filter by Order # (ID)" value="' . esc_attr($val) . '" style="width:180px;margin-left:6px;" />';
});

// 3) Apply ID filter OR treat search "123" or "#123" as an ID search
add_action('parse_query', function($q){
    if (!is_admin() || !$q->is_main_query()) return;
    if (($q->get('post_type') ?? '') !== 'buytap_order') return;

    // From the filter box
    if (!empty($_GET['order_id'])) {
        $q->set('p', intval($_GET['order_id']));
        $q->set('s', '');
        return;
    }

    // From the search box (top right): allow "#123" or "123"
    $s = trim($q->get('s', ''));
    if ($s !== '') {
        if (preg_match('/^#?(\d+)$/', $s, $m)) {
            $q->set('p', intval($m[1]));
            $q->set('s', '');
        }
    }
});



// =============================================
// CUSTOM POST TYPE SETUP - ORDER MANAGEMENT
// =============================================

/**
 * Registers the custom post type for handling orders in the backend dashboard
 * This creates the 'Orders' section in WordPress admin
 */
add_action('init', function () {
    register_post_type('buytap_order', array(
        'labels' => array(
            'name' => 'Orders',
            'singular_name' => 'Order',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New Order',
            'edit_item' => 'Edit Order',
            'new_item' => 'New Order',
            'view_item' => 'View Order',
            'search_items' => 'Search Orders',
            'not_found' => 'No orders found',
        ),
        'public' => true,
        'publicly_queryable' => false,
        'has_archive' => false,
        'show_in_menu' => true,
        'rewrite' => false,
        'menu_position' => 25,
        'menu_icon' => 'dashicons-cart',
        'supports' => array('title', 'author'),
        'capability_type' => 'post',
    ));
});

/**
 * --------------------------------------------------------------------
 * Admin: Create Order (manual admin creation)
 * Appears under: Orders (buytap_order) → Create Order
 * --------------------------------------------------------------------
 */

// Helper: Turn your buytap_get_active_plans() array into [days => percent]
function buytap_admin_map_plans() {
    $out = [];
    foreach ((array) buytap_get_active_plans() as $p) {
        if (isset($p['days'], $p['profit'])) {
            $out[(int)$p['days']] = (float)$p['profit'];
        }
    }
    return $out; // e.g. [4=>30, 8=>65, 12=>95]
}

// Submenu
add_action('admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=buytap_order',
        'Create Order',
        'Create Order',
        'manage_options',
        'buytap-create-order',
        'buytap_render_admin_create_order'
    );
});

// Page render + handler
function buytap_render_admin_create_order() {
    if (!current_user_can('manage_options')) wp_die('Not allowed');

    $plans = buytap_admin_map_plans();
    $notice = '';

    // Handle create
    if (!empty($_POST['buytap_create_order_submit']) && check_admin_referer('buytap_admin_create_order')) {
        $buyer_id       = isset($_POST['buyer_id']) ? (int) $_POST['buyer_id'] : 0;
        $amount         = isset($_POST['amount_to_send']) ? (float) $_POST['amount_to_send'] : 0;
        $plan_days      = isset($_POST['plan_days']) ? (int) $_POST['plan_days'] : 0;
        $status         = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'Pending';
        $note           = sanitize_text_field($_POST['note'] ?? '');
        $pair_now       = !empty($_POST['pair_now']);

        $errors = [];

        // Validate buyer
        if ($buyer_id <= 0 || !get_user_by('ID', $buyer_id)) {
            $errors[] = 'Please enter a valid Buyer User ID.';
        }

        // Validate amount against your limits
        $min_limit = (int) get_option('buytap_min_purchase', 500);
        $max_limit = (int) get_option('buytap_max_purchase', 5000);
        if ($amount < $min_limit) $errors[] = "Amount must be at least {$min_limit}.";
        if ($amount > $max_limit) $errors[] = "Amount must be at most {$max_limit}.";

        // Validate plan
        if (!$plan_days || !isset($plans[$plan_days])) {
            $errors[] = 'Select a valid Plan.';
        }

        // Normalize status
        $allowed_statuses = ['Pending','paired','Active','Closed','Matured','Revoked'];
        if (!in_array($status, $allowed_statuses, true)) $status = 'Pending';

        if (empty($errors)) {
            $profit_percent   = (float) $plans[$plan_days];
            $expected_amount  = round($amount * (1 + $profit_percent/100), 2);

           // Deduct from PUBLIC pool (respect floor)
			if (!buytap_deduct_public_batch((int)$amount, buytap_soldout_floor_default())) {
				$errors[] = 'Not enough shares available in the current batch.';
			} else {
                // Create post authored by the buyer (so it shows in their dashboard)
                $post_id = wp_insert_post([
                    'post_type'   => 'buytap_order',
                    'post_status' => 'publish',
                    'post_author' => $buyer_id,
                    'post_title'  => 'Order (Admin) ' . current_time('YmdHis'),
                    'post_content'=> $note,
                ], true);

                if (is_wp_error($post_id)) {
                    $errors[] = 'Failed to create order: ' . $post_id->get_error_message();
                    // give back tokens if creation failed
                    update_option('buytap_total_tokens', (int)get_option('buytap_total_tokens',0) + (int)$amount);
                } else {
                    // Rename to human-friendly
                    wp_update_post(['ID'=>$post_id,'post_title'=>'Order #'.$post_id]);

                    // Save metas your system already uses
                    $now_mysql = current_time('mysql');
                    update_post_meta($post_id, 'order_date', $now_mysql);
                    update_post_meta($post_id, 'order_details', "{$amount} shares for {$plan_days} days ({$profit_percent}% profit)");
                    update_post_meta($post_id, 'status', $status);
                    update_post_meta($post_id, 'amount_to_send', $amount);
                    update_post_meta($post_id, 'remaining_to_send', $amount);
                    update_post_meta($post_id, 'expected_amount', $expected_amount);
                    update_post_meta($post_id, 'amount_to_make', $expected_amount);
                    update_post_meta($post_id, 'sub_status', $status === 'Pending' ? 'Pending' : '');
                    update_post_meta($post_id, 'is_paired', 'no');

                    // If set to Active, start the countdown now
                    if ($status === 'Active') {
                        $maturity_ts = current_time('timestamp', true) + ($plan_days * DAY_IN_SECONDS);
                        update_post_meta($post_id, 'date_purchased', $now_mysql);
                        update_post_meta($post_id, 'running_status', 'Running');
                        update_post_meta($post_id, 'time_remaining', $maturity_ts);
                        update_post_meta($post_id, 'sub_status', 'Running');
                    }

                    // Optional: try auto-pairing now
                    if ($pair_now) {
                        buytap_pair_orders($post_id);
                    }

                    $edit_link = get_edit_post_link($post_id, '');
                    $notice = '<div class="notice notice-success is-dismissible"><p>'.
                              'Order <strong>#'.$post_id.'</strong> created for user ID <strong>'.$buyer_id.
                              '</strong>. <a href="'.esc_url($edit_link).'">Edit Order</a></p></div>';
                }
            }
        }

        if (!empty($errors)) {
            $notice = '<div class="notice notice-error"><p>'.esc_html(implode(' ', $errors)).'</p></div>';
        }
    }

    // --- UI ---
    echo '<div class="wrap"><h1>Create Order</h1>';
    echo $notice;

    // Simple helper: small search for buyer by email/username -> fills the ID box
    ?>
    <form method="post" style="max-width: 820px;">
        <?php wp_nonce_field('buytap_admin_create_order'); ?>
        <table class="form-table" role="presentation"><tbody>
            <tr>
                <th scope="row"><label for="buyer_id">Buyer User ID</label></th>
                <td>
                    <input type="number" min="1" name="buyer_id" id="buyer_id" class="regular-text" required />
                    <p class="description">Tip: start typing below to search a user, then click to auto-fill the ID.</p>
                    <input type="text" id="buyer_search" placeholder="Search by email or username..." class="regular-text" />
                    <div id="buyer_search_results" style="margin-top:6px;"></div>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="amount_to_send">Amount to Send</label></th>
                <td><input type="number" step="1" min="0" name="amount_to_send" id="amount_to_send" class="regular-text" required /></td>
            </tr>
            <tr>
                <th scope="row"><label for="plan_days">Plan</label></th>
                <td>
                    <select name="plan_days" id="plan_days" required>
                        <option value="">— Select plan —</option>
                        <?php foreach ($plans as $d=>$pct) printf('<option value="%d">%d days — %s%%</option>', $d, $d, esc_html($pct)); ?>
                    </select>
                    <p class="description">Plans come from <code>buytap_active_plans</code> (e.g. <code>4:30</code> per line).</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="status">Initial Status</label></th>
                <td>
                    <select name="status" id="status">
                        <?php foreach (['Pending','paired','Active'] as $s) echo '<option value="'.esc_attr($s).'">'.$s.'</option>'; ?>
                    </select>
                    <label style="margin-left:12px;">
                        <input type="checkbox" name="pair_now" value="1" /> Try pair now
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="note">Admin Note (optional)</label></th>
                <td><textarea name="note" id="note" class="large-text" rows="3" placeholder="Internal note..."></textarea></td>
            </tr>
        </tbody></table>
        <?php submit_button('Create Order', 'primary', 'buytap_create_order_submit'); ?>
    </form>

    <script>
    // Lightweight user search (admin-ajax via wp.users REST would need auth headers; keep it simple with AJAX endpoint)
    (function() {
        const input = document.getElementById('buyer_search');
        const results = document.getElementById('buyer_search_results');
        const idField = document.getElementById('buyer_id');
        if (!input || !results || !idField) return;

        let t = null;
        input.addEventListener('input', function() {
            const q = this.value.trim();
            results.innerHTML = '';
            if (t) clearTimeout(t);
            if (q.length < 2) return;
            t = setTimeout(() => {
                fetch(ajaxurl, {
                    method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({action:'buytap_admin_user_search', q})
                })
                .then(r=>r.json()).then(list=>{
                    results.innerHTML = '';
                    if (!Array.isArray(list) || list.length===0) {
                        results.innerHTML = '<em>No matches</em>';
                        return;
                    }
                    const ul = document.createElement('ul');
                    ul.style.margin='6px 0'; ul.style.padding='0';
                    list.forEach(u=>{
                        const li = document.createElement('li');
                        li.style.listStyle='none'; li.style.padding='6px 8px'; li.style.border='1px solid #ddd'; li.style.marginBottom='4px'; li.style.cursor='pointer';
                        li.textContent = `${u.display}  (ID:${u.id})`;
                        li.addEventListener('click', ()=>{
                            idField.value = u.id;
							input.value   = u.display; // show selected user in search box
                            results.innerHTML = '';
                        });
                        ul.appendChild(li);
                    });
                    results.appendChild(ul);
                }).catch(()=>{ results.innerHTML = '<em>Error searching</em>'; });
            }, 250);
        });
    })();
    </script>
    <?php
    echo '</div>';
}
// AJAX: smarter user search (ID, name, email, mobile, referral code)
add_action('wp_ajax_buytap_admin_user_search', function() {
    if (!current_user_can('manage_options')) wp_send_json([]);

    $q = sanitize_text_field($_POST['q'] ?? '');
    $q = trim($q);
    if ($q === '') wp_send_json([]);

    // If numeric, try exact ID
    if (ctype_digit($q)) {
        $u = get_user_by('ID', (int)$q);
        if ($u) {
            wp_send_json([[
                'id' => (int)$u->ID,
                'display' => sprintf('%s — %s (ID:%d)',
                    $u->display_name ?: $u->user_login,
                    $u->user_email,
                    $u->ID
                )
            ]]);
        }
    }

    // Broad query: username/email/display_name + meta fields
    $meta_like = ['first_name','last_name','mobile_number','my_referral_code','promo_code'];
    $meta_query = ['relation' => 'OR'];
    foreach ($meta_like as $key) {
        $meta_query[] = [
            'key'     => $key,
            'value'   => $q,
            'compare' => 'LIKE',
        ];
    }

    $users = get_users([
        'number'         => 12,
        'search'         => '*'.esc_attr($q).'*',
        'search_columns' => ['user_login','user_email','display_name'],
        'meta_query'     => $meta_query,
        'fields'         => ['ID','user_login','user_email','display_name']
    ]);

    // Fallback exact match for referral/mobile
    if (empty($users)) {
        $users = get_users([
            'number'     => 12,
            'meta_query' => [
                'relation' => 'OR',
                ['key'=>'my_referral_code', 'value'=>$q],
                ['key'=>'promo_code',       'value'=>$q],
                ['key'=>'mobile_number',    'value'=>$q],
            ],
            'fields'     => ['ID','user_login','user_email','display_name']
        ]);
    }

    $out = [];
    foreach ($users as $u) {
        $out[] = [
            'id' => (int)$u->ID,
            'display' => sprintf('%s — %s (ID:%d)',
                $u->display_name ?: $u->user_login,
                $u->user_email,
                $u->ID
            )
        ];
    }
    wp_send_json($out);
});

// =============================================
// ORDER META BOXES - ADMIN INTERFACE
// =============================================

/**
 * Adds a meta box to the order edit screen in the admin
 * This displays all order-related fields in a single box
 */
add_action('add_meta_boxes', 'buytap_add_order_meta_boxes');
function buytap_add_order_meta_boxes() {
    add_meta_box('order_details_meta', 'Order Details', 'buytap_render_order_meta_box', 'buytap_order', 'normal', 'default');
}

/**
 * Renders input fields for each order-related meta field (admin view)
 * Organized by order status for better clarity
 */
function buytap_render_order_meta_box($post) {
    $fields = [
        // Pending / Shared fields
        'order_date' => 'Order Date',
        'order_details' => 'Order Details',
        'status' => 'Status',
        'amount_to_make' => 'Amount to Make',
        'seller_name' => 'Seller Name',
        'seller_number' => 'Seller Number',
        'amount_to_send' => 'Amount to Send',
        'time_left' => 'Time Left',
        'sub_status' => 'Sub Status',

        // Active order fields
        'date_purchased' => 'Date Purchased',
        'expected_amount' => 'Expected Amount',
        'running_status' => 'Running Status',
        'time_remaining' => 'Time Remaining',

        // Paired to Sell (New Buyer Info)
        'paired_buyer_name' => 'Buyer Name',
        'paired_buyer_number' => 'Buyer MPESA Number',
        'amount_to_receive' => 'Amount to Receive',
        'payment_status' => 'Payment Status',
        'seller_action_note' => 'Action (e.g. Click to Sell)',

        // Closed order fields
        'date_completed' => 'Date Completed',
        'final_amount_paid' => 'Final Amount Paid',

        // Revoked order fields
        'date_revoked' => 'Date Revoked',
        'revoked_reason' => 'Revoked Reason',
    ];
    
    // Display each field as a text input
			foreach ($fields as $key => $label) {
				$value = esc_attr(get_post_meta($post->ID, $key, true));
				echo "<p><label for='$key'><strong>$label:</strong></label><br/>";
				echo "<input type='text' id='$key' name='$key' value='$value' style='width:100%' /></p>";
				if ($key === 'time_remaining') {
					?>
					<p>
					  <label><strong>Helper: Pick Expiry Date/Time</strong></label><br/>
					  <input type="datetime-local" id="expiry_picker" style="width:100%" />
					  <small>This will calculate the Unix timestamp for you and auto-fill it into <em>Time Remaining</em>.</small>
					</p>
					<p><strong>Generated Timestamp:</strong> <span id="expiry_timestamp"></span></p>

					<script>
					document.addEventListener("DOMContentLoaded", function() {
						const picker = document.getElementById("expiry_picker");
						const output = document.getElementById("expiry_timestamp");
						const target = document.getElementById("time_remaining"); // 👈 your input field
						if (!picker || !output || !target) return;

						picker.addEventListener("change", function() {
							if (!this.value) { 
								output.textContent = ''; 
								target.value = ''; 
								return; 
							}
							const dt = new Date(this.value);
							const ts = Math.floor(dt.getTime() / 1000); // ms → seconds
							output.textContent = ts;
							target.value = ts;  // 👈 auto-fills your Time Remaining input
						});
					});
					</script>
					<?php
				}
    }
}

/**
 * Saves all custom field values entered by the admin into post meta
 * Handles data sanitization before saving
 */
add_action('save_post', 'buytap_save_order_meta');
function buytap_save_order_meta($post_id) {
   $keys = [
        // Pending / Shared
        'order_date', 'order_details', 'status', 'amount_to_make',
        'seller_name', 'seller_number', 'amount_to_send', 'time_left',
        'sub_status',

        // Active
        'date_purchased', 'expected_amount', 'running_status', 'time_remaining',

        // Paired to Sell (New Buyer Info)
        'paired_buyer_name', 'paired_buyer_number', 'amount_to_receive',
        'payment_status', 'seller_action_note',

        // Closed
        'date_completed', 'final_amount_paid',

        // Revoked
        'date_revoked', 'revoked_reason'
    ];

    foreach ($keys as $key) {
        if (array_key_exists($key, $_POST)) {
            update_post_meta($post_id, $key, sanitize_text_field($_POST[$key]));
        }
    }
}


// =============================================
// CUSTOM SHORTCODE FORM - ORDER CREATION
// =============================================

/**
 * Outputs a BuyTap purchase form via shortcode [buytap_purchase_form]
 * Mimics Elementor "Purchase_Form" styles and settings
 * - Form CSS ID: buy-form
 * - Hidden Elementor Form Name: Purchase_Form
 * - Amount Field ID: buytap_amount
 * - Return Option Select ID: buytap_duration
 *
 * Handles submission via wp-admin/admin-post.php
 */

// ---- 1. Shortcode to output form ----
add_shortcode('buytap_purchase_form', function () {
    $is_logged_in = is_user_logged_in();
    $login_url    = 'https://bidwave.trade/login/'; // redirect back here after login

    // Show error only for logged-in users
   if ($is_logged_in) {
    $user_error = get_transient('buytap_error_' . get_current_user_id());
    if ($user_error) {
        delete_transient('buytap_error_' . get_current_user_id());
        ?>
        <div id="buytap-error-popup" class="buytap-popup">
            <div class="buytap-popup-content">
                <span class="buytap-popup-close">&times;</span>
                <p><?= esc_html($user_error) ?></p>
            </div>
        </div>
        <style>
            .buytap-popup {
                display: flex;
                align-items: center;
                justify-content: center;
                position: fixed;
                top: 0; left: 0;
                width: 100%; height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 9999;
            }
            .buytap-popup-content {
                position: relative;
                background: #fff;
                padding: 25px 35px;
                border-radius: 12px;
                text-align: center;
                font-size: 16px;
                color: #333;
                box-shadow: 0 8px 20px rgba(0,0,0,0.3);
                animation: popIn 0.3s ease;
                max-width: 400px;
            }
            .buytap-popup-icon {
                font-size: 40px;
                color: #e74c3c; /* red */
                margin-bottom: 15px;
            }
            .buytap-popup-close {
                position: absolute;
                top: 10px; right: 15px;
                font-size: 20px;
                cursor: pointer;
                color: #888;
            }
            .buytap-popup-close:hover {
                color: #000;
            }
            @keyframes popIn {
                from { transform: scale(0.8); opacity: 0; }
                to   { transform: scale(1); opacity: 1; }
            }
        </style>
        <script>
        document.addEventListener("DOMContentLoaded", function() {
            const popup = document.getElementById("buytap-error-popup");
            const closeBtn = popup.querySelector(".buytap-popup-close");
            closeBtn.addEventListener("click", () => popup.remove());
            // Auto-close after 5 seconds
            setTimeout(() => popup.remove(), 6000);
        });
        </script>
        <?php
    }
}
    // 🔄 Prefill logic
    $resume_data      = $is_logged_in ? get_user_meta(get_current_user_id(), 'buytap_resume_data', true) : [];
    $prefill_amount   = isset($resume_data['amount']) ? (int) $resume_data['amount'] : '';
    $prefill_duration = isset($resume_data['duration']) ? $resume_data['duration'] : '';

    // Clear after one use
    if (!empty($resume_data)) {
        delete_user_meta(get_current_user_id(), 'buytap_resume_data');
    }

    ob_start(); ?>
    <form id="buy-form"
          class="elementor-form <?php echo $is_logged_in ? '' : 'buytap-guest'; ?>"
          method="post"
          action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

        <input type="hidden" name="action" value="buytap_handle_purchase">
        <?php wp_nonce_field('buytap_purchase', 'buytap_nonce'); ?>

        <div class="elementor-form-fields-wrapper">

            <!-- Amount -->
            <div class="elementor-field-type-number elementor-field-group elementor-column elementor-col-100">
                <label for="buytap_amount" class="elementor-field-label">Amount</label>
                <?php 
				$min_limit = (int) get_option('buytap_min_purchase', 500);
				$max_limit = (int) get_option('buytap_max_purchase', 5000);
				?>
				<input type="number" id="buytap_amount" name="buytap_amount"
					   value="<?php echo esc_attr($prefill_amount); ?>"
					   class="elementor-field elementor-size-sm elementor-field-textual"
					   placeholder="Enter Share Amount"
					   required min="<?php echo esc_attr($min_limit); ?>" 
					   max="<?php echo esc_attr($max_limit); ?>">
				<p class="description">Allowed Amount: <?php echo esc_html($min_limit); ?> – <?php echo esc_html($max_limit); ?> Shares.</p>
            </div>

            <!-- Duration -->
            <div class="elementor-field-type-select elementor-field-group elementor-column elementor-col-100">
                <label for="buytap_duration" class="elementor-field-label">Select Return Option</label>
                <select id="buytap_duration" name="buytap_duration"
						class="elementor-field elementor-size-sm elementor-field-textual" required>
					<option value="">-- Choose --</option>
					<?php foreach (buytap_get_active_plans() as $plan): ?>
					<option value="<?php echo esc_attr($plan['days']); ?>"
							<?php selected($prefill_duration, $plan['days']); ?>>
						<?php echo esc_html($plan['profit'] . '% in ' . $plan['days'] . ' Days'); ?>
					</option>
					<?php endforeach; ?>
				</select>

            </div>

            <!-- Submit -->
            <div class="elementor-field-group elementor-column elementor-col-100">
                <button type="submit" class="elementor-button elementor-size-sm">
                    <span class="elementor-button-content-wrapper">
                        <span class="elementor-button-text">Buy Shares</span>
                    </span>
                </button>
            </div>
        </div>
    </form>
    <?php
    return ob_get_clean();
});

// Never cache the logged-in dashboard page
add_action('template_redirect', function () {
    if (is_user_logged_in() && is_page('dashboard')) {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers(); // send no-store headers
    }
});

// ---- Handle submission ----
add_action('admin_post_buytap_handle_purchase', 'buytap_handle_purchase');
add_action('admin_post_nopriv_buytap_handle_purchase', 'buytap_handle_purchase');

function buytap_handle_purchase() {
    if (!isset($_POST['buytap_nonce']) || !wp_verify_nonce($_POST['buytap_nonce'], 'buytap_purchase')) {
        wp_die('Security check failed');
    }
    // 🔑 Redirect guests to login instead of wp_die
    if (!is_user_logged_in()) {
    $amount   = isset($_POST['buytap_amount']) ? (int) $_POST['buytap_amount'] : 0;
    $duration = sanitize_text_field($_POST['buytap_duration'] ?? '');

    // Generate a random key and store values in transient for 5 minutes
    $resume_key = wp_generate_password(8, false);
    set_transient('buytap_resume_' . $resume_key, [
        'amount'   => $amount,
        'duration' => $duration,
    ], 5 * MINUTE_IN_SECONDS);

    // Redirect to login with the resume_key
    $login_url = 'https://bidwave.trade/login/';
    $redirect_back = urlencode(home_url('/dashboard/?buytap_resume=1&resume_key=' . $resume_key));
    wp_redirect($login_url . '?redirect_to=' . $redirect_back);
    exit;
}

	// 🚦 Check if sale window is open (server-side, global)
	$mode         = get_option('buytap_mode', 'test');
	$show_for     = (int) get_option('buytap_show_for_seconds', 3600);
	$open_times   = get_option('buytap_open_times', '09:00,19:00');
	$open_dates   = trim(get_option('buytap_open_dates', ''));
	$tz           = get_option('buytap_timezone', 'Africa/Nairobi');

	$now_local    = new DateTime('now', new DateTimeZone($tz));
	$window_open  = false;

	if ($mode === 'test') {
		$window_open = true; // always allow in test mode
	} else {
		// 1) Explicit open dates take priority
		if ($open_dates !== '') {
			$lines = preg_split('/\r\n|\r|\n/', $open_dates);
			foreach ($lines as $line) {
				$line = trim($line);
				if (!$line) continue;

				// Parse date or datetime
				if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $line)) {
					$candidate = DateTime::createFromFormat('Y-m-d H:i', $line, new DateTimeZone($tz));
				} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $line)) {
					// Date only → use first daily open time
					$parts = array_map('trim', explode(',', $open_times));
					$first_time = $parts[0] ?? '00:00';
					[$hh,$mm] = array_map('intval', explode(':',$first_time));
					$candidate = DateTime::createFromFormat('Y-m-d H:i', $line.' '.$hh.':'.sprintf('%02d',$mm), new DateTimeZone($tz));
				} else {
					continue;
				}

				$close_time = clone $candidate;
				$close_time->modify("+{$show_for} seconds");

				if ($now_local >= $candidate && $now_local <= $close_time) {
					$window_open = true;
					break;
				}
			}
		}

		// 2) If no open date matched, fall back to daily times
		if (!$window_open && $open_dates === '') {
			$times = array_map('trim', explode(',', $open_times));
			foreach ($times as $time) {
				if (!$time) continue;
				[$hh,$mm] = array_map('intval', explode(':',$time));
				$candidate = clone $now_local;
				$candidate->setTime($hh, $mm, 0);

				$close_time = clone $candidate;
				$close_time->modify("+{$show_for} seconds");

				if ($now_local >= $candidate && $now_local <= $close_time) {
					$window_open = true;
					break;
				}
			}
		}
	}

	if (!$window_open) {
    buytap_redirect_with_error("❌ Share sale window is currently closed. Please wait for the next opening.");
}

		$amount   = isset($_POST['buytap_amount']) ? (int) $_POST['buytap_amount'] : 0;
		$duration = sanitize_text_field($_POST['buytap_duration'] ?? '');

		$result = buytap_process_order_submission($amount, $duration);

		if (is_wp_error($result)) {
			wp_die($result->get_error_message(), "Purchase Error", ['response' => 400]);
		}

		wp_redirect(add_query_arg('buytap_success', '1', wp_get_referer()));
		exit;
	}
/**
 * After Guest Fills the  form and  Gets  Redirected to login, pass values into dashboard)
 */
add_action('template_redirect', function () {
    if (!is_user_logged_in()) return;

    if (isset($_GET['buytap_resume'], $_GET['resume_key'])) {
        $resume_key = sanitize_text_field($_GET['resume_key']);
        $data = get_transient('buytap_resume_' . $resume_key);

        if ($data) {
            // Store in user meta so dashboard shortcode can prefill
            update_user_meta(get_current_user_id(), 'buytap_resume_data', $data);
            delete_transient('buytap_resume_' . $resume_key);
        }
    }
});


/**
 * Core order creation logic (shared by Elementor + shortcode forms)
 */
function buytap_process_order_submission($amount, $duration, $buyer_id = 0) {
    if (!$buyer_id) $buyer_id = get_current_user_id();

	// 🚦 Block multiple pending/paired orders with dynamic message
	$existing = get_posts([
		'post_type'      => 'buytap_order',
		'posts_per_page' => 1,
		'author'         => $buyer_id,
		'meta_query'     => [[
			'key'     => 'status',
			'value'   => ['Pending','paired'],
			'compare' => 'IN'
		]],
		'fields'         => 'ids',
		'no_found_rows'          => true,    // ← Add these
		'cache_results'          => false,   // ← performance
		'update_post_meta_cache' => false,   // ← optimizations
		'update_post_term_cache' => false,   // ←
	]);

	if (!empty($existing)) {
		$order_id    = $existing[0];
		$order_status = get_post_meta($order_id, 'status', true);

		if ($order_status === 'Pending') {
			$msg = "⚠ You already have a pending order (#{$order_id}). Please complete it by making payment first before starting a new purchase.";
		} elseif ($order_status === 'paired') {
			$msg = "⚠ You already have an order (#{$order_id}) waiting for seller confirmation. Please wait until it goes Active before starting a new purchase.";
		} else {
			$msg = "⚠ You already have an incomplete order (#{$order_id}). Please finish it before starting a new purchase.";
		}


		buytap_redirect_with_error($msg);
	}
	
    // 🔐 Limits
    $min_limit = (int) get_option('buytap_min_purchase', 500);
    $max_limit = (int) get_option('buytap_max_purchase', 5000);
    $available = buytap_get_token_balance();
	
	// ✅ Validation
	if ($amount < $min_limit) {
		buytap_redirect_with_error("Minimum purchase is {$min_limit} shares.");
	}
	if ($amount > $max_limit) {
		buytap_redirect_with_error("Maximum purchase is {$max_limit} shares.");
	}
	// Deduct from PUBLIC batch only, keeping a 1,000 token floor
	if ( ! buytap_deduct_public_batch($amount, buytap_soldout_floor_default()) ) {
		buytap_redirect_with_error("❌ This batch is sold out. Please wait for the next drop.");
	}

	
   // 🔢 Duration logic — lookup from active plans
	$duration_days  = (int) $duration;
	$profit_percent = 0;

	foreach (buytap_get_active_plans() as $plan) {
		if ((int)$plan['days'] === $duration_days) {
			$profit_percent = (float)$plan['profit'];
			break;
		}
	}

	if ($profit_percent <= 0) {
		return new WP_Error('invalid_plan', 'Invalid plan selected. Please try again.');
	}


    // ✅ Expected return including profit
    $expected_return = $amount + ($amount * $profit_percent / 100);
	
	// 🔧 ENSURE WALLET EXISTS: Initialize wallet balance if it doesn't exist
	if (!metadata_exists('user', $buyer_id, 'buytap_wallet_balance')) {
		update_user_meta($buyer_id, 'buytap_wallet_balance', 0);
		if (function_exists('buytap_log')) {
			buytap_log('Initialized wallet balance for user', ['user_id' => $buyer_id]);
		}
	}
	
	
	
	
	// Wallet 5% reinvestment
	// 🔧 FIXED: Wallet 5% reinvestment - PROPERLY IMPLEMENTED
	$wallet_balance = (float) get_user_meta($buyer_id, 'buytap_wallet_balance', true);

	if ($wallet_balance > 0) {
		$wallet_bonus = round($wallet_balance * 0.10, 2); // 10% of wallet

		// ✅ Add to expected return
		$expected_return += $wallet_bonus;

		// ✅ Deduct from wallet (5% of current balance)
		$new_balance = max(0, $wallet_balance - $wallet_bonus);
		$update_success = update_user_meta($buyer_id, 'buytap_wallet_balance', $new_balance);

		// ✅ Log the transaction for debugging
		if (function_exists('buytap_log')) {
			buytap_log('Wallet auto-clear applied', [
				'user_id' => $buyer_id,
				'old_wallet_balance' => $wallet_balance,
				'wallet_bonus_5percent' => $wallet_bonus,
				'new_wallet_balance' => $new_balance,
				'base_expected' => $expected_return - $wallet_bonus,
				'final_expected' => $expected_return,
				'update_success' => $update_success
			]);
		}
	}
	
	
	
    // 📝 Create order
    $order_id = wp_insert_post([
        'post_type'   => 'buytap_order',
        'post_title'  => 'Order #' . $buyer_id . '-' . current_time('YmdHis'),
        'post_status' => 'publish',
        'post_author' => $buyer_id,
    ]);

	wp_update_post([
    'ID'         => $order_id,
    'post_title' => 'Order #' . $order_id
]);

    // --- Save metadata (descriptive details) ---
    update_post_meta($order_id, 'order_date', current_time('Y-m-d H:i'));
    update_post_meta($order_id, 'order_details', "{$amount} shares for {$duration_days} days ({$profit_percent}% profit)");
    update_post_meta($order_id, 'status', 'Pending');
    update_post_meta($order_id, 'amount_to_make', $expected_return);
    update_post_meta($order_id, 'expected_amount', $expected_return);
    update_post_meta($order_id, 'remaining_to_receive', $expected_return);

    // Seller info (blank until paired)
    update_post_meta($order_id, 'seller_name', '');
    update_post_meta($order_id, 'seller_number', '');

    // Buyer payment info
    update_post_meta($order_id, 'amount_to_send', $amount);
    update_post_meta($order_id, 'remaining_to_send', $amount);

    // Other flags
    update_post_meta($order_id, 'sub_status', 'Pending');
    update_post_meta($order_id, 'is_paired', 'no');

    // 🎁 Apply bonuses + referrals (unchanged)
    buytap_apply_pending_bonuses_to_new_order($order_id, $buyer_id, (float)$amount, (int)$profit_percent);

    $promo_code = get_user_meta($buyer_id, 'promo_code', true);
    if ($promo_code) {
        $upline_user = get_users([
            'meta_key'   => 'my_referral_code',
            'meta_value' => sanitize_text_field($promo_code),
            'number'     => 1,
            'fields'     => 'ID'
        ]);
        if (!empty($upline_user)) {
            $upline_id = $upline_user[0];
			// referral bonus part
            $referral_percent = (float) get_option('buytap_referral_bonus_percent', 3);
	$bonus = round($amount * ($referral_percent / 100), 2);

            $bonus_history = get_user_meta($upline_id, 'referral_bonus_history', true) ?: [];
            $bonus_history[] = [
                'date'             => current_time('mysql'),
                'buyer_id'         => $buyer_id,
                'buyer_name'       => get_userdata($buyer_id)->display_name,
                'order_id'         => $order_id,
                'purchase_amt'     => $amount,
                'bonus_amt'        => $bonus,
                'status'           => 'pending',
                'applied_to_order' => 0,
            ];
            update_user_meta($upline_id, 'referral_bonus_history', $bonus_history);
        }
    }

    // 🔻 Deduct tokens + attempt pairing
//     buytap_reduce_tokens_on_purchase((int)$amount);
    buytap_pair_orders($order_id);

    return $order_id;
}

function buytap_redirect_with_error($message) {
    // Store error in a transient (short-lived)
    set_transient('buytap_error_' . get_current_user_id(), $message, 30);

    // Redirect back to the dashboard (adjust slug if needed)
    wp_safe_redirect(home_url('/dashboard/'));
    exit;
}

// Show popup once on dashboard
add_action('wp_footer', function () {
    if (!is_user_logged_in()) return;

    $user_error = get_transient('buytap_error_' . get_current_user_id());
    if ($user_error) {
        delete_transient('buytap_error_' . get_current_user_id());
        ?>
        <script>
        document.addEventListener("DOMContentLoaded", function() {
            alert("<?= esc_js($user_error); ?>");
        });
        </script>
        <?php
    }
});

//Shortcode For  My  Referal Bonus History
add_shortcode('my_referral_bonus_history', function() {
    if (!is_user_logged_in()) {
        return "<p>Please log in to view your referral bonus history.</p>";
    }

    $user_id = get_current_user_id();
    $bonus_history = get_user_meta($user_id, 'referral_bonus_history', true);

    if (empty($bonus_history) || !is_array($bonus_history)) {
        return "<p>No referral bonuses earned yet.</p>";
    }

    // Sort by date (newest first)
    usort($bonus_history, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    ob_start();
    ?>
    <div class="buytap-orders-table buytap-bonus-table">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Buyer</th>
                    <th>Purchase Amount</th>
                    <th>Bonus Earned</th>
                    <th>Order ID</th>
                    <th>Status</th>
                    <th>Applied To</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bonus_history as $bonus): ?>
                    <tr>
                        <td><?= esc_html(date('Y-m-d H:i', strtotime($bonus['date']))) ?></td>
                        <td><?= esc_html($bonus['buyer_name']) ?></td>
                        <td>Ksh <?= number_format($bonus['purchase_amt'], 2) ?></td>
                        <td style="color:green;">+Ksh <?= number_format($bonus['bonus_amt'], 2) ?></td>
                        <td>#<?= intval($bonus['order_id']) ?></td>
                        <td>
                            <?php if ($bonus['status'] === 'applied'): ?>
                                <span style="color:green;">✔ Applied</span>
                            <?php else: ?>
                                <span style="color:orange;">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($bonus['applied_to_order'])): ?>
                                Order #<?= $bonus['applied_to_order'] ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
});
		
// Apply all pending referral bonuses to a just-created order.
// Must be called AFTER base metas (amount_to_send, expected_amount, etc.) are saved.
function buytap_apply_pending_bonuses_to_new_order($order_id, $user_id, $amount, $profit_percent) {
    $bonus_history = get_user_meta($user_id, 'referral_bonus_history', true);
    if (empty($bonus_history) || !is_array($bonus_history)) return;

    $total_bonus = 0.0;
    $applied_bonuses = [];

    foreach ($bonus_history as &$bonus) {
        if (!isset($bonus['status']) || $bonus['status'] !== 'pending') continue;
        $total_bonus += (float)$bonus['bonus_amt'];
        $bonus['status'] = 'applied';
        $bonus['applied_to_order'] = $order_id;
        $applied_bonuses[] = $bonus;
    }
    unset($bonus); // break reference

    if ($total_bonus <= 0) return;

    // Save updated history
    update_user_meta($user_id, 'referral_bonus_history', $bonus_history);

    // Base expected = tokens + profit
    $expected_without_bonus = (float)$amount * (1 + ((float)$profit_percent / 100.0));
    $new_expected = $expected_without_bonus + (float)$total_bonus;

    // Update order metas to reflect bonus
    update_post_meta($order_id, 'expected_amount', $new_expected);
    update_post_meta($order_id, 'remaining_to_receive', $new_expected);
    update_post_meta($order_id, 'amount_to_make', $new_expected);
    update_post_meta($order_id, 'applied_referral_bonus', (float)$total_bonus);
    update_post_meta($order_id, '_applied_bonuses', $applied_bonuses);

    buytap_log('Applied referral bonuses to new order', [
        'order_id' => $order_id,
        'user_id'  => $user_id,
        'base_amt' => (float)$amount,
        'profit_percent' => (float)$profit_percent,
        'bonus_total' => (float)$total_bonus,
        'expected_with_bonus' => $new_expected
    ]);
}


//verification function (to check for and fix any discrepancies):
function buytap_verify_bonus_application($user_id) {
    $bonus_history = get_user_meta($user_id, 'referral_bonus_history', true) ?: [];
    $needs_update = false;
    
    foreach ($bonus_history as &$bonus) {
        if ($bonus['status'] === 'applied') {
            // Verify the bonus was actually added to an order
            $order = get_post($bonus['applied_to_order']);
            if (!$order || $order->post_type !== 'buytap_order') {
                $bonus['status'] = 'pending';
                unset($bonus['applied_to_order']);
                $needs_update = true;
                continue;
            }
            
            // Get the recorded bonus amount from the order
            $recorded_bonus = (float) get_post_meta($order->ID, 'applied_referral_bonus', true);
            $expected_amount = (float) get_post_meta($order->ID, 'expected_amount', true);
            $base_amount = (float) get_post_meta($order->ID, 'amount_to_send', true);
            
            // Calculate what the expected amount should be based on order details
            $order_details = get_post_meta($order->ID, 'order_details', true);
            $profit_percent = 0;
            
            if (strpos($order_details, '30% in 4 Days') !== false) {
                $profit_percent = 30;
            } elseif (strpos($order_details, '65% in 8 Days') !== false) {
                $profit_percent = 65;
            } elseif (strpos($order_details, '95% in 12 Days') !== false) {
                $profit_percent = 95;
            } else {
                $profit_percent = 30; // Default fallback
            }
            
            $expected_without_bonus = $base_amount * (1 + ($profit_percent / 100));
            $expected_with_bonus = $expected_without_bonus + $bonus['bonus_amt'];
            
            // Check if the bonus was properly applied
            $bonus_correct = (
                abs($recorded_bonus - $bonus['bonus_amt']) < 0.01 && 
                abs($expected_amount - $expected_with_bonus) < 0.01
            );
            
            if (!$bonus_correct) {
                // Bonus wasn't properly applied
                $bonus['status'] = 'pending';
                unset($bonus['applied_to_order']);
                $needs_update = true;
                
                // Reset the order's expected amount if it was incorrect
                update_post_meta($order->ID, 'expected_amount', $expected_without_bonus);
                update_post_meta($order->ID, 'remaining_to_receive', $expected_without_bonus);
                update_post_meta($order->ID, 'applied_referral_bonus', 0);
            }
        }
    }
    
    if ($needs_update) {
        update_user_meta($user_id, 'referral_bonus_history', $bonus_history);
    }
    
    return $needs_update;
}

// Add this to your admin tools or run periodically

// =============================================
// TOKEN POOL MANAGEMENT SYSTEM
// =============================================

/**
 * Set initial token balance (run once or manually via admin)
 * @param int $amount Initial token amount (default: 200,000)
 */
function buytap_set_initial_token_pool($amount = 200000) {
    update_option('buytap_total_tokens', $amount);
}

/**
 * Reduce tokens when buyer makes a purchase
 * @param int $amount Amount to deduct from token pool
 */
function buytap_reduce_tokens_on_purchase($amount) {
    $current = (int) get_option('buytap_total_tokens', 0);
    $new_total = max($current - $amount, 0);
    update_option('buytap_total_tokens', $new_total);
}

/**
 * Add tokens + profit when order matures
 * @param int $amount Base amount
 * @param int $profit_percent Profit percentage to add
 */
function buytap_add_tokens_on_maturity($amount, $profit_percent) {
    $profit = ($profit_percent / 100) * $amount;
    $add_back = $amount + $profit;
    $current = (int) get_option('buytap_total_tokens', 0);
    update_option('buytap_total_tokens', $current + $add_back);
}

/**
 * Returns the current available tokens
 * @return int Current token balance
 */
function buytap_get_token_balance() {
    return (int) get_option('buytap_total_tokens', 0);
}

// ================================
// PUBLIC BATCH (Visible Scarcity)
// ================================

// Settings
function buytap_batch_size_default() { 
    // Default 5000, unless admin sets different value
    return (int) get_option('buytap_base_batch_size', 5000); 
}      

function buytap_drop_interval_default() { 
    // Default 6 hours (21600 seconds)
    return (int) get_option('buytap_drop_interval_sec', 6*3600); 
}  

function buytap_soldout_floor_default() { 
    // Default 1000, unless overridden
    return (int) get_option('buytap_soldout_floor', 1000); 
}

// Canonical pools
function buytap_public_balance() { return (int) get_option('buytap_total_tokens', 0); }              // PUBLIC
function buytap_set_public_balance($n) { update_option('buytap_total_tokens', max(0, (int)$n)); }

function buytap_reserve_balance() { return (int) get_option('buytap_reserve_tokens', 0); }           // RESERVE
function buytap_set_reserve_balance($n){ update_option('buytap_reserve_tokens', max(0, (int)$n)); }

// Release timing
function buytap_last_release()      { return (int) get_option('buytap_last_release', 0); }
function buytap_set_last_release($ts)    { update_option('buytap_last_release', max(0,(int)$ts)); }
function buytap_release_interval()  { 
    $v = get_option('buytap_release_interval', null);
    if ($v === null || $v === '' ) return buytap_drop_interval_default();
    return (int)$v;
}
function buytap_set_release_interval($s) { update_option('buytap_release_interval', max(60,(int)$s)); }

// Deduct from PUBLIC only, while keeping a floor
function buytap_deduct_public_batch($amount, $floor = null) {
    global $wpdb;
    $amount = (int) $amount;
    if ($amount <= 0) return false;
    $floor = is_null($floor) ? buytap_soldout_floor_default() : (int)$floor;

    // Only deduct if public >= amount + floor
    $rows = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = option_value - %d
             WHERE option_name = %s
               AND option_value >= %d",
            $amount,
            'buytap_total_tokens',
            $amount + $floor
        )
    );
    if ($rows) {
        wp_cache_delete('buytap_total_tokens', 'options');
        return true;
    }
    return false;
}

// Move from RESERVE -> PUBLIC
function buytap_release_new_batch($size = null, $interval = null) {
    if ($size === null)     $size = buytap_batch_size_default();
    if ($interval === null) $interval = buytap_drop_interval_default();

    $reserve = buytap_reserve_balance();
    if ($reserve <= 0) return;

    $batch = min($reserve, (int)$size);
    if ($batch <= 0) return;

    buytap_set_reserve_balance($reserve - $batch);
    buytap_set_public_balance(buytap_public_balance() + $batch);

    buytap_set_last_release(time());
    buytap_set_release_interval($interval);
}

// Called by cron: release when sold-out or interval elapsed
function buytap_maybe_release_batch() {
    $now      = time();
    $last     = buytap_last_release();
    $interval = buytap_release_interval();
    $public   = buytap_public_balance();
    $floor    = buytap_soldout_floor_default();

    $time_ok  = ($last === 0) || (($now - $last) >= $interval);
    $soldout  = ($public <= $floor);

    if ($time_ok) {
    buytap_release_new_batch();
	}
}

// function buytap_maybe_release_batch() {
//     $now      = time();
//     $last     = buytap_last_release();
//     $interval = buytap_release_interval();
//     $public   = buytap_public_balance();
//     $floor    = buytap_soldout_floor_default();

//     $time_ok  = ($last === 0) || (($now - $last) >= $interval);
//     $soldout  = ($public <= $floor);

//     if ($time_ok && $soldout) {
//         buytap_release_new_batch();
//         buytap_log('Released batch', [
//             'public_before' => $public,
//             'floor' => $floor,
//             'interval' => $interval
//         ]);
//     } else {
//         buytap_log('No release', [
//             'time_ok' => $time_ok,
//             'soldout' => $soldout,
//             'public'  => $public,
//             'floor'   => $floor
//         ]);
//     }
// }

// =============================================
// FRONT-END DISPLAY SHORTCODES
// =============================================

/**
 * Shortcode to display available tokens
 * Usage: [buytap_tokens]
 */
function buytap_display_available_tokens_shortcode() {
    $available = buytap_get_token_balance();
    $ajax_url = admin_url('admin-ajax.php');

    return '<div id="available-tokens" 
                 class="buytap-token-box" 
                 data-ajax-url="' . esc_url($ajax_url) . '">
                <strong>Available Shares: </strong> ' . number_format($available) . '
            </div>';
}
add_shortcode('buytap_tokens', 'buytap_display_available_tokens_shortcode');

// =============================================
// FRONTEND: Dynamic Token Pool + Countdown
// =============================================
/**
 * Shortcode: [buytap_public_tokens]
 * Shows animated token count + SOLD OUT (countdown hidden)
 */
add_shortcode('buytap_public_tokens', function () {
    $public_tokens = (int) get_option('buytap_total_tokens', 0);

    // Ensure last_release is always set
    $last_release  = (int) get_option('buytap_last_release', 0);
    $interval      = (int) get_option('buytap_release_interval', 6 * HOUR_IN_SECONDS);
    if ($last_release === 0) {
        $last_release = time();
        update_option('buytap_last_release', $last_release);
    }
    $next_release  = $last_release + $interval;

    ob_start(); ?>
    <div id="buytap-public-tokens" style="font-size:20px; font-weight:bold;">
        <?php if ($public_tokens <= 1000): ?>
            <span style="color:#e54f59;">🚫 SOLD OUT</span>
        <?php else: ?>
            <span id="buytap-token-counter"><?= number_format($public_tokens) ?></span> Available Shares
        <?php endif; ?>
        <!-- Hidden timestamp for JS logic -->
        <span id="buytap-next-release" data-release="<?= esc_attr($next_release) ?>" style="display:none;"></span>
    </div>
    <?php
    return ob_get_clean();
});


// ==========================
// FRONTEND JS (safe enqueue)
// ==========================
add_action('wp_footer', function () {
    ?>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // prevent duplicate runs on AJAX-loaded Elementor pages
        if (window.buytapTickerStarted) return;
        window.buytapTickerStarted = true;

        const tokenCounter = document.getElementById("buytap-token-counter");
        const releaseEl    = document.getElementById("buytap-next-release");
        const nextRelease  = releaseEl ? parseInt(releaseEl.dataset.release, 10) * 1000 : 0;

        // Optional: silent countdown placeholder (does nothing visual)
        if (nextRelease) {
            function updateCountdown() {
                const now  = Date.now();
                const diff = nextRelease - now;
                if (diff <= 0) return;
                requestAnimationFrame(updateCountdown);
            }
            updateCountdown();
        }

        // ❌ Removed fake slideDown ticker
        // ✅ Keep static display only (accurate number)
        if (tokenCounter) {
            tokenCounter.textContent = parseInt(tokenCounter.textContent.replace(/,/g,"")).toLocaleString();
        }
    });
    </script>
    <?php
});


// =============================================
// ADMIN TOKEN POOL MANAGEMENT
// =============================================

/**
 * Adds token pool management page to admin menu
 */
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=buytap_order',
        'Token Pool Manager',
        'Token Pool',
        'manage_options',
        'buytap-token-pool',
        'buytap_render_token_pool_page'
    );
});

/**
 * Renders the token pool management page in admin
 * Allows admins to manually adjust token pool amount
 */
function buytap_render_token_pool_page() {
    // ===== Handle Reserve Pool update =====
    if (isset($_POST['buytap_reserve_pool_submit']) && current_user_can('manage_options')) {
        $new_reserve = (int) sanitize_text_field($_POST['new_reserve_value']);
        update_option('buytap_reserve_tokens', $new_reserve);
        echo '<div class="updated"><p><strong>Reserve Pool Updated to: ' . number_format($new_reserve) . '</strong></p></div>';
    }

    // ===== Handle Public Pool update =====
    if (isset($_POST['buytap_public_pool_submit']) && current_user_can('manage_options')) {
        $new_public = (int) sanitize_text_field($_POST['new_public_value']);
        update_option('buytap_total_tokens', $new_public);
        echo '<div class="updated"><p><strong>Public Pool Updated to: ' . number_format($new_public) . '</strong></p></div>';
    }

    // ===== Handle Batch & Scarcity Settings update =====
    if (isset($_POST['buytap_settings_submit']) && current_user_can('manage_options')) {
        update_option('buytap_base_batch_size', (int) $_POST['batch_size']);
        update_option('buytap_drop_interval_sec', (int) $_POST['drop_interval']);
        update_option('buytap_soldout_floor', (int) $_POST['soldout_floor']);
        echo '<div class="updated"><p><strong>Batch & Scarcity Settings updated.</strong></p></div>';
    }

    $reserve       = (int) get_option('buytap_reserve_tokens', 0);
    $public        = (int) get_option('buytap_total_tokens', 0);
    $batch_size    = (int) get_option('buytap_base_batch_size', 5000);
    $drop_interval = (int) get_option('buytap_drop_interval_sec', 6*3600);
    $soldout_floor = (int) get_option('buytap_soldout_floor', 1000);

    echo '<div class="wrap">';
    echo '<h1>BuyTap Token Pool Manager</h1>';

    // ===== Reserve Pool Form =====
    echo '<h2>Reserve Pool (long-term storage)</h2>';
    echo '<p><strong>Current Reserve:</strong> ' . number_format($reserve) . '</p>';
    echo '<form method="post">';
    echo '<label for="new_reserve_value"><strong>Set Reserve Pool:</strong></label><br>';
    echo '<input type="number" name="new_reserve_value" id="new_reserve_value" value="' . esc_attr($reserve) . '" required>';
    echo '<br><br>';
    echo '<input type="submit" name="buytap_reserve_pool_submit" class="button button-primary" value="Update Reserve Pool">';
    echo '</form>';

    // ===== Public Pool Form =====
    echo '<h2 style="margin-top:30px;">Public Pool (available to buyers)</h2>';
    echo '<p><strong>Current Public Tokens:</strong> ' . number_format($public) . '</p>';
    echo '<form method="post">';
    echo '<label for="new_public_value"><strong>Set Public Pool:</strong></label><br>';
    echo '<input type="number" name="new_public_value" id="new_public_value" value="' . esc_attr($public) . '" required>';
    echo '<br><br>';
    echo '<input type="submit" name="buytap_public_pool_submit" class="button button-primary" value="Update Public Pool">';
    echo '</form>';

    // ===== Batch & Scarcity Settings =====
	echo '<h2 style="margin-top:30px;">Batch & Scarcity Settings</h2>';

	// Handle settings save
	if (isset($_POST['buytap_settings_submit']) && current_user_can('manage_options')) {
		$batch_size   = (int) $_POST['batch_size'];
		$soldout_floor = (int) $_POST['soldout_floor'];

		// Convert interval to seconds
		$interval_value = (int) $_POST['drop_interval_value'];
		$interval_unit  = sanitize_text_field($_POST['drop_interval_unit']);
		if ($interval_unit === 'minutes') {
			$interval_seconds = $interval_value * 60;
		} else { // hours default
			$interval_seconds = $interval_value * 3600;
		}

		update_option('buytap_base_batch_size', $batch_size);
		update_option('buytap_soldout_floor', $soldout_floor);
		update_option('buytap_drop_interval_sec', $interval_seconds);

		echo '<div class="updated"><p><strong>Batch & Scarcity Settings updated.</strong></p></div>';
	}

	// Display friendly values
	$interval_sec  = (int) get_option('buytap_drop_interval_sec', 6*3600);
	if ($interval_sec % 3600 === 0) {
		$interval_value = $interval_sec / 3600;
		$interval_unit  = 'hours';
	} else {
		$interval_value = $interval_sec / 60;
		$interval_unit  = 'minutes';
	}

	echo '<form method="post">';
	echo '<label><strong>Batch Size (tokens released per drop):</strong></label><br>';
	echo '<input type="number" name="batch_size" value="' . esc_attr($batch_size) . '" required><br><br>';

	echo '<label><strong>Drop Interval:</strong></label><br>';
	echo '<input type="number" name="drop_interval_value" value="' . esc_attr($interval_value) . '" required>';
	echo ' <select name="drop_interval_unit">';
	echo '<option value="hours" '   . selected($interval_unit, 'hours', false)   . '>Hours</option>';
	echo '<option value="minutes" ' . selected($interval_unit, 'minutes', false) . '>Minutes</option>';
	echo '</select><br><br>';

	echo '<label><strong>Sold Out Floor (tokens left before next release):</strong></label><br>';
	echo '<input type="number" name="soldout_floor" value="' . esc_attr($soldout_floor) . '" required><br><br>';

	echo '<input type="submit" name="buytap_settings_submit" class="button button-primary" value="Update Settings">';
	echo '</form>';


    // ===== Referral Bonus Verification =====
    echo '<h2 style="margin-top:30px;">Referral Bonus Verification</h2>';
    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('buytap_verify_bonuses', 'buytap_bonus_nonce');
    echo '<input type="hidden" name="verify_bonuses" value="1">';
    echo '<input type="submit" class="button button-secondary" value="Verify All Bonus Applications">';
    echo '</form>';

    if (isset($_POST['verify_bonuses']) && current_user_can('manage_options')) {
        check_admin_referer('buytap_verify_bonuses', 'buytap_bonus_nonce');
        
        $users = get_users([
            'meta_key' => 'referral_bonus_history',
            'fields' => 'ID'
        ]);
        
        $fixed = 0;
        foreach ($users as $user_id) {
            if (buytap_verify_bonus_application($user_id)) {
                $fixed++;
            }
        }
        
        echo '<div class="notice notice-success"><p>Verified bonuses for '.$fixed.' users. ';
        echo '<a href="#" onclick="jQuery(this).parent().remove();">Dismiss</a></p></div>';
    }
	// ===== Referral Bonus Settings =====
if (isset($_POST['buytap_referral_settings_submit']) && current_user_can('manage_options')) {

    $percent = floatval($_POST['buytap_referral_bonus_percent']);

    update_option('buytap_referral_bonus_percent', $percent);

    echo '<div class="updated"><p><strong>Referral Bonus Updated to: '
        . esc_html($percent) .
        '%</strong></p></div>';
}

$current_percent = get_option('buytap_referral_bonus_percent', 3);

echo '<h2 style="margin-top:30px;">Referral Bonus Settings</h2>';

echo '<form method="post">';
echo '<label><strong>Referral Bonus Percentage:</strong></label><br>';
echo '<input type="number" step="0.1" min="0" max="100"
      name="buytap_referral_bonus_percent"
      value="' . esc_attr($current_percent) . '" required> %';

echo '<br><br>';

echo '<input type="submit"
      name="buytap_referral_settings_submit"
      class="button button-primary"
      value="Update Referral Bonus">';
echo '</form>';

    echo '</div>'; // Close wrap
}


// =============================================
// ORDER STATUS SHORTCODES - FRONTEND VIEWS
// =============================================

/**
 * Pending Orders Tab - Displays orders waiting to be paired
 */
add_shortcode('buytap_pending_orders', function () {
    if (!is_user_logged_in()) return '<p>Please log in to view your orders.</p>';

    $user_id = get_current_user_id();
    $args = [
        'post_type' => 'buytap_order',
        'posts_per_page' => -1,
        'author' => $user_id,
        'meta_query' => [
            [
                'key' => 'status',
                'value' => ['Pending', 'paired'],
                'compare' => 'IN'
            ]
        ],
        'orderby' => 'date',
        'order' => 'DESC',
		'no_found_rows'          => true,
		'cache_results'          => false,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
    ];
    $orders = get_posts($args);

    ob_start();
    ?>
    <div class="buytap-orders-table">
        <?php if ($orders): ?>
            <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                <thead>
					  <tr>
						<th>Order #</th>
						<th>Order Date</th>
						<th>Order Details</th>
						<th>Status</th>
						<th>Amount to Make</th>
					  </tr>
					</thead>
                <tbody>
                <?php foreach ($orders as $order):
                    $id = $order->ID;
                    $order_date = esc_html(get_post_meta($id, 'order_date', true));
                    $details = esc_html(get_post_meta($id, 'order_details', true));
                    $status = esc_html(get_post_meta($id, 'status', true));
                    $expected_return = number_format(get_post_meta($id, 'amount_to_make', true));
                    $sub_status = get_post_meta($id, 'sub_status', true);
                    ?>
                    <tr style='border-bottom:1px solid #ddd;'>
						<td>
						  <strong>#<?= intval($id) ?></strong>
						  <button type="button" class="copy-id" data-id="<?= intval($id) ?>">Copy</button>
						</td>
						<td><?= $order_date ?></td>
						<td><?= $details ?></td>
						<td><?= $status ?></td>
						<td><strong>Ksh</strong> <?= $expected_return ?></td>
					</tr>
                    <?php if (in_array($status, ['Pending', 'paired'])):
								global $wpdb; 
								$chunks = buytap_buyer_chunks($id);
                    ?>
                    <tr class='paired-seller'>
                      <td colspan='4'>
                        <?php if ($chunks): ?>
                          <table class='paired-seller-table' style="width:100%;">
                            <tr>
                              <th>Seller's Name</th>
                              <th>Seller's Number</th>
                              <th>Amount to Send</th>
                              <th>Time Left</th>
                              <th>Status</th>
                            </tr>
                            <?php foreach ($chunks as $chunk):
                                $seller_id = (int) $chunk->seller_order_id;
                                $seller_user_id = (int) get_post_field('post_author', $seller_id);
                                $seller_name = get_the_author_meta('display_name', $seller_user_id);
                                $seller_number = get_user_meta($seller_user_id, 'mobile_number', true);
                                $pair_time = (int) $chunk->pair_time;
                                $chunk_status = (string) $chunk->status;
                            ?>
                            <tr>
                              <td><?= esc_html($seller_name) ?></td>
                              <td><?= esc_html($seller_number) ?></td>
                              <td>Ksh. <?= number_format((float)$chunk->amount) ?></td>
                              <?php
								if ($pair_time <= 0) {
									$pair_time = time();
									global $wpdb;
									$wpdb->update(
										buytap_chunks_table(),
										['pair_time' => $pair_time],
										['id' => (int) $chunk->id]
									);
								}
								?>
								<td data-countdown="<?= esc_attr($pair_time + 3600) ?>">Loading...</td>
                              <td>
                                <?php if ($chunk_status === 'Received'): ?>
                                    <span class="badge-paid">✔ Received</span>
                                <?php elseif ($chunk_status === 'Payment Made'): ?>
                                    <span class="badge-paid">Payment Sent</span>
                                <?php else: ?>
                                    <button class="made-payment-btn" 
                                            data-chunk-id="<?= (int)$chunk->id ?>">Made Payment</button>
                                <?php endif; ?>
                              </td>
                            </tr>
                            <?php endforeach; ?>
                          </table>
                        <?php else: ?>
                          <em>Waiting to be paired...</em>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No pending orders found.</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

// =============================================
// PAYMENT CONFIRMATION SYSTEM
// =============================================

/**
 * REST API Endpoints for payment confirmation
 * Allows frontend to mark payments as made/received
 */
add_action('rest_api_init', function () {
    // Endpoint for marking entire order as paid
    register_rest_route('buytap/v1', '/mark-payment/', [
        'methods' => 'POST',
        'callback' => 'buytap_rest_mark_payment',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ]);
    
    // Endpoint for marking individual chunks as paid
    register_rest_route('buytap/v1', '/chunk-made-payment/', [
        'methods'  => 'POST',
        'callback' => function(WP_REST_Request $request) {
            global $wpdb; 
            $t = buytap_chunks_table();
            $chunk_id = (int) ($request['chunk_id'] ?? 0);
			
            if ($chunk_id <= 0) return new WP_REST_Response(['success'=>false,'message'=>'Invalid chunk'], 400);

            $chunk = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $chunk_id));
            if (!$chunk) return new WP_REST_Response(['success'=>false,'message'=>'Not found'], 404);

            $buyer_order_id = (int) $chunk->buyer_order_id;
            

            $author_id = (int) get_post_field('post_author', $buyer_order_id);
            if ($author_id !== get_current_user_id()) {
                return new WP_REST_Response(['success'=>false,'message'=>'Unauthorized'], 403);
            }
			// Update chunk status
            $wpdb->update($t, ['status' => 'Payment Made'], ['id' => $chunk_id]);

            return new WP_REST_Response(['success'=>true], 200);
        },
        'permission_callback' => function () { return is_user_logged_in(); }
    ]);
});

/**
 * Handle Buyer Payment Confirmation and Activate Order with Maturity Countdown
 * @param WP_REST_Request $request REST API request object
 */
function buytap_rest_mark_payment(WP_REST_Request $request) {
    $order_id = intval($request['order_id']);
    $user_id = get_current_user_id();

    if (!$order_id || get_post_type($order_id) !== 'buytap_order') {
		// Silently return success to avoid front-end popup
		return new WP_REST_Response(['success' => true, 'message' => 'Order already handled'], 200);
	}

    $author_id = (int)get_post_field('post_author', $order_id);
    if ($author_id !== $user_id) {
        return new WP_REST_Response(['success' => false, 'message' => 'Unauthorized'], 403);
    }

    update_post_meta($order_id, 'sub_status', 'Payment Made');
    update_post_meta($order_id, 'buyer_confirmed', 'yes');

    return new WP_REST_Response(['success' => true, 'message' => 'Payment marked and order activated'], 200);
}

/**
 * Enqueue frontend scripts and localize data for AJAX calls
 */
function buytap_enqueue_script_and_nonce() {
    // Use a real handle that prints in footer
    wp_register_script('buytap-front-js', includes_url('js/wp-util.min.js'), array('jquery'), null, true);
    wp_enqueue_script('buytap-front-js');

    wp_localize_script('buytap-front-js', 'buytapData', array(
        'nonce'                   => wp_create_nonce('wp_rest'),
        'rest_url'                => esc_url_raw(rest_url('buytap/v1/mark-payment/')),
        'rest_chunk_made_payment' => esc_url_raw(rest_url('buytap/v1/chunk-made-payment/')),
    ));
}
add_action('wp_enqueue_scripts', 'buytap_enqueue_script_and_nonce');


// =============================================
// COUNTDOWN TIMERS - FRONTEND JAVASCRIPT
// =============================================

/**
 * Create the Countdown Script (MM:SS Format) for the buyer to make payment 
 * Shows time remaining to complete payment after pairing
 */
add_action('wp_footer', function () {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const countdownCells = document.querySelectorAll('[data-countdown]');
        countdownCells.forEach(cell => {
            const orderRow = cell.closest('tr');
            const statusElement = orderRow?.querySelector('.badge-paid, .made-payment-btn');

            // If payment already marked, skip countdown
            if (statusElement && statusElement.classList.contains('badge-paid')) {
                cell.textContent = '--';
                return;
            }

            const raw = parseInt(cell.dataset.countdown, 10); // expiry in UTC seconds
            if (!raw) { 
                cell.textContent = '--'; 
                return; 
            }

            const endTime = raw * 1000; // convert to ms

            function updateCountdown() {
                const now = Date.now();      // UTC ms
                const diff = endTime - now;  // ms

                if (diff <= 0) {
                    cell.textContent = '00:00';
                    return;
                }

                const minutes = Math.floor(diff / 60000);
                const seconds = Math.floor((diff % 60000) / 1000);
                cell.textContent = 
                    `${String(minutes).padStart(2,'0')}:${String(seconds).padStart(2,'0')}`;

                requestAnimationFrame(updateCountdown);
            }

            updateCountdown();
        });
    });
    </script>
    <?php
});

// =============================================
// ACTIVE ORDERS MANAGEMENT
// =============================================

/**
 * Active Orders Tab - Displays currently running investments
 */
add_shortcode('buytap_active_orders', function () {
    if (!is_user_logged_in()) return '<p>Please log in to view your orders.</p>';

    $user_id = get_current_user_id();
    $args = [
        'post_type' => 'buytap_order',
        'posts_per_page' => -1,
        'author' => $user_id,
        'meta_query' => [
            [
                'key' => 'status',
                'value' => ['Active', 'Matured'],
                'compare' => 'IN'
            ]
        ],
        'orderby' => 'date',
        'order' => 'DESC',
		'no_found_rows'          => true,
		'cache_results'          => false,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
    ];

    $orders = get_posts($args);

    ob_start();
    ?>
    <div class="buytap-orders-table">
        <?php if ($orders): ?>
            <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                <thead>
				  <tr>
					<th>Order #</th>
					<th>Date Purchased</th>
					<th>Shares Bought</th>
					<th>Expected Return</th>
					<th>Time Remaining</th>
					<th>Status</th>
				  </tr>
				</thead>
                <tbody>
                    <?php foreach ($orders as $order):
                        $id = $order->ID;
                        $date_purchased = get_post_meta($id, 'date_purchased', true);
						if (empty($date_purchased)) {
							$date_purchased = get_post_meta($id, 'order_date', true);
						}
						$date_purchased = esc_html($date_purchased);
                        $amount_bought = (float) get_post_meta($id, 'amount_to_send', true);
						if ($amount_bought <= 0) {
							$amount_bought = (float) get_post_meta($id, 'amount_bought', true); // fallback for Matured seller orders
						}
                        $expected_return = (float) get_post_meta($id, 'expected_amount', true);
                        $time_remaining = (int)get_post_meta($id, 'time_remaining', true);

                        $status = get_post_meta($id, 'status', true);
                        $current_time = current_time('timestamp', true);

                        // Check if order has matured
                        if ($status === 'Active' && $time_remaining <= $current_time) {
                            update_post_meta($id, 'status', 'Matured');
                            $status = 'Matured';

                            // Add matured tokens back to pool if not already done
                            $already_returned = get_post_meta($id, 'returned_to_pool', true);
                            if ($already_returned !== 'yes') {
                                $expected = get_post_meta($id, 'expected_amount', true);
                                $expected = floatval($expected);
                                $current_pool = get_option('buytap_total_tokens', 0);
                                $new_pool = $current_pool + $expected;
                                update_option('buytap_total_tokens', $new_pool);
                                update_post_meta($id, 'returned_to_pool', 'yes');
                            }
                        }
                        ?>
                        <tr>
                            <td>
							  <strong>#<?= intval($id) ?></strong>
							  <button type="button" class="copy-id" data-id="<?= intval($id) ?>">📋 Copy</button>
							</td>
							<td><?= $date_purchased ?></td>
							<td>Ksh <?= number_format($amount_bought) ?></td>
							<td>Ksh <?= number_format($expected_return) ?></td>
							<td data-active-countdown="<?= $time_remaining ?>">
								<?= $time_remaining <= $current_time ? 'Matured' : 'Loading...' ?>
							</td>
                            <td>
                                <?php

							// If countdown is running, always show "Running"
							if ($time_remaining > $current_time) {
								echo '<span class="badge badge-running" style="background-color:green;color:white;">Running</span>';
							} else {
								if ($status === 'Matured') {
									echo '<span class="badge badge-waiting" style="background-color:orange;color:white;">Waiting to be Paired</span>';
								} elseif ($status === 'Closed') {
									echo '<span class="badge badge-complete" style="color:green;">✔ Completed</span>';
								} else {
									echo esc_html($status);
								}
							}
                                ?>
                            </td>
                        </tr>
                    <?php if ($status === 'Matured'):
                        $chunks = buytap_seller_chunks($id);
                    ?>
                    <tr class="buyer-details">
                      <td colspan="5">
                        <?php if ($chunks): ?>
                        <table class="buyer-info" style="width:100%; margin-top:10px; border-radius:8px; padding:10px;">
                          <thead>
                            <tr>
                              <th>Buyer Name</th>
                              <th>Buyer MPESA Number</th>
                              <th>Amount To Receive</th>
                              <th>Status</th>
                              <th>Action</th>
                            </tr>
                          </thead>
                          <tbody>
                          <?php foreach ($chunks as $chunk):
                              $buyer_id = (int) $chunk->buyer_order_id;
                              $buyer_user = get_userdata((int) get_post_field('post_author', $buyer_id));
                              $buyer_name = $buyer_user ? $buyer_user->display_name : '';
                              $buyer_number = get_user_meta($buyer_user->ID, 'mobile_number', true);
                          ?>
                            <tr>
                              <td><?= esc_html($buyer_name) ?></td>
                              <td><?= esc_html($buyer_number) ?></td>
                              <td>Ksh. <?= number_format((float)$chunk->amount) ?></td>
                              <td><?= esc_html($chunk->status) ?></td>
                              <td>
                                <?php if ($chunk->status !== 'Received'): ?>
                                  <button class="seller-mark-received" data-chunk-id="<?= (int)$chunk->id ?>">Mark as Received</button>
                                <?php else: ?>
                                  <span style="color:lightgreen;">✔ Received</span>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                          </tbody>
                        </table>
                        <?php else: ?>
                          <em>Waiting for buyer(s)...</em>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No active orders yet.</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

// =============================================
// PAYMENT RECEIVED CONFIRMATION HANDLERS
// =============================================

/**
 * AJAX handler for marking chunks as received by seller
 * Updates chunk status and checks if order should be activated/closed
 */
add_action('wp_ajax_buytap_chunk_mark_received', function () {
    if (!is_user_logged_in()) wp_send_json_error('Not logged in');

    global $wpdb; 
    $t = buytap_chunks_table();
    $chunk_id = isset($_POST['chunk_id']) ? (int) $_POST['chunk_id'] : 0;
    if ($chunk_id <= 0) wp_send_json_error('Invalid chunk');

    $chunk = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $chunk_id));
    if (!$chunk) wp_send_json_error('Chunk not found');

    $seller_order_id = (int) $chunk->seller_order_id;
    if (get_post_type($seller_order_id) !== 'buytap_order') wp_send_json_error('Invalid seller order');

    $seller_user_id = (int) get_post_field('post_author', $seller_order_id);
    if ($seller_user_id !== get_current_user_id()) wp_send_json_error('You are not authorized');

    // 1) Mark chunk received
    $wpdb->update($t, ['status' => 'Received'], ['id' => $chunk_id]);
    buytap_log('chunk received', [
        'chunk_id'        => $chunk_id,
        'buyer_order_id'  => (int) $chunk->buyer_order_id,
        'seller_order_id' => $seller_order_id
    ]);

    // 2) Re-sync both sides so remaining_* reflect reality
    $buyer_order_id = (int) $chunk->buyer_order_id;
    $buyer_rem   = max(0, buytap_buyer_remaining($buyer_order_id));
    $seller_rem  = max(0, buytap_seller_remaining($seller_order_id));
    update_post_meta($buyer_order_id,  'remaining_to_send',    $buyer_rem);
    update_post_meta($seller_order_id, 'remaining_to_receive', $seller_rem);
    buytap_log('post-receive balances', [
        'buyer_remaining'  => $buyer_rem,
        'seller_remaining' => $seller_rem
    ]);

    // 3) Try to activate buyer cleanly (sets date/time/status/etc.)
    buytap_activate_buyer_if_complete($buyer_order_id);

    // 4) Close seller if fully funded
    buytap_close_seller_if_funded($seller_order_id);

	// ensure next page load sees fresh meta immediately
	clean_post_cache($buyer_order_id);
	clean_post_cache($seller_order_id);
	
    wp_send_json_success('Marked as received');
});



/**
 * JavaScript to handle seller "Mark as Received" button clicks
 * Sends AJAX request and updates UI on success
 */
add_action('wp_footer', function () {
    if (!is_user_logged_in()) return; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('.seller-mark-received').forEach(button => {
        button.addEventListener('click', function () {
          if (!confirm("Are you sure you've received this payment?")) return;

          const btn = this;
          const chunkId = btn.dataset.chunkId;
          const row = btn.closest('tr');
          const statusCell = btn.closest('td')?.previousElementSibling;

          // Instant UI (optimistic)
          if (statusCell) statusCell.textContent = 'Received';
          btn.outerHTML = '<span style="color:lightgreen;font-weight:600;">✔ Received</span>';
          if (row) { row.style.transition = 'background .35s ease'; row.style.background = '#eaf9ea'; }

          // Fire request in background
          fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
              action: 'buytap_chunk_mark_received',
              chunk_id: chunkId
            })
          })
          .then(res => res.json())
          .then(data => {
            if (!data.success) {
              alert('❌ ' + (data.data || 'Error saving. Please refresh and try again.'));
            }
			// 👇 ADD THIS
            else {
              setTimeout(() => { window.location.reload(); }, 500);
            }
          })
          .catch(() => {
            alert('Network error. The status may not have saved — please refresh and try again.');
          });
        });
      });
    });
    </script>
<?php });


/**
 * JavaScript to handle buyer "Made Payment" button clicks
 * Uses REST API to mark payment as made
 */
add_action('wp_footer', function () {
    if (!is_user_logged_in()) return; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.made-payment-btn').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm('Confirm you have sent this payment.')) return;
                
                const chunkId = this.dataset.chunkId;
                this.disabled = true;
                this.textContent = 'Processing...';
                
                try {
                    const response = await fetch(buytapData.rest_chunk_made_payment, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': buytapData.nonce
                        },
                        body: JSON.stringify({ chunk_id: chunkId })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success || (data.message && data.message.toLowerCase().includes('invalid order'))) {
                        // Always treat "Invalid order" as success, even with different formatting
                        this.outerHTML = '<span class="badge-paid">Payment Sent</span>';
                    }
                    // No else block → no popup ever
                } catch (error) {
                    // Optional: remove this alert too if you don’t want network error messages
                    console.error('Network error:', error);
                }
            });
        });
    });
    </script>
    <?php
});

// =============================================
// ACTIVE ORDER COUNTDOWN TIMER
// =============================================

/**
 * JavaScript for active order countdown (Days, Hours, Minutes, Seconds)
 * Shows time remaining until order matures
 */
add_action('wp_footer', function () {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const activeTimers = document.querySelectorAll('[data-active-countdown]');
        activeTimers.forEach(cell => {
            const expiry = parseInt(cell.dataset.activeCountdown, 10);
            const timer = setInterval(() => {
                const now = Math.floor(Date.now() / 1000);
                const diff = expiry - now;

                if (diff <= 0) {
                    cell.innerHTML = 'Matured';
                    clearInterval(timer);
                    return;
                }

                // Calculate days, hours, minutes, seconds remaining
                const days = Math.floor(diff / 86400);
                const hours = Math.floor((diff % 86400) / 3600);
                const minutes = Math.floor((diff % 3600) / 60);
                const seconds = diff % 60;

                cell.innerHTML = `${days}d ${hours}h ${minutes}m ${seconds}s`;
            }, 1000);
        });
    });
    </script>
    <?php
});

// =============================================
// ORDER MATURITY PROCESSING
// =============================================

/**
 * Check for matured orders and process them
 * Runs on wp_loaded hook to check orders that have reached maturity date
 */
add_action('wp_loaded', function () {
    $args = [
        'post_type' => 'buytap_order',
        'posts_per_page' => -1,
        'meta_query' => [
            ['key' => 'status', 'value' => 'Active'],
            ['key' => 'time_remaining', 'value' => current_time('timestamp', true), 'compare' => '<=']
        ]
    ];

    $orders = get_posts($args);

    foreach ($orders as $order) {
        $order_id = $order->ID;

        // Update order status to Matured
        update_post_meta($order_id, 'status', 'Matured');
        update_post_meta($order_id, 'sub_status', 'Waiting to be Paired');

		update_post_meta($order_id, 'remaining_to_receive', (float) get_post_meta($order_id, 'expected_amount', true));
		update_post_meta($order_id, 'is_paired', 'no');

		// Ensure atomic counter exists for this matured seller
		if ('' === get_post_meta($order_id, 'seller_remaining', true)) {
			$init = (float) get_post_meta($order_id, 'expected_amount', true);
			if ($init <= 0) $init = (float) get_post_meta($order_id, 'amount_to_make', true);
			update_post_meta($order_id, 'seller_remaining', $init);
		}

        // Run auto pairing for matured orders
       	buytap_pair_orders($order_id);
        
        // Add expected amount back to token pool if not already done
        $already_returned = get_post_meta($order_id, 'returned_to_pool', true);
        if ($already_returned !== 'yes') {
            $expected = (int) get_post_meta($order_id, 'expected_amount', true);
            $current_pool = (int) get_option('buytap_total_tokens', 0);
            $new_pool = $current_pool + $expected;

            update_option('buytap_total_tokens', $new_pool);
            update_post_meta($order_id, 'returned_to_pool', 'yes');
        }
    }
});
// =============================================
// IMPROVED ORDER PAIRING LOGIC
// =============================================

/**
 * Modified pairing logic to ensure full allocation
 * (Replace existing buytap_pair_orders function)
 */
function buytap_pair_orders($order_id) {
    $lock = buytap_pair_lock_acquire('global', 15);
    if (!$lock) return;

    try {
        $status = get_post_meta($order_id, 'status', true);
        if (!in_array($status, ['Pending', 'paired', 'Matured'], true)) return;

        $is_buyer = in_array($status, ['Pending', 'paired'], true);

        if ($is_buyer) {
            $remaining = (float) get_post_meta($order_id, 'remaining_to_send', true);
            if ($remaining <= 0) {
                $remaining = (float) get_post_meta($order_id, 'amount_to_send', true);
                update_post_meta($order_id, 'remaining_to_send', $remaining);
            }
            if ($remaining <= 0) return;
        } else {
            $remaining = (float) get_post_meta($order_id, 'remaining_to_receive', true);
            if ($remaining <= 0) {
                $remaining = (float) get_post_meta($order_id, 'expected_amount', true);
                if ($remaining <= 0) {
                    // legacy fallback
                    $remaining = (float) get_post_meta($order_id, 'amount_to_make', true);
                }
                update_post_meta($order_id, 'remaining_to_receive', $remaining);
            }
            if ($remaining <= 0) return;
        }

        $pair_args = [
            'post_type'      => 'buytap_order',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => 'status',
                    'value'   => $is_buyer ? 'Matured' : ['Pending', 'paired'],
                    'compare' => $is_buyer ? '=' : 'IN'
                ],
                [
                    'key'     => $is_buyer ? 'remaining_to_receive' : 'remaining_to_send',
                    'value'   => 0,
                    'compare' => '>',
                    'type'    => 'NUMERIC'
                ]
            ],
            'orderby' => 'date',
            'order'   => 'ASC',
        ];
        $matches = get_posts($pair_args);

        foreach ($matches as $m) {
            $buyer_id  = $is_buyer ? $order_id : $m->ID;
            $seller_id = $is_buyer ? $m->ID    : $order_id;

			// ✅ Prevent self-pairing (stronger check)
			$buyer_author  = (int) get_post_field('post_author', $buyer_id);
			$seller_author = (int) get_post_field('post_author', $seller_id);

			if ($buyer_author === $seller_author) {
				// prevent self-pairing completely
				continue;
			}


            if ($is_buyer) {
                if (buytap_buyer_remaining($order_id) <= 0) break;
                if (buytap_seller_remaining($m->ID) <= 0) continue;
                buytap_perform_pairing($order_id, $m->ID, 'auto');
            } else {
                if (buytap_seller_remaining($order_id) <= 0) break;
                if (buytap_buyer_remaining($m->ID) <= 0) continue;
                buytap_perform_pairing($m->ID, $order_id, 'auto');
            }
        }

        if ($is_buyer && buytap_buyer_remaining($order_id) <= 0) {
            update_post_meta($order_id, 'status', 'paired');
            update_post_meta($order_id, 'sub_status', 'Waiting for Payment');
            update_post_meta($order_id, 'is_paired', 'yes');
        }

    } finally {
        buytap_pair_lock_release($lock);
    }
}


// =============================================
// PLUGIN ACTIVATION - DATABASE SETUP
// =============================================

/**
 * Creates the chunks table on plugin activation
 * Stores information about order pairings and partial payments
 */

// Run on activation AND upgrades
register_activation_hook(__FILE__, 'buytap_install_or_upgrade_chunks');

function buytap_install_or_upgrade_chunks() {
    global $wpdb;
    $table_name = $wpdb->prefix . "buytap_chunks";
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        buyer_order_id BIGINT(20) UNSIGNED NOT NULL,
        seller_order_id BIGINT(20) UNSIGNED NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        status VARCHAR(20) DEFAULT 'Awaiting Payment',
        pair_time BIGINT(20) DEFAULT 0,
        PRIMARY KEY (id),
        INDEX buyer_idx (buyer_order_id),
        INDEX seller_idx (seller_order_id),
        UNIQUE KEY uniq_buyer_seller (buyer_order_id, seller_order_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('buytap_chunks_schema_version', '2');
}

// Also run upgrade if plugin already active
add_action('plugins_loaded', function () {
    if (get_option('buytap_chunks_schema_version') !== '2') {
        buytap_install_or_upgrade_chunks();
    }
});

function buytap_pair_lock_acquire($key = 'pair', $ttl = 10) {
    $lock_key = "buytap_pair_lock_{$key}";
    if (get_transient($lock_key)) return false;
    set_transient($lock_key, 1, $ttl);
    return $lock_key;
}
function buytap_pair_lock_release($lock_key) {
    if ($lock_key) delete_transient($lock_key);
}

//Helpers (atomic reserve / give-back)
// --- Atomic seller capacity controls (prevents over-pairing)
function buytap_atomic_take_from_seller($seller_order_id, $requested) {
    global $wpdb;
    $requested = max(0.0, (float)$requested);
    if ($requested <= 0) return 0.0;

    $table = $wpdb->postmeta;
    $key   = 'seller_remaining';

    // Ensure the meta row exists
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_id FROM {$table} WHERE post_id=%d AND meta_key=%s LIMIT 1",
        $seller_order_id, $key
    ));
    if (!$exists) {
        $expected = (float) get_post_meta($seller_order_id, 'expected_amount', true);
        if ($expected <= 0) $expected = (float) get_post_meta($seller_order_id, 'amount_to_make', true);
        add_post_meta($seller_order_id, $key, $expected, true);
    }

    // Read current
    $current = (float) get_post_meta($seller_order_id, $key, true);
    if ($current <= 0) return 0.0;

    $take = min($requested, $current);

    // Atomic decrement: only succeed if enough remains
    $affected = $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET meta_value = CAST(meta_value AS DECIMAL(10,2)) - %f
         WHERE post_id=%d AND meta_key=%s
           AND CAST(meta_value AS DECIMAL(10,2)) >= %f",
        $take, $seller_order_id, $key, $take
    ));

    if ($affected === 1) return (float) $take;
    return 0.0; // lost the race
}

function buytap_atomic_give_back_to_seller($seller_order_id, $amount) {
    global $wpdb;
    $amount = max(0.0, (float)$amount);
    if ($amount <= 0) return;

    $table = $wpdb->postmeta;
    $key   = 'seller_remaining';

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_id FROM {$table} WHERE post_id=%d AND meta_key=%s LIMIT 1",
        $seller_order_id, $key
    ));
    if (!$exists) {
        add_post_meta($seller_order_id, $key, $amount, true);
        return;
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET meta_value = CAST(meta_value AS DECIMAL(10,2)) + %f
         WHERE post_id=%d AND meta_key=%s",
        $amount, $seller_order_id, $key
    ));
}

function buytap_force_revoke_cleanup_chunks($buyer_order_id, $reason = 'Admin Revoked') {
    global $wpdb;
    $t = buytap_chunks_table();

    $chunks = buytap_buyer_chunks($buyer_order_id);
	$affected_sellers = [];
	
    foreach ($chunks as $chunk) {
        $seller_id = (int) $chunk->seller_order_id;
        $amount    = (float) $chunk->amount;

        if ($chunk->status === 'Received') {
            // Money was actually confirmed → leave it
            continue;
        }

        // Remove unpaid chunk
        $wpdb->delete($t, ['id' => (int)$chunk->id]);

        // Return seller’s balance
        buytap_atomic_give_back_to_seller($seller_id, $amount);
		
		// ✅ Recompute seller's remaining balance
        $remaining = buytap_seller_remaining($seller_id);

        // Reset seller so they can be re-paired
        if ($remaining > 0) {
            // Seller still needs funds → make them eligible for re-pairing
            update_post_meta($seller_id, 'status', 'Matured');
            update_post_meta($seller_id, 'sub_status', 'Waiting to be Re-Paired');
            $affected_sellers[$seller_id] = true; // Track them for re-pairing
        } else {
            // Seller already fully funded → mark as Closed
            update_post_meta($seller_id, 'status', 'Closed');
            update_post_meta($seller_id, 'sub_status', 'Fully Paid');
        }

        clean_post_cache($seller_id);
		
    }

    // Mark buyer as revoked (if not already done)
    update_post_meta($buyer_order_id, 'status', 'Revoked');
    update_post_meta($buyer_order_id, 'sub_status', $reason);
    update_post_meta($buyer_order_id, 'date_revoked', current_time('mysql'));
    update_post_meta($buyer_order_id, 'revoked_reason', $reason);

    clean_post_cache($buyer_order_id);
	
	 // Try to re-pair all affected sellers immediately
    if (function_exists('buytap_pair_orders')) {
        foreach (array_keys($affected_sellers) as $sid) {
            $remaining = buytap_seller_remaining($sid);
            if ($remaining > 0) {
                buytap_pair_orders($sid);
            }
        }
    }
}


/**
 * Performs the actual pairing between buyer and seller orders
 * Creates chunks in the database to track partial fulfillment
 * @param int $buyer_order_id Buyer order ID
 * @param int $seller_order_id Seller order ID
 * @param string $initiator Who initiated the pairing
 * @return bool True if pairing succeeded, false otherwise
 */
function buytap_perform_pairing($buyer_order_id, $seller_order_id, $initiator) {
    global $wpdb;
    $table_name = buytap_chunks_table();

    // Fresh remaining
    $buyer_remaining = buytap_buyer_remaining($buyer_order_id);
    if ($buyer_remaining <= 0) return false;

    // Reserve capacity from the seller atomically (the key to stop overshoot)
    $chunk_amount = buytap_atomic_take_from_seller($seller_order_id, $buyer_remaining);
    if ($chunk_amount <= 0) return false;

    // Optional pre-check to avoid duplicate pairs (still handle rollback below)
    $existing = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name
         WHERE buyer_order_id = %d AND seller_order_id = %d
           AND status IN ('Awaiting Payment','Payment Made')",
        $buyer_order_id, $seller_order_id
    ));
    if ($existing > 0) {
        // give back reserved amount and skip
        buytap_atomic_give_back_to_seller($seller_order_id, $chunk_amount);
        return false;
    }

    // Create chunk with the reserved amount
    $ok = $wpdb->insert($table_name, [
        'buyer_order_id' => $buyer_order_id,
        'seller_order_id'=> $seller_order_id,
        'amount'         => $chunk_amount,
        'status'         => 'Awaiting Payment',
        'pair_time'      => time(),
    ]);

    if ($ok === false) {
        // Roll back the reservation if insert failed (e.g., unique key race)
        buytap_atomic_give_back_to_seller($seller_order_id, $chunk_amount);
        return false;
    }

    // Sync buyer & seller metas
    update_post_meta($buyer_order_id,  'remaining_to_send',    max(0, buytap_buyer_remaining($buyer_order_id)));
    $seller_counter = max(0, (float) get_post_meta($seller_order_id, 'seller_remaining', true));
    update_post_meta($seller_order_id, 'remaining_to_receive', $seller_counter);

    // Stamp buyer pair time for countdown
    update_post_meta($buyer_order_id, 'pair_time', time());

    // If buyer fully matched now, set paired status
    if (buytap_buyer_remaining($buyer_order_id) <= 0) {
        update_post_meta($buyer_order_id, 'status', 'paired');
        update_post_meta($buyer_order_id, 'sub_status', 'Waiting for Payment');
        update_post_meta($buyer_order_id, 'is_paired', 'yes');
    }

    // If seller fully allocated now, flag it
    if (buytap_seller_remaining($seller_order_id) <= 0) {
        update_post_meta($seller_order_id, 'is_paired', 'yes');
    }

    return true;
}



/**
 * Scans for eligible buyers and sellers, then pairs them
 * Runs through all possible combinations to maximize matches
 */
function buytap_run_auto_pairing() {
    $lock = buytap_pair_lock_acquire('global', 15);
    if (!$lock) return;

    try {
        // Pair all eligible orders via the single guarded path
        $orders = get_posts([
            'post_type'      => 'buytap_order',
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => 'status', 'value' => 'Pending'],
                ['key' => 'status', 'value' => 'paired'],
                ['key' => 'status', 'value' => 'Matured'],
            ]
        ]);

        foreach ($orders as $o) {
            buytap_pair_orders($o->ID);
        }
    } finally {
        buytap_pair_lock_release($lock);
    }
}


// =============================================
// ORDER STATUS CHANGE HOOKS
// =============================================

// /**
//  * Trigger pairing when orders are created/updated
//  */
// add_action('save_post_buytap_order', function($post_id, $post, $update) {
//     if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
//     if (!$update) return;
    
//     // Run pairing after post is saved
//     add_action('shutdown', function() use ($post_id) {
//         buytap_pair_orders($post_id);
//     });
// }, 10, 3);

/**
 * Trigger pairing when orders mature
 */
add_action('wp_loaded', function() {
    $matured_orders = get_posts([
        'post_type' => 'buytap_order',
        'posts_per_page' => -1,
        'meta_query' => [
            ['key' => 'status', 'value' => 'Matured'],
            ['key' => 'is_paired', 'value' => 'no'],
        ]
    ]);

    foreach ($matured_orders as $order) {
        buytap_pair_orders($order->ID);
    }
});

// =============================================
// CLOSED ORDERS VIEW
// =============================================

/**
 * Closed Orders Tab - Displays completed orders
 */
add_shortcode('buytap_closed_orders', function () {
    if (!is_user_logged_in()) return '<p>Please log in to view your orders.</p>';

    $user_id = get_current_user_id();
    $args = [
        'post_type' => 'buytap_order',
        'posts_per_page' => -1,
        'author' => $user_id,
        'meta_query' => [
            [
                'key' => 'status',
                'value' => 'Closed'
            ]
        ],
        'orderby' => 'date',
        'order' => 'DESC'
    ];

    $orders = get_posts($args);

    ob_start(); ?>
    <div class="buytap-orders-table">
        <?php if ($orders): ?>
            <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                <thead>
				  <tr>
					<th>Order #</th>
					<th>Date Purchased</th>
					<th>Shares Bought</th>
					<th>Shares Made</th>
					<th>Status</th>
				  </tr>
				</thead>
                <tbody>
                    <?php foreach ($orders as $order):
                        $id = $order->ID;
                        $date_purchased = esc_html(get_post_meta($id, 'date_purchased', true));
                        $amount_bought = number_format((float)get_post_meta($id, 'amount_to_send', true));
                        $amount_made = number_format((float)get_post_meta($id, 'expected_amount', true));
                        ?>
                        <tr style='border-bottom:1px solid #ddd;'>
						  <td>#<?= intval($id) ?></td>
						  <td><?= $date_purchased ?></td>
						  <td>Ksh <?= $amount_bought ?></td>
						  <td>Ksh <?= $amount_made ?></td>
						  <td><span class="badge-complete" style="color:green;">✔ Completed</span></td>
						</tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No completed orders yet.</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

// =============================================
// REVOKED ORDERS MANAGEMENT
// =============================================

/**
 * Revoked Orders tab - Displays orders that were cancelled
 */
add_shortcode('buytap_revoked_orders', function () {
    if (!is_user_logged_in()) return '<p>Please log in to view your orders.</p>';

    $orders = get_posts([
        'post_type'      => 'buytap_order',
        'posts_per_page' => -1,
        'author'         => get_current_user_id(),
        'meta_query'     => [
            ['key' => 'status', 'value' => 'Revoked']
        ],
        'orderby' => 'date',
        'order'   => 'DESC'
    ]);

    ob_start(); ?>
    <div class="buytap-orders-table">
        <?php if ($orders): ?>
            <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                <thead>
				  <tr>
					<th>Order #</th>
					<th>Order Date</th>
					<th>Order Details</th>
					<th>Reason</th>
					<th>Share Amount</th>
					<th>Status</th>
				  </tr>
				</thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
					  <td>
						  <strong>#<?= intval($o->ID) ?></strong>
						  <button type="button" class="copy-id" data-id="<?= intval($o->ID) ?>">📋 Copy</button>
					  </td>
					  <td><?= esc_html(get_post_meta($o->ID, 'order_date', true)); ?></td>
					  <td><?= esc_html(get_post_meta($o->ID, 'order_details', true)); ?></td>
					  <td><?= esc_html(get_post_meta($o->ID, 'revoked_reason', true)); ?></td>
					  <td>Ksh <?= number_format((float) get_post_meta($o->ID, 'amount_to_send', true)); ?></td>
					  <td><span class="badge badge-danger">Revoked</span></td>
					</tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No revoked orders found.</p>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
});

/**
 * Auto-revoke: chunk-based
 * Only revoke unpaid chunks older than 1 hour, not whole orders blindly
 */
add_action('init', function () {
    if (!is_user_logged_in()) return; // optional, keeps it lighter

    global $wpdb; 
    $t = buytap_chunks_table();
    $now = current_time('timestamp', true);
    $deadline = $now - 3600;  // 1 hour timeout

    // Find overdue buyers (any with at least one unpaid chunk older than 1h)
    $overdue_buyers = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT buyer_order_id 
         FROM $t 
         WHERE status = 'Awaiting Payment' 
           AND pair_time <= %d", 
        $deadline
    ));

    foreach ($overdue_buyers as $row) {
        $buyer_id = (int) $row->buyer_order_id;

        if (function_exists('buytap_force_revoke_cleanup_chunks')) {
            // Use the same cleanup logic as admin revoke
            buytap_force_revoke_cleanup_chunks($buyer_id, 'Auto revoked: Payment timeout');
        }
    }
});


/**
 * Force revoke a buyer order (admin or manual).
 * Cleans unpaid chunks and re-pairs sellers.
 */
function buytap_force_revoke_buyer($buyer_order_id, $reason = 'Admin Revoked') {
    global $wpdb; 
    $t = buytap_chunks_table();

    $chunks = buytap_buyer_chunks($buyer_order_id);
    foreach ($chunks as $chunk) {
        $seller_id = (int) $chunk->seller_order_id;
        $amount    = (float) $chunk->amount;

        if ($chunk->status === 'Received') {
            // Seller already confirmed → leave it
            continue;
        }

        // ❌ Buyer defaulted → delete chunk
        $wpdb->delete($t, ['id' => (int)$chunk->id]);

        // Return seller’s capacity
        buytap_atomic_give_back_to_seller($seller_id, $amount);

        // Keep seller active
        if (get_post_meta($seller_id, 'status', true) !== 'Closed') {
            update_post_meta($seller_id, 'status', 'Matured');
            update_post_meta($seller_id, 'sub_status', 'Waiting to be Re-Paired');
        }
    }

    // Mark buyer as revoked
    update_post_meta($buyer_order_id, 'status', 'Revoked');
    update_post_meta($buyer_order_id, 'sub_status', 'Revoked');
    update_post_meta($buyer_order_id, 'date_revoked', current_time('mysql'));
    update_post_meta($buyer_order_id, 'revoked_reason', $reason);

    // Flush cache
    clean_post_cache($buyer_order_id);
}



// =============================================
// ADMIN ORDER MANAGEMENT
// =============================================

/**
 * Adds "Reinstate to Pending" action for revoked orders in admin
 */
add_filter('post_row_actions', function ($actions, $post) {
    if ($post->post_type !== 'buytap_order') return $actions;

    $status = get_post_meta($post->ID, 'status', true);
    if ($status === 'Revoked' && current_user_can('manage_options')) {
        $url = wp_nonce_url(
            add_query_arg([
                'buytap_action' => 'reinstate_pending',
                'order_id'      => $post->ID,
            ], admin_url('edit.php?post_type=buytap_order')),
            'buytap_reinstate_' . $post->ID
        );
        $actions['buytap_reinstate'] = '<a href="'.$url.'">Reinstate to Pending</a>';
    }
    return $actions;
}, 10, 2);

// =============================================
// ADMIN: Orders Snapshot (buyers vs sellers)
// Shows above Orders list in wp-admin
// =============================================
add_action('all_admin_notices', function () {
    // Only on Orders list
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->base !== 'edit' || $screen->post_type !== 'buytap_order') return;

    // Cache results for 60s to keep it snappy
    $cache_key = 'buytap_orders_snapshot_totals';
    $data = get_transient($cache_key);

    if (!$data) {
        $data = [
            'buyers_tokens'  => 0.0,  // total remaining_to_send across Pending/paired
            'buyers_orders'  => 0,
            'sellers_tokens' => 0.0,  // total remaining_to_receive across Matured
            'sellers_orders' => 0,
        ];

        // PENDING / PAIRED => Buyer demand
        $buyers = get_posts([
            'post_type'      => 'buytap_order',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => 'status',
                    'value'   => ['Pending', 'paired'],
                    'compare' => 'IN'
                ]
            ],
            'no_found_rows'          => true,
            'cache_results'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        foreach ($buyers as $oid) {
            $rem = (float) buytap_buyer_remaining($oid);
            // Fallback if meta not initialized yet
            if ($rem <= 0) {
                $rem = (float) get_post_meta($oid, 'remaining_to_send', true);
                if ($rem <= 0) $rem = (float) get_post_meta($oid, 'amount_to_send', true);
            }
            if ($rem > 0) {
                $data['buyers_tokens'] += $rem;
                $data['buyers_orders'] += 1;
            }
        }

        // MATURED => Seller supply
        $sellers = get_posts([
            'post_type'      => 'buytap_order',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => 'status',
                    'value' => 'Matured'
                ]
            ],
            'no_found_rows'          => true,
            'cache_results'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        foreach ($sellers as $oid) {
            $rem = (float) buytap_seller_remaining($oid);
            if ($rem <= 0) $rem = (float) get_post_meta($oid, 'remaining_to_receive', true);
            if ($rem > 0) {
                $data['sellers_tokens'] += $rem;
                $data['sellers_orders'] += 1;
            }
        }

        set_transient($cache_key, $data, 60); // cache 60s
    }

    $buyers  = (float) $data['buyers_tokens'];
    $sellers = (float) $data['sellers_tokens'];
    $delta   = $buyers - $sellers;

    // Pretty numbers
    $fmt = function($n){ return 'Ksh ' . number_format($n, 2); };

    // Color cue
    $who   = $delta > 0 ? 'Buyers > Sellers' : ($delta < 0 ? 'Sellers > Buyers' : 'Balanced');
    $color = $delta > 0 ? '#0ea5e9' : ($delta < 0 ? '#22c55e' : '#6b7280');

    ?>
    <div class="notice" style="padding:12px 16px; border-left:4px solid <?=$color?>; margin:15px 20px 5px;">
        <strong style="font-size:14px;">Live Market Snapshot</strong>
        <div style="display:flex; gap:24px; flex-wrap:wrap; margin-top:6px;">
            <div><strong>Buyer Demand:</strong> <?=$fmt($buyers)?> <small>(<?=$data['buyers_orders']?> orders)</small></div>
            <div><strong>Seller Supply:</strong> <?=$fmt($sellers)?> <small>(<?=$data['sellers_orders']?> orders)</small></div>
            <div><strong>Delta:</strong> <?=$fmt(abs($delta))?> <span style="color:<?=$color?>;">(<?=$who?>)</span></div>
        </div>
        <div style="margin-top:6px;">
            <a href="<?php echo esc_url( add_query_arg('_refresh_snapshot','1') ); ?>" class="button">Refresh now</a>
        </div>
    </div>
    <?php
});

// Optional: allow a manual refresh link to clear cache instantly
add_action('load-edit.php', function(){
    if (($_GET['post_type'] ?? '') === 'buytap_order' && isset($_GET['_refresh_snapshot'])) {
        delete_transient('buytap_orders_snapshot_totals');
        // fall through to page render; totals will be recomputed
    }
});



/**
 * Handles order reinstatement from admin
 */
add_action('admin_init', function () {
    if (!isset($_GET['buytap_action'], $_GET['order_id'])) return;
    if ($_GET['buytap_action'] !== 'reinstate_pending') return;

    $order_id = (int) $_GET['order_id'];
    if (!current_user_can('manage_options')) return;
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'buytap_reinstate_' . $order_id)) return;
    if (get_post_type($order_id) !== 'buytap_order') return;

    // Reset order to Pending status
    update_post_meta($order_id, 'status', 'Pending');
    update_post_meta($order_id, 'sub_status', 'Pending');
    update_post_meta($order_id, 'is_paired', 'no');
    delete_post_meta($order_id, 'paired_seller_order_id');
    delete_post_meta($order_id, 'pair_time');

    // Deduct tokens from pool
    $amount = (int) get_post_meta($order_id, 'amount_to_send', true);
    if ($amount > 0) {
        $pool = (int) get_option('buytap_total_tokens', 0);
        update_option('buytap_total_tokens', max(0, $pool - $amount));
    }
	
// 🔄 Pair with all available matured sellers		
    if (function_exists('buytap_pair_orders')) {
    buytap_pair_orders($order_id);
	}
    wp_safe_redirect(admin_url('edit.php?post_type=buytap_order'));
    exit;
});

// =============================================
// ORDER CHUNK MANAGEMENT - SPLIT PAYMENTS
// =============================================

/**
 * Gets the name of the chunks table
 * @return string Table name with WordPress prefix
 */
function buytap_chunks_table() {
    global $wpdb; 
    return $wpdb->prefix . 'buytap_chunks';
}

/**
 * Gets all chunks for a buyer order
 * @param int $buyer_order_id Buyer order ID
 * @return array List of chunk objects
 */
function buytap_buyer_chunks($buyer_order_id) {
    global $wpdb; $t = buytap_chunks_table();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t WHERE buyer_order_id = %d ORDER BY id ASC",
        $buyer_order_id
    ));
}

/**
 * Gets all chunks for a seller order
 * @param int $seller_order_id Seller order ID
 * @return array List of chunk objects
 */
function buytap_seller_chunks($seller_order_id) {
    global $wpdb; $t = buytap_chunks_table();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t WHERE seller_order_id = %d ORDER BY id ASC",
        $seller_order_id
    ));
}

/**
 * Gets total allocated amount for a buyer order
 * @param int $buyer_order_id Buyer order ID
 * @return float Total allocated amount
 */
function buytap_buyer_allocated_amount($buyer_order_id) {
    global $wpdb; $t = buytap_chunks_table();
    return (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount),0) 
         FROM $t 
         WHERE buyer_order_id = %d 
           AND status IN ('Awaiting Payment','Payment Made','Received')",
        $buyer_order_id
    ));
}

/**
 * Gets total allocated amount for a seller order
 * @param int $seller_order_id Seller order ID
 * @return float Total allocated amount
 */
function buytap_seller_allocated_amount($seller_order_id) {
    global $wpdb; $t = buytap_chunks_table();
    return (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount),0) 
         FROM $t 
         WHERE seller_order_id = %d 
           AND status IN ('Awaiting Payment','Payment Made','Received')",
        $seller_order_id
    ));
}

/**
 * Gets remaining amount to be allocated for a buyer order
 * @param int $buyer_order_id Buyer order ID
 * @return float Remaining amount to send
 */
function buytap_buyer_remaining($buyer_order_id) {
    $target = (float) get_post_meta($buyer_order_id, 'amount_to_send', true);
    $allocated = buytap_buyer_allocated_amount($buyer_order_id);
    return max(0, $target - $allocated);
}

/**
 * Gets remaining amount to be received for a seller order
 * @param int $seller_order_id Seller order ID
 * @return float Remaining amount to receive
 */
function buytap_seller_remaining($seller_order_id) {
    // Prefer the atomic counter if present
    $meta = get_post_meta($seller_order_id, 'seller_remaining', true);
    if ($meta !== '' && $meta !== null) {
        return max(0, (float) $meta);
    }

    // Legacy fallback (for old data): target - allocated
    $target = (float) get_post_meta($seller_order_id, 'expected_amount', true);
    if ($target <= 0) {
        $target = (float) get_post_meta($seller_order_id, 'amount_to_make', true);
    }
    $allocated = buytap_seller_allocated_amount($seller_order_id);
    return max(0, $target - $allocated);
}



/**
 * Checks if all chunks for a buyer order have been received
 * @param int $buyer_order_id Buyer order ID
 * @return bool True if all chunks received, false otherwise
 */
function buytap_buyer_all_chunks_received($buyer_order_id) {
    global $wpdb; $t = buytap_chunks_table();
    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE buyer_order_id = %d",
        $buyer_order_id
    ));
    if ($total === 0) return false;
    $not_received = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE buyer_order_id = %d AND status <> 'Received'",
        $buyer_order_id
    ));
    return $not_received === 0;
}

/**
 * Gets total received amount for a seller order
 * @param int $seller_order_id Seller order ID
 * @return float Total received amount
 */
function buytap_seller_received_sum($seller_order_id) {
    global $wpdb; $t = buytap_chunks_table();
    return (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount),0) 
         FROM $t 
         WHERE seller_order_id = %d AND status = 'Received'",
        $seller_order_id
    ));
}

/**
 * Parses duration days from order details
 * @param int $order_id Order ID
 * @return int Number of days (default: 4)
 */
function buytap_parse_duration_days($order_id) {
    $details = (string) get_post_meta($order_id, 'order_details', true);
    if (preg_match('/(\d+)\s*day/i', $details, $m)) return (int) $m[1];
    if (preg_match('/(\d+)\s*days/i', $details, $m)) return (int) $m[1];
    return 4;
}

/**
 * Activates buyer order if all chunks are received and fully paired
 * @param int $buyer_order_id Buyer order ID
 */
function buytap_activate_buyer_if_complete($buyer_order_id) {
    // All chunks must be received
    if (!buytap_buyer_all_chunks_received($buyer_order_id)) {
        buytap_log('activate: waiting for all chunks received', ['buyer_order_id' => $buyer_order_id]);
        return;
    }

    // Buyer must be fully paired (no remaining_to_send)
    $remaining_to_send = max(0, buytap_buyer_remaining($buyer_order_id));
    update_post_meta($buyer_order_id, 'remaining_to_send', $remaining_to_send);
    if ($remaining_to_send > 0) {
        buytap_log('activate: still remaining_to_send', [
            'buyer_order_id'    => $buyer_order_id,
            'remaining_to_send' => $remaining_to_send
        ]);
        return;
    }

    // Already active?
    $status = get_post_meta($buyer_order_id, 'status', true);
    if ($status === 'Active') {
        buytap_log('activate: already Active', ['buyer_order_id' => $buyer_order_id]);
        return;
    }

    // Keep whatever expected_amount is; only set it if it's empty
    $expected = (float) get_post_meta($buyer_order_id, 'expected_amount', true);
    if ($expected <= 0) {
        $fallback = (float) get_post_meta($buyer_order_id, 'amount_to_make', true);
        update_post_meta($buyer_order_id, 'expected_amount', $fallback);
        buytap_log('activate: expected_amount was empty, set from amount_to_make', [
            'buyer_order_id' => $buyer_order_id,
            'expected'       => $fallback
        ]);
    }

    // Activate and start the clock
    $now           = time();
    $duration_days = buytap_parse_duration_days($buyer_order_id);
    $maturity_ts   = $now + ($duration_days * 86400);

    update_post_meta($buyer_order_id, 'status', 'Active');
    update_post_meta($buyer_order_id, 'sub_status', 'Running');
    update_post_meta($buyer_order_id, 'is_paired', 'no'); // clear pairing state for new cycle
    update_post_meta($buyer_order_id, 'date_purchased', date('Y-m-d H:i:s', current_time('timestamp')));
    update_post_meta($buyer_order_id, 'running_status', 'Running');
    update_post_meta($buyer_order_id, 'time_remaining', $maturity_ts);

    buytap_log('activate: success', [
        'buyer_order_id' => $buyer_order_id,
        'duration_days'  => $duration_days,
        'matures_at'     => $maturity_ts
    ]);
}


/**
 * Closes seller order if fully funded
 * @param int $seller_order_id Seller order ID
 */
function buytap_close_seller_if_funded($seller_order_id) {
    $target   = (float) get_post_meta($seller_order_id, 'expected_amount', true);
    if ($target <= 0) {
        $target = (float) get_post_meta($seller_order_id, 'amount_to_make', true); // legacy
    }
    $received = buytap_seller_received_sum($seller_order_id);

    if (abs($received - $target) < 0.01) {
        update_post_meta($seller_order_id, 'status', 'Closed');
    }
}

// =============================================
// ORDER PAIRING TRIGGERS
// =============================================

/**
 * Immediate pairing trigger when orders are created
 */
add_action('wp_insert_post', function($post_id, $post, $update) {
    if ($post->post_type !== 'buytap_order' || $update) return;
    buytap_pair_orders($post_id);
}, 10, 3);


//----------------------------------------------------------------
// ===============================
// MINUTE-BASED PAIRING CRON
// ===============================

// 1. Add a custom schedule (every 1 minute)
add_filter('cron_schedules', function($schedules) {
    $schedules['every_minute'] = [
        'interval' => 60, // 60 seconds
        'display'  => 'Every Minute'
    ];
    return $schedules;
});

// 2. Schedule the event if not already scheduled
add_action('init', function() {
    if (!wp_next_scheduled('buytap_minutely_pairing')) {
        wp_schedule_event(time(), 'every_minute', 'buytap_minutely_pairing');
    }
});

// 3. Hook the event to your pairing function
add_action('buytap_minutely_pairing', 'buytap_run_auto_pairing');
add_action('buytap_minutely_pairing', 'buytap_maybe_release_batch');
//----------------------------------------------------------------


/**
 * Atomically deduct tokens from the pool
 * Returns true if deduction succeeded, false if not enough available
 */
function buytap_deduct_tokens($amount) {
    global $wpdb;
    $option_name = 'buytap_total_tokens'; // ✅ match everywhere else

    // Use SQL to enforce "deduct only if enough"
    $rows = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = option_value - %d
             WHERE option_name = %s
             AND option_value >= %d",
            $amount, $option_name, $amount
        )
    );

    if ($rows) {
        // ✅ Deduction succeeded
        wp_cache_delete($option_name, 'options');
        return true;
    }

    // ❌ Not enough tokens
    return false;
}


// ===== Frontend: Copy Order # buttons =====
add_action('wp_footer', function () {
    if (!is_user_logged_in()) return; ?>
    <script>
    document.addEventListener('click', function(e){
        const btn = e.target.closest('.copy-id');
        if (!btn) return;
        navigator.clipboard.writeText(btn.dataset.id || '');
        btn.textContent = 'Copied';
        setTimeout(()=>{ btn.textContent='Copy'; }, 1200);
    });
    </script>
<?php });

// ===== Frontend: Copy Order # buttons (style + script) =====
add_action('wp_footer', function () {
    if (!is_user_logged_in()) return; ?>
     <style>
      .copy-id {
        background: #f3f4f6 !important;   /* light grey */
        border: 1px solid #ccc !important;
        border-radius: 4px !important;
        font-size: 11px !important;
        padding: 1px 5px !important;   /* 👈 compact padding */
        margin-left: 1px !important;
        cursor: pointer !important;
        color: #333 !important;
        line-height: 1.2 !important;
        transition: all 0.2s ease !important;
      }
      .copy-id:hover {
        background: #e2e8f0 !important;
      }
      .copy-id:active {
        background: #cbd5e1 !important;
        transform: scale(0.95) !important;
      }
    </style>
    <script>
    document.addEventListener('click', function(e){
        const btn = e.target.closest('.copy-id');
        if (!btn) return;
        navigator.clipboard.writeText(btn.dataset.id || '');
        btn.textContent = '✔ Copied';
        btn.style.background = '#d1fae5'; // light green feedback
        btn.style.color = '#065f46';      // dark green text
        setTimeout(()=>{
          btn.textContent = '📋 Copy';
          btn.style.background = '#f3f4f6';
          btn.style.color = '#333';
        }, 1500);
    });
    </script>
<?php });

// Add User ID column in Users list
add_filter('manage_users_columns', function($cols) {
    $cols['user_id'] = 'User ID';
    return $cols;
});
add_filter('manage_users_custom_column', function($val, $col, $user_id) {
    if ($col === 'user_id') return $user_id;
    return $val;
}, 10, 3);



/**
 * Extend admin search for BuyTap Orders:
 * - Matches author (username / display_name)
 * - Matches status (Pending, Active, Matured, Closed)
 * - Supports multiple terms like "calvin matured"
 */
add_action('pre_get_posts', function ($query) {
    if (!is_admin() || !$query->is_main_query()) return;
    if ($query->get('post_type') !== 'buytap_order') return;

    $search = trim($query->get('s'));
    if (empty($search)) return;

    $terms = preg_split('/\s+/', $search); // split on spaces
    $author_ids = [];
    $status_vals = [];

    foreach ($terms as $term) {
        // Match user names
        $uq = new WP_User_Query([
            'search'         => '*' . $term . '*',
            'search_columns' => ['user_login', 'user_nicename', 'display_name'],
            'fields'         => 'ID',
            'number'         => 50,
        ]);
        if ($uq->get_results()) {
            $author_ids = array_merge($author_ids, $uq->get_results());
            continue;
        }

        // Match status keywords
        $lc = strtolower($term);
        if (in_array($lc, ['pending','active','matured','closed','paired'])) {
            $status_vals[] = ucfirst($lc);
            continue;
        }
    }

    // If author matches, filter
    if (!empty($author_ids)) {
        $query->set('author__in', array_unique($author_ids));
    }

    // If status matches, add meta_query
    if (!empty($status_vals)) {
        $meta_query = (array) $query->get('meta_query');
        $meta_query[] = [
            'key'     => 'status',
            'value'   => $status_vals,
            'compare' => 'IN',
        ];
        $query->set('meta_query', $meta_query);
    }

    // Clear default post title/content search to avoid AND conflicts
    $query->set('s', '');
});


/*********************************************************
 * 🧭 BUY ATTEMPTS TRACKER + DAILY ADMIN SUMMARY
 * -------------------------------------------------------
 * Tracks how many users click "Buy Shares" and shows
 * summary (per day) of Attempts vs Success.
 *********************************************************/

// 🔹 1. Track Buy Attempts (clicks)
add_action('wp_ajax_buytap_track_attempt', 'buytap_track_attempt');
add_action('wp_ajax_nopriv_buytap_track_attempt', 'buytap_track_attempt');

function buytap_track_attempt() {
    // Get today's key (e.g., 2025-10-28)
    $today = gmdate('Y-m-d');

    // Get current daily data array
    $data = get_option('buytap_daily_attempts', []);
    if (!is_array($data)) $data = [];

    // Increment today's counter
    $data[$today] = isset($data[$today]) ? $data[$today] + 1 : 1;

    // Save back
    update_option('buytap_daily_attempts', $data);

    // Optionally track per user (still works)
    if (is_user_logged_in()) {
        $uid = get_current_user_id();
        $user_attempts = (int) get_user_meta($uid, 'buytap_attempts', true);
        update_user_meta($uid, 'buytap_attempts', $user_attempts + 1);
    }

    wp_send_json_success(['today' => $data[$today]]);
}


// 🔹 2. Admin Dashboard Summary (Attempts vs Success + History)
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;

    $screen = get_current_screen();
    if (empty($screen) || $screen->post_type !== 'buytap_order') return;

    // Get today's date and stored data
    $today = gmdate('Y-m-d');
    $data = get_option('buytap_daily_attempts', []);
    if (!is_array($data)) $data = [];

    $attempts_today = isset($data[$today]) ? (int)$data[$today] : 0;

    // Successful Orders created today
    $args = [
        'post_type'      => 'buytap_order',
        'post_status'    => 'publish',
        'date_query'     => [
            [
                'after'     => $today . ' 00:00:00',
                'before'    => $today . ' 23:59:59',
                'inclusive' => true,
            ],
        ],
        'fields' => 'ids',
    ];
    $query = new WP_Query($args);
    $success_today = $query->found_posts;
    $failed_today = max(0, $attempts_today - $success_today);

    // --- Build summary HTML
    echo '<div class="notice notice-info" style="padding:12px 18px; margin-top:10px; font-size:15px;">';
    echo '<strong>📅 Today (' . esc_html($today) . ')</strong><br>';
    echo '<strong>🟢 Successful Orders:</strong> ' . esc_html($success_today) . ' &nbsp;&nbsp;';
    echo '<strong>🔵 Total Attempts:</strong> ' . esc_html($attempts_today) . ' &nbsp;&nbsp;';
    echo '<strong>🔴 Unsuccessful Attempts:</strong> ' . esc_html($failed_today) . '<br><br>';

    // --- Optional: History Table (last 7 days)
    if (!empty($data)) {
        echo '<details style="margin-top:6px;"><summary style="cursor:pointer;font-weight:600;">📊 View Previous Days</summary>';
        echo '<table style="margin-top:10px;border-collapse:collapse;width:100%;font-size:14px;">';
        echo '<tr style="background:#f2f2f2;"><th style="text-align:left;padding:6px;">Date</th><th style="text-align:center;">Attempts</th><th style="text-align:center;">Success</th><th style="text-align:center;">Failed</th></tr>';

        // Sort newest first
        krsort($data);

        $count = 0;
        foreach ($data as $date => $attempts) {
            if ($count >= 7) break; // show only last 7 days

            // Count successes for that date
            $q = new WP_Query([
                'post_type' => 'buytap_order',
                'post_status' => 'publish',
                'date_query' => [
                    [
                        'after' => $date . ' 00:00:00',
                        'before' => $date . ' 23:59:59',
                        'inclusive' => true,
                    ],
                ],
                'fields' => 'ids',
            ]);
            $success = $q->found_posts;
            $failed = max(0, $attempts - $success);

            echo '<tr>
                <td style="padding:4px 6px;">' . esc_html($date) . '</td>
                <td style="text-align:center;">' . esc_html($attempts) . '</td>
                <td style="text-align:center;color:green;">' . esc_html($success) . '</td>
                <td style="text-align:center;color:red;">' . esc_html($failed) . '</td>
            </tr>';

            $count++;
        }
        echo '</table>';
        echo '</details>';
    }

    echo '</div>';
});



// 🔹 3. Front-end JavaScript tracker (counts clicks on “Buy Shares”)
add_action('wp_footer', function() {
    ?>
    <script>
    document.addEventListener('click', function(e) {
      const btn = e.target.closest('button, input[type="submit"]');
      if (!btn) return;
      if (btn.textContent.trim().match(/buy\s*shares/i)) {
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body: new URLSearchParams({action:'buytap_track_attempt'})
        });
      }
    });
    </script>
    <?php
});
