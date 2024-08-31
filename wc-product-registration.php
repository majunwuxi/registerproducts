<?php
/**
 * Plugin Name: WooCommerce Product Registration
 * Plugin URI: https://invawise.com/register-your-products/
 * Description: 通过产品序列号来验证产品/Verification of products by serial number
 * Version: 1.4.0
 * Author: Ma Jun
 * Author URI: majun0723@gmail.com
 * Text Domain: wc-product-registration
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */
/*
 * Copyright (C) 2024 Ma Jun
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
defined('ABSPATH') or die('Direct access not allowed');

// Error logging function
function wc_product_registration_log($message, $type = 'debug') {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log("WC Product Registration {$type}: {$message}");
    }
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . __('WooCommerce Product Registration requires WooCommerce to be installed and activated.', 'wc-product-registration') . '</p></div>';
    });
    return;
}

class WC_Product_Registration {
    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('plugins_loaded', array($this, 'init'));
    }

    public function activate() {
        $this->create_tables();
        wc_product_registration_log('Plugin activated successfully');
    }

    public function init() {
        add_shortcode('product_registration_form', array($this, 'registration_form_shortcode'));
        add_shortcode('user_registered_products', array($this, 'user_registered_products_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_validate_serial', array($this, 'ajax_validate_serial'));
        add_action('wp_ajax_nopriv_validate_serial', array($this, 'ajax_validate_serial'));
        add_action('wp_ajax_register_product', array($this, 'ajax_register_product'));
        add_action('wp_ajax_nopriv_register_product', array($this, 'ajax_register_product'));
        add_action('admin_post_delete_serial', array($this, 'delete_serial'));
        add_action('admin_post_edit_serial', array($this, 'edit_serial'));
        wc_product_registration_log('Plugin initialized successfully');
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'product_registrations';

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            product_id bigint(20) NOT NULL,
            serial_number varchar(255) NOT NULL,
            registration_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            purchase_proof varchar(255) DEFAULT '' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY serial_number (serial_number)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        if ($wpdb->last_error) {
            wc_product_registration_log("Error creating table: {$wpdb->last_error}", 'error');
        } else {
            wc_product_registration_log('Database table created successfully');
        }
    }

    public function registration_form_shortcode() {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to register your product.', 'wc-product-registration') . '</p>';
        }

        ob_start();
        ?>
        <form id="product-registration-form" enctype="multipart/form-data">
            <?php wp_nonce_field('wc_product_registration', 'wc_product_registration_nonce'); ?>
            <label for="serial_number"><?php _e('Serial Number:', 'wc-product-registration'); ?></label>
            <input type="text" id="serial_number" name="serial_number" required>
            <label for="purchase_proof"><?php _e('Upload Purchase Proof (PDF, JPG, PNG only):', 'wc-product-registration'); ?></label>
            <input type="file" id="purchase_proof" name="purchase_proof" accept=".pdf,.jpg,.jpeg,.png" required>
            <button type="submit"><?php _e('Register Product', 'wc-product-registration'); ?></button>
        </form>
        <div id="registration-result"></div>
        <?php
        return ob_get_clean();
    }

    public function user_registered_products_shortcode() {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to view your registered products.', 'wc-product-registration') . '</p>';
        }

        $user_id = get_current_user_id();
        $registrations = $this->get_user_registered_products($user_id);

        ob_start();
        ?>
        <div class="user-registered-products">
            <h2><?php _e('Your Registered Products', 'wc-product-registration'); ?></h2>
            <?php if (!empty($registrations)): ?>
                <table class="registered-products-table">
                    <thead>
                        <tr>
                            <th><?php _e('Product Name', 'wc-product-registration'); ?></th>
                            <th><?php _e('Serial Number', 'wc-product-registration'); ?></th>
                            <th><?php _e('Registration Date', 'wc-product-registration'); ?></th>
                            <th><?php _e('Purchase Proof', 'wc-product-registration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registrations as $registration): ?>
                            <tr>
                                <td><?php echo esc_html($registration->product_name); ?></td>
                                <td><?php echo esc_html($registration->serial_number); ?></td>
                                <td><?php echo esc_html($registration->registration_date); ?></td>
                                <td>
                                    <?php if (!empty($registration->purchase_proof)): ?>
                                        <a href="<?php echo esc_url(wp_get_attachment_url($registration->purchase_proof)); ?>" target="_blank"><?php _e('View', 'wc-product-registration'); ?></a>
                                    <?php else: ?>
                                        <?php _e('N/A', 'wc-product-registration'); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php _e('You have no registered products.', 'wc-product-registration'); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function get_user_registered_products($user_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'product_registrations';
        $query = $wpdb->prepare(
            "SELECT pr.*, p.post_title as product_name 
            FROM $table_name pr
            LEFT JOIN {$wpdb->posts} p ON pr.product_id = p.ID
            WHERE pr.user_id = %d
            ORDER BY pr.registration_date DESC",
            $user_id
        );
        return $wpdb->get_results($query);
    }

    public function enqueue_scripts() {
        wp_enqueue_script('wc-product-registration', plugin_dir_url(__FILE__) . 'js/wc-product-registration.js', array('jquery'), '1.4.0', true);
        wp_localize_script('wc-product-registration', 'wc_product_registration', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc-product-registration-nonce')
        ));
        wp_enqueue_style('wc-product-registration', plugin_dir_url(__FILE__) . 'css/wc-product-registration.css', array(), '1.4.0');
        wc_product_registration_log('Scripts and styles enqueued');
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Product Registrations', 'wc-product-registration'),
            __('Product Registrations', 'wc-product-registration'),
            'manage_options',
            'wc-product-registrations',
            array($this, 'admin_page'),
            'dashicons-products',
            56
        );
        wc_product_registration_log('Admin menu added');
    }

    public function admin_page() {
        wc_product_registration_log('Admin page function called');

        ?>
        <div class="wrap">
            <h1><?php _e('Product Registrations', 'wc-product-registration'); ?></h1>
            <h2><?php _e('Registered Products', 'wc-product-registration'); ?></h2>
            <?php
            $registrations = $this->get_registrations();
            if (!empty($registrations)) {
                echo '<table class="wp-list-table widefat fixed striped">';
                echo '<thead><tr><th>User</th><th>Product</th><th>Serial Number</th><th>Registration Date</th><th>Purchase Proof</th><th>Actions</th></tr></thead>';
                echo '<tbody>';
                foreach ($registrations as $registration) {
                    echo '<tr>';
                    echo '<td>' . esc_html(get_userdata($registration->user_id)->user_login) . '</td>';
                    echo '<td>' . esc_html(get_the_title($registration->product_id)) . '</td>';
                    echo '<td>' . esc_html($registration->serial_number) . '</td>';
                    echo '<td>' . esc_html($registration->registration_date) . '</td>';
                    echo '<td>' . (empty($registration->purchase_proof) ? 'N/A' : '<a href="' . esc_url(wp_get_attachment_url($registration->purchase_proof)) . '" target="_blank">View</a>') . '</td>';
                    echo '<td>';
                    echo '<a href="' . admin_url('admin-post.php?action=edit_serial&id=' . $registration->id) . '">Edit</a> | ';
                    echo '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=delete_serial&id=' . $registration->id), 'delete_serial_' . $registration->id) . '" onclick="return confirm(\'' . __('Are you sure you want to delete this registration?', 'wc-product-registration') . '\')">Delete</a>';
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p>' . __('No product registrations found.', 'wc-product-registration') . '</p>';
            }
            ?>
            <h2><?php _e('Import Serial Numbers', 'wc-product-registration'); ?></h2>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('import_serial_numbers', 'import_serial_numbers_nonce'); ?>
                <input type="file" name="serial_numbers_file" accept=".xls,.xlsx,.csv">
                <input type="submit" name="import_serial_numbers" value="<?php _e('Import', 'wc-product-registration'); ?>" class="button button-primary">
            </form>
            <?php
            if (isset($_POST['import_serial_numbers']) && check_admin_referer('import_serial_numbers', 'import_serial_numbers_nonce')) {
                $this->import_serial_numbers();
            }
            ?>
        </div>
        <?php
        wc_product_registration_log('Admin page rendered');
    }

    private function get_registrations() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'product_registrations';
        $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY registration_date DESC");
        wc_product_registration_log('Retrieved ' . count($results) . ' registrations');
        return $results;
    }

    public function ajax_validate_serial() {
        check_ajax_referer('wc-product-registration-nonce', 'nonce');

        $serial_number = sanitize_text_field($_POST['serial_number']);
        wc_product_registration_log("Validating serial number: {$serial_number}");

        global $wpdb;
        $table_name = $wpdb->prefix . 'product_registrations';
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE serial_number = %s", $serial_number));

        if ($result) {
            if ($result->user_id != 0) {
                wc_product_registration_log('Serial number already registered');
                wp_send_json_error(array('message' => __('This serial number has already been registered.', 'wc-product-registration')));
                return;
            }

            $product = wc_get_product($result->product_id);
            if ($product) {
                wc_product_registration_log('Valid serial number, product found');
                wp_send_json_success(array(
                    'product' => array(
                        'id' => $product->get_id(),
                        'name' => $product->get_name(),
                        'image' => wp_get_attachment_url($product->get_image_id()),
                        'url' => $product->get_permalink()
                    )
                ));
            } else {
                wc_product_registration_log('Valid serial number, but product not found');
                wp_send_json_error(array('message' => __('Product not found.', 'wc-product-registration')));
            }
        } else {
            wc_product_registration_log('Invalid serial number');
            wp_send_json_error(array('message' => __('Invalid serial number.', 'wc-product-registration')));
        }
    }

    public function ajax_register_product() {
        check_ajax_referer('wc-product-registration-nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in to register a product.', 'wc-product-registration')));
            return;
        }

        $serial_number = sanitize_text_field($_POST['serial_number']);
        $product_id = intval($_POST['product_id']);
        $user_id = get_current_user_id();

        wc_product_registration_log("Registering product: {$serial_number} for user: {$user_id}");

        // Handle file upload
        $purchase_proof_id = 0;
        if (!empty($_FILES['purchase_proof'])) {
            $upload = wp_handle_upload($_FILES['purchase_proof'], array('test_form' => false));
            if (!empty($upload['file'])) {
                $filetype = wp_check_filetype(basename($upload['file']), null);
                $attachment = array(
                    'post_mime_type' => $filetype['type'],
                    'post_title' => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
                    'post_content' => '',
                    'post_status' => 'inherit'
                );
                $purchase_proof_id = wp_insert_attachment($attachment, $upload['file']);
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $attach_data = wp_generate_attachment_metadata($purchase_proof_id, $upload['file']);
                wp_update_attachment_metadata($purchase_proof_id, $attach_data);
                wc_product_registration_log("Purchase proof uploaded: {$purchase_proof_id}");
            } else {
                wc_product_registration_log('Failed to upload purchase proof', 'error');
            }
        } else {
            wc_product_registration_log('No purchase proof file provided', 'error');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'product_registrations';

        // Check if the serial number has already been registered
        $existing_registration = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE serial_number = %s", $serial_number));

        if ($existing_registration && $existing_registration->user_id != 0) {
            wc_product_registration_log('Serial number already registered');
            wp_send_json_error(array('message' => __('This serial number has already been registered.', 'wc-product-registration')));
            return;
        }

        $result = $wpdb->update(
            $table_name,
            array(
                'user_id' => $user_id,
                'registration_date' => current_time('mysql'),
                'purchase_proof' => $purchase_proof_id
            ),
            array('serial_number' => $serial_number, 'product_id' => $product_id),
            array('%d', '%s', '%d'),
            array('%s', '%d')
        );

        if ($result) {
            wc_product_registration_log('Product registered successfully');
            $this->send_confirmation_emails($user_id, $product_id, $serial_number);
            wp_send_json_success(array('message' => __('Product successfully registered.', 'wc-product-registration')));
        } else {
            wc_product_registration_log('Failed to register product', 'error');
            wp_send_json_error(array('message' => __('Failed to register product.', 'wc-product-registration')));
        }
    }

    private function send_confirmation_emails($user_id, $product_id, $serial_number) {
        $user = get_userdata($user_id);
        $product = wc_get_product($product_id);
        $admin_email = get_option('admin_email');

        // Customer email
        $customer_subject = sprintf(__('Your product %s has been registered', 'wc-product-registration'), $product->get_name());
        $customer_message = sprintf(__("Thank you for registering your product. Here are the details:\n\nProduct: %s\nSerial Number: %s", 'wc-product-registration'), $product->get_name(), $serial_number);
        wp_mail($user->user_email, $customer_subject, $customer_message);

        // Admin email
        $admin_subject = sprintf(__('New product registration: %s', 'wc-product-registration'), $product->get_name());
        $admin_message = sprintf(__("A new product has been registered:\n\nUser: %s\nProduct: %s\nSerial Number: %s", 'wc-product-registration'), $user->user_login, $product->get_name(), $serial_number);
        wp_mail($admin_email, $admin_subject, $admin_message);

        wc_product_registration_log('Confirmation emails sent');
    }

    public function delete_serial() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $id = intval($_GET['id']);
        if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_serial_' . $id)) {
            wp_die(__('Invalid nonce.'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'product_registrations';
        $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));

        if ($result) {
            wc_product_registration_log("Serial number deleted: {$id}");
        } else {
            wc_product_registration_log("Failed to delete serial number: {$id}", 'error');
        }

        wp_redirect(admin_url('admin.php?page=wc-product-registrations'));
        exit;
    }

    public function edit_serial() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $id = intval($_GET['id']);

        global $wpdb;
        $table_name = $wpdb->prefix . 'product_registrations';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && wp_verify_nonce($_POST['edit_serial_nonce'], 'edit_serial_' . $id)) {
            $serial_number = sanitize_text_field($_POST['serial_number']);
            $product_id = intval($_POST['product_id']);

            $result = $wpdb->update(
                $table_name,
                array('serial_number' => $serial_number, 'product_id' => $product_id),
                array('id' => $id),
                array('%s', '%d'),
                array('%d')
            );

            if ($result) {
                wc_product_registration_log("Serial number updated: {$id}");
            } else {
                wc_product_registration_log("Failed to update serial number: {$id}", 'error');
            }

            wp_redirect(admin_url('admin.php?page=wc-product-registrations'));
            exit;
        }

        $registration = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));

        ?>
        <div class="wrap">
            <h1><?php _e('Edit Serial Number', 'wc-product-registration'); ?></h1>
            <form method="post">
                <?php wp_nonce_field('edit_serial_' . $id, 'edit_serial_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="serial_number"><?php _e('Serial Number', 'wc-product-registration'); ?></label></th>
                        <td><input name="serial_number" id="serial_number" type="text" value="<?php echo esc_attr($registration->serial_number); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="product_id"><?php _e('Product ID', 'wc-product-registration'); ?></label></th>
                        <td><input name="product_id" id="product_id" type="number" value="<?php echo esc_attr($registration->product_id); ?>" class="regular-text"></td>
                    </tr>
                </table>
                <p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Update Serial Number', 'wc-product-registration'); ?>"></p>
            </form>
        </div>
        <?php
    }

    public function import_serial_numbers() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        if (!isset($_FILES['serial_numbers_file'])) {
            wc_product_registration_log('No file uploaded for import', 'error');
            return;
        }

        $file = $_FILES['serial_numbers_file'];
        $file_path = $file['tmp_name'];

        if (!file_exists($file_path)) {
            wc_product_registration_log('Uploaded file not found', 'error');
            return;
        }

        $file_type = wp_check_filetype(basename($file['name']), null);
        $supported_types = array('xls', 'xlsx', 'csv');

        if (!in_array($file_type['ext'], $supported_types)) {
            wc_product_registration_log('Unsupported file type', 'error');
            return;
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        WP_Filesystem();
        global $wp_filesystem;

        $content = $wp_filesystem->get_contents($file_path);
        $rows = explode("\n", $content);

        $imported_count = 0;
        foreach ($rows as $row) {
            $data = str_getcsv($row);
            if (count($data) == 2) {
                $serial_number = sanitize_text_field(trim($data[0]));
                $product_id = intval(trim($data[1]));

                if ($this->add_serial_number($serial_number, $product_id)) {
                    $imported_count++;
                }
            }
        }

        wc_product_registration_log("Imported {$imported_count} serial numbers");
        echo '<div class="updated"><p>' . sprintf(__('Successfully imported %d serial numbers.', 'wc-product-registration'), $imported_count) . '</p></div>';
    }

    private function add_serial_number($serial_number, $product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'product_registrations';

        $result = $wpdb->insert(
            $table_name,
            array(
                'serial_number' => $serial_number,
                'product_id' => $product_id,
                'user_id' => 0,
                'registration_date' => current_time('mysql')
            ),
            array('%s', '%d', '%d', '%s')
        );

        if ($result) {
            wc_product_registration_log("Added serial number: {$serial_number}");
            return true;
        } else {
            wc_product_registration_log("Failed to add serial number: {$serial_number}", 'error');
            return false;
        }
    }
}

new WC_Product_Registration();