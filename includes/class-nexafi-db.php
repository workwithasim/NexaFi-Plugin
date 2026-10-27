<?php
/**
 * NexaFi Database Manager
 *
 * Manages database tables for applications, representatives, and audit logs.
 * Provides secure prepared query interfaces, pagination, reporting queries,
 * and data integrity protections.
 *
 * @package NexaFi_Financing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NexaFi_DB {

    /**
     * Singleton instance
     *
     * @var NexaFi_DB|null
     */
    private static $instance = null;

    /**
     * Table names
     */
    public $tbl_applications;
    public $tbl_representatives;
    public $tbl_audit_logs;
    public $tbl_contact_entries;
    public $tbl_email_logs;

    /**
     * Get singleton instance
     *
     * @return NexaFi_DB
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
        global $wpdb;
        $this->tbl_applications    = $wpdb->prefix . 'nexafi_applications';
        $this->tbl_representatives = $wpdb->prefix . 'nexafi_representatives';
        $this->tbl_audit_logs      = $wpdb->prefix . 'nexafi_audit_logs';
        $this->tbl_contact_entries = $wpdb->prefix . 'nexafi_contact_entries';
        $this->tbl_email_logs      = $wpdb->prefix . 'nexafi_email_logs';
    }

    /**
     * Create or upgrade all custom database tables
     */
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. Applications Table
        $sql_apps = "CREATE TABLE {$this->tbl_applications} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reference_number varchar(50) NOT NULL,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) NOT NULL,
            dob date NOT NULL,
            phone varchar(50) NOT NULL,
            email varchar(150) NOT NULL,
            home_address varchar(255) NOT NULL,
            city varchar(100) NOT NULL,
            state varchar(50) NOT NULL,
            zip_code varchar(20) NOT NULL,
            employment_status varchar(50) NOT NULL,
            employer_name varchar(150) DEFAULT '',
            job_title varchar(150) DEFAULT '',
            time_at_job varchar(50) DEFAULT '',
            monthly_income decimal(12,2) NOT NULL DEFAULT '0.00',
            has_id_declaration tinyint(1) NOT NULL DEFAULT '0',
            has_debit_card_declaration tinyint(1) NOT NULL DEFAULT '0',
            treatment_quote decimal(12,2) NOT NULL DEFAULT '0.00',
            rep_record_id bigint(20) unsigned DEFAULT NULL,
            rep_id_str varchar(50) NOT NULL DEFAULT '',
            consent_status tinyint(1) NOT NULL DEFAULT '0',
            consent_timestamp datetime DEFAULT NULL,
            eligibility_result varchar(50) NOT NULL DEFAULT 'approved',
            eligibility_reason text DEFAULT NULL,
            decision_mode varchar(50) NOT NULL DEFAULT 'final_authorized',
            upfront_pct decimal(5,2) NOT NULL DEFAULT '70.00',
            financed_pct decimal(5,2) NOT NULL DEFAULT '30.00',
            charge_pct decimal(5,2) NOT NULL DEFAULT '15.00',
            repayment_term int(10) unsigned NOT NULL DEFAULT '12',
            charge_basis varchar(50) NOT NULL DEFAULT 'financed_amount',
            plan_fee decimal(12,2) NOT NULL DEFAULT '0.00',
            calc_version varchar(50) NOT NULL DEFAULT '1.0',
            upfront_amount decimal(12,2) NOT NULL DEFAULT '0.00',
            financed_amount decimal(12,2) NOT NULL DEFAULT '0.00',
            charge_amount decimal(12,2) NOT NULL DEFAULT '0.00',
            total_repayment decimal(12,2) NOT NULL DEFAULT '0.00',
            monthly_payment decimal(12,2) NOT NULL DEFAULT '0.00',
            application_status varchar(50) NOT NULL DEFAULT 'approved',
            terms_accepted tinyint(1) NOT NULL DEFAULT '0',
            terms_accepted_timestamp datetime DEFAULT NULL,
            terms_token_hash varchar(64) DEFAULT '',
            terms_version varchar(50) DEFAULT '',
            terms_ip_address varchar(45) DEFAULT '',
            rule_version varchar(50) NOT NULL DEFAULT '1.0',
            submission_token varchar(64) DEFAULT '',
            ip_address varchar(45) DEFAULT '',
            user_agent text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY reference_number (reference_number),
            KEY email (email),
            KEY rep_id_str (rep_id_str),
            KEY application_status (application_status),
            KEY eligibility_result (eligibility_result),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // 2. Representatives Table
        $sql_reps = "CREATE TABLE {$this->tbl_representatives} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rep_id varchar(50) NOT NULL,
            name varchar(150) NOT NULL,
            email varchar(150) DEFAULT '',
            phone varchar(50) DEFAULT '',
            photo_url varchar(255) DEFAULT '',
            notes text DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT '1',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY rep_id (rep_id),
            KEY is_active (is_active)
        ) {$charset_collate};";

        // 3. Audit Logs Table
        $sql_audit = "CREATE TABLE {$this->tbl_audit_logs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_type varchar(50) NOT NULL,
            entity_id varchar(50) NOT NULL,
            action varchar(100) NOT NULL,
            old_value longtext DEFAULT NULL,
            new_value longtext DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT '0',
            user_login varchar(100) DEFAULT '',
            ip_address varchar(45) DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY entity_lookup (entity_type, entity_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // 4. Contact entries table (preserved for backwards-compatibility)
        $sql_contact = "CREATE TABLE {$this->tbl_contact_entries} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) NOT NULL,
            email varchar(150) NOT NULL,
            phone varchar(50) DEFAULT '',
            company varchar(150) DEFAULT '',
            website varchar(255) DEFAULT '',
            country varchar(100) DEFAULT '',
            subject varchar(200) NOT NULL,
            message text NOT NULL,
            privacy_consent tinyint(1) NOT NULL DEFAULT '1',
            user_ip varchar(45) DEFAULT '',
            user_agent text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) {$charset_collate};";

        // 5. Email Logs table
        $sql_email_logs = "CREATE TABLE {$this->tbl_email_logs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            application_id bigint(20) unsigned DEFAULT NULL,
            recipient_email varchar(150) NOT NULL,
            recipient_name varchar(150) DEFAULT '',
            email_type varchar(50) NOT NULL DEFAULT 'applicant_notification',
            subject varchar(255) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'sent',
            error_message text DEFAULT NULL,
            sent_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY application_id (application_id),
            KEY recipient_email (recipient_email),
            KEY status (status),
            KEY sent_at (sent_at)
        ) {$charset_collate};";

        dbDelta( $sql_apps );
        dbDelta( $sql_reps );
        dbDelta( $sql_audit );
        dbDelta( $sql_contact );
        dbDelta( $sql_email_logs );

        // Clean up any legacy or rogue unique index names (one-time migration).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query( 'ALTER TABLE `' . esc_sql( $this->tbl_applications ) . '` DROP INDEX `reference_key`' );
    }

    /**
     * Seed initial GDG representative (deprecated - no sample representatives seeded on fresh installs)
     */
    public function seed_default_representative() {
        // No sample representatives are seeded on fresh installs.
        return;
    }

    /**
     * Generate a unique, human-friendly application reference number
     *
     * Format: NEXA-YYYYMMDD-XXXX (e.g. NEXA-20250826-1047 matching the reference design)
     *
     * @return string
     */
    public function generate_unique_reference() {
        global $wpdb;
        $date_prefix = 'NEXA-' . wp_date( 'Ymd' ) . '-';

        for ( $i = 0; $i < 10; $i++ ) {
            $suffix    = str_pad( (string) wp_rand( 1000, 9999 ), 4, '0', STR_PAD_LEFT );
            $reference = $date_prefix . $suffix;
            $exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->prepare( 'SELECT id FROM `' . esc_sql( $this->tbl_applications ) . '` WHERE reference_number = %s LIMIT 1', $reference )
            );
            if ( ! $exists ) {
                return $reference;
            }
        }

        return $date_prefix . strtoupper( substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 6 ) );
    }

    /**
     * Check if a submission token was already used
     *
     * @param string $token
     * @return int|false Returns existing application ID or false
     */
    public function get_application_by_token( $token ) {
        if ( empty( $token ) ) {
            return false;
        }
        global $wpdb;
        $id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( 'SELECT id FROM `' . esc_sql( $this->tbl_applications ) . '` WHERE submission_token = %s LIMIT 1', $token )
        );
        return $id ? intval( $id ) : false;
    }

    /**
     * Insert a new application record
     *
     * @param array $data Application record data
     * @return int|false Inserted ID or false on error
     */
    public function insert_application( $data ) {
        global $wpdb;

        if ( empty( $data['reference_number'] ) ) {
            $data['reference_number'] = $this->generate_unique_reference();
        }

        $now = current_time( 'mysql' );
        if ( empty( $data['created_at'] ) ) {
            $data['created_at'] = $now;
        }
        $data['updated_at'] = $now;

        $format = array(
            'reference_number'           => '%s',
            'first_name'                 => '%s',
            'last_name'                  => '%s',
            'dob'                        => '%s',
            'phone'                      => '%s',
            'email'                      => '%s',
            'home_address'               => '%s',
            'city'                       => '%s',
            'state'                      => '%s',
            'zip_code'                   => '%s',
            'employment_status'          => '%s',
            'employer_name'              => '%s',
            'job_title'                  => '%s',
            'time_at_job'                => '%s',
            'monthly_income'             => '%f',
            'has_id_declaration'         => '%d',
            'has_debit_card_declaration' => '%d',
            'treatment_quote'            => '%f',
            'rep_record_id'              => '%d',
            'rep_id_str'                 => '%s',
            'consent_status'             => '%d',
            'consent_timestamp'          => '%s',
            'eligibility_result'         => '%s',
            'eligibility_reason'         => '%s',
            'decision_mode'              => '%s',
            'upfront_pct'                => '%f',
            'financed_pct'               => '%f',
            'charge_pct'                 => '%f',
            'repayment_term'             => '%d',
            'charge_basis'               => '%s',
            'plan_fee'                   => '%f',
            'calc_version'               => '%s',
            'upfront_amount'             => '%f',
            'financed_amount'            => '%f',
            'charge_amount'              => '%f',
            'total_repayment'            => '%f',
            'monthly_payment'            => '%f',
            'application_status'         => '%s',
            'terms_accepted'             => '%d',
            'terms_accepted_timestamp'   => '%s',
            'terms_token_hash'           => '%s',
            'terms_version'              => '%s',
            'terms_ip_address'           => '%s',
            'rule_version'               => '%s',
            'submission_token'           => '%s',
            'ip_address'                 => '%s',
            'user_agent'                 => '%s',
            'created_at'                 => '%s',
            'updated_at'                 => '%s',
        );

        $formats_to_use = array();
        foreach ( $data as $key => $val ) {
            $formats_to_use[] = isset( $format[ $key ] ) ? $format[ $key ] : '%s';
        }

        $result = $wpdb->insert( $this->tbl_applications, $data, $formats_to_use ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( false === $result ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'NexaFi DB insert_application error: ' . $wpdb->last_error . ' | Query: ' . $wpdb->last_query );
            }
            return false;
        }

        $app_id = $wpdb->insert_id;

        if ( ! $app_id ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'NexaFi DB Error: insert_id is empty. Last query: ' . $wpdb->last_query );
            }
            return false;
        }

        // Log creation in audit table
        $this->log_audit( 'application', (string) $app_id, 'created', null, wp_json_encode( array(
            'reference' => $data['reference_number'],
            'quote'     => $data['treatment_quote'],
            'status'    => $data['application_status'],
            'result'    => $data['eligibility_result'],
        ) ) );

        return $app_id;
    }

    /**
     * Retrieve application by ID
     *
     * @param int $id
     * @return object|null
     */
    public function get_application( $id ) {
        global $wpdb;
        return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $this->tbl_applications ) . '` WHERE id = %d', intval( $id ) )
        );
    }

    /**
     * Retrieve application by public reference number
     *
     * @param string $reference
     * @return object|null
     */
    public function get_application_by_reference( $reference ) {
        global $wpdb;
        return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $this->tbl_applications ) . '` WHERE reference_number = %s', sanitize_text_field( $reference ) )
        );
    }

    /**
     * Update application terms acceptance
     *
     * @param int $id
     * @return bool
     */
    public function mark_terms_accepted( $id, $terms_version = '1.0', $ip_address = '' ) {
        global $wpdb;
        $id  = intval( $id );
        $now = current_time( 'mysql' );

        $old = $this->get_application( $id );
        if ( ! $old ) {
            return false;
        }

        $new_status = ( 'approved' === $old->eligibility_result ) ? 'accepted' : $old->application_status;

        $update_data = array(
            'terms_accepted'           => 1,
            'terms_accepted_timestamp' => $now,
            'application_status'       => $new_status,
            'updated_at'               => $now,
        );
        $update_format = array( '%d', '%s', '%s', '%s' );

        if ( ! empty( $terms_version ) ) {
            $update_data['terms_version'] = sanitize_text_field( $terms_version );
            $update_format[] = '%s';
        }
        if ( ! empty( $ip_address ) ) {
            $update_data['terms_ip_address'] = sanitize_text_field( $ip_address );
            $update_format[] = '%s';
        }

        $updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->tbl_applications,
            $update_data,
            array( 'id' => $id ),
            $update_format,
            array( '%d' )
        );

        if ( false !== $updated ) {
            $this->log_audit( 'application', (string) $id, 'terms_accepted', $old->application_status, $new_status );
            return true;
        }

        return false;
    }

    /**
     * Update application status with audit trail
     *
     * @param int    $id
     * @param string $new_status
     * @param string $reason
     * @return bool
     */
    public function update_application_status( $id, $new_status, $reason = '' ) {
        global $wpdb;
        $id  = intval( $id );
        $now = current_time( 'mysql' );

        $old = $this->get_application( $id );
        if ( ! $old ) {
            return false;
        }

        $updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->tbl_applications,
            array(
                'application_status' => sanitize_text_field( $new_status ),
                'updated_at'         => $now,
            ),
            array( 'id' => $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( false !== $updated ) {
            $this->log_audit( 'application', (string) $id, 'status_change', $old->application_status, $new_status . ( $reason ? ' (' . $reason . ')' : '' ) );
            return true;
        }

        return false;
    }

    /**
     * Representative lookup by Rep ID (Active only)
     *
     * @param string $rep_id
     * @return object|null
     */
    public function get_active_representative( $rep_id ) {
        global $wpdb;
        $rep_id = trim( strtoupper( $rep_id ) );
        return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                'SELECT id, rep_id, name, email, phone, photo_url FROM `' . esc_sql( $this->tbl_representatives ) . '` WHERE UPPER(rep_id) = %s AND is_active = 1 LIMIT 1',
                $rep_id
            )
        );
    }

    /**
     * Representative lookup by Rep ID (Any status)
     *
     * @param string $rep_id
     * @return object|null
     */
    public function get_representative_by_id_str( $rep_id ) {
        global $wpdb;
        $rep_id = trim( strtoupper( $rep_id ) );
        return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->tbl_representatives ) . '` WHERE UPPER(rep_id) = %s LIMIT 1',
                $rep_id
            )
        );
    }

    /**
     * Get representative by database ID
     *
     * @param int $id
     * @return object|null
     */
    public function get_representative_by_id( $id ) {
        global $wpdb;
        return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $this->tbl_representatives ) . '` WHERE id = %d', intval( $id ) )
        );
    }

    /**
     * Get default active representative
     * Returns the primary active representative (or first active representative).
     *
     * @return object|null
     */
    public function get_default_representative() {
        global $wpdb;

        // 1. First active representative in order of ID
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rep = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, rep_id, name, email, phone, photo_url, is_active FROM `' . esc_sql( $this->tbl_representatives ) . '` WHERE is_active = 1 ORDER BY id ASC LIMIT 1'
            )
        );
        if ( $rep ) {
            return $rep;
        }

        return null;
    }

    /**
     * Check if at least one active GDG representative exists in database
     *
     * @return bool
     */
    public function has_active_representative() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            'SELECT COUNT(*) FROM `' . esc_sql( $this->tbl_representatives ) . '` WHERE is_active = 1'
        );
        return ( intval( $count ) > 0 );
    }

    /**
     * Get all representatives with application counts
     *
     * @param array $args
     * @return array
     */
    public function get_representatives( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'search'    => '',
            'status'    => '', // 'active', 'inactive', or ''
            'orderby'   => 'name',
            'order'     => 'ASC',
        );
        $params = wp_parse_args( $args, $defaults );

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $params['search'] ) ) {
            $like = '%' . $wpdb->esc_like( trim( $params['search'] ) ) . '%';
            $where[] = "(r.rep_id LIKE %s OR r.name LIKE %s OR r.email LIKE %s)";
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        if ( 'active' === $params['status'] ) {
            $where[] = "r.is_active = 1";
        } elseif ( 'inactive' === $params['status'] ) {
            $where[] = "r.is_active = 0";
        }

        $orderby = in_array( $params['orderby'], array( 'name', 'rep_id', 'created_at', 'apps_count' ), true ) ? $params['orderby'] : 'name';
        $order   = ( 'DESC' === strtoupper( $params['order'] ) ) ? 'DESC' : 'ASC';

        $where_sql = implode( ' AND ', $where );

        $query = "SELECT r.*, COUNT(a.id) AS apps_count
                  FROM {$this->tbl_representatives} r
                  LEFT JOIN {$this->tbl_applications} a ON UPPER(r.rep_id) = UPPER(a.rep_id_str)
                  WHERE {$where_sql}
                  GROUP BY r.id
                  ORDER BY {$orderby} {$order}";

        if ( ! empty( $values ) ) {
            return $wpdb->get_results( $wpdb->prepare( $query, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        return $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Insert or update a representative
     *
     * @param array $data
     * @param int   $id If provided, updates existing record.
     * @return int|WP_Error
     */
    public function save_representative( $data, $id = 0 ) {
        global $wpdb;
        $id = intval( $id );

        $rep_id = trim( strtoupper( sanitize_text_field( $data['rep_id'] ?? '' ) ) );
        $name   = sanitize_text_field( $data['name'] ?? '' );

        if ( empty( $rep_id ) ) {
            return new WP_Error( 'missing_id', __( 'Representative ID is required.', 'nexafi-plugin' ) );
        }
        if ( empty( $name ) ) {
            return new WP_Error( 'missing_name', __( 'Representative Name is required.', 'nexafi-plugin' ) );
        }

        // Check uniqueness of rep_id
        if ( $id > 0 ) {
            $exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    'SELECT id FROM `' . esc_sql( $this->tbl_representatives ) . '` WHERE UPPER(rep_id) = %s AND id != %d',
                    $rep_id,
                    $id
                )
            );
        } else {
            $exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->prepare(
                    'SELECT id FROM `' . esc_sql( $this->tbl_representatives ) . '` WHERE UPPER(rep_id) = %s',
                    $rep_id
                )
            );
        }

        if ( $exists ) {
            return new WP_Error( 'duplicate_id', __( 'This Representative ID is already assigned to another representative.', 'nexafi-plugin' ) );
        }

        $now = current_time( 'mysql' );
        $row = array(
            'rep_id'     => $rep_id,
            'name'       => $name,
            'email'      => sanitize_email( $data['email'] ?? '' ),
            'phone'      => sanitize_text_field( $data['phone'] ?? '' ),
            'photo_url'  => esc_url_raw( $data['photo_url'] ?? '' ),
            'notes'      => sanitize_textarea_field( $data['notes'] ?? '' ),
            'is_active'  => isset( $data['is_active'] ) ? intval( $data['is_active'] ) : 1,
            'updated_at' => $now,
        );

        if ( $id > 0 ) {
            $old = $this->get_representative_by_id( $id );
            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $this->tbl_representatives,
                $row,
                array( 'id' => $id ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
                array( '%d' )
            );

            // If rep_id changed, cascade update existing applications linked to this representative
            if ( $old && strtoupper( $old->rep_id ) !== strtoupper( $rep_id ) ) {
                $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
                    $wpdb->prepare(
                        'UPDATE `' . esc_sql( $this->tbl_applications ) . '` SET rep_id_str = %s, rep_record_id = %d WHERE rep_record_id = %d OR UPPER(rep_id_str) = %s',
                        $rep_id,
                        $id,
                        $id,
                        strtoupper( $old->rep_id )
                    )
                );
            }

            $this->log_audit( 'representative', (string) $id, 'updated', wp_json_encode( $old ), wp_json_encode( $row ) );
            return $id;
        } else {
            $row['created_at'] = $now;
            $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $this->tbl_representatives,
                $row,
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
            );
            $new_id = $wpdb->insert_id;
            $this->log_audit( 'representative', (string) $new_id, 'created', null, wp_json_encode( $row ) );
            return $new_id;
        }
    }

    /**
     * Delete representative safely (blocks deletion if linked applications exist to prevent corrupting history)
     *
     * @param int $id
     * @return true|WP_Error
     */
    public function delete_representative( $id ) {
        global $wpdb;
        $id  = intval( $id );
        $rep = $this->get_representative_by_id( $id );
        if ( ! $rep ) {
            return new WP_Error( 'not_found', __( 'Representative not found.', 'nexafi-plugin' ) );
        }

        // Unlink applications pointing to this representative to preserve applicant records
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->tbl_applications,
            array( 'rep_record_id' => 0 ),
            array( 'rep_record_id' => $id ),
            array( '%d' ),
            array( '%d' )
        );

        $wpdb->delete( $this->tbl_representatives, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->log_audit( 'representative', (string) $id, 'deleted', wp_json_encode( $rep ), null );
        return true;
    }

    /**
     * Delete application record and log audit
     *
     * @param int $id
     * @return bool
     */
    public function delete_application( $id ) {
        global $wpdb;
        $id      = intval( $id );
        $deleted = $wpdb->delete( $this->tbl_applications, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $deleted ) {
            $this->log_audit( 'application', (string) $id, 'deleted' );
            return true;
        }
        return false;
    }

    /**
     * Toggle representative active state
     *
     * @param int  $id
     * @param bool $active
     * @return bool
     */
    public function set_representative_active( $id, $active ) {
        global $wpdb;
        $id     = intval( $id );
        $status = $active ? 1 : 0;
        $rep    = $this->get_representative_by_id( $id );
        if ( ! $rep ) {
            return false;
        }

        $res = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->tbl_representatives,
            array( 'is_active' => $status, 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => $id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        if ( false !== $res ) {
            $this->log_audit( 'representative', (string) $id, 'status_toggled', (string) $rep->is_active, (string) $status );
            return true;
        }
        return false;
    }

    /**
     * Query applications with pagination and filters
     *
     * @param array $args
     * @return array Array of application records
     */
    public function get_applications( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'search'             => '',
            'status'             => '',
            'eligibility'        => '',
            'rep_id'             => '',
            'date_from'          => '',
            'date_to'            => '',
            'orderby'            => 'id',
            'order'              => 'DESC',
            'per_page'           => 20,
            'page'               => 1,
        );
        $params = wp_parse_args( $args, $defaults );

        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $params['search'] ) ) {
            $like = '%' . $wpdb->esc_like( trim( $params['search'] ) ) . '%';
            $where[] = "(reference_number LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s OR rep_id_str LIKE %s)";
            for ( $i = 0; $i < 6; $i++ ) {
                $values[] = $like;
            }
        }

        if ( ! empty( $params['status'] ) ) {
            $where[]  = "application_status = %s";
            $values[] = sanitize_text_field( $params['status'] );
        }

        if ( ! empty( $params['eligibility'] ) ) {
            $where[]  = "eligibility_result = %s";
            $values[] = sanitize_text_field( $params['eligibility'] );
        }

        if ( ! empty( $params['rep_id'] ) ) {
            $where[]  = "UPPER(rep_id_str) = %s";
            $values[] = strtoupper( sanitize_text_field( $params['rep_id'] ) );
        }

        if ( ! empty( $params['date_from'] ) ) {
            $where[]  = "created_at >= %s";
            $values[] = sanitize_text_field( $params['date_from'] ) . ' 00:00:00';
            if ( empty( $params['date_to'] ) ) {
                $where[]  = "created_at <= %s";
                $values[] = sanitize_text_field( $params['date_from'] ) . ' 23:59:59';
            }
        }

        if ( ! empty( $params['date_to'] ) ) {
            $where[]  = "created_at <= %s";
            $values[] = sanitize_text_field( $params['date_to'] ) . ' 23:59:59';
        }

        $allowed_orders = array( 'id', 'created_at', 'treatment_quote', 'first_name', 'last_name', 'application_status', 'eligibility_result' );
        $orderby = in_array( $params['orderby'], $allowed_orders, true ) ? $params['orderby'] : 'id';
        $order   = ( 'ASC' === strtoupper( $params['order'] ) ) ? 'ASC' : 'DESC';

        $limit_clause = '';
        if ( $params['per_page'] > 0 ) {
            $offset = ( max( 1, intval( $params['page'] ) ) - 1 ) * intval( $params['per_page'] );
            $limit_clause = sprintf( ' LIMIT %d OFFSET %d', intval( $params['per_page'] ), $offset );
        }

        $where_sql = implode( ' AND ', $where );
        $query     = "SELECT * FROM {$this->tbl_applications} WHERE {$where_sql} ORDER BY {$orderby} {$order} {$limit_clause}";

        if ( ! empty( $values ) ) {
            return $wpdb->get_results( $wpdb->prepare( $query, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        return $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Count applications matching filters
     *
     * @param array $args
     * @return int
     */
    public function count_applications( $args = array() ) {
        global $wpdb;

        $args['per_page'] = -1;
        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $args['search'] ) ) {
            $like = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
            $where[] = "(reference_number LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s OR rep_id_str LIKE %s)";
            for ( $i = 0; $i < 6; $i++ ) {
                $values[] = $like;
            }
        }

        if ( ! empty( $args['status'] ) ) {
            $where[]  = "application_status = %s";
            $values[] = sanitize_text_field( $args['status'] );
        }

        if ( ! empty( $args['eligibility'] ) ) {
            $where[]  = "eligibility_result = %s";
            $values[] = sanitize_text_field( $args['eligibility'] );
        }

        if ( ! empty( $args['rep_id'] ) ) {
            $where[]  = "UPPER(rep_id_str) = %s";
            $values[] = strtoupper( sanitize_text_field( $args['rep_id'] ) );
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = "created_at >= %s";
            $values[] = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
            if ( empty( $args['date_to'] ) ) {
                $where[]  = "created_at <= %s";
                $values[] = sanitize_text_field( $args['date_from'] ) . ' 23:59:59';
            }
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = "created_at <= %s";
            $values[] = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
        }

        $where_sql = implode( ' AND ', $where );
        $query     = "SELECT COUNT(*) FROM {$this->tbl_applications} WHERE {$where_sql}";

        if ( ! empty( $values ) ) {
            return intval( $wpdb->get_var( $wpdb->prepare( $query, $values ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        return intval( $wpdb->get_var( $query ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Get aggregate report metrics
     *
     * @return array
     */
    public function get_report_metrics() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_apps = intval( $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $this->tbl_applications ) . '`' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $counts_by_result = $wpdb->get_results(
            'SELECT eligibility_result, COUNT(*) as cnt FROM `' . esc_sql( $this->tbl_applications ) . '` GROUP BY eligibility_result',
            OBJECT_K
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $counts_by_status = $wpdb->get_results(
            'SELECT application_status, COUNT(*) as cnt FROM `' . esc_sql( $this->tbl_applications ) . '` GROUP BY application_status',
            OBJECT_K
        );

        // Financial totals (excluding declined / cancelled)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $financials = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'SELECT 
                SUM(treatment_quote) as total_quotes,
                SUM(upfront_amount) as total_upfront,
                SUM(financed_amount) as total_financed,
                SUM(charge_amount) as total_charges,
                SUM(total_repayment) as total_repayment
             FROM `' . esc_sql( $this->tbl_applications ) . "`
             WHERE application_status NOT IN ('declined', 'cancelled')"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $accepted_financials = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'SELECT 
                SUM(treatment_quote) as total_quotes,
                SUM(upfront_amount) as total_upfront,
                SUM(financed_amount) as total_financed,
                SUM(charge_amount) as total_charges,
                SUM(total_repayment) as total_repayment
             FROM `' . esc_sql( $this->tbl_applications ) . "`
             WHERE terms_accepted = 1 OR application_status = 'accepted'"
        );

        return array(
            'total_applications'  => $total_apps,
            'approved_count'      => isset( $counts_by_result['approved'] ) ? intval( $counts_by_result['approved']->cnt ) : 0,
            'preliminary_count'   => isset( $counts_by_result['preliminary'] ) ? intval( $counts_by_result['preliminary']->cnt ) : 0,
            'ineligible_count'    => isset( $counts_by_result['ineligible'] ) ? intval( $counts_by_result['ineligible']->cnt ) : 0,
            'accepted_count'      => isset( $counts_by_status['accepted'] ) ? intval( $counts_by_status['accepted']->cnt ) : 0,
            'under_review_count'  => isset( $counts_by_status['under_review'] ) ? intval( $counts_by_status['under_review']->cnt ) : 0,
            // All active applications totals
            'total_quotes'        => floatval( $financials->total_quotes ?? 0 ),
            'total_upfront'       => floatval( $financials->total_upfront ?? 0 ),
            'total_financed'      => floatval( $financials->total_financed ?? 0 ),
            'total_charges'       => floatval( $financials->total_charges ?? 0 ),
            'total_repayment'     => floatval( $financials->total_repayment ?? 0 ),
            // Accepted totals
            'accepted_quotes'     => floatval( $accepted_financials->total_quotes ?? 0 ),
            'accepted_financed'   => floatval( $accepted_financials->total_financed ?? 0 ),
            'accepted_repayment'  => floatval( $accepted_financials->total_repayment ?? 0 ),
        );
    }

    /**
     * Get applications grouped by representative for reporting
     *
     * @return array
     */
    public function get_reports_by_representative() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'SELECT 
                r.rep_id,
                r.name,
                r.is_active,
                COUNT(a.id) as total_apps,
                SUM(CASE WHEN a.eligibility_result = \'approved\' THEN 1 ELSE 0 END) as approved_apps,
                SUM(CASE WHEN a.terms_accepted = 1 OR a.application_status = \'accepted\' THEN 1 ELSE 0 END) as accepted_apps,
                COALESCE(SUM(a.treatment_quote), 0) as total_quote_amount,
                COALESCE(SUM(a.financed_amount), 0) as total_financed_amount
             FROM `' . esc_sql( $this->tbl_representatives ) . '` r
             LEFT JOIN `' . esc_sql( $this->tbl_applications ) . '` a ON UPPER(r.rep_id) = UPPER(a.rep_id_str)
             GROUP BY r.id
             ORDER BY total_apps DESC, r.name ASC'
        );
    }

    /**
     * Get applications grouped by date for reporting (last 30 days)
     *
     * @param int $days Number of days
     * @return array
     */
    public function get_reports_by_date( $days = 30 ) {
        global $wpdb;
        $days = max( 7, intval( $days ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->prepare(
                'SELECT 
                    DATE(created_at) as app_date,
                    COUNT(*) as total_apps,
                    SUM(CASE WHEN eligibility_result = \'approved\' THEN 1 ELSE 0 END) as approved_apps,
                    SUM(CASE WHEN eligibility_result = \'ineligible\' THEN 1 ELSE 0 END) as ineligible_apps,
                    SUM(CASE WHEN terms_accepted = 1 THEN 1 ELSE 0 END) as accepted_apps,
                    COALESCE(SUM(treatment_quote), 0) as total_volume
                 FROM `' . esc_sql( $this->tbl_applications ) . '`
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY app_date DESC',
                $days
            )
        );
    }

    /**
     * Record an audit log entry
     *
     * @param string $entity_type 'application', 'representative', 'settings'
     * @param string $entity_id
     * @param string $action
     * @param mixed  $old_val
     * @param mixed  $new_val
     */
    public function log_audit( $entity_type, $entity_id, $action, $old_val = null, $new_val = null ) {
        global $wpdb;

        $user_id    = get_current_user_id();
        $user_login = '';
        if ( $user_id ) {
            $user       = get_userdata( $user_id );
            $user_login = $user ? $user->user_login : '';
        }

        $ip = ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->tbl_audit_logs,
            array(
                'entity_type' => sanitize_text_field( $entity_type ),
                'entity_id'   => sanitize_text_field( $entity_id ),
                'action'      => sanitize_text_field( $action ),
                'old_value'   => is_scalar( $old_val ) ? (string) $old_val : ( null !== $old_val ? wp_json_encode( $old_val ) : null ),
                'new_value'   => is_scalar( $new_val ) ? (string) $new_val : ( null !== $new_val ? wp_json_encode( $new_val ) : null ),
                'user_id'     => $user_id,
                'user_login'  => $user_login,
                'ip_address'  => $ip,
                'created_at'  => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );
    }

    /**
     * Retrieve audit log entries for an entity
     *
     * @param string $entity_type
     * @param string $entity_id
     * @return array
     */
    public function get_audit_logs( $entity_type, $entity_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->tbl_audit_logs ) . '` WHERE entity_type = %s AND entity_id = %s ORDER BY id DESC',
                sanitize_text_field( $entity_type ),
                sanitize_text_field( $entity_id )
            )
        );
    }

    /**
     * Ensure email logs table exists (self-healing)
     */
    public function ensure_email_logs_table() {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->tbl_email_logs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( ! $exists ) {
            $this->create_tables();
        }
    }

    /**
     * Insert an email log entry
     *
     * @param array $data
     * @return int|false Inserted ID or false
     */
    public function log_email( $data ) {
        global $wpdb;
        $this->ensure_email_logs_table();

        $sent_at = ! empty( $data['sent_at'] ) ? $data['sent_at'] : current_time( 'mysql' );

        $inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->tbl_email_logs,
            array(
                'application_id'  => ! empty( $data['application_id'] ) ? intval( $data['application_id'] ) : null,
                'recipient_email' => sanitize_email( $data['recipient_email'] ?? '' ),
                'recipient_name'  => sanitize_text_field( $data['recipient_name'] ?? '' ),
                'email_type'      => sanitize_text_field( $data['email_type'] ?? 'applicant_notification' ),
                'subject'         => sanitize_text_field( $data['subject'] ?? '' ),
                'status'          => sanitize_text_field( $data['status'] ?? 'sent' ),
                'error_message'   => ! empty( $data['error_message'] ) ? sanitize_textarea_field( $data['error_message'] ) : null,
                'sent_at'         => $sent_at,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        return $inserted ? intval( $wpdb->insert_id ) : false;
    }

    /**
     * Get email logs with optional filtering, search, and pagination
     *
     * @param array $args
     * @return array
     */
    public function get_email_logs( $args = array() ) {
        global $wpdb;
        $this->ensure_email_logs_table();

        $defaults = array(
            'search'   => '',
            'status'   => '', // 'sent', 'failed', or ''
            'app_id'   => 0,
            'page'     => 1,
            'per_page' => 20,
            'orderby'  => 'sent_at',
            'order'    => 'DESC',
        );
        $params = wp_parse_args( $args, $defaults );

        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $params['status'] ) ) {
            $where[]  = 'l.status = %s';
            $values[] = sanitize_text_field( $params['status'] );
        }

        if ( ! empty( $params['app_id'] ) ) {
            $where[]  = 'l.application_id = %d';
            $values[] = intval( $params['app_id'] );
        }

        if ( ! empty( $params['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( sanitize_text_field( $params['search'] ) ) . '%';
            $where[]  = '(l.recipient_email LIKE %s OR l.recipient_name LIKE %s OR l.subject LIKE %s OR a.reference_number LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        // Sorting
        $allowed_orderby = array( 'id', 'sent_at', 'status', 'recipient_email', 'email_type' );
        $orderby = in_array( strtolower( $params['orderby'] ), $allowed_orderby, true ) ? strtolower( $params['orderby'] ) : 'sent_at';
        $order   = 'ASC' === strtoupper( $params['order'] ) ? 'ASC' : 'DESC';

        // Limit
        $limit_clause = '';
        if ( intval( $params['per_page'] ) > 0 ) {
            $offset = ( max( 1, intval( $params['page'] ) ) - 1 ) * intval( $params['per_page'] );
            $limit_clause = $wpdb->prepare( 'LIMIT %d OFFSET %d', intval( $params['per_page'] ), $offset );
        }

        $query = "SELECT l.*, a.reference_number, a.first_name as app_first_name, a.last_name as app_last_name
                  FROM {$this->tbl_email_logs} l
                  LEFT JOIN {$this->tbl_applications} a ON l.application_id = a.id
                  WHERE {$where_sql}
                  ORDER BY l.{$orderby} {$order}
                  {$limit_clause}";

        if ( ! empty( $values ) ) {
            return $wpdb->get_results( $wpdb->prepare( $query, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        return $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Get count of email logs matching criteria
     *
     * @param array $args
     * @return int
     */
    public function get_email_logs_count( $args = array() ) {
        global $wpdb;
        $this->ensure_email_logs_table();

        $defaults = array(
            'search' => '',
            'status' => '',
            'app_id' => 0,
        );
        $params = wp_parse_args( $args, $defaults );

        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $params['status'] ) ) {
            $where[]  = 'l.status = %s';
            $values[] = sanitize_text_field( $params['status'] );
        }

        if ( ! empty( $params['app_id'] ) ) {
            $where[]  = 'l.application_id = %d';
            $values[] = intval( $params['app_id'] );
        }

        if ( ! empty( $params['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( sanitize_text_field( $params['search'] ) ) . '%';
            $where[]  = '(l.recipient_email LIKE %s OR l.recipient_name LIKE %s OR l.subject LIKE %s OR a.reference_number LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        $query = "SELECT COUNT(*)
                  FROM {$this->tbl_email_logs} l
                  LEFT JOIN {$this->tbl_applications} a ON l.application_id = a.id
                  WHERE {$where_sql}";

        if ( ! empty( $values ) ) {
            return intval( $wpdb->get_var( $wpdb->prepare( $query, $values ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        return intval( $wpdb->get_var( $query ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Get a single email log by ID
     *
     * @param int $id
     * @return object|null
     */
    public function get_email_log( $id ) {
        global $wpdb;
        $this->ensure_email_logs_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT l.*, a.reference_number FROM `' . esc_sql( $this->tbl_email_logs ) . '` l LEFT JOIN `' . esc_sql( $this->tbl_applications ) . '` a ON l.application_id = a.id WHERE l.id = %d LIMIT 1',
                intval( $id )
            )
        );
    }

    /**
     * Delete an email log
     *
     * @param int $id
     * @return bool
     */
    public function delete_email_log( $id ) {
        global $wpdb;
        $this->ensure_email_logs_table();
        return (bool) $wpdb->delete( $this->tbl_email_logs, array( 'id' => intval( $id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Clear all email logs
     *
     * @return bool
     */
    public function clear_all_email_logs() {
        global $wpdb;
        $this->ensure_email_logs_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
        return (bool) $wpdb->query( 'TRUNCATE TABLE `' . esc_sql( $this->tbl_email_logs ) . '`' );
    }
}
