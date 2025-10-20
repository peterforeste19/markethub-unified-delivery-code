<?php
/**
 * Plugin Name: MarketHub Unified Delivery & Access Control
 * Description: Roles, signup/login control, driver app shortcode with Google Maps, and REST API integrating WooCommerce orders and checkout GPS data.
 * Version: 1.0.0
 * Author: MarketHub TT
 */

if (!defined('ABSPATH')) { exit; }

// =============================
// SECTION 0: CONSTANTS/HELPERS
// =============================

define('MHU_PLUGIN_VERSION', '1.0.0');

define('MHU_SECURE_POD_DIR', wp_upload_dir()['basedir'] . '/markethub_pod');
define('MHU_TOKEN_META', '_mhu_driver_token_hash');
define('MHU_TOKEN_EXP_META', '_mhu_driver_token_exp');
define('MHU_EMP_TOKEN_META', '_mhu_employee_token_hash');
define('MHU_EMP_TOKEN_EXP_META', '_mhu_employee_token_exp');

function mhu_now_mysql() {
    return current_time('mysql');
}

function mhu_require_wc() {
    return function_exists('WC');
}

// ======================================
// SECTION 1: ROLES & ORDER STATUS SETUP
// ======================================

function mhu_register_roles() {
    // Plural roles
    add_role('markethub_drivers', 'MarketHub Drivers', [ 'read' => true ]);
    add_role('markethub_employees', 'MarketHub Employees', [ 'read' => true ]);
    // Pending role with minimal capabilities
    add_role('markethub_pending', 'MarketHub Pending', [ 'read' => true ]);
}
add_action('init', 'mhu_register_roles');

function mhu_remove_legacy_roles() {
    // Remove old singular driver role if present
    if (get_role('markethub_driver')) {
        remove_role('markethub_driver');
    }
}

function mhu_register_custom_statuses() {
    register_post_status('wc-driver-claimed', [
        'label' => 'Driver Claimed',
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop('Driver Claimed <span class="count">(%s)</span>', 'Driver Claimed <span class="count">(%s)</span>')
    ]);

    register_post_status('wc-out-for-delivery', [
        'label' => 'Out for Delivery',
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop('Out for Delivery <span class="count">(%s)</span>', 'Out for Delivery <span class="count">(%s)</span>')
    ]);
}
add_action('init', 'mhu_register_custom_statuses');

function mhu_add_statuses_to_wc($statuses) {
    $statuses['wc-driver-claimed'] = 'Driver Claimed';
    $statuses['wc-out-for-delivery'] = 'Out for Delivery';
    return $statuses;
}
add_filter('wc_order_statuses', 'mhu_add_statuses_to_wc');

register_activation_hook(__FILE__, function() {
    mhu_register_roles();
    mhu_remove_legacy_roles();
    mhu_register_custom_statuses();

    // Ensure secure POD directory exists
    if (!file_exists(MHU_SECURE_POD_DIR)) {
        wp_mkdir_p(MHU_SECURE_POD_DIR);
    }
    // Harden directory with .htaccess deny
    $htaccess = MHU_SECURE_POD_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }
});

// =====================================================
// SECTION 2: REGISTRATION FIELD + PENDING ROLE ASSIGN
// =====================================================

// Add applicant type field to WC registration
add_action('woocommerce_register_form', function() {
    echo '<p class="form-row form-row-wide">'
        .'<label for="mh_applicant_type">Registering as</label>'
        .'<select name="mh_applicant_type" id="mh_applicant_type" required>'
        .'<option value="">Select...</option>'
        .'<option value="driver">Driver</option>'
        .'<option value="employee">Employee</option>'
        .'</select>'
        .'</p>';
});

// Validate field
add_action('woocommerce_register_post', function($username, $email, $validation_errors) {
    if (empty($_POST['mh_applicant_type']) || !in_array($_POST['mh_applicant_type'], ['driver','employee'], true)) {
        $validation_errors->add('mh_applicant_type_error', __('Please choose Driver or Employee.', 'markethub-unified'));
    }
}, 10, 3);

// Force pending role and save applicant type on customer creation
add_action('woocommerce_created_customer', function($customer_id) {
    $user = get_user_by('id', $customer_id);
    if (!$user) { return; }

    // Save applicant type
    $type = isset($_POST['mh_applicant_type']) ? sanitize_text_field($_POST['mh_applicant_type']) : '';
    if ($type) {
        update_user_meta($customer_id, 'mh_applicant_type', $type);
    }

    // Assign pending role immediately
    $user->set_role('markethub_pending');
});

// Auto-activate driver role on first login if pending+driver
add_action('wp_login', function($user_login, $user) {
    if (!$user instanceof WP_User) { return; }
    if (in_array('markethub_pending', (array) $user->roles, true)) {
        $type = get_user_meta($user->ID, 'mh_applicant_type', true);
        if ($type === 'driver') {
            $user->set_role('markethub_drivers');
            delete_user_meta($user->ID, 'mh_applicant_type');
        }
    }
}, 10, 2);

// ========================================
// SECTION 3: ADMIN - MANAGEMENT + MIGRATION
// ========================================

add_action('admin_menu', function() {
    add_menu_page(
        'MarketHub Management',
        'MarketHub Management',
        'manage_options',
        'markethub-management',
        'mhu_render_management_page',
        'dashicons-groups',
        57
    );
});

function mhu_render_management_page() {
    if (!current_user_can('manage_options')) { return; }

    // Handle migration trigger
    if (isset($_POST['mhu_run_employee_migration']) && check_admin_referer('mhu_employee_migration')) {
        $migrated = mhu_migrate_legacy_employees();
        echo '<div class="notice notice-success"><p>Migrated '.intval($migrated).' employees.</p></div>';
    }

    // List pending employee applicants
    $pending_employees = get_users([
        'role' => 'markethub_pending',
        'meta_key' => 'mh_applicant_type',
        'meta_value' => 'employee',
        'number' => 200,
        'fields' => ['ID','user_login','display_name','user_email']
    ]);

    echo '<div class="wrap">';
    echo '<h1>MarketHub Management</h1>';

    echo '<h2>Employee Migration</h2>';
    echo '<form method="post">';
    wp_nonce_field('mhu_employee_migration');
    echo '<p><button type="submit" name="mhu_run_employee_migration" class="button button-primary">Run Legacy Employee Migration</button></p>';
    echo '</form>';

    echo '<hr />';

    echo '<h2>Pending Employee Approvals</h2>';
    if (empty($pending_employees)) {
        echo '<p>No pending employee applicants.</p>';
    } else {
        echo '<table class="widefat"><thead><tr><th>User</th><th>Email</th><th>Action</th></tr></thead><tbody>';
        foreach ($pending_employees as $u) {
            $nonce = wp_create_nonce('mhu_approve_employee_'.$u->ID);
            echo '<tr>'
                .'<td>'.esc_html($u->display_name ?: $u->user_login).'</td>'
                .'<td>'.esc_html($u->user_email).'</td>'
                .'<td><button class="button approve-emp" data-user="'.intval($u->ID).'" data-nonce="'.$nonce.'">Approve</button></td>'
                .'</tr>';
        }
        echo '</tbody></table>';
        echo '<script>document.addEventListener("click",function(e){if(e.target && e.target.classList.contains("approve-emp")){const id=e.target.dataset.user;const nonce=e.target.dataset.nonce;e.target.disabled=true;fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:new URLSearchParams({action:"mhu_approve_employee",user_id:id,nonce:nonce})}).then(r=>r.json()).then(j=>{if(j.success){e.target.closest("tr").remove();}else{alert(j.data||"Error");e.target.disabled=false;}}).catch(()=>{alert("Error");e.target.disabled=false;});}});</script>';
    }

    echo '</div>';
}

add_action('wp_ajax_mhu_approve_employee', function() {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!$user_id || !wp_verify_nonce($nonce, 'mhu_approve_employee_'.$user_id)) {
        wp_send_json_error('Invalid request');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    $user = get_user_by('id', $user_id);
    if (!$user) { wp_send_json_error('User not found'); }

    $type = get_user_meta($user_id, 'mh_applicant_type', true);
    if ($type !== 'employee') { wp_send_json_error('Not an employee applicant'); }

    $user->set_role('markethub_employees');
    delete_user_meta($user_id, 'mh_applicant_type');

    wp_send_json_success();
});

function mhu_migrate_legacy_employees() {
    global $wpdb;
    $table = $wpdb->prefix . 'mh_employees';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return 0; // table not found
    }

    $rows = $wpdb->get_results("SELECT id, email, name FROM {$table} WHERE is_active = 1");
    $migrated = 0;
    foreach ($rows as $row) {
        $email = sanitize_email($row->email);
        if (!$email) { continue; }
        if (email_exists($email)) {
            $user_id = email_exists($email);
        } else {
            $login = sanitize_user(current(explode('@', $email)) . '_' . wp_generate_password(4, false));
            $pass = wp_generate_password(20, true);
            $user_id = wp_create_user($login, $pass, $email);
            if (is_wp_error($user_id)) { continue; }
            wp_update_user(['ID'=>$user_id, 'display_name'=>sanitize_text_field($row->name)]);
        }
        $user = get_user_by('id', $user_id);
        if ($user) {
            $user->set_role('markethub_employees');
            $migrated++;
        }
    }
    return $migrated;
}

// =====================================
// SECTION 4: DRIVER AUTH + REST ENDPOINTS
// =====================================

function mhu_generate_token_for_user($user_id) {
    $token = wp_generate_password(32, false);
    $hash = wp_hash_password($token);
    update_user_meta($user_id, MHU_TOKEN_META, $hash);
    update_user_meta($user_id, MHU_TOKEN_EXP_META, time() + 24*60*60);
    return $token;
}

function mhu_verify_bearer_token($request, $allowed_roles = []) {
    $auth = $request->get_header('authorization');
    if (!$auth || stripos($auth, 'Bearer ') !== 0) { return new WP_Error('unauthorized', 'Missing token', ['status'=>401]); }
    $token = trim(substr($auth, 7));

    $users = get_users(['role__in' => $allowed_roles, 'fields' => 'ID']);
    foreach ($users as $uid) {
        $hash = get_user_meta($uid, MHU_TOKEN_META, true);
        $exp = intval(get_user_meta($uid, MHU_TOKEN_EXP_META, true));
        if ($hash && wp_check_password($token, $hash) && time() < $exp) {
            $request->set_param('mhu_user_id', $uid);
            return true;
        }
    }
    return new WP_Error('unauthorized', 'Invalid or expired token', ['status'=>401]);
}

function mhu_get_user_from_request($request) {
    $uid = intval($request->get_param('mhu_user_id'));
    return $uid ? get_user_by('id', $uid) : null;
}

add_action('rest_api_init', function() {
    register_rest_route('markethub/v1', '/driver/login', [
        'methods' => 'POST',
        'callback' => function(WP_REST_Request $request) {
            $username = sanitize_text_field($request->get_param('username'));
            $password = (string) $request->get_param('password');
            $user = wp_authenticate($username, $password);
            if (is_wp_error($user)) {
                return new WP_REST_Response(['success'=>false,'message'=>'Invalid credentials'], 401);
            }
            if (in_array('markethub_pending', (array)$user->roles, true)) {
                return new WP_REST_Response(['success'=>false,'message'=>'Account pending approval'], 403);
            }
            if (!in_array('markethub_drivers', (array)$user->roles, true) && !in_array('administrator', (array)$user->roles, true)) {
                return new WP_REST_Response(['success'=>false,'message'=>'Insufficient permissions'], 403);
            }
            $token = mhu_generate_token_for_user($user->ID);
            return new WP_REST_Response(['success'=>true,'token'=>$token,'driver_name'=>$user->display_name,'user_id'=>$user->ID], 200);
        },
        'permission_callback' => '__return_true'
    ]);

    // List orders: pending (unclaimed) and claimed by current driver
    register_rest_route('markethub/v1', '/driver/orders', [
        'methods' => 'GET',
        'callback' => function(WP_REST_Request $request) {
            $auth = mhu_verify_bearer_token($request, ['markethub_drivers', 'administrator']);
            if (is_wp_error($auth)) { return $auth; }
            if (!mhu_require_wc()) { return new WP_Error('no_wc', 'WooCommerce not available', ['status'=>500]); }
            $driver = mhu_get_user_from_request($request);

            // Pending: processing or on-hold orders with no driver assigned
            $pending_orders = wc_get_orders([
                'status' => ['processing','on-hold'],
                'limit' => 20,
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => [
                    [ 'key' => '_mh_driver_id', 'compare' => 'NOT EXISTS' ]
                ]
            ]);

            $claimed_orders = wc_get_orders([
                'status' => ['driver-claimed','out-for-delivery'],
                'limit' => 20,
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => [
                    [ 'key' => '_mh_driver_id', 'value' => (string) $driver->ID ]
                ]
            ]);

            $normalize = function($order) {
                $items = [];
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    $items[] = [
                        'id' => $item->get_id(),
                        'name' => $item->get_name(),
                        'price' => (float) $item->get_total(),
                        'quantity' => (int) $item->get_quantity(),
                        'image' => $product ? wp_get_attachment_url($product->get_image_id()) : ''
                    ];
                }
                $coords = mhu_get_order_coords($order);
                return [
                    'id' => $order->get_id(),
                    'customer_name' => $order->get_billing_first_name().' '.$order->get_billing_last_name(),
                    'delivery_address' => trim($order->get_billing_address_1().' '.$order->get_billing_city()),
                    'total' => (float) $order->get_total(),
                    'item_count' => count($items),
                    'items' => $items,
                    'customer_lat' => $coords['customer_lat'],
                    'customer_lng' => $coords['customer_lng'],
                    'store_lat' => $coords['store_lat'],
                    'store_lng' => $coords['store_lng'],
                    'store_name' => $coords['store_name'],
                    'status' => $order->get_status()
                ];
            };

            return new WP_REST_Response([
                'success' => true,
                'pending' => array_map($normalize, $pending_orders),
                'claimed' => array_map($normalize, $claimed_orders)
            ], 200);
        },
        'permission_callback' => '__return_true'
    ]);

    // Claim order (race-checked)
    register_rest_route('markethub/v1', '/driver/claim', [
        'methods' => 'POST',
        'callback' => function(WP_REST_Request $request) {
            $auth = mhu_verify_bearer_token($request, ['markethub_drivers', 'administrator']);
            if (is_wp_error($auth)) { return $auth; }
            if (!mhu_require_wc()) { return new WP_Error('no_wc', 'WooCommerce not available', ['status'=>500]); }
            $driver = mhu_get_user_from_request($request);
            $order_id = intval($request->get_param('order_id'));
            $order = wc_get_order($order_id);
            if (!$order) { return new WP_REST_Response(['success'=>false,'message'=>'Order not found'], 404); }

            // Race condition check
            $existing_driver = $order->get_meta('_mh_driver_id');
            $allowed_statuses = ['processing','on-hold','pending'];
            if ($existing_driver || !in_array($order->get_status(), $allowed_statuses, true)) {
                return new WP_REST_Response(['success'=>false,'message'=>'Order unavailable'], 409);
            }

            $order->update_meta_data('_mh_driver_id', $driver->ID);
            $order->update_meta_data('_mh_driver_name', $driver->display_name);
            $order->update_meta_data('_mh_claimed_time', mhu_now_mysql());
            $order->set_status('driver-claimed');
            $order->save();

            return new WP_REST_Response(['success'=>true], 200);
        },
        'permission_callback' => '__return_true'
    ]);

    // Start fulfillment (optional marker)
    register_rest_route('markethub/v1', '/driver/start', [
        'methods' => 'POST',
        'callback' => function(WP_REST_Request $request) {
            $auth = mhu_verify_bearer_token($request, ['markethub_drivers', 'administrator']);
            if (is_wp_error($auth)) { return $auth; }
            $driver = mhu_get_user_from_request($request);
            $order_id = intval($request->get_param('order_id'));
            $order = wc_get_order($order_id);
            if (!$order) { return new WP_REST_Response(['success'=>false,'message'=>'Order not found'], 404); }
            if ((int)$order->get_meta('_mh_driver_id') !== (int)$driver->ID) { return new WP_REST_Response(['success'=>false,'message'=>'Unauthorized'], 403); }
            $order->update_meta_data('_mh_fulfillment_start', mhu_now_mysql());
            $order->save();
            return new WP_REST_Response(['success'=>true], 200);
        },
        'permission_callback' => '__return_true'
    ]);

    // Pay (after collection) -> set out-for-delivery and start delivery timer
    register_rest_route('markethub/v1', '/driver/pay', [
        'methods' => 'POST',
        'callback' => function(WP_REST_Request $request) {
            $auth = mhu_verify_bearer_token($request, ['markethub_drivers', 'administrator']);
            if (is_wp_error($auth)) { return $auth; }
            $driver = mhu_get_user_from_request($request);
            $order_id = intval($request->get_param('order_id'));
            $order = wc_get_order($order_id);
            if (!$order) { return new WP_REST_Response(['success'=>false,'message'=>'Order not found'], 404); }
            if ((int)$order->get_meta('_mh_driver_id') !== (int)$driver->ID) { return new WP_REST_Response(['success'=>false,'message'=>'Unauthorized'], 403); }
            $order->update_meta_data('_mh_pay_time', mhu_now_mysql());
            $order->update_meta_data('_mh_delivery_start', time());
            $order->set_status('out-for-delivery');
            $order->save();
            return new WP_REST_Response(['success'=>true], 200);
        },
        'permission_callback' => '__return_true'
    ]);

    // Complete order with POD (ID front/back + signature)
    register_rest_route('markethub/v1', '/driver/complete', [
        'methods' => 'POST',
        'callback' => function(WP_REST_Request $request) {
            $auth = mhu_verify_bearer_token($request, ['markethub_drivers', 'administrator']);
            if (is_wp_error($auth)) { return $auth; }
            $driver = mhu_get_user_from_request($request);
            $order_id = intval($request->get_param('order_id'));
            $id_type = sanitize_text_field($request->get_param('id_type'));
            $id_front = (string) $request->get_param('id_front');
            $id_back = (string) $request->get_param('id_back');
            $signature = (string) $request->get_param('signature');
            if (!$order_id || !$id_type || !$id_front || !$id_back || !$signature) {
                return new WP_REST_Response(['success'=>false,'message'=>'Missing data'], 400);
            }
            $order = wc_get_order($order_id);
            if (!$order) { return new WP_REST_Response(['success'=>false,'message'=>'Order not found'], 404); }
            if ((int)$order->get_meta('_mh_driver_id') !== (int)$driver->ID) { return new WP_REST_Response(['success'=>false,'message'=>'Unauthorized'], 403); }

            // Ensure dir
            if (!file_exists(MHU_SECURE_POD_DIR)) { wp_mkdir_p(MHU_SECURE_POD_DIR); }
            $base = 'order_'.$order_id.'_'.time();
            $save_image = function($data_url, $suffix) use ($base) {
                $bin = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $data_url));
                $path = MHU_SECURE_POD_DIR . "/{$base}_{$suffix}.jpg";
                file_put_contents($path, $bin);
                return $path;
            };
            $front_path = $save_image($id_front, 'id_front');
            $back_path  = $save_image($id_back, 'id_back');
            $sig_path   = $save_image($signature, 'signature');

            $start_ts = intval($order->get_meta('_mh_delivery_start')) ?: time();
            $end_ts = time();
            $duration_sec = max(0, $end_ts - $start_ts);

            $order->update_meta_data('_mh_id_type', $id_type);
            $order->update_meta_data('_mh_id_front_path', $front_path);
            $order->update_meta_data('_mh_id_back_path', $back_path);
            $order->update_meta_data('_mh_signature_path', $sig_path);
            $order->update_meta_data('_mh_delivery_end', $end_ts);
            $order->update_meta_data('_mh_delivery_duration_sec', $duration_sec);
            $order->set_status('completed');
            $order->save();

            return new WP_REST_Response(['success'=>true], 200);
        },
        'permission_callback' => '__return_true'
    ]);

    // ================================
    // Employee endpoints (WP users)
    // ================================

    // Employee login using WP auth, role check
    register_rest_route('markethub/v1', '/employee/login', [
        'methods' => 'POST',
        'callback' => function(WP_REST_Request $request) {
            $email = sanitize_email($request->get_param('email'));
            $username = sanitize_user($request->get_param('username'));
            $password = (string) $request->get_param('password');

            $login = '';
            if ($email) {
                $u = get_user_by('email', $email);
                if ($u) { $login = $u->user_login; }
            } elseif ($username) {
                $login = $username;
            }
            if (!$login || !$password) {
                return new WP_REST_Response(['success'=>false,'message'=>'Missing credentials'], 400);
            }

            $user = wp_authenticate($login, $password);
            if (is_wp_error($user)) {
                return new WP_REST_Response(['success'=>false,'message'=>'Invalid credentials'], 401);
            }
            if (in_array('markethub_pending', (array) $user->roles, true)) {
                return new WP_REST_Response(['success'=>false,'message'=>'Account pending approval'], 403);
            }
            if (!in_array('markethub_employees', (array) $user->roles, true) && !in_array('administrator', (array) $user->roles, true)) {
                return new WP_REST_Response(['success'=>false,'message'=>'Insufficient permissions'], 403);
            }

            // Issue employee token
            $token = wp_generate_password(32, false);
            $hash = wp_hash_password($token);
            update_user_meta($user->ID, MHU_EMP_TOKEN_META, $hash);
            update_user_meta($user->ID, MHU_EMP_TOKEN_EXP_META, time() + 24*60*60);

            return new WP_REST_Response(['success'=>true,'token'=>$token,'employee_name'=>$user->display_name,'user_id'=>$user->ID], 200);
        },
        'permission_callback' => '__return_true'
    ]);

    // Verify employee token helper
    $verify_employee = function(WP_REST_Request $request) {
        $auth = $request->get_header('authorization');
        if (!$auth || stripos($auth, 'Bearer ') !== 0) { return new WP_Error('unauthorized', 'Missing token', ['status'=>401]); }
        $token = trim(substr($auth, 7));
        $users = get_users(['role__in' => ['markethub_employees','administrator'], 'fields' => 'ID']);
        foreach ($users as $uid) {
            $hash = get_user_meta($uid, MHU_EMP_TOKEN_META, true);
            $exp = intval(get_user_meta($uid, MHU_EMP_TOKEN_EXP_META, true));
            if ($hash && wp_check_password($token, $hash) && time() < $exp) {
                $request->set_param('mhu_employee_user_id', $uid);
                return true;
            }
        }
        return new WP_Error('unauthorized', 'Invalid or expired token', ['status'=>401]);
    };

    $get_employee = function(WP_REST_Request $request) {
        $uid = intval($request->get_param('mhu_employee_user_id'));
        return $uid ? get_user_by('id', $uid) : null;
    };

    // Pending orders for employee review (bank transfer, cheque, COD)
    register_rest_route('markethub/v1', '/employee/pending-orders', [
        'methods' => 'GET',
        'callback' => function(WP_REST_Request $request) use ($verify_employee) {
            $auth = $verify_employee($request);
            if (is_wp_error($auth)) { return $auth; }
            if (!mhu_require_wc()) { return new WP_Error('no_wc', 'WooCommerce not available', ['status'=>500]); }

            $orders = wc_get_orders([
                'status' => ['pending','on-hold'],
                'limit' => 50,
                'orderby' => 'date',
                'order' => 'DESC'
            ]);
            $allowed_methods = ['bacs','cheque','cod'];
            $list = [];
            foreach ($orders as $order) {
                if (!in_array($order->get_payment_method(), $allowed_methods, true)) { continue; }
                $items = [];
                foreach ($order->get_items() as $item) {
                    $items[] = [
                        'name' => $item->get_name(),
                        'quantity' => (int) $item->get_quantity(),
                        'total' => (float) $item->get_total(),
                    ];
                }
                $list[] = [
                    'id' => $order->get_id(),
                    'customer_name' => $order->get_billing_first_name().' '.$order->get_billing_last_name(),
                    'customer_email' => $order->get_billing_email(),
                    'customer_phone' => $order->get_billing_phone(),
                    'delivery_address' => trim($order->get_billing_address_1().' '.$order->get_billing_city()),
                    'total' => (float) $order->get_total(),
                    'item_count' => count($items),
                    'items' => $items,
                    'payment_method' => $order->get_payment_method_title(),
                    'status' => wc_get_order_status_name($order->get_status()),
                    'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
                    'transaction_id' => $order->get_transaction_id(),
                    'customer_note' => $order->get_customer_note(),
                ];
            }
            return new WP_REST_Response(['success'=>true,'orders'=>$list], 200);
        },
        'permission_callback' => '__return_true'
    ]);

    // Confirm or reject order
    register_rest_route('markethub/v1', '/employee/confirm-order', [
        'methods' => 'POST',
        'callback' => function(WP_REST_Request $request) use ($verify_employee, $get_employee) {
            $auth = $verify_employee($request);
            if (is_wp_error($auth)) { return $auth; }
            if (!mhu_require_wc()) { return new WP_Error('no_wc', 'WooCommerce not available', ['status'=>500]); }
            $employee = $get_employee($request);

            $order_id = intval($request->get_param('order_id'));
            $action = sanitize_text_field($request->get_param('action'));
            if (!$order_id || !in_array($action, ['approve','reject'], true)) {
                return new WP_REST_Response(['success'=>false,'message'=>'Invalid request'], 400);
            }
            $order = wc_get_order($order_id);
            if (!$order) { return new WP_REST_Response(['success'=>false,'message'=>'Order not found'], 404); }

            if ($action === 'approve') {
                $order->set_status('processing');
                $order->update_meta_data('_mh_approved_by', $employee ? $employee->display_name : 'Employee');
                $order->update_meta_data('_mh_approved_at', mhu_now_mysql());
                $order->add_order_note(sprintf('Payment confirmed by %s. Order ready for driver assignment.', $employee ? $employee->display_name : 'employee'));
            } else {
                $order->set_status('cancelled');
                $order->update_meta_data('_mh_rejected_by', $employee ? $employee->display_name : 'Employee');
                $order->update_meta_data('_mh_rejected_at', mhu_now_mysql());
                $order->add_order_note(sprintf('Order rejected by %s. Payment not confirmed.', $employee ? $employee->display_name : 'employee'));
            }
            $order->save();
            return new WP_REST_Response(['success'=>true,'message'=> $action === 'approve' ? 'Order approved' : 'Order rejected'], 200);
        },
        'permission_callback' => '__return_true'
    ]);
});

// Helper: derive store coords from MarketHub checkout/order meta and options
function mhu_get_order_coords($order) {
    $customer_lat = (float) $order->get_meta('_markethub_customer_lat');
    $customer_lng = (float) $order->get_meta('_markethub_customer_lng');

    $store_name = '';
    $store_lat = 0.0;
    $store_lng = 0.0;

    // Grocery
    $g_key = $order->get_meta('_markethub_grocery_store');
    if ($g_key) {
        $stores = get_option('markethub_grocery_stores', []);
        if (isset($stores[$g_key])) {
            $store_name = $stores[$g_key]['name'];
            $store_lat = (float) $stores[$g_key]['lat'];
            $store_lng = (float) $stores[$g_key]['lng'];
        }
    }

    // Food
    if (!$store_lat || !$store_lng) {
        $f_key = $order->get_meta('_markethub_food_store'); // format storeKey_idx
        if ($f_key) {
            $food = get_option('markethub_food_stores', []);
            if (strpos($f_key, '_') !== false) {
                list($sk, $idx) = explode('_', $f_key, 2);
                if (isset($food[$sk]['locations'][$idx])) {
                    $loc = $food[$sk]['locations'][$idx];
                    $store_name = $food[$sk]['name'].' - '.$loc['address'];
                    $store_lat = (float) $loc['lat'];
                    $store_lng = (float) $loc['lng'];
                }
            }
        }
    }

    // Generic store
    if (!$store_lat || !$store_lng) {
        $s_key = $order->get_meta('_markethub_generic_store');
        if ($s_key) {
            $generics = get_option('markethub_generic_stores', []);
            if (strpos($s_key, '_') !== false) {
                list($sk, $idx) = explode('_', $s_key, 2);
                if (isset($generics[$sk]['locations'][$idx])) {
                    $loc = $generics[$sk]['locations'][$idx];
                    $store_name = $generics[$sk]['name'].' - '.$loc['address'];
                    $store_lat = (float) $loc['lat'];
                    $store_lng = (float) $loc['lng'];
                }
            }
        }
    }

    return [
        'customer_lat' => $customer_lat,
        'customer_lng' => $customer_lng,
        'store_lat' => $store_lat,
        'store_lng' => $store_lng,
        'store_name' => $store_name,
    ];
}

// ==============================================
// SECTION 5: ADMIN ORDER VIEW - DRIVER/POD META
// ==============================================

add_action('woocommerce_admin_order_data_after_order_details', function($order) {
    if (!$order instanceof WC_Order) { return; }
    $driver_id = $order->get_meta('_mh_driver_id');
    $driver_name = $order->get_meta('_mh_driver_name');
    $claimed = $order->get_meta('_mh_claimed_time');
    $pay_time = $order->get_meta('_mh_pay_time');
    $del_start = $order->get_meta('_mh_delivery_start');
    $del_end = $order->get_meta('_mh_delivery_end');
    $dur = $order->get_meta('_mh_delivery_duration_sec');
    $id_type = $order->get_meta('_mh_id_type');
    $front = $order->get_meta('_mh_id_front_path');
    $back = $order->get_meta('_mh_id_back_path');
    $sig = $order->get_meta('_mh_signature_path');

    echo '<div class="order_data_column">';
    echo '<h3>MarketHub Delivery</h3>';
    if ($driver_name) {
        echo '<p><strong>Driver:</strong> '.esc_html($driver_name).' (ID '.intval($driver_id).')</p>';
    }
    if ($claimed) {
        echo '<p><strong>Claimed:</strong> '.esc_html($claimed).'</p>';
    }
    if ($pay_time) {
        echo '<p><strong>Paid/Out-for-delivery:</strong> '.esc_html($pay_time).'</p>';
    }
    if ($del_start) {
        echo '<p><strong>Delivery start:</strong> '.esc_html(is_numeric($del_start)? date('Y-m-d H:i:s', intval($del_start)) : $del_start).'</p>';
    }
    if ($del_end) {
        echo '<p><strong>Delivery end:</strong> '.esc_html(date('Y-m-d H:i:s', intval($del_end))).'</p>';
    }
    if ($dur) {
        echo '<p><strong>Duration:</strong> '.esc_html(gmdate('H:i:s', intval($dur))).'</p>';
    }
    if ($id_type) {
        echo '<p><strong>ID Type:</strong> '.esc_html(strtoupper($id_type)).'</p>';
    }

    $render_link = function($label, $path) {
        if (!$path || !file_exists($path)) { return; }
        $url = wp_nonce_url(admin_url('admin-ajax.php?action=mhu_view_pod&path='.rawurlencode($path)), 'mhu_view_pod_'.$path);
        echo '<p><a class="button" href="'.esc_url($url).'" target="_blank">'.esc_html($label).'</a></p>';
    };

    $render_link('View ID (Front)', $front);
    $render_link('View ID (Back)', $back);
    $render_link('View Signature', $sig);

    echo '</div>';
});

// Secure viewer for POD files (admins and employees)
add_action('wp_ajax_mhu_view_pod', function() {
    $path = isset($_GET['path']) ? wp_normalize_path($_GET['path']) : '';
    if (!$path || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'mhu_view_pod_'.$path)) {
        wp_die('Invalid request');
    }
    if (!current_user_can('manage_woocommerce') && !current_user_can('administrator') && !in_array('markethub_employees', (array) wp_get_current_user()->roles, true)) {
        wp_die('Unauthorized');
    }
    if (strpos($path, wp_normalize_path(MHU_SECURE_POD_DIR)) !== 0 || !file_exists($path)) {
        wp_die('Not found');
    }
    $mime = 'image/jpeg';
    header('Content-Type: '.$mime);
    readfile($path);
    exit;
});

// =============================================
// SECTION 6: SHORTCODE - REACT DRIVER APP + MAP
// =============================================

add_shortcode('markethub_driver_app', function() {
    ob_start();
    $api_base = esc_url_raw( rest_url('markethub/v1') );
    $google_key = 'AIzaSyBERt1jXPqYasS3pPVOUUF9oh8QfCQ8_TA';
    ?>
<div id="mhu-driver-app-root"></div>
<script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
<script src="https://cdn.tailwindcss.com"></script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc_attr($google_key); ?>&libraries=places"></script>
<script type="text/babel">
const { useState, useEffect, useRef } = React;
const API_BASE = <?php echo json_encode($api_base); ?>;

function useGoogleMapsLoaded() {
  const [loaded, setLoaded] = useState(!!window.google);
  useEffect(() => {
    if (window.google) { setLoaded(true); return; }
    const id = setInterval(()=>{ if (window.google) { setLoaded(true); clearInterval(id);} }, 300);
    return () => clearInterval(id);
  }, []);
  return loaded;
}

function MapRoute({ customer, store }) {
  const mapRef = useRef(null);
  const gLoaded = useGoogleMapsLoaded();
  useEffect(() => {
    if (!gLoaded || !customer || !store) return;
    const center = { lat: customer.lat, lng: customer.lng };
    const map = new google.maps.Map(mapRef.current, { center, zoom: 12 });
    const cust = new google.maps.Marker({ position: customer, map, label: 'C' });
    const stor = new google.maps.Marker({ position: store, map, label: 'S' });
    const dirSvc = new google.maps.DirectionsService();
    const dirDisp = new google.maps.DirectionsRenderer({ map });
    dirSvc.route({ origin: store, destination: customer, travelMode: google.maps.TravelMode.DRIVING }, (res, status) => {
      if (status === 'OK') dirDisp.setDirections(res);
    });
  }, [gLoaded, customer, store]);
  return (
    <div className="w-full" style={{height: '100vh'}} ref={mapRef} />
  );
}

function Collapsible({ title, children, right }) {
  const [open, setOpen] = useState(false);
  return (
    <div className="bg-white rounded-lg shadow">
      <button className="w-full flex justify-between items-center p-4" onClick={() => setOpen(o=>!o)}>
        <div className="text-left">
          <div className="font-semibold">{title}</div>
        </div>
        <div>{right}</div>
      </button>
      {open && <div className="border-t p-4">{children}</div>}
    </div>
  );
}

function App() {
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [driverName, setDriverName] = useState('');
  const [token, setToken] = useState('');
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [loginError, setLoginError] = useState('');
  const [loading, setLoading] = useState(false);

  const [pendingOrders, setPendingOrders] = useState([]);
  const [claimedOrders, setClaimedOrders] = useState([]);

  const [activeOrder, setActiveOrder] = useState(null);
  const [deliveryStarted, setDeliveryStarted] = useState(false);
  const [idType, setIdType] = useState('id');
  const [idFront, setIdFront] = useState('');
  const [idBack, setIdBack] = useState('');
  const [signature, setSignature] = useState('');
  const sigCanvasRef = useRef(null);
  const [drawing, setDrawing] = useState(false);

  const compressImage = (file, maxW=1280, quality=0.7) => new Promise((resolve, reject) => {
    const img = new Image();
    const reader = new FileReader();
    reader.onload = e => { img.src = e.target.result; };
    img.onload = () => {
      const scale = Math.min(1, maxW / img.width);
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(img.width * scale);
      canvas.height = Math.round(img.height * scale);
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      resolve(canvas.toDataURL('image/jpeg', quality));
    };
    img.onerror = reject;
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });

  const drawStart = (e) => {
    setDrawing(true);
    const canvas = sigCanvasRef.current; const ctx = canvas.getContext('2d');
    const rect = canvas.getBoundingClientRect();
    const x = (e.touches?e.touches[0].clientX:e.clientX) - rect.left;
    const y = (e.touches?e.touches[0].clientY:e.clientY) - rect.top;
    ctx.beginPath(); ctx.moveTo(x, y);
  };
  const drawMove = (e) => {
    if (!drawing) return; const canvas = sigCanvasRef.current; const ctx = canvas.getContext('2d');
    const rect = canvas.getBoundingClientRect();
    const x = (e.touches?e.touches[0].clientX:e.clientX) - rect.left;
    const y = (e.touches?e.touches[0].clientY:e.clientY) - rect.top;
    ctx.lineTo(x, y); ctx.stroke();
  };
  const drawEnd = () => { setDrawing(false); if (sigCanvasRef.current) setSignature(sigCanvasRef.current.toDataURL('image/jpeg', 0.8)); };
  const clearSignature = () => { const c=sigCanvasRef.current; c.getContext('2d').clearRect(0,0,c.width,c.height); setSignature(''); };

  const handleLogin = async () => {
    setLoading(true); setLoginError('');
    try {
      const res = await fetch(`${API_BASE}driver/login`, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ username, password }) });
      const data = await res.json();
      if (data.success) { setToken(data.token); setDriverName(data.driver_name); setIsLoggedIn(true); await loadOrders(data.token); }
      else { setLoginError(data.message || 'Login failed'); }
    } catch(e){ setLoginError('Network error'); }
    setLoading(false);
  };

  const loadOrders = async (tok = token) => {
    const res = await fetch(`${API_BASE}driver/orders`, { headers: { Authorization: `Bearer ${tok}` } });
    const data = await res.json();
    if (data.success) { setPendingOrders(data.pending || []); setClaimedOrders(data.claimed || []); }
  };

  const claimOrder = async (orderId) => {
    setLoading(true);
    try {
      const res = await fetch(`${API_BASE}driver/claim`, { method:'POST', headers: { 'Content-Type':'application/json', Authorization: `Bearer ${token}` }, body: JSON.stringify({ order_id: orderId }) });
      const data = await res.json();
      if (data.success) { await loadOrders(); }
      else { alert(data.message || 'Order unavailable'); await loadOrders(); }
    } finally { setLoading(false); }
  };

  const startFulfillment = async (order) => {
    await fetch(`${API_BASE}driver/start`, { method:'POST', headers: { 'Content-Type':'application/json', Authorization: `Bearer ${token}` }, body: JSON.stringify({ order_id: order.id }) });
    setActiveOrder(order);
  };

  const markPaid = async () => {
    if (!activeOrder) return;
    setLoading(true);
    try {
      const res = await fetch(`${API_BASE}driver/pay`, { method:'POST', headers:{ 'Content-Type':'application/json', Authorization: `Bearer ${token}` }, body: JSON.stringify({ order_id: activeOrder.id }) });
      const data = await res.json(); if (data.success){ setDeliveryStarted(true); }
    } finally { setLoading(false); }
  };

  const completeOrder = async () => {
    if (!activeOrder || !signature || !idFront || !idBack) { alert('Capture ID front/back and signature'); return; }
    setLoading(true);
    try {
      const res = await fetch(`${API_BASE}driver/complete`, { method:'POST', headers:{ 'Content-Type':'application/json', Authorization: `Bearer ${token}` }, body: JSON.stringify({ order_id: activeOrder.id, id_type: idType, id_front: idFront, id_back: idBack, signature }) });
      const data = await res.json(); if (data.success) { alert('Order completed'); setActiveOrder(null); setDeliveryStarted(false); setSignature(''); setIdFront(''); setIdBack(''); await loadOrders(); }
    } finally { setLoading(false); }
  };

  if (!isLoggedIn) {
    return (
      <div className="min-h-screen bg-gradient-to-br from-blue-600 to-blue-800 flex items-center justify-center p-4">
        <div className="bg-white rounded-lg shadow-2xl p-8 w-full max-w-md">
          <div className="text-center mb-6">
            <h1 className="text-3xl font-bold text-gray-800">MarketHub Driver</h1>
            <p className="text-gray-600 mt-2">Login to start deliveries</p>
          </div>
          <div className="space-y-4">
            <input className="w-full px-4 py-3 border rounded" placeholder="Username" value={username} onChange={e=>setUsername(e.target.value)} />
            <input type="password" className="w-full px-4 py-3 border rounded" placeholder="Password" value={password} onChange={e=>setPassword(e.target.value)} />
            {loginError && <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">{loginError}</div>}
            <button onClick={handleLogin} disabled={loading} className="w-full bg-blue-600 text-white py-3 rounded font-semibold">{loading? 'Logging in...' : 'Login'}</button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <div className="bg-blue-600 text-white p-4 shadow">
        <div className="max-w-6xl mx-auto flex justify-between items-center">
          <div>
            <div className="font-semibold">{driverName}</div>
            <div className="text-xs text-blue-100">MarketHub Driver</div>
          </div>
          <div className="flex gap-2">
            <button onClick={()=>{setIsLoggedIn(false); setToken('');}} className="bg-blue-700 px-3 py-2 rounded">Logout</button>
            <button onClick={()=>loadOrders()} className="bg-blue-700 px-3 py-2 rounded">Refresh</button>
          </div>
        </div>
      </div>

      <div className="max-w-6xl mx-auto p-4 space-y-6">
        {/* Active order section */}
        {activeOrder && (
          <div className="bg-white rounded-lg shadow p-4 space-y-4">
            <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
              <div>
                <div className="text-xl font-bold">Order #{activeOrder.id}</div>
                <div className="text-gray-600">{activeOrder.customer_name} • {activeOrder.delivery_address}</div>
              </div>
              <div className="text-right">{activeOrder.store_name && <div className="text-sm text-gray-500">Store: {activeOrder.store_name}</div>}</div>
            </div>
            {(activeOrder.customer_lat && activeOrder.customer_lng && activeOrder.store_lat && activeOrder.store_lng) && (
              <MapRoute customer={{lat: parseFloat(activeOrder.customer_lat), lng: parseFloat(activeOrder.customer_lng)}} store={{lat: parseFloat(activeOrder.store_lat), lng: parseFloat(activeOrder.store_lng)}} />
            )}

            {!deliveryStarted ? (
              <button onClick={markPaid} className="w-full bg-blue-600 text-white py-3 rounded">Start Delivery (Paid)</button>
            ) : (
              <div className="grid md:grid-cols-2 gap-4">
                <div className="space-y-3">
                  <label className="block text-sm font-medium">ID Type</label>
                  <select value={idType} onChange={e=>setIdType(e.target.value)} className="border rounded px-3 py-2 w-full">
                    <option value="id">ID</option>
                    <option value="passport">Passport</option>
                    <option value="driver">Driver's</option>
                  </select>
                  <label className="block text-sm font-medium">ID Photo - Front</label>
                  <input type="file" accept="image/*" capture="environment" onChange={async e=>{ if(e.target.files[0]) setIdFront(await compressImage(e.target.files[0])); }} />
                  {idFront && <img alt="front" src={idFront} className="border rounded max-h-40" />}
                  <label className="block text-sm font-medium">ID Photo - Back</label>
                  <input type="file" accept="image/*" capture="environment" onChange={async e=>{ if(e.target.files[0]) setIdBack(await compressImage(e.target.files[0])); }} />
                  {idBack && <img alt="back" src={idBack} className="border rounded max-h-40" />}
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium">Signature</label>
                  <canvas ref={sigCanvasRef} width={600} height={300} className="border rounded w-full"
                    onMouseDown={drawStart} onMouseMove={drawMove} onMouseUp={drawEnd} onMouseLeave={drawEnd}
                    onTouchStart={drawStart} onTouchMove={drawMove} onTouchEnd={drawEnd}
                  />
                  <div className="flex gap-2">
                    <button onClick={clearSignature} className="px-3 py-2 border rounded">Clear</button>
                    <button onClick={completeOrder} disabled={!signature || !idFront || !idBack} className="px-3 py-2 bg-green-600 text-white rounded">Complete Order</button>
                  </div>
                </div>
              </div>
            )}
          </div>
        )}

        {/* Pending orders */}
        <div>
          <h2 className="text-xl font-bold mb-2">Pending Orders</h2>
          <div className="space-y-3">
            {pendingOrders.length === 0 ? <div className="text-gray-500">No pending orders</div> : pendingOrders.map(o => (
              <Collapsible key={o.id} title={`#${o.id} • ${o.customer_name}`} right={<button onClick={()=>claimOrder(o.id)} className="bg-blue-600 text-white px-3 py-1 rounded">Claim</button>}>
                <div className="text-sm text-gray-600 mb-2">{o.delivery_address}</div>
                <div className="grid grid-cols-2 gap-2 text-sm">
                  {o.items.map(it => <div key={it.id} className="border rounded p-2"><div className="font-medium">{it.name}</div><div>x{it.quantity}</div></div>)}
                </div>
              </Collapsible>
            ))}
          </div>
        </div>

        {/* Claimed orders */}
        <div>
          <h2 className="text-xl font-bold mb-2">Claimed Orders</h2>
          <div className="space-y-3">
            {claimedOrders.length === 0 ? <div className="text-gray-500">No claimed orders</div> : claimedOrders.map(o => (
              <Collapsible key={o.id} title={`#${o.id} • ${o.customer_name}`} right={<button onClick={()=>startFulfillment(o)} className="bg-emerald-600 text-white px-3 py-1 rounded">Start Fulfillment</button>}>
                <div className="text-sm text-gray-600 mb-2">{o.delivery_address}</div>
                {(o.customer_lat && o.customer_lng && o.store_lat && o.store_lng) && (
                  <MapRoute customer={{lat: parseFloat(o.customer_lat), lng: parseFloat(o.customer_lng)}} store={{lat: parseFloat(o.store_lat), lng: parseFloat(o.store_lng)}} />
                )}
                <div className="grid grid-cols-2 gap-2 text-sm">
                  {o.items.map(it => <div key={it.id} className="border rounded p-2"><div className="font-medium">{it.name}</div><div>x{it.quantity}</div></div>)}
                </div>
              </Collapsible>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

const root = ReactDOM.createRoot(document.getElementById('mhu-driver-app-root'));
root.render(<App />);
</script>
<?php
    return ob_get_clean();
});

?>
