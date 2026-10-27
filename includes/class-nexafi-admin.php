<?php
/**
 * NexaFi Admin Dashboard
 *
 * Provides complete administrative controls for applications, representatives,
 * reporting & analytics, CSV exports, financing parameters, and approval policy guards.
 *
 * @package NexaFi_Financing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NexaFi_Admin {

    /**
     * Singleton instance
     *
     * @var NexaFi_Admin|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return NexaFi_Admin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
        add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // AJAX handlers for admin modal & actions
        add_action( 'wp_ajax_nexafi_admin_get_app_details', array( $this, 'ajax_get_app_details' ) );
        add_action( 'wp_ajax_nexafi_admin_update_status', array( $this, 'ajax_update_app_status' ) );
        add_action( 'wp_ajax_nexafi_admin_toggle_rep', array( $this, 'ajax_toggle_rep' ) );
        add_action( 'wp_ajax_nexafi_admin_delete_email_log', array( $this, 'ajax_delete_email_log' ) );

        // Active representative notice
        add_action( 'admin_notices', array( $this, 'maybe_render_active_rep_notice' ) );
    }

    /**
     * Register Admin Menus
     */
    public function register_admin_menus() {
        $hook_main = add_menu_page(
            __( 'NexaFi Financing', 'nexafi-plugin' ),
            __( 'NexaFi Financing', 'nexafi-plugin' ),
            'manage_options',
            'nexafi-financing',
            array( $this, 'render_applications_page' ),
            'dashicons-money-alt',
            26
        );

        add_submenu_page(
            'nexafi-financing',
            __( 'Applications — NexaFi', 'nexafi-plugin' ),
            __( 'Applications', 'nexafi-plugin' ),
            'manage_options',
            'nexafi-financing',
            array( $this, 'render_applications_page' )
        );

        add_submenu_page(
            'nexafi-financing',
            __( 'GDG Representatives — NexaFi', 'nexafi-plugin' ),
            __( 'Representatives', 'nexafi-plugin' ),
            'manage_options',
            'nexafi-representatives',
            array( $this, 'render_representatives_page' )
        );

        add_submenu_page(
            'nexafi-financing',
            __( 'Email Logs — NexaFi', 'nexafi-plugin' ),
            __( 'Email Logs', 'nexafi-plugin' ),
            'manage_options',
            'nexafi-email-logs',
            array( $this, 'render_email_logs_page' )
        );

        add_submenu_page(
            'nexafi-financing',
            __( 'Reports & Analytics — NexaFi', 'nexafi-plugin' ),
            __( 'Reports', 'nexafi-plugin' ),
            'manage_options',
            'nexafi-reports',
            array( $this, 'render_reports_page' )
        );

        add_submenu_page(
            'nexafi-financing',
            __( 'Settings & Policies — NexaFi', 'nexafi-plugin' ),
            __( 'Settings', 'nexafi-plugin' ),
            'manage_options',
            'nexafi-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Enqueue Admin CSS and JS
     */
    public function enqueue_admin_assets( $hook ) {
        if ( false === strpos( $hook, 'nexafi-' ) ) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'nexafi-admin-css',
            plugins_url( 'assets/css/nexafi-admin.css', dirname( __FILE__ ) ),
            array(),
            NEXAFI_CF_VERSION
        );

        wp_enqueue_script(
            'nexafi-admin-js',
            plugins_url( 'assets/js/nexafi-admin.js', dirname( __FILE__ ) ),
            array( 'jquery' ),
            NEXAFI_CF_VERSION,
            true
        );

        wp_localize_script( 'nexafi-admin-js', 'nexafi_admin_obj', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'nexafi_admin_action_nonce' ),
            'i18n'     => array(
                'confirm_delete'      => __( 'Are you sure you want to delete this record? This action cannot be undone.', 'nexafi-plugin' ),
                'confirm_policy_text' => __( 'WARNING: Enabling Final Approval wording confirms written authorization has been obtained from GDG and the financing provider. Are you sure you wish to enable final approval mode?', 'nexafi-plugin' ),
            ),
        ) );
    }

    /**
     * Show admin notices on NexaFi pages if no active representative or return URL is unset
     */
    public function maybe_render_active_rep_notice() {
        $screen = get_current_screen();
        if ( ! $screen || false === strpos( $screen->id, 'nexafi' ) ) {
            return;
        }

        $db = NexaFi_DB::get_instance();
        if ( ! $db->has_active_representative() ) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><?php esc_html_e( 'Add at least one active GDG representative before going live.', 'nexafi-plugin' ); ?></p>
            </div>
            <?php
        }

        $return_url = get_option( 'nexafi_return_url', '' );
        if ( empty( $return_url ) ) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><?php esc_html_e( 'Set the GDG Smiles homepage URL in Settings before going live.', 'nexafi-plugin' ); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Handle state-changing actions (CSV export, saving settings, adding rep)
     */
    public function handle_admin_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action = isset( $_REQUEST['nexafi_action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nexafi_action'] ) ) : '';
        if ( empty( $action ) && isset( $_REQUEST['action'] ) ) {
            $action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
        }

        if ( 'export_csv' === $action ) {
            check_admin_referer( 'nexafi_export_csv_action', 'nexafi_export_nonce' );
            $this->process_csv_export();
            exit;
        }

        if ( 'save_settings' === $action ) {
            check_admin_referer( 'nexafi_save_settings_action', 'nexafi_settings_nonce' );
            $this->process_save_settings();
        }

        if ( 'save_representative' === $action ) {
            check_admin_referer( 'nexafi_save_rep_action', 'nexafi_rep_nonce' );
            $this->process_save_representative();
        }

        if ( 'delete_application' === $action ) {
            $app_id = isset( $_GET['app_id'] ) ? intval( $_GET['app_id'] ) : ( isset( $_POST['app_id'] ) ? intval( $_POST['app_id'] ) : 0 );
            check_admin_referer( 'nexafi_delete_app_' . $app_id, 'nexafi_delete_nonce' );
            $db = NexaFi_DB::get_instance();
            $db->delete_application( $app_id );
            wp_safe_redirect( add_query_arg( array( 'page' => 'nexafi-financing', 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( 'delete_representative' === $action ) {
            $rep_id = isset( $_GET['rep_id'] ) ? intval( $_GET['rep_id'] ) : ( isset( $_POST['rep_id'] ) ? intval( $_POST['rep_id'] ) : 0 );
            check_admin_referer( 'nexafi_delete_rep_' . $rep_id, 'nexafi_delete_nonce' );
            $db  = NexaFi_DB::get_instance();
            $res = $db->delete_representative( $rep_id );
            if ( is_wp_error( $res ) ) {
                wp_safe_redirect( add_query_arg( array( 'page' => 'nexafi-representatives', 'error' => urlencode( $res->get_error_message() ) ), admin_url( 'admin.php' ) ) );
            } else {
                wp_safe_redirect( add_query_arg( array( 'page' => 'nexafi-representatives', 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
            }
            exit;
        }

        if ( 'delete_email_log' === $action ) {
            $log_id = isset( $_GET['log_id'] ) ? intval( $_GET['log_id'] ) : ( isset( $_POST['log_id'] ) ? intval( $_POST['log_id'] ) : 0 );
            check_admin_referer( 'nexafi_delete_log_' . $log_id, 'nexafi_delete_nonce' );
            $db = NexaFi_DB::get_instance();
            $db->delete_email_log( $log_id );
            wp_safe_redirect( add_query_arg( array( 'page' => 'nexafi-email-logs', 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( 'clear_email_logs' === $action ) {
            check_admin_referer( 'nexafi_clear_email_logs_action', 'nexafi_clear_nonce' );
            $db = NexaFi_DB::get_instance();
            $db->clear_all_email_logs();
            wp_safe_redirect( add_query_arg( array( 'page' => 'nexafi-email-logs', 'cleared' => 1 ), admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    /**
     * CSV Export Handler (Protected against spreadsheet formula injection)
     */
    private function process_csv_export() {
        check_admin_referer( 'nexafi_export_csv_action', 'nexafi_export_nonce' );

        $db = NexaFi_DB::get_instance();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search      = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status      = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $eligibility = isset( $_GET['eligibility'] ) ? sanitize_text_field( wp_unslash( $_GET['eligibility'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $rep_id      = isset( $_GET['rep_id'] ) ? sanitize_text_field( wp_unslash( $_GET['rep_id'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $date_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $date_to     = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';

        $apps = $db->get_applications( array(
            'search'      => $search,
            'status'      => $status,
            'eligibility' => $eligibility,
            'rep_id'      => $rep_id,
            'date_from'   => $date_from,
            'date_to'     => $date_to,
            'per_page'    => -1, // all matching records
        ) );

        $filename = 'nexafi-financing-applications-' . gmdate( 'Y-m-d-His' ) . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        // Write UTF-8 BOM for Microsoft Excel compatibility
        fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        // Header row
        fputcsv( $output, array(
            'ID',
            'Reference #',
            'Submission Date',
            'First Name',
            'Last Name',
            'Email',
            'Phone',
            'Home Address',
            'City',
            'State',
            'ZIP Code',
            'Employment Status',
            'Employer Name',
            'Job Title',
            'Time at Job',
            'Monthly Income',
            'State ID Declared',
            'Debit Card Declared',
            'Total Treatment Price',
            'Representative ID',
            'Eligibility Result',
            'Eligibility Reason',
            'Decision Mode',
            'Required Down Payment',
            'Amount Financed',
            'Financing Charge',
            'Total Installment Payments',
            'Your Monthly Payment',
            'Term (Months)',
            'Status',
            'Terms Accepted',
            'Accepted Date',
        ) );

        foreach ( $apps as $a ) {
            fputcsv( $output, array(
                $a->id,
                $this->escape_csv_cell( $a->reference_number ),
                $a->created_at,
                $this->escape_csv_cell( $a->first_name ),
                $this->escape_csv_cell( $a->last_name ),
                $this->escape_csv_cell( $a->email ),
                $this->escape_csv_cell( $a->phone ),
                $this->escape_csv_cell( $a->home_address ),
                $this->escape_csv_cell( $a->city ),
                $this->escape_csv_cell( $a->state ),
                $this->escape_csv_cell( $a->zip_code ),
                $this->escape_csv_cell( $a->employment_status ),
                $this->escape_csv_cell( $a->employer_name ),
                $this->escape_csv_cell( $a->job_title ),
                $this->escape_csv_cell( $a->time_at_job ),
                $a->monthly_income,
                $a->has_id_declaration ? 'Yes' : 'No',
                $a->has_debit_card_declaration ? 'Yes' : 'No',
                $a->treatment_quote,
                $this->escape_csv_cell( $a->rep_id_str ),
                $a->eligibility_result,
                $this->escape_csv_cell( $a->eligibility_reason ),
                $a->decision_mode,
                $a->upfront_amount,
                $a->financed_amount,
                $a->charge_amount,
                $a->total_repayment,
                $a->monthly_payment,
                $a->repayment_term,
                $a->application_status,
                $a->terms_accepted ? 'Yes' : 'No',
                $a->terms_accepted_timestamp ? $a->terms_accepted_timestamp : 'N/A',
            ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $output );
    }

    /**
     * Prevent CSV Formula Injection
     */
    private function escape_csv_cell( $val ) {
        $val = (string) $val;
        if ( isset( $val[0] ) && in_array( $val[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            return "'" . $val;
        }
        return $val;
    }

    /**
     * Process Save Settings
     */
    private function process_save_settings() {
        check_admin_referer( 'nexafi_save_settings_action', 'nexafi_settings_nonce' );

        $upfront_pct         = floatval( $_POST['nexafi_upfront_pct'] ?? 70.0 );
        $financed_pct        = floatval( $_POST['nexafi_financed_pct'] ?? 30.0 );
        $charge_pct          = floatval( $_POST['nexafi_charge_pct'] ?? 15.0 );
        $repayment_term      = intval( $_POST['nexafi_repayment_term'] ?? 12 );
        $min_quote           = max( 0.0, floatval( $_POST['nexafi_min_quote'] ?? 0.0 ) );
        $max_quote           = floatval( $_POST['nexafi_max_quote'] ?? 50000.0 );
        $charge_basis        = sanitize_text_field( wp_unslash( $_POST['nexafi_charge_basis'] ?? 'financed_amount' ) );
        $plan_fee            = max( 0.0, floatval( $_POST['nexafi_plan_fee'] ?? 0.0 ) );
        $plan_fee_treatment  = sanitize_text_field( wp_unslash( $_POST['nexafi_plan_fee_treatment'] ?? 'financed' ) );
        $terms_verified      = ! empty( $_POST['nexafi_terms_verified'] ) ? 1 : 0;
        $booking_deposit     = max( 0.0, floatval( $_POST['nexafi_booking_deposit'] ?? 0.0 ) );

        // Validate percentage balance and financing options
        $val_res = NexaFi_Calculator::validate_settings( $upfront_pct, $financed_pct, $charge_pct, $repayment_term, $charge_basis, $plan_fee, $plan_fee_treatment );
        if ( is_wp_error( $val_res ) ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'nexafi-settings', 'error' => urlencode( $val_res->get_error_message() ) ), admin_url( 'admin.php' ) ) );
            exit;
        }

        update_option( 'nexafi_upfront_pct', $upfront_pct );
        update_option( 'nexafi_financed_pct', $financed_pct );
        update_option( 'nexafi_charge_pct', $charge_pct );
        update_option( 'nexafi_repayment_term', $repayment_term );
        update_option( 'nexafi_min_quote', $min_quote );
        update_option( 'nexafi_max_quote', $max_quote );
        update_option( 'nexafi_charge_basis', $charge_basis );
        update_option( 'nexafi_plan_fee', $plan_fee );
        update_option( 'nexafi_plan_fee_treatment', $plan_fee_treatment );
        update_option( 'nexafi_terms_verified', $terms_verified );
        update_option( 'nexafi_booking_deposit', $booking_deposit );
        update_option( 'nexafi_booking_deposit_note', sanitize_textarea_field( wp_unslash( $_POST['nexafi_booking_deposit_note'] ?? '' ) ) );

        // Decision Policy Mode (preliminary_only vs final_authorized)
        $posted_mode = sanitize_text_field( wp_unslash( $_POST['nexafi_decision_mode'] ?? 'preliminary_only' ) );
        $new_mode    = ( 'final_authorized' === $posted_mode ) ? 'final_authorized' : 'preliminary_only';
        $old_mode    = get_option( 'nexafi_decision_mode', 'preliminary_only' );
        if ( $old_mode !== $new_mode ) {
            update_option( 'nexafi_decision_mode', $new_mode );
            $db = NexaFi_DB::get_instance();
            $db->log_audit( 'settings', 'nexafi_decision_mode', 'policy_mode_change', $old_mode, $new_mode );
        }

        // Messages & URLs & Disclosures
        update_option( 'nexafi_ineligibility_message', sanitize_textarea_field( wp_unslash( $_POST['nexafi_ineligibility_message'] ?? '' ) ) );
        update_option( 'nexafi_preliminary_message', sanitize_textarea_field( wp_unslash( $_POST['nexafi_preliminary_message'] ?? '' ) ) );
        update_option( 'nexafi_privacy_policy_url', esc_url_raw( wp_unslash( $_POST['nexafi_privacy_policy_url'] ?? '' ) ) );

        // Validate Return URL as an absolute HTTPS URL
        $raw_return_url = trim( wp_unslash( $_POST['nexafi_return_url'] ?? '' ) );
        if ( '' !== $raw_return_url ) {
            $is_valid_https = false;
            if ( 0 === strpos( $raw_return_url, 'https://' ) ) {
                $sanitized_url = esc_url_raw( $raw_return_url );
                $parsed_url    = wp_parse_url( $sanitized_url );
                if ( ! empty( $parsed_url['host'] ) && filter_var( $sanitized_url, FILTER_VALIDATE_URL ) && wp_http_validate_url( $sanitized_url ) ) {
                    $is_valid_https = true;
                }
            }
            if ( ! $is_valid_https ) {
                wp_safe_redirect( add_query_arg( array( 'page' => 'nexafi-settings', 'error' => urlencode( __( 'Return URL must be a valid absolute HTTPS URL (e.g. https://gdgsmiles.com).', 'nexafi-plugin' ) ) ), admin_url( 'admin.php' ) ) );
                exit;
            }
            update_option( 'nexafi_return_url', esc_url_raw( $raw_return_url ) );
        } else {
            update_option( 'nexafi_return_url', '' );
        }

        update_option( 'nexafi_contact_fallback_text', sanitize_text_field( wp_unslash( $_POST['nexafi_contact_fallback_text'] ?? '' ) ) );
        update_option( 'nexafi_disclosure_text', sanitize_textarea_field( wp_unslash( $_POST['nexafi_disclosure_text'] ?? '' ) ) );
        update_option( 'nexafi_provider_disclosure', sanitize_textarea_field( wp_unslash( $_POST['nexafi_provider_disclosure'] ?? '' ) ) );

        // Email Notification Settings
        $admin_email_enabled = ! empty( $_POST['nexafi_admin_email_enabled'] ) ? '1' : '0';
        $admin_email_custom  = sanitize_email( wp_unslash( $_POST['nexafi_admin_email'] ?? '' ) );
        update_option( 'nexafi_admin_email_enabled', $admin_email_enabled );
        update_option( 'nexafi_admin_email', $admin_email_custom );

        wp_safe_redirect( add_query_arg( array( 'page' => 'nexafi-settings', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Process Save Representative
     */
    private function process_save_representative() {
        check_admin_referer( 'nexafi_save_rep_action', 'nexafi_rep_nonce' );

        $id     = isset( $_POST['rep_db_id'] ) ? intval( $_POST['rep_db_id'] ) : 0;
        $rep_id = sanitize_text_field( wp_unslash( $_POST['rep_id'] ?? '' ) );
        $name   = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $email  = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $phone  = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $photo  = esc_url_raw( wp_unslash( $_POST['photo_url'] ?? '' ) );
        $notes  = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
        $active = ! empty( $_POST['is_active'] ) ? 1 : 0;

        $db  = NexaFi_DB::get_instance();
        $res = $db->save_representative( array(
            'rep_id'    => $rep_id,
            'name'      => $name,
            'email'     => $email,
            'phone'     => $phone,
            'photo_url' => $photo,
            'notes'     => $notes,
            'is_active' => $active,
        ), $id );

        if ( is_wp_error( $res ) ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'nexafi-representatives', 'error' => urlencode( $res->get_error_message() ) ), admin_url( 'admin.php' ) ) );
        } else {
            wp_safe_redirect( add_query_arg( array( 'page' => 'nexafi-representatives', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
        }
        exit;
    }

    /**
     * Render Unified Modern Top Navigation Bar
     *
     * @param string $active_tab Current active tab slug.
     */
    public function render_top_navigation( $active_tab = 'applications' ) {
        $tabs = array(
            'applications'    => array(
                'label' => __( 'Applications', 'nexafi-plugin' ),
                'url'   => admin_url( 'admin.php?page=nexafi-financing' ),
                'icon'  => 'dashicons-clipboard',
            ),
            'representatives' => array(
                'label' => __( 'Representatives', 'nexafi-plugin' ),
                'url'   => admin_url( 'admin.php?page=nexafi-representatives' ),
                'icon'  => 'dashicons-id-alt',
            ),
            'email-logs'      => array(
                'label' => __( 'Email Logs', 'nexafi-plugin' ),
                'url'   => admin_url( 'admin.php?page=nexafi-email-logs' ),
                'icon'  => 'dashicons-email-alt',
            ),
            'reports'         => array(
                'label' => __( 'Reports', 'nexafi-plugin' ),
                'url'   => admin_url( 'admin.php?page=nexafi-reports' ),
                'icon'  => 'dashicons-chart-area',
            ),
            'settings'        => array(
                'label' => __( 'Settings', 'nexafi-plugin' ),
                'url'   => admin_url( 'admin.php?page=nexafi-settings' ),
                'icon'  => 'dashicons-admin-settings',
            ),
        );
        ?>
        <header class="nexafi-topnav">
            <div class="nexafi-topnav__brand">
                <div class="nexafi-topnav__logo">
                    <span class="dashicons dashicons-money-alt"></span>
                    <span class="nexafi-topnav__brand-name">NexaFi</span>
                </div>
                <span class="nexafi-topnav__version">v<?php echo esc_html( NEXAFI_CF_VERSION ); ?></span>
            </div>

            <nav class="nexafi-topnav__menu" aria-label="<?php esc_attr_e( 'NexaFi Navigation', 'nexafi-plugin' ); ?>">
                <?php foreach ( $tabs as $slug => $tab ) : ?>
                    <a href="<?php echo esc_url( $tab['url'] ); ?>" class="nexafi-navlink <?php echo $slug === $active_tab ? 'nexafi-navlink--active' : ''; ?>">
                        <span><?php echo esc_html( $tab['label'] ); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </header>
        <?php
    }

    /**
     * RENDER PAGE 1: Applications
     */
    public function render_applications_page() {
        $db = NexaFi_DB::get_instance();

        // Filters
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search      = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status      = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $eligibility = isset( $_GET['eligibility'] ) ? sanitize_text_field( wp_unslash( $_GET['eligibility'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $rep_id      = isset( $_GET['rep_id'] ) ? sanitize_text_field( wp_unslash( $_GET['rep_id'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $date_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $date_to     = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page        = max( 1, isset( $_GET['paged'] ) ? intval( $_GET['paged'] ) : 1 );
        $per_page    = 20;

        $filter_args = array(
            'search'      => $search,
            'status'      => $status,
            'eligibility' => $eligibility,
            'rep_id'      => $rep_id,
            'date_from'   => $date_from,
            'date_to'     => $date_to,
            'page'        => $page,
            'per_page'    => $per_page,
            'orderby'     => 'id',
            'order'       => 'DESC',
        );

        $applications = $db->get_applications( $filter_args );
        $total_count  = $db->count_applications( $filter_args );
        $total_pages  = ceil( $total_count / $per_page );

        $metrics = $db->get_report_metrics();
        $all_reps = $db->get_representatives();

        ?>
        <div class="wrap nexafi-admin-wrap">
            <?php $this->render_top_navigation( 'applications' ); ?>
            <div class="nexafi-admin-header">
                <div class="nexafi-admin-title-box">
                    <h1 class="wp-heading-inline">
                        <span class="nexafi-brand-text">NexaFi</span> Financing Applications
                    </h1>
                    <span class="nexafi-admin-badge-count"><?php echo intval( $total_count ); ?> records</span>
                </div>
                <div class="nexafi-admin-actions">
                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array(
                        'nexafi_action' => 'export_csv',
                        'search'        => $search,
                        'status'        => $status,
                        'eligibility'   => $eligibility,
                        'rep_id'        => $rep_id,
                        'date_from'     => $date_from,
                        'date_to'       => $date_to,
                    ), admin_url( 'admin.php' ) ), 'nexafi_export_csv_action', 'nexafi_export_nonce' ) ); ?>" class="button button-primary nexafi-header-btn nexafi-export-btn">
                        <?php esc_html_e( 'Export Filtered CSV', 'nexafi-plugin' ); ?>
                    </a>
                </div>
            </div>

            <!-- Top Metric Stat Cards -->
            <div class="nexafi-stat-cards-grid">
                <div class="nexafi-stat-card">
                    <div class="nexafi-stat-label">Total Applications</div>
                    <div class="nexafi-stat-val"><?php echo intval( $metrics['total_applications'] ); ?></div>
                </div>
                <div class="nexafi-stat-card nexafi-stat-green">
                    <div class="nexafi-stat-label">Approved</div>
                    <div class="nexafi-stat-val"><?php echo intval( $metrics['approved_count'] ); ?></div>
                </div>
                <div class="nexafi-stat-card nexafi-stat-blue">
                    <div class="nexafi-stat-label">Accepted Offers</div>
                    <div class="nexafi-stat-val"><?php echo intval( $metrics['accepted_count'] ); ?></div>
                </div>
                <div class="nexafi-stat-card nexafi-stat-amber">
                    <div class="nexafi-stat-label">Ineligible</div>
                    <div class="nexafi-stat-val"><?php echo intval( $metrics['ineligible_count'] ); ?></div>
                </div>
                <div class="nexafi-stat-card nexafi-stat-purple">
                    <div class="nexafi-stat-label">Total Treatment Volume</div>
                    <div class="nexafi-stat-val"><?php echo esc_html( NexaFi_Calculator::format_currency( $metrics['total_quotes'] ) ); ?></div>
                </div>
            </div>

            <!-- Filter Controls Bar -->
            <div class="nexafi-filter-bar">
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nexafi-filter-form">
                    <input type="hidden" name="page" value="nexafi-financing" />

                    <div class="nexafi-filter-inputs">
                        <input type="text" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="Search reference, name, email, rep..." class="nexafi-admin-input" />

                        <select name="status" class="nexafi-admin-select">
                            <option value="">All Statuses</option>
                            <option value="accepted" <?php selected( $status, 'accepted' ); ?>>Accepted Offer</option>
                            <option value="approved" <?php selected( $status, 'approved' ); ?>>Approved</option>
                            <option value="ineligible" <?php selected( $status, 'ineligible' ); ?>>Ineligible</option>
                        </select>

                        <select name="eligibility" class="nexafi-admin-select">
                            <option value="">All Decisions</option>
                            <option value="approved" <?php selected( $eligibility, 'approved' ); ?>>Approved</option>
                            <option value="ineligible" <?php selected( $eligibility, 'ineligible' ); ?>>Ineligible</option>
                        </select>

                        <select name="rep_id" class="nexafi-admin-select">
                            <option value="">All Representatives</option>
                            <?php foreach ( $all_reps as $r ) : ?>
                                <option value="<?php echo esc_attr( $r->rep_id ); ?>" <?php selected( strtoupper( $rep_id ), strtoupper( $r->rep_id ) ); ?>>
                                    <?php echo esc_html( $r->rep_id . ' - ' . $r->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" title="Filter by Date" class="nexafi-admin-input-date" />

                        <button type="submit" class="button button-primary nexafi-filter-btn"><?php esc_html_e( 'Filter', 'nexafi-plugin' ); ?></button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=nexafi-financing' ) ); ?>" class="button button-secondary nexafi-reset-btn"><?php esc_html_e( 'Reset', 'nexafi-plugin' ); ?></a>
                    </div>
                </form>
            </div>

            <!-- Applications Table -->
            <div class="nexafi-table-container">
                <table class="wp-list-table widefat striped nexafi-apps-table">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-ref"><?php esc_html_e( 'Reference #', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-applicant column-primary"><?php esc_html_e( 'Applicant', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-rep"><?php esc_html_e( 'Representative', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-quote"><?php esc_html_e( 'Quote / Financed', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-decision"><?php esc_html_e( 'Eligibility Decision', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-status"><?php esc_html_e( 'Application Status', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-date"><?php esc_html_e( 'Submitted', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-actions"><?php esc_html_e( 'Actions', 'nexafi-plugin' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $applications ) ) : ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 30px;">
                                    <em><?php esc_html_e( 'No applications found matching your criteria.', 'nexafi-plugin' ); ?></em>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $applications as $app ) : ?>
                                <tr>
                                    <td class="column-ref" data-colname="<?php esc_attr_e( 'Reference #', 'nexafi-plugin' ); ?>">
                                        <a href="#" class="nexafi-view-detail-link" data-app-id="<?php echo esc_attr( $app->id ); ?>" title="<?php esc_attr_e( 'View application details', 'nexafi-plugin' ); ?>">
                                            <?php echo esc_html( $app->reference_number ); ?>
                                        </a>
                                    </td>
                                    <td class="column-applicant column-primary" data-colname="<?php esc_attr_e( 'Applicant', 'nexafi-plugin' ); ?>">
                                        <div class="nexafi-applicant-cell">
                                            <strong><?php echo esc_html( $app->first_name . ' ' . $app->last_name ); ?></strong>
                                            <div class="nexafi-sub-meta">
                                                <a href="mailto:<?php echo esc_attr( $app->email ); ?>"><?php echo esc_html( $app->email ); ?></a>
                                                <?php if ( ! empty( $app->phone ) ) : ?>
                                                    <span class="nexafi-sep">|</span> <a href="tel:<?php echo esc_attr( $app->phone ); ?>"><?php echo esc_html( $app->phone ); ?></a>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ( ! empty( $app->employer_name ) || ! empty( $app->job_title ) ) : ?>
                                                <div class="nexafi-sub-meta nexafi-applicant-job">
                                                    💼 <?php echo esc_html( trim( ( $app->job_title ?: '' ) . ( ( $app->job_title && $app->employer_name ) ? ' at ' : '' ) . ( $app->employer_name ?: '' ) ) ); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="column-rep" data-colname="<?php esc_attr_e( 'Representative', 'nexafi-plugin' ); ?>">
                                        <span class="nexafi-rep-tag"><?php echo esc_html( $app->rep_id_str ); ?></span>
                                    </td>
                                    <td class="column-quote" data-colname="<?php esc_attr_e( 'Quote / Financed', 'nexafi-plugin' ); ?>">
                                        <div><strong>Quote:</strong> <?php echo esc_html( NexaFi_Calculator::format_currency( $app->treatment_quote ) ); ?></div>
                                        <div class="nexafi-sub-meta">Financed: <?php echo esc_html( NexaFi_Calculator::format_currency( $app->financed_amount ) ); ?> (<?php echo esc_html( NexaFi_Calculator::format_currency( $app->monthly_payment ) ); ?>/mo)</div>
                                    </td>
                                    <td class="column-decision" data-colname="<?php esc_attr_e( 'Eligibility Decision', 'nexafi-plugin' ); ?>">
                                        <?php if ( 'approved' === $app->eligibility_result || 'approved' === $app->application_status ) : ?>
                                            <span class="nexafi-badge nexafi-badge-success"><?php esc_html_e( 'Approved', 'nexafi-plugin' ); ?></span>
                                            <div class="nexafi-sub-meta"><?php esc_html_e( 'Automated Rule', 'nexafi-plugin' ); ?></div>
                                        <?php else : ?>
                                            <span class="nexafi-badge nexafi-badge-warning"><?php esc_html_e( 'Ineligible', 'nexafi-plugin' ); ?></span>
                                            <div class="nexafi-sub-meta"><?php esc_html_e( 'Criteria Unmet', 'nexafi-plugin' ); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-status" data-colname="<?php esc_attr_e( 'Application Status', 'nexafi-plugin' ); ?>">
                                        <?php if ( ! empty( $app->terms_accepted ) || 'accepted' === $app->application_status ) : ?>
                                            <span class="nexafi-badge nexafi-badge-primary"><?php esc_html_e( 'Accepted Offer', 'nexafi-plugin' ); ?></span>
                                        <?php elseif ( 'approved' === $app->eligibility_result || 'approved' === $app->application_status ) : ?>
                                            <span class="nexafi-badge nexafi-badge-success"><?php esc_html_e( 'Approved', 'nexafi-plugin' ); ?></span>
                                        <?php else : ?>
                                            <span class="nexafi-badge nexafi-badge-warning"><?php esc_html_e( 'Ineligible', 'nexafi-plugin' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-date" data-colname="<?php esc_attr_e( 'Submitted', 'nexafi-plugin' ); ?>">
                                        <?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $app->created_at ) ) ); ?>
                                    </td>
                                    <td class="column-actions" data-colname="<?php esc_attr_e( 'Actions', 'nexafi-plugin' ); ?>">
                                        <div class="nexafi-table-actions">
                                            <button type="button" class="button button-small nexafi-action-btn nexafi-action-btn-view nexafi-view-detail-btn" data-app-id="<?php echo esc_attr( $app->id ); ?>" title="<?php esc_attr_e( 'View application details', 'nexafi-plugin' ); ?>">
                                                <span><?php esc_html_e( 'View', 'nexafi-plugin' ); ?></span>
                                            </button>
                                            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array(
                                                'nexafi_action' => 'delete_application',
                                                'app_id'        => $app->id,
                                            ), admin_url( 'admin.php?page=nexafi-financing' ) ), 'nexafi_delete_app_' . $app->id, 'nexafi_delete_nonce' ) ); ?>" 
                                               class="button button-small nexafi-action-btn nexafi-action-btn-delete" 
                                               onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this application record?', 'nexafi-plugin' ) ); ?>');" 
                                               title="<?php esc_attr_e( 'Delete application', 'nexafi-plugin' ); ?>">
                                                <span><?php esc_html_e( 'Delete', 'nexafi-plugin' ); ?></span>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php
                            /* translators: %s: number of items */
                            echo esc_html( sprintf( _n( '%s item', '%s items', $total_count, 'nexafi-plugin' ), number_format_i18n( $total_count ) ) );
                            ?>
                        </span>
                        <?php
                        echo wp_kses_post( paginate_links( array(
                            'base'      => add_query_arg( 'paged', '%#%' ),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $page,
                        ) ) );
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Application Detail Modal Window -->
            <div id="nexafi-detail-modal" class="nexafi-modal" style="display: none;" role="dialog" aria-modal="true">
                <div class="nexafi-modal-backdrop"></div>
                <div class="nexafi-modal-dialog">
                    <div class="nexafi-modal-header">
                        <h2 class="nexafi-modal-title">Application Record: <span id="nexafi-modal-ref"></span></h2>
                        <button type="button" class="nexafi-modal-close" aria-label="Close modal">&times;</button>
                    </div>
                    <div class="nexafi-modal-body" id="nexafi-modal-content">
                        <div class="nexafi-modal-spinner"><span class="spinner is-active"></span> Loading application details...</div>
                    </div>
                    <div class="nexafi-modal-footer">
                        <button type="button" class="button button-secondary nexafi-modal-close-btn">Close</button>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * RENDER PAGE 2: Representatives
     */
    public function render_representatives_page() {
        $db = NexaFi_DB::get_instance();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
        $reps   = $db->get_representatives( array( 'search' => $search ) );

        ?>
        <div class="wrap nexafi-admin-wrap">
            <?php $this->render_top_navigation( 'representatives' ); ?>
            <div class="nexafi-admin-header">
                <div>
                    <h1 class="wp-heading-inline"><span class="nexafi-brand-text">GDG</span> Representative Management</h1>
                    <span class="nexafi-admin-badge-count"><?php echo count( $reps ); ?> representatives</span>
                </div>
                <div>
                    <button type="button" class="button button-primary nexafi-header-btn" id="nexafi-add-rep-btn">
                        <?php esc_html_e( 'Add Representative', 'nexafi-plugin' ); ?>
                    </button>
                </div>
            </div>

            <?php
            // phpcs:disable WordPress.Security.NonceVerification.Recommended
            if ( isset( $_GET['error'] ) ) :
                ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ); ?></p></div>
            <?php elseif ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Representative saved successfully.</p></div>
            <?php elseif ( isset( $_GET['deleted'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Representative deleted.</p></div>
            <?php
            endif;
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            ?>

            <!-- Table of Representatives -->
            <div class="nexafi-table-container">
                <table class="wp-list-table widefat striped nexafi-reps-table">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-photo" style="width: 56px; text-align: center;"><?php esc_html_e( 'Photo', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-repid"><?php esc_html_e( 'Rep ID', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-name column-primary"><?php esc_html_e( 'Name', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-contact"><?php esc_html_e( 'Contact Details', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-apps"><?php esc_html_e( 'Applications', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-actions"><?php esc_html_e( 'Actions', 'nexafi-plugin' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $reps ) ) : ?>
                            <tr><td colspan="7" style="text-align:center; padding: 24px; color: #64748b;"><em><?php esc_html_e( 'No representatives found. Click "Add Representative" to create one.', 'nexafi-plugin' ); ?></em></td></tr>
                        <?php else : ?>
                            <?php foreach ( $reps as $r ) : ?>
                                <?php
                                $default_photo = plugins_url( 'assets/images/rep-caleb.png', dirname( __FILE__ ) );
                                $photo = ! empty( $r->photo_url ) ? $r->photo_url : $default_photo;
                                ?>
                                <tr>
                                    <td class="column-photo" data-colname="<?php esc_attr_e( 'Photo', 'nexafi-plugin' ); ?>" style="text-align: center; width: 56px;">
                                        <img src="<?php echo esc_url( $photo ); ?>" alt="<?php echo esc_attr( $r->name ); ?>" class="nexafi-rep-avatar-img" width="42" height="42" style="width:42px;height:42px;border-radius:50%;object-fit:cover;display:block;margin:0 auto;" />
                                    </td>
                                    <td class="column-repid" data-colname="<?php esc_attr_e( 'Rep ID', 'nexafi-plugin' ); ?>"><span class="nexafi-rep-tag"><?php echo esc_html( $r->rep_id ); ?></span></td>
                                    <td class="column-name column-primary" data-colname="<?php esc_attr_e( 'Name', 'nexafi-plugin' ); ?>"><strong style="color: #0f172a;"><?php echo esc_html( $r->name ); ?></strong></td>
                                    <td class="column-contact" data-colname="<?php esc_attr_e( 'Contact Details', 'nexafi-plugin' ); ?>">
                                        <div style="color: #1e293b;"><a href="mailto:<?php echo esc_attr( $r->email ); ?>"><?php echo esc_html( $r->email ); ?></a></div>
                                        <div class="nexafi-sub-meta"><?php echo esc_html( $r->phone ); ?></div>
                                    </td>
                                    <td class="column-apps" data-colname="<?php esc_attr_e( 'Applications', 'nexafi-plugin' ); ?>">
                                        <span class="nexafi-badge nexafi-badge-info"><?php echo intval( $r->apps_count ); ?> <?php esc_html_e( 'apps', 'nexafi-plugin' ); ?></span>
                                    </td>
                                    <td class="column-status" data-colname="<?php esc_attr_e( 'Status', 'nexafi-plugin' ); ?>">
                                        <?php if ( $r->is_active ) : ?>
                                            <span class="nexafi-badge nexafi-badge-success"><?php esc_html_e( 'Active', 'nexafi-plugin' ); ?></span>
                                        <?php else : ?>
                                            <span class="nexafi-badge nexafi-badge-warning"><?php esc_html_e( 'Inactive', 'nexafi-plugin' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-actions" data-colname="<?php esc_attr_e( 'Actions', 'nexafi-plugin' ); ?>">
                                        <div class="nexafi-rep-actions-wrap">
                                            <button type="button" class="button button-small nexafi-action-btn nexafi-action-btn-edit nexafi-edit-rep-btn" 
                                                    data-id="<?php echo esc_attr( $r->id ); ?>"
                                                    data-rep-id="<?php echo esc_attr( $r->rep_id ); ?>"
                                                    data-name="<?php echo esc_attr( $r->name ); ?>"
                                                    data-email="<?php echo esc_attr( $r->email ); ?>"
                                                    data-phone="<?php echo esc_attr( $r->phone ); ?>"
                                                    data-photo="<?php echo esc_attr( $r->photo_url ); ?>"
                                                    data-notes="<?php echo esc_attr( $r->notes ); ?>"
                                                    data-active="<?php echo esc_attr( $r->is_active ); ?>">
                                                <span><?php esc_html_e( 'Edit', 'nexafi-plugin' ); ?></span>
                                            </button>
                                            <button type="button" class="button button-small nexafi-action-btn nexafi-action-btn-toggle nexafi-toggle-rep-btn" data-id="<?php echo esc_attr( $r->id ); ?>" data-active="<?php echo esc_attr( $r->is_active ); ?>">
                                                <span><?php echo $r->is_active ? esc_html__( 'Deactivate', 'nexafi-plugin' ) : esc_html__( 'Activate', 'nexafi-plugin' ); ?></span>
                                            </button>
                                            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array(
                                                'nexafi_action' => 'delete_representative',
                                                'rep_id'        => $r->id,
                                            ), admin_url( 'admin.php?page=nexafi-representatives' ) ), 'nexafi_delete_rep_' . $r->id, 'nexafi_delete_nonce' ) ); ?>" class="button button-small nexafi-action-btn nexafi-action-btn-delete nexafi-delete-rep-btn" onclick="return confirm('<?php echo esc_js( __( 'Delete this representative?', 'nexafi-plugin' ) ); ?>');">
                                                <span><?php esc_html_e( 'Delete', 'nexafi-plugin' ); ?></span>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Add / Edit Representative Modal -->
            <div id="nexafi-rep-modal" class="nexafi-modal" style="display: none;" role="dialog">
                <div class="nexafi-modal-backdrop"></div>
                <div class="nexafi-modal-dialog">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=nexafi-representatives' ) ); ?>">
                        <?php wp_nonce_field( 'nexafi_save_rep_action', 'nexafi_rep_nonce' ); ?>
                        <input type="hidden" name="nexafi_action" value="save_representative" />
                        <input type="hidden" name="rep_db_id" id="rep_db_id" value="0" />

                        <div class="nexafi-modal-header">
                            <h2 class="nexafi-modal-title" id="rep-modal-title"><?php esc_html_e( 'Add Representative', 'nexafi-plugin' ); ?></h2>
                            <button type="button" class="nexafi-modal-close" aria-label="<?php esc_attr_e( 'Close', 'nexafi-plugin' ); ?>">&times;</button>
                        </div>
                        <div class="nexafi-modal-body">
                            <div class="nexafi-form-grid-2">
                                <div class="nexafi-form-col">
                                    <label class="nexafi-admin-label" for="rep_field_id"><?php esc_html_e( 'Representative ID *', 'nexafi-plugin' ); ?></label>
                                    <input type="text" name="rep_id" id="rep_field_id" placeholder="e.g. GDG1027" required style="text-transform: uppercase;" />
                                </div>
                                <div class="nexafi-form-col">
                                    <label class="nexafi-admin-label" for="rep_field_name"><?php esc_html_e( 'Full Name *', 'nexafi-plugin' ); ?></label>
                                    <input type="text" name="name" id="rep_field_name" placeholder="e.g. Caleb Johnson" required />
                                </div>
                            </div>
                            <div class="nexafi-form-grid-2">
                                <div class="nexafi-form-col">
                                    <label class="nexafi-admin-label" for="rep_field_email"><?php esc_html_e( 'Email Address', 'nexafi-plugin' ); ?></label>
                                    <input type="email" name="email" id="rep_field_email" placeholder="name@gdgsmiles.com" />
                                </div>
                                <div class="nexafi-form-col">
                                    <label class="nexafi-admin-label" for="rep_field_phone"><?php esc_html_e( 'Phone Number', 'nexafi-plugin' ); ?></label>
                                    <input type="text" name="phone" id="rep_field_phone" placeholder="(555) 000-0000" />
                                </div>
                            </div>
                            <div class="nexafi-form-row">
                                <label class="nexafi-admin-label"><?php esc_html_e( 'Representative Photo', 'nexafi-plugin' ); ?></label>
                                <input type="hidden" name="photo_url" id="rep_field_photo" value="" />
                                <div class="nexafi-photo-upload-wrap">
                                    <div class="nexafi-photo-preview-box">
                                        <img id="rep_photo_preview_img" src="" alt="<?php esc_attr_e( 'Preview', 'nexafi-plugin' ); ?>" style="display: none;" />
                                        <div id="rep_photo_placeholder" class="nexafi-photo-placeholder">
                                            <span class="dashicons dashicons-admin-users"></span>
                                        </div>
                                    </div>
                                    <div class="nexafi-photo-controls">
                                        <div class="nexafi-photo-btn-group">
                                            <button type="button" class="button button-secondary" id="nexafi-upload-photo-btn">
                                                <span id="nexafi-upload-btn-text"><?php esc_html_e( 'Upload Photo', 'nexafi-plugin' ); ?></span>
                                            </button>
                                            <button type="button" class="button button-link-delete" id="nexafi-remove-photo-btn">
                                                <?php esc_html_e( 'Remove', 'nexafi-plugin' ); ?>
                                            </button>
                                        </div>
                                        <p class="description" style="margin-top: 4px;"><?php esc_html_e( 'Square portrait photo (e.g. 200x200px).', 'nexafi-plugin' ); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="nexafi-form-row">
                                <label class="nexafi-admin-label" for="rep_field_notes"><?php esc_html_e( 'Internal Notes (Optional)', 'nexafi-plugin' ); ?></label>
                                <textarea name="notes" id="rep_field_notes" class="nexafi-admin-textarea" rows="3" placeholder="<?php esc_attr_e( 'Internal notes, assigned clinic locations, schedule preferences...', 'nexafi-plugin' ); ?>"></textarea>
                                <p class="description" style="margin: 4px 0 0 0; font-size: 11px; color: #64748b;"><?php esc_html_e( 'Private notes for internal clinic management. Not visible to applicants.', 'nexafi-plugin' ); ?></p>
                            </div>
                            <div class="nexafi-form-row" style="margin-top: 8px;">
                                <label style="display: inline-flex; align-items: center; gap: 8px; font-weight: 500; cursor: pointer; color: #334155;">
                                    <input type="checkbox" name="is_active" id="rep_field_active" value="1" checked style="margin: 0;" />
                                    <span><?php esc_html_e( 'Active (Eligible to receive applications)', 'nexafi-plugin' ); ?></span>
                                </label>
                            </div>
                        </div>
                        <div class="nexafi-modal-footer">
                            <a href="#" id="nexafi-modal-delete-rep-link" class="button button-link-delete nexafi-modal-delete-rep-btn" style="display: none;" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this representative?', 'nexafi-plugin' ) ); ?>');">
                                <?php esc_html_e( 'Delete Representative', 'nexafi-plugin' ); ?>
                            </a>
                            <div class="nexafi-modal-footer-actions">
                                <button type="button" class="button button-secondary nexafi-modal-close-btn"><?php esc_html_e( 'Cancel', 'nexafi-plugin' ); ?></button>
                                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Representative', 'nexafi-plugin' ); ?></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * RENDER PAGE: Email Logs
     */
    public function render_email_logs_page() {
        $db = NexaFi_DB::get_instance();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged  = max( 1, isset( $_GET['paged'] ) ? intval( $_GET['paged'] ) : 1 );
        $per_page = 20;

        $logs        = $db->get_email_logs( array(
            'search'   => $search,
            'status'   => $status,
            'page'     => $paged,
            'per_page' => $per_page,
        ) );
        $total_count = $db->get_email_logs_count( array(
            'search' => $search,
            'status' => $status,
        ) );
        $total_pages = ceil( $total_count / $per_page );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $deleted = isset( $_GET['deleted'] );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $cleared = isset( $_GET['cleared'] );
        ?>
        <div class="wrap nexafi-admin-wrap">
            <?php $this->render_top_navigation( 'email-logs' ); ?>
            <div class="nexafi-admin-header">
                <div class="nexafi-admin-title-box">
                    <h1 class="wp-heading-inline"><span class="nexafi-brand-text">NexaFi</span> Email Delivery Logs</h1>
                    <span class="nexafi-admin-badge-count"><?php echo intval( $total_count ); ?> logs</span>
                </div>
                <div class="nexafi-admin-actions">
                    <?php if ( $total_count > 0 ) : ?>
                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array(
                        'page'          => 'nexafi-email-logs',
                        'nexafi_action' => 'clear_email_logs',
                    ), admin_url( 'admin.php' ) ), 'nexafi_clear_email_logs_action', 'nexafi_clear_nonce' ) ); ?>" 
                       class="button button-secondary" 
                       onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear all email delivery logs?', 'nexafi-plugin' ) ); ?>');">
                        <?php esc_html_e( 'Clear All Logs', 'nexafi-plugin' ); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( $deleted ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Email log entry deleted successfully.', 'nexafi-plugin' ); ?></p></div>
            <?php endif; ?>

            <?php if ( $cleared ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'All email delivery logs cleared successfully.', 'nexafi-plugin' ); ?></p></div>
            <?php endif; ?>

            <!-- Notification banner placeholder for AJAX resend responses -->
            <div id="nexafi-resend-notice-box" style="display:none;margin-top:15px;" class="notice is-dismissible">
                <p id="nexafi-resend-notice-text"></p>
            </div>

            <!-- Filter & Search Bar -->
            <div class="nexafi-filter-bar" style="margin-top: 15px;">
                <form method="get" class="nexafi-admin-filters">
                    <input type="hidden" name="page" value="nexafi-email-logs" />
                    
                    <input type="text" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search recipient, subject, reference...', 'nexafi-plugin' ); ?>" class="nexafi-admin-input" style="min-width: 260px;" />

                    <select name="status" class="nexafi-admin-select">
                        <option value=""><?php esc_html_e( 'All Delivery Statuses', 'nexafi-plugin' ); ?></option>
                        <option value="sent" <?php selected( $status, 'sent' ); ?>><?php esc_html_e( 'Sent Successfully', 'nexafi-plugin' ); ?></option>
                        <option value="failed" <?php selected( $status, 'failed' ); ?>><?php esc_html_e( 'Delivery Failed', 'nexafi-plugin' ); ?></option>
                    </select>

                    <button type="submit" class="button button-primary nexafi-filter-btn"><?php esc_html_e( 'Filter Logs', 'nexafi-plugin' ); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=nexafi-email-logs' ) ); ?>" class="button button-secondary nexafi-reset-btn"><?php esc_html_e( 'Reset', 'nexafi-plugin' ); ?></a>
                </form>
            </div>

            <!-- Email Logs Table -->
            <div class="nexafi-table-container">
                <table class="wp-list-table widefat striped nexafi-admin-table nexafi-logs-table">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-id" style="width: 60px;"><?php esc_html_e( 'ID', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date & Time', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-recipient column-primary"><?php esc_html_e( 'Recipient', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-ref"><?php esc_html_e( 'Application Ref #', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-type"><?php esc_html_e( 'Email Type', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-details"><?php esc_html_e( 'Delivery Details / Error', 'nexafi-plugin' ); ?></th>
                            <th scope="col" class="manage-column column-actions"><?php esc_html_e( 'Actions', 'nexafi-plugin' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $logs ) ) : ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 30px; color: #64748b;">
                                    <em><?php esc_html_e( 'No email delivery logs recorded yet. Outgoing emails from NexaFi will appear here.', 'nexafi-plugin' ); ?></em>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $logs as $log ) : 
                                $is_sent = ( 'sent' === $log->status );
                                $type_label = ( 'resend' === $log->email_type ) ? __( 'Resend Notification', 'nexafi-plugin' ) : ( ( 'admin_notification' === $log->email_type ) ? __( 'Admin Alert', 'nexafi-plugin' ) : __( 'Applicant Email', 'nexafi-plugin' ) );
                                $type_class = ( 'resend' === $log->email_type ) ? 'nexafi-type-resend' : ( ( 'admin_notification' === $log->email_type ) ? 'nexafi-type-alert' : 'nexafi-type-applicant' );
                            ?>
                            <tr id="nexafi-log-row-<?php echo intval( $log->id ); ?>">
                                <td class="column-id" data-colname="<?php esc_attr_e( 'ID', 'nexafi-plugin' ); ?>"><strong>#<?php echo intval( $log->id ); ?></strong></td>
                                <td class="column-date" data-colname="<?php esc_attr_e( 'Date & Time', 'nexafi-plugin' ); ?>"><?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $log->sent_at ) ) ); ?></td>
                                <td class="column-recipient column-primary" data-colname="<?php esc_attr_e( 'Recipient', 'nexafi-plugin' ); ?>">
                                    <div class="nexafi-recipient-cell">
                                        <strong><?php echo esc_html( $log->recipient_name ? $log->recipient_name : 'Applicant' ); ?></strong>
                                        <div class="nexafi-sub-meta">
                                            <a href="mailto:<?php echo esc_attr( $log->recipient_email ); ?>"><?php echo esc_html( $log->recipient_email ); ?></a>
                                        </div>
                                    </div>
                                </td>
                                <td class="column-ref" data-colname="<?php esc_attr_e( 'Application Ref #', 'nexafi-plugin' ); ?>">
                                    <?php if ( ! empty( $log->reference_number ) ) : ?>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=nexafi-financing&search=' . urlencode( $log->reference_number ) ) ); ?>" class="nexafi-view-detail-link" data-app-id="<?php echo esc_attr( $log->application_id ); ?>">
                                            <?php echo esc_html( $log->reference_number ); ?>
                                        </a>
                                    <?php else : ?>
                                        <span style="color: #94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-type" data-colname="<?php esc_attr_e( 'Email Type', 'nexafi-plugin' ); ?>">
                                    <span class="nexafi-type-tag <?php echo esc_attr( $type_class ); ?>">
                                        <?php echo esc_html( $type_label ); ?>
                                    </span>
                                </td>
                                <td class="column-status nexafi-log-status-cell" data-colname="<?php esc_attr_e( 'Status', 'nexafi-plugin' ); ?>">
                                    <?php if ( $is_sent ) : ?>
                                        <span class="nexafi-badge nexafi-badge-success">&#10003; <?php esc_html_e( 'Sent', 'nexafi-plugin' ); ?></span>
                                    <?php else : ?>
                                        <span class="nexafi-badge nexafi-badge-danger">&#10007; <?php esc_html_e( 'Failed', 'nexafi-plugin' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-details nexafi-log-error-cell" data-colname="<?php esc_attr_e( 'Delivery Details / Error', 'nexafi-plugin' ); ?>">
                                    <?php if ( ! empty( $log->error_message ) ) : ?>
                                        <div class="nexafi-error-snippet" title="<?php echo esc_attr( $log->error_message ); ?>">
                                            <span class="nexafi-error-icon">⚠️</span>
                                            <span><?php echo esc_html( $log->error_message ); ?></span>
                                        </div>
                                    <?php else : ?>
                                        <span class="nexafi-success-snippet">&#10003; <?php esc_html_e( 'Delivered to mail server', 'nexafi-plugin' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-actions" data-colname="<?php esc_attr_e( 'Actions', 'nexafi-plugin' ); ?>">
                                    <div class="nexafi-table-actions">
                                        <?php if ( ! empty( $log->application_id ) ) : ?>
                                            <button type="button" 
                                                    class="button button-small nexafi-action-btn nexafi-action-btn-resend nexafi-resend-email-btn" 
                                                    data-log-id="<?php echo esc_attr( $log->id ); ?>" 
                                                    data-app-id="<?php echo esc_attr( $log->application_id ); ?>"
                                                    title="<?php esc_attr_e( 'Resend confirmation email to applicant', 'nexafi-plugin' ); ?>">
                                                <span><?php esc_html_e( 'Resend', 'nexafi-plugin' ); ?></span>
                                            </button>
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array(
                                            'page'          => 'nexafi-email-logs',
                                            'nexafi_action' => 'delete_email_log',
                                            'log_id'        => $log->id,
                                        ), admin_url( 'admin.php' ) ), 'nexafi_delete_log_' . $log->id, 'nexafi_delete_nonce' ) ); ?>" 
                                           class="button button-small nexafi-action-btn nexafi-action-btn-delete nexafi-delete-email-log-btn" 
                                           data-log-id="<?php echo esc_attr( $log->id ); ?>" 
                                           data-nonce="<?php echo esc_attr( wp_create_nonce( 'nexafi_delete_log_' . $log->id ) ); ?>"
                                           title="<?php esc_attr_e( 'Delete log', 'nexafi-plugin' ); ?>">
                                            <span><?php esc_html_e( 'Delete', 'nexafi-plugin' ); ?></span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav" style="margin-top: 15px;">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php
                        /* translators: %s: number of items */
                        echo esc_html( sprintf( _n( '%s item', '%s items', $total_count, 'nexafi-plugin' ), number_format_i18n( $total_count ) ) ); ?></span>
                        <?php
                        echo wp_kses_post( paginate_links( array(
                            'base'      => add_query_arg( 'paged', '%#%' ),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $paged,
                        ) ) );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * RENDER PAGE 3: Reports & Analytics
     */
    public function render_reports_page() {
        $db = NexaFi_DB::get_instance();
        $metrics     = $db->get_report_metrics();
        $date_stats  = $db->get_reports_by_date( 30 );
        $rep_stats   = $db->get_reports_by_representative();

        ?>
        <div class="wrap nexafi-admin-wrap">
            <?php $this->render_top_navigation( 'reports' ); ?>
            <div class="nexafi-admin-header">
                <div>
                    <h1 class="wp-heading-inline"><span class="nexafi-brand-text">NexaFi</span> Reports &amp; Analytics</h1>
                    <p class="description"><?php esc_html_e( 'Real-time breakdown of applications, authorized financing totals, and GDG representative performance.', 'nexafi-plugin' ); ?></p>
                </div>
            </div>

            <!-- Key Financial Performance Metrics -->
            <div class="nexafi-stat-cards-grid" style="margin-top: 20px;">
                <div class="nexafi-stat-card">
                    <div class="nexafi-stat-label">Total Applications Submitted</div>
                    <div class="nexafi-stat-val"><?php echo intval( $metrics['total_applications'] ); ?></div>
                </div>
                <div class="nexafi-stat-card nexafi-stat-green">
                    <div class="nexafi-stat-label">Accepted Financing Volume</div>
                    <div class="nexafi-stat-val"><?php echo esc_html( NexaFi_Calculator::format_currency( $metrics['accepted_quotes'] ) ); ?></div>
                    <div class="nexafi-sub-meta"><?php echo intval( $metrics['accepted_count'] ); ?> accepted offers</div>
                </div>
                <div class="nexafi-stat-card nexafi-stat-blue">
                    <div class="nexafi-stat-label">Total Financed Amount (30%)</div>
                    <div class="nexafi-stat-val"><?php echo esc_html( NexaFi_Calculator::format_currency( $metrics['total_financed'] ) ); ?></div>
                </div>
                <div class="nexafi-stat-card nexafi-stat-purple">
                    <div class="nexafi-stat-label">Financing Charges Earned (15%)</div>
                    <div class="nexafi-stat-val"><?php echo esc_html( NexaFi_Calculator::format_currency( $metrics['total_charges'] ) ); ?></div>
                </div>
                <div class="nexafi-stat-card">
                    <div class="nexafi-stat-label">Total Scheduled Repayments</div>
                    <div class="nexafi-stat-val"><?php echo esc_html( NexaFi_Calculator::format_currency( $metrics['total_repayment'] ) ); ?></div>
                </div>
            </div>

            <div class="nexafi-report-section" style="margin-top: 24px;">
                <h2 style="font-size: 15px; font-weight: 700; color: #0f172a; margin-bottom: 12px;"><?php esc_html_e( 'Applications Grouped by Representative', 'nexafi-plugin' ); ?></h2>
                <div class="nexafi-table-container">
                    <table class="wp-list-table widefat striped nexafi-reports-table">
                        <thead>
                            <tr>
                                <th scope="col" class="manage-column column-repid" style="width: 120px;"><?php esc_html_e( 'Rep ID', 'nexafi-plugin' ); ?></th>
                                <th scope="col" class="manage-column column-repname column-primary"><?php esc_html_e( 'Representative Name', 'nexafi-plugin' ); ?></th>
                                <th scope="col" class="manage-column column-totalapps"><?php esc_html_e( 'Total Applications', 'nexafi-plugin' ); ?></th>
                                <th scope="col" class="manage-column column-approved"><?php esc_html_e( 'Approved / Eligible', 'nexafi-plugin' ); ?></th>
                                <th scope="col" class="manage-column column-accepted"><?php esc_html_e( 'Accepted Offers', 'nexafi-plugin' ); ?></th>
                                <th scope="col" class="manage-column column-quotes"><?php esc_html_e( 'Total Treatment Quotes', 'nexafi-plugin' ); ?></th>
                                <th scope="col" class="manage-column column-financed"><?php esc_html_e( 'Total Financed Amount', 'nexafi-plugin' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $rep_stats ) ) : ?>
                                <tr><td colspan="7" style="text-align:center; padding: 24px; color: #64748b;"><?php esc_html_e( 'No representative application data available.', 'nexafi-plugin' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $rep_stats as $rs ) : ?>
                                    <tr>
                                        <td class="column-repid" data-colname="<?php esc_attr_e( 'Rep ID', 'nexafi-plugin' ); ?>"><span class="nexafi-rep-tag"><?php echo esc_html( $rs->rep_id ); ?></span></td>
                                        <td class="column-repname column-primary" data-colname="<?php esc_attr_e( 'Representative Name', 'nexafi-plugin' ); ?>"><strong style="color: #0f172a;"><?php echo esc_html( $rs->name ); ?></strong></td>
                                        <td class="column-totalapps" data-colname="<?php esc_attr_e( 'Total Applications', 'nexafi-plugin' ); ?>"><strong><?php echo intval( $rs->total_apps ); ?></strong></td>
                                        <td class="column-approved" data-colname="<?php esc_attr_e( 'Approved / Eligible', 'nexafi-plugin' ); ?>"><span class="nexafi-badge nexafi-badge-success"><?php echo intval( $rs->approved_apps ); ?></span></td>
                                        <td class="column-accepted" data-colname="<?php esc_attr_e( 'Accepted Offers', 'nexafi-plugin' ); ?>"><span class="nexafi-badge nexafi-badge-info"><?php echo intval( $rs->accepted_apps ); ?></span></td>
                                        <td class="column-quotes" data-colname="<?php esc_attr_e( 'Total Treatment Quotes', 'nexafi-plugin' ); ?>"><?php echo esc_html( NexaFi_Calculator::format_currency( $rs->total_quote_amount ) ); ?></td>
                                        <td class="column-financed" data-colname="<?php esc_attr_e( 'Total Financed Amount', 'nexafi-plugin' ); ?>"><strong style="color: #0f172a;"><?php echo esc_html( NexaFi_Calculator::format_currency( $rs->total_financed_amount ) ); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="nexafi-report-section" style="margin-top: 28px;">
                <h2 style="font-size: 15px; font-weight: 700; color: #0f172a; margin-bottom: 12px;"><?php esc_html_e( 'Applications by Date (Last 30 Days)', 'nexafi-plugin' ); ?></h2>
                <div class="nexafi-table-container">
                    <table class="wp-list-table widefat striped nexafi-reports-table">
                        <thead>
                            <tr>
                                <th scope="col" class="manage-column column-date column-primary"><?php esc_html_e( 'Date', 'nexafi-plugin' ); ?></th>
                                <th scope="col" class="manage-column column-totalapps"><?php esc_html_e( 'Total Applications', 'nexafi-plugin' ); ?></th>
                                <th scope="col" class="manage-column column-approved"><?php esc_html_e( 'Approved', 'nexafi-plugin' ); ?></th>
                                <th scope="col" class="manage-column column-ineligible"><?php esc_html_e( 'Ineligible', 'nexafi-plugin' ); ?></th>
                                <th scope="col" class="manage-column column-accepted"><?php esc_html_e( 'Offers Accepted', 'nexafi-plugin' ); ?></th>
                                <th scope="col" class="manage-column column-volume"><?php esc_html_e( 'Submitted Treatment Volume', 'nexafi-plugin' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $date_stats ) ) : ?>
                                <tr><td colspan="6" style="text-align:center; padding: 24px; color: #64748b;"><?php esc_html_e( 'No historical data recorded in the last 30 days.', 'nexafi-plugin' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $date_stats as $ds ) : ?>
                                    <tr>
                                        <td class="column-date column-primary" data-colname="<?php esc_attr_e( 'Date', 'nexafi-plugin' ); ?>"><strong style="color: #0f172a;"><?php echo esc_html( wp_date( 'M j, Y (l)', strtotime( $ds->app_date ) ) ); ?></strong></td>
                                        <td class="column-totalapps" data-colname="<?php esc_attr_e( 'Total Applications', 'nexafi-plugin' ); ?>"><strong><?php echo intval( $ds->total_apps ); ?></strong></td>
                                        <td class="column-approved" data-colname="<?php esc_attr_e( 'Approved', 'nexafi-plugin' ); ?>"><span class="nexafi-badge nexafi-badge-success"><?php echo intval( $ds->approved_apps ); ?></span></td>
                                        <td class="column-ineligible" data-colname="<?php esc_attr_e( 'Ineligible', 'nexafi-plugin' ); ?>"><span class="nexafi-badge nexafi-badge-warning"><?php echo intval( $ds->ineligible_apps ); ?></span></td>
                                        <td class="column-accepted" data-colname="<?php esc_attr_e( 'Offers Accepted', 'nexafi-plugin' ); ?>"><span class="nexafi-badge nexafi-badge-info"><?php echo intval( $ds->accepted_apps ); ?></span></td>
                                        <td class="column-volume" data-colname="<?php esc_attr_e( 'Submitted Treatment Volume', 'nexafi-plugin' ); ?>"><strong style="color: #0f172a;"><?php echo esc_html( NexaFi_Calculator::format_currency( $ds->total_volume ) ); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * RENDER PAGE 4: Settings & Policies
     */
    public function render_settings_page() {
        $upfront_pct         = floatval( get_option( 'nexafi_upfront_pct', 70.0 ) );
        $financed_pct        = floatval( get_option( 'nexafi_financed_pct', 30.0 ) );
        $charge_pct          = floatval( get_option( 'nexafi_charge_pct', 15.0 ) );
        $repayment_term      = intval( get_option( 'nexafi_repayment_term', 12 ) );
        $min_quote           = floatval( get_option( 'nexafi_min_quote', 0.0 ) );
        $max_quote           = floatval( get_option( 'nexafi_max_quote', 50000.0 ) );
        $charge_basis        = get_option( 'nexafi_charge_basis', 'financed_amount' );
        $plan_fee            = floatval( get_option( 'nexafi_plan_fee', 0.00 ) );
        $plan_fee_treatment  = get_option( 'nexafi_plan_fee_treatment', 'financed' );
        $terms_verified      = (bool) get_option( 'nexafi_terms_verified', false );
        $booking_deposit     = floatval( get_option( 'nexafi_booking_deposit', 0.00 ) );
        $booking_deposit_note = get_option(
            'nexafi_booking_deposit_note',
            __( 'Any booking deposit you pay is credited toward your Required Down Payment. It does not replace it.', 'nexafi-plugin' )
        );
        $disclosure_text     = get_option(
            'nexafi_disclosure_text',
            __( 'Financing disclosures pending verification.', 'nexafi-plugin' )
        );
        $provider_disclosure = get_option( 'nexafi_provider_disclosure', '' );
        $decision_mode       = get_option( 'nexafi_decision_mode', 'preliminary_only' );

        $ineligibility_msg = get_option(
            'nexafi_ineligibility_message',
            __( 'Based on your self-reported declarations, your application does not currently meet our standard automated financing criteria. However, our team is here to help discuss alternative options for your treatment.', 'nexafi-plugin' )
        );

        $prelim_msg = get_option(
            'nexafi_preliminary_message',
            __( 'Your self-reported declarations satisfy initial financing eligibility. A GDG representative will review your treatment plan to finalize your financing.', 'nexafi-plugin' )
        );

        $privacy_url = get_option( 'nexafi_privacy_policy_url', home_url( '/privacy-policy/' ) );
        $return_url  = get_option( 'nexafi_return_url', '' );
        $fallback_rep_text = get_option(
            'nexafi_contact_fallback_text',
            __( 'Please contact your GDG representative directly or speak with our front desk.', 'nexafi-plugin' )
        );

        $admin_email_enabled = get_option( 'nexafi_admin_email_enabled', '1' );
        $admin_email_custom  = get_option( 'nexafi_admin_email', '' );
        $wp_admin_email      = get_option( 'admin_email' );

        $db = NexaFi_DB::get_instance();

        // Pre-launch checklist evaluations
        $chk_has_return_url = ( ! empty( $return_url ) && 0 === strpos( $return_url, 'https://' ) );
        $chk_has_active_rep = $db->has_active_representative();
        $chk_terms_verified = (bool) get_option( 'nexafi_terms_verified', false );
        $chk_decision_mode  = get_option( 'nexafi_decision_mode', '' );
        $chk_is_final_mode  = ( 'final_authorized' === $chk_decision_mode );
        $chk_has_disclosure = ( ! empty( $disclosure_text ) && false === strpos( strtolower( $disclosure_text ), 'pending verification' ) );
        $chk_plan_fee_raw   = get_option( 'nexafi_plan_fee', null );
        $chk_has_plan_fee   = ( null !== $chk_plan_fee_raw && false !== $chk_plan_fee_raw && '' !== (string) $chk_plan_fee_raw );

        $checklist_items = array(
            'return_url' => array(
                'label'       => __( 'Return URL', 'nexafi-plugin' ),
                'is_done'     => $chk_has_return_url,
                'status_done' => $return_url,
                'status_fail' => __( 'Unset — Add GDG Smiles homepage URL', 'nexafi-plugin' ),
                'anchor'      => '#nexafi_return_url',
            ),
            'active_rep' => array(
                'label'       => __( 'Active Representative', 'nexafi-plugin' ),
                'is_done'     => $chk_has_active_rep,
                'status_done' => __( 'Active representative available', 'nexafi-plugin' ),
                'status_fail' => __( 'Unset — Add at least one active GDG rep', 'nexafi-plugin' ),
                'anchor'      => admin_url( 'admin.php?page=nexafi-representatives' ),
            ),
            'terms_verified' => array(
                'label'       => __( 'Terms Verified', 'nexafi-plugin' ),
                'is_done'     => $chk_terms_verified,
                'status_done' => __( 'Terms verified gate enabled', 'nexafi-plugin' ),
                'status_fail' => __( 'Unset — Verification checkbox unchecked', 'nexafi-plugin' ),
                'anchor'      => '#nexafi_terms_verified',
            ),
            'decision_mode' => array(
                'label'       => __( 'Decision Mode', 'nexafi-plugin' ),
                'is_done'     => $chk_is_final_mode,
                'status_done' => __( 'Final Approval Authorized', 'nexafi-plugin' ),
                'status_fail' => empty( $chk_decision_mode ) ? __( 'Unset — Configure decision policy mode', 'nexafi-plugin' ) : __( 'Preliminary Only mode', 'nexafi-plugin' ),
                'anchor'      => '#nexafi_decision_mode',
            ),
            'disclosure_text' => array(
                'label'       => __( 'Disclosure Text', 'nexafi-plugin' ),
                'is_done'     => $chk_has_disclosure,
                'status_done' => __( 'Disclosures configured', 'nexafi-plugin' ),
                'status_fail' => __( 'Unset — Pending verification placeholder', 'nexafi-plugin' ),
                'anchor'      => '#nexafi_disclosure_text',
            ),
            'plan_fee' => array(
                'label'       => __( 'Plan Fee', 'nexafi-plugin' ),
                'is_done'     => $chk_has_plan_fee,
                'status_done' => sprintf( __( 'Configured (%s)', 'nexafi-plugin' ), NexaFi_Calculator::format_currency( $plan_fee ) ),
                'status_fail' => __( 'Unset — Configure plan / admin fee', 'nexafi-plugin' ),
                'anchor'      => '#nexafi_plan_fee',
            ),
        );

        $unset_items = array_filter( $checklist_items, function( $it ) { return ! $it['is_done']; } );
        $unset_count = count( $unset_items );

        ?>
        <div class="wrap nexafi-admin-wrap">
            <?php $this->render_top_navigation( 'settings' ); ?>
            <div class="nexafi-admin-header">
                <div>
                    <h1 class="wp-heading-inline"><span class="nexafi-brand-text">NexaFi</span> Financing &amp; Policy Settings</h1>
                    <p class="description"><?php esc_html_e( 'Configure automated financing parameters, calculation percentages, and customer policies.', 'nexafi-plugin' ); ?></p>
                </div>
            </div>

            <?php
            // phpcs:disable WordPress.Security.NonceVerification.Recommended
            if ( isset( $_GET['error'] ) ) :
                ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ); ?></p></div>
            <?php elseif ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Financing parameters and policies updated successfully.</p></div>
            <?php
            endif;
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            ?>

            <!-- Pre-Launch Checklist Box -->
            <div class="nexafi-settings-box nexafi-checklist-box" style="margin-bottom: 24px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 18px 24px; background: #ffffff;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                    <div>
                        <h2 style="margin: 0 0 2px 0; font-size: 15px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                            <span class="dashicons dashicons-clipboard" style="color: #2563eb; font-size: 18px; width: 18px; height: 18px;"></span>
                            <?php esc_html_e( 'Pre-Launch Checklist', 'nexafi-plugin' ); ?>
                        </h2>
                        <p class="description" style="margin: 0; font-size: 12px;">
                            <?php
                            if ( $unset_count > 0 ) {
                                /* translators: %d: count of unset items */
                                echo esc_html( sprintf( _n( '%d required pre-launch configuration item is currently unset:', '%d required pre-launch configuration items are currently unset:', $unset_count, 'nexafi-plugin' ), $unset_count ) );
                            } else {
                                esc_html_e( 'All required pre-launch items are configured and ready for live operation.', 'nexafi-plugin' );
                            }
                            ?>
                        </p>
                    </div>
                    <div>
                        <?php if ( $unset_count > 0 ) : ?>
                            <span class="nexafi-badge nexafi-badge-warning">
                                <?php echo esc_html( sprintf( _n( '%d item unset', '%d items unset', $unset_count, 'nexafi-plugin' ), $unset_count ) ); ?>
                            </span>
                        <?php else : ?>
                            <span class="nexafi-badge nexafi-badge-success">
                                ✓ <?php esc_html_e( 'Ready for Launch', 'nexafi-plugin' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 10px;">
                    <?php foreach ( $checklist_items as $item_key => $item ) : ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-radius: 6px; border: 1px solid <?php echo $item['is_done'] ? '#e2e8f0' : '#fed7aa'; ?>; background: <?php echo $item['is_done'] ? '#f8fafc' : '#fffbeb'; ?>;">
                            <div>
                                <div style="font-weight: 600; font-size: 13px; color: #0f172a;">
                                    <a href="<?php echo esc_url( $item['anchor'] ); ?>" style="text-decoration: none; color: inherit;">
                                        <?php echo esc_html( $item['label'] ); ?>
                                    </a>
                                </div>
                                <div style="font-size: 11px; color: <?php echo $item['is_done'] ? '#64748b' : '#b45309'; ?>; margin-top: 1px;">
                                    <?php echo esc_html( $item['is_done'] ? $item['status_done'] : $item['status_fail'] ); ?>
                                </div>
                            </div>
                            <div style="margin-left: 10px;">
                                <?php if ( $item['is_done'] ) : ?>
                                    <span class="nexafi-badge nexafi-badge-success" style="font-size: 10px; padding: 2px 7px;">✓ <?php esc_html_e( 'Done', 'nexafi-plugin' ); ?></span>
                                <?php else : ?>
                                    <span class="nexafi-badge nexafi-badge-warning" style="font-size: 10px; padding: 2px 7px;">✕ <?php esc_html_e( 'Not Done', 'nexafi-plugin' ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=nexafi-settings' ) ); ?>" class="nexafi-settings-form">
                <?php wp_nonce_field( 'nexafi_save_settings_action', 'nexafi_settings_nonce' ); ?>
                <input type="hidden" name="nexafi_action" value="save_settings" />

                <!-- SECTION 1: Financing Formulas -->
                <div class="nexafi-settings-box">
                    <h2>1. Financing Calculation Parameters</h2>
                    <p class="description">Configure the financing formula for treatment quotes. Upfront % and Financed % must total 100%.</p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="nexafi_upfront_pct">Upfront Contribution (%)</label></th>
                            <td>
                                <input type="number" step="0.1" min="0" max="100" name="nexafi_upfront_pct" id="nexafi_upfront_pct" value="<?php echo esc_attr( $upfront_pct ); ?>" class="small-text" required /> %
                                <p class="description">Default: 70%. Patient pays this upfront at the clinic as the Required Down Payment.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_financed_pct">Financed Balance (%)</label></th>
                            <td>
                                <input type="number" step="0.1" min="0" max="100" name="nexafi_financed_pct" id="nexafi_financed_pct" value="<?php echo esc_attr( $financed_pct ); ?>" class="small-text" required /> %
                                <p class="description">Default: 30%. Portion financed through monthly installments.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_charge_pct">One-Time Financing Charge (%)</label></th>
                            <td>
                                <input type="number" step="0.1" min="0" max="100" name="nexafi_charge_pct" id="nexafi_charge_pct" value="<?php echo esc_attr( $charge_pct ); ?>" class="small-text" required /> %
                                <p class="description">Default: 15%. Applied to either the financed balance or the total treatment price per setting below.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_charge_basis">Financing Charge Basis</label></th>
                            <td>
                                <select name="nexafi_charge_basis" id="nexafi_charge_basis">
                                    <option value="financed_amount" <?php selected( 'financed_amount', $charge_basis ); ?>>Financed Amount (Default - 15% of 30% financed balance)</option>
                                    <option value="treatment_price" <?php selected( 'treatment_price', $charge_basis ); ?>>Total Treatment Price (15% of entire treatment price)</option>
                                </select>
                                <p class="description">Select whether the 15% financing charge is calculated against the financed amount ($3,000 on $10k) or total treatment price ($10,000).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_plan_fee">Plan / Administrative Fee ($)</label></th>
                            <td>
                                $ <input type="number" step="0.01" min="0" name="nexafi_plan_fee" id="nexafi_plan_fee" value="<?php echo esc_attr( number_format( $plan_fee, 2, '.', '' ) ); ?>" class="small-text" />
                                <p class="description">Optional administrative or plan setup fee (e.g. $35.00). Default: $0.00.</p>
                                <p class="description"><strong>Financed vs. Upfront:</strong> <em>Financed</em> adds the fee into the installment balance (increasing Financing Charge and Total Installment Payments). <em>Upfront</em> adds the fee to the Required Down Payment at booking without reducing the amount financed (Financing Charge and Total Installments exclude the fee).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_plan_fee_treatment">Plan Fee Treatment</label></th>
                            <td>
                                <select name="nexafi_plan_fee_treatment" id="nexafi_plan_fee_treatment">
                                    <option value="financed" <?php selected( 'financed', $plan_fee_treatment ); ?>>Financed (Added into installment balance)</option>
                                    <option value="upfront" <?php selected( 'upfront', $plan_fee_treatment ); ?>>Upfront (Collected at clinic with down payment)</option>
                                </select>
                                <p class="description">Controls whether the plan fee is financed over the installment term or due upfront at booking. <em>Financed</em> adds the fee into installment balance; <em>Upfront</em> adds it to Required Down Payment without reducing financed amount.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_terms_verified">Financing Terms Verification Gate</label></th>
                            <td>
                                <label for="nexafi_terms_verified" style="font-weight: 600; cursor: pointer;">
                                    <input type="checkbox" name="nexafi_terms_verified" id="nexafi_terms_verified" value="1" <?php checked( true, $terms_verified ); ?> style="margin-top: -2px;" />
                                    <?php esc_html_e( 'Financing terms verified by GDG/legal', 'nexafi-plugin' ); ?>
                                </label>
                                <p class="description" style="font-weight: 600; color: #0b275b; margin-top: 4px;">Patients see the approval screen only when Decision Mode is Final Authorized AND this box is ticked. Otherwise eligible patients see the 'your representative will confirm' screen.</p>
                                <p class="description">When unchecked (default off), unverified calculation figures (Financing Charge, Total Installment Payments, Monthly Payment) are displayed as neutral placeholders ("— / Your representative will confirm") on patient-facing screens and emails until verified.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_repayment_term">Repayment Term (Months)</label></th>
                            <td>
                                <input type="number" min="1" max="120" name="nexafi_repayment_term" id="nexafi_repayment_term" value="<?php echo esc_attr( $repayment_term ); ?>" class="small-text" required /> months
                                <p class="description">Default: 12 months.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_min_quote">Minimum Allowed Quote ($)</label></th>
                            <td>
                                $ <input type="number" step="50" min="0" name="nexafi_min_quote" id="nexafi_min_quote" value="<?php echo esc_attr( $min_quote > 0 ? $min_quote : 0 ); ?>" class="regular-text" />
                                <p class="description">Minimum treatment quote allowed on the application (Default: $0 = no minimum).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_max_quote">Maximum Allowed Quote ($)</label></th>
                            <td>
                                $ <input type="number" step="100" min="500" name="nexafi_max_quote" id="nexafi_max_quote" value="<?php echo esc_attr( $max_quote ); ?>" class="regular-text" />
                                <p class="description">Maximum treatment quote allowed on the application (Default: $50,000).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_booking_deposit">Standard Booking Deposit ($)</label></th>
                            <td>
                                $ <input type="number" step="1" min="0" name="nexafi_booking_deposit" id="nexafi_booking_deposit" value="<?php echo esc_attr( $booking_deposit > 0 ? $booking_deposit : '' ); ?>" placeholder="e.g. 300" class="regular-text" />
                                <p class="description">Optional standard booking deposit amount (e.g. $300) credited toward the Required Down Payment. Leave blank if none.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_booking_deposit_note">Booking Deposit Clarification Note</label></th>
                            <td>
                                <textarea name="nexafi_booking_deposit_note" id="nexafi_booking_deposit_note" rows="2" class="large-text"><?php echo esc_textarea( $booking_deposit_note ); ?></textarea>
                                <p class="description">Non-promotional clarification note displayed under next steps ("Any booking deposit you pay is credited toward your Required Down Payment. It does not replace it.").</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- SECTION 2: Automated Approval Policy -->
                <div class="nexafi-settings-box nexafi-policy-box">
                    <h2>2. Automated Decision &amp; Approval Policy</h2>
                    <p class="description">Configure whether automated qualifying decisions emit final approval or preliminary eligibility pending representative authorization.</p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">Decision Policy Mode</th>
                            <td>
                                <p class="description" style="font-weight: 600; color: #0b275b; margin-top: 0; margin-bottom: 8px;">Patients see the approval screen only when Decision Mode is Final Authorized AND this box is ticked. Otherwise eligible patients see the 'your representative will confirm' screen.</p>
                                <fieldset>
                                    <label style="display: block; margin-bottom: 12px; cursor: pointer;">
                                        <input type="radio" name="nexafi_decision_mode" value="preliminary_only" <?php checked( 'preliminary_only', $decision_mode ); ?> />
                                        <strong>Preliminary Eligibility Mode (Default / Safe Safeguard)</strong>
                                        <p class="description" style="margin-left: 24px; margin-top: 2px;">Applicants meeting criteria receive an initial eligibility notification. Final approval requires representative follow-up and written authorization.</p>
                                    </label>
                                    <label style="display: block; margin-bottom: 12px; cursor: pointer;">
                                        <input type="radio" name="nexafi_decision_mode" value="final_authorized" <?php checked( 'final_authorized', $decision_mode ); ?> />
                                        <strong>Final Approval Mode (Authorized)</strong>
                                        <p class="description" style="margin-left: 24px; margin-top: 2px;">Qualifying applicants receive immediate final approval ("YOU'RE APPROVED! / You're All Set"). Requires verified written authorization.</p>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                    </table>

                    <div id="nexafi-authorization-confirm-box" style="display: <?php echo ( 'final_authorized' === $decision_mode ) ? 'block' : 'none'; ?>; background: #f0fdf4; border-left: 4px solid #10b981; padding: 14px 18px; border-radius: 6px; margin-top: 14px;">
                        <strong style="color: #065f46; font-size: 14px;">&#10003; Final Approval Automated Decisioning Active</strong>
                        <p style="margin: 8px 0 0 0; color: #1e293b; font-size: 13px; line-height: 1.6;">
                            Written authorization confirmed for automated final approvals. Qualifying applications that satisfy all declarations and terms verification will emit final approved confirmation.
                        </p>
                    </div>
                </div>

                <!-- SECTION 3: Content & URLs -->
                <div class="nexafi-settings-box">
                    <h2>3. Patient Messages, Disclosures &amp; Navigation URLs</h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="nexafi_disclosure_text">Financing Disclosure Text</label></th>
                            <td>
                                <textarea name="nexafi_disclosure_text" id="nexafi_disclosure_text" rows="3" class="large-text"><?php echo esc_textarea( $disclosure_text ); ?></textarea>
                                <p class="description">Configurable financing terms disclosure displayed on Screen 4 above the patient acknowledgment checkbox.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_provider_disclosure">Financing Provider Disclosure</label></th>
                            <td>
                                <textarea name="nexafi_provider_disclosure" id="nexafi_provider_disclosure" rows="2" class="large-text"><?php echo esc_textarea( $provider_disclosure ); ?></textarea>
                                <p class="description">Legally required creditor/servicer identification rendered in the disclosure block and applicant email (empty by default).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_ineligibility_message">Ineligibility Message</label></th>
                            <td>
                                <textarea name="nexafi_ineligibility_message" id="nexafi_ineligibility_message" rows="3" class="large-text"><?php echo esc_textarea( $ineligibility_msg ); ?></textarea>
                                <p class="description">Displayed when an applicant unchecks either the state ID or traditional debit card declaration.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_preliminary_message">Preliminary Eligibility Message</label></th>
                            <td>
                                <textarea name="nexafi_preliminary_message" id="nexafi_preliminary_message" rows="3" class="large-text"><?php echo esc_textarea( $prelim_msg ); ?></textarea>
                                <p class="description">Displayed on Screen 4 when in Preliminary Eligibility mode.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_privacy_policy_url">Privacy Policy URL</label></th>
                            <td>
                                <input type="url" name="nexafi_privacy_policy_url" id="nexafi_privacy_policy_url" value="<?php echo esc_attr( $privacy_url ); ?>" class="regular-text" />
                                <p class="description">Destination for the "View Privacy Policy" link on Screen 3.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_return_url">Return to GDG Smiles URL</label></th>
                            <td>
                                <input type="url" name="nexafi_return_url" id="nexafi_return_url" value="<?php echo esc_attr( $return_url ); ?>" class="regular-text" />
                                <p class="description">Destination for the "Return to GDG Smiles" button on Screen 5.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexafi_contact_fallback_text">Representative Fallback Text</label></th>
                            <td>
                                <input type="text" name="nexafi_contact_fallback_text" id="nexafi_contact_fallback_text" value="<?php echo esc_attr( $fallback_rep_text ); ?>" class="regular-text" />
                                <p class="description">Fallback displayed when a representative does not have phone/email configured.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- SECTION 4: Email Notification Settings -->
                <div class="nexafi-settings-box">
                    <h2>4. Admin Notification Email Settings</h2>
                    <p class="description"><?php esc_html_e( 'Configure whether email alerts are sent to the site administrator when a new financing application is submitted, and customize the recipient email address.', 'nexafi-plugin' ); ?></p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="nexafi_admin_email_enabled"><?php esc_html_e( 'Admin Email Notifications', 'nexafi-plugin' ); ?></label></th>
                            <td>
                                <label for="nexafi_admin_email_enabled" style="font-weight: 600; cursor: pointer;">
                                    <input type="checkbox" name="nexafi_admin_email_enabled" id="nexafi_admin_email_enabled" value="1" <?php checked( '1', $admin_email_enabled ); ?> style="margin-top: -2px;" />
                                    <?php esc_html_e( 'Send email notification to admin upon new application', 'nexafi-plugin' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'When checked, an alert email with full applicant details is sent whenever an application is submitted.', 'nexafi-plugin' ); ?></p>
                            </td>
                        </tr>
                        <tr id="nexafi_admin_email_field_row">
                            <th scope="row"><label for="nexafi_admin_email"><?php esc_html_e( 'Admin Notification Email', 'nexafi-plugin' ); ?></label></th>
                            <td>
                                <input type="email" name="nexafi_admin_email" id="nexafi_admin_email" value="<?php echo esc_attr( $admin_email_custom ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $wp_admin_email ); ?>" />
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s: default WordPress administration email */
                                        esc_html__( 'Enter the email address where admin notifications should be delivered. Leave blank to default to the WordPress administrator email (%s).', 'nexafi-plugin' ),
                                        '<code>' . esc_html( $wp_admin_email ) . '</code>'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( 'Save All Settings &amp; Policies' ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * AJAX handler to fetch complete application details for the admin modal
     */
    public function ajax_get_app_details() {
        check_ajax_referer( 'nexafi_admin_action_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }

        $app_id = isset( $_GET['app_id'] ) ? intval( $_GET['app_id'] ) : 0;
        $db     = NexaFi_DB::get_instance();
        $app    = $db->get_application( $app_id );

        if ( ! $app ) {
            wp_send_json_error( array( 'message' => 'Application not found.' ), 404 );
        }

        $rep  = $db->get_representative_by_id( $app->rep_record_id );
        $logs = $db->get_audit_logs( 'application', (string) $app_id );

        ob_start();
        ?>
        <div class="nexafi-detail-grid">
            <!-- Col 1: Personal & Employment -->
            <div class="nexafi-detail-col">
                <h3>Personal Information</h3>
                <table class="widefat fixed striped">
                    <tr><td><strong>Name:</strong></td><td><?php echo esc_html( $app->first_name . ' ' . $app->last_name ); ?></td></tr>
                    <tr><td><strong>DOB:</strong></td><td><?php echo esc_html( $app->dob ); ?></td></tr>
                    <tr><td><strong>Email:</strong></td><td><a href="mailto:<?php echo esc_attr( $app->email ); ?>"><?php echo esc_html( $app->email ); ?></a></td></tr>
                    <tr><td><strong>Phone:</strong></td><td><a href="tel:<?php echo esc_attr( $app->phone ); ?>"><?php echo esc_html( $app->phone ); ?></a></td></tr>
                    <tr><td><strong>Address:</strong></td><td><?php echo esc_html( $app->home_address . ', ' . $app->city . ', ' . $app->state . ' ' . $app->zip_code ); ?></td></tr>
                </table>

                <h3 style="margin-top: 15px;">Employment &amp; Income</h3>
                <table class="widefat fixed striped">
                    <tr><td><strong>Status:</strong></td><td><?php echo esc_html( $app->employment_status ); ?></td></tr>
                    <tr><td><strong>Employer:</strong></td><td><?php echo esc_html( $app->employer_name ?: 'N/A' ); ?></td></tr>
                    <tr><td><strong>Job Title:</strong></td><td><?php echo esc_html( $app->job_title ?: 'N/A' ); ?></td></tr>
                    <tr><td><strong>Time at Job:</strong></td><td><?php echo esc_html( $app->time_at_job ?: 'N/A' ); ?></td></tr>
                    <tr><td><strong>Monthly Income:</strong></td><td><?php echo esc_html( NexaFi_Calculator::format_currency( $app->monthly_income ) ); ?></td></tr>
                </table>

                <h3 style="margin-top: 15px;">Self-Reported Declarations</h3>
                <table class="widefat fixed striped">
                    <tr>
                        <td><strong>Valid Driver's License/ID:</strong></td>
                        <td><?php echo $app->has_id_declaration ? '<span class="nexafi-badge nexafi-badge-success">Confirmed</span>' : '<span class="nexafi-badge nexafi-badge-warning">Not Confirmed</span>'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Active Traditional Debit Card:</strong></td>
                        <td><?php echo $app->has_debit_card_declaration ? '<span class="nexafi-badge nexafi-badge-success">Confirmed</span>' : '<span class="nexafi-badge nexafi-badge-warning">Not Confirmed</span>'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Consent Authorized:</strong></td>
                        <td><?php echo $app->consent_status ? '<span class="nexafi-badge nexafi-badge-success">Authorized (' . esc_html( $app->consent_timestamp ) . ')</span>' : 'No'; ?></td>
                    </tr>
                </table>
            </div>

            <!-- Col 2: Financing Breakdown & System Metadata -->
            <div class="nexafi-detail-col">
                <h3>Treatment &amp; Financing Breakdown</h3>
                <table class="widefat fixed striped">
                    <tr><td><strong>Total Treatment Price:</strong></td><td><strong><?php echo esc_html( NexaFi_Calculator::format_currency( $app->treatment_quote ) ); ?></strong></td></tr>
                    <tr><td><strong>Required Down Payment:</strong></td><td><?php echo esc_html( NexaFi_Calculator::format_currency( $app->upfront_amount ) ); ?></td></tr>
                    <tr><td><strong>Amount Financed:</strong></td><td><?php echo esc_html( NexaFi_Calculator::format_currency( $app->financed_amount ) ); ?></td></tr>
                    <tr><td><strong>Financing Charge:</strong></td><td><?php echo esc_html( NexaFi_Calculator::format_currency( $app->charge_amount ) ); ?></td></tr>
                    <tr><td><strong>Total Installment Payments:</strong></td><td><strong><?php echo esc_html( NexaFi_Calculator::format_currency( $app->total_repayment ) ); ?></strong></td></tr>
                    <tr><td><strong>Your Monthly Payment:</strong></td><td><strong style="color: #10b981; font-size: 15px;"><?php echo esc_html( NexaFi_Calculator::format_currency( $app->monthly_payment ) ); ?>/mo</strong></td></tr>
                </table>

                <h3 style="margin-top: 15px;">Internal Calculation Details</h3>
                <table class="widefat fixed striped">
                    <tr><td><strong>Down / Financed Split:</strong></td><td><?php echo esc_html( $app->upfront_pct ); ?>% upfront / <?php echo esc_html( $app->financed_pct ); ?>% financed</td></tr>
                    <tr><td><strong>Charge Percentage:</strong></td><td><?php echo esc_html( $app->charge_pct ); ?>%</td></tr>
                    <tr><td><strong>Charge Basis:</strong></td><td><?php echo esc_html( ! empty( $app->charge_basis ) ? $app->charge_basis : 'financed_amount' ); ?></td></tr>
                    <tr><td><strong>Plan Fee:</strong></td><td><?php echo esc_html( NexaFi_Calculator::format_currency( ! empty( $app->plan_fee ) ? $app->plan_fee : 0 ) ); ?></td></tr>
                    <tr><td><strong>Repayment Term:</strong></td><td><?php echo esc_html( $app->repayment_term ); ?> months</td></tr>
                </table>

                <h3 style="margin-top: 15px;">Decision &amp; Policy Metadata</h3>
                <table class="widefat fixed striped">
                    <tr><td><strong>Representative:</strong></td><td><?php echo esc_html( $rep ? $rep->name . ' (' . $rep->rep_id . ')' : $app->rep_id_str ); ?></td></tr>
                    <tr><td><strong>Eligibility Result:</strong></td><td><strong><?php echo esc_html( strtoupper( $app->eligibility_result ) ); ?></strong></td></tr>
                    <tr><td><strong>Decision Mode:</strong></td><td><?php echo esc_html( $app->decision_mode ); ?></td></tr>
                    <tr><td><strong>Rule Version:</strong></td><td><?php echo esc_html( $app->rule_version ); ?></td></tr>
                    <tr><td><strong>Terms Accepted:</strong></td><td><?php echo $app->terms_accepted ? 'Yes (' . esc_html( $app->terms_accepted_timestamp ) . ')' : 'Pending'; ?></td></tr>
                    <tr><td><strong>IP Address:</strong></td><td><?php echo esc_html( $app->ip_address ); ?></td></tr>
                    <tr><td><strong>Submitted Date:</strong></td><td><?php echo esc_html( $app->created_at ); ?></td></tr>
                </table>

                <h3 style="margin-top: 15px;">Audit Trail</h3>
                <div style="max-height: 120px; overflow-y: auto; font-size: 11px;">
                    <?php if ( empty( $logs ) ) : ?>
                        <p class="description">No status transitions recorded.</p>
                    <?php else : ?>
                        <ul>
                            <?php foreach ( $logs as $l ) : ?>
                                <li><?php echo esc_html( $l->created_at . ' — ' . $l->action . ': ' . $l->new_value ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Email Delivery & Resend Block -->
                <h3 style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                    <span>Email Delivery</span>
                    <button type="button" class="button button-small button-primary nexafi-resend-email-btn" data-app-id="<?php echo esc_attr( $app->id ); ?>" style="font-size: 11px;">
                        <span>Resend Email</span>
                    </button>
                </h3>
                <div id="nexafi-modal-email-notice" style="display:none; font-size: 12px; padding: 6px 10px; margin-bottom: 6px; border-radius: 4px;"></div>
                <?php
                $app_email_logs = $db->get_email_logs( array( 'app_id' => $app->id, 'per_page' => 3 ) );
                if ( empty( $app_email_logs ) ) :
                ?>
                    <p class="description" style="font-size: 11px;">No email records found for this application.</p>
                <?php else : ?>
                    <table class="widefat fixed striped" style="font-size: 11px;">
                        <?php foreach ( $app_email_logs as $elog ) : ?>
                            <tr>
                                <td style="width: 30%;"><?php echo esc_html( wp_date( 'M j, g:i A', strtotime( $elog->sent_at ) ) ); ?></td>
                                <td style="width: 25%;">
                                    <?php if ( 'sent' === $elog->status ) : ?>
                                        <span class="nexafi-badge nexafi-badge-success" style="background:#dcfce7;color:#15803d;padding:2px 6px;border-radius:3px;font-weight:700;">Sent</span>
                                    <?php else : ?>
                                        <span class="nexafi-badge nexafi-badge-danger" style="background:#fee2e2;color:#b91c1c;padding:2px 6px;border-radius:3px;font-weight:700;">Failed</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html( $elog->error_message ? $elog->error_message : 'Delivered to mail server' ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
        $html = ob_get_clean();
        wp_send_json_success( array(
            'reference' => $app->reference_number,
            'html'      => $html,
        ) );
    }

    /**
     * AJAX handler to update application status from dropdown
     */
    public function ajax_update_app_status() {
        check_ajax_referer( 'nexafi_admin_action_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }

        $app_id     = isset( $_POST['app_id'] ) ? intval( $_POST['app_id'] ) : 0;
        $new_status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

        $db = NexaFi_DB::get_instance();
        $ok = $db->update_application_status( $app_id, $new_status, 'Admin quick status dropdown' );

        if ( $ok ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( array( 'message' => 'Could not update status.' ) );
        }
    }

    /**
     * AJAX handler to toggle representative active status
     */
    public function ajax_toggle_rep() {
        check_ajax_referer( 'nexafi_admin_action_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }

        $rep_id  = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        $active  = ! empty( $_POST['active'] );

        $db = NexaFi_DB::get_instance();
        $ok = $db->set_representative_active( $rep_id, $active );

        if ( $ok ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( array( 'message' => 'Could not update representative status.' ) );
        }
    }

    /**
     * AJAX handler to delete an email delivery log entry from database
     */
    public function ajax_delete_email_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nexafi-plugin' ) ), 403 );
        }

        $log_id = isset( $_POST['log_id'] ) ? intval( $_POST['log_id'] ) : 0;
        check_ajax_referer( 'nexafi_delete_log_' . $log_id, 'security' );

        $db = NexaFi_DB::get_instance();
        $ok = $db->delete_email_log( $log_id );

        if ( $ok ) {
            wp_send_json_success( array(
                'message' => __( 'Email log deleted successfully.', 'nexafi-plugin' ),
                'log_id'  => $log_id,
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Could not delete email log from database.', 'nexafi-plugin' ) ) );
        }
    }
}
