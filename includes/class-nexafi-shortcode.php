<?php
/**
 * NexaFi Shortcode & Application Renderer
 *
 * Renders the complete 5-screen patient financing application interface.
 * Embeddable via [nexafi_financing_application] or [nexafi_contact_form]
 * into any Elementor page, standard page, or custom layout.
 *
 * @package NexaFi_Financing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NexaFi_Shortcode {

    /**
     * Singleton instance
     *
     * @var NexaFi_Shortcode|null
     */
    private static $instance = null;

    /**
     * Form counter instance for unique DOM IDs
     *
     * @var int
     */
    private static $instance_count = 0;

    /**
     * Get singleton instance
     *
     * @return NexaFi_Shortcode
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
        add_shortcode( 'nexafi_financing_application', array( $this, 'render_shortcode' ) );
        add_shortcode( 'nexafi_contact_form', array( $this, 'render_shortcode' ) );
        add_shortcode( 'nexafi_financing_form', array( $this, 'render_shortcode' ) );
    }

    /**
     * Render the 5-screen financing application
     *
     * @param array $atts
     * @return string
     */
    public function render_shortcode( $atts = array() ) {
        self::$instance_count++;
        $instance_id = 'nexafi-app-' . self::$instance_count;

        $atts = shortcode_atts(
            array(
                'start_screen' => '2',
                'privacy_url'  => '',
                'return_url'   => '',
            ),
            $atts,
            'nexafi_financing_application'
        );

        // Enqueue frontend scripts & styles
        wp_enqueue_style( 'nexafi-frontend-css' );
        wp_enqueue_script( 'nexafi-frontend-js' );

        $privacy_url = ! empty( $atts['privacy_url'] ) ? esc_url( $atts['privacy_url'] ) : esc_url( get_option( 'nexafi_privacy_policy_url', home_url( '/privacy-policy/' ) ) );
        $return_url  = ! empty( $atts['return_url'] ) ? esc_url( $atts['return_url'] ) : esc_url( get_option( 'nexafi_return_url', '' ) );

        $start_screen = in_array( (string) $atts['start_screen'], array( '2', '3' ), true ) ? (int) $atts['start_screen'] : 2;

        $disclosure_text      = get_option( 'nexafi_disclosure_text', 'Financing disclosures pending verification.' );
        $provider_disclosure  = get_option( 'nexafi_provider_disclosure', '' );
        $booking_deposit_note = get_option( 'nexafi_booking_deposit_note', 'Any booking deposit you pay is credited toward your Required Down Payment. It does not replace it.' );

        // SVG Logo representations
        $logo_dark_html  = $this->get_logo_svg( 'dark' );
        $logo_light_html = $this->get_logo_svg( 'light' );

        // Active default representative from database
        $db = NexaFi_DB::get_instance();
        $default_rep = $db->get_default_representative();
        $default_rep_id = ( $default_rep && ! empty( $default_rep->rep_id ) ) ? $default_rep->rep_id : '';
        $default_rep_name = ( $default_rep && ! empty( $default_rep->name ) ) ? $default_rep->name : '';
        $default_rep_photo = ( $default_rep && ! empty( $default_rep->photo_url ) ) ? $default_rep->photo_url : '';

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $instance_id ); ?>" 
             class="nexafi-app-wrapper" 
             data-start-screen="<?php echo esc_attr( $start_screen ); ?>"
             data-privacy-url="<?php echo esc_attr( $privacy_url ); ?>"
             data-return-url="<?php echo esc_attr( $return_url ); ?>"
             data-home-url="<?php echo esc_attr( home_url( '/' ) ); ?>">

            <!-- =========================================================================
                 APPLICATION FORM (Personal & Employment Details)
                 ========================================================================= -->
            <section class="nexafi-screen nexafi-screen-2 <?php echo ( 2 === $start_screen || 1 === $start_screen ) ? 'nexafi-active' : ''; ?>" data-screen="2">
                <div class="nexafi-card">
                    
                    <!-- Top Navigation Bar -->
                    <div class="nexafi-topbar">
                        <?php if ( ! empty( $return_url ) ) : ?>
                        <a href="<?php echo esc_url( $return_url ); ?>" class="nexafi-nav-back nexafi-nav-back-home" aria-label="Go back">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                        </a>
                        <?php else : ?>
                        <div class="nexafi-nav-spacer"></div>
                        <?php endif; ?>
                        <div class="nexafi-topbar-logo">
                            <?php echo $logo_dark_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                        <div class="nexafi-nav-spacer"></div>
                    </div>

                    <!-- Progress Indicator: Step 1 of 2 -->
                    <div class="nexafi-progress-wrapper">
                        <div class="nexafi-progress-meta">
                            <span class="nexafi-step-tag">Step 1 of 2</span>
                        </div>
                        <div class="nexafi-progress-track">
                            <div class="nexafi-progress-bar" style="width: 50%;"></div>
                        </div>
                    </div>

                    <!-- Heading & Subtitle -->
                    <div class="nexafi-heading-group">
                        <h2 class="nexafi-card-title">Tell Us About You</h2>
                        <p class="nexafi-card-subtitle">This helps us get you approved quickly.</p>
                    </div>

                    <!-- Validation Alert Box -->
                    <div class="nexafi-alert nexafi-alert-error" style="display: none;" role="alert">
                        <span class="nexafi-alert-icon">⚠️</span>
                        <span class="nexafi-alert-message"></span>
                    </div>

                    <!-- Form Content -->
                    <form class="nexafi-form-step nexafi-form-step-2" novalidate>
                        
                        <!-- Honeypot anti-spam -->
                        <div style="display:none !important;" aria-hidden="true">
                            <input type="text" name="nexafi_hp" value="" tabindex="-1" autocomplete="off" />
                        </div>

                        <!-- SECTION A: Personal Information -->
                        <div class="nexafi-form-section">
                            <div class="nexafi-grid nexafi-grid-2">
                                <div class="nexafi-field-group">
                                    <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-fname">First Name <span class="nexafi-req">*</span></label>
                                    <input type="text" id="<?php echo esc_attr( $instance_id ); ?>-fname" name="first_name" class="nexafi-input" placeholder="Jennifer" required autocomplete="given-name" />
                                </div>
                                <div class="nexafi-field-group">
                                    <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-lname">Last Name <span class="nexafi-req">*</span></label>
                                    <input type="text" id="<?php echo esc_attr( $instance_id ); ?>-lname" name="last_name" class="nexafi-input" placeholder="Rodriguez" required autocomplete="family-name" />
                                </div>
                            </div>

                            <div class="nexafi-field-group">
                                <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-dob">Date of Birth <span class="nexafi-req">*</span></label>
                                <div class="nexafi-input-icon-wrap">
                                    <input type="date" id="<?php echo esc_attr( $instance_id ); ?>-dob" name="dob" class="nexafi-input" required autocomplete="bday" max="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '-18 years' ) ) ); ?>" />
                                    <span class="nexafi-input-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                    </span>
                                </div>
                                <div class="nexafi-field-error nexafi-dob-error" style="display: none; color: #dc2626; font-size: 12px; margin-top: 4px; font-weight: 500;">
                                    <?php esc_html_e( 'You must be at least 18 years old to apply.', 'nexafi-plugin' ); ?>
                                </div>
                            </div>

                            <div class="nexafi-grid nexafi-grid-contact">
                                <div class="nexafi-field-group">
                                    <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-phone">Phone Number <span class="nexafi-req">*</span></label>
                                    <input type="tel" id="<?php echo esc_attr( $instance_id ); ?>-phone" name="phone" class="nexafi-input" placeholder="(555) 123-4567" required autocomplete="tel" inputmode="tel" />
                                </div>

                                <div class="nexafi-field-group">
                                    <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-email">Email Address <span class="nexafi-req">*</span></label>
                                    <input type="email" id="<?php echo esc_attr( $instance_id ); ?>-email" name="email" class="nexafi-input" placeholder="jennifer@gmail.com" required autocomplete="email" inputmode="email" autocapitalize="off" autocorrect="off" spellcheck="false" />
                                </div>
                            </div>
                        </div>

                        <!-- SECTION B: Home Address -->
                        <div class="nexafi-form-section">
                            <div class="nexafi-field-group">
                                <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-address">Home Address <span class="nexafi-req">*</span></label>
                                <input type="text" id="<?php echo esc_attr( $instance_id ); ?>-address" name="home_address" class="nexafi-input" placeholder="1234 Main St" required autocomplete="address-line1" />
                            </div>

                            <div class="nexafi-grid nexafi-grid-3">
                                <div class="nexafi-field-group">
                                    <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-city">City <span class="nexafi-req">*</span></label>
                                    <input type="text" id="<?php echo esc_attr( $instance_id ); ?>-city" name="city" class="nexafi-input" placeholder="Fort Lauderdale" required autocomplete="address-level2" />
                                </div>
                                <div class="nexafi-field-group">
                                    <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-state">State <span class="nexafi-req">*</span></label>
                                    <select id="<?php echo esc_attr( $instance_id ); ?>-state" name="state" class="nexafi-select" required autocomplete="address-level1">
                                        <option value=""><?php esc_html_e( 'Select state', 'nexafi-plugin' ); ?></option>
                                        <?php echo $this->get_us_states_options( '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </select>
                                </div>
                                <div class="nexafi-field-group">
                                    <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-zip">ZIP Code <span class="nexafi-req">*</span></label>
                                    <input type="text" id="<?php echo esc_attr( $instance_id ); ?>-zip" name="zip_code" class="nexafi-input" placeholder="33301" maxlength="10" required autocomplete="postal-code" inputmode="numeric" pattern="[0-9]*" />
                                </div>
                            </div>
                        </div>

                        <!-- SECTION C: Employment and Income -->
                        <div class="nexafi-form-section">
                            <div class="nexafi-field-group">
                                <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-emp-status">Employment Status <span class="nexafi-req">*</span></label>
                                <select id="<?php echo esc_attr( $instance_id ); ?>-emp-status" name="employment_status" class="nexafi-select nexafi-emp-toggle" required autocomplete="off">
                                    <option value="">Select Employment Status</option>
                                    <option value="Employed">Employed</option>
                                    <option value="Self-Employed">Self-Employed</option>
                                    <option value="Unemployed">Unemployed</option>
                                    <option value="Retired">Retired</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <div class="nexafi-emp-dependent-fields">
                                <div class="nexafi-grid nexafi-grid-2">
                                    <div class="nexafi-field-group">
                                        <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-employer">Employer Name <span class="nexafi-req nexafi-emp-req">*</span></label>
                                        <input type="text" id="<?php echo esc_attr( $instance_id ); ?>-employer" name="employer_name" class="nexafi-input" placeholder="Baptist Health" required autocomplete="organization" />
                                    </div>
                                    <div class="nexafi-field-group">
                                        <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-jobtitle">Job Title <span class="nexafi-req nexafi-emp-req">*</span></label>
                                        <input type="text" id="<?php echo esc_attr( $instance_id ); ?>-jobtitle" name="job_title" class="nexafi-input" placeholder="Dental Assistant" required autocomplete="organization-title" />
                                    </div>
                                </div>
                            </div>

                            <div class="nexafi-grid nexafi-grid-2">
                                <div class="nexafi-field-group nexafi-time-at-job-field">
                                    <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-timejob">Time at Job <span class="nexafi-req nexafi-emp-req">*</span></label>
                                    <select id="<?php echo esc_attr( $instance_id ); ?>-timejob" name="time_at_job" class="nexafi-select" required autocomplete="off">
                                        <option value="">Select Time at Job</option>
                                        <option value="Less than 1 year">Less than 1 year</option>
                                        <option value="1-2 years">1-2 years</option>
                                        <option value="2-3 years">2-3 years</option>
                                        <option value="3+ years">3+ years</option>
                                    </select>
                                </div>
                                <div class="nexafi-field-group">
                                    <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-income">Monthly Income <span class="nexafi-req">*</span></label>
                                    <div class="nexafi-input-currency-wrap">
                                        <span class="nexafi-currency-symbol">$</span>
                                        <input type="text" id="<?php echo esc_attr( $instance_id ); ?>-income" name="monthly_income" class="nexafi-input nexafi-currency-input" placeholder="4,500" required inputmode="numeric" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION D: Two Required Eligibility Declarations -->
                        <div class="nexafi-form-section nexafi-declarations-box">
                            <label class="nexafi-checkbox-card">
                                <input type="checkbox" name="has_id_declaration" class="nexafi-checkbox" value="1" />
                                <span class="nexafi-checkbox-indicator"></span>
                                <span class="nexafi-checkbox-label">
                                    I have a valid, unexpired driver's license or state ID.
                                </span>
                            </label>

                            <label class="nexafi-checkbox-card">
                                <input type="checkbox" name="has_debit_card_declaration" class="nexafi-checkbox" value="1" />
                                <span class="nexafi-checkbox-indicator"></span>
                                <span class="nexafi-checkbox-label">
                                    I have an active debit card from a traditional bank or credit union (not a prepaid or internet-only bank).
                                </span>
                            </label>
                        </div>

                        <!-- SECTION E: Action Button -->
                        <div class="nexafi-form-action">
                            <button type="button" class="nexafi-btn nexafi-btn-primary nexafi-btn-continue" data-target-screen="3">
                                <span class="nexafi-btn-label">Continue</span>
                                <span class="nexafi-btn-arrow" aria-hidden="true">&rarr;</span>
                            </button>
                        </div>

                    </form>
                </div>
            </section>

            <!-- =========================================================================
                 SCREEN 3 — TREATMENT QUOTE & GDG REPRESENTATIVE ID
                 ========================================================================= -->
            <section class="nexafi-screen nexafi-screen-3 <?php echo 3 === $start_screen ? 'nexafi-active' : ''; ?>" data-screen="3">
                <div class="nexafi-card">
                    
                    <!-- Top Navigation Bar -->
                    <div class="nexafi-topbar">
                        <button type="button" class="nexafi-nav-back" data-target-screen="2" aria-label="Go back to application details">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                        </button>
                        <div class="nexafi-topbar-logo">
                            <?php echo $logo_dark_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                        <div class="nexafi-nav-spacer"></div>
                    </div>

                    <!-- Progress Indicator: Step 2 of 2 -->
                    <div class="nexafi-progress-wrapper">
                        <div class="nexafi-progress-meta">
                            <span class="nexafi-step-tag">Step 2 of 2</span>
                        </div>
                        <div class="nexafi-progress-track">
                            <div class="nexafi-progress-bar" style="width: 100%;"></div>
                        </div>
                    </div>

                    <!-- Heading & Subtitle -->
                    <div class="nexafi-heading-group">
                        <h2 class="nexafi-card-title">Financing Details</h2>
                        <p class="nexafi-card-subtitle">Enter your treatment quote and your GDG representative's ID.</p>
                    </div>

                    <!-- Validation Alert Box -->
                    <div class="nexafi-alert nexafi-alert-error" style="display: none;" role="alert">
                        <span class="nexafi-alert-icon">⚠️</span>
                        <span class="nexafi-alert-message"></span>
                    </div>

                    <form class="nexafi-form-step nexafi-form-step-3" novalidate>
                        
                        <!-- Form Section: Financing Details -->
                        <div class="nexafi-form-section nexafi-form-section-quote">
                            <!-- Field 1: Total Treatment Quote -->
                            <div class="nexafi-field-group">
                                <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-quote">Total Treatment Quote <span class="nexafi-req">*</span></label>
                                <div class="nexafi-input-currency-wrap nexafi-quote-wrap">
                                    <span class="nexafi-currency-symbol">$</span>
                                    <input type="text" id="<?php echo esc_attr( $instance_id ); ?>-quote" name="treatment_quote" class="nexafi-input nexafi-currency-input nexafi-quote-input" placeholder="10,000" required inputmode="numeric" />
                                </div>
                            </div>

                            <!-- Field 2: GDG Representative ID -->
                            <div class="nexafi-field-group">
                                <label class="nexafi-label" for="<?php echo esc_attr( $instance_id ); ?>-repid">GDG Representative ID <span class="nexafi-req">*</span></label>
                                <div class="nexafi-input-rep-wrap">
                                    <input type="text" id="<?php echo esc_attr( $instance_id ); ?>-repid" name="rep_id" class="nexafi-input nexafi-rep-input" placeholder="GDG ID" value="" required autocomplete="off" />
                                    <span class="nexafi-rep-spinner" style="display:none;"></span>
                                </div>
                                <span class="nexafi-rep-status-text"></span>
                            </div>

                            <!-- Representative Preview Card (Dynamic live lookup - shown only when valid ID entered) -->
                            <div class="nexafi-rep-card" style="display: none;">
                                <div class="nexafi-rep-avatar-wrap">
                                    <img src="<?php echo esc_url( $default_rep_photo ); ?>" alt="Representative Photo" class="nexafi-rep-avatar" />
                                </div>
                                <div class="nexafi-rep-info">
                                    <div class="nexafi-rep-name"><?php echo esc_html( $default_rep_name ); ?></div>
                                    <div class="nexafi-rep-code"><?php echo esc_html( $default_rep_id ); ?></div>
                                    <div class="nexafi-rep-role">Your GDG Representative</div>
                                </div>
                            </div>

                            <!-- Representative info confirmation notice (shown only when rep preview is displayed) -->
                            <div class="nexafi-info-callout" style="display: none;">
                                <svg class="nexafi-info-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                <span>Confirm this is your representative to ensure your application is properly tracked.</span>
                            </div>
                        </div>

                        <!-- Required Consent Checkbox -->
                        <div class="nexafi-consent-group">
                            <label class="nexafi-checkbox-card nexafi-consent-card">
                                <input type="checkbox" name="consent" class="nexafi-checkbox" value="1" required />
                                <span class="nexafi-checkbox-indicator"></span>
                                <span class="nexafi-checkbox-label">
                                    <?php esc_html_e( 'I confirm that all information provided is accurate and authorize GDG Smiles and its financing partners to review my information for financing purposes.', 'nexafi-plugin' ); ?>
                                </span>
                            </label>

                            <div class="nexafi-privacy-link-wrap">
                                <a href="<?php echo esc_url( $privacy_url ); ?>" class="nexafi-link nexafi-privacy-link" target="_blank" rel="noopener noreferrer">
                                    View Privacy Policy
                                </a>
                            </div>
                        </div>

                        <!-- Submit Application Action Button -->
                        <div class="nexafi-form-action">
                            <button type="submit" class="nexafi-btn nexafi-btn-primary nexafi-btn-submit" data-submit-label="Submit Application &rarr;">
                                <span class="nexafi-btn-label">Submit Application</span>
                                <span class="nexafi-btn-arrow" aria-hidden="true">&rarr;</span>
                                <span class="nexafi-spinner" style="display:none;"></span>
                            </button>
                        </div>

                    </form>
                </div>
            </section>

            <!-- =========================================================================
                 SCREEN 4 — DECISION (Outcome A: Approved / Preliminary OR Outcome B: Ineligible)
                 ========================================================================= -->
            <section class="nexafi-screen nexafi-screen-4 <?php echo 4 === $start_screen ? 'nexafi-active' : ''; ?>" data-screen="4">
                <div class="nexafi-card nexafi-decision-card">
                    
                    <!-- Top NexaFi Logo -->
                    <div class="nexafi-topbar nexafi-topbar-center">
                        <div class="nexafi-topbar-logo">
                            <?php echo $logo_dark_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                    </div>

                    <!-- OUTCOME A: APPROVED CONTAINER -->
                    <div class="nexafi-decision-outcome nexafi-outcome-approved">
                        
                        <!-- Confetti & Celebration Header -->
                        <div class="nexafi-celebration-badge-wrap">
                            <div class="nexafi-confetti-particle-box" aria-hidden="true">
                                <span class="nexafi-confetti c1"></span><span class="nexafi-confetti c2"></span>
                                <span class="nexafi-confetti c3"></span><span class="nexafi-confetti c4"></span>
                                <span class="nexafi-confetti c5"></span><span class="nexafi-confetti c6"></span>
                                <span class="nexafi-confetti c7 dot"></span><span class="nexafi-confetti c8"></span>
                                <span class="nexafi-confetti c9 dot"></span><span class="nexafi-confetti c10"></span>
                            </div>
                            <div class="nexafi-check-circle">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            </div>
                        </div>

                        <div class="nexafi-decision-title-group">
                            <span class="nexafi-congrats-text">Congratulations!</span>
                            <h2 class="nexafi-approved-heading">YOU'RE APPROVED!</h2>
                            <p class="nexafi-approved-subtext">Your GDG Smiles in-house financing has been approved.</p>
                        </div>

                        <!-- Approved Financing Amount Highlight Card -->
                        <div class="nexafi-amount-featured-card">
                            <div class="nexafi-featured-label"><?php esc_html_e( 'Approved Financing Amount', 'nexafi-plugin' ); ?></div>
                            <div class="nexafi-featured-val nexafi-val-financed">&mdash;</div>
                        </div>

                        <!-- Required Down Payment Featured Box (Section 2b: prominent card directly under approved amount) -->
                        <div class="nexafi-downpayment-featured-box">
                            <div class="nexafi-downpayment-label"><?php esc_html_e( 'Required Down Payment', 'nexafi-plugin' ); ?></div>
                            <div class="nexafi-downpayment-val nexafi-val-down-payment">&mdash;</div>
                            <div class="nexafi-downpayment-sub"><?php esc_html_e( 'Due to begin your treatment plan.', 'nexafi-plugin' ); ?></div>
                            <div class="nexafi-deposit-credit-breakdown" style="display: none;">
                                <div class="nexafi-deposit-credit-line"><?php esc_html_e( 'Booking deposit credited:', 'nexafi-plugin' ); ?> <span class="nexafi-val-deposit-credited">&mdash;</span></div>
                                <div class="nexafi-deposit-remaining-line"><?php esc_html_e( 'Remaining down payment:', 'nexafi-plugin' ); ?> <span class="nexafi-val-deposit-remaining">&mdash;</span></div>
                            </div>
                        </div>

                        <!-- Monthly Payment Highlight Box -->
                        <div class="nexafi-monthly-featured-box">
                            <div class="nexafi-monthly-label"><?php esc_html_e( 'Your Monthly Payment', 'nexafi-plugin' ); ?></div>
                            <div class="nexafi-monthly-val nexafi-val-monthly">&mdash;</div>
                            <div class="nexafi-monthly-sub">
                                <?php esc_html_e( '12 monthly payments', 'nexafi-plugin' ); ?>
                            </div>
                        </div>

                        <!-- Motivation Banner with Gift SVG -->
                        <div class="nexafi-motivation-banner">
                            <div class="nexafi-gift-icon-wrap" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="nexafi-gift-svg">
                                    <polyline points="20 12 20 22 4 22 4 12"></polyline>
                                    <rect x="2" y="7" width="20" height="5" rx="1"></rect>
                                    <line x1="12" y1="22" x2="12" y2="7"></line>
                                    <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"></path>
                                    <path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"></path>
                                </svg>
                            </div>
                            <div class="nexafi-motivation-text">
                                <div class="nexafi-motivation-heading"><?php esc_html_e( 'Your new smile is within reach!', 'nexafi-plugin' ); ?></div>
                                <div class="nexafi-motivation-desc"><?php esc_html_e( 'Other financing options may have said no. GDG Smiles is giving you another opportunity to move forward!', 'nexafi-plugin' ); ?></div>
                            </div>
                        </div>

                        <!-- Compact Plan Details List (Section 2a labels in exact order) -->
                        <div class="nexafi-breakdown-wrap nexafi-plan-details-wrap">
                            <div class="nexafi-breakdown-title"><?php esc_html_e( 'Plan Details', 'nexafi-plugin' ); ?></div>
                            <div class="nexafi-breakdown-table">
                                <div class="nexafi-breakdown-row">
                                    <span class="nexafi-row-label"><?php esc_html_e( 'Total Treatment Price', 'nexafi-plugin' ); ?></span>
                                    <span class="nexafi-row-val nexafi-val-quote">&mdash;</span>
                                </div>
                                <div class="nexafi-breakdown-row">
                                    <span class="nexafi-row-label"><?php esc_html_e( 'Required Down Payment', 'nexafi-plugin' ); ?></span>
                                    <span class="nexafi-row-val nexafi-val-down-payment-table nexafi-val-upfront">&mdash;</span>
                                </div>
                                <div class="nexafi-breakdown-row">
                                    <span class="nexafi-row-label"><?php esc_html_e( 'Amount Financed', 'nexafi-plugin' ); ?></span>
                                    <span class="nexafi-row-val nexafi-val-financed-sub">&mdash;</span>
                                </div>
                                <div class="nexafi-breakdown-row">
                                    <span class="nexafi-row-label"><?php esc_html_e( 'Financing Charge', 'nexafi-plugin' ); ?></span>
                                    <span class="nexafi-row-val nexafi-val-charge">&mdash;</span>
                                </div>
                                <div class="nexafi-breakdown-row nexafi-row-total">
                                    <span class="nexafi-row-label"><?php esc_html_e( 'Total Installment Payments', 'nexafi-plugin' ); ?></span>
                                    <span class="nexafi-row-val nexafi-val-total-repayment">&mdash;</span>
                                </div>
                                <div class="nexafi-breakdown-row">
                                    <span class="nexafi-row-label"><?php esc_html_e( 'Your Monthly Payment', 'nexafi-plugin' ); ?></span>
                                    <span class="nexafi-row-val nexafi-val-monthly-table">&mdash;</span>
                                </div>
                            </div>
                        </div>

                        <!-- Section 4: Financing Disclosures & Acknowledgment -->
                        <div class="nexafi-disclosures-block">
                            <div class="nexafi-disclosures-title"><?php esc_html_e( 'Financing Disclosures', 'nexafi-plugin' ); ?></div>
                            <div class="nexafi-disclosures-text nexafi-val-disclosures">
                                <?php echo esc_html( $disclosure_text ); ?>
                            </div>
                            <div class="nexafi-disclosures-reconciliation nexafi-val-reconciliation" style="display: none;"></div>
                            <?php if ( ! empty( $provider_disclosure ) ) : ?>
                                <div class="nexafi-provider-disclosure-text">
                                    <?php echo esc_html( $provider_disclosure ); ?>
                                </div>
                            <?php endif; ?>
                            <div class="nexafi-disclosure-checkbox-wrap">
                                <label class="nexafi-checkbox-card nexafi-ack-card">
                                    <input type="checkbox" name="accept_financing_terms" class="nexafi-terms-checkbox" value="1" />
                                    <span class="nexafi-checkbox-indicator"></span>
                                    <span class="nexafi-checkbox-label">
                                        <?php esc_html_e( 'I have reviewed the financing terms above.', 'nexafi-plugin' ); ?>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Screen 4 Action: See Your Next Steps → -->
                        <div class="nexafi-form-action">
                            <button type="button" class="nexafi-btn nexafi-btn-primary nexafi-btn-accept" data-target-screen="5" disabled>
                                <span class="nexafi-btn-label"><?php esc_html_e( 'See Your Next Steps', 'nexafi-plugin' ); ?></span>
                                <span class="nexafi-btn-arrow" aria-hidden="true">&rarr;</span>
                            </button>
                        </div>

                    </div>

                    <!-- OUTCOME B: PENDING VERIFICATION / NOT ELIGIBLE CONTAINER -->
                    <div class="nexafi-decision-outcome nexafi-outcome-ineligible" style="display: none;">
                        <div class="nexafi-info-badge-wrap">
                            <div class="nexafi-badge-circle nexafi-badge-blue">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            </div>
                        </div>

                        <div class="nexafi-decision-title-group">
                            <h2 class="nexafi-ineligible-heading"><?php esc_html_e( 'Your Application Has Been Received', 'nexafi-plugin' ); ?></h2>
                            <p class="nexafi-ineligible-reason">
                                <?php esc_html_e( 'Your application has been received. Your GDG Smiles representative will confirm verified financing terms with you before proceeding.', 'nexafi-plugin' ); ?>
                            </p>
                        </div>

                        <div class="nexafi-ineligible-msg-box" style="display: none;">
                            <p class="nexafi-ineligible-detail-text">
                                Unfortunately, you don’t currently meet the requirements for our in-house financing program. Don’t worry! Your GDG Smiles representative can help you explore other available payment options.
                            </p>
                        </div>

                        <!-- Representative Contact Details Box -->
                        <div class="nexafi-rep-contact-box">
                            <div class="nexafi-rep-contact-title">Contact Your Assigned GDG Representative:</div>
                            <div class="nexafi-rep-contact-info">
                                <div class="nexafi-rep-contact-name nexafi-val-rep-name">&mdash;</div>
                                <div class="nexafi-rep-contact-id">ID: <span class="nexafi-val-rep-id">&mdash;</span></div>
                                <div class="nexafi-rep-contact-channels">
                                    <span class="nexafi-rep-phone-line" style="display:none;">📞 <a href="#" class="nexafi-val-rep-phone"></a></span>
                                    <span class="nexafi-rep-email-line" style="display:none;">✉️ <a href="#" class="nexafi-val-rep-email"></a></span>
                                    <span class="nexafi-rep-contact-fallback"></span>
                                </div>
                            </div>
                        </div>

                        <div class="nexafi-form-action" style="display: flex; flex-direction: column; gap: 12px;">
                            <a href="#" class="nexafi-btn nexafi-btn-primary nexafi-btn-contact-rep" style="display: flex; text-decoration: none; justify-content: center; align-items: center;">
                                <span class="nexafi-btn-label">Contact Your Representative</span>
                                <span class="nexafi-btn-arrow" aria-hidden="true">&rarr;</span>
                            </a>
                            <a href="<?php echo esc_url( $return_url ); ?>" class="nexafi-btn nexafi-btn-secondary nexafi-btn-return-home" style="<?php echo empty( $return_url ) ? 'display: none;' : 'display: flex;'; ?> text-decoration: none; justify-content: center; align-items: center;">
                                <span class="nexafi-btn-label">Back to Website</span>
                            </a>
                        </div>

                    </div>

                </div>
            </section>

            <!-- =========================================================================
                 SCREEN 5 — CONFIRMATION
                 ========================================================================= -->
            <section class="nexafi-screen nexafi-screen-5 <?php echo 5 === $start_screen ? 'nexafi-active' : ''; ?>" data-screen="5">
                <div class="nexafi-card nexafi-confirm-card">
                    
                    <!-- Top NexaFi Logo -->
                    <div class="nexafi-topbar nexafi-topbar-center">
                        <div class="nexafi-topbar-logo">
                            <?php echo $logo_dark_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                    </div>

                    <!-- Confirmation Header & Party Icon -->
                    <div class="nexafi-confirm-header">
                        <div class="nexafi-party-icon-wrap">
                            <span class="nexafi-party-emoji">🎉</span>
                        </div>
                        <h2 class="nexafi-confirm-title">You're All Set!</h2>
                        <p class="nexafi-confirm-subtitle">Your financing application has been completed and approved.</p>
                    </div>

                    <!-- Application Reference Card -->
                    <div class="nexafi-reference-card">
                        <div class="nexafi-ref-header-block">
                            <span class="nexafi-ref-label">Application Reference #</span>
                            <span class="nexafi-ref-val nexafi-ref-number">&mdash;</span>
                        </div>
                        <div class="nexafi-ref-grid">
                            <div class="nexafi-ref-col">
                                <span class="nexafi-ref-label">Date</span>
                                <span class="nexafi-ref-val nexafi-ref-date">&mdash;</span>
                            </div>
                            <div class="nexafi-ref-col">
                                <span class="nexafi-ref-label">Time</span>
                                <span class="nexafi-ref-val nexafi-ref-time">&mdash;</span>
                            </div>
                        </div>
                        <div class="nexafi-ref-footer-block">
                            <span class="nexafi-ref-label">Representative</span>
                            <span class="nexafi-ref-val nexafi-ref-rep"><?php echo esc_html( $default_rep_name ? ( $default_rep_name . ( $default_rep_id ? " ({$default_rep_id})" : '' ) ) : '&mdash;' ); ?></span>
                        </div>
                        <div class="nexafi-ref-row nexafi-ref-status-row" style="display: none !important;">
                            <span class="nexafi-ref-label">Status</span>
                            <span class="nexafi-ref-val nexafi-badge-status nexafi-ref-status">Approved</span>
                        </div>
                    </div>

                    <!-- What's Next? Section -->
                    <div class="nexafi-next-steps-section">
                        <h3 class="nexafi-next-title">What's Next?</h3>
                        
                        <!-- Eligible Next Steps -->
                        <ol class="nexafi-steps-list nexafi-steps-eligible">
                            <li class="nexafi-step-item">
                                <span class="nexafi-step-num">1</span>
                                <span class="nexafi-step-desc"><strong><?php esc_html_e( 'Connect With Your GDG Representative', 'nexafi-plugin' ); ?></strong> &mdash; <?php esc_html_e( 'Your GDG Smiles representative will help finalize your financing arrangements and answer any remaining questions.', 'nexafi-plugin' ); ?></span>
                            </li>
                            <li class="nexafi-step-item">
                                <span class="nexafi-step-num">2</span>
                                <span class="nexafi-step-desc"><strong><?php esc_html_e( 'Secure Your Promotional Pricing', 'nexafi-plugin' ); ?></strong> &mdash; <?php esc_html_e( 'Complete your required booking deposit to lock in your promotional pricing for up to 3 months! Your deposit is applied toward your treatment balance.', 'nexafi-plugin' ); ?></span>
                            </li>
                            <li class="nexafi-step-item">
                                <span class="nexafi-step-num">3</span>
                                <span class="nexafi-step-desc"><strong><?php esc_html_e( 'Schedule Your Smile Transformation', 'nexafi-plugin' ); ?></strong> &mdash; <?php esc_html_e( 'Once your booking requirements and payment arrangements are completed, your representative will help reserve your treatment dates.', 'nexafi-plugin' ); ?></span>
                            </li>
                        </ol>

                        <!-- Ineligible / Preliminary Next Steps -->
                        <ol class="nexafi-steps-list nexafi-steps-ineligible" style="display: none;">
                            <li class="nexafi-step-item">
                                <span class="nexafi-step-num">1</span>
                                <span class="nexafi-step-desc">Your application reference has been safely recorded in our system.</span>
                            </li>
                            <li class="nexafi-step-item">
                                <span class="nexafi-step-num">2</span>
                                <span class="nexafi-step-desc">Contact your assigned GDG representative to discuss flexible alternatives.</span>
                            </li>
                            <li class="nexafi-step-item">
                                <span class="nexafi-step-num">3</span>
                                <span class="nexafi-step-desc">Follow the personalized guidance provided by your GDG team.</span>
                            </li>
                        </ol>

                        <!-- Non-promotional booking deposit note -->
                        <div class="nexafi-booking-deposit-note">
                            <?php echo esc_html( $booking_deposit_note ); ?>
                        </div>
                    </div>

                    <!-- Callout Tagline Card -->
                    <div class="nexafi-tagline-box nexafi-tagline-card">
                        <div class="nexafi-tagline-script">A Brighter You<br>Starts Now!</div>
                        <div class="nexafi-tagline-brand">&mdash; GDG SMILES</div>
                    </div>

                    <!-- Return Action Button -->
                    <div class="nexafi-form-action"<?php echo empty( $return_url ) ? ' style="display: none;"' : ''; ?>>
                        <a href="<?php echo esc_url( $return_url ); ?>" class="nexafi-btn nexafi-btn-primary nexafi-btn-return">
                            <span class="nexafi-btn-label"><?php esc_html_e( 'Return to GDG Smiles', 'nexafi-plugin' ); ?></span>
                            <span class="nexafi-btn-arrow" aria-hidden="true">&rarr;</span>
                        </a>
                    </div>

                </div>
            </section>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get US state options
     */
    private function get_us_states_options( $selected = '' ) {
        $states = array(
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
            'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
            'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
            'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
            'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
            'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
            'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
            'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
            'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
            'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
            'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming'
        );

        $out = '';
        foreach ( $states as $code => $name ) {
            $is_sel = ( $code === $selected ) ? ' selected' : '';
            $out .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $code ), $is_sel, esc_html( $name ) );
        }
        return $out;
    }

    /**
     * Get NexaFi SVG Logo in Light or Dark theme
     *
     * @param string $theme 'dark' for dark text on white cards, 'light' for white text on navy screen 1
     * @return string
     */
    private function get_logo_svg( $theme = 'dark' ) {
        $text_color = ( 'light' === $theme ) ? '#ffffff' : '#0b275b';
        $sub_color  = ( 'light' === $theme ) ? '#94a3b8' : '#64748b';

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 130" class="nexafi-svg-logo" aria-label="NexaFi GDG Smiles Logo">' .
            '<text x="200" y="60" font-family="\'Inter\', system-ui, -apple-system, sans-serif" font-size="56" font-weight="800" text-anchor="middle">' .
            '<tspan fill="%s">Nexa</tspan><tspan fill="#1d72f2">Fi</tspan>' .
            '</text>' .
            '<g transform="translate(123, 70) scale(1.4)">' .
            '<path d="M4 3.5C24 13.5 86 13.5 106 3.5" fill="none" stroke="#1d72f2" stroke-linecap="round" stroke-width="4.5"/>' .
            '</g>' .
            '<text x="200" y="115" font-family="\'Inter\', system-ui, -apple-system, sans-serif" font-size="11" font-weight="700" fill="%s" letter-spacing="4" text-anchor="middle">' .
            'IN-HOUSE FINANCING BY GDG SMILES' .
            '</text>' .
            '</svg>',
            esc_attr( $text_color ),
            esc_attr( $sub_color )
        );
    }
}
