<?php
/**
 * NexaFi AJAX Controller
 *
 * Handles live representative lookup, application submission,
 * duplicate submission controls, and financing terms acceptance.
 *
 * @package NexaFi_Financing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NexaFi_Ajax {

    /**
     * Singleton instance
     *
     * @var NexaFi_Ajax|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return NexaFi_Ajax
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
        // Representative Lookup
        add_action( 'wp_ajax_nexafi_lookup_rep', array( $this, 'lookup_representative' ) );
        add_action( 'wp_ajax_nopriv_nexafi_lookup_rep', array( $this, 'lookup_representative' ) );

        // Nonce Refresh for cached form pages
        add_action( 'wp_ajax_nexafi_get_nonce', array( $this, 'get_nonce' ) );
        add_action( 'wp_ajax_nopriv_nexafi_get_nonce', array( $this, 'get_nonce' ) );

        // Application Submission
        add_action( 'wp_ajax_nexafi_submit_application', array( $this, 'submit_application' ) );
        add_action( 'wp_ajax_nopriv_nexafi_submit_application', array( $this, 'submit_application' ) );

        // Terms Acceptance
        add_action( 'wp_ajax_nexafi_accept_terms', array( $this, 'accept_terms' ) );
        add_action( 'wp_ajax_nopriv_nexafi_accept_terms', array( $this, 'accept_terms' ) );

        // Admin Resend Email
        add_action( 'wp_ajax_nexafi_admin_resend_email', array( $this, 'admin_resend_email' ) );
    }

    /**
     * Return fresh nonce for cached pages
     */
    public function get_nonce() {
        wp_send_json_success( array(
            'nonce' => wp_create_nonce( 'nexafi_financing_nonce' ),
        ) );
    }

    /**
     * Live GDG Representative lookup
     */
    public function lookup_representative() {
        check_ajax_referer( 'nexafi_financing_nonce', 'security' );

        $rep_id = isset( $_POST['rep_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rep_id'] ) ) : '';

        if ( empty( $rep_id ) ) {
            wp_send_json_error( array(
                'message' => __( 'Please enter a Representative ID.', 'nexafi-plugin' ),
            ) );
        }

        $db  = NexaFi_DB::get_instance();
        $rep = $db->get_active_representative( $rep_id );

        if ( ! $rep ) {
            // Check if representative exists but is inactive
            $inactive = $db->get_representative_by_id_str( $rep_id );
            if ( $inactive && ! $inactive->is_active ) {
                wp_send_json_error( array(
                    'message' => __( 'This Representative ID is currently inactive. Please contact GDG Smiles.', 'nexafi-plugin' ),
                ) );
            }

            wp_send_json_error( array(
                'message' => __( 'Representative ID not found. Please verify with your GDG representative.', 'nexafi-plugin' ),
            ) );
        }

        $default_avatar = plugins_url( 'assets/images/rep-caleb.png', dirname( __FILE__ ) );
        $photo_url      = ! empty( $rep->photo_url ) ? $rep->photo_url : $default_avatar;

        wp_send_json_success( array(
            'id'        => intval( $rep->id ),
            'rep_id'    => esc_html( $rep->rep_id ),
            'name'      => esc_html( $rep->name ),
            'photo_url' => esc_url( $photo_url ),
            'email'     => esc_html( $rep->email ),
            'phone'     => esc_html( $rep->phone ),
        ) );
    }

    /**
     * Process Application Submission
     */
    public function submit_application() {
        // 1. Strict nonce check (CSRF Protection)
        if ( ! check_ajax_referer( 'nexafi_financing_nonce', 'security', false ) ) {
            wp_send_json_error( array(
                'message'       => __( 'Security verification expired. Please refresh the page and try again.', 'nexafi-plugin' ),
                'nonce_expired' => true,
            ), 403 );
        }

        $db = NexaFi_DB::get_instance();

        // 2. Duplicate submission check via token (within 5 seconds)
        $submission_token = isset( $_POST['submission_token'] ) ? sanitize_text_field( wp_unslash( $_POST['submission_token'] ) ) : '';
        if ( ! empty( $submission_token ) ) {
            $existing_id = $db->get_application_by_token( $submission_token );
            if ( $existing_id ) {
                $existing_app = $db->get_application( $existing_id );
                if ( $existing_app && ( time() - strtotime( $existing_app->created_at ) < 5 ) ) {
                    $rep = $db->get_representative_by_id( $existing_app->rep_record_id );
                    wp_send_json_success( $this->format_application_response( $existing_app, $rep ) );
                }
            }
        }

        // 4. Sanitize and validate inputs
        $errors = array();

        $first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        $dob        = sanitize_text_field( wp_unslash( $_POST['dob'] ?? '' ) );
        $phone      = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $email      = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

        $home_address = sanitize_text_field( wp_unslash( $_POST['home_address'] ?? '' ) );
        $city         = sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) );
        $state        = strtoupper( sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ) );
        $zip_code     = sanitize_text_field( wp_unslash( $_POST['zip_code'] ?? '' ) );

        $employment_status = sanitize_text_field( wp_unslash( $_POST['employment_status'] ?? '' ) );
        $employer_name     = sanitize_text_field( wp_unslash( $_POST['employer_name'] ?? '' ) );
        $job_title         = sanitize_text_field( wp_unslash( $_POST['job_title'] ?? '' ) );
        $time_at_job       = sanitize_text_field( wp_unslash( $_POST['time_at_job'] ?? '' ) );
        $raw_income        = sanitize_text_field( wp_unslash( $_POST['monthly_income'] ?? '' ) );
        $income_cleaned    = preg_replace( '/[^\d.]/', '', $raw_income );
        $monthly_income    = floatval( $income_cleaned );

        $has_id_decl    = ( ! empty( $_POST['has_id_declaration'] ) && in_array( (string) $_POST['has_id_declaration'], array( '1', 'true', 'on' ), true ) ) ? 1 : 0;
        $has_debit_decl = ( ! empty( $_POST['has_debit_card_declaration'] ) && in_array( (string) $_POST['has_debit_card_declaration'], array( '1', 'true', 'on' ), true ) ) ? 1 : 0;

        $raw_quote       = sanitize_text_field( wp_unslash( $_POST['treatment_quote'] ?? '' ) );
        $quote_cleaned   = preg_replace( '/[^\d.]/', '', $raw_quote );
        $treatment_quote = floatval( $quote_cleaned );

        $rep_id_input = trim( strtoupper( sanitize_text_field( wp_unslash( $_POST['rep_id'] ?? '' ) ) ) );
        $consent      = ! empty( $_POST['consent'] ) ? 1 : 0;

        // Validation rules
        if ( empty( $first_name ) ) {
            $errors['first_name'] = __( 'First name is required.', 'nexafi-plugin' );
        }
        if ( empty( $last_name ) ) {
            $errors['last_name'] = __( 'Last name is required.', 'nexafi-plugin' );
        }
        if ( empty( $dob ) ) {
            $errors['dob'] = __( 'Date of birth is required.', 'nexafi-plugin' );
        } elseif ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) && ! preg_match( '/^\d{2}\/\d{2}\/\d{4}$/', $dob ) ) {
            $errors['dob'] = __( 'Please enter a valid date of birth.', 'nexafi-plugin' );
        } else {
            // Standardize dob to YYYY-MM-DD
            $time = strtotime( $dob );
            if ( ! $time ) {
                $errors['dob'] = __( 'Invalid date of birth format.', 'nexafi-plugin' );
            } else {
                $dob = gmdate( 'Y-m-d', $time );
            }
        }

        // Phone Validation (10 digits required)
        $phone_digits = preg_replace( '/\D/', '', $phone );
        if ( strlen( $phone_digits ) === 11 && '1' === substr( $phone_digits, 0, 1 ) ) {
            $phone_digits = substr( $phone_digits, 1 );
        }
        if ( strlen( $phone_digits ) !== 10 ) {
            $errors['phone'] = __( 'Please enter a valid 10-digit phone number.', 'nexafi-plugin' );
        } else {
            $phone = sprintf( '(%s) %s-%s', substr( $phone_digits, 0, 3 ), substr( $phone_digits, 3, 3 ), substr( $phone_digits, 6, 4 ) );
        }

        if ( empty( $email ) || ! is_email( $email ) ) {
            $errors['email'] = __( 'Please enter a valid email address.', 'nexafi-plugin' );
        }

        if ( empty( $home_address ) ) {
            $errors['home_address'] = __( 'Home address is required.', 'nexafi-plugin' );
        }
        if ( empty( $city ) ) {
            $errors['city'] = __( 'City is required.', 'nexafi-plugin' );
        }
        if ( empty( $state ) ) {
            $errors['state'] = __( 'State is required.', 'nexafi-plugin' );
        }
        if ( empty( $zip_code ) ) {
            $errors['zip_code'] = __( 'ZIP code is required.', 'nexafi-plugin' );
        } elseif ( ! preg_match( '/^\d{5}(-\d{4})?$/', $zip_code ) ) {
            $errors['zip_code'] = __( 'Please enter a valid 5-digit US ZIP code.', 'nexafi-plugin' );
        }

        if ( empty( $employment_status ) ) {
            $errors['employment_status'] = __( 'Employment status is required.', 'nexafi-plugin' );
        }

        $emp_status_lower = strtolower( $employment_status );
        if ( empty( $employment_status ) || in_array( $emp_status_lower, array( 'employed', 'self-employed' ), true ) ) {
            if ( empty( $employer_name ) ) {
                $errors['employer_name'] = __( 'Employer name is required.', 'nexafi-plugin' );
            }
            if ( empty( $job_title ) ) {
                $errors['job_title'] = __( 'Job title is required.', 'nexafi-plugin' );
            }
            if ( empty( $time_at_job ) ) {
                $errors['time_at_job'] = __( 'Time at job is required.', 'nexafi-plugin' );
            }
        }

        if ( $monthly_income < 0 ) {
            $errors['monthly_income'] = __( 'Monthly income cannot be negative.', 'nexafi-plugin' );
        }

        // Quote Validation
        $max_quote = floatval( get_option( 'nexafi_max_quote', 50000.0 ) );
        if ( $treatment_quote <= 0 ) {
            $errors['treatment_quote'] = __( 'Please enter a valid treatment quote greater than $0.', 'nexafi-plugin' );
        } elseif ( $max_quote > 0 && $treatment_quote > $max_quote ) {
            $errors['treatment_quote'] = sprintf(
                /* translators: %s: maximum allowed treatment quote amount in currency format */
                __( 'Treatment quote cannot exceed %s.', 'nexafi-plugin' ),
                NexaFi_Calculator::format_currency( $max_quote )
            );
        }

        // GDG Representative Validation (Required)
        if ( empty( $rep_id_input ) ) {
            $errors['rep_id'] = __( 'Representative ID is required.', 'nexafi-plugin' );
            $rep = null;
        } else {
            $rep = $db->get_active_representative( $rep_id_input );
            if ( ! $rep ) {
                $rep = $db->get_representative_by_id_str( $rep_id_input );
            }
            if ( ! $rep ) {
                $errors['rep_id'] = __( 'Representative ID not found. Please verify with your GDG representative.', 'nexafi-plugin' );
            }
        }

        // Consent Checkbox (explicit verification required)
        $consent_checked = ! empty( $_POST['consent'] ) && in_array( (string) $_POST['consent'], array( '1', 'true', 'on' ), true );
        if ( ! $consent_checked ) {
            $errors['consent'] = __( 'You must authorize information review to submit your application.', 'nexafi-plugin' );
        }
        $consent = $consent_checked ? 1 : 0;

        if ( ! empty( $errors ) ) {
            wp_send_json_error( array(
                'message' => __( 'Please correct the highlighted fields before proceeding.', 'nexafi-plugin' ),
                'errors'  => $errors,
            ) );
        }

        // 5. Evaluate Eligibility & Calculate Financing
        $eval = NexaFi_Eligibility::evaluate(
            array(
                'has_id_declaration'         => $has_id_decl,
                'has_debit_card_declaration' => $has_debit_decl,
                'consent_status'             => $consent,
            ),
            $rep
        );

        $calc = NexaFi_Calculator::calculate( $treatment_quote );

        // 6. Assemble record data
        $ip_address = ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $user_agent = ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $ref_number = $db->generate_unique_reference();
        if ( empty( $ref_number ) ) {
            $ref_number = 'NEXA-' . wp_date( 'Ymd' ) . '-' . wp_rand( 1000, 9999 );
        }

        // 32-character random secret for hardening terms acceptance authorization
        $terms_secret     = wp_generate_password( 32, false, false );
        $terms_token_hash = hash( 'sha256', $terms_secret );

        $record_data = array(
            'reference_number'           => $ref_number,
            'first_name'                 => $first_name,
            'last_name'                  => $last_name,
            'dob'                        => $dob,
            'phone'                      => $phone,
            'email'                      => $email,
            'home_address'               => $home_address,
            'city'                       => $city,
            'state'                      => $state,
            'zip_code'                   => $zip_code,
            'employment_status'          => $employment_status,
            'employer_name'              => $employer_name,
            'job_title'                  => $job_title,
            'time_at_job'                => $time_at_job,
            'monthly_income'             => $monthly_income,
            'has_id_declaration'         => $has_id_decl,
            'has_debit_card_declaration' => $has_debit_decl,
            'treatment_quote'            => $treatment_quote,
            'rep_record_id'              => $rep ? intval( $rep->id ) : null,
            'rep_id_str'                 => $rep ? $rep->rep_id : $rep_id_input,
            'consent_status'             => $consent,
            'consent_timestamp'          => current_time( 'mysql' ),
            'eligibility_result'         => $eval['result'],
            'eligibility_reason'         => $eval['reason'],
            'decision_mode'              => $eval['decision_mode'],
            'upfront_pct'                => $calc['upfront_pct'],
            'financed_pct'               => $calc['financed_pct'],
            'charge_pct'                 => $calc['charge_pct'],
            'repayment_term'             => $calc['repayment_term'],
            'charge_basis'               => $calc['charge_basis'],
            'plan_fee'                   => $calc['plan_fee'],
            'calc_version'               => '1.0',
            'upfront_amount'             => $calc['upfront_amount'],
            'financed_amount'            => $calc['financed_amount'],
            'charge_amount'              => $calc['charge_amount'],
            'total_repayment'            => $calc['total_repayment'],
            'monthly_payment'            => $calc['monthly_payment'],
            'application_status'         => ( 'approved' === $eval['result'] ) ? 'approved' : 'ineligible',
            'terms_accepted'             => 0,
            'terms_token_hash'           => $terms_token_hash,
            'rule_version'               => $eval['rule_version'],
            'submission_token'           => $submission_token,
            'ip_address'                 => $ip_address,
            'user_agent'                 => $user_agent,
        );

        $inserted_id = $db->insert_application( $record_data );

        if ( ! $inserted_id ) {
            global $wpdb;
            $db_err = ! empty( $wpdb->last_error ) ? ' (' . $wpdb->last_error . ')' : '';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'NexaFi Submit Error: ' . ( ! empty( $wpdb->last_error ) ? $wpdb->last_error : 'Unknown DB error' ) );
            }
            wp_send_json_error( array(
                'message'  => __( 'Database error recording application. Please try again.', 'nexafi-plugin' ) . $db_err,
                'db_error' => $wpdb->last_error,
            ) );
        }

        $saved_app = $db->get_application( $inserted_id );

        // 7. Email notification to administrator and applicant (safe & non-blocking)
        $this->maybe_send_admin_notification( $saved_app, $rep );
        $this->maybe_send_applicant_notification( $saved_app, $rep );

        // 8. Return formatted response for Screens 4 & 5
        wp_send_json_success( $this->format_application_response( $saved_app, $rep, $eval, $calc, $terms_secret ) );
    }

    /**
     * Mark terms accepted by applicant (Hardened with 32-char token verification)
     */
    public function accept_terms() {
        check_ajax_referer( 'nexafi_financing_nonce', 'security' );

        $app_id        = isset( $_POST['app_id'] ) ? intval( $_POST['app_id'] ) : 0;
        $terms_token   = isset( $_POST['terms_token'] ) ? sanitize_text_field( wp_unslash( $_POST['terms_token'] ) ) : '';
        $terms_version = isset( $_POST['terms_version'] ) ? sanitize_text_field( wp_unslash( $_POST['terms_version'] ) ) : '1.0';

        if ( ! $app_id || empty( $terms_token ) ) {
            wp_send_json_error( array(
                'message' => __( 'Missing application parameters.', 'nexafi-plugin' ),
            ), 400 );
        }

        $db  = NexaFi_DB::get_instance();
        $app = $db->get_application( $app_id );

        if ( ! $app || empty( $app->terms_token_hash ) ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid application record.', 'nexafi-plugin' ),
            ), 404 );
        }

        if ( ! hash_equals( $app->terms_token_hash, hash( 'sha256', $terms_token ) ) ) {
            wp_send_json_error( array(
                'message' => __( 'Authorization verification failed.', 'nexafi-plugin' ),
            ), 403 );
        }

        $ip_address = ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $success    = $db->mark_terms_accepted( $app_id, $terms_version, $ip_address );

        if ( $success ) {
            wp_send_json_success( array(
                'status'  => 'accepted',
                'message' => __( 'Terms accepted successfully.', 'nexafi-plugin' ),
            ) );
        } else {
            wp_send_json_error( array(
                'message' => __( 'Could not update terms acceptance.', 'nexafi-plugin' ),
            ) );
        }
    }

    /**
     * Format application record into client-ready response payload
     */
    private function format_application_response( $app, $rep = null, $eval = null, $calc = null, $terms_token = '' ) {
        if ( ! $eval ) {
            $eval = array(
                'result'           => $app->eligibility_result,
                'decision_mode'    => $app->decision_mode,
                'headline'         => ( 'approved' === $app->eligibility_result ) ? __( "YOU'RE APPROVED!", 'nexafi-plugin' ) : ( 'preliminary' === $app->eligibility_result ? __( 'Preliminary Financing Estimate', 'nexafi-plugin' ) : __( 'We’re Unable to Offer In-House Financing Right Now', 'nexafi-plugin' ) ),
                'subtitle'         => ( 'approved' === $app->eligibility_result ) ? __( 'Your GDG Smiles in-house financing has been approved.', 'nexafi-plugin' ) : '',
                'message'          => $app->eligibility_reason,
                'requires_contact' => ( 'ineligible' === $app->eligibility_result ),
            );
        }

        if ( ! $calc ) {
            $calc = NexaFi_Calculator::calculate( $app->treatment_quote, array(
                'upfront_pct'    => $app->upfront_pct,
                'financed_pct'   => $app->financed_pct,
                'charge_pct'     => $app->charge_pct,
                'repayment_term' => $app->repayment_term,
                'charge_basis'   => ! empty( $app->charge_basis ) ? $app->charge_basis : 'financed_amount',
                'plan_fee'       => ! empty( $app->plan_fee ) ? $app->plan_fee : 0.00,
            ) );
        }

        $created_timestamp = strtotime( $app->created_at );
        $display_date      = wp_date( 'F j, Y', $created_timestamp );
        $display_time      = wp_date( 'g:i A', $created_timestamp );

        if ( ! $rep ) {
            $db = NexaFi_DB::get_instance();
            if ( ! empty( $app->rep_record_id ) ) {
                $rep = $db->get_representative_by_id( $app->rep_record_id );
            }
            if ( ! $rep && ! empty( $app->rep_id_str ) ) {
                $rep = $db->get_active_representative( $app->rep_id_str );
            }
            if ( ! $rep ) {
                $rep = $db->get_default_representative();
            }
        }

        $rep_name_val = ( $rep && ! empty( $rep->name ) ) ? $rep->name : ( ! empty( $app->rep_id_str ) ? $app->rep_id_str : '' );
        $rep_id_val   = ( $rep && ! empty( $rep->rep_id ) ) ? $rep->rep_id : ( ! empty( $app->rep_id_str ) ? $app->rep_id_str : '' );

        $default_avatar = plugins_url( 'assets/images/rep-caleb.png', dirname( __FILE__ ) );
        $rep_data = array(
            'name'      => esc_html( $rep_name_val ),
            'rep_id'    => esc_html( $rep_id_val ),
            'phone'     => $rep ? esc_html( $rep->phone ) : '',
            'email'     => $rep ? esc_html( $rep->email ) : '',
            'photo_url' => ( $rep && ! empty( $rep->photo_url ) ) ? esc_url( $rep->photo_url ) : esc_url( $default_avatar ),
        );

        $fallback_contact_text = get_option(
            'nexafi_contact_fallback_text',
            __( 'Please contact your GDG representative directly or speak with our front desk.', 'nexafi-plugin' )
        );

        return array(
            'app_id'                     => intval( $app->id ),
            'reference_number'           => esc_html( $app->reference_number ),
            'applicant_name'             => esc_html( $app->first_name . ' ' . $app->last_name ),
            'eligibility_result'         => $app->eligibility_result,
            'decision_mode'              => $app->decision_mode,
            'terms_verified'             => (bool) ( $calc['terms_verified'] ?? false ),
            'terms_token'                => $terms_token,
            'headline'                   => $eval['headline'],
            'subtitle'                   => $eval['subtitle'] ?? '',
            'message'                    => $eval['message'] ?? '',
            'requires_contact'           => ! empty( $eval['requires_contact'] ),
            'fallback_contact_text'      => esc_html( $fallback_contact_text ),
            'representative'             => $rep_data,
            'calculation'                => $calc,
            'submission_date'            => $display_date,
            'submission_time'            => $display_time,
            'application_status'         => esc_html( $app->application_status ),
            'terms_accepted'             => (bool) $app->terms_accepted,
            'disclosure_text'            => get_option( 'nexafi_disclosure_text', __( 'Financing disclosures pending verification.', 'nexafi-plugin' ) ),
            'provider_disclosure'        => get_option( 'nexafi_provider_disclosure', '' ),
            'reconciliation_note'        => $calc['reconciliation_note'] ?? '',
            'booking_deposit'            => floatval( $calc['booking_deposit'] ?? 0.00 ),
            'remaining_down_payment'     => floatval( $calc['remaining_down_payment'] ?? 0.00 ),
            'formatted_booking_deposit'  => $calc['formatted_booking_deposit'] ?? '',
            'formatted_remaining_down'   => $calc['formatted_remaining_down'] ?? '',
            'booking_deposit_note'       => get_option( 'nexafi_booking_deposit_note', __( 'Any booking deposit you pay is credited toward your Required Down Payment. It does not replace it.', 'nexafi-plugin' ) ),
        );
    }

    /**
     * Send email notification to site admin on new application
     */
    private function maybe_send_admin_notification( $app, $rep ) {
        $admin_email_enabled = get_option( 'nexafi_admin_email_enabled', '1' );
        if ( '1' !== (string) $admin_email_enabled ) {
            return;
        }

        $custom_admin_email = sanitize_email( get_option( 'nexafi_admin_email', '' ) );
        $to                 = ! empty( $custom_admin_email ) ? $custom_admin_email : get_option( 'admin_email' );

        if ( empty( $to ) || ! is_email( $to ) ) {
            return;
        }

        $is_approved   = ( 'approved' === $app->eligibility_result );
        $is_prelim     = ( 'preliminary' === $app->eligibility_result );
        $status_upper  = strtoupper( $app->eligibility_result );

        $subject = sprintf(
            /* translators: 1: application reference number, 2: eligibility result status (e.g. APPROVED) */
            __( '[NexaFi] New Financing Application: %1$s (%2$s)', 'nexafi-plugin' ),
            $app->reference_number,
            $status_upper
        );

        $site_name = get_bloginfo( 'name' );
        if ( empty( $site_name ) ) {
            $site_name = 'NexaFi / GDG Smiles';
        }

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );
        $from_email = get_option( 'admin_email' );
        if ( empty( $from_email ) || ! is_email( $from_email ) ) {
            $from_email = $to;
        }
        $headers[] = 'From: ' . wp_specialchars_decode( $site_name, ENT_QUOTES ) . ' <' . sanitize_email( $from_email ) . '>';
        if ( ! empty( $app->email ) && is_email( $app->email ) ) {
            $headers[] = 'Reply-To: ' . sanitize_email( $app->email );
        }

        $created_timestamp = ! empty( $app->created_at ) ? strtotime( $app->created_at ) : time();
        $date_formatted    = wp_date( 'F j, Y, g:i A', $created_timestamp );

        $dob_formatted = ! empty( $app->dob ) ? wp_date( 'F j, Y', strtotime( $app->dob ) ) : 'N/A';

        $quote_fmt       = NexaFi_Calculator::format_currency( $app->treatment_quote );
        $upfront_fmt     = NexaFi_Calculator::format_currency( $app->upfront_amount );
        $financed_fmt    = NexaFi_Calculator::format_currency( $app->financed_amount );
        $charge_fmt      = NexaFi_Calculator::format_currency( $app->charge_amount );
        $monthly_fmt     = NexaFi_Calculator::format_currency( $app->monthly_payment );
        $total_rep_fmt   = NexaFi_Calculator::format_currency( $app->total_repayment );
        $monthly_income_fmt = NexaFi_Calculator::format_currency( $app->monthly_income );

        $upfront_pct_str  = rtrim( rtrim( (string) $app->upfront_pct, '0' ), '.' );
        $financed_pct_str = rtrim( rtrim( (string) $app->financed_pct, '0' ), '.' );
        $charge_pct_str   = rtrim( rtrim( (string) $app->charge_pct, '0' ), '.' );

        $status_bg    = $is_approved ? '#16a34a' : ( $is_prelim ? '#d97706' : '#dc2626' );
        $status_label = $is_approved ? 'APPROVED' : ( $is_prelim ? 'PRELIMINARY' : 'INELIGIBLE' );
        $banner_bg    = $is_approved ? '#f0fdf4' : ( $is_prelim ? '#fffbeb' : '#fef2f2' );

        $rep_name  = $rep ? $rep->name : ( ! empty( $app->rep_id_str ) ? $app->rep_id_str : 'None' );
        $rep_id    = $rep ? $rep->rep_id : $app->rep_id_str;
        $rep_phone = $rep ? $rep->phone : '';
        $rep_email = $rep ? $rep->email : '';

        $admin_url = admin_url( 'admin.php?page=nexafi-financing&search=' . urlencode( $app->reference_number ) );

        $employment_label = ucfirst( str_replace( '_', ' ', $app->employment_status ) );

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.5;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f1f5f9; padding: 30px 15px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 620px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08);" cellspacing="0" cellpadding="0">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #0f172a; padding: 26px 30px; text-align: left;">
                            <table width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td>
                                        <h1 style="margin: 0; color: #ffffff; font-size: 22px; font-weight: 700; letter-spacing: 0.3px;">NexaFi &bull; GDG Smiles</h1>
                                        <p style="margin: 4px 0 0 0; color: #94a3b8; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px;">New Financing Application Notification</p>
                                    </td>
                                    <td align="right">
                                        <span style="display: inline-block; background-color: rgba(255,255,255,0.1); color: #38bdf8; font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 6px;">ADMIN ALERT</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Status Hero Banner -->
                    <tr>
                        <td style="padding: 22px 30px; background-color: <?php echo esc_attr( $banner_bg ); ?>; border-bottom: 1px solid #e2e8f0;">
                            <table width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td>
                                        <span style="display: inline-block; background-color: <?php echo esc_attr( $status_bg ); ?>; color: #ffffff; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.5px;">
                                            <?php echo esc_html( $status_label ); ?>
                                        </span>
                                        <h2 style="margin: 8px 0 2px 0; color: #0f172a; font-size: 19px; font-weight: 700;">
                                            <?php echo esc_html( $app->first_name . ' ' . $app->last_name ); ?>
                                        </h2>
                                        <p style="margin: 0; color: #64748b; font-size: 13px;">
                                            Ref: <strong style="color: #0f172a; font-family: monospace;"><?php echo esc_html( $app->reference_number ); ?></strong> &bull; <?php echo esc_html( $date_formatted ); ?>
                                        </p>
                                    </td>
                                    <td align="right" valign="middle">
                                        <a href="<?php echo esc_url( $admin_url ); ?>" style="display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; font-size: 12.5px; font-weight: 600; padding: 9px 16px; border-radius: 6px; box-shadow: 0 1px 3px rgba(37,99,235,0.3);">
                                            View in Dashboard &rarr;
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Content Body -->
                    <tr>
                        <td style="padding: 26px 30px;">

                            <!-- Section 1: Applicant Information -->
                            <h3 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 700; color: #0f172a; text-transform: uppercase; letter-spacing: 0.6px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                                Applicant Information
                            </h3>
                            <table width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 22px; font-size: 13px;">
                                <tr>
                                    <td width="35%" style="padding: 5px 0; color: #64748b;">Full Name:</td>
                                    <td style="padding: 5px 0; color: #0f172a; font-weight: 600;"><?php echo esc_html( $app->first_name . ' ' . $app->last_name ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px 0; color: #64748b;">Date of Birth:</td>
                                    <td style="padding: 5px 0; color: #0f172a;"><?php echo esc_html( $dob_formatted ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px 0; color: #64748b;">Email Address:</td>
                                    <td style="padding: 5px 0; color: #2563eb;"><a href="mailto:<?php echo esc_attr( $app->email ); ?>" style="color: #2563eb; text-decoration: none; font-weight: 500;"><?php echo esc_html( $app->email ); ?></a></td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px 0; color: #64748b;">Phone Number:</td>
                                    <td style="padding: 5px 0; color: #0f172a;"><a href="tel:<?php echo esc_attr( $app->phone ); ?>" style="color: #0f172a; text-decoration: none;"><?php echo esc_html( $app->phone ); ?></a></td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px 0; color: #64748b; vertical-align: top;">Home Address:</td>
                                    <td style="padding: 5px 0; color: #0f172a;">
                                        <?php echo esc_html( $app->home_address ); ?><br>
                                        <?php echo esc_html( $app->city . ', ' . $app->state . ' ' . $app->zip_code ); ?>
                                    </td>
                                </tr>
                            </table>

                            <!-- Section 2: Employment & Income -->
                            <h3 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 700; color: #0f172a; text-transform: uppercase; letter-spacing: 0.6px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                                Employment & Income
                            </h3>
                            <table width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 22px; font-size: 13px;">
                                <tr>
                                    <td width="35%" style="padding: 5px 0; color: #64748b;">Employment Status:</td>
                                    <td style="padding: 5px 0; color: #0f172a; font-weight: 600;"><?php echo esc_html( $employment_label ); ?></td>
                                </tr>
                                <?php if ( ! empty( $app->employer_name ) ) : ?>
                                <tr>
                                    <td style="padding: 5px 0; color: #64748b;">Employer:</td>
                                    <td style="padding: 5px 0; color: #0f172a;"><?php echo esc_html( $app->employer_name ); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ( ! empty( $app->job_title ) ) : ?>
                                <tr>
                                    <td style="padding: 5px 0; color: #64748b;">Job Title:</td>
                                    <td style="padding: 5px 0; color: #0f172a;"><?php echo esc_html( $app->job_title ); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ( ! empty( $app->time_at_job ) ) : ?>
                                <tr>
                                    <td style="padding: 5px 0; color: #64748b;">Time at Current Job:</td>
                                    <td style="padding: 5px 0; color: #0f172a;"><?php echo esc_html( $app->time_at_job ); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td style="padding: 5px 0; color: #64748b;">Monthly Income:</td>
                                    <td style="padding: 5px 0; color: #0f172a; font-weight: 600;"><?php echo esc_html( $monthly_income_fmt ); ?></td>
                                </tr>
                            </table>

                            <!-- Section 3: Financing Calculation Breakdown -->
                            <h3 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 700; color: #0f172a; text-transform: uppercase; letter-spacing: 0.6px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                                Financing Breakdown
                            </h3>
                            <table width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 16px; font-size: 13px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px;">
                                <tr>
                                    <td style="padding: 6px 0; color: #475569;">Total Treatment Price:</td>
                                    <td align="right" style="padding: 6px 0; color: #0f172a; font-weight: 700; font-size: 15px;"><?php echo esc_html( $quote_fmt ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 6px 0; color: #475569;">Required Down Payment:</td>
                                    <td align="right" style="padding: 6px 0; color: #0f172a; font-weight: 600;"><?php echo esc_html( $upfront_fmt ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 6px 0; color: #475569;">Amount Financed:</td>
                                    <td align="right" style="padding: 6px 0; color: #0f172a; font-weight: 600;"><?php echo esc_html( $financed_fmt ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 6px 0; color: #475569;">Financing Charge:</td>
                                    <td align="right" style="padding: 6px 0; color: #0f172a;"><?php echo esc_html( $charge_fmt ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 6px 0; color: #475569;">Total Installment Payments:</td>
                                    <td align="right" style="padding: 6px 0; color: #0f172a; font-weight: 600;"><?php echo esc_html( $total_rep_fmt ); ?></td>
                                </tr>
                                <tr style="border-top: 1px solid #cbd5e1;">
                                    <td style="padding: 8px 0 4px 0; color: #0f172a; font-weight: 700;">Your Monthly Payment:</td>
                                    <td align="right" style="padding: 8px 0 4px 0; color: #16a34a; font-weight: 700; font-size: 16px;"><?php echo esc_html( $monthly_fmt ); ?> / mo</td>
                                </tr>
                            </table>

                            <!-- Calculation Details for Admin Reference -->
                            <div style="font-size: 11.5px; color: #64748b; margin-bottom: 22px; line-height: 1.5; padding: 6px 10px; background: #f1f5f9; border-radius: 4px;">
                                <strong>Internal Calculation Details:</strong>
                                Down Payment: <?php echo esc_html( $upfront_pct_str ); ?>% | Financed: <?php echo esc_html( $financed_pct_str ); ?>% | Charge: <?php echo esc_html( $charge_pct_str ); ?>% | Basis: <?php echo esc_html( ! empty( $app->charge_basis ) ? $app->charge_basis : 'financed_amount' ); ?> | Term: <?php echo intval( $app->repayment_term ); ?> months
                            </div>

                            <!-- Section 4: Assigned Representative -->
                            <table width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 22px;">
                                <tr>
                                    <td width="100%" valign="top" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; font-size: 12.5px;">
                                        <div style="font-weight: 700; color: #0f172a; margin-bottom: 6px; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px;">Assigned Representative</div>
                                        <div style="color: #0f172a; font-weight: 600; font-size: 13.5px;"><?php echo esc_html( $rep_name ); ?></div>
                                        <div style="color: #64748b; font-size: 12px; margin-top: 2px;">Rep ID: <strong style="color: #0f172a;"><?php echo esc_html( $rep_id ); ?></strong></div>
                                        <?php if ( ! empty( $rep_phone ) ) : ?>
                                            <div style="color: #64748b; font-size: 12px; margin-top: 2px;">Phone: <?php echo esc_html( $rep_phone ); ?></div>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $rep_email ) ) : ?>
                                            <div style="color: #64748b; font-size: 12px; margin-top: 2px;">Email: <?php echo esc_html( $rep_email ); ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>

                            <!-- Call to Action Button Center -->
                            <div style="text-align: center; margin: 26px 0 10px 0;">
                                <a href="<?php echo esc_url( $admin_url ); ?>" style="display: inline-block; background-color: #0f172a; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 700; padding: 14px 28px; border-radius: 6px; letter-spacing: 0.3px;">
                                    Open Full Application in WordPress &rarr;
                                </a>
                            </div>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; padding: 18px 30px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 11.5px; color: #94a3b8;">
                            <p style="margin: 0;">This automated notification was generated by NexaFi Financing Application &bull; GDG Smiles Practice Management</p>
                            <p style="margin: 4px 0 0 0;">Application Ref: <?php echo esc_html( $app->reference_number ); ?> &bull; Logged on <?php echo esc_html( $date_formatted ); ?></p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
        <?php
        $message = ob_get_clean();

        $mail_error = null;
        $fail_cb = function( $wp_error ) use ( &$mail_error ) {
            if ( is_wp_error( $wp_error ) ) {
                $mail_error = $wp_error->get_error_message();
            }
        };
        add_action( 'wp_mail_failed', $fail_cb );
        $sent = @wp_mail( $to, $subject, $message, $headers );
        remove_action( 'wp_mail_failed', $fail_cb );

        $db = NexaFi_DB::get_instance();
        $db->log_email( array(
            'application_id'  => $app->id,
            'recipient_email' => $to,
            'recipient_name'  => 'Administrator',
            'email_type'      => 'admin_notification',
            'subject'         => $subject,
            'status'          => $sent ? 'sent' : 'failed',
            'error_message'   => $sent ? null : ( $mail_error ? $mail_error : 'wp_mail returned false.' ),
        ) );
    }

    /**
     * Send email notification with application details to the applicant (user)
     *
     * @param object      $app Application database record.
     * @param object|null $rep Representative database record or null.
     * @param string      $email_type Type of email ('applicant_notification' or 'resend').
    public function send_applicant_notification( $app, $rep = null, $email_type = 'applicant_notification' ) {
        return $this->maybe_send_applicant_notification( $app, $rep, $email_type );
    }

    /**
     * Send email notification with application details to the applicant (user)
     *
     * @param object      $app Application database record.
     * @param object|null $rep Representative database record or null.
     * @param string      $email_type Type of email ('applicant_notification' or 'resend').
     * @return array
     */
    public function maybe_send_applicant_notification( $app, $rep = null, $email_type = 'applicant_notification' ) {
        if ( empty( $app->email ) || ! is_email( $app->email ) ) {
            return;
        }

        $to = sanitize_email( $app->email );

        $is_approved    = ( 'approved' === $app->eligibility_result );
        $applicant_name = trim( $app->first_name . ' ' . $app->last_name );
        $rep_name       = $rep ? $rep->name : ( ! empty( $app->rep_id_str ) ? $app->rep_id_str : 'Assigned Representative' );
        $rep_id         = $rep ? $rep->rep_id : $app->rep_id_str;
        $rep_phone      = $rep ? $rep->phone : '';
        $rep_email      = $rep ? $rep->email : '';

        /* translators: %s: application reference number */
        $subject = sprintf(
            $is_approved
                /* translators: %s: application reference number */
                ? __( 'Your NexaFi Financing Application & Approval Details [%s]', 'nexafi-plugin' )
                /* translators: %s: application reference number */
                : __( 'Your NexaFi Financing Application Details [%s]', 'nexafi-plugin' ),
            $app->reference_number
        );

        $site_name = get_bloginfo( 'name' );
        if ( empty( $site_name ) ) {
            $site_name = 'GDG Smiles';
        }
        $admin_email = get_option( 'admin_email' );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );
        if ( ! empty( $admin_email ) ) {
            $headers[] = 'From: ' . wp_specialchars_decode( $site_name, ENT_QUOTES ) . ' <' . sanitize_email( $admin_email ) . '>';
            $headers[] = 'Reply-To: ' . sanitize_email( $admin_email );
        }

        $created_timestamp = ! empty( $app->created_at ) ? strtotime( $app->created_at ) : time();
        $date_formatted    = wp_date( 'F j, Y, g:i A', $created_timestamp );

        $quote_fmt       = NexaFi_Calculator::format_currency( $app->treatment_quote );
        $upfront_fmt     = NexaFi_Calculator::format_currency( $app->upfront_amount );
        $financed_fmt    = NexaFi_Calculator::format_currency( $app->financed_amount );
        $charge_fmt      = NexaFi_Calculator::format_currency( $app->charge_amount );
        $monthly_fmt     = NexaFi_Calculator::format_currency( $app->monthly_payment );
        $total_rep_fmt   = NexaFi_Calculator::format_currency( $app->total_repayment );
        $monthly_income_fmt = NexaFi_Calculator::format_currency( $app->monthly_income );

        $full_address = esc_html( $app->home_address );
        $city_state_zip = trim( $app->city . ( ! empty( $app->state ) ? ', ' . $app->state : '' ) . ( ! empty( $app->zip_code ) ? ' ' . $app->zip_code : '' ) );
        if ( ! empty( $city_state_zip ) ) {
            $full_address .= '<br>' . esc_html( $city_state_zip );
        }

        $employment_line = esc_html( ucfirst( str_replace( '_', ' ', $app->employment_status ) ) );
        if ( ! empty( $app->job_title ) || ! empty( $app->employer_name ) ) {
            $emp_details = trim( $app->job_title . ( ! empty( $app->employer_name ) ? ' at ' . $app->employer_name : '' ) );
            if ( ! empty( $emp_details ) ) {
                $employment_line .= ' (' . esc_html( $emp_details ) . ')';
            }
        }

        $status_bg    = $is_approved ? '#16a34a' : '#d97706';
        $status_title = $is_approved ? __( "YOU'RE APPROVED!", 'nexafi-plugin' ) : __( 'APPLICATION RECEIVED', 'nexafi-plugin' );
        $status_desc  = $is_approved
            ? __( 'Congratulations! Your GDG Smiles in-house financing has been approved.', 'nexafi-plugin' )
            : __( 'Your application has been received and is being processed by our team.', 'nexafi-plugin' );

        // Clean percent strings (e.g. 70 instead of 70.00)
        $upfront_pct_str  = rtrim( rtrim( (string) $app->upfront_pct, '0' ), '.' );
        $financed_pct_str = rtrim( rtrim( (string) $app->financed_pct, '0' ), '.' );
        $charge_pct_str   = rtrim( rtrim( (string) $app->charge_pct, '0' ), '.' );

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.5;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f1f5f9; padding: 30px 15px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.06);" cellspacing="0" cellpadding="0">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #0f172a; padding: 28px 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 700; letter-spacing: 0.5px;">GDG SMILES</h1>
                            <p style="margin: 6px 0 0 0; color: #94a3b8; font-size: 13px; font-weight: 500; text-transform: uppercase; letter-spacing: 1px;">In-House Financing &bull; NexaFi</p>
                        </td>
                    </tr>

                    <!-- Status Hero Banner -->
                    <tr>
                        <td style="padding: 24px 30px; text-align: center; border-bottom: 1px solid #e2e8f0; background-color: <?php echo $is_approved ? '#f0fdf4' : '#fffbeb'; ?>;">
                            <span style="display: inline-block; background-color: <?php echo esc_attr( $status_bg ); ?>; color: #ffffff; font-size: 12px; font-weight: 700; padding: 6px 14px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.5px;">
                                <?php echo esc_html( $status_title ); ?>
                            </span>
                            <h2 style="margin: 12px 0 6px 0; color: #0f172a; font-size: 20px; font-weight: 700;">
                                <?php
                                /* translators: %s: applicant's first name */
                                echo esc_html( sprintf( __( 'Hello, %s', 'nexafi-plugin' ), $app->first_name ) );
                                ?>
                            </h2>
                            <p style="margin: 0; color: #475569; font-size: 14px;">
                                <?php echo esc_html( $status_desc ); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Reference Number Callout -->
                    <tr>
                        <td style="padding: 20px 30px 10px 30px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px;">
                                <tr>
                                    <td>
                                        <div style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b;"><?php esc_html_e( 'Application Reference #', 'nexafi-plugin' ); ?></div>
                                        <div style="font-size: 17px; font-weight: 700; color: #0f172a; margin-top: 3px; font-family: monospace;"><?php echo esc_html( $app->reference_number ); ?></div>
                                    </td>
                                    <td align="right">
                                        <div style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b;"><?php esc_html_e( 'Date & Time', 'nexafi-plugin' ); ?></div>
                                        <div style="font-size: 13px; font-weight: 500; color: #334155; margin-top: 3px;"><?php echo esc_html( $date_formatted ); ?></div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Financing Breakdown -->
                    <tr>
                        <td style="padding: 15px 30px 10px 30px;">
                            <h3 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 700; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #0f172a; padding-bottom: 6px;">
                                <?php esc_html_e( 'Financing Breakdown', 'nexafi-plugin' ); ?>
                            </h3>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size: 14px;">
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b;"><?php esc_html_e( 'Total Treatment Price', 'nexafi-plugin' ); ?></td>
                                    <td align="right" style="padding: 7px 0; font-weight: 700; color: #0f172a;"><?php echo esc_html( $quote_fmt ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;">
                                        <?php esc_html_e( 'Required Down Payment', 'nexafi-plugin' ); ?>
                                    </td>
                                    <td align="right" style="padding: 7px 0; font-weight: 600; color: #0f172a; border-top: 1px solid #f1f5f9;"><?php echo esc_html( $upfront_fmt ); ?></td>
                                </tr>
                                <?php
                                $booking_deposit_val = floatval( get_option( 'nexafi_booking_deposit', 0.00 ) );
                                $terms_verified_val  = (bool) get_option( 'nexafi_terms_verified', false );
                                if ( $booking_deposit_val > 0 && $terms_verified_val ) :
                                    $rem_down = max( 0.0, floatval( $app->upfront_amount ) - $booking_deposit_val );
                                ?>
                                <tr>
                                    <td style="padding: 5px 0 5px 12px; color: #64748b; font-size: 12.5px;"><?php esc_html_e( 'Booking deposit credited', 'nexafi-plugin' ); ?></td>
                                    <td align="right" style="padding: 5px 0; color: #0f172a; font-size: 12.5px;"><?php echo esc_html( NexaFi_Calculator::format_currency( $booking_deposit_val, true ) ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px 0 5px 12px; color: #64748b; font-size: 12.5px;"><?php esc_html_e( 'Remaining down payment', 'nexafi-plugin' ); ?></td>
                                    <td align="right" style="padding: 5px 0; color: #0f172a; font-size: 12.5px; font-weight: 600;"><?php echo esc_html( NexaFi_Calculator::format_currency( $rem_down, true ) ); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;">
                                        <?php esc_html_e( 'Amount Financed', 'nexafi-plugin' ); ?>
                                    </td>
                                    <td align="right" style="padding: 7px 0; font-weight: 600; color: #0f172a; border-top: 1px solid #f1f5f9;"><?php echo esc_html( $financed_fmt ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;">
                                        <?php esc_html_e( 'Financing Charge', 'nexafi-plugin' ); ?>
                                    </td>
                                    <td align="right" style="padding: 7px 0; font-weight: 600; color: #0f172a; border-top: 1px solid #f1f5f9;"><?php echo $terms_verified_val ? esc_html( $charge_fmt ) : esc_html__( '— (Pending verification)', 'nexafi-plugin' ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;"><?php esc_html_e( 'Total Installment Payments', 'nexafi-plugin' ); ?></td>
                                    <td align="right" style="padding: 7px 0; font-weight: 600; color: #0f172a; border-top: 1px solid #f1f5f9;"><?php echo $terms_verified_val ? esc_html( $total_rep_fmt ) : '—'; ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 0; color: #0f172a; font-weight: 700; border-top: 2px solid #e2e8f0; font-size: 15px;"><?php esc_html_e( 'Your Monthly Payment', 'nexafi-plugin' ); ?></td>
                                    <td align="right" style="padding: 10px 0; font-weight: 800; color: #16a34a; border-top: 2px solid #e2e8f0; font-size: 17px;"><?php echo $terms_verified_val ? ( esc_html( $monthly_fmt ) . ' <span style="font-size: 12px; color: #64748b; font-weight: normal;">/ mo</span>' ) : '—'; ?></td>
                                </tr>
                                <?php
                                $calc_reconciliation_note = '';
                                if ( $terms_verified_val ) {
                                    $email_calc = NexaFi_Calculator::calculate( $app->treatment_quote, array(
                                        'upfront_pct'        => $app->upfront_pct,
                                        'financed_pct'       => $app->financed_pct,
                                        'charge_pct'         => $app->charge_pct,
                                        'repayment_term'     => $app->repayment_term,
                                        'charge_basis'       => ! empty( $app->charge_basis ) ? $app->charge_basis : 'financed_amount',
                                        'plan_fee'           => ! empty( $app->plan_fee ) ? $app->plan_fee : 0.00,
                                        'plan_fee_treatment' => get_option( 'nexafi_plan_fee_treatment', 'financed' ),
                                        'terms_verified'     => true,
                                    ) );
                                    $calc_reconciliation_note = $email_calc['reconciliation_note'] ?? '';
                                }
                                if ( ! empty( $calc_reconciliation_note ) ) :
                                ?>
                                <tr>
                                    <td colspan="2" style="padding: 10px 0 0 0; color: #0b275b; font-size: 13px; font-weight: 600; border-top: 1px dashed #cbd5e1;">
                                        <?php echo esc_html( $calc_reconciliation_note ); ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </td>
                    </tr>

                    <!-- Submitted Details Section -->
                    <tr>
                        <td style="padding: 15px 30px 10px 30px;">
                            <h3 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 700; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #0f172a; padding-bottom: 6px;">
                                <?php esc_html_e( 'Your Submitted Details', 'nexafi-plugin' ); ?>
                            </h3>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size: 14px;">
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; width: 36%;"><?php esc_html_e( 'Full Name', 'nexafi-plugin' ); ?></td>
                                    <td style="padding: 7px 0; font-weight: 600; color: #0f172a;"><?php echo esc_html( $applicant_name ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;"><?php esc_html_e( 'Email', 'nexafi-plugin' ); ?></td>
                                    <td style="padding: 7px 0; font-weight: 600; color: #0f172a; border-top: 1px solid #f1f5f9;"><?php echo esc_html( $app->email ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;"><?php esc_html_e( 'Phone', 'nexafi-plugin' ); ?></td>
                                    <td style="padding: 7px 0; font-weight: 600; color: #0f172a; border-top: 1px solid #f1f5f9;"><?php echo esc_html( $app->phone ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;"><?php esc_html_e( 'Home Address', 'nexafi-plugin' ); ?></td>
                                    <td style="padding: 7px 0; font-weight: 500; color: #0f172a; border-top: 1px solid #f1f5f9;"><?php echo $full_address; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;"><?php esc_html_e( 'Employment', 'nexafi-plugin' ); ?></td>
                                    <td style="padding: 7px 0; font-weight: 500; color: #0f172a; border-top: 1px solid #f1f5f9;"><?php echo $employment_line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;"><?php esc_html_e( 'Monthly Income', 'nexafi-plugin' ); ?></td>
                                    <td style="padding: 7px 0; font-weight: 600; color: #0f172a; border-top: 1px solid #f1f5f9;"><?php echo esc_html( $monthly_income_fmt ); ?></td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Representative & Next Steps Box -->
                    <tr>
                        <td style="padding: 15px 30px 25px 30px;">
                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px 20px;">
                                <div style="font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                                    <?php esc_html_e( 'Assigned GDG Representative:', 'nexafi-plugin' ); ?>
                                </div>
                                <div style="font-size: 14px; color: #334155; font-weight: 600;">
                                    <?php echo esc_html( $rep_name ); ?>
                                    <?php if ( ! empty( $rep_id ) ) : ?>
                                        <span style="font-size: 12px; color: #64748b; font-weight: normal;">(ID: <?php echo esc_html( $rep_id ); ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ( ! empty( $rep_phone ) || ! empty( $rep_email ) ) : ?>
                                    <div style="font-size: 13px; color: #475569; margin-top: 4px;">
                                        <?php if ( ! empty( $rep_phone ) ) : ?>
                                            <span>&#128222; <?php echo esc_html( $rep_phone ); ?></span> &nbsp;&nbsp;
                                        <?php endif; ?>
                                        <?php if ( ! empty( $rep_email ) ) : ?>
                                            <span>&#9993; <a href="mailto:<?php echo esc_attr( $rep_email ); ?>" style="color: #2563eb; text-decoration: none;"><?php echo esc_html( $rep_email ); ?></a></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed #cbd5e1;">
                                    <div style="font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                                        <?php esc_html_e( 'Next Steps:', 'nexafi-plugin' ); ?>
                                    </div>
                                    <ol style="margin: 0; padding-left: 18px; font-size: 13px; color: #475569; line-height: 1.6;">
                                        <li><strong><?php esc_html_e( 'Connect With Your GDG Representative', 'nexafi-plugin' ); ?></strong> &mdash; <?php esc_html_e( 'Your GDG Smiles representative will help finalize your financing arrangements and answer any remaining questions.', 'nexafi-plugin' ); ?></li>
                                        <li><strong><?php esc_html_e( 'Secure Your Promotional Pricing', 'nexafi-plugin' ); ?></strong> &mdash; <?php esc_html_e( 'Complete your required booking deposit to lock in your promotional pricing for up to 3 months! Your deposit is applied toward your treatment balance.', 'nexafi-plugin' ); ?></li>
                                        <li><strong><?php esc_html_e( 'Schedule Your Smile Transformation', 'nexafi-plugin' ); ?></strong> &mdash; <?php esc_html_e( 'Once your booking requirements and payment arrangements are completed, your representative will help reserve your treatment dates.', 'nexafi-plugin' ); ?></li>
                                    </ol>
                                    <?php
                                    $deposit_note = get_option(
                                        'nexafi_booking_deposit_note',
                                        __( 'Any booking deposit you pay is credited toward your Required Down Payment. It does not replace it.', 'nexafi-plugin' )
                                    );
                                    if ( ! empty( $deposit_note ) ) :
                                    ?>
                                        <p style="margin: 10px 0 0 0; font-size: 12px; color: #64748b; font-style: italic; line-height: 1.5;">
                                            <?php echo esc_html( $deposit_note ); ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php
                                    $provider_disc = get_option( 'nexafi_provider_disclosure', '' );
                                    if ( ! empty( $provider_disc ) ) :
                                    ?>
                                        <p style="margin: 8px 0 0 0; font-size: 11.5px; color: #94a3b8; line-height: 1.4;">
                                            <?php echo esc_html( $provider_disc ); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #0f172a; padding: 22px 30px; text-align: center; color: #94a3b8; font-size: 12px;">
                            <p style="margin: 0 0 6px 0; color: #cbd5e1; font-weight: 600;">GDG SMILES &bull; NexaFi In-House Financing</p>
                            <p style="margin: 0; color: #64748b;">This email was sent to confirm your financing submission. If you did not submit this application, please contact us immediately.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
        <?php
        $message = ob_get_clean();

        $mail_error = null;
        $fail_cb = function( $wp_error ) use ( &$mail_error ) {
            if ( is_wp_error( $wp_error ) ) {
                $mail_error = $wp_error->get_error_message();
            }
        };
        add_action( 'wp_mail_failed', $fail_cb );
        $sent = @wp_mail( $to, $subject, $message, $headers );
        remove_action( 'wp_mail_failed', $fail_cb );

        $db = NexaFi_DB::get_instance();
        $log_id = $db->log_email( array(
            'application_id'  => $app->id,
            'recipient_email' => $to,
            'recipient_name'  => $applicant_name,
            'email_type'      => $email_type,
            'subject'         => $subject,
            'status'          => $sent ? 'sent' : 'failed',
            'error_message'   => $sent ? null : ( $mail_error ? $mail_error : 'wp_mail returned false (check WordPress mail / SMTP configuration).' ),
        ) );

        if ( ! $sent ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'NexaFi: Failed to send applicant confirmation email to ' . $to . ' | Error: ' . ( $mail_error ? $mail_error : 'Unknown' ) );
            }
        }

        return array(
            'success' => (bool) $sent,
            'message' => $sent ? __( 'Email sent successfully.', 'nexafi-plugin' ) : ( $mail_error ? $mail_error : __( 'Mail server rejected the message.', 'nexafi-plugin' ) ),
            'log_id'  => $log_id,
        );
    }

    /**
     * AJAX action to resend applicant notification email from admin dashboard
     */
    public function admin_resend_email() {
        check_ajax_referer( 'nexafi_admin_nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'nexafi-plugin' ) ), 403 );
        }

        $app_id = isset( $_POST['app_id'] ) ? intval( $_POST['app_id'] ) : 0;
        $log_id = isset( $_POST['log_id'] ) ? intval( $_POST['log_id'] ) : 0;

        $db = NexaFi_DB::get_instance();

        if ( ! $app_id && $log_id ) {
            $log = $db->get_email_log( $log_id );
            if ( $log && ! empty( $log->application_id ) ) {
                $app_id = intval( $log->application_id );
            }
        }

        if ( ! $app_id ) {
            wp_send_json_error( array( 'message' => __( 'Application record not specified.', 'nexafi-plugin' ) ) );
        }

        $app = $db->get_application( $app_id );
        if ( ! $app ) {
            wp_send_json_error( array( 'message' => __( 'Application record not found.', 'nexafi-plugin' ) ) );
        }

        $rep = null;
        if ( ! empty( $app->rep_record_id ) ) {
            $rep = $db->get_representative_by_id( $app->rep_record_id );
        }
        if ( ! $rep ) {
            $rep = $db->get_default_representative();
        }

        $res = $this->maybe_send_applicant_notification( $app, $rep, 'resend' );

        if ( $res['success'] ) {
            wp_send_json_success( array(
                /* translators: %s: applicant email address */
                'message' => sprintf( __( 'Confirmation email successfully resent to %s.', 'nexafi-plugin' ), esc_html( $app->email ) ),
                'log_id'  => $res['log_id'],
            ) );
        } else {
            wp_send_json_error( array(
                /* translators: %s: error message describing the failure reason */
                'message' => sprintf( __( 'Failed to resend email: %s', 'nexafi-plugin' ), esc_html( $res['message'] ) ),
                'log_id'  => $res['log_id'],
            ) );
        }
    }
}
