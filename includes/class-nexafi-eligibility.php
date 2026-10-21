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
        // 1. Fetch current decision policy mode
        $decision_mode = get_option( 'nexafi_decision_mode', 'final_authorized' );
        $rule_version  = self::RULE_VERSION;

        // 2. Validate representative (with automatic fallback to default active representative)
        if ( empty( $representative ) || empty( $representative->id ) ) {
            $db = NexaFi_DB::get_instance();
            $representative = $db->get_default_representative();
        }

        // 3. Validate Consent (default to 1 if submitted)
        if ( isset( $data['consent_status'] ) && empty( $data['consent_status'] ) ) {
            return array(
                'is_eligible'        => false,
                'result'             => 'ineligible',
                'reason'             => __( 'Applicant consent was not provided.', 'contact-form' ),
                'decision_mode'      => $decision_mode,
                'rule_version'       => $rule_version,
                'headline'           => __( 'Consent Required', 'contact-form' ),
                'message'            => __( 'Financing authorization consent is required to process this application.', 'contact-form' ),
                'requires_contact'   => true,
            );
        }

        // 4. Validate Declaration Answers (default confirmed for patient application flow)
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
                $missing[] = __( 'valid unexpired driver’s license or state ID', 'contact-form' );
            }
            if ( ! $has_debit ) {
                $missing[] = __( 'active debit card from a traditional bank or credit union', 'contact-form' );
            }

            $reason = __( 'Unfortunately, you don’t currently meet the requirements for our in-house financing program. Don’t worry! Your GDG Smiles representative can help you explore other available payment options.', 'contact-form' );

            $ineligibility_msg = $reason;

            return array(
                'is_eligible'        => false,
                'result'             => 'ineligible',
                'reason'             => $reason,
                'decision_mode'      => $decision_mode,
                'rule_version'       => $rule_version,
                'headline'           => __( 'We’re Unable to Offer In-House Financing Right Now', 'contact-form' ),
                'message'            => $ineligibility_msg,
                'requires_contact'   => true,
            );
        }

        // 5. Declarations are satisfied. Return approved decision
        return array(
            'is_eligible'        => true,
            'result'             => 'approved',
            'reason'             => __( 'All program declarations and authorization criteria satisfied.', 'contact-form' ),
            'decision_mode'      => 'final_authorized',
            'rule_version'       => $rule_version,
            'headline'           => __( "YOU'RE APPROVED!", 'contact-form' ),
            'subtitle'           => __( 'Your GDG Smiles in-house financing has been approved.', 'contact-form' ),
            'message'            => '',
            'requires_contact'   => false,
        );
    }
}
