<?php 
/*
Plugin Name: Codewing Contact Form
Plugin URI: https://codewing.com
Description: A simple contact form plugin for WordPress.
Version: 1.0
Author: Codewing
Author URI: https://codewing.com
*/

function codewing_contact_form_enqueue_styles() {
    wp_enqueue_style('codewing-contact-form-styles', plugins_url('css/style.css', __FILE__));
}
add_action('wp_enqueue_scripts', 'codewing_contact_form_enqueue_styles');

function codewing_contact_form_enqueue_scripts() {
    wp_enqueue_script('jquery');
    wp_enqueue_script(
        'codewing-contact-form-script', 
        plugins_url('js/form-handler.js', __FILE__), 
        array('jquery'), 
        '1.0.0', 
        true
    );
    
    wp_localize_script('codewing-contact-form-script', 'ajax_object', array(
        'ajax_url' => admin_url('admin-ajax.php')
    ));
}
add_action('wp_enqueue_scripts', 'codewing_contact_form_enqueue_scripts');

function codewing_contact_form_shortcode() {
    ob_start(); ?>
    <div class="codewing-form-wrapper">
        <div class="codewing-form-message" style="display: none;"></div>
        <form class="codewing-contact-form" method="post">
            <?php wp_nonce_field('codewing_contact_form_nonce', 'contact_form_nonce'); ?>
            
            <div class="codewing-form-group">
                <input type="text" name="name" placeholder="<?php esc_attr_e('Name', 'codewing-contact-form'); ?>" required>
            </div>
            <div class="codewing-form-group">
                <input type="email" name="email" placeholder="<?php esc_attr_e('Email', 'codewing-contact-form'); ?>" required>
            </div>
            <div class="codewing-form-group">
                <textarea name="message" placeholder="<?php esc_attr_e('Message', 'codewing-contact-form'); ?>" required></textarea>
            </div>
            <div class="codewing-form-group">
                <button type="submit" class="codewing-submit-btn"><?php esc_html_e('Submit', 'codewing-contact-form'); ?></button>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('codewing_contact', 'codewing_contact_form_shortcode');

function codewing_handle_form_submission() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wp_send_json_error('Invalid request method');
        return;
    }

    if (!isset($_POST['contact_form_nonce']) || 
        !wp_verify_nonce($_POST['contact_form_nonce'], 'codewing_contact_form_nonce')) {
        wp_send_json_error('Invalid nonce verification');
        return;
    }

    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';

    if (empty($name) || empty($email) || empty($message)) {
        wp_send_json_error('Please fill in all required fields');
        return;
    }

    if (!is_email($email)) {
        wp_send_json_error('Please enter a valid email address');
        return;
    }

    // Save form entry first
    $entry_data = array(
        'post_title'    => wp_strip_all_tags($name),
        'post_content'  => $message,
        'post_type'     => 'form_entry',
        'post_status'   => 'publish',
        'meta_input'    => array(
            '_contact_email' => $email,
            '_contact_date' => current_time('mysql'),
            '_contact_ip' => $_SERVER['REMOTE_ADDR']
        )
    );

    $post_id = wp_insert_post($entry_data);

    if (!$post_id) {
        wp_send_json_error('Failed to save the message. Please try again.');
        return;
    }

    $to = get_option('codewing_contact_recipient_email', get_option('admin_email'));
    $subject = sprintf('[%s] New Contact Form Submission', get_bloginfo('name'));
    
    // Get the saved email template or fall back to default
    $template = get_option('codewing_email_template', codewing_get_default_email_template());

    // Prepare replacements
    $replacements = array(
        '{name}' => esc_html($name),
        '{email}' => esc_html($email),
        '{message}' => wp_kses_post(nl2br($message)),
        '{date}' => esc_html(current_time('F j, Y g:i a')),
        '{admin_url}' => admin_url('post.php?post=' . absint($post_id) . '&action=edit')
    );
    
    $email_content = str_replace(
        array_keys($replacements),
        array_values($replacements),
        $template
    );

    $headers = array();
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = sprintf('From: %s <%s>', wp_specialchars_decode($name), 
        'wordpress@' . str_replace('www.', '', $_SERVER['HTTP_HOST']));
    $headers[] = sprintf('Reply-To: %s <%s>', $name, $email);

    $mail_sent = wp_mail($to, $subject, $email_content, $headers);

    if ($mail_sent) {
        wp_send_json_success('Message sent successfully! We\'ll get back to you soon.');
    } else {
        wp_send_json_error('Failed to send message. Please try again later.');
    }
}
add_action('wp_ajax_codewing_contact_form', 'codewing_handle_form_submission');
add_action('wp_ajax_nopriv_codewing_contact_form', 'codewing_handle_form_submission');

// Register Form Entry Post Type
function codewing_register_form_entry_post_type() {
    $args = array(
        'labels' => array(
            'name' => 'Form Entries',
            'singular_name' => 'Form Entry',
            'menu_name' => 'Form Entries',
            'all_items' => 'All Entries',
            'view_item' => 'View Entry',
            'edit_item' => 'Edit Entry'
        ),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'capability_type' => 'post',
        'hierarchical' => false,
        'rewrite' => false,
        'supports' => array('title', 'editor'),
        'publicly_queryable' => false,
        'show_in_nav_menus' => false,
        'exclude_from_search' => true,
        'show_in_admin_bar' => false,
    );
    
    register_post_type('form_entry', $args);
}
add_action('init', 'codewing_register_form_entry_post_type');

// Add Settings Page
function codewing_add_settings_page() {
    add_submenu_page(
        'edit.php?post_type=form_entry',
        'Contact Form Settings',
        'Settings',
        'manage_options',
        'contact-form-settings',
        'codewing_render_settings_page'
    );
}
add_action('admin_menu', 'codewing_add_settings_page');

// Register Settings
function codewing_register_settings() {
    register_setting('codewing_contact_settings', 'codewing_contact_recipient_email', array(
        'sanitize_callback' => 'sanitize_email'
    ));
    
    // Add email template setting
    register_setting('codewing_contact_settings', 'codewing_email_template', array(
        'sanitize_callback' => 'wp_kses_post',
        'default' => codewing_get_default_email_template()
    ));
}
add_action('admin_init', 'codewing_register_settings');

// Render Settings Page
function codewing_render_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <form method="post" action="options.php">
            <?php
            settings_fields('codewing_contact_settings');
            do_settings_sections('codewing_contact_settings');
            ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="recipient_email"><?php esc_html_e('Notification Email', 'codewing-contact-form'); ?></label>
                    </th>
                    <td>
                        <input type="email" 
                               name="codewing_contact_recipient_email" 
                               id="recipient_email" 
                               value="<?php echo esc_attr(get_option('codewing_contact_recipient_email', get_option('admin_email'))); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php esc_html_e('Email address where form submissions will be sent.', 'codewing-contact-form'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="email_template"><?php esc_html_e('Email Template', 'codewing-contact-form'); ?></label>
                    </th>
                    <td>
                        <?php
                        wp_editor(
                            get_option('codewing_email_template', codewing_get_default_email_template()),
                            'codewing_email_template',
                            array(
                                'textarea_name' => 'codewing_email_template',
                                'media_buttons' => false,
                                'textarea_rows' => 20,
                                'teeny' => true
                            )
                        );
                        ?>
                        <p class="description">
                            <?php esc_html_e('Available placeholders: {name}, {email}, {message}, {date}, {admin_url}', 'codewing-contact-form'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Customize Form Entry Columns
function codewing_set_form_entry_columns($columns) {
    $new_columns = array();
    $new_columns['cb'] = $columns['cb'];
    $new_columns['title'] = __('Name', 'codewing-contact-form');
    $new_columns['email'] = __('Email', 'codewing-contact-form');
    $new_columns['message'] = __('Message', 'codewing-contact-form');
    $new_columns['date'] = __('Date', 'codewing-contact-form');
    
    return $new_columns;
}
add_filter('manage_form_entry_posts_columns', 'codewing_set_form_entry_columns');

// Fill Form Entry Columns
function codewing_fill_form_entry_columns($column, $post_id) {
    switch ($column) {
        case 'email':
            echo esc_html(get_post_meta($post_id, '_contact_email', true));
            break;
        case 'message':
            $post = get_post($post_id);
            echo esc_html(wp_trim_words($post->post_content, 10, '...'));
            break;
    }
}
add_action('manage_form_entry_posts_custom_column', 'codewing_fill_form_entry_columns', 10, 2);

// Make Columns Sortable
function codewing_sortable_form_entry_columns($columns) {
    $columns['email'] = 'email';
    return $columns;
}
add_filter('manage_edit-form_entry_sortable_columns', 'codewing_sortable_form_entry_columns');

// Add Meta Box for Form Entry Details
function codewing_add_form_entry_meta_box() {
    add_meta_box(
        'contact_form_details',
        'Contact Details',
        'codewing_render_form_entry_meta_box',
        'form_entry',
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'codewing_add_form_entry_meta_box');

// Render Meta Box
function codewing_render_form_entry_meta_box($post) {
    $post_id = absint($post->ID);
    if (!$post_id) return;

    $email = get_post_meta($post_id, '_contact_email', true);
    $date = get_post_meta($post_id, '_contact_date', true);
    $ip = get_post_meta($post_id, '_contact_ip', true);
    ?>
    <p><strong><?php esc_html_e('Email:', 'codewing-contact-form'); ?></strong> <?php echo esc_html($email); ?></p>
    <p><strong><?php esc_html_e('Submitted:', 'codewing-contact-form'); ?></strong> <?php echo esc_html($date); ?></p>
    <p><strong><?php esc_html_e('IP Address:', 'codewing-contact-form'); ?></strong> <?php echo esc_html($ip); ?></p>
    <?php
}

// Add this new function to get the default template:
function codewing_get_default_email_template() {
    return '<div style="max-width: 600px; margin: 0 auto; padding: 20px; background-color: #ffffff; font-family: Arial, sans-serif;">
        <!-- Header with gradient -->
        <div style="background: linear-gradient(135deg, #6b46c1 0%, #805ad5 100%); padding: 30px 20px; border-radius: 10px 10px 0 0; margin-bottom: 30px;">
            <h2 style="color: #ffffff; margin: 0; font-family: \'Playfair Display\', Georgia, serif; font-size: 28px; text-align: center; text-shadow: 2px 2px 4px rgba(0,0,0,0.2);">✨ New Message Received ✨</h2>
        </div>
        
        <!-- Content Container -->
        <div style="padding: 0 20px;">
            <!-- Sender Info Box -->
            <div style="background-color: #f8f7ff; border-left: 4px solid #6b46c1; padding: 20px; margin-bottom: 25px; border-radius: 0 8px 8px 0;">
                <h3 style="color: #44337a; font-family: \'Playfair Display\', Georgia, serif; margin: 0 0 15px 0;">Sender Details</h3>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #e9d8fd; width: 100px;">
                            <strong style="color: #553c9a; font-family: \'Playfair Display\', Georgia, serif;">From:</strong>
                        </td>
                        <td style="padding: 10px; border-bottom: 1px solid #e9d8fd; color: #4a5568;">
                            {name}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #e9d8fd; width: 100px;">
                            <strong style="color: #553c9a; font-family: \'Playfair Display\', Georgia, serif;">Email:</strong>
                        </td>
                        <td style="padding: 10px; border-bottom: 1px solid #e9d8fd; color: #4a5568;">
                            {email}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #e9d8fd; width: 100px;">
                            <strong style="color: #553c9a; font-family: \'Playfair Display\', Georgia, serif;">Date:</strong>
                        </td>
                        <td style="padding: 10px; border-bottom: 1px solid #e9d8fd; color: #4a5568;">
                            {date}
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Message Box -->
            <div style="background-color: #ffffff; border: 1px solid #e9ecef; border-radius: 8px; padding: 20px; margin-bottom: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                <h3 style="color: #44337a; font-family: \'Playfair Display\', Georgia, serif; margin: 0 0 15px 0; padding-bottom: 10px; border-bottom: 2px solid #e9d8fd;">Message Content</h3>
                <div style="color: #4a5568; line-height: 1.8; white-space: pre-line;">{message}</div>
            </div>

            <!-- Footer -->
            <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #e9d8fd; text-align: center;">
                <a href="{admin_url}" style="display: inline-block; padding: 10px 20px; background: linear-gradient(135deg, #6b46c1 0%, #805ad5 100%); color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold; text-transform: uppercase; font-size: 14px; letter-spacing: 1px;">View in Dashboard</a>
                <p style="color: #718096; font-size: 12px; margin-top: 20px;">This is an automated message from your website contact form.</p>
            </div>
        </div>
    </div>';
}