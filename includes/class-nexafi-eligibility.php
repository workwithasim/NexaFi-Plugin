<?php
/**
 * NexaFi Eligibility Engine
 *
 * Implements MVP declaration-based eligibility rules, authorized approval safeguards,
 * and decision mode determinations.
 *
 * @package NexaFi_Financing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NexaFi_Eligibility {

    const RULE_VERSION = '1.0-mvp';

    /**
     * Evaluate application eligibility based on submitted data and active configuration
     *
     * @param array  $data Submitted application fields
     * @param object $representative Resolved active representative object
     * @return array Evaluation result
     */
    public static function evaluate( $data, $representative ) {
        // 1. Fetch current decision policy mode (default preliminary_only)
        $decision_mode = get_option( 'nexafi_decision_mode', 'preliminary_only' );
        $rule_version  = self::RULE_VERSION;

        // 2. Validate representative (with automatic fallback to default active representative)
        if ( empty( $representative ) || empty( $representative->id ) ) {
            $db = NexaFi_DB::get_instance();
            $representative = $db->get_default_representative();
        }

        // 3. Validate Consent
        if ( isset( $data['consent_status'] ) && empty( $data['consent_status'] ) ) {
            return array(
                'is_eligible'        => false,
                'result'             => 'ineligible',
                'reason'             => __( 'Applicant consent was not provided.', 'nexafi-plugin' ),
                'decision_mode'      => $decision_mode,
                'rule_version'       => $rule_version,
                'headline'           => __( 'Consent Required', 'nexafi-plugin' ),
                'message'            => __( 'Financing authorization consent is required to process this application.', 'nexafi-plugin' ),
                'requires_contact'   => true,
            );
        }

        // 4. Validate Declaration Answers (both required)
        $has_id    = true;
        $has_debit = true;
        if ( isset( $data['has_id_declaration'] ) && ( '0' === (string) $data['has_id_declaration'] || false === $data['has_id_declaration'] ) ) {
            $has_id = false;
        }
        if ( isset( $data['has_debit_card_declaration'] ) && ( '0' === (string) $data['has_debit_card_declaration'] || false === $data['has_debit_card_declaration'] ) ) {
            $has_debit = false;
        }

        if ( ! $has_id || ! $has_debit ) {
            $missing = array();
            if ( ! $has_id ) {
                $missing[] = __( 'valid unexpired driver’s license or state ID', 'nexafi-plugin' );
            }
            if ( ! $has_debit ) {
                $missing[] = __( 'active debit card from a traditional bank or credit union', 'nexafi-plugin' );
            }

            $reason = __( 'Unfortunately, you don’t currently meet the requirements for our in-house financing program. Don’t worry! Your GDG Smiles representative can help you explore other available payment options.', 'nexafi-plugin' );

            $ineligibility_msg = $reason;

            return array(
                'is_eligible'        => false,
                'result'             => 'ineligible',
                'reason'             => $reason,
                'decision_mode'      => $decision_mode,
                'rule_version'       => $rule_version,
                'headline'           => __( 'We’re Unable to Offer In-House Financing Right Now', 'nexafi-plugin' ),
                'message'            => $ineligibility_msg,
                'requires_contact'   => true,
            );
        }

        // 5. Declarations are satisfied. Return approved decision ONLY if final_authorized mode is active
        if ( 'final_authorized' === $decision_mode ) {
            return array(
                'is_eligible'        => true,
                'result'             => 'approved',
                'reason'             => __( 'All program declarations and authorization criteria satisfied.', 'nexafi-plugin' ),
                'decision_mode'      => 'final_authorized',
                'rule_version'       => $rule_version,
                'headline'           => __( "YOU'RE APPROVED!", 'nexafi-plugin' ),
                'subtitle'           => __( 'Your GDG Smiles in-house financing has been approved.', 'nexafi-plugin' ),
                'message'            => '',
                'requires_contact'   => false,
            );
        }

        $prelim_msg = get_option(
            'nexafi_preliminary_message',
            __( 'Your self-reported declarations satisfy initial financing eligibility. A GDG representative will review your treatment plan to finalize your financing.', 'nexafi-plugin' )
        );

        return array(
            'is_eligible'        => true,
            'result'             => 'preliminary',
            'reason'             => __( 'Declarations satisfy initial criteria. Pending written authorization and representative review.', 'nexafi-plugin' ),
            'decision_mode'      => 'preliminary_only',
            'rule_version'       => $rule_version,
            'headline'           => __( 'Preliminary Financing Eligibility', 'nexafi-plugin' ),
            'subtitle'           => __( 'Your self-reported declarations satisfy initial financing requirements.', 'nexafi-plugin' ),
            'message'            => $prelim_msg,
            'requires_contact'   => true,
        );
    }
}
