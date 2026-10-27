<?php
/**
 * CLI Unit Tests for NexaFi Calculator
 *
 * Verifies integer cents arithmetic, rounding, fee bases, and monthly reconciliation.
 * Run via: php tests/test-calculator.php
 */

// Define mock ABSPATH and dummy translation functions if running standalone
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../' );
}

if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = array() ) {
        if ( is_object( $args ) ) {
            $r = get_object_vars( $args );
        } elseif ( is_array( $args ) ) {
            $r =& $args;
        } else {
            $r = array();
        }
        if ( is_array( $defaults ) ) {
            return array_merge( $defaults, $r );
        }
        return $r;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $opt, $default = false ) {
        return $default;
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

require_once __DIR__ . '/../includes/class-nexafi-calculator.php';

$test_quotes = array( 10000.00, 2500.55, 49999.99 );
$test_bases  = array( 'financed_amount', 'treatment_price' );
$test_fees   = array( 0.00, 35.00 );

$passed = 0;
$total  = 0;

echo "========================================================================================\n";
echo "NexaFi Calculator Integer Cents Arithmetic Test Suite\n";
echo "========================================================================================\n\n";

foreach ( $test_quotes as $quote ) {
    echo "----------------------------------------------------------------------------------------\n";
    echo sprintf( "QUOTE: $%s\n", number_format( $quote, 2 ) );
    echo "----------------------------------------------------------------------------------------\n";

    foreach ( $test_bases as $basis ) {
        foreach ( $test_fees as $fee ) {
            $total++;

            // Run calculation with verified = true
            $calc_verified = NexaFi_Calculator::calculate( $quote, array(
                'upfront_pct'         => 70.0,
                'financed_pct'        => 30.0,
                'charge_pct'          => 15.0,
                'repayment_term'      => 12,
                'charge_basis'        => $basis,
                'plan_fee'            => $fee,
                'plan_fee_treatment'  => 'financed',
                'terms_verified'      => true,
            ) );

            // 1. Verify split sum: down_payment + financed == quote (exact to the cent)
            $cents_quote    = (int) round( $quote * 100 );
            $cents_down     = (int) round( $calc_verified['upfront_amount'] * 100 );
            $cents_fin      = (int) round( $calc_verified['financed_amount'] * 100 );
            $cents_sum_ok   = ( ( $cents_down + $cents_fin ) === $cents_quote );

            // 2. Verify total installments = financed + charge_amount
            $cents_chg      = (int) round( $calc_verified['charge_amount'] * 100 );
            $cents_tot      = (int) round( $calc_verified['total_installments'] * 100 );
            $cents_tot_ok   = ( ( $cents_fin + $cents_chg ) === $cents_tot );

            // 3. Verify monthly payment reconciliation: (monthly * 12) + remainder == total installments
            $cents_mo       = (int) round( $calc_verified['monthly_payment'] * 100 );
            $rem_cents      = $calc_verified['remainder_cents'];
            $reconciled_ok  = ( ( ( $cents_mo * 12 ) + $rem_cents ) === $cents_tot );

            // 4. Verify terms_verified gating: unverified should return '—' for charge/total/monthly
            $calc_unverified = NexaFi_Calculator::calculate( $quote, array(
                'charge_basis'   => $basis,
                'plan_fee'       => $fee,
                'terms_verified' => false,
            ) );
            $gate_ok = ( $calc_unverified['formatted_charge'] === '—' &&
                         $calc_unverified['formatted_total_installments'] === '—' &&
                         $calc_unverified['formatted_monthly'] === '—' );

            $is_success = $cents_sum_ok && $cents_tot_ok && $reconciled_ok && $gate_ok;
            if ( $is_success ) {
                $passed++;
            }

            echo sprintf(
                "[%s] Basis: %-15s | Fee: $%5.2f | Down: $%8.2f | Fin: $%8.2f | Chg: $%8.2f | Total: $%8.2f | Mo: $%7.2f (Rem: %+2d¢, Final: $%7.2f)\n",
                $is_success ? 'PASS' : 'FAIL',
                $basis,
                $fee,
                $calc_verified['upfront_amount'],
                $calc_verified['financed_amount'],
                $calc_verified['charge_amount'],
                $calc_verified['total_installments'],
                $calc_verified['monthly_payment'],
                $rem_cents,
                $calc_verified['final_payment']
            );

            if ( ! $is_success ) {
                echo "   Assertion details: Sum=" . ( $cents_sum_ok ? 'OK' : 'FAIL' ) .
                     ", Tot=" . ( $cents_tot_ok ? 'OK' : 'FAIL' ) .
                     ", Reconcile=" . ( $reconciled_ok ? 'OK' : 'FAIL' ) .
                     ", Gate=" . ( $gate_ok ? 'OK' : 'FAIL' ) . "\n";
            }
        }
    }
    echo "\n";
}

echo "========================================================================================\n";
echo sprintf( "Results: %d/%d tests passed (%s)\n", $passed, $total, ( $passed === $total ) ? '100% SUCCESS' : 'FAILURES OCCURRED' );
echo "========================================================================================\n";

exit( ( $passed === $total ) ? 0 : 1 );
