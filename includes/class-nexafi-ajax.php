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
        // 1. Nonce check with fallback for public cached form pages
        $has_valid_nonce = check_ajax_referer( 'nexafi_financing_nonce', 'security', false );
        if ( ! $has_valid_nonce && empty( $_POST['email'] ) && empty( $_POST['first_name'] ) ) {
            wp_send_json_error( array(
                'message' => __( 'Security verification expired. Please refresh the page and try again.', 'nexafi-plugin' ),
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

        if ( empty( $phone ) ) {
            $errors['phone'] = __( 'Phone number is required.', 'nexafi-plugin' );
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

        // Consent Checkbox (default confirmed on submission)
        $consent = 1;

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
            'consent_status'             => 1,
            'consent_timestamp'          => current_time( 'mysql' ),
            'eligibility_result'         => $eval['result'],
            'eligibility_reason'         => $eval['reason'],
            'decision_mode'              => $eval['decision_mode'],
            'upfront_pct'                => $calc['upfront_pct'],
            'financed_pct'               => $calc['financed_pct'],
            'charge_pct'                 => $calc['charge_pct'],
            'repayment_term'             => $calc['repayment_term'],
            'upfront_amount'             => $calc['upfront_amount'],
            'financed_amount'            => $calc['financed_amount'],
            'charge_amount'              => $calc['charge_amount'],
            'total_repayment'            => $calc['total_repayment'],
            'monthly_payment'            => $calc['monthly_payment'],
            'application_status'         => ( 'approved' === $eval['result'] ) ? 'approved' : 'ineligible',
            'terms_accepted'             => 0,
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
        wp_send_json_success( $this->format_application_response( $saved_app, $rep, $eval, $calc ) );
    }

    /**
     * Mark terms accepted by applicant
     */
    public function accept_terms() {
        check_ajax_referer( 'nexafi_financing_nonce', 'security' );

        $app_id    = isset( $_POST['app_id'] ) ? intval( $_POST['app_id'] ) : 0;
        $reference = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';

        if ( ! $app_id || empty( $reference ) ) {
            wp_send_json_error( array(
                'message' => __( 'Missing application parameters.', 'nexafi-plugin' ),
            ) );
        }

        $db  = NexaFi_DB::get_instance();
        $app = $db->get_application( $app_id );

        if ( ! $app || $app->reference_number !== $reference ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid application record.', 'nexafi-plugin' ),
            ) );
        }

        $success = $db->mark_terms_accepted( $app_id );

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
    private function format_application_response( $app, $rep = null, $eval = null, $calc = null ) {
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

        $rep_name_val = ( $rep && ! empty( $rep->name ) ) ? $rep->name : ( ! empty( $app->rep_id_str ) ? $app->rep_id_str : 'kalijo' );
        $rep_id_val   = ( $rep && ! empty( $rep->rep_id ) ) ? $rep->rep_id : ( ! empty( $app->rep_id_str ) ? $app->rep_id_str : 'GDG1122' );

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
            'app_id'                 => intval( $app->id ),
            'reference_number'       => esc_html( $app->reference_number ),
            'applicant_name'         => esc_html( $app->first_name . ' ' . $app->last_name ),
            'eligibility_result'     => $app->eligibility_result,
            'decision_mode'          => $app->decision_mode,
            'headline'               => $eval['headline'],
            'subtitle'               => $eval['subtitle'] ?? '',
            'message'                => $eval['message'] ?? '',
            'requires_contact'       => ! empty( $eval['requires_contact'] ),
            'fallback_contact_text'  => esc_html( $fallback_contact_text ),
            'representative'         => $rep_data,
            'calculation'            => $calc,
            'submission_date'        => $display_date,
            'submission_time'        => $display_time,
            'application_status'     => esc_html( $app->application_status ),
            'terms_accepted'         => (bool) $app->terms_accepted,
        );
    }

    /**
     * Send email notification to site admin on new application
     */
    private function maybe_send_admin_notification( $app, $rep ) {
        $to = get_option( 'admin_email' );
        if ( empty( $to ) ) {
            return;
        }

        $subject = sprintf(
            /* translators: 1: application reference number, 2: eligibility result status (e.g. APPROVED) */
            __( '[NexaFi] New Financing Application: %1$s (%2$s)', 'nexafi-plugin' ),
            $app->reference_number,
            strtoupper( $app->eligibility_result )
        );

        $message = sprintf(
            "A new NexaFi financing application has been submitted.\n\n" .
            "Reference: %s\n" .
            "Applicant: %s %s\n" .
            "Email: %s\n" .
            "Phone: %s\n" .
            "Treatment Quote: %s\n" .
            "Eligibility Result: %s\n" .
            "Representative: %s (%s)\n" .
            "Date/Time: %s\n\n" .
            "View details in your WordPress admin dashboard:\n%s",
            $app->reference_number,
            $app->first_name,
            $app->last_name,
            $app->email,
            $app->phone,
            NexaFi_Calculator::format_currency( $app->treatment_quote ),
            strtoupper( $app->eligibility_result ),
            $rep ? $rep->name : $app->rep_id_str,
            $app->rep_id_str,
            $app->created_at,
            admin_url( 'admin.php?page=nexafi-financing&search=' . urlencode( $app->reference_number ) )
        );

        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

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
                                    <td style="padding: 7px 0; color: #64748b;"><?php esc_html_e( 'Total Treatment Quote', 'nexafi-plugin' ); ?></td>
                                    <td align="right" style="padding: 7px 0; font-weight: 700; color: #0f172a;"><?php echo esc_html( $quote_fmt ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;">
                                        <?php
                                        /* translators: %s: upfront percentage value */
                                        printf( esc_html__( 'Upfront Contribution (%s%%)', 'nexafi-plugin' ), esc_html( $upfront_pct_str ) );
                                        ?>
                                    </td>
                                    <td align="right" style="padding: 7px 0; font-weight: 600; color: #0f172a; border-top: 1px solid #f1f5f9;"><?php echo esc_html( $upfront_fmt ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;">
                                        <?php
                                        /* translators: %s: financed percentage value */
                                        printf( esc_html__( 'Financed Amount (%s%%)', 'nexafi-plugin' ), esc_html( $financed_pct_str ) );
                                        ?>
                                    </td>
                                    <td align="right" style="padding: 7px 0; font-weight: 600; color: #0f172a; border-top: 1px solid #f1f5f9;"><?php echo esc_html( $financed_fmt ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;">
                                        <?php
                                        /* translators: %s: financing charge percentage value */
                                        printf( esc_html__( 'Financing Charge (%s%%)', 'nexafi-plugin' ), esc_html( $charge_pct_str ) );
                                        ?>
                                    </td>
                                    <td align="right" style="padding: 7px 0; font-weight: 600; color: #0f172a; border-top: 1px solid #f1f5f9;"><?php echo esc_html( $charge_fmt ); ?></td>
                                </tr>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;"><?php esc_html_e( 'Repayment Term', 'nexafi-plugin' ); ?></td>
                                    <td align="right" style="padding: 7px 0; font-weight: 600; color: #0f172a; border-top: 1px solid #f1f5f9;">
                                        <?php echo esc_html( $app->repayment_term ); ?> <?php esc_html_e( 'Months', 'nexafi-plugin' ); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 0; color: #0f172a; font-weight: 700; border-top: 2px solid #e2e8f0; font-size: 15px;"><?php esc_html_e( 'Monthly Payment', 'nexafi-plugin' ); ?></td>
                                    <td align="right" style="padding: 10px 0; font-weight: 800; color: #16a34a; border-top: 2px solid #e2e8f0; font-size: 17px;"><?php echo esc_html( $monthly_fmt ); ?> <span style="font-size: 12px; color: #64748b; font-weight: normal;">/ mo</span></td>
                                </tr>
                                <tr>
                                    <td style="padding: 7px 0; color: #64748b; border-top: 1px solid #f1f5f9;"><?php esc_html_e( 'Total of Monthly Payments', 'nexafi-plugin' ); ?></td>
                                    <td align="right" style="padding: 7px 0; font-weight: 600; color: #0f172a; border-top: 1px solid #f1f5f9;"><?php echo esc_html( $total_rep_fmt ); ?></td>
                                </tr>
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
                                        <li><?php esc_html_e( 'Your GDG representative will contact you shortly to finalize your setup.', 'nexafi-plugin' ); ?></li>
                                        <li><?php esc_html_e( 'Complete your payment arrangements.', 'nexafi-plugin' ); ?></li>
                                        <li><?php esc_html_e( 'Lock in your appointment and get one step closer to your new smile!', 'nexafi-plugin' ); ?></li>
                                    </ol>
                                </div>
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
