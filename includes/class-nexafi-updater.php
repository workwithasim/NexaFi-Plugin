<?php
/**
 * NexaFi GitHub Auto-Updater
 *
 * Checks for updates from the official GitHub repository (workwithasim/NexaFi-Plugin)
 * and seamlessly displays update notifications in WordPress, handles package downloads,
 * and extracts updates into the proper plugin directory.
 *
 * @package NexaFi_Financing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * NexaFi Updater Class
 */
class NexaFi_Updater {

    /**
     * Singleton instance
     *
     * @var NexaFi_Updater|null
     */
    private static $instance = null;

    /**
     * Plugin slug (folder name)
     *
     * @var string
     */
    private $slug = 'contact-form';

    /**
     * Plugin basename (e.g. contact-form/contact-form.php)
     *
     * @var string
     */
    private $plugin_basename = 'contact-form/contact-form.php';

    /**
     * Current plugin version
     *
     * @var string
     */
    private $current_version = '2.4.11';

    /**
     * GitHub repository path (owner/repo)
     *
     * @var string
     */
    private $github_repo = 'workwithasim/NexaFi-Plugin';

    /**
     * Cache key for transient
     *
     * @var string
     */
    private $transient_key = 'nexafi_github_update_cache';

    /**
     * Cache lifetime in seconds (default 6 hours)
     *
     * @var int
     */
    private $cache_ttl = 21600;

    /**
     * Get singleton instance
     *
     * @return NexaFi_Updater
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
        if ( defined( 'NEXAFI_CF_VERSION' ) ) {
            $this->current_version = NEXAFI_CF_VERSION;
        }

        if ( defined( 'NEXAFI_CF_PLUGIN_BASENAME' ) ) {
            $this->plugin_basename = NEXAFI_CF_PLUGIN_BASENAME;
            $this->slug            = dirname( $this->plugin_basename );
        }

        // Check for updates in WordPress transient
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

        // Provide plugin information popup on "View version X.X.X details"
        add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );

        // Ensure the downloaded GitHub zip extracts to the correct plugin directory (priority 5 runs before check_package at 10)
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_directory' ), 5, 4 );

        // Purge update cache after plugin upgrade
        add_action( 'upgrader_process_complete', array( $this, 'purge_cache' ), 10, 2 );

        // Allow manual refresh via admin query param
        add_action( 'admin_init', array( $this, 'maybe_force_check' ) );
    }

    /**
     * Check if a manual update check was requested
     */
    public function maybe_force_check() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        // WordPress native "Check Again" button on Dashboard -> Updates sets force-check
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['force-check'] ) || ( ! empty( $_GET['nexafi_check_update'] ) && check_admin_referer( 'nexafi_check_update_nonce' ) ) ) {
            delete_site_transient( $this->transient_key );
            delete_site_transient( 'update_plugins' );
            if ( function_exists( 'wp_clean_plugins_cache' ) ) {
                wp_clean_plugins_cache();
            }
        }
    }

    /**
     * Check GitHub for a new version and inject into update_plugins transient
     *
     * @param object $transient
     * @return object
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        // Dynamically find our plugin basename if the folder name is different (e.g. NexaFi-Plugin-main vs contact-form)
        if ( ! isset( $transient->checked[ $this->plugin_basename ] ) ) {
            foreach ( array_keys( $transient->checked ) as $installed_plugin ) {
                if ( substr( $installed_plugin, -16 ) === 'contact-form.php' ) {
                    $this->plugin_basename = $installed_plugin;
                    $this->slug            = dirname( $installed_plugin );
                    break;
                }
            }
        }

        if ( ! isset( $transient->checked[ $this->plugin_basename ] ) ) {
            return $transient;
        }

        $remote = $this->get_remote_release_info();
        if ( ! $remote || empty( $remote->new_version ) ) {
            return $transient;
        }

        $item = (object) array(
            'id'            => 'https://github.com/' . $this->github_repo,
            'slug'          => $this->slug,
            'plugin'        => $this->plugin_basename,
            'new_version'   => $remote->new_version,
            'url'           => 'https://github.com/' . $this->github_repo,
            'package'       => $remote->download_url,
            'icons'         => array(),
            'banners'       => array(),
            'banners_rtl'   => array(),
            'tested'        => '7.1',
            'requires_php'  => '7.4',
            'compatibility' => new stdClass(),
        );

        if ( version_compare( $this->current_version, $remote->new_version, '<' ) ) {
            $transient->response[ $this->plugin_basename ] = $item;
            if ( isset( $transient->no_update[ $this->plugin_basename ] ) ) {
                unset( $transient->no_update[ $this->plugin_basename ] );
            }
        } else {
            $transient->no_update[ $this->plugin_basename ] = $item;
            if ( isset( $transient->response[ $this->plugin_basename ] ) ) {
                unset( $transient->response[ $this->plugin_basename ] );
            }
        }

        return $transient;
    }

    /**
     * Handle "View version details" modal in WordPress admin
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public function plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }

        $remote = $this->get_remote_release_info();
        if ( ! $remote ) {
            return $result;
        }

        $res = new stdClass();
        $res->name           = 'NexaFi Financing Application & Contact Form';
        $res->slug           = $this->slug;
        $res->version        = $remote->new_version;
        $res->author         = '<a href="https://nexafi.com/">NexaFi & GDG Smiles Team</a>';
        $res->author_profile = 'https://github.com/' . $this->github_repo;
        $res->homepage       = 'https://github.com/' . $this->github_repo;
        $res->requires       = '5.8';
        $res->tested         = '7.1';
        $res->requires_php   = '7.4';
        $res->downloaded     = 0;
        $res->last_updated   = ! empty( $remote->published_at ) ? $remote->published_at : gmdate( 'Y-m-d' );
        $res->sections       = array(
            'description' => esc_html__( 'Complete 5-screen patient financing application workflow, eligibility engine, GDG representative management, reporting, and admin dashboard for NexaFi / GDG Smiles.', 'nexafi-plugin' ),
            'changelog'   => ! empty( $remote->changelog ) ? wp_kses_post( wpautop( $remote->changelog ) ) : esc_html__( 'Please visit the GitHub repository for release notes.', 'nexafi-plugin' ),
        );
        $res->download_link  = $remote->download_url;

        return $res;
    }

    /**
     * Ensure the downloaded GitHub zip file is renamed to the expected plugin directory name
     *
     * When GitHub generates zip archives (either for releases or branch commits),
     * the root folder inside the zip is named e.g. "workwithasim-NexaFi-Plugin-abc" or "NexaFi-Plugin-main".
     * This filter renames it to "contact-form" so WordPress updates the existing plugin in place.
     *
     * @param string|WP_Error $source        Path to current source folder.
     * @param string          $remote_source Remote source path.
     * @param WP_Upgrader     $upgrader      WP_Upgrader instance.
     * @param array|null      $hook_extra    Extra hook data.
     * @return string|WP_Error
     */
    public function fix_source_directory( $source, $remote_source, $upgrader, $hook_extra = null ) {
        global $wp_filesystem;

        if ( is_wp_error( $source ) ) {
            return $source;
        }

        $source_clean = untrailingslashit( $source );

        // Robust detection: verify if this package is actually our NexaFi plugin
        $is_our_plugin = false;

        // 1. Check hook_extra plugin parameter
        if ( ! empty( $hook_extra['plugin'] ) ) {
            if ( $hook_extra['plugin'] === $this->plugin_basename || false !== strpos( $hook_extra['plugin'], 'contact-form.php' ) ) {
                $is_our_plugin = true;
            }
        }

        // 2. Direct filesystem inspection: check if contact-form.php exists in the root of the source directory
        if ( ! $is_our_plugin && ( file_exists( $source_clean . '/contact-form.php' ) || ( $wp_filesystem && $wp_filesystem->exists( $source_clean . '/contact-form.php' ) ) ) ) {
            $is_our_plugin = true;
        }

        // If this is not our plugin, do not touch the source path
        if ( ! $is_our_plugin ) {
            return $source;
        }

        $target_slug = ! empty( $this->slug ) ? $this->slug : 'contact-form';

        // If the folder is already correctly named, return as-is
        if ( basename( $source_clean ) === $target_slug ) {
            return trailingslashit( $source_clean );
        }

        // Destination path in upgrade directory without trailing slash for atomic rename
        $new_source = untrailingslashit( dirname( $source_clean ) ) . '/' . $target_slug;

        // Remove any stale directory that might already exist with this target name
        if ( $wp_filesystem && $wp_filesystem->exists( $new_source ) ) {
            $wp_filesystem->delete( $new_source, true );
        } elseif ( file_exists( $new_source ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFileSystem
            @unlink( $new_source );
        }

        $renamed = false;

        // Strategy A: Native PHP rename without trailing slashes
        if ( @rename( $source_clean, $new_source ) ) {
            $renamed = true;
        }

        // Strategy B: WP_Filesystem move
        if ( ! $renamed && $wp_filesystem && $wp_filesystem->move( $source_clean, $new_source, true ) ) {
            $renamed = true;
        }

        // Strategy C: Copy directory and delete source
        if ( ! $renamed && function_exists( 'copy_dir' ) ) {
            $copy_res = copy_dir( $source_clean, $new_source );
            if ( ! is_wp_error( $copy_res ) ) {
                if ( $wp_filesystem ) {
                    $wp_filesystem->delete( $source_clean, true );
                }
                $renamed = true;
            }
        }

        // If all rename strategies failed, return a WP_Error to ABORT upgrade safely,
        // preventing WordPress from deleting the existing plugin and breaking the installation.
        if ( ! $renamed ) {
            return new WP_Error(
                'nexafi_folder_rename_error',
                sprintf(
                    /* translators: 1: original folder name, 2: target slug */
                    __( 'NexaFi Updater: Unable to rename update directory "%1$s" to "%2$s". Upgrade aborted to protect the existing plugin files.', 'nexafi-plugin' ),
                    esc_html( basename( $source_clean ) ),
                    esc_html( $target_slug )
                )
            );
        }

        return trailingslashit( $new_source );
    }

    /**
     * Purge update cache on complete
     *
     * @param WP_Upgrader $upgrader
     * @param array       $options
     */
    public function purge_cache( $upgrader, $options ) {
        if ( isset( $options['action'], $options['type'] ) && 'update' === $options['action'] && 'plugin' === $options['type'] ) {
            delete_site_transient( $this->transient_key );
        }
    }

    /**
     * Fetch latest release info from GitHub (with caching)
     *
     * Strategy:
     * 1. Check official GitHub Releases API first (best for tagged releases).
     * 2. If no releases exist or 404, fallback to inspecting contact-form.php header on main branch.
     *
     * @return object|false
     */
    private function get_remote_release_info() {
        $cached = get_site_transient( $this->transient_key );
        if ( false !== $cached && is_object( $cached ) ) {
            return $cached;
        }

        $http_args = array(
            'timeout'    => 10,
            'headers'    => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
            'sslverify'  => true,
        );

        $candidates = array();

        // 1. Try GitHub Releases API
        $api_url  = 'https://api.github.com/repos/' . $this->github_repo . '/releases/latest';
        $response = wp_remote_get( $api_url, $http_args );

        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( ! empty( $data['tag_name'] ) ) {
                $version  = ltrim( sanitize_text_field( $data['tag_name'] ), 'vV' );
                $download = ! empty( $data['zipball_url'] ) ? esc_url_raw( $data['zipball_url'] ) : '';

                if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
                    foreach ( $data['assets'] as $asset ) {
                        if ( ! empty( $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['name'] ?? '' ) ) {
                            $download = esc_url_raw( $asset['browser_download_url'] );
                            break;
                        }
                    }
                }

                $candidates[] = (object) array(
                    'new_version'  => $version,
                    'download_url' => $download,
                    'changelog'    => ! empty( $data['body'] ) ? wp_kses_post( $data['body'] ) : '',
                    'published_at' => ! empty( $data['published_at'] ) ? sanitize_text_field( $data['published_at'] ) : current_time( 'mysql' ),
                );
            }
        }

        // 2. Try GitHub Tags API (for git tags like v2.4.4, v2.4.5)
        $tags_url  = 'https://api.github.com/repos/' . $this->github_repo . '/tags';
        $response = wp_remote_get( $tags_url, $http_args );

        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $body = wp_remote_retrieve_body( $response );
            $tags = json_decode( $body, true );

            if ( ! empty( $tags ) && is_array( $tags ) && ! empty( $tags[0]['name'] ) ) {
                $version  = ltrim( sanitize_text_field( $tags[0]['name'] ), 'vV' );
                // Direct archive URL avoids GitHub API unauthenticated rate limits (60 req/hr)
                $download = 'https://github.com/' . $this->github_repo . '/archive/refs/tags/' . $tags[0]['name'] . '.zip';

                $candidates[] = (object) array(
                    'new_version'  => $version,
                    'download_url' => $download,
                    'changelog'    => esc_html__( 'New release available from GitHub.', 'nexafi-plugin' ),
                    'published_at' => current_time( 'mysql' ),
                );
            }
        }

        // 3. Inspect raw contact-form.php on main branch
        $raw_url  = 'https://raw.githubusercontent.com/' . $this->github_repo . '/main/contact-form.php';
        $response = wp_remote_get( $raw_url, $http_args );

        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $content = wp_remote_retrieve_body( $response );
            if ( preg_match( '/^[ \t\/*#@]*Version:\s*([0-9\.]+)/mi', $content, $matches ) ) {
                $version  = sanitize_text_field( $matches[1] );
                $download = 'https://github.com/' . $this->github_repo . '/archive/refs/heads/main.zip';

                $candidates[] = (object) array(
                    'new_version'  => $version,
                    'download_url' => $download,
                    'changelog'    => esc_html__( 'Latest update from GitHub main branch.', 'nexafi-plugin' ),
                    'published_at' => current_time( 'mysql' ),
                );
            }
        }

        if ( empty( $candidates ) ) {
            return false;
        }

        // Sort descending by version and pick the highest version
        usort(
            $candidates,
            function( $a, $b ) {
                return version_compare( $b->new_version, $a->new_version );
            }
        );

        $best = $candidates[0];
        set_site_transient( $this->transient_key, $best, $this->cache_ttl );
        return $best;
    }
}
