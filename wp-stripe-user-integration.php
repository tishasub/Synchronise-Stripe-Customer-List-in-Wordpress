<?php
/**
 * Plugin Name: WordPress Stripe User Integration
 * Description: Fetches Stripe customer information and updates WordPress user meta. Automatically syncs new WordPress users with Stripe.
 * Version: 1.1
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Load Composer's autoloader
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

// Set your Stripe API key
\Stripe\Stripe::setApiKey('you_api_key_here');


// Function to fetch Stripe customer information
function fetch_stripe_customer_id($email) {
    try {
        $customers = \Stripe\Customer::all(['email' => $email, 'limit' => 1]);
        if (!empty($customers->data)) {
            return $customers->data[0]->id;
        }
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('Stripe API Error: ' . $e->getMessage());
    }
    return null;
}


// Function to update WordPress user meta with Stripe customer ID
function update_user_stripe_id($user_id, $stripe_customer_id) {
    update_user_meta($user_id, '_stripe_customer_id', $stripe_customer_id);
}

// Function to process all users
function process_all_users() {
    $users = get_users(['fields' => ['ID', 'user_email']]);
    foreach ($users as $user) {
        $stripe_customer_id = get_user_meta($user->ID, '_stripe_customer_id', true);
        if (empty($stripe_customer_id)) {
            $stripe_customer_id = fetch_stripe_customer_id($user->user_email);
            if ($stripe_customer_id) {
                update_user_stripe_id($user->ID, $stripe_customer_id);
            }
        }
    }
}

// Schedule cron job
function schedule_stripe_user_sync() {
    if (!wp_next_scheduled('stripe_user_sync_event')) {
        wp_schedule_event(time(), 'daily', 'stripe_user_sync_event');
    }
}
add_action('wp', 'schedule_stripe_user_sync');

// Hook for cron job
add_action('stripe_user_sync_event', 'process_all_users');

// Hook for new user registration - sync with Stripe immediately
add_action('user_register', 'sync_new_user_with_stripe', 10, 1);

// Function to sync a new WordPress user with Stripe
function sync_new_user_with_stripe($user_id) {
    // Get user data
    $user = get_userdata($user_id);

    if (!$user || empty($user->user_email)) {
        return;
    }

    // First check if a Stripe customer already exists with this email
    $stripe_customer_id = fetch_stripe_customer_id($user->user_email);

    if ($stripe_customer_id) {
        // Stripe customer exists, just update user meta
        update_user_stripe_id($user_id, $stripe_customer_id);
        error_log("Synced new user (ID: $user_id, Email: {$user->user_email}) with existing Stripe customer: $stripe_customer_id");
    }
}

// Hook for user profile updates - sync with Stripe if email changes
add_action('profile_update', 'sync_updated_user_with_stripe', 10, 2);

// Function to sync an updated WordPress user with Stripe
function sync_updated_user_with_stripe($user_id, $old_user_data) {
    $user = get_userdata($user_id);

    if (!$user || empty($user->user_email)) {
        return;
    }

    // Check if email was changed
    if ($user->user_email !== $old_user_data->user_email) {
        // Email changed, look for Stripe customer with new email
        $stripe_customer_id = fetch_stripe_customer_id($user->user_email);

        if ($stripe_customer_id) {
            // Stripe customer exists with new email, update user meta
            update_user_stripe_id($user_id, $stripe_customer_id);
            error_log("Synced updated user (ID: $user_id, New Email: {$user->user_email}) with Stripe customer: $stripe_customer_id");
        } else {
            // No Stripe customer with new email, remove old Stripe ID if any
            delete_user_meta($user_id, '_stripe_customer_id');
            error_log("Removed Stripe customer ID for user (ID: $user_id) after email change to {$user->user_email}");
        }
    }
}

// Function to process a single user by email
function process_single_user_by_email($email) {
    // Validate email
    if (!is_email($email)) {
        return [
            'success' => false,
            'message' => 'Invalid email address'
        ];
    }

    // Look up WordPress user by email
    $user = get_user_by('email', $email);
    if (!$user) {
        return [
            'success' => false,
            'message' => 'WordPress user not found with this email'
        ];
    }

    // Check if user already has a Stripe ID
    $existing_stripe_id = get_user_meta($user->ID, '_stripe_customer_id', true);
    if (!empty($existing_stripe_id)) {
        return [
            'success' => true,
            'user_id' => $user->ID,
            'email' => $email,
            'stripe_customer_id' => $existing_stripe_id,
            'message' => 'Existing Stripe customer ID found'
        ];
    }

    // Fetch Stripe customer ID
    $stripe_customer_id = fetch_stripe_customer_id($email);

    if ($stripe_customer_id) {
        // Update user meta with the Stripe customer ID
        update_user_stripe_id($user->ID, $stripe_customer_id);

        return [
            'success' => true,
            'user_id' => $user->ID,
            'email' => $email,
            'stripe_customer_id' => $stripe_customer_id,
            'message' => 'Stripe customer ID found and saved'
        ];
    } else {
        return [
            'success' => false,
            'user_id' => $user->ID,
            'email' => $email,
            'message' => 'No Stripe customer found with this email'
        ];
    }
}

// Add the form and processing to admin page
function add_single_user_lookup_to_admin_page() {
    $result = null;

    if (isset($_POST['lookup_single_user']) && isset($_POST['user_email'])) {
        $email = sanitize_email($_POST['user_email']);
        $result = process_single_user_by_email($email);
    }

    ?>
    <div class="postbox">
        <h2 class="hndle">Look Up Single User</h2>
        <div class="inside">
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="user_email">User Email</label></th>
                        <td>
                            <input type="email" name="user_email" id="user_email" class="regular-text" required>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Look Up User', 'secondary', 'lookup_single_user'); ?>
            </form>

            <?php if ($result): ?>
                <div class="notice <?php echo $result['success'] ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                    <p><strong><?php echo esc_html($result['message']); ?></strong></p>
                    <?php if ($result['success']): ?>
                        <table class="widefat striped" style="margin-top: 10px;">
                            <tr>
                                <th>User ID</th>
                                <td><?php echo esc_html($result['user_id']); ?></td>
                            </tr>
                            <tr>
                                <th>Email</th>
                                <td><?php echo esc_html($result['email']); ?></td>
                            </tr>
                            <tr>
                                <th>Stripe Customer ID</th>
                                <td><?php echo esc_html($result['stripe_customer_id']); ?></td>
                            </tr>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
// Add admin menu
// Function to process multiple users by email
function process_multiple_users_by_email($emails) {
    $results = [];

    // Handle both comma-separated string and array inputs
    if (is_string($emails)) {
        $emails = array_map('trim', explode(',', $emails));
    }

    foreach ($emails as $email) {
        // Skip empty emails
        if (empty($email)) {
            continue;
        }

        // Validate email
        if (!is_email($email)) {
            $results[] = [
                'success' => false,
                'email' => $email,
                'message' => 'Invalid email address'
            ];
            continue;
        }

        // Look up WordPress user by email
        $user = get_user_by('email', $email);
        if (!$user) {
            $results[] = [
                'success' => false,
                'email' => $email,
                'message' => 'WordPress user not found with this email'
            ];
            continue;
        }

        // Check if user already has a Stripe ID
        $existing_stripe_id = get_user_meta($user->ID, '_stripe_customer_id', true);
        if (!empty($existing_stripe_id)) {
            $results[] = [
                'success' => true,
                'user_id' => $user->ID,
                'email' => $email,
                'stripe_customer_id' => $existing_stripe_id,
                'message' => 'Existing Stripe customer ID found'
            ];
            continue;
        }

        // Fetch Stripe customer ID
        $stripe_customer_id = fetch_stripe_customer_id($email);

        if ($stripe_customer_id) {
            // Update user meta with the Stripe customer ID
            update_user_stripe_id($user->ID, $stripe_customer_id);

            $results[] = [
                'success' => true,
                'user_id' => $user->ID,
                'email' => $email,
                'stripe_customer_id' => $stripe_customer_id,
                'message' => 'Stripe customer ID found and saved'
            ];
        } else {
            $results[] = [
                'success' => false,
                'user_id' => $user->ID,
                'email' => $email,
                'message' => 'No Stripe customer found with this email'
            ];
        }
    }

    return $results;
}

// Add the form and processing for multiple emails to admin page
function add_multiple_user_lookup_to_admin_page() {
    $results = null;

    if (isset($_POST['lookup_multiple_users']) && isset($_POST['user_emails'])) {
        $emails_input = sanitize_textarea_field($_POST['user_emails']);
        $emails = array_map('trim', explode("\n", $emails_input));
        $results = process_multiple_users_by_email($emails);
    }

    ?>
    <div class="postbox">
        <h2 class="hndle">Look Up Multiple Users</h2>
        <div class="inside">
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="user_emails">User Emails</label></th>
                        <td>
                            <textarea name="user_emails" id="user_emails" class="large-text" rows="5" placeholder="Enter one email address per line" required></textarea>
                            <p class="description">Enter one email address per line</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Look Up Users', 'secondary', 'lookup_multiple_users'); ?>
            </form>

            <?php if ($results): ?>
                <h3>Results</h3>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th>Email</th>
                        <th>User ID</th>
                        <th>Stripe Customer ID</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $result): ?>
                        <tr>
                            <td><?php echo esc_html($result['email']); ?></td>
                            <td><?php echo isset($result['user_id']) ? esc_html($result['user_id']) : 'N/A'; ?></td>
                            <td><?php echo isset($result['stripe_customer_id']) ? esc_html($result['stripe_customer_id']) : 'N/A'; ?></td>
                            <td>
                                    <span class="<?php echo $result['success'] ? 'dashicons dashicons-yes' : 'dashicons dashicons-no'; ?>"
                                          style="color: <?php echo $result['success'] ? 'green' : 'red'; ?>;">
                                    </span>
                                <?php echo esc_html($result['message']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
function stripe_user_sync_menu() {
    add_menu_page('Stripe User Sync', 'Stripe User Sync', 'manage_options', 'stripe-user-sync', 'stripe_user_sync_page');
    add_submenu_page('stripe-user-sync', 'Stripe Users List', 'Stripe Users List', 'manage_options', 'stripe-users-list', 'stripe_users_list_page');
}
add_action('admin_menu', 'stripe_user_sync_menu');

// Admin page content
function stripe_user_sync_page() {
    if (isset($_POST['sync_users'])) {
        process_all_users();
        echo '<div class="updated"><p>User synchronization completed.</p></div>';
    }

    // Add option to sync recently created users
    if (isset($_POST['sync_recent_users'])) {
        $count = sync_recent_users();
        echo '<div class="updated"><p>Synchronized ' . $count . ' recently created users.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Stripe User Sync</h1>
        <div class="postbox-container" style="width: 100%;">
            <div class="postbox">
                <h2 class="hndle">Bulk Synchronization</h2>
                <div class="inside">
                    <form method="post">
                        <?php submit_button('Sync All Users with Stripe', 'primary', 'sync_users'); ?>
                    </form>

                    <form method="post" style="margin-top: 20px;">
                        <p>Only sync users created in the past 7 days:</p>
                        <?php submit_button('Sync Recent Users', 'secondary', 'sync_recent_users'); ?>
                    </form>
                </div>
            </div>

            <?php add_single_user_lookup_to_admin_page(); ?>
            <?php add_multiple_user_lookup_to_admin_page(); ?>
        </div>
    </div>
    <?php
}

// Function to sync recently created users (within last 7 days)
function sync_recent_users() {
    $args = array(
        'date_query' => array(
            array(
                'after'     => '1 week ago',
                'inclusive' => true,
            ),
        ),
        'fields' => array('ID', 'user_email'),
    );

    $recent_users = get_users($args);
    $count = 0;

    foreach ($recent_users as $user) {
        $stripe_customer_id = get_user_meta($user->ID, '_stripe_customer_id', true);
        if (empty($stripe_customer_id)) {
            $stripe_customer_id = fetch_stripe_customer_id($user->user_email);
            if ($stripe_customer_id) {
                update_user_stripe_id($user->ID, $stripe_customer_id);
                $count++;
            }
        }
    }

    return $count;
}

// Stripe Users List page
function stripe_users_list_page() {
    $users_per_page = 30;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $users_per_page;

    $args = [
        'meta_key' => '_stripe_customer_id',
        'meta_compare' => '!=',
        'meta_value' => '',
        'number' => $users_per_page,
        'offset' => $offset,
    ];

    $users = get_users($args);
    $total_users = count(get_users([
        'meta_key' => '_stripe_customer_id',
        'meta_compare' => '!=',
        'meta_value' => '',
        'fields' => 'ID',
    ]));

    $total_pages = ceil($total_users / $users_per_page);

    // Process resync if requested
    if (isset($_GET['resync']) && isset($_GET['user_id'])) {
        $user_id = intval($_GET['user_id']);
        $user = get_userdata($user_id);

        if ($user && !empty($user->user_email)) {
            $stripe_customer_id = fetch_stripe_customer_id($user->user_email);
            if ($stripe_customer_id) {
                update_user_stripe_id($user_id, $stripe_customer_id);
                echo '<div class="updated"><p>User ' . esc_html($user->user_email) . ' resynced with Stripe.</p></div>';
            } else {
                echo '<div class="error"><p>No Stripe customer found for ' . esc_html($user->user_email) . '.</p></div>';
            }
        }
    }

    // Show user status - with or without Stripe ID
    $sync_status = isset($_GET['sync_status']) ? $_GET['sync_status'] : 'with_stripe';

    ?>
    <div class="wrap">
        <h1>Users with Stripe Customer ID</h1>

        <ul class="subsubsub">
            <li>
                <a href="<?php echo admin_url('admin.php?page=stripe-users-list&sync_status=with_stripe'); ?>"
                   class="<?php echo $sync_status === 'with_stripe' ? 'current' : ''; ?>">
                    With Stripe ID
                    <span class="count">(<?php echo $total_users; ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo admin_url('admin.php?page=stripe-users-list&sync_status=without_stripe'); ?>"
                   class="<?php echo $sync_status === 'without_stripe' ? 'current' : ''; ?>">
                    Without Stripe ID
                </a>
            </li>
        </ul>

        <?php
        // If viewing users without Stripe ID
        if ($sync_status === 'without_stripe') {
            $missing_users_args = [
                'meta_query' => [
                    [
                        'key' => '_stripe_customer_id',
                        'compare' => 'NOT EXISTS'
                    ]
                ],
                'number' => $users_per_page,
                'offset' => $offset,
            ];

            $users = get_users($missing_users_args);
            $total_missing = count(get_users([
                'meta_query' => [
                    [
                        'key' => '_stripe_customer_id',
                        'compare' => 'NOT EXISTS'
                    ]
                ],
                'fields' => 'ID',
            ]));

            $total_pages = ceil($total_missing / $users_per_page);

            echo '<p>Showing users without Stripe Customer IDs. Total: ' . $total_missing . '</p>';
        }
        ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
            <tr>
                <th>User ID</th>
                <th>Username</th>
                <th>Email</th>
                <th>Registration Date</th>
                <?php if ($sync_status === 'with_stripe'): ?>
                    <th>Stripe Customer ID</th>
                <?php endif; ?>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            foreach ($users as $user) {
                $stripe_customer_id = get_user_meta($user->ID, '_stripe_customer_id', true);
                $registered = get_the_author_meta('registered', $user->ID);
                $registered_date = $registered ? date('Y-m-d', strtotime($registered)) : 'Unknown';

                echo "<tr>
                        <td>{$user->ID}</td>
                        <td>{$user->user_login}</td>
                        <td>{$user->user_email}</td>
                        <td>{$registered_date}</td>";

                if ($sync_status === 'with_stripe') {
                    echo "<td>{$stripe_customer_id}</td>";
                }

                echo "<td>
                        <a href='" . admin_url('admin.php?page=stripe-users-list&resync=1&user_id=' . $user->ID) . "'>Resync with Stripe</a>
                      </td>
                    </tr>";
            }
            ?>
            </tbody>
        </table>

        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => $total_pages,
                    'current' => $current_page,
                ]);
                ?>
            </div>
        </div>
    </div>
    <?php
}

// New functions for WooCommerce integration
function get_user_stripe_payment_methods($user_id) {
    $stripe_customer_id = get_user_meta($user_id, '_stripe_customer_id', true);

    if (empty($stripe_customer_id)) {
        return array();
    }

    try {
        \Stripe\Stripe::setApiKey('sk_live_Hlz0b337Odul23jA5CUukQmc');
        $payment_methods = \Stripe\PaymentMethod::all([
            'customer' => $stripe_customer_id,
            'type' => 'card',
        ]);

        return $payment_methods->data;
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('Stripe API Error: ' . $e->getMessage());
        return array();
    }
}


add_action('woocommerce_review_order_before_payment', 'add_saved_payment_methods_to_checkout');

function add_saved_payment_methods_to_checkout() {
    if (!is_user_logged_in()) {
        return;
    }

    $user_id = get_current_user_id();
    $payment_methods = get_user_stripe_payment_methods($user_id);

    if (empty($payment_methods)) {
        return;
    }

    echo '<div id="saved-payment-methods">';
    echo '<h3>Your saved cards</h3>';
    echo '<ul>';
    foreach ($payment_methods as $method) {
        $card = $method->card;
        echo '<li>';
        echo '<label>';
        echo '<input type="radio" name="saved_payment_method" value="' . esc_attr($method->id) . '">';
        echo sprintf(
            '%s ending in %s (expires %s/%s)',
            $card->brand,
            $card->last4,
            $card->exp_month,
            $card->exp_year
        );
        echo '</label>';
        echo '</li>';
    }
    echo '</ul>';
    echo '</div>';
}

add_action('wp_footer', 'hide_cc_form_if_saved_method_selected');

function hide_cc_form_if_saved_method_selected() {
    if (!is_checkout()) {
        return;
    }
    ?>
    <script type="text/javascript">
        jQuery(function($) {
            $('input[name="saved_payment_method"]').on('change', function() {
                if ($(this).is(':checked')) {
                    $('.wc-stripe-elements-field').hide();
                } else {
                    $('.wc-stripe-elements-field').show();
                }
            });
        });
    </script>
    <?php
}

// Hook into all user registration methods
add_action('user_register', 'sync_new_user_with_stripe', 10);
add_action('woocommerce_created_customer', 'sync_new_user_with_stripe', 10);
add_action('woocommerce_new_customer', 'sync_new_user_with_stripe', 10);

// Deactivation hook to remove scheduled event
register_deactivation_hook(__FILE__, 'deactivate_stripe_user_sync');
function deactivate_stripe_user_sync() {
    wp_clear_scheduled_hook('stripe_user_sync_event');
}
