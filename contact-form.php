<?php
/**
 * Plugin Name: NexaFi Financing Application & Contact Form
 * Plugin URI:https://github.com/workwithasim/NexaFi-Plugin
 * Description: Complete 5-screen patient financing application workflow, eligibility engine, GDG representative management, reporting, and admin dashboard for NexaFi / GDG Smiles.
 * Version: 2.4.12
 * Author: NexaFi & GDG Smiles Team
 * Author URI:https://xortlogix.com/
 * Text Domain: nexafi-plugin
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 *
 * @package NexaFi_Financing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'NEXAFI_CF_VERSION', '2.4.12' );
define( 'NEXAFI_CF_PLUGIN_FILE', __FILE__ );
define( 'NEXAFI_CF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEXAFI_CF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NEXAFI_CF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main NexaFi Financing Plugin Bootstrap
 */
final class NexaFi_Financing_Plugin {

    /**
     * Singleton instance
     *
     * @var NexaFi_Financing_Plugin|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return NexaFi_Financing_Plugin
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
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Include all required classes
     */
    private function load_dependencies() {
        require_once NEXAFI_CF_PLUGIN_DIR . 'includes/class-nexafi-calculator.php';
        require_once NEXAFI_CF_PLUGIN_DIR . 'includes/class-nexafi-db.php';
        require_once NEXAFI_CF_PLUGIN_DIR . 'includes/class-nexafi-eligibility.php';
        require_once NEXAFI_CF_PLUGIN_DIR . 'includes/class-nexafi-ajax.php';
        require_once NEXAFI_CF_PLUGIN_DIR . 'includes/class-nexafi-assets.php';
        require_once NEXAFI_CF_PLUGIN_DIR . 'includes/class-nexafi-shortcode.php';
        require_once NEXAFI_CF_PLUGIN_DIR . 'includes/class-nexafi-admin.php';
        require_once NEXAFI_CF_PLUGIN_DIR . 'includes/class-nexafi-updater.php';
    }

    /**
     * Initialize plugin hooks
     */
    private function init_hooks() {
        // Lifecycle hooks
        register_activation_hook( NEXAFI_CF_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( NEXAFI_CF_PLUGIN_FILE, array( $this, 'deactivate' ) );

        // Instantiate components
        add_action( 'plugins_loaded', array( $this, 'bootstrap' ) );

        // Add settings action link to plugins list
        add_filter( 'plugin_action_links_' . NEXAFI_CF_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );
    }

    /**
     * Plugin activation handler
     */
    public function activate() {
        $db = NexaFi_DB::get_instance();
        $db->create_tables();

        // Seed default options if not existing
        if ( false === get_option( 'nexafi_upfront_pct' ) ) {
            update_option( 'nexafi_upfront_pct', 70.0 );
        }
        if ( false === get_option( 'nexafi_financed_pct' ) ) {
            update_option( 'nexafi_financed_pct', 30.0 );
        }
        if ( false === get_option( 'nexafi_charge_pct' ) ) {
            update_option( 'nexafi_charge_pct', 15.0 );
        }
        if ( false === get_option( 'nexafi_repayment_term' ) ) {
            update_option( 'nexafi_repayment_term', 12 );
        }
        if ( false === get_option( 'nexafi_decision_mode' ) ) {
            update_option( 'nexafi_decision_mode', 'final_authorized' );
        }

        update_option( 'nexafi_db_version', NEXAFI_CF_VERSION );
    }

    /**
     * Plugin deactivation handler
     */
    public function deactivate() {
        // Flush rewrite rules safely if needed
    }

    /**
     * Bootstrap instances
     */
    public function bootstrap() {
        // Self-healing database check
        if ( get_option( 'nexafi_db_version' ) !== NEXAFI_CF_VERSION ) {
            $db = NexaFi_DB::get_instance();
            $db->create_tables();
            update_option( 'nexafi_db_version', NEXAFI_CF_VERSION );
        }

        NexaFi_Assets::get_instance();
        NexaFi_Ajax::get_instance();
        NexaFi_Shortcode::get_instance();
        NexaFi_Admin::get_instance();
        NexaFi_Updater::get_instance();
    }

    /**
     * Add settings link to plugins page
     */
    public function add_plugin_action_links( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'admin.php?page=nexafi-financing' ) ),
            __( 'Dashboard', 'nexafi-plugin' )
        );
        $check_update_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( wp_nonce_url( admin_url( 'plugins.php?nexafi_check_update=1' ), 'nexafi_check_update_nonce' ) ),
            __( 'Check for Updates', 'nexafi-plugin' )
        );
        array_unshift( $links, $settings_link, $check_update_link );
        return $links;
    }
}

// Start the plugin
NexaFi_Financing_Plugin::get_instance();
