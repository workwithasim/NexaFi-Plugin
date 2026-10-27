<?php
/**
 * NexaFi Financing Calculator
 *
 * Handles precise financing calculations, rounding, currency formatting,
 * and financing plan breakdowns according to NexaFi / GDG Smiles rules.
 * All internal arithmetic is performed in integer cents to eliminate floating-point drift.
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
    const DEFAULT_UPFRONT_PCT        = 70.0;
    const DEFAULT_FINANCED_PCT       = 30.0;
    const DEFAULT_CHARGE_PCT         = 15.0;
    const DEFAULT_REPAYMENT_TERM     = 12;
    const DEFAULT_CHARGE_BASIS       = 'financed_amount';
    const DEFAULT_PLAN_FEE           = 0.00;
    const DEFAULT_PLAN_FEE_TREATMENT = 'financed';

    /**
     * Calculate financing terms for a given treatment quote using integer cents arithmetic.
     *
     * Formulas (performed in integer cents):
     * - Base Down Payment (Cents) = round( Quote_Cents × ( Upfront_% ÷ 100 ) )
     * - Financed Amount (Cents) = Quote_Cents - Base_Down_Payment_Cents
     * - Percentage Charge Base:
     *     * If charge_basis === 'treatment_price': Base = Quote_Cents
     *     * If charge_basis === 'financed_amount': Base = Financed_Amount_Cents
     * - Percentage Charge (Cents) = round( Base × ( Charge_% ÷ 100 ) )
     * - Plan Fee Treatment:
     *     * If plan_fee_treatment === 'financed':
     *         Required Down Payment (Cents) = Base_Down_Payment_Cents
     *         Charge Amount (Cents) = Percentage_Charge_Cents + Plan_Fee_Cents
     *         Total Installment Payments (Cents) = Financed_Amount_Cents + Charge_Amount_Cents
     *     * If plan_fee_treatment === 'upfront':
     *         Required Down Payment (Cents) = Base_Down_Payment_Cents + Plan_Fee_Cents
     *         Charge Amount (Cents) = Percentage_Charge_Cents
     *         Total Installment Payments (Cents) = Financed_Amount_Cents + Percentage_Charge_Cents
     * - Monthly Payment (Cents) = round( Total_Installments_Cents ÷ Term )
     * - Monthly Reconciliation: Remainder_Cents = Total_Installments_Cents - ( Monthly_Cents × Term )
     *   Final Payment (Cents) = ( Monthly_Cents + Remainder_Cents )
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

        $upfront_pct         = floatval( $settings['upfront_pct'] );
        $financed_pct        = floatval( $settings['financed_pct'] );
        $charge_pct          = floatval( $settings['charge_pct'] );
        $repayment_term      = max( 1, intval( $settings['repayment_term'] ) );
        $charge_basis        = in_array( $settings['charge_basis'], array( 'treatment_price', 'financed_amount' ), true ) ? $settings['charge_basis'] : self::DEFAULT_CHARGE_BASIS;
        $plan_fee            = max( 0.0, floatval( $settings['plan_fee'] ) );
        $plan_fee_treatment  = in_array( $settings['plan_fee_treatment'], array( 'financed', 'upfront' ), true ) ? $settings['plan_fee_treatment'] : self::DEFAULT_PLAN_FEE_TREATMENT;
        $terms_verified      = ! empty( $settings['terms_verified'] );
        $booking_deposit     = max( 0.0, floatval( $settings['booking_deposit'] ?? 0.0 ) );

        // Ensure percentage consistency
        if ( round( $upfront_pct + $financed_pct, 2 ) !== 100.0 ) {
            $financed_pct = 100.0 - $upfront_pct;
        }

        // Integer cents conversion
        $quote_cents = (int) round( $quote * 100 );

        // Base down payment & financed amount (split cents cleanly)
        $base_down_payment_cents = (int) round( $quote_cents * ( $upfront_pct / 100 ) );
        $financed_cents          = $quote_cents - $base_down_payment_cents;

        // Base for percentage charge
        $base_cents       = ( 'treatment_price' === $charge_basis ) ? $quote_cents : $financed_cents;
        $pct_charge_cents = (int) round( $base_cents * ( $charge_pct / 100 ) );

        $plan_fee_cents = (int) round( $plan_fee * 100 );

        if ( 'financed' === $plan_fee_treatment ) {
            $down_payment_cents       = $base_down_payment_cents;
            $charge_amount_cents      = $pct_charge_cents + $plan_fee_cents;
            $total_installments_cents = $financed_cents + $charge_amount_cents;
        } else {
            // 'upfront': Required Down Payment (cents) = round(quote x upfront%) + plan_fee_cents.
            // Amount Financed stays quote - base down payment, so fee does not reduce financed amount.
            // Financing Charge and Total Installment Payments exclude fee.
            $down_payment_cents       = $base_down_payment_cents + $plan_fee_cents;
            $charge_amount_cents      = $pct_charge_cents;
            $total_installments_cents = $financed_cents + $pct_charge_cents;
        }

        $monthly_cents          = (int) round( $total_installments_cents / $repayment_term );
        $reconciled_total_cents = $monthly_cents * $repayment_term;
        $remainder_cents        = $total_installments_cents - $reconciled_total_cents;
        $final_payment_cents    = $monthly_cents + $remainder_cents;

        // Booking deposit reconciliation
        $booking_deposit_cents = (int) round( $booking_deposit * 100 );
        $remaining_down_cents  = max( 0, $down_payment_cents - $booking_deposit_cents );

        // Convert back to currency floats
        $base_down_payment      = $base_down_payment_cents / 100;
        $upfront_amount         = $down_payment_cents / 100; // Required Down Payment including fee
        $financed_amount        = $financed_cents / 100;
        $charge_amount          = $charge_amount_cents / 100;
        $total_installments     = $total_installments_cents / 100;
        $monthly_payment        = $monthly_cents / 100;
        $final_payment          = $final_payment_cents / 100;
        $remaining_down_payment = $remaining_down_cents / 100;

        // Final payment reconciliation notice (Section 3: when remainder_cents != 0 and terms verified)
        $first_payments_count = $repayment_term - 1;
        $reconciliation_note  = ( $terms_verified && 0 !== $remainder_cents && $repayment_term > 1 )
            ? sprintf(
                /* translators: 1: number of payments, 2: monthly payment amount, 3: final payment amount */
                __( 'Your first %1$d payments are %2$s. Your final payment is %3$s.', 'nexafi-plugin' ),
                $first_payments_count,
                self::format_currency( $monthly_payment, false ),
                self::format_currency( $final_payment, false )
            )
            : '';

        return array(
            'treatment_quote'             => $quote,
            'upfront_pct'                 => $upfront_pct,
            'financed_pct'                => $financed_pct,
            'charge_pct'                  => $charge_pct,
            'repayment_term'              => $repayment_term,
            'charge_basis'                => $charge_basis,
            'plan_fee'                    => $plan_fee,
            'plan_fee_treatment'          => $plan_fee_treatment,
            'terms_verified'              => $terms_verified,
            'booking_deposit'             => $booking_deposit,
            'base_down_payment'           => $base_down_payment,
            'remaining_down_payment'      => $remaining_down_payment,
            'upfront_amount'              => $upfront_amount,
            'financed_amount'             => $financed_amount,
            'charge_amount'               => $charge_amount,
            'total_repayment'             => $total_installments, // Backward compatibility alias
            'total_installments'          => $total_installments,
            'monthly_payment'             => $monthly_payment,
            'remainder_cents'             => $remainder_cents,
            'final_payment'               => $final_payment,
            'reconciliation_note'         => $reconciliation_note,
            // Formatted strings matching reference design ($10,000, $3,000 etc.)
            'formatted_quote'             => self::format_currency( $quote, true ),
            'formatted_base_down'         => self::format_currency( $base_down_payment, true ),
            'formatted_plan_fee'          => self::format_currency( $plan_fee, true ),
            'formatted_upfront'           => self::format_currency( $upfront_amount, true ),
            'formatted_financed'          => self::format_currency( $financed_amount, true ),
            'formatted_charge'            => $terms_verified ? self::format_currency( $charge_amount, true ) : '—',
            'formatted_repayment'         => $terms_verified ? self::format_currency( $total_installments, true ) : '—',
            'formatted_total_installments'=> $terms_verified ? self::format_currency( $total_installments, true ) : '—',
            'formatted_monthly'           => $terms_verified ? self::format_currency( $monthly_payment, false ) : '—',
            'formatted_final_payment'     => $terms_verified ? self::format_currency( $final_payment, false ) : '—',
            'formatted_booking_deposit'   => ( $booking_deposit > 0 ) ? self::format_currency( $booking_deposit, true ) : '',
            'formatted_remaining_down'    => self::format_currency( $remaining_down_payment, true ),
            'formatted_remaining_down_payment' => self::format_currency( $remaining_down_payment, true ),
        );
    }

    /**
     * Get active configured financing parameters from WordPress options
     *
     * @return array
     */
    public static function get_configured_settings() {
        return array(
            'upfront_pct'        => floatval( get_option( 'nexafi_upfront_pct', self::DEFAULT_UPFRONT_PCT ) ),
            'financed_pct'       => floatval( get_option( 'nexafi_financed_pct', self::DEFAULT_FINANCED_PCT ) ),
            'charge_pct'         => floatval( get_option( 'nexafi_charge_pct', self::DEFAULT_CHARGE_PCT ) ),
            'repayment_term'     => intval( get_option( 'nexafi_repayment_term', self::DEFAULT_REPAYMENT_TERM ) ),
            'min_quote'          => floatval( get_option( 'nexafi_min_quote', 0.0 ) ),
            'max_quote'          => floatval( get_option( 'nexafi_max_quote', 50000.0 ) ),
            'charge_basis'       => get_option( 'nexafi_charge_basis', self::DEFAULT_CHARGE_BASIS ),
            'plan_fee'           => floatval( get_option( 'nexafi_plan_fee', self::DEFAULT_PLAN_FEE ) ),
            'plan_fee_treatment' => get_option( 'nexafi_plan_fee_treatment', self::DEFAULT_PLAN_FEE_TREATMENT ),
            'terms_verified'     => (bool) get_option( 'nexafi_terms_verified', false ),
            'booking_deposit'    => floatval( get_option( 'nexafi_booking_deposit', 0.00 ) ),
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
     * Validate financing settings percentages and parameters
     *
     * @param float  $upfront_pct
     * @param float  $financed_pct
     * @param float  $charge_pct
     * @param int    $repayment_term
     * @param string $charge_basis
     * @param float  $plan_fee
     * @param string $plan_fee_treatment
     * @return true|WP_Error
     */
    public static function validate_settings( $upfront_pct, $financed_pct, $charge_pct, $repayment_term, $charge_basis = 'financed_amount', $plan_fee = 0.0, $plan_fee_treatment = 'financed' ) {
        if ( $upfront_pct < 0 || $upfront_pct > 100 ) {
            return new WP_Error( 'invalid_upfront', __( 'Upfront contribution percentage must be between 0% and 100%.', 'nexafi-plugin' ) );
        }
        if ( $financed_pct < 0 || $financed_pct > 100 ) {
            return new WP_Error( 'invalid_financed', __( 'Financed percentage must be between 0% and 100%.', 'nexafi-plugin' ) );
        }
        if ( round( $upfront_pct + $financed_pct, 2 ) !== 100.0 ) {
            return new WP_Error( 'pct_sum_mismatch', __( 'Upfront percentage and financed percentage must sum to exactly 100%.', 'nexafi-plugin' ) );
        }
        if ( $charge_pct < 0 ) {
            return new WP_Error( 'invalid_charge', __( 'Financing charge percentage cannot be negative.', 'nexafi-plugin' ) );
        }
        if ( $repayment_term < 1 || $repayment_term > 120 ) {
            return new WP_Error( 'invalid_term', __( 'Repayment term must be between 1 and 120 months.', 'nexafi-plugin' ) );
        }
        if ( ! in_array( $charge_basis, array( 'financed_amount', 'treatment_price' ), true ) ) {
            return new WP_Error( 'invalid_charge_basis', __( 'Financing charge basis must be either financed_amount or treatment_price.', 'nexafi-plugin' ) );
        }
        if ( $plan_fee < 0 ) {
            return new WP_Error( 'invalid_plan_fee', __( 'Plan fee cannot be negative.', 'nexafi-plugin' ) );
        }
        if ( ! in_array( $plan_fee_treatment, array( 'financed', 'upfront' ), true ) ) {
            return new WP_Error( 'invalid_plan_fee_treatment', __( 'Plan fee treatment must be either financed or upfront.', 'nexafi-plugin' ) );
        }
        return true;
    }
}

