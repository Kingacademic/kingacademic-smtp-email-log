<?php
/**
 * Plugin Name: Kingacademic SMTP & Email Log
 * Plugin URI: https://www.kingacademic.com/
 * Description: Configure WordPress SMTP, send email from the dashboard, and keep privacy-conscious delivery logs without exposing SMTP passwords.
 * Version: 1.0.0
 * Author: Kingacademic
 * Author URI: https://www.kingacademic.com/
 * Copyright: 2026 Kingacademic
 * License: GPL-2.0-or-later
 * Text Domain: kingacademic-smtp-email-log
 * Requires at least: 5.9
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Kingacademic_SMTP_Email_Log {
    const VERSION = '1.0.0';
    const OPTION_KEY = 'kac_smtp_email_log_settings';
    const MENU_SLUG = 'kac-smtp-email-log';
    const NONCE_ACTION = 'kac_smtp_email_log_action';
    const NOTICE_TRANSIENT_PREFIX = 'kac_smtp_notice_';

    private static $instance = null;
    private $table_name;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'kac_email_log';

        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ), 20 );
        add_action( 'wp_mail_succeeded', array( $this, 'log_mail_success' ) );
        add_action( 'wp_mail_failed', array( $this, 'log_mail_failure' ) );

        add_action( 'admin_post_kac_send_email', array( $this, 'handle_send_email' ) );
        add_action( 'admin_post_kac_delete_logs', array( $this, 'handle_delete_logs' ) );
        add_action( 'admin_post_kac_delete_log', array( $this, 'handle_delete_log' ) );
    }

    public static function activate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'kac_email_log';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            status varchar(20) NOT NULL,
            recipient text NOT NULL,
            subject text NOT NULL,
            error_message text NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function register_settings() {
        register_setting(
            'kac_smtp_group',
            self::OPTION_KEY,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
                'default'           => array(),
            )
        );
    }

    public function sanitize_settings( $input ) {
        $old = $this->get_settings();
        $out = array();

        $out['enabled']     = ! empty( $input['enabled'] ) ? 1 : 0;
        $out['host']        = isset( $input['host'] ) ? sanitize_text_field( $input['host'] ) : '';
        $out['port']        = isset( $input['port'] ) ? max( 1, min( 65535, absint( $input['port'] ) ) ) : 587;
        $out['encryption']  = isset( $input['encryption'] ) && in_array( $input['encryption'], array( 'none', 'tls', 'ssl' ), true ) ? $input['encryption'] : 'tls';
        $out['auth']        = ! empty( $input['auth'] ) ? 1 : 0;
        $out['username']    = isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '';
        $out['from_email']  = isset( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : '';
        $out['from_name']   = isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '';
        $out['force_from']  = ! empty( $input['force_from'] ) ? 1 : 0;
        $out['retention']   = isset( $input['retention'] ) ? absint( $input['retention'] ) : 90;
        if ( ! in_array( $out['retention'], array( 7, 30, 90, 180, 365, 0 ), true ) ) {
            $out['retention'] = 90;
        }

        // Password is deliberately never rendered back into the HTML form.
        // A blank submission keeps the existing stored password.
        if ( isset( $input['password'] ) && '' !== (string) $input['password'] ) {
            $out['password'] = (string) wp_unslash( $input['password'] );
        } else {
            $out['password'] = isset( $old['password'] ) ? $old['password'] : '';
        }

        $this->purge_old_logs( $out['retention'] );

        return $out;
    }

    private function get_settings() {
        $defaults = array(
            'enabled'    => 0,
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls',
            'auth'       => 1,
            'username'   => '',
            'password'   => '',
            'from_email' => '',
            'from_name'  => get_bloginfo( 'name' ),
            'force_from' => 1,
            'retention'  => 90,
        );

        $settings = get_option( self::OPTION_KEY, array() );
        return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
    }

    public function configure_phpmailer( $phpmailer ) {
        $s = $this->get_settings();

        if ( empty( $s['enabled'] ) || empty( $s['host'] ) ) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = $s['host'];
        $phpmailer->Port = (int) $s['port'];
        $phpmailer->SMTPAuth = ! empty( $s['auth'] );
        $phpmailer->SMTPDebug = 0;
        $phpmailer->Debugoutput = 'error_log';

        if ( $phpmailer->SMTPAuth ) {
            $phpmailer->Username = $s['username'];
            $phpmailer->Password = $s['password'];
        }

        if ( 'none' === $s['encryption'] ) {
            $phpmailer->SMTPSecure = '';
            $phpmailer->SMTPAutoTLS = false;
        } else {
            $phpmailer->SMTPSecure = $s['encryption'];
            $phpmailer->SMTPAutoTLS = true;
        }

        if ( ! empty( $s['force_from'] ) && ! empty( $s['from_email'] ) && is_email( $s['from_email'] ) ) {
            $phpmailer->setFrom( $s['from_email'], $s['from_name'], false );
        }
    }

    public function log_mail_success( $mail_data ) {
        $this->insert_log(
            'sent',
            isset( $mail_data['to'] ) ? $mail_data['to'] : array(),
            isset( $mail_data['subject'] ) ? $mail_data['subject'] : '',
            ''
        );
    }

    public function log_mail_failure( $error ) {
        $data = $error instanceof WP_Error ? $error->get_error_data() : array();
        $message = $error instanceof WP_Error ? $error->get_error_message() : __( 'Unknown mail error.', 'kingacademic-smtp-email-log' );

        $this->insert_log(
            'failed',
            isset( $data['to'] ) ? $data['to'] : array(),
            isset( $data['subject'] ) ? $data['subject'] : '',
            $this->redact_secrets( $message )
        );
    }

    private function insert_log( $status, $to, $subject, $error_message = '' ) {
        global $wpdb;

        $recipients = is_array( $to ) ? $to : array( $to );
        $recipients = array_filter( array_map( 'sanitize_email', $recipients ) );

        $wpdb->insert(
            $this->table_name,
            array(
                'created_at'    => current_time( 'mysql' ),
                'status'        => sanitize_key( $status ),
                'recipient'     => implode( ', ', $recipients ),
                'subject'       => sanitize_text_field( wp_strip_all_tags( (string) $subject ) ),
                'error_message' => $this->redact_secrets( wp_strip_all_tags( (string) $error_message ) ),
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );

        $s = $this->get_settings();
        $this->purge_old_logs( (int) $s['retention'] );
    }

    private function redact_secrets( $text ) {
        $text = (string) $text;
        $s = $this->get_settings();
        $secrets = array();

        foreach ( array( 'password', 'username' ) as $setting_key ) {
            if ( empty( $s[ $setting_key ] ) ) {
                continue;
            }

            $secrets[] = (string) $s[ $setting_key ];
            $secrets[] = rawurlencode( (string) $s[ $setting_key ] );
            $secrets[] = urlencode( (string) $s[ $setting_key ] );
        }

        foreach ( array_unique( array_filter( $secrets ) ) as $secret ) {
            $text = str_replace( $secret, '[REDACTED]', $text );
        }

        // Defensive cleanup for common credential-style diagnostic fragments.
        $patterns = array(
            '/(authorization\s*:\s*)[^\r\n]+/i',
            '/\b(bearer|basic)\s+[a-z0-9+\/_=.\-]+/i',
            '/([\"\']?(?:password|passwd|pwd|api[_ -]?key|access[_ -]?token|secret|credential)[\"\']?\s*[:=]\s*[\"\']?)[^\"\',;\s}]+/i',
            '/(\/\/)[^\/\s:@]+:[^\/\s@]+@/i',
        );

        $text = preg_replace( $patterns[0], '$1[REDACTED]', $text );
        $text = preg_replace( $patterns[1], '$1 [REDACTED]', $text );
        $text = preg_replace( $patterns[2], '$1[REDACTED]', $text );
        $text = preg_replace( $patterns[3], '$1[REDACTED]@', $text );

        return sanitize_text_field( $text );
    }

    private function purge_old_logs( $days ) {
        global $wpdb;
        $days = (int) $days;
        if ( $days <= 0 ) {
            return;
        }
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table_name} WHERE created_at < %s", $cutoff ) );
    }

    public function admin_menu() {
        add_menu_page(
            __( 'SMTP & Email Log', 'kingacademic-smtp-email-log' ),
            __( 'SMTP & Email Log', 'kingacademic-smtp-email-log' ),
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_admin_page' ),
            'dashicons-email-alt2',
            81
        );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
        if ( ! in_array( $tab, array( 'settings', 'send', 'logs' ), true ) ) {
            $tab = 'settings';
        }

        $this->render_notice();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Kingacademic SMTP & Email Log', 'kingacademic-smtp-email-log' ); ?></h1>
            <p><?php esc_html_e( 'Configure SMTP, send WordPress email, and review delivery logs. Passwords are never shown in the log.', 'kingacademic-smtp-email-log' ); ?></p>
            <nav class="nav-tab-wrapper">
                <a class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=settings' ) ); ?>"><?php esc_html_e( 'SMTP Settings', 'kingacademic-smtp-email-log' ); ?></a>
                <a class="nav-tab <?php echo 'send' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=send' ) ); ?>"><?php esc_html_e( 'Send Email', 'kingacademic-smtp-email-log' ); ?></a>
                <a class="nav-tab <?php echo 'logs' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=logs' ) ); ?>"><?php esc_html_e( 'Email Log', 'kingacademic-smtp-email-log' ); ?></a>
            </nav>
            <div style="margin-top:20px;max-width:1000px;">
                <?php
                if ( 'send' === $tab ) {
                    $this->render_send_tab();
                } elseif ( 'logs' === $tab ) {
                    $this->render_logs_tab();
                } else {
                    $this->render_settings_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_settings_tab() {
        $s = $this->get_settings();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'kac_smtp_group' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable SMTP', 'kingacademic-smtp-email-log' ); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> <?php esc_html_e( 'Route WordPress mail through this SMTP server', 'kingacademic-smtp-email-log' ); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="kac_host"><?php esc_html_e( 'SMTP Host', 'kingacademic-smtp-email-log' ); ?></label></th>
                    <td><input class="regular-text" id="kac_host" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[host]" type="text" value="<?php echo esc_attr( $s['host'] ); ?>" autocomplete="off" placeholder="smtp.example.com"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="kac_port"><?php esc_html_e( 'SMTP Port', 'kingacademic-smtp-email-log' ); ?></label></th>
                    <td><input id="kac_port" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[port]" type="number" min="1" max="65535" value="<?php echo esc_attr( $s['port'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Encryption', 'kingacademic-smtp-email-log' ); ?></th>
                    <td>
                        <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[encryption]">
                            <option value="tls" <?php selected( $s['encryption'], 'tls' ); ?>>TLS / STARTTLS</option>
                            <option value="ssl" <?php selected( $s['encryption'], 'ssl' ); ?>>SSL / SMTPS</option>
                            <option value="none" <?php selected( $s['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'kingacademic-smtp-email-log' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Authentication', 'kingacademic-smtp-email-log' ); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auth]" value="1" <?php checked( ! empty( $s['auth'] ) ); ?>> <?php esc_html_e( 'Use SMTP authentication', 'kingacademic-smtp-email-log' ); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="kac_username"><?php esc_html_e( 'SMTP Username', 'kingacademic-smtp-email-log' ); ?></label></th>
                    <td><input class="regular-text" id="kac_username" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[username]" type="text" value="<?php echo esc_attr( $s['username'] ); ?>" autocomplete="off"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="kac_password"><?php esc_html_e( 'SMTP Password', 'kingacademic-smtp-email-log' ); ?></label></th>
                    <td>
                        <input class="regular-text" id="kac_password" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[password]" type="password" value="" autocomplete="new-password" placeholder="<?php echo ! empty( $s['password'] ) ? esc_attr__( 'Saved — leave blank to keep', 'kingacademic-smtp-email-log' ) : ''; ?>">
                        <p class="description"><?php esc_html_e( 'For security, the saved password is never displayed back in this form and is never written to the email log.', 'kingacademic-smtp-email-log' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kac_from_email"><?php esc_html_e( 'From Email', 'kingacademic-smtp-email-log' ); ?></label></th>
                    <td><input class="regular-text" id="kac_from_email" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[from_email]" type="email" value="<?php echo esc_attr( $s['from_email'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="kac_from_name"><?php esc_html_e( 'From Name', 'kingacademic-smtp-email-log' ); ?></label></th>
                    <td><input class="regular-text" id="kac_from_name" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[from_name]" type="text" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Force From', 'kingacademic-smtp-email-log' ); ?></th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[force_from]" value="1" <?php checked( ! empty( $s['force_from'] ) ); ?>> <?php esc_html_e( 'Use the From Email and From Name above for WordPress mail', 'kingacademic-smtp-email-log' ); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Log Retention', 'kingacademic-smtp-email-log' ); ?></th>
                    <td>
                        <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[retention]">
                            <?php foreach ( array( 7 => '7 days', 30 => '30 days', 90 => '90 days', 180 => '180 days', 365 => '365 days', 0 => 'Keep until manually deleted' ) as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( (int) $s['retention'], (int) $value ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Logs contain only timestamp, recipient, subject, delivery status and a redacted error message. Email bodies are not stored.', 'kingacademic-smtp-email-log' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save SMTP Settings', 'kingacademic-smtp-email-log' ) ); ?>
        </form>
        <?php
    }

    private function render_send_tab() {
        $current_user = wp_get_current_user();
        ?>
        <div class="card" style="max-width:800px;padding:20px;">
            <h2><?php esc_html_e( 'Send Email', 'kingacademic-smtp-email-log' ); ?></h2>
            <p><?php esc_html_e( 'Send an email through WordPress wp_mail(). If SMTP is enabled, the SMTP settings on this plugin will be used.', 'kingacademic-smtp-email-log' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kac_send_email">
                <?php wp_nonce_field( self::NONCE_ACTION, '_kac_nonce' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="kac_to"><?php esc_html_e( 'To', 'kingacademic-smtp-email-log' ); ?></label></th>
                        <td><input class="regular-text" type="email" id="kac_to" name="to" required value="<?php echo esc_attr( $current_user->user_email ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="kac_subject"><?php esc_html_e( 'Subject', 'kingacademic-smtp-email-log' ); ?></label></th>
                        <td><input class="regular-text" type="text" id="kac_subject" name="subject" required value="<?php esc_attr_e( 'SMTP test from WordPress', 'kingacademic-smtp-email-log' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="kac_message"><?php esc_html_e( 'Message', 'kingacademic-smtp-email-log' ); ?></label></th>
                        <td><textarea class="large-text" rows="9" id="kac_message" name="message" required><?php echo esc_textarea( __( "This is a test email sent by Kingacademic SMTP & Email Log.\n\nIf you received this message, WordPress was able to submit the email successfully.", 'kingacademic-smtp-email-log' ) ); ?></textarea></td>
                    </tr>
                </table>
                <?php submit_button( __( 'Send Email', 'kingacademic-smtp-email-log' ) ); ?>
            </form>
        </div>
        <?php
    }

    public function handle_send_email() {
        $this->verify_admin_request();

        $to      = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
        $subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

        if ( ! is_email( $to ) || '' === $subject || '' === $message ) {
            $this->set_notice( 'error', __( 'Please enter a valid recipient, subject and message.', 'kingacademic-smtp-email-log' ) );
            $this->redirect_to_tab( 'send' );
        }

        $sent = wp_mail( $to, $subject, $message );
        if ( $sent ) {
            $this->set_notice( 'success', __( 'WordPress accepted the email for sending. Check the Email Log for details.', 'kingacademic-smtp-email-log' ) );
        } else {
            $this->set_notice( 'error', __( 'WordPress reported that the email could not be sent. Check the Email Log for the redacted error.', 'kingacademic-smtp-email-log' ) );
        }

        $this->redirect_to_tab( 'send' );
    }

    private function render_logs_tab() {
        global $wpdb;
        $per_page = 50;
        $page_num = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $offset = ( $page_num - 1 ) * $per_page;
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_name} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:20px;">
            <div>
                <h2><?php esc_html_e( 'Email Log', 'kingacademic-smtp-email-log' ); ?></h2>
                <p><?php esc_html_e( 'Privacy-conscious metadata only. Passwords and email message bodies are never stored here.', 'kingacademic-smtp-email-log' ); ?></p>
            </div>
            <?php if ( $total > 0 ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete all email logs?');">
                    <input type="hidden" name="action" value="kac_delete_logs">
                    <?php wp_nonce_field( self::NONCE_ACTION, '_kac_nonce' ); ?>
                    <?php submit_button( __( 'Delete All Logs', 'kingacademic-smtp-email-log' ), 'delete', 'submit', false ); ?>
                </form>
            <?php endif; ?>
        </div>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'kingacademic-smtp-email-log' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'kingacademic-smtp-email-log' ); ?></th>
                    <th><?php esc_html_e( 'Recipient', 'kingacademic-smtp-email-log' ); ?></th>
                    <th><?php esc_html_e( 'Subject', 'kingacademic-smtp-email-log' ); ?></th>
                    <th><?php esc_html_e( 'Error', 'kingacademic-smtp-email-log' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'kingacademic-smtp-email-log' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="6"><?php esc_html_e( 'No emails logged yet.', 'kingacademic-smtp-email-log' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $rows as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row->created_at ); ?></td>
                        <td><strong><?php echo esc_html( 'sent' === $row->status ? __( 'Sent', 'kingacademic-smtp-email-log' ) : __( 'Failed', 'kingacademic-smtp-email-log' ) ); ?></strong></td>
                        <td><?php echo esc_html( $row->recipient ); ?></td>
                        <td><?php echo esc_html( $row->subject ); ?></td>
                        <td><?php echo esc_html( $row->error_message ); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="kac_delete_log">
                                <input type="hidden" name="log_id" value="<?php echo esc_attr( $row->id ); ?>">
                                <?php wp_nonce_field( self::NONCE_ACTION, '_kac_nonce' ); ?>
                                <button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'kingacademic-smtp-email-log' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
        if ( $total_pages > 1 ) {
            $base = add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'logs', 'paged' => '%#%' ), admin_url( 'admin.php' ) );
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page_num, 'total' => $total_pages ) ) );
            echo '</div></div>';
        }
    }

    public function handle_delete_logs() {
        $this->verify_admin_request();
        global $wpdb;
        $wpdb->query( "DELETE FROM {$this->table_name}" );
        $this->set_notice( 'success', __( 'All email logs were deleted.', 'kingacademic-smtp-email-log' ) );
        $this->redirect_to_tab( 'logs' );
    }

    public function handle_delete_log() {
        $this->verify_admin_request();
        global $wpdb;
        $id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
        if ( $id ) {
            $wpdb->delete( $this->table_name, array( 'id' => $id ), array( '%d' ) );
        }
        $this->set_notice( 'success', __( 'Email log entry deleted.', 'kingacademic-smtp-email-log' ) );
        $this->redirect_to_tab( 'logs' );
    }

    private function verify_admin_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'kingacademic-smtp-email-log' ) );
        }
        check_admin_referer( self::NONCE_ACTION, '_kac_nonce' );
    }

    private function set_notice( $type, $message ) {
        set_transient(
            self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
            array( 'type' => sanitize_key( $type ), 'message' => sanitize_text_field( $message ) ),
            MINUTE_IN_SECONDS
        );
    }

    private function render_notice() {
        $key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
        $notice = get_transient( $key );
        if ( ! $notice || empty( $notice['message'] ) ) {
            return;
        }
        delete_transient( $key );
        $class = 'success' === $notice['type'] ? 'notice notice-success is-dismissible' : 'notice notice-error is-dismissible';
        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $notice['message'] ) );
    }

    private function redirect_to_tab( $tab ) {
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . sanitize_key( $tab ) ) );
        exit;
    }
}

register_activation_hook( __FILE__, array( 'Kingacademic_SMTP_Email_Log', 'activate' ) );
Kingacademic_SMTP_Email_Log::instance();
