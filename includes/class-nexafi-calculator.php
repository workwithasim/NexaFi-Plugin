<?php
/**
 * NexaFi Financing Calculator
 *
 * Handles precise financing calculations, rounding, currency formatting,
 * and financing plan breakdowns according to NexaFi / GDG Smiles rules.
 *
 * @package NexaFi_Financing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NexaFi_Calculator {

    /**
     * Default financing configuration
     */
    const DEFAULT_UPFRONT_PCT = 70.0;
    const DEFAULT_FINANCED_PCT = 30.0;
    const DEFAULT_CHARGE_PCT = 15.0;
    const DEFAULT_REPAYMENT_TERM = 12;

    /**
     * Calculate financing terms for a given treatment quote
     *
     * Formula:
     * - Upfront Payment = Treatment Quote × Upfront %
     * - Financed Amount = Treatment Quote × Financed %
     * - Financing Charge = Financed Amount × Charge %
     * - Total Repayment = Financed Amount + Financing Charge
     * - Monthly Payment = Total Repayment ÷ Repayment Term
     *
     * @param float $quote Total treatment quote amount.
     * @param array $custom_settings Optional overrides for financing parameters.
     * @return array Calculation breakdown.
     */
    public static function calculate( $quote, $custom_settings = array() ) {
        $quote = floatval( $quote );
        if ( $quote <= 0 ) {
            $quote = 0.0;
        }

        $settings = wp_parse_args( $custom_settings, self::get_configured_settings() );

        $upfront_pct    = floatval( $settings['upfront_pct'] );
        $financed_pct   = floatval( $settings['financed_pct'] );
        $charge_pct     = floatval( $settings['charge_pct'] );
        $repayment_term = max( 1, intval( $settings['repayment_term'] ) );

        // Ensure percentage consistency
        if ( round( $upfront_pct + $financed_pct, 2 ) !== 100.0 ) {
            $financed_pct = 100.0 - $upfront_pct;
        }

        // Exact decimal calculations with standard half-up rounding
        $upfront_amount  = round( $quote * ( $upfront_pct / 100 ), 2 );
        $financed_amount = round( $quote * ( $financed_pct / 100 ), 2 );
        $charge_amount   = round( $financed_amount * ( $charge_pct / 100 ), 2 );
        $total_repayment = round( $financed_amount + $charge_amount, 2 );
        $monthly_payment = round( $total_repayment / $repayment_term, 2 );

        return array(
            'treatment_quote'     => $quote,
            'upfront_pct'         => $upfront_pct,
            'financed_pct'        => $financed_pct,
            'charge_pct'          => $charge_pct,
            'repayment_term'      => $repayment_term,
            'upfront_amount'      => $upfront_amount,
            'financed_amount'     => $financed_amount,
            'charge_amount'       => $charge_amount,
            'total_repayment'     => $total_repayment,
            'monthly_payment'     => $monthly_payment,
            // Formatted strings matching reference design ($10,000, $3,000 etc.)
            'formatted_quote'     => self::format_currency( $quote, true ),
            'formatted_upfront'   => self::format_currency( $upfront_amount, true ),
            'formatted_financed'  => self::format_currency( $financed_amount, true ),
            'formatted_charge'    => self::format_currency( $charge_amount, true ),
            'formatted_repayment' => self::format_currency( $total_repayment, true ),
            'formatted_monthly'   => self::format_currency( $monthly_payment, false ),
        );
    }

    /**
     * Get active configured financing parameters from WordPress options
     *
     * @return array
     */
    public static function get_configured_settings() {
        return array(
            'upfront_pct'    => floatval( get_option( 'nexafi_upfront_pct', self::DEFAULT_UPFRONT_PCT ) ),
            'financed_pct'   => floatval( get_option( 'nexafi_financed_pct', self::DEFAULT_FINANCED_PCT ) ),
            'charge_pct'     => floatval( get_option( 'nexafi_charge_pct', self::DEFAULT_CHARGE_PCT ) ),
            'repayment_term' => intval( get_option( 'nexafi_repayment_term', self::DEFAULT_REPAYMENT_TERM ) ),
            'max_quote'      => floatval( get_option( 'nexafi_max_quote', 50000.0 ) ),
        );
    }

    /**
     * Format a monetary number into clean USD currency display
     *
     * @param float $amount
     * @param bool  $trim_zeros If true, amounts with .00 omit decimals.
     * @return string
     */
    public static function format_currency( $amount, $trim_zeros = false ) {
        $amount = floatval( $amount );
        if ( $trim_zeros && floor( $amount ) == $amount ) {
            return '$' . number_format( $amount, 0 );
        }
        return '$' . number_format( $amount, 2 );
    }

    /**
     * Validate financing settings percentages
     *
     * @param float $upfront_pct
     * @param float $financed_pct
     * @param float $charge_pct
     * @param int   $repayment_term
     * @return true|WP_Error
     */
    public static function validate_settings( $upfront_pct, $financed_pct, $charge_pct, $repayment_term ) {
        if ( $upfront_pct < 0 || $upfront_pct > 100 ) {
            return new WP_Error( 'invalid_upfront', __( 'Upfront contribution percentage must be between 0% and 100%.', 'contact-form' ) );
        }
        if ( $financed_pct < 0 || $financed_pct > 100 ) {
            return new WP_Error( 'invalid_financed', __( 'Financed percentage must be between 0% and 100%.', 'contact-form' ) );
        }
        if ( round( $upfront_pct + $financed_pct, 2 ) !== 100.0 ) {
            return new WP_Error( 'pct_sum_mismatch', __( 'Upfront percentage and financed percentage must sum to exactly 100%.', 'contact-form' ) );
        }
        if ( $charge_pct < 0 ) {
            return new WP_Error( 'invalid_charge', __( 'Financing charge percentage cannot be negative.', 'contact-form' ) );
        }
        if ( $repayment_term < 1 || $repayment_term > 120 ) {
            return new WP_Error( 'invalid_term', __( 'Repayment term must be between 1 and 120 months.', 'contact-form' ) );
        }
        return true;
    }
}
