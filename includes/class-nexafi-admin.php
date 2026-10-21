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
    }

    /**
     * Register Admin Menus
     */
    public function register_admin_menus() {
        $hook_main = add_menu_page(
            __( 'NexaFi Financing', 'contact-form' ),
            __( 'NexaFi Financing', 'contact-form' ),
            'manage_options',
            'nexafi-financing',
            array( $this, 'render_applications_page' ),
            'dashicons-money-alt',
            26
        );

        add_submenu_page(
            'nexafi-financing',
            __( 'Applications — NexaFi', 'contact-form' ),
            __( 'Applications', 'contact-form' ),
            'manage_options',
            'nexafi-financing',
            array( $this, 'render_applications_page' )
        );

        add_submenu_page(
            'nexafi-financing',
            __( 'GDG Representatives — NexaFi', 'contact-form' ),
            __( 'Representatives', 'contact-form' ),
            'manage_options',
            'nexafi-representatives',
            array( $this, 'render_representatives_page' )
        );

        add_submenu_page(
            'nexafi-financing',
            __( 'Email Logs — NexaFi', 'contact-form' ),
            __( 'Email Logs', 'contact-form' ),
            'manage_options',
            'nexafi-email-logs',
            array( $this, 'render_email_logs_page' )
        );

        add_submenu_page(
            'nexafi-financing',
            __( 'Reports & Analytics — NexaFi', 'contact-form' ),
            __( 'Reports', 'contact-form' ),
            'manage_options',
            'nexafi-reports',
            array( $this, 'render_reports_page' )
        );

        add_submenu_page(
            'nexafi-financing',
            __( 'Settings & Policies — NexaFi', 'contact-form' ),
            __( 'Settings', 'contact-form' ),
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
                'confirm_delete'      => __( 'Are you sure you want to delete this record? This action cannot be undone.', 'contact-form' ),
                'confirm_policy_text' => __( 'WARNING: Enabling Final Approval wording confirms written authorization has been obtained from GDG & iCare. Are you sure you wish to enable final approval mode?', 'contact-form' ),
            ),
        ) );
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
            'Treatment Quote',
            'Representative ID',
            'Eligibility Result',
            'Eligibility Reason',
            'Decision Mode',
            'Upfront Amount',
            'Financed Amount',
            'Financing Charge',
            'Total Repayment',
            'Monthly Payment',
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

        $upfront_pct    = floatval( $_POST['nexafi_upfront_pct'] ?? 70.0 );
        $financed_pct   = floatval( $_POST['nexafi_financed_pct'] ?? 30.0 );
        $charge_pct     = floatval( $_POST['nexafi_charge_pct'] ?? 15.0 );
        $repayment_term = intval( $_POST['nexafi_repayment_term'] ?? 12 );
        $max_quote      = floatval( $_POST['nexafi_max_quote'] ?? 50000.0 );

        // Validate percentage balance
        $val_res = NexaFi_Calculator::validate_settings( $upfront_pct, $financed_pct, $charge_pct, $repayment_term );
        if ( is_wp_error( $val_res ) ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'nexafi-settings', 'error' => urlencode( $val_res->get_error_message() ) ), admin_url( 'admin.php' ) ) );
            exit;
        }

        update_option( 'nexafi_upfront_pct', $upfront_pct );
        update_option( 'nexafi_financed_pct', $financed_pct );
        update_option( 'nexafi_charge_pct', $charge_pct );
        update_option( 'nexafi_repayment_term', $repayment_term );
        update_option( 'nexafi_max_quote', $max_quote );

        // Automated Decision Policy: Always Final Authorized (Rule-based)
        $new_mode = 'final_authorized';
        $old_mode = get_option( 'nexafi_decision_mode', 'final_authorized' );
        if ( $old_mode !== $new_mode ) {
            update_option( 'nexafi_decision_mode', $new_mode );
            $db = NexaFi_DB::get_instance();
            $db->log_audit( 'settings', 'nexafi_decision_mode', 'policy_mode_change', $old_mode, $new_mode );
        }

        // Messages & URLs
        update_option( 'nexafi_ineligibility_message', sanitize_textarea_field( wp_unslash( $_POST['nexafi_ineligibility_message'] ?? '' ) ) );
        update_option( 'nexafi_preliminary_message', sanitize_textarea_field( wp_unslash( $_POST['nexafi_preliminary_message'] ?? '' ) ) );
        update_option( 'nexafi_privacy_policy_url', esc_url_raw( wp_unslash( $_POST['nexafi_privacy_policy_url'] ?? '' ) ) );
        update_option( 'nexafi_return_url', esc_url_raw( wp_unslash( $_POST['nexafi_return_url'] ?? '' ) ) );
        update_option( 'nexafi_contact_fallback_text', sanitize_text_field( wp_unslash( $_POST['nexafi_contact_fallback_text'] ?? '' ) ) );

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
                'label' => __( 'Applications', 'contact-form' ),
                'url'   => admin_url( 'admin.php?page=nexafi-financing' ),
                'icon'  => 'dashicons-clipboard',
            ),
            'representatives' => array(
                'label' => __( 'Representatives', 'contact-form' ),
                'url'   => admin_url( 'admin.php?page=nexafi-representatives' ),
                'icon'  => 'dashicons-id-alt',
            ),
            'email-logs'      => array(
                'label' => __( 'Email Logs', 'contact-form' ),
                'url'   => admin_url( 'admin.php?page=nexafi-email-logs' ),
                'icon'  => 'dashicons-email-alt',
            ),
            'reports'         => array(
                'label' => __( 'Reports', 'contact-form' ),
                'url'   => admin_url( 'admin.php?page=nexafi-reports' ),
                'icon'  => 'dashicons-chart-area',
            ),
            'settings'        => array(
                'label' => __( 'Settings', 'contact-form' ),
                'url'   => admin_url( 'admin.php?page=nexafi-settings' ),
                'icon'  => 'dashicons-admin-settings',
            ),
        );

        $form_page_url = get_option( 'nexafi_form_page_url', home_url( '/form/' ) );
        ?>
        <header class="nexafi-topnav">
            <div class="nexafi-topnav__brand">
                <div class="nexafi-topnav__logo">
                    <span class="dashicons dashicons-money-alt"></span>
                    <span class="nexafi-topnav__brand-name">NexaFi</span>
                </div>
                <span class="nexafi-topnav__version">v<?php echo esc_html( NEXAFI_CF_VERSION ); ?></span>
            </div>

            <nav class="nexafi-topnav__menu" aria-label="<?php esc_attr_e( 'NexaFi Navigation', 'contact-form' ); ?>">
                <?php foreach ( $tabs as $slug => $tab ) : ?>
                    <a href="<?php echo esc_url( $tab['url'] ); ?>" class="nexafi-navlink <?php echo $slug === $active_tab ? 'nexafi-navlink--active' : ''; ?>">
                        <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
                        <span><?php echo esc_html( $tab['label'] ); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="nexafi-topnav__actions">
                <a href="<?php echo esc_url( $form_page_url ); ?>" target="_blank" rel="noopener noreferrer" class="nexafi-topnav__ext-link" title="<?php esc_attr_e( 'Open Public Patient Form', 'contact-form' ); ?>">
                    <span class="dashicons dashicons-external"></span>
                    <span><?php esc_html_e( 'View Form', 'contact-form' ); ?></span>
                </a>
            </div>
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
                    ), admin_url( 'admin.php' ) ), 'nexafi_export_csv_action', 'nexafi_export_nonce' ) ); ?>" class="button button-primary nexafi-export-btn">
                        <span class="dashicons dashicons-download"></span> Export Filtered CSV
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

                        <button type="submit" class="button button-secondary">Filter</button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=nexafi-financing' ) ); ?>" class="button">Reset</a>
                    </div>
                </form>
            </div>

            <!-- Applications Table -->
            <div class="nexafi-table-container">
                <table class="wp-list-table widefat fixed striped nexafi-apps-table">
                    <thead>
                        <tr>
                            <th style="width: 170px;">Reference #</th>
                            <th>Applicant</th>
                            <th>Representative</th>
                            <th>Quote / Financed</th>
                            <th>Eligibility Decision</th>
                            <th style="width: 155px; min-width: 145px;">Application Status</th>
                            <th style="width: 140px;">Submitted</th>
                            <th style="width: 175px; min-width: 160px;"><?php esc_html_e( 'Actions', 'contact-form' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $applications ) ) : ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 30px;">
                                    <em>No applications found matching your criteria.</em>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $applications as $app ) : ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <a href="#" class="nexafi-view-detail-link" data-app-id="<?php echo esc_attr( $app->id ); ?>">
                                                <?php echo esc_html( $app->reference_number ); ?>
                                            </a>
                                        </strong>
                                    </td>
                                    <td>
                                        <div class="nexafi-applicant-cell">
                                            <strong><?php echo esc_html( $app->first_name . ' ' . $app->last_name ); ?></strong>
                                            <div class="nexafi-sub-meta"><?php echo esc_html( $app->email ); ?> | <?php echo esc_html( $app->phone ); ?></div>
                                            <?php if ( ! empty( $app->employer_name ) || ! empty( $app->job_title ) ) : ?>
                                                <div class="nexafi-sub-meta" style="color: #64748b; font-size: 11px; margin-top: 2px;">
                                                    💼 <?php echo esc_html( trim( ( $app->job_title ?: '' ) . ( ( $app->job_title && $app->employer_name ) ? ' at ' : '' ) . ( $app->employer_name ?: '' ) ) ); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="nexafi-rep-tag"><?php echo esc_html( $app->rep_id_str ); ?></span>
                                    </td>
                                    <td>
                                        <div><strong>Quote:</strong> <?php echo esc_html( NexaFi_Calculator::format_currency( $app->treatment_quote ) ); ?></div>
                                        <div class="nexafi-sub-meta">Financed: <?php echo esc_html( NexaFi_Calculator::format_currency( $app->financed_amount ) ); ?> (<?php echo esc_html( NexaFi_Calculator::format_currency( $app->monthly_payment ) ); ?>/mo)</div>
                                    </td>
                                    <td>
                                        <?php if ( 'approved' === $app->eligibility_result || 'approved' === $app->application_status ) : ?>
                                            <span class="nexafi-badge nexafi-badge-success">Approved</span>
                                            <div class="nexafi-sub-meta" style="font-size:11px;">Automated Rule</div>
                                        <?php else : ?>
                                            <span class="nexafi-badge nexafi-badge-warning">Ineligible</span>
                                            <div class="nexafi-sub-meta" style="font-size:11px;">Criteria Unmet</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( ! empty( $app->terms_accepted ) || 'accepted' === $app->application_status ) : ?>
                                            <span class="nexafi-badge nexafi-badge-primary" style="background:#2563eb;color:#fff;font-weight:600;padding:4px 10px;border-radius:12px;display:inline-block;">Accepted Offer</span>
                                        <?php elseif ( 'approved' === $app->eligibility_result || 'approved' === $app->application_status ) : ?>
                                            <span class="nexafi-badge nexafi-badge-success" style="background:#10b981;color:#fff;font-weight:600;padding:4px 10px;border-radius:12px;display:inline-block;">Approved</span>
                                        <?php else : ?>
                                            <span class="nexafi-badge nexafi-badge-warning" style="background:#f59e0b;color:#fff;font-weight:600;padding:4px 10px;border-radius:12px;display:inline-block;">Ineligible</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $app->created_at ) ) ); ?>
                                    </td>
                                    <td>
                                        <div class="nexafi-table-actions">
                                            <button type="button" class="button button-small nexafi-action-btn nexafi-action-btn-view nexafi-view-detail-btn" data-app-id="<?php echo esc_attr( $app->id ); ?>" title="<?php esc_attr_e( 'View application details', 'contact-form' ); ?>">
                                                <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                                <span><?php esc_html_e( 'View', 'contact-form' ); ?></span>
                                            </button>
                                            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array(
                                                'nexafi_action' => 'delete_application',
                                                'app_id'        => $app->id,
                                            ), admin_url( 'admin.php?page=nexafi-financing' ) ), 'nexafi_delete_app_' . $app->id, 'nexafi_delete_nonce' ) ); ?>" 
                                               class="button button-small nexafi-action-btn nexafi-action-btn-delete" 
                                               onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this application record?', 'contact-form' ) ); ?>');" 
                                               title="<?php esc_attr_e( 'Delete application', 'contact-form' ); ?>">
                                                <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                                <span><?php esc_html_e( 'Delete', 'contact-form' ); ?></span>
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
                            echo esc_html( sprintf( _n( '%s item', '%s items', $total_count, 'contact-form' ), number_format_i18n( $total_count ) ) );
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
                    <button type="button" class="button button-primary" id="nexafi-add-rep-btn">
                        <span class="dashicons dashicons-plus-alt2"></span> Add Representative
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
            <div class="nexafi-table-responsive">
                <table class="wp-list-table widefat fixed striped nexafi-reps-table" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th style="width: 70px;">Photo</th>
                            <th style="width: 120px;">Rep ID</th>
                            <th>Name</th>
                            <th>Contact Details</th>
                            <th style="width: 140px;">Applications</th>
                            <th style="width: 110px;">Status</th>
                            <th style="width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $reps ) ) : ?>
                            <tr><td colspan="7" style="text-align:center; padding: 20px;"><em>No representatives found. Click "Add Representative" to create one.</em></td></tr>
                        <?php else : ?>
                            <?php foreach ( $reps as $r ) : ?>
                                <?php
                                $default_photo = plugins_url( 'assets/images/rep-caleb.png', dirname( __FILE__ ) );
                                $photo = ! empty( $r->photo_url ) ? $r->photo_url : $default_photo;
                                ?>
                                <tr>
                                    <td>
                                        <img src="<?php echo esc_url( $photo ); ?>" alt="<?php echo esc_attr( $r->name ); ?>" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 1px solid #cbd5e1;" />
                                    </td>
                                    <td><strong class="nexafi-rep-code"><?php echo esc_html( $r->rep_id ); ?></strong></td>
                                    <td><strong><?php echo esc_html( $r->name ); ?></strong></td>
                                    <td>
                                        <div><?php echo esc_html( $r->email ); ?></div>
                                        <div class="nexafi-sub-meta"><?php echo esc_html( $r->phone ); ?></div>
                                    </td>
                                    <td>
                                        <span class="nexafi-badge nexafi-badge-info"><?php echo intval( $r->apps_count ); ?> applications</span>
                                    </td>
                                    <td>
                                        <?php if ( $r->is_active ) : ?>
                                            <span class="nexafi-badge nexafi-badge-success">Active</span>
                                        <?php else : ?>
                                            <span class="nexafi-badge nexafi-badge-warning">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="nexafi-rep-actions-wrap">
                                            <button type="button" class="button button-small nexafi-edit-rep-btn" 
                                                    data-id="<?php echo esc_attr( $r->id ); ?>"
                                                    data-rep-id="<?php echo esc_attr( $r->rep_id ); ?>"
                                                    data-name="<?php echo esc_attr( $r->name ); ?>"
                                                    data-email="<?php echo esc_attr( $r->email ); ?>"
                                                    data-phone="<?php echo esc_attr( $r->phone ); ?>"
                                                    data-photo="<?php echo esc_attr( $r->photo_url ); ?>"
                                                    data-notes="<?php echo esc_attr( $r->notes ); ?>"
                                                    data-active="<?php echo esc_attr( $r->is_active ); ?>">
                                                <?php esc_html_e( 'Edit', 'contact-form' ); ?>
                                            </button>
                                            <button type="button" class="button button-small nexafi-toggle-rep-btn" data-id="<?php echo esc_attr( $r->id ); ?>" data-active="<?php echo esc_attr( $r->is_active ); ?>">
                                                <?php echo $r->is_active ? esc_html__( 'Deactivate', 'contact-form' ) : esc_html__( 'Activate', 'contact-form' ); ?>
                                            </button>
                                            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array(
                                                'nexafi_action' => 'delete_representative',
                                                'rep_id'        => $r->id,
                                            ), admin_url( 'admin.php?page=nexafi-representatives' ) ), 'nexafi_delete_rep_' . $r->id, 'nexafi_delete_nonce' ) ); ?>" class="button button-small nexafi-delete-rep-btn" onclick="return confirm('<?php echo esc_js( __( 'Delete this representative?', 'contact-form' ) ); ?>');">
                                                <?php esc_html_e( 'Delete', 'contact-form' ); ?>
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
                <div class="nexafi-modal-dialog" style="max-width: 550px;">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=nexafi-representatives' ) ); ?>">
                        <?php wp_nonce_field( 'nexafi_save_rep_action', 'nexafi_rep_nonce' ); ?>
                        <input type="hidden" name="nexafi_action" value="save_representative" />
                        <input type="hidden" name="rep_db_id" id="rep_db_id" value="0" />

                        <div class="nexafi-modal-header">
                            <h2 class="nexafi-modal-title" id="rep-modal-title"><?php esc_html_e( 'Add Representative', 'contact-form' ); ?></h2>
                            <button type="button" class="nexafi-modal-close">&times;</button>
                        </div>
                        <div class="nexafi-modal-body">
                            <div class="nexafi-form-grid-2">
                                <div class="nexafi-form-col">
                                    <label class="nexafi-admin-label"><?php esc_html_e( 'Representative ID (e.g. GDG1027) *', 'contact-form' ); ?></label>
                                    <input type="text" name="rep_id" id="rep_field_id" class="widefat" required style="text-transform: uppercase;" />
                                </div>
                                <div class="nexafi-form-col">
                                    <label class="nexafi-admin-label"><?php esc_html_e( 'Full Name *', 'contact-form' ); ?></label>
                                    <input type="text" name="name" id="rep_field_name" class="widefat" required />
                                </div>
                            </div>
                            <div class="nexafi-form-grid-2">
                                <div class="nexafi-form-col">
                                    <label class="nexafi-admin-label"><?php esc_html_e( 'Email Address', 'contact-form' ); ?></label>
                                    <input type="email" name="email" id="rep_field_email" class="widefat" />
                                </div>
                                <div class="nexafi-form-col">
                                    <label class="nexafi-admin-label"><?php esc_html_e( 'Phone Number', 'contact-form' ); ?></label>
                                    <input type="text" name="phone" id="rep_field_phone" class="widefat" />
                                </div>
                            </div>
                            <div class="nexafi-form-row">
                                <label class="nexafi-admin-label"><?php esc_html_e( 'Representative Photo', 'contact-form' ); ?></label>
                                <input type="hidden" name="photo_url" id="rep_field_photo" value="" />
                                <div class="nexafi-photo-upload-wrap">
                                    <div class="nexafi-photo-preview-box">
                                        <img id="rep_photo_preview_img" src="" alt="<?php esc_attr_e( 'Preview', 'contact-form' ); ?>" style="display: none;" />
                                        <div id="rep_photo_placeholder" class="nexafi-photo-placeholder">
                                            <span class="dashicons dashicons-admin-users"></span>
                                        </div>
                                    </div>
                                    <div class="nexafi-photo-controls">
                                        <div class="nexafi-photo-btn-group">
                                            <button type="button" class="button button-secondary" id="nexafi-upload-photo-btn">
                                                <span id="nexafi-upload-btn-text"><?php esc_html_e( 'Upload Photo', 'contact-form' ); ?></span>
                                            </button>
                                            <button type="button" class="button button-link-delete" id="nexafi-remove-photo-btn">
                                                <?php esc_html_e( 'Delete Photo', 'contact-form' ); ?>
                                            </button>
                                        </div>
                                        <p class="description" style="margin-top: 5px;"><?php esc_html_e( 'Upload or select a photo. Click Delete Photo to remove.', 'contact-form' ); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="nexafi-form-row">
                                <label class="nexafi-admin-label"><?php esc_html_e( 'Notes', 'contact-form' ); ?></label>
                                <textarea name="notes" id="rep_field_notes" class="widefat" rows="3"></textarea>
                            </div>
                            <div class="nexafi-form-row">
                                <label>
                                    <input type="checkbox" name="is_active" id="rep_field_active" value="1" checked />
                                    <?php esc_html_e( 'Active (Eligible to receive applications)', 'contact-form' ); ?>
                                </label>
                            </div>
                        </div>
                        <div class="nexafi-modal-footer">
                            <a href="#" id="nexafi-modal-delete-rep-link" class="button button-link-delete nexafi-modal-delete-rep-btn" style="display: none;" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this representative?', 'contact-form' ) ); ?>');">
                                <?php esc_html_e( 'Delete Representative', 'contact-form' ); ?>
                            </a>
                            <div class="nexafi-modal-footer-actions">
                                <button type="button" class="button button-secondary nexafi-modal-close-btn"><?php esc_html_e( 'Cancel', 'contact-form' ); ?></button>
                                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Representative', 'contact-form' ); ?></button>
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
                       onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear all email delivery logs?', 'contact-form' ) ); ?>');">
                        <?php esc_html_e( 'Clear All Logs', 'contact-form' ); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( $deleted ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Email log entry deleted successfully.', 'contact-form' ); ?></p></div>
            <?php endif; ?>

            <?php if ( $cleared ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'All email delivery logs cleared successfully.', 'contact-form' ); ?></p></div>
            <?php endif; ?>

            <!-- Notification banner placeholder for AJAX resend responses -->
            <div id="nexafi-resend-notice-box" style="display:none;margin-top:15px;" class="notice is-dismissible">
                <p id="nexafi-resend-notice-text"></p>
            </div>

            <!-- Filter & Search Bar -->
            <div class="nexafi-filter-bar" style="margin-top: 15px;">
                <form method="get" class="nexafi-admin-filters">
                    <input type="hidden" name="page" value="nexafi-email-logs" />
                    
                    <input type="text" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search recipient, subject, reference...', 'contact-form' ); ?>" class="nexafi-admin-input" style="min-width: 260px;" />

                    <select name="status" class="nexafi-admin-select">
                        <option value=""><?php esc_html_e( 'All Delivery Statuses', 'contact-form' ); ?></option>
                        <option value="sent" <?php selected( $status, 'sent' ); ?>><?php esc_html_e( 'Sent Successfully', 'contact-form' ); ?></option>
                        <option value="failed" <?php selected( $status, 'failed' ); ?>><?php esc_html_e( 'Delivery Failed', 'contact-form' ); ?></option>
                    </select>

                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Filter Logs', 'contact-form' ); ?></button>
                    <?php if ( ! empty( $search ) || ! empty( $status ) ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=nexafi-email-logs' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Reset Filters', 'contact-form' ); ?></a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Email Logs Table -->
            <table class="wp-list-table widefat fixed striped nexafi-admin-table" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th style="width: 50px;"><?php esc_html_e( 'ID', 'contact-form' ); ?></th>
                        <th style="width: 140px;"><?php esc_html_e( 'Date & Time', 'contact-form' ); ?></th>
                        <th style="width: 180px;"><?php esc_html_e( 'Recipient', 'contact-form' ); ?></th>
                        <th style="width: 140px;"><?php esc_html_e( 'Application Ref #', 'contact-form' ); ?></th>
                        <th style="width: 130px;"><?php esc_html_e( 'Email Type', 'contact-form' ); ?></th>
                        <th style="width: 90px;"><?php esc_html_e( 'Status', 'contact-form' ); ?></th>
                        <th><?php esc_html_e( 'Delivery Details / Error', 'contact-form' ); ?></th>
                        <th style="width: 195px; min-width: 180px; text-align: left;"><?php esc_html_e( 'Actions', 'contact-form' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 30px; color: #64748b;">
                                <?php esc_html_e( 'No email delivery logs recorded yet. Outgoing emails from NexaFi will appear here.', 'contact-form' ); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $log ) : 
                            $is_sent = ( 'sent' === $log->status );
                            $type_label = ( 'resend' === $log->email_type ) ? __( 'Resend Notification', 'contact-form' ) : ( ( 'admin_notification' === $log->email_type ) ? __( 'Admin Alert', 'contact-form' ) : __( 'Applicant Email', 'contact-form' ) );
                            $type_bg = ( 'resend' === $log->email_type ) ? '#fef3c7;color:#92400e;' : ( ( 'admin_notification' === $log->email_type ) ? '#e0e7ff;color:#3730a3;' : '#f1f5f9;color:#334155;' );
                        ?>
                        <tr id="nexafi-log-row-<?php echo intval( $log->id ); ?>">
                            <td>#<?php echo intval( $log->id ); ?></td>
                            <td><?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $log->sent_at ) ) ); ?></td>
                            <td>
                                <strong><?php echo esc_html( $log->recipient_name ? $log->recipient_name : 'Applicant' ); ?></strong><br>
                                <a href="mailto:<?php echo esc_attr( $log->recipient_email ); ?>" style="font-size: 12px; color: #2563eb;"><?php echo esc_html( $log->recipient_email ); ?></a>
                            </td>
                            <td>
                                <?php if ( ! empty( $log->reference_number ) ) : ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=nexafi-financing&search=' . urlencode( $log->reference_number ) ) ); ?>" class="nexafi-view-detail-btn" data-app-id="<?php echo esc_attr( $log->application_id ); ?>" style="font-family: monospace; font-weight: 700;">
                                        <?php echo esc_html( $log->reference_number ); ?>
                                    </a>
                                <?php else : ?>
                                    <span style="color: #94a3b8;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: <?php echo esc_attr( $type_bg ); ?>">
                                    <?php echo esc_html( $type_label ); ?>
                                </span>
                            </td>
                            <td class="nexafi-log-status-cell">
                                <?php if ( $is_sent ) : ?>
                                    <span class="nexafi-badge nexafi-badge-success" style="background:#dcfce7;color:#15803d;padding:4px 8px;border-radius:4px;font-weight:700;font-size:11px;display:inline-block;">&#10003; Sent</span>
                                <?php else : ?>
                                    <span class="nexafi-badge nexafi-badge-danger" style="background:#fee2e2;color:#b91c1c;padding:4px 8px;border-radius:4px;font-weight:700;font-size:11px;display:inline-block;">&#10007; Failed</span>
                                <?php endif; ?>
                            </td>
                            <td class="nexafi-log-error-cell">
                                <?php if ( ! empty( $log->error_message ) ) : ?>
                                    <span style="color: #b91c1c; font-size: 12px; line-height: 1.4; display: block; max-width: 320px; word-break: break-word;">
                                        ⚠️ <?php echo esc_html( $log->error_message ); ?>
                                    </span>
                                <?php else : ?>
                                    <span style="color: #64748b; font-size: 12px;">Delivered to mail server</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="nexafi-table-actions">
                                    <?php if ( ! empty( $log->application_id ) ) : ?>
                                        <button type="button" 
                                                class="button button-small nexafi-action-btn nexafi-action-btn-resend nexafi-resend-email-btn" 
                                                data-log-id="<?php echo esc_attr( $log->id ); ?>" 
                                                data-app-id="<?php echo esc_attr( $log->application_id ); ?>"
                                                title="<?php esc_attr_e( 'Resend confirmation email to applicant', 'contact-form' ); ?>">
                                            <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
                                            <span><?php esc_html_e( 'Resend', 'contact-form' ); ?></span>
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
                                       title="<?php esc_attr_e( 'Delete log', 'contact-form' ); ?>">
                                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                        <span><?php esc_html_e( 'Delete', 'contact-form' ); ?></span>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav" style="margin-top: 15px;">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php
                        /* translators: %s: number of items */
                        echo esc_html( sprintf( _n( '%s item', '%s items', $total_count, 'contact-form' ), number_format_i18n( $total_count ) ) ); ?></span>
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
                    <p class="description"><?php esc_html_e( 'Real-time breakdown of applications, authorized financing totals, and GDG representative performance.', 'contact-form' ); ?></p>
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

            <div style="margin-top: 30px;">
                <h2>Applications Grouped by Representative</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Rep ID</th>
                            <th>Representative Name</th>
                            <th>Total Applications</th>
                            <th>Approved / Eligible</th>
                            <th>Accepted Offers</th>
                            <th>Total Treatment Quotes</th>
                            <th>Total Financed Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rep_stats ) ) : ?>
                            <tr><td colspan="7">No representative application data available.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $rep_stats as $rs ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $rs->rep_id ); ?></strong></td>
                                    <td><?php echo esc_html( $rs->name ); ?></td>
                                    <td><strong><?php echo intval( $rs->total_apps ); ?></strong></td>
                                    <td><span class="nexafi-badge nexafi-badge-success"><?php echo intval( $rs->approved_apps ); ?></span></td>
                                    <td><span class="nexafi-badge nexafi-badge-blue"><?php echo intval( $rs->accepted_apps ); ?></span></td>
                                    <td><?php echo esc_html( NexaFi_Calculator::format_currency( $rs->total_quote_amount ) ); ?></td>
                                    <td><strong><?php echo esc_html( NexaFi_Calculator::format_currency( $rs->total_financed_amount ) ); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 30px;">
                <h2>Applications by Date (Last 30 Days)</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Total Applications</th>
                            <th>Approved</th>
                            <th>Ineligible</th>
                            <th>Offers Accepted</th>
                            <th>Submitted Treatment Volume</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $date_stats ) ) : ?>
                            <tr><td colspan="6">No historical data recorded in the last 30 days.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $date_stats as $ds ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( wp_date( 'M j, Y (l)', strtotime( $ds->app_date ) ) ); ?></strong></td>
                                    <td><?php echo intval( $ds->total_apps ); ?></td>
                                    <td><span class="nexafi-badge nexafi-badge-success"><?php echo intval( $ds->approved_apps ); ?></span></td>
                                    <td><span class="nexafi-badge nexafi-badge-warning"><?php echo intval( $ds->ineligible_apps ); ?></span></td>
                                    <td><span class="nexafi-badge nexafi-badge-blue"><?php echo intval( $ds->accepted_apps ); ?></span></td>
                                    <td><strong><?php echo esc_html( NexaFi_Calculator::format_currency( $ds->total_volume ) ); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * RENDER PAGE 4: Settings & Policies
     */
    public function render_settings_page() {
        $upfront_pct    = floatval( get_option( 'nexafi_upfront_pct', 70.0 ) );
        $financed_pct   = floatval( get_option( 'nexafi_financed_pct', 30.0 ) );
        $charge_pct     = floatval( get_option( 'nexafi_charge_pct', 15.0 ) );
        $repayment_term = intval( get_option( 'nexafi_repayment_term', 12 ) );
        $max_quote      = floatval( get_option( 'nexafi_max_quote', 50000.0 ) );
        $decision_mode  = get_option( 'nexafi_decision_mode', 'preliminary_only' );

        $ineligibility_msg = get_option(
            'nexafi_ineligibility_message',
            __( 'Based on your self-reported declarations, your application does not currently meet our standard automated financing criteria. However, our team is here to help discuss alternative options for your treatment.', 'contact-form' )
        );

        $prelim_msg = get_option(
            'nexafi_preliminary_message',
            __( 'Your self-reported declarations satisfy initial financing eligibility. A GDG representative will review your treatment plan to finalize your financing.', 'contact-form' )
        );

        $privacy_url = get_option( 'nexafi_privacy_policy_url', home_url( '/privacy-policy/' ) );
        $return_url  = get_option( 'nexafi_return_url', home_url( '/' ) );
        $fallback_rep_text = get_option(
            'nexafi_contact_fallback_text',
            __( 'Please contact your GDG representative directly or speak with our front desk.', 'contact-form' )
        );

        ?>
        <div class="wrap nexafi-admin-wrap">
            <?php $this->render_top_navigation( 'settings' ); ?>
            <div class="nexafi-admin-header">
                <div>
                    <h1 class="wp-heading-inline"><span class="nexafi-brand-text">NexaFi</span> Financing &amp; Policy Settings</h1>
                    <p class="description"><?php esc_html_e( 'Configure automated financing parameters, calculation percentages, and customer policies.', 'contact-form' ); ?></p>
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
                                <p class="description">Default: 70%. Patient pays this upfront at the clinic.</p>
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
                                <p class="description">Default: 15% of the financed balance. Not an APR.</p>
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
                            <th scope="row"><label for="nexafi_max_quote">Maximum Allowed Quote ($)</label></th>
                            <td>
                                $ <input type="number" step="100" min="500" name="nexafi_max_quote" id="nexafi_max_quote" value="<?php echo esc_attr( $max_quote ); ?>" class="regular-text" />
                                <p class="description">Maximum treatment quote allowed on the application (Default: $50,000).</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- SECTION 2: Automated Approval Policy -->
                <div class="nexafi-settings-box nexafi-policy-box">
                    <h2>2. Automated Decision &amp; Approval Policy</h2>
                    <p class="description">NexaFi operates as a fully automated, rule-based in-house financing application with zero manual management review.</p>

                    <div style="background: #f0fdf4; border-left: 4px solid #10b981; padding: 14px 18px; border-radius: 6px; margin-top: 14px;">
                        <strong style="color: #065f46; font-size: 14px;">&#10003; Automated Rule-Based Decisioning Active</strong>
                        <p style="margin: 8px 0 0 0; color: #1e293b; font-size: 13px; line-height: 1.6;">
                            Applications are evaluated immediately by the rule engine:
                            <br />&bull; Active GDG Representative ID verified
                            <br />&bull; Valid government ID and active traditional debit card declarations checked
                            <br />&bull; Standard financing formula applied: <strong>70% upfront, 30% approved financing, 15% financing charge, 12 monthly payments</strong>.
                            <br />Qualifying applicants instantly receive the final <strong>"YOU'RE APPROVED!"</strong> screen with zero manual management intervention or waiting period.
                        </p>
                    </div>
                    <input type="hidden" name="nexafi_decision_mode" value="final_authorized" />
                </div>

                <!-- SECTION 3: Content & URLs -->
                <div class="nexafi-settings-box">
                    <h2>3. Patient Messages &amp; Navigation URLs</h2>

                    <table class="form-table">
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
                    <tr><td><strong>Total Treatment Quote:</strong></td><td><strong><?php echo esc_html( NexaFi_Calculator::format_currency( $app->treatment_quote ) ); ?></strong></td></tr>
                    <tr><td><strong>Upfront Contribution (<?php echo esc_html( $app->upfront_pct ); ?>%):</strong></td><td><?php echo esc_html( NexaFi_Calculator::format_currency( $app->upfront_amount ) ); ?></td></tr>
                    <tr><td><strong>Financed Balance (<?php echo esc_html( $app->financed_pct ); ?>%):</strong></td><td><?php echo esc_html( NexaFi_Calculator::format_currency( $app->financed_amount ) ); ?></td></tr>
                    <tr><td><strong>Financing Charge (<?php echo esc_html( $app->charge_pct ); ?>%):</strong></td><td><?php echo esc_html( NexaFi_Calculator::format_currency( $app->charge_amount ) ); ?></td></tr>
                    <tr><td><strong>Total Repayment:</strong></td><td><strong><?php echo esc_html( NexaFi_Calculator::format_currency( $app->total_repayment ) ); ?></strong></td></tr>
                    <tr><td><strong>Monthly Payment (<?php echo esc_html( $app->repayment_term ); ?> mos):</strong></td><td><strong style="color: #10b981; font-size: 15px;"><?php echo esc_html( NexaFi_Calculator::format_currency( $app->monthly_payment ) ); ?>/mo</strong></td></tr>
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
                    <button type="button" class="button button-small button-primary nexafi-resend-email-btn" data-app-id="<?php echo esc_attr( $app->id ); ?>" style="display: inline-flex; align-items: center; gap: 3px; font-size: 11px;">
                        <span class="dashicons dashicons-email-alt" style="font-size: 13px; width: 13px; height: 13px; line-height: 13px;"></span>
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
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'contact-form' ) ), 403 );
        }

        $log_id = isset( $_POST['log_id'] ) ? intval( $_POST['log_id'] ) : 0;
        check_ajax_referer( 'nexafi_delete_log_' . $log_id, 'security' );

        $db = NexaFi_DB::get_instance();
        $ok = $db->delete_email_log( $log_id );

        if ( $ok ) {
            wp_send_json_success( array(
                'message' => __( 'Email log deleted successfully.', 'contact-form' ),
                'log_id'  => $log_id,
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Could not delete email log from database.', 'contact-form' ) ) );
        }
    }
}
