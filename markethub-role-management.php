<?php
/**
 * Plugin Name: MarketHub Role Management & Sign-up System
 * Description: Part 1 - Role standardization, unified sign-up, driver/employee management
 * Version: 1.0
 * Author: MarketHub TT
 */

if (!defined('ABSPATH')) exit;

// ============================================
// ACTION 1 & 2: ROLE STANDARDIZATION & PENDING ROLE
// ============================================

register_activation_hook(__FILE__, 'markethub_register_custom_roles');
function markethub_register_custom_roles() {
    // Remove old singular role if it exists
    remove_role('markethub_driver');
    
    // Register plural roles
    add_role('markethub_drivers', 'MarketHub Driver', array(
        'read' => true,
        'edit_posts' => false,
        'delete_posts' => false,
    ));
    
    add_role('markethub_employees', 'MarketHub Employee', array(
        'read' => true,
        'edit_posts' => false,
        'delete_posts' => false,
    ));
    
    // Pending role with minimal capabilities
    add_role('markethub_pending', 'MarketHub Pending Approval', array(
        'read' => false,
    ));
}

// ============================================
// ACTION 3: UNIFIED SIGN-UP LOGIC
// ============================================

// Add applicant type field to registration form
add_action('woocommerce_register_form', 'markethub_add_applicant_type_field');
function markethub_add_applicant_type_field() {
    ?>
    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide" style="margin-top: 20px; padding: 20px; background: #f0f8ff; border: 2px solid #2271b1; border-radius: 8px;">
        <label style="font-weight: 600; font-size: 16px; color: #2271b1; margin-bottom: 10px; display: block;">
            <?php _e('Are you applying as a Driver or Employee?', 'markethub'); ?>
        </label>
        <select class="woocommerce-Input woocommerce-Input--text input-text" name="mh_applicant_type" id="mh_applicant_type" required style="width: 100%; padding: 12px; font-size: 14px;">
            <option value="">-- Select Type --</option>
            <option value="driver">🚗 Driver (Deliver orders)</option>
            <option value="employee">💼 Employee (Office/Admin)</option>
            <option value="customer">🛒 Customer (Just shopping)</option>
        </select>
        <span class="description" style="font-size: 13px; color: #666; margin-top: 8px; display: block;">
            <strong>Drivers:</strong> Will be activated automatically after first login<br>
            <strong>Employees:</strong> Require admin approval<br>
            <strong>Customers:</strong> Regular shopping account
        </span>
    </p>
    <?php
}

// Validate applicant type field
add_filter('woocommerce_registration_errors', 'markethub_validate_applicant_type', 10, 3);
function markethub_validate_applicant_type($errors, $username, $email) {
    if (empty($_POST['mh_applicant_type'])) {
        $errors->add('applicant_type_error', __('Please select whether you are applying as a Driver, Employee, or Customer.', 'markethub'));
    }
    return $errors;
}

// Override role on registration and save applicant type
add_action('woocommerce_created_customer', 'markethub_override_customer_role', 10, 3);
function markethub_override_customer_role($customer_id, $new_customer_data, $password_generated) {
    $applicant_type = isset($_POST['mh_applicant_type']) ? sanitize_text_field($_POST['mh_applicant_type']) : 'customer';
    
    // Save applicant type as user meta
    update_user_meta($customer_id, 'mh_applicant_type', $applicant_type);
    
    // Get the user object
    $user = get_user_by('ID', $customer_id);
    
    if ($applicant_type === 'driver') {
        // Set to pending role for drivers
        $user->remove_role('customer');
        $user->add_role('markethub_pending');
        update_user_meta($customer_id, 'mh_pending_reason', 'Awaiting first login for driver activation');
        
    } elseif ($applicant_type === 'employee') {
        // Set to pending role for employees (requires manual approval)
        $user->remove_role('customer');
        $user->add_role('markethub_pending');
        update_user_meta($customer_id, 'mh_pending_reason', 'Awaiting admin approval for employee access');
        
        // Notify admin of new employee application
        $admin_email = get_option('admin_email');
        $subject = 'New MarketHub Employee Application';
        $message = sprintf(
            "New employee application received:\n\nName: %s\nEmail: %s\nUser ID: %d\n\nPlease review and approve at: %s",
            $user->display_name,
            $user->user_email,
            $customer_id,
            admin_url('admin.php?page=markethub-management')
        );
        wp_mail($admin_email, $subject, $message);
        
    } else {
        // Regular customer - keep default customer role
        // No action needed
    }
}

// ============================================
// ACTION 4: AUTOMATE DRIVER ACTIVATION
// ============================================

add_action('wp_login', 'markethub_auto_activate_driver', 10, 2);
function markethub_auto_activate_driver($user_login, $user) {
    // Check if user has pending role
    if (!in_array('markethub_pending', (array) $user->roles)) {
        return;
    }
    
    // Check if applicant type is driver
    $applicant_type = get_user_meta($user->ID, 'mh_applicant_type', true);
    
    if ($applicant_type === 'driver') {
        // Automatically upgrade to driver role
        $user->remove_role('markethub_pending');
        $user->add_role('markethub_drivers');
        
        // Log activation
        update_user_meta($user->ID, 'mh_activated_at', current_time('mysql'));
        update_user_meta($user->ID, 'mh_activation_method', 'automatic_first_login');
        
        // Clean up applicant type meta
        delete_user_meta($user->ID, 'mh_applicant_type');
        delete_user_meta($user->ID, 'mh_pending_reason');
        
        // Send welcome email
        $to = $user->user_email;
        $subject = 'Welcome to MarketHub - Driver Account Activated';
        $message = sprintf(
            "Hello %s,\n\nYour driver account has been activated!\n\nYou can now access the driver app at:\n%s/driver-app\n\nThank you for joining MarketHub!",
            $user->display_name,
            home_url()
        );
        wp_mail($to, $subject, $message);
    }
}

// ============================================
// ACTION 5: RESTRICT PENDING ACCESS
// ============================================

// Block pending users from accessing WordPress dashboard
add_action('admin_init', 'markethub_restrict_pending_dashboard_access');
function markethub_restrict_pending_dashboard_access() {
    $user = wp_get_current_user();
    
    if (in_array('markethub_pending', (array) $user->roles)) {
        wp_redirect(home_url());
        exit;
    }
}

// Block pending users from REST API
add_filter('rest_authentication_errors', 'markethub_restrict_pending_api_access');
function markethub_restrict_pending_api_access($result) {
    if (!empty($result)) {
        return $result;
    }
    
    $user = wp_get_current_user();
    
    if ($user && in_array('markethub_pending', (array) $user->roles)) {
        return new WP_Error(
            'markethub_pending_access_denied',
            'Your account is pending approval. Please contact an administrator.',
            array('status' => 403)
        );
    }
    
    return $result;
}

// Display pending message on login
add_filter('login_message', 'markethub_pending_login_message');
function markethub_pending_login_message($message) {
    if (isset($_GET['markethub_pending'])) {
        $message .= '<div id="login_error"><strong>Account Pending:</strong> ';
        
        if ($_GET['markethub_pending'] === 'employee') {
            $message .= 'Your employee account is awaiting admin approval. You will be notified via email once approved.</div>';
        } else {
            $message .= 'Your account is pending. Please contact support.</div>';
        }
    }
    return $message;
}

// Redirect pending users after login
add_filter('woocommerce_login_redirect', 'markethub_redirect_pending_users', 10, 2);
function markethub_redirect_pending_users($redirect, $user) {
    if (in_array('markethub_pending', (array) $user->roles)) {
        $applicant_type = get_user_meta($user->ID, 'mh_applicant_type', true);
        
        // Log them out
        wp_logout();
        
        // Redirect to login with message
        return add_query_arg('markethub_pending', $applicant_type, wp_login_url());
    }
    
    return $redirect;
}

// ============================================
// ACTION 6: EMPLOYEE MIGRATION (from custom table to WP users)
// ============================================

// Add admin page for one-time migration
add_action('admin_menu', 'markethub_add_migration_page');
function markethub_add_migration_page() {
    add_submenu_page(
        'tools.php',
        'MarketHub Employee Migration',
        'MH Employee Migration',
        'manage_options',
        'markethub-migration',
        'markethub_migration_page'
    );
}

function markethub_migration_page() {
    global $wpdb;
    
    if (isset($_POST['migrate_employees']) && check_admin_referer('mh_migrate_employees')) {
        $table_name = $wpdb->prefix . 'mh_employees';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            echo '<div class="notice notice-error"><p>Employee table does not exist. Nothing to migrate.</p></div>';
        } else {
            $employees = $wpdb->get_results("SELECT * FROM $table_name WHERE is_active = 1");
            $migrated_count = 0;
            $errors = array();
            
            foreach ($employees as $employee) {
                // Check if user already exists
                $existing_user = get_user_by('email', $employee->email);
                
                if ($existing_user) {
                    // Update existing user to employee role
                    $existing_user->add_role('markethub_employees');
                    update_user_meta($existing_user->ID, 'mh_migrated_from_custom_table', true);
                    $migrated_count++;
                } else {
                    // Create new WordPress user
                    $user_id = wp_create_user(
                        sanitize_user($employee->email),
                        wp_generate_password(),
                        $employee->email
                    );
                    
                    if (!is_wp_error($user_id)) {
                        // Set user data
                        wp_update_user(array(
                            'ID' => $user_id,
                            'display_name' => $employee->name,
                            'first_name' => $employee->name,
                        ));
                        
                        // Remove customer role and add employee role
                        $user = get_user_by('ID', $user_id);
                        $user->remove_role('customer');
                        $user->add_role('markethub_employees');
                        
                        // Copy password hash
                        $wpdb->update(
                            $wpdb->users,
                            array('user_pass' => $employee->password_hash),
                            array('ID' => $user_id),
                            array('%s'),
                            array('%d')
                        );
                        
                        update_user_meta($user_id, 'mh_migrated_from_custom_table', true);
                        update_user_meta($user_id, 'mh_original_employee_id', $employee->id);
                        
                        $migrated_count++;
                    } else {
                        $errors[] = "Failed to migrate {$employee->email}: " . $user_id->get_error_message();
                    }
                }
            }
            
            echo '<div class="notice notice-success"><p>Successfully migrated ' . $migrated_count . ' employees to WordPress users.</p></div>';
            
            if (!empty($errors)) {
                echo '<div class="notice notice-warning"><p>Errors:<br>' . implode('<br>', $errors) . '</p></div>';
            }
        }
    }
    
    ?>
    <div class="wrap">
        <h1>MarketHub Employee Migration</h1>
        <p>This tool migrates employees from the custom <code>wp_mh_employees</code> table to standard WordPress users with the <strong>markethub_employees</strong> role.</p>
        
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
            <h3 style="margin-top: 0;">⚠️ Important</h3>
            <ul>
                <li>This migration can be run multiple times safely</li>
                <li>Existing users with matching emails will have the employee role added</li>
                <li>New WordPress user accounts will be created for employees that don't exist</li>
                <li>Passwords will be preserved from the custom table</li>
                <li>After migration, update the API endpoints to use WordPress authentication</li>
            </ul>
        </div>
        
        <form method="post" action="">
            <?php wp_nonce_field('mh_migrate_employees'); ?>
            <p class="submit">
                <input type="submit" name="migrate_employees" class="button button-primary button-large" 
                       value="Migrate Employees to WordPress Users" 
                       onclick="return confirm('This will migrate all active employees from the custom table to WordPress users. Continue?');">
            </p>
        </form>
        
        <hr>
        
        <h2>Current Employee Users</h2>
        <?php
        $employee_users = get_users(array('role' => 'markethub_employees'));
        if (!empty($employee_users)) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Migrated</th></tr></thead>';
            echo '<tbody>';
            foreach ($employee_users as $emp_user) {
                $migrated = get_user_meta($emp_user->ID, 'mh_migrated_from_custom_table', true);
                echo '<tr>';
                echo '<td>' . $emp_user->ID . '</td>';
                echo '<td>' . esc_html($emp_user->display_name) . '</td>';
                echo '<td>' . esc_html($emp_user->user_email) . '</td>';
                echo '<td>' . ($migrated ? '✅ Yes' : 'No') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No employee users found yet.</p>';
        }
        ?>
    </div>
    <?php
}

// Update employee login endpoint to use WordPress authentication
add_action('rest_api_init', 'markethub_register_updated_employee_routes');
function markethub_register_updated_employee_routes() {
    register_rest_route('markethub/v1', '/employee/login', array(
        'methods' => 'POST',
        'callback' => 'markethub_employee_wp_login',
        'permission_callback' => '__return_true'
    ));
}

function markethub_employee_wp_login($request) {
    $username = sanitize_text_field($request->get_param('email')); // Can use email as username
    $password = $request->get_param('password');
    
    // Use WordPress authentication
    $user = wp_authenticate($username, $password);
    
    if (is_wp_error($user)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid credentials'
        ), 401);
    }
    
    // Check if user has employee role
    if (!in_array('markethub_employees', $user->roles) && !in_array('administrator', $user->roles)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'You do not have employee permissions'
        ), 403);
    }
    
    // Generate secure token
    $token = wp_generate_password(32, false);
    $token_hash = wp_hash_password($token);
    
    update_user_meta($user->ID, '_mh_employee_token', $token_hash);
    update_user_meta($user->ID, '_mh_employee_token_expiry', time() + (24 * 60 * 60));
    
    return new WP_REST_Response(array(
        'success' => true,
        'token' => $token,
        'employee_name' => $user->display_name,
        'user_id' => $user->ID
    ), 200);
}

// ============================================
// ACTION 7: ADMIN CONFIRMATION PANEL
// ============================================

add_action('admin_menu', 'markethub_add_management_page');
function markethub_add_management_page() {
    add_menu_page(
        'MarketHub Management',
        'MarketHub Mgmt',
        'manage_options',
        'markethub-management',
        'markethub_management_page',
        'dashicons-groups',
        56
    );
}

function markethub_management_page() {
    // Handle employee confirmation
    if (isset($_POST['confirm_employee']) && check_admin_referer('mh_confirm_employee')) {
        $user_id = intval($_POST['user_id']);
        $action = sanitize_text_field($_POST['action']);
        
        $user = get_user_by('ID', $user_id);
        
        if ($user && in_array('markethub_pending', $user->roles)) {
            if ($action === 'approve') {
                // Approve employee
                $user->remove_role('markethub_pending');
                $user->add_role('markethub_employees');
                
                update_user_meta($user_id, 'mh_approved_at', current_time('mysql'));
                update_user_meta($user_id, 'mh_approved_by', wp_get_current_user()->display_name);
                delete_user_meta($user_id, 'mh_applicant_type');
                delete_user_meta($user_id, 'mh_pending_reason');
                
                // Send approval email
                $to = $user->user_email;
                $subject = 'MarketHub Employee Account Approved';
                $message = sprintf(
                    "Hello %s,\n\nYour employee account has been approved!\n\nYou can now login at:\n%s/employee-confirmation\n\nWelcome to the MarketHub team!",
                    $user->display_name,
                    home_url()
                );
                wp_mail($to, $subject, $message);
                
                echo '<div class="notice notice-success"><p>Employee approved successfully!</p></div>';
                
            } elseif ($action === 'reject') {
                // Reject and remove user
                require_once(ABSPATH . 'wp-admin/includes/user.php');
                wp_delete_user($user_id);
                
                echo '<div class="notice notice-success"><p>Employee application rejected and removed.</p></div>';
            }
        }
    }
    
    // Get pending employees
    $pending_employees = get_users(array(
        'role' => 'markethub_pending',
        'meta_key' => 'mh_applicant_type',
        'meta_value' => 'employee'
    ));
    
    // Get active employees
    $active_employees = get_users(array('role' => 'markethub_employees'));
    
    // Get active drivers
    $active_drivers = get_users(array('role' => 'markethub_drivers'));
    
    ?>
    <div class="wrap">
        <h1>MarketHub Management Panel</h1>
        
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin: 20px 0;">
            <div style="background: #fff; padding: 20px; border-left: 4px solid #2271b1; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin-top: 0;">👥 Pending Employees</h3>
                <div style="font-size: 32px; font-weight: bold; color: #2271b1;"><?php echo count($pending_employees); ?></div>
            </div>
            <div style="background: #fff; padding: 20px; border-left: 4px solid #00a32a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin-top: 0;">💼 Active Employees</h3>
                <div style="font-size: 32px; font-weight: bold; color: #00a32a;"><?php echo count($active_employees); ?></div>
            </div>
            <div style="background: #fff; padding: 20px; border-left: 4px solid #00a32a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin-top: 0;">🚗 Active Drivers</h3>
                <div style="font-size: 32px; font-weight: bold; color: #00a32a;"><?php echo count($active_drivers); ?></div>
            </div>
        </div>
        
        <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
            <h2>Pending Employee Applications</h2>
            
            <?php if (empty($pending_employees)): ?>
                <p style="color: #666; font-style: italic;">No pending employee applications.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Registered</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_employees as $employee): ?>
                            <?php
                            $registered_date = get_userdata($employee->ID)->user_registered;
                            $pending_reason = get_user_meta($employee->ID, 'mh_pending_reason', true);
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($employee->display_name); ?></strong></td>
                                <td><?php echo esc_html($employee->user_email); ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($registered_date)); ?></td>
                                <td><span style="padding: 4px 8px; background: #ffc107; color: #000; border-radius: 3px; font-size: 12px;">Pending Approval</span></td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('mh_confirm_employee'); ?>
                                        <input type="hidden" name="user_id" value="<?php echo $employee->ID; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" name="confirm_employee" class="button button-primary" style="margin-right: 5px;">✅ Approve</button>
                                    </form>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('mh_confirm_employee'); ?>
                                        <input type="hidden" name="user_id" value="<?php echo $employee->ID; ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" name="confirm_employee" class="button" onclick="return confirm('Reject and delete this application?');">❌ Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
            <h2>Active Employees</h2>
            <?php if (empty($active_employees)): ?>
                <p style="color: #666; font-style: italic;">No active employees.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Approved</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active_employees as $employee): ?>
                            <?php
                            $approved_at = get_user_meta($employee->ID, 'mh_approved_at', true);
                            $approved_by = get_user_meta($employee->ID, 'mh_approved_by', true);
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($employee->display_name); ?></strong></td>
                                <td><?php echo esc_html($employee->user_email); ?></td>
                                <td><?php echo $approved_at ? date('Y-m-d', strtotime($approved_at)) : 'N/A'; ?><?php echo $approved_by ? ' by ' . esc_html($approved_by) : ''; ?></td>
                                <td><span style="padding: 4px 8px; background: #00a32a; color: #fff; border-radius: 3px; font-size: 12px;">✅ Active</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
            <h2>Active Drivers</h2>
            <?php if (empty($active_drivers)): ?>
                <p style="color: #666; font-style: italic;">No active drivers.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Activated</th>
                            <th>Method</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active_drivers as $driver): ?>
                            <?php
                            $activated_at = get_user_meta($driver->ID, 'mh_activated_at', true);
                            $activation_method = get_user_meta($driver->ID, 'mh_activation_method', true);
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($driver->display_name); ?></strong></td>
                                <td><?php echo esc_html($driver->user_email); ?></td>
                                <td><?php echo $activated_at ? date('Y-m-d', strtotime($activated_at)) : 'N/A'; ?></td>
                                <td><?php echo $activation_method ? ucwords(str_replace('_', ' ', $activation_method)) : 'N/A'; ?></td>
                                <td><span style="padding: 4px 8px; background: #00a32a; color: #fff; border-radius: 3px; font-size: 12px;">✅ Active</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ============================================
// DISPLAY CUSTOM ROLE IN USER LIST
// ============================================

add_filter('manage_users_columns', 'markethub_add_user_role_column');
function markethub_add_user_role_column($columns) {
    $columns['markethub_role'] = 'MarketHub Role';
    return $columns;
}

add_filter('manage_users_custom_column', 'markethub_show_user_role_column', 10, 3);
function markethub_show_user_role_column($value, $column_name, $user_id) {
    if ($column_name === 'markethub_role') {
        $user = get_userdata($user_id);
        
        if (in_array('markethub_drivers', $user->roles)) {
            return '<span style="padding: 4px 8px; background: #00a32a; color: #fff; border-radius: 3px; font-size: 11px;">🚗 Driver</span>';
        } elseif (in_array('markethub_employees', $user->roles)) {
            return '<span style="padding: 4px 8px; background: #2271b1; color: #fff; border-radius: 3px; font-size: 11px;">💼 Employee</span>';
        } elseif (in_array('markethub_pending', $user->roles)) {
            $applicant_type = get_user_meta($user_id, 'mh_applicant_type', true);
            return '<span style="padding: 4px 8px; background: #ffc107; color: #000; border-radius: 3px; font-size: 11px;">⏳ Pending (' . ucfirst($applicant_type) . ')</span>';
        }
    }
    return $value;
}
