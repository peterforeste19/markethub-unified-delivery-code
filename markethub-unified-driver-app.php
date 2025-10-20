<?php
/**
 * Plugin Name: MarketHub Unified Driver App
 * Description: Part 2 - Complete driver app with maps, orders, fulfillment, and proof of delivery
 * Version: 1.0
 * Author: MarketHub TT
 */

if (!defined('ABSPATH')) exit;

// ============================================
// REGISTER CUSTOM ORDER STATUSES
// ============================================

add_action('init', 'markethub_register_driver_order_statuses');
function markethub_register_driver_order_statuses() {
    register_post_status('wc-driver-claimed', array(
        'label' => 'Driver Claimed',
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop('Driver Claimed <span class="count">(%s)</span>', 'Driver Claimed <span class="count">(%s)</span>')
    ));
    
    register_post_status('wc-out-delivery', array(
        'label' => 'Out for Delivery',
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop('Out for Delivery <span class="count">(%s)</span>', 'Out for Delivery <span class="count">(%s)</span>')
    ));
}

add_filter('wc_order_statuses', 'markethub_add_driver_order_statuses');
function markethub_add_driver_order_statuses($order_statuses) {
    $order_statuses['wc-driver-claimed'] = 'Driver Claimed';
    $order_statuses['wc-out-delivery'] = 'Out for Delivery';
    return $order_statuses;
}

// ============================================
// REST API ENDPOINTS FOR DRIVER APP
// ============================================

add_action('rest_api_init', 'markethub_register_driver_api_routes');
function markethub_register_driver_api_routes() {
    
    // Driver Login
    register_rest_route('markethub/v1', '/driver/login', array(
        'methods' => 'POST',
        'callback' => 'markethub_driver_login_api',
        'permission_callback' => '__return_true'
    ));
    
    // Get Available Orders (Pending)
    register_rest_route('markethub/v1', '/driver/orders', array(
        'methods' => 'GET',
        'callback' => 'markethub_get_driver_orders',
        'permission_callback' => 'markethub_verify_driver_auth'
    ));
    
    // Get Claimed Orders
    register_rest_route('markethub/v1', '/driver/my-orders', array(
        'methods' => 'GET',
        'callback' => 'markethub_get_my_claimed_orders',
        'permission_callback' => 'markethub_verify_driver_auth'
    ));
    
    // Claim Order
    register_rest_route('markethub/v1', '/driver/claim-order', array(
        'methods' => 'POST',
        'callback' => 'markethub_claim_order_api',
        'permission_callback' => 'markethub_verify_driver_auth'
    ));
    
    // Start Fulfillment (Pay button pressed - start delivery timer)
    register_rest_route('markethub/v1', '/driver/start-delivery', array(
        'methods' => 'POST',
        'callback' => 'markethub_start_delivery_api',
        'permission_callback' => 'markethub_verify_driver_auth'
    ));
    
    // Complete Delivery (with POD)
    register_rest_route('markethub/v1', '/driver/complete-delivery', array(
        'methods' => 'POST',
        'callback' => 'markethub_complete_delivery_api',
        'permission_callback' => 'markethub_verify_driver_auth'
    ));
}

// ============================================
// AUTHENTICATION FUNCTIONS
// ============================================

function markethub_driver_login_api($request) {
    $username = sanitize_text_field($request->get_param('username'));
    $password = $request->get_param('password');
    
    $user = wp_authenticate($username, $password);
    
    if (is_wp_error($user)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid credentials'
        ), 401);
    }
    
    // Check for driver role (also block pending users)
    if (!in_array('markethub_drivers', $user->roles) && !in_array('administrator', $user->roles)) {
        if (in_array('markethub_pending', $user->roles)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Your account is pending approval. Please wait for admin confirmation.'
            ), 403);
        }
        
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'You do not have driver permissions'
        ), 403);
    }
    
    // Generate token
    $token = wp_generate_password(32, false);
    $token_hash = wp_hash_password($token);
    
    update_user_meta($user->ID, '_mh_driver_token', $token_hash);
    update_user_meta($user->ID, '_mh_driver_token_expiry', time() + (24 * 60 * 60));
    
    return new WP_REST_Response(array(
        'success' => true,
        'token' => $token,
        'driver_id' => $user->ID,
        'driver_name' => $user->display_name
    ), 200);
}

function markethub_verify_driver_auth($request) {
    $auth_header = $request->get_header('authorization');
    
    if (!$auth_header || strpos($auth_header, 'Bearer ') !== 0) {
        return false;
    }
    
    $token = str_replace('Bearer ', '', $auth_header);
    
    $users = get_users(array(
        'role__in' => array('markethub_drivers', 'administrator'),
        'meta_key' => '_mh_driver_token'
    ));
    
    foreach ($users as $user) {
        $stored_hash = get_user_meta($user->ID, '_mh_driver_token', true);
        $expiry = get_user_meta($user->ID, '_mh_driver_token_expiry', true);
        
        if (wp_check_password($token, $stored_hash) && time() < $expiry) {
            $request->set_param('driver_user_id', $user->ID);
            return true;
        }
    }
    
    return false;
}

function markethub_get_current_driver($request) {
    return get_user_by('ID', $request->get_param('driver_user_id'));
}

// ============================================
// ORDER API FUNCTIONS
// ============================================

function markethub_get_driver_orders($request) {
    $driver = markethub_get_current_driver($request);
    
    if (!$driver) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Unauthorized'), 403);
    }
    
    // Get orders that are processing and not yet claimed
    $orders = wc_get_orders(array(
        'status' => 'processing',
        'limit' => -1,
        'meta_query' => array(
            array(
                'key' => '_mh_driver_id',
                'compare' => 'NOT EXISTS'
            )
        )
    ));
    
    $pending_orders = array();
    
    foreach ($orders as $order) {
        $pending_orders[] = markethub_format_order_for_driver($order);
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'orders' => $pending_orders
    ), 200);
}

function markethub_get_my_claimed_orders($request) {
    $driver = markethub_get_current_driver($request);
    
    if (!$driver) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Unauthorized'), 403);
    }
    
    // Get orders claimed by this driver
    $orders = wc_get_orders(array(
        'status' => array('driver-claimed', 'out-delivery'),
        'limit' => -1,
        'meta_key' => '_mh_driver_id',
        'meta_value' => $driver->ID
    ));
    
    $claimed_orders = array();
    
    foreach ($orders as $order) {
        $claimed_orders[] = markethub_format_order_for_driver($order, true);
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'orders' => $claimed_orders
    ), 200);
}

function markethub_format_order_for_driver($order, $include_full_details = false) {
    $items = array();
    
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $item_data = array(
            'id' => $item->get_id(),
            'name' => $item->get_name(),
            'quantity' => $item->get_quantity(),
            'price' => $item->get_total(),
        );
        
        if ($product) {
            $image_id = $product->get_image_id();
            $item_data['image'] = $image_id ? wp_get_attachment_url($image_id) : '';
        }
        
        $items[] = $item_data;
    }
    
    $order_data = array(
        'id' => $order->get_id(),
        'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        'customer_phone' => $order->get_billing_phone(),
        'delivery_address' => $order->get_billing_address_1() . ', ' . $order->get_billing_city(),
        'total' => $order->get_total(),
        'item_count' => count($items),
        'items' => $items,
        'status' => $order->get_status()
    );
    
    if ($include_full_details) {
        // Add GPS coordinates from checkout
        $order_data['customer_lat'] = $order->get_meta('_markethub_customer_lat');
        $order_data['customer_lng'] = $order->get_meta('_markethub_customer_lng');
        
        // Add store coordinates based on order type
        $grocery_store = $order->get_meta('_markethub_grocery_store');
        $food_store = $order->get_meta('_markethub_food_store');
        $generic_store = $order->get_meta('_markethub_generic_store');
        
        $order_data['store_lat'] = '';
        $order_data['store_lng'] = '';
        $order_data['store_name'] = '';
        
        if ($grocery_store) {
            $stores = get_option('markethub_grocery_stores', array());
            if (isset($stores[$grocery_store])) {
                $order_data['store_lat'] = $stores[$grocery_store]['lat'];
                $order_data['store_lng'] = $stores[$grocery_store]['lng'];
                $order_data['store_name'] = $stores[$grocery_store]['name'];
            }
        } elseif ($food_store) {
            $food_stores = get_option('markethub_food_stores', array());
            list($key, $idx) = explode('_', $food_store, 2);
            if (isset($food_stores[$key]['locations'][$idx])) {
                $location = $food_stores[$key]['locations'][$idx];
                $order_data['store_lat'] = $location['lat'];
                $order_data['store_lng'] = $location['lng'];
                $order_data['store_name'] = $food_stores[$key]['name'] . ' - ' . $location['address'];
            }
        } elseif ($generic_store) {
            $generic_stores = get_option('markethub_generic_stores', array());
            list($key, $idx) = explode('_', $generic_store, 2);
            if (isset($generic_stores[$key]['locations'][$idx])) {
                $location = $generic_stores[$key]['locations'][$idx];
                $order_data['store_lat'] = $location['lat'];
                $order_data['store_lng'] = $location['lng'];
                $order_data['store_name'] = $generic_stores[$key]['name'] . ' - ' . $location['address'];
            }
        }
        
        // Add timer data
        $order_data['claimed_time'] = $order->get_meta('_mh_claimed_time');
        $order_data['delivery_start_time'] = $order->get_meta('_mh_delivery_start_time');
        $order_data['delivery_complete_time'] = $order->get_meta('_mh_delivery_complete_time');
    }
    
    return $order_data;
}

function markethub_claim_order_api($request) {
    $driver = markethub_get_current_driver($request);
    $order_id = intval($request->get_param('order_id'));
    
    if (!$driver || !$order_id) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid request'), 400);
    }
    
    $order = wc_get_order($order_id);
    
    if (!$order) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Order not found'), 404);
    }
    
    // Race condition check
    $existing_driver = $order->get_meta('_mh_driver_id');
    
    if ($existing_driver) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'This order has already been claimed by another driver'
        ), 409);
    }
    
    // Claim the order
    $order->update_meta_data('_mh_driver_id', $driver->ID);
    $order->update_meta_data('_mh_driver_name', $driver->display_name);
    $order->update_meta_data('_mh_claimed_time', current_time('mysql'));
    $order->set_status('driver-claimed');
    $order->add_order_note(sprintf('Order claimed by driver: %s', $driver->display_name));
    $order->save();
    
    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Order claimed successfully',
        'order' => markethub_format_order_for_driver($order, true)
    ), 200);
}

function markethub_start_delivery_api($request) {
    $driver = markethub_get_current_driver($request);
    $order_id = intval($request->get_param('order_id'));
    
    if (!$driver || !$order_id) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid request'), 400);
    }
    
    $order = wc_get_order($order_id);
    
    if (!$order || $order->get_meta('_mh_driver_id') != $driver->ID) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Unauthorized'), 403);
    }
    
    // Start delivery timer
    $order->update_meta_data('_mh_delivery_start_time', current_time('mysql'));
    $order->set_status('out-delivery');
    $order->add_order_note('Driver started delivery - items collected and paid for');
    $order->save();
    
    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Delivery started',
        'start_time' => current_time('mysql')
    ), 200);
}

function markethub_complete_delivery_api($request) {
    $driver = markethub_get_current_driver($request);
    $order_id = intval($request->get_param('order_id'));
    $signature_data = $request->get_param('signature');
    $id_front_data = $request->get_param('id_front');
    $id_back_data = $request->get_param('id_back');
    $id_type = sanitize_text_field($request->get_param('id_type'));
    
    if (!$driver || !$order_id || !$signature_data || !$id_front_data || !$id_type) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Missing required data'), 400);
    }
    
    $order = wc_get_order($order_id);
    
    if (!$order || $order->get_meta('_mh_driver_id') != $driver->ID) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Unauthorized'), 403);
    }
    
    // Create secure directory
    $upload_dir = wp_upload_dir();
    $secure_dir = $upload_dir['basedir'] . '/markethub_pods/';
    
    if (!file_exists($secure_dir)) {
        wp_mkdir_p($secure_dir);
        file_put_contents($secure_dir . '.htaccess', "deny from all");
    }
    
    // Compress and save images
    $signature_file = markethub_save_compressed_image($signature_data, $secure_dir, 'signature_' . $order_id);
    $id_front_file = markethub_save_compressed_image($id_front_data, $secure_dir, 'id_front_' . $order_id);
    $id_back_file = $id_back_data ? markethub_save_compressed_image($id_back_data, $secure_dir, 'id_back_' . $order_id) : '';
    
    // Calculate delivery time
    $start_time = $order->get_meta('_mh_delivery_start_time');
    $complete_time = current_time('mysql');
    $delivery_duration = '';
    
    if ($start_time) {
        $start = strtotime($start_time);
        $end = strtotime($complete_time);
        $duration_minutes = round(($end - $start) / 60);
        $delivery_duration = $duration_minutes . ' minutes';
    }
    
    // Save all POD data
    $order->update_meta_data('_mh_signature_path', $signature_file);
    $order->update_meta_data('_mh_id_front_path', $id_front_file);
    $order->update_meta_data('_mh_id_back_path', $id_back_file);
    $order->update_meta_data('_mh_id_type', $id_type);
    $order->update_meta_data('_mh_delivery_complete_time', $complete_time);
    $order->update_meta_data('_mh_delivery_duration', $delivery_duration);
    $order->set_status('completed');
    $order->add_order_note(sprintf(
        'Delivery completed by %s. Duration: %s. ID Type: %s',
        $driver->display_name,
        $delivery_duration,
        $id_type
    ));
    $order->save();
    
    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Delivery completed successfully',
        'duration' => $delivery_duration
    ), 200);
}

function markethub_save_compressed_image($base64_data, $dir, $prefix) {
    // Remove data URI scheme
    $image_data = preg_replace('#^data:image/\w+;base64,#i', '', $base64_data);
    $decoded = base64_decode($image_data);
    
    // Create temp file
    $temp_file = tempnam(sys_get_temp_dir(), 'mh_img_');
    file_put_contents($temp_file, $decoded);
    
    // Load image
    $image_info = getimagesize($temp_file);
    $image_type = $image_info[2];
    
    if ($image_type == IMAGETYPE_JPEG) {
        $source = imagecreatefromjpeg($temp_file);
    } elseif ($image_type == IMAGETYPE_PNG) {
        $source = imagecreatefrompng($temp_file);
    } else {
        // Fallback: save as-is
        $filename = $prefix . '_' . time() . '.jpg';
        $filepath = $dir . $filename;
        file_put_contents($filepath, $decoded);
        unlink($temp_file);
        return $filepath;
    }
    
    // Compress and save
    $filename = $prefix . '_' . time() . '.jpg';
    $filepath = $dir . $filename;
    
    // Resize if too large
    $width = imagesx($source);
    $height = imagesy($source);
    $max_size = 1200;
    
    if ($width > $max_size || $height > $max_size) {
        $ratio = $width / $height;
        if ($width > $height) {
            $new_width = $max_size;
            $new_height = $max_size / $ratio;
        } else {
            $new_height = $max_size;
            $new_width = $max_size * $ratio;
        }
        
        $compressed = imagecreatetruecolor($new_width, $new_height);
        imagecopyresampled($compressed, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagejpeg($compressed, $filepath, 75);
        imagedestroy($compressed);
    } else {
        imagejpeg($source, $filepath, 75);
    }
    
    imagedestroy($source);
    unlink($temp_file);
    
    return $filepath;
}

// ============================================
// ADMIN VISIBILITY - ORDER META BOX
// ============================================

add_action('add_meta_boxes', 'markethub_add_driver_info_metabox');
function markethub_add_driver_info_metabox() {
    add_meta_box(
        'markethub_driver_info',
        '🚗 Driver & Delivery Information',
        'markethub_driver_info_metabox_content',
        'shop_order',
        'side',
        'high'
    );
    
    add_meta_box(
        'markethub_pod',
        '📋 Proof of Delivery',
        'markethub_pod_metabox_content',
        'shop_order',
        'normal',
        'high'
    );
}

function markethub_driver_info_metabox_content($post) {
    $order = wc_get_order($post->ID);
    
    $driver_id = $order->get_meta('_mh_driver_id');
    $driver_name = $order->get_meta('_mh_driver_name');
    $claimed_time = $order->get_meta('_mh_claimed_time');
    $start_time = $order->get_meta('_mh_delivery_start_time');
    $complete_time = $order->get_meta('_mh_delivery_complete_time');
    $duration = $order->get_meta('_mh_delivery_duration');
    
    ?>
    <div style="padding: 10px;">
        <?php if ($driver_name): ?>
            <p><strong>Driver:</strong><br><?php echo esc_html($driver_name); ?></p>
            <?php if ($claimed_time): ?>
                <p><strong>Claimed:</strong><br><?php echo date('Y-m-d H:i:s', strtotime($claimed_time)); ?></p>
            <?php endif; ?>
            <?php if ($start_time): ?>
                <p><strong>Delivery Started:</strong><br><?php echo date('Y-m-d H:i:s', strtotime($start_time)); ?></p>
            <?php endif; ?>
            <?php if ($complete_time): ?>
                <p><strong>Completed:</strong><br><?php echo date('Y-m-d H:i:s', strtotime($complete_time)); ?></p>
            <?php endif; ?>
            <?php if ($duration): ?>
                <p><strong>Duration:</strong><br><?php echo esc_html($duration); ?></p>
            <?php endif; ?>
        <?php else: ?>
            <p style="color: #999;">Not yet claimed by a driver</p>
        <?php endif; ?>
    </div>
    <?php
}

function markethub_pod_metabox_content($post) {
    $order = wc_get_order($post->ID);
    
    $signature_path = $order->get_meta('_mh_signature_path');
    $id_front_path = $order->get_meta('_mh_id_front_path');
    $id_back_path = $order->get_meta('_mh_id_back_path');
    $id_type = $order->get_meta('_mh_id_type');
    
    ?>
    <div style="padding: 15px;">
        <?php if ($signature_path || $id_front_path): ?>
            
            <?php if ($id_type): ?>
                <h4>ID Type: <?php echo esc_html(ucfirst($id_type)); ?></h4>
            <?php endif; ?>
            
            <?php if ($id_front_path && file_exists($id_front_path)): ?>
                <div style="margin: 15px 0;">
                    <strong>Customer ID (Front):</strong><br>
                    <img src="<?php echo esc_url(markethub_get_secure_image_url($id_front_path)); ?>" 
                         style="max-width: 100%; height: auto; border: 1px solid #ddd; margin-top: 10px;">
                </div>
            <?php endif; ?>
            
            <?php if ($id_back_path && file_exists($id_back_path)): ?>
                <div style="margin: 15px 0;">
                    <strong>Customer ID (Back):</strong><br>
                    <img src="<?php echo esc_url(markethub_get_secure_image_url($id_back_path)); ?>" 
                         style="max-width: 100%; height: auto; border: 1px solid #ddd; margin-top: 10px;">
                </div>
            <?php endif; ?>
            
            <?php if ($signature_path && file_exists($signature_path)): ?>
                <div style="margin: 15px 0;">
                    <strong>Customer Signature:</strong><br>
                    <img src="<?php echo esc_url(markethub_get_secure_image_url($signature_path)); ?>" 
                         style="max-width: 400px; height: auto; border: 1px solid #ddd; margin-top: 10px; background: #f9f9f9;">
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <p style="color: #999;">Proof of delivery not yet captured</p>
        <?php endif; ?>
    </div>
    <?php
}

function markethub_get_secure_image_url($filepath) {
    // Create a nonce-protected URL
    $filename = basename($filepath);
    return add_query_arg(array(
        'mh_pod_image' => $filename,
        'nonce' => wp_create_nonce('mh_pod_' . $filename)
    ), admin_url('admin-ajax.php'));
}

// Serve secure images
add_action('wp_ajax_nopriv_', 'markethub_serve_pod_image');
add_action('wp_ajax_', 'markethub_serve_pod_image');
add_action('admin_init', 'markethub_serve_pod_image_admin');
function markethub_serve_pod_image_admin() {
    if (!isset($_GET['mh_pod_image']) || !isset($_GET['nonce'])) {
        return;
    }
    
    $filename = sanitize_file_name($_GET['mh_pod_image']);
    
    if (!wp_verify_nonce($_GET['nonce'], 'mh_pod_' . $filename)) {
        wp_die('Invalid request');
    }
    
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }
    
    $upload_dir = wp_upload_dir();
    $filepath = $upload_dir['basedir'] . '/markethub_pods/' . $filename;
    
    if (!file_exists($filepath)) {
        wp_die('File not found');
    }
    
    $mime_type = mime_content_type($filepath);
    header('Content-Type: ' . $mime_type);
    readfile($filepath);
    exit;
}

// ============================================
// SHORTCODE FOR DRIVER APP PAGE
// ============================================

add_shortcode('markethub_driver_app', 'markethub_driver_app_shortcode');
function markethub_driver_app_shortcode() {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>MarketHub Driver App</title>
        <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
        <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
        <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
        <script src="https://cdn.tailwindcss.com"></script>
        <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyBERt1jXPqYasS3pPVOUUF9oh8QfCQ8_TA&libraries=geometry,places"></script>
        <style>
            body, html { margin: 0; padding: 0; height: 100%; overflow: hidden; }
            #root { height: 100vh; }
            .order-collapse { max-height: 60px; overflow: hidden; transition: max-height 0.3s ease; }
            .order-collapse.expanded { max-height: 2000px; }
        </style>
    </head>
    <body>
        <div id="root"></div>
        
        <script type="text/babel">
            const { useState, useEffect, useRef } = React;
            
            const API_BASE = '<?php echo rest_url('markethub/v1'); ?>';
            
            // SVG Icons
            const User = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>;
            const Package = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>;
            const MapPin = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>;
            const Clock = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>;
            const CheckCircle = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>;
            const Camera = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" /></svg>;
            const LogOut = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>;
            const ChevronDown = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" /></svg>;

            const MarketHubDriverApp = () => {
                const [isLoggedIn, setIsLoggedIn] = useState(false);
                const [authToken, setAuthToken] = useState('');
                const [driverName, setDriverName] = useState('');
                const [driverId, setDriverId] = useState('');
                const [username, setUsername] = useState('');
                const [password, setPassword] = useState('');
                const [pendingOrders, setPendingOrders] = useState([]);
                const [claimedOrders, setClaimedOrders] = useState([]);
                const [currentOrder, setCurrentOrder] = useState(null);
                const [expandedOrders, setExpandedOrders] = useState({});
                const [showMap, setShowMap] = useState(false);
                const [loginError, setLoginError] = useState('');
                const [loading, setLoading] = useState(false);
                
                // POD states
                const [idFront, setIdFront] = useState('');
                const [idBack, setIdBack] = useState('');
                const [idType, setIdType] = useState('');
                const [signature, setSignature] = useState('');
                const [isDrawing, setIsDrawing] = useState(false);
                const [deliveryTimer, setDeliveryTimer] = useState(0);
                
                const mapRef = useRef(null);
                const mapInstanceRef = useRef(null);
                const signatureRef = useRef(null);
                const timerIntervalRef = useRef(null);

                useEffect(() => {
                    // Check for saved token
                    const savedToken = localStorage.getItem('mh_driver_token');
                    const savedName = localStorage.getItem('mh_driver_name');
                    const savedId = localStorage.getItem('mh_driver_id');
                    
                    if (savedToken && savedName) {
                        setAuthToken(savedToken);
                        setDriverName(savedName);
                        setDriverId(savedId);
                        setIsLoggedIn(true);
                        loadOrders(savedToken);
                    }
                }, []);

                useEffect(() => {
                    if (isLoggedIn && authToken) {
                        const interval = setInterval(() => {
                            loadOrders(authToken);
                        }, 30000); // Refresh every 30 seconds
                        
                        return () => clearInterval(interval);
                    }
                }, [isLoggedIn, authToken]);

                const handleLogin = async () => {
                    setLoading(true);
                    setLoginError('');

                    try {
                        const response = await fetch(`${API_BASE}/driver/login`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ username, password })
                        });

                        const data = await response.json();
                        
                        if (data.success) {
                            setAuthToken(data.token);
                            setDriverName(data.driver_name);
                            setDriverId(data.driver_id);
                            setIsLoggedIn(true);
                            
                            localStorage.setItem('mh_driver_token', data.token);
                            localStorage.setItem('mh_driver_name', data.driver_name);
                            localStorage.setItem('mh_driver_id', data.driver_id);
                            
                            loadOrders(data.token);
                        } else {
                            setLoginError(data.message || 'Invalid credentials');
                        }
                    } catch (error) {
                        setLoginError('Connection error. Please try again.');
                    }
                    setLoading(false);
                };

                const loadOrders = async (token) => {
                    try {
                        const [pendingRes, claimedRes] = await Promise.all([
                            fetch(`${API_BASE}/driver/orders`, {
                                headers: { 'Authorization': `Bearer ${token}` }
                            }),
                            fetch(`${API_BASE}/driver/my-orders`, {
                                headers: { 'Authorization': `Bearer ${token}` }
                            })
                        ]);

                        const pendingData = await pendingRes.json();
                        const claimedData = await claimedRes.json();
                        
                        if (pendingData.success) setPendingOrders(pendingData.orders);
                        if (claimedData.success) setClaimedOrders(claimedData.orders);
                    } catch (error) {
                        console.error('Error loading orders:', error);
                    }
                };

                const claimOrder = async (orderId) => {
                    setLoading(true);
                    try {
                        const response = await fetch(`${API_BASE}/driver/claim-order`, {
                            method: 'POST',
                            headers: {
                                'Authorization': `Bearer ${authToken}`,
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({ order_id: orderId })
                        });

                        const data = await response.json();
                        
                        if (data.success) {
                            alert('Order claimed successfully!');
                            loadOrders(authToken);
                        } else {
                            alert(data.message || 'Failed to claim order');
                        }
                    } catch (error) {
                        alert('Error claiming order');
                    }
                    setLoading(false);
                };

                const startDelivery = async (orderId) => {
                    setLoading(true);
                    try {
                        const response = await fetch(`${API_BASE}/driver/start-delivery`, {
                            method: 'POST',
                            headers: {
                                'Authorization': `Bearer ${authToken}`,
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({ order_id: orderId })
                        });

                        const data = await response.json();
                        
                        if (data.success) {
                            setCurrentOrder(claimedOrders.find(o => o.id === orderId));
                            startTimer();
                            loadOrders(authToken);
                        }
                    } catch (error) {
                        alert('Error starting delivery');
                    }
                    setLoading(false);
                };

                const startTimer = () => {
                    setDeliveryTimer(0);
                    timerIntervalRef.current = setInterval(() => {
                        setDeliveryTimer(prev => prev + 1);
                    }, 1000);
                };

                const stopTimer = () => {
                    if (timerIntervalRef.current) {
                        clearInterval(timerIntervalRef.current);
                    }
                };

                const formatTime = (seconds) => {
                    const mins = Math.floor(seconds / 60);
                    const secs = seconds % 60;
                    return `${mins}:${secs.toString().padStart(2, '0')}`;
                };

                const toggleOrderExpand = (orderId) => {
                    setExpandedOrders(prev => ({
                        ...prev,
                        [orderId]: !prev[orderId]
                    }));
                };

                const showOrderOnMap = (order) => {
                    setCurrentOrder(order);
                    setShowMap(true);
                    
                    setTimeout(() => {
                        initMap(order);
                    }, 100);
                };

                const initMap = (order) => {
                    if (!mapRef.current || !order.store_lat || !order.customer_lat) return;
                    
                    const storeLoc = { lat: parseFloat(order.store_lat), lng: parseFloat(order.store_lng) };
                    const customerLoc = { lat: parseFloat(order.customer_lat), lng: parseFloat(order.customer_lng) };
                    
                    const map = new google.maps.Map(mapRef.current, {
                        zoom: 12,
                        center: storeLoc
                    });
                    
                    mapInstanceRef.current = map;
                    
                    // Add markers
                    new google.maps.Marker({
                        position: storeLoc,
                        map: map,
                        title: 'Store Location',
                        label: 'S',
                        icon: {
                            url: 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png'
                        }
                    });
                    
                    new google.maps.Marker({
                        position: customerLoc,
                        map: map,
                        title: 'Customer Location',
                        label: 'C',
                        icon: {
                            url: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png'
                        }
                    });
                    
                    // Draw route
                    const directionsService = new google.maps.DirectionsService();
                    const directionsRenderer = new google.maps.DirectionsRenderer({
                        map: map,
                        suppressMarkers: true
                    });
                    
                    directionsService.route({
                        origin: storeLoc,
                        destination: customerLoc,
                        travelMode: google.maps.TravelMode.DRIVING
                    }, (result, status) => {
                        if (status === 'OK') {
                            directionsRenderer.setDirections(result);
                        }
                    });
                };

                const startDrawing = (e) => {
                    setIsDrawing(true);
                    const canvas = signatureRef.current;
                    const ctx = canvas.getContext('2d');
                    const rect = canvas.getBoundingClientRect();
                    const x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
                    const y = (e.touches ? e.touches[0].clientY : e.clientY) - rect.top;
                    ctx.beginPath();
                    ctx.moveTo(x, y);
                };

                const draw = (e) => {
                    if (!isDrawing) return;
                    const canvas = signatureRef.current;
                    const ctx = canvas.getContext('2d');
                    const rect = canvas.getBoundingClientRect();
                    const x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
                    const y = (e.touches ? e.touches[0].clientY : e.clientY) - rect.top;
                    ctx.lineTo(x, y);
                    ctx.stroke();
                };

                const stopDrawing = () => {
                    if (isDrawing) {
                        setIsDrawing(false);
                        setSignature(signatureRef.current.toDataURL());
                    }
                };

                const clearSignature = () => {
                    const canvas = signatureRef.current;
                    const ctx = canvas.getContext('2d');
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    setSignature('');
                };

                const captureImage = (e, setter) => {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onloadend = () => {
                            setter(reader.result);
                        };
                        reader.readAsDataURL(file);
                    }
                };

                const completeDelivery = async () => {
                    if (!idFront || !signature || !idType) {
                        alert('Please capture ID (front), signature, and select ID type');
                        return;
                    }

                    setLoading(true);
                    stopTimer();
                    
                    try {
                        const response = await fetch(`${API_BASE}/driver/complete-delivery`, {
                            method: 'POST',
                            headers: {
                                'Authorization': `Bearer ${authToken}`,
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                order_id: currentOrder.id,
                                signature: signature,
                                id_front: idFront,
                                id_back: idBack,
                                id_type: idType
                            })
                        });

                        const data = await response.json();
                        
                        if (data.success) {
                            alert(`Delivery completed! Duration: ${data.duration}`);
                            setCurrentOrder(null);
                            setShowMap(false);
                            setIdFront('');
                            setIdBack('');
                            setIdType('');
                            setSignature('');
                            setDeliveryTimer(0);
                            loadOrders(authToken);
                        } else {
                            alert(data.message || 'Error completing delivery');
                        }
                    } catch (error) {
                        alert('Error completing delivery');
                    }
                    setLoading(false);
                };

                const handleLogout = () => {
                    setIsLoggedIn(false);
                    setAuthToken('');
                    setDriverName('');
                    setCurrentOrder(null);
                    localStorage.removeItem('mh_driver_token');
                    localStorage.removeItem('mh_driver_name');
                    localStorage.removeItem('mh_driver_id');
                };

                if (!isLoggedIn) {
                    return (
                        <div className="min-h-screen bg-gradient-to-br from-blue-600 to-blue-800 flex items-center justify-center p-4">
                            <div className="bg-white rounded-lg shadow-2xl p-8 w-full max-w-md">
                                <div className="text-center mb-8">
                                    <Package className="w-16 h-16 text-blue-600 mx-auto mb-4" />
                                    <h1 className="text-3xl font-bold text-gray-800">MarketHub Driver</h1>
                                    <p className="text-gray-600 mt-2">Login to start deliveries</p>
                                </div>
                                
                                <div className="space-y-4">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">Username</label>
                                        <input
                                            type="text"
                                            value={username}
                                            onChange={(e) => setUsername(e.target.value)}
                                            className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                            placeholder="Enter your username"
                                        />
                                    </div>
                                    
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">Password</label>
                                        <input
                                            type="password"
                                            value={password}
                                            onChange={(e) => setPassword(e.target.value)}
                                            onKeyPress={(e) => e.key === 'Enter' && handleLogin()}
                                            className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                            placeholder="Enter your password"
                                        />
                                    </div>

                                    {loginError && (
                                        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">
                                            {loginError}
                                        </div>
                                    )}

                                    <button
                                        onClick={handleLogin}
                                        disabled={loading}
                                        className="w-full bg-blue-600 text-white py-3 rounded-lg font-semibold hover:bg-blue-700 transition disabled:opacity-50"
                                    >
                                        {loading ? 'Logging in...' : 'Login'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    );
                }

                if (showMap && currentOrder) {
                    const showingPOD = currentOrder.status === 'out-delivery' || deliveryTimer > 0;
                    
                    return (
                        <div className="h-screen flex flex-col">
                            <div className="bg-blue-600 text-white p-4 flex justify-between items-center">
                                <div>
                                    <div className="font-semibold">Order #{currentOrder.id}</div>
                                    <div className="text-xs">{currentOrder.customer_name}</div>
                                </div>
                                {deliveryTimer > 0 && (
                                    <div className="bg-red-600 px-4 py-2 rounded-lg font-bold text-lg">
                                        ⏱️ {formatTime(deliveryTimer)}
                                    </div>
                                )}
                                <button onClick={() => { setShowMap(false); stopTimer(); }} className="bg-blue-700 px-4 py-2 rounded-lg">
                                    ← Back
                                </button>
                            </div>
                            
                            <div ref={mapRef} style={{height: showingPOD ? '40vh' : '70vh', width: '100%'}}></div>
                            
                            {!showingPOD ? (
                                <div className="p-4 bg-white overflow-auto">
                                    <h3 className="text-xl font-bold mb-4">Order Items</h3>
                                    <div className="space-y-3">
                                        {currentOrder.items.map((item, idx) => (
                                            <details key={idx} className="border rounded-lg">
                                                <summary className="cursor-pointer p-3 font-medium hover:bg-gray-50">
                                                    {item.quantity}x {item.name}
                                                </summary>
                                                {item.image && (
                                                    <div className="p-3 border-t">
                                                        <img src={item.image} alt={item.name} className="w-full max-w-xs rounded" />
                                                    </div>
                                                )}
                                            </details>
                                        ))}
                                    </div>
                                    <button
                                        onClick={() => startDelivery(currentOrder.id)}
                                        className="w-full mt-4 bg-green-600 text-white py-4 rounded-lg font-bold text-lg hover:bg-green-700"
                                    >
                                        💳 Items Collected - Start Delivery
                                    </button>
                                </div>
                            ) : (
                                <div className="p-4 bg-white overflow-auto" style={{height: '60vh'}}>
                                    <h3 className="text-xl font-bold mb-4">📋 Proof of Delivery</h3>
                                    
                                    <div className="space-y-4">
                                        <div>
                                            <label className="block font-medium mb-2">ID Type:</label>
                                            <select
                                                value={idType}
                                                onChange={(e) => setIdType(e.target.value)}
                                                className="w-full border rounded-lg p-2"
                                            >
                                                <option value="">Select ID Type</option>
                                                <option value="national_id">National ID</option>
                                                <option value="passport">Passport</option>
                                                <option value="drivers_license">Driver's License</option>
                                            </select>
                                        </div>
                                        
                                        <div>
                                            <label className="block font-medium mb-2">ID Photo (Front):</label>
                                            <input
                                                type="file"
                                                accept="image/*"
                                                capture="environment"
                                                onChange={(e) => captureImage(e, setIdFront)}
                                                className="w-full border rounded-lg p-2"
                                            />
                                            {idFront && <img src={idFront} className="mt-2 max-w-xs rounded border" />}
                                        </div>
                                        
                                        <div>
                                            <label className="block font-medium mb-2">ID Photo (Back - Optional):</label>
                                            <input
                                                type="file"
                                                accept="image/*"
                                                capture="environment"
                                                onChange={(e) => captureImage(e, setIdBack)}
                                                className="w-full border rounded-lg p-2"
                                            />
                                            {idBack && <img src={idBack} className="mt-2 max-w-xs rounded border" />}
                                        </div>
                                        
                                        <div>
                                            <label className="block font-medium mb-2">Customer Signature:</label>
                                            <canvas
                                                ref={signatureRef}
                                                width={400}
                                                height={200}
                                                onMouseDown={startDrawing}
                                                onMouseMove={draw}
                                                onMouseUp={stopDrawing}
                                                onTouchStart={startDrawing}
                                                onTouchMove={draw}
                                                onTouchEnd={stopDrawing}
                                                className="border-2 border-gray-300 rounded w-full touch-none"
                                                style={{maxWidth: '400px', background: '#f9f9f9'}}
                                            />
                                            <button onClick={clearSignature} className="mt-2 text-sm text-blue-600">
                                                Clear Signature
                                            </button>
                                        </div>
                                        
                                        <button
                                            onClick={completeDelivery}
                                            disabled={loading || !idFront || !signature || !idType}
                                            className="w-full bg-green-600 text-white py-4 rounded-lg font-bold text-lg hover:bg-green-700 disabled:opacity-50"
                                        >
                                            ✅ Complete Delivery
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    );
                }

                return (
                    <div className="h-screen flex flex-col bg-gray-50">
                        <div className="bg-blue-600 text-white p-4">
                            <div className="flex justify-between items-center">
                                <div className="flex items-center gap-3">
                                    <User className="w-6 h-6" />
                                    <div>
                                        <div className="font-semibold">{driverName}</div>
                                        <div className="text-xs text-blue-100">MarketHub Driver</div>
                                    </div>
                                </div>
                                <button onClick={handleLogout} className="flex items-center gap-2 bg-blue-700 px-4 py-2 rounded-lg hover:bg-blue-800">
                                    <LogOut className="w-4 h-4" />
                                    <span>Logout</span>
                                </button>
                            </div>
                        </div>

                        <div className="flex-1 overflow-auto p-4">
                            <div className="mb-6">
                                <h2 className="text-2xl font-bold text-gray-800 mb-4">📦 Pending Orders</h2>
                                {pendingOrders.length === 0 ? (
                                    <div className="bg-white rounded-lg p-8 text-center text-gray-500">
                                        No pending orders available
                                    </div>
                                ) : (
                                    <div className="space-y-3">
                                        {pendingOrders.map(order => (
                                            <div key={order.id} className={`bg-white rounded-lg shadow order-collapse ${expandedOrders[order.id] ? 'expanded' : ''}`}>
                                                <div
                                                    onClick={() => toggleOrderExpand(order.id)}
                                                    className="p-4 cursor-pointer flex justify-between items-center hover:bg-gray-50"
                                                >
                                                    <div className="flex-1">
                                                        <div className="font-bold text-lg">Order #{order.id}</div>
                                                        <div className="text-sm text-gray-600">{order.customer_name}</div>
                                                    </div>
                                                    <div className="text-right mr-3">
                                                        <div className="font-bold text-blue-600">${order.total}</div>
                                                        <div className="text-xs text-gray-500">{order.item_count} items</div>
                                                    </div>
                                                    <ChevronDown className={`w-6 h-6 transition-transform ${expandedOrders[order.id] ? 'rotate-180' : ''}`} />
                                                </div>
                                                {expandedOrders[order.id] && (
                                                    <div className="p-4 border-t">
                                                        <p className="text-sm text-gray-600 mb-3">
                                                            <MapPin className="w-4 h-4 inline mr-1" />
                                                            {order.delivery_address}
                                                        </p>
                                                        <button
                                                            onClick={() => claimOrder(order.id)}
                                                            disabled={loading}
                                                            className="w-full bg-blue-600 text-white py-3 rounded-lg font-semibold hover:bg-blue-700 disabled:opacity-50"
                                                        >
                                                            Accept Order
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <div>
                                <h2 className="text-2xl font-bold text-gray-800 mb-4">🚗 My Claimed Orders</h2>
                                {claimedOrders.length === 0 ? (
                                    <div className="bg-white rounded-lg p-8 text-center text-gray-500">
                                        You haven't claimed any orders yet
                                    </div>
                                ) : (
                                    <div className="space-y-3">
                                        {claimedOrders.map(order => (
                                            <div key={order.id} className={`bg-green-50 rounded-lg shadow border-2 border-green-500 order-collapse ${expandedOrders[order.id] ? 'expanded' : ''}`}>
                                                <div
                                                    onClick={() => toggleOrderExpand(order.id)}
                                                    className="p-4 cursor-pointer flex justify-between items-center hover:bg-green-100"
                                                >
                                                    <div className="flex-1">
                                                        <div className="font-bold text-lg">Order #{order.id}</div>
                                                        <div className="text-sm text-gray-600">{order.customer_name}</div>
                                                        <div className="text-xs font-medium text-green-700 mt-1">
                                                            {order.status === 'out-delivery' ? '🚚 Out for Delivery' : '✅ Claimed'}
                                                        </div>
                                                    </div>
                                                    <div className="text-right mr-3">
                                                        <div className="font-bold text-green-600">${order.total}</div>
                                                        <div className="text-xs text-gray-500">{order.item_count} items</div>
                                                    </div>
                                                    <ChevronDown className={`w-6 h-6 transition-transform ${expandedOrders[order.id] ? 'rotate-180' : ''}`} />
                                                </div>
                                                {expandedOrders[order.id] && (
                                                    <div className="p-4 border-t border-green-200">
                                                        <p className="text-sm text-gray-600 mb-3">
                                                            <MapPin className="w-4 h-4 inline mr-1" />
                                                            {order.delivery_address}
                                                        </p>
                                                        <button
                                                            onClick={() => showOrderOnMap(order)}
                                                            className="w-full bg-green-600 text-white py-3 rounded-lg font-semibold hover:bg-green-700"
                                                        >
                                                            {order.status === 'out-delivery' ? '📋 Complete Delivery' : '🚀 Start Fulfillment'}
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                );
            };

            const root = ReactDOM.createRoot(document.getElementById('root'));
            root.render(<MarketHubDriverApp />);
        </script>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

// Create driver app page automatically
add_action('after_setup_theme', 'markethub_create_driver_app_page');
function markethub_create_driver_app_page() {
    $page = get_page_by_path('driver-app');
    
    if (!$page) {
        wp_insert_post(array(
            'post_title' => 'Driver App',
            'post_content' => '[markethub_driver_app]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'driver-app'
        ));
    }
}
