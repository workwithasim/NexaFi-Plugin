/**
 * NexaFi Financing Application - Frontend Vanilla JavaScript
 *
 * Implements full 5-screen patient financing workflow:
 * - Multi-screen step navigation with backward state preservation
 * - Client-side validation & live currency input formatting
 * - Dynamic GDG representative AJAX lookup & avatar preview
 * - Duplicate submission prevention (submission tokens + button lock)
 * - Server-side financing calculations integration
 * - Decision outcomes: Approved, Preliminary, and Ineligible
 * - Financing terms acceptance and confirmation reference rendering
 *
 * @package NexaFi_Financing
 */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const apps = document.querySelectorAll('.nexafi-app-wrapper');
    apps.forEach(function (app) {
      initNexaFiApp(app);
    });
  });

  /**
   * Initialize a NexaFi financing application instance
   */
  function initNexaFiApp(wrapper) {
    const config = window.nexafi_obj || {
      ajax_url: '/wp-admin/admin-ajax.php',
      nonce: '',
      default_rep_id: '',
      home_url: '/',
      i18n: {},
    };

    let currentScreen = parseInt(wrapper.getAttribute('data-start-screen') || '2', 10);
    if (isNaN(currentScreen) || currentScreen < 2) {
      currentScreen = 2;
    }
    let applicationData = {};
    let submissionToken = generateSubmissionToken();
    let isSubmitting = false;

    // Cache Screen Elements
    const screens = {
      2: wrapper.querySelector('.nexafi-screen-2'),
      3: wrapper.querySelector('.nexafi-screen-3'),
      4: wrapper.querySelector('.nexafi-screen-4'),
      5: wrapper.querySelector('.nexafi-screen-5'),
    };

    // Show initial screen
    goToScreen(currentScreen);

    // -----------------------------------------------------------------------
    // Top Navigation Back Buttons
    // -----------------------------------------------------------------------
    const backButtons = wrapper.querySelectorAll('.nexafi-nav-back:not(.nexafi-nav-back-home)');
    backButtons.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const target = parseInt(btn.getAttribute('data-target-screen') || '2', 10);
        goToScreen(target);
      });
    });

    // Home-back buttons: navigate to home page (used when form starts directly at Screen 2)
    const homeBackButtons = wrapper.querySelectorAll('.nexafi-nav-back-home');
    homeBackButtons.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const homeUrl = wrapper.getAttribute('data-home-url') || config.home_url || '/';
        window.location.href = homeUrl;
      });
    });

    // -----------------------------------------------------------------------
    // SCREEN 2: Application Form & Validation
    // -----------------------------------------------------------------------
    const formStep2 = wrapper.querySelector('.nexafi-form-step-2');
    const btnContinue2 = wrapper.querySelector('.nexafi-btn-continue');
    const alertBox2 = wrapper.querySelector('.nexafi-screen-2 .nexafi-alert');

    // Employment status requirement handling - keep fields always visible in form
    const empSelect = wrapper.querySelector('.nexafi-emp-toggle');
    const empDepFields = wrapper.querySelector('.nexafi-emp-dependent-fields');
    const empReqMarks = wrapper.querySelectorAll('.nexafi-emp-req');

    if (empSelect) {
      function updateEmploymentFields() {
        const val = empSelect.value ? empSelect.value.toLowerCase().trim() : '';
        const isEmployed = !val || val === 'employed' || val === 'self-employed';
        
        // Ensure Employer Name and Job Title fields are ALWAYS visible in form
        if (empDepFields) {
          empDepFields.style.display = 'block';
        }
        const timeJobField = wrapper.querySelector('.nexafi-time-at-job-field');
        if (timeJobField) {
          timeJobField.style.display = 'block';
        }
        const timeIncomeGrid = wrapper.querySelector('.nexafi-time-at-job-field') ? wrapper.querySelector('.nexafi-time-at-job-field').closest('.nexafi-grid-2') : null;
        if (timeIncomeGrid) {
          timeIncomeGrid.style.gridTemplateColumns = '1fr 1fr';
        }
        empReqMarks.forEach(function (mark) {
          mark.style.display = isEmployed ? 'inline' : 'none';
        });

        // Toggle required attribute based on employment status
        const empInputs = [
          wrapper.querySelector('input[name="employer_name"]'),
          wrapper.querySelector('input[name="job_title"]'),
          wrapper.querySelector('select[name="time_at_job"]')
        ];
        empInputs.forEach(function (inp) {
          if (!inp) return;
          if (val === 'unemployed' || val === 'retired') {
            inp.removeAttribute('required');
            inp.classList.remove('nexafi-input-invalid');
          } else {
            inp.setAttribute('required', 'required');
          }
        });
      }
      empSelect.addEventListener('change', updateEmploymentFields);
      updateEmploymentFields();
    }

    // Real-time error removal when user inputs or changes values
    if (formStep2) {
      const step2Inputs = formStep2.querySelectorAll('input, select');
      step2Inputs.forEach(function (field) {
        const clearFieldError = function () {
          if (field.classList.contains('nexafi-input-invalid')) {
            if (field.value && field.value.trim()) {
              field.classList.remove('nexafi-input-invalid');
            }
          }
        };
        field.addEventListener('input', clearFieldError);
        field.addEventListener('change', clearFieldError);
      });
    }

    // Live currency formatting for Monthly Income
    const incomeInput = wrapper.querySelector('input[name="monthly_income"]');
    if (incomeInput) {
      formatCurrencyInputOnType(incomeInput);
    }

    // Continue to Screen 3
    if (btnContinue2) {
      btnContinue2.addEventListener('click', function (e) {
        e.preventDefault();
        if (validateScreen2()) {
          goToScreen(3);
        }
      });
    }

    function validateScreen2() {
      let isValid = true;
      let firstInvalid = null;
      hideAlert(alertBox2);

      const requiredInputs = formStep2.querySelectorAll('input[required], select[required]');
      requiredInputs.forEach(function (input) {
        input.classList.remove('nexafi-input-invalid');

        // Check if employment dependent
        if (input.name === 'employer_name' || input.name === 'job_title' || input.name === 'time_at_job') {
          const empVal = empSelect ? empSelect.value.toLowerCase().trim() : '';
          if (empVal === 'unemployed' || empVal === 'retired') {
            return; // Not strictly required if unemployed/retired
          }
        }

        if (!input.value.trim()) {
          input.classList.add('nexafi-input-invalid');
          isValid = false;
          if (!firstInvalid) firstInvalid = input;
        } else if (input.type === 'email' && !isValidEmail(input.value.trim())) {
          input.classList.add('nexafi-input-invalid');
          isValid = false;
          if (!firstInvalid) firstInvalid = input;
        }
      });

      if (!isValid) {
        showAlert(alertBox2, 'Please complete all required fields correctly before continuing.');
        if (firstInvalid) {
          firstInvalid.focus();
          firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      }

      return isValid;
    }

    // -----------------------------------------------------------------------
    // SCREEN 3: Treatment Quote & GDG Representative Lookup
    // -----------------------------------------------------------------------
    const formStep3 = wrapper.querySelector('.nexafi-form-step-3');
    const quoteInput = wrapper.querySelector('input[name="treatment_quote"]');
    const repInput = wrapper.querySelector('input[name="rep_id"]');
    const repCard = wrapper.querySelector('.nexafi-rep-card');
    const repSpinner = wrapper.querySelector('.nexafi-rep-spinner');
    const repStatusText = wrapper.querySelector('.nexafi-rep-status-text');
    const alertBox3 = wrapper.querySelector('.nexafi-screen-3 .nexafi-alert');
    const consentCheckbox = wrapper.querySelector('input[name="consent"]');
    const btnSubmit = wrapper.querySelector('.nexafi-btn-submit');

    // Live currency formatting for Treatment Quote
    if (quoteInput) {
      formatCurrencyInputOnType(quoteInput);
    }

    // Debounced GDG Representative Lookup
    let repLookupTimeout = null;
    let isRepValid = false;

    if (repInput) {
      repInput.placeholder = 'GDG ID';
      repInput.value = '';
      hideRepPreview();
      if (repStatusText) repStatusText.textContent = '';
      repInput.classList.remove('nexafi-input-invalid');

      repInput.addEventListener('input', function () {
        clearTimeout(repLookupTimeout);
        const val = repInput.value.trim();
        if (!val) {
          hideRepPreview();
          isRepValid = false;
          if (repStatusText) repStatusText.textContent = '';
          repInput.classList.remove('nexafi-input-invalid');
          return;
        }
        repLookupTimeout = setTimeout(function () {
          performRepLookup(val);
        }, 350);
      });
    }

    function performRepLookup(repCode) {
      if (repSpinner) repSpinner.style.display = 'block';
      if (repStatusText) repStatusText.textContent = '';
      repInput.classList.remove('nexafi-input-invalid');

      const formData = new FormData();
      formData.append('action', 'nexafi_lookup_rep');
      formData.append('security', config.nonce);
      formData.append('rep_id', repCode);

      fetch(config.ajax_url, {
        method: 'POST',
        body: formData,
      })
        .then(function (res) { return res.json(); })
        .then(function (res) {
          if (repSpinner) repSpinner.style.display = 'none';
          if (res.success && res.data) {
            isRepValid = true;
            showRepPreview(res.data);
          } else {
            isRepValid = false;
            hideRepPreview();
            repInput.classList.add('nexafi-input-invalid');
            if (repStatusText) {
              repStatusText.textContent = res.data && res.data.message ? res.data.message : 'Representative ID not found or inactive.';
            }
          }
        })
        .catch(function () {
          if (repSpinner) repSpinner.style.display = 'none';
          isRepValid = false;
          hideRepPreview();
        });
    }

    function showRepPreview(data) {
      if (!repCard) return;
      const nameEl = repCard.querySelector('.nexafi-rep-name');
      const codeEl = repCard.querySelector('.nexafi-rep-code');
      const imgEl = repCard.querySelector('.nexafi-rep-avatar');

      if (nameEl) nameEl.textContent = data.name;
      if (codeEl) codeEl.textContent = data.rep_id;
      if (imgEl && data.photo_url) imgEl.src = data.photo_url;

      repCard.style.display = 'flex';
      repCard.style.opacity = '1';

      const calloutEl = wrapper.querySelector('.nexafi-info-callout');
      if (calloutEl) {
        calloutEl.style.display = 'flex';
      }
    }

    function hideRepPreview() {
      if (repCard) {
        repCard.style.display = 'none';
      }
      const calloutEl = wrapper.querySelector('.nexafi-info-callout');
      if (calloutEl) {
        calloutEl.style.display = 'none';
      }
    }

    // Submit Application on Screen 3
    if (formStep3) {
      formStep3.addEventListener('submit', function (e) {
        e.preventDefault();
        if (isSubmitting) return;

        hideAlert(alertBox3);

        // 1. Validate Quote
        const rawQuote = quoteInput ? quoteInput.value.replace(/[^\d.]/g, '') : '';
        const numQuote = parseFloat(rawQuote);
        if (isNaN(numQuote) || numQuote <= 0) {
          showAlert(alertBox3, 'Please enter a valid treatment quote greater than $0.');
          if (quoteInput) {
            quoteInput.classList.add('nexafi-input-invalid');
            quoteInput.focus();
          }
          return;
        }

        const maxQuote = parseFloat(config.max_quote || 50000);
        if (maxQuote > 0 && numQuote > maxQuote) {
          showAlert(alertBox3, 'Treatment quote cannot exceed $' + maxQuote.toLocaleString('en-US') + '.');
          if (quoteInput) {
            quoteInput.classList.add('nexafi-input-invalid');
            quoteInput.focus();
          }
          return;
        }

        // 2. Validate Representative (Strictly Required & Must Be Valid)
        const submittedRep = repInput ? repInput.value.trim() : '';
        if (!submittedRep) {
          showAlert(alertBox3, 'Representative ID is required. Please enter your GDG Representative ID.');
          if (repInput) {
            repInput.classList.add('nexafi-input-invalid');
            repInput.focus();
          }
          if (repStatusText) {
            repStatusText.textContent = 'Representative ID is required.';
          }
          return;
        }

        if (!isRepValid) {
          showAlert(alertBox3, 'Please enter a valid GDG Representative ID before submitting.');
          if (repInput) {
            repInput.classList.add('nexafi-input-invalid');
            repInput.focus();
          }
          if (repStatusText) {
            repStatusText.textContent = 'Representative ID not found. Please verify with your GDG representative.';
          }
          return;
        }

        // 3. Validate Consent
        if (consentCheckbox && !consentCheckbox.checked) {
          showAlert(alertBox3, 'You must authorize information review to submit your application.');
          consentCheckbox.focus();
          return;
        }

        // Assemble Full Application Payload with fresh token
        const freshToken = generateSubmissionToken();
        const formData = new FormData(formStep2);
        formData.append('action', 'nexafi_submit_application');
        formData.append('security', config.nonce);
        formData.append('treatment_quote', numQuote.toString());
        formData.append('rep_id', submittedRep);
        formData.append('consent', (consentCheckbox && consentCheckbox.checked) ? '1' : '0');

        const hasIdInput = formStep2.querySelector('input[name="has_id_declaration"]');
        const hasDebitInput = formStep2.querySelector('input[name="has_debit_card_declaration"]');
        formData.set('has_id_declaration', (hasIdInput && hasIdInput.checked) ? '1' : '0');
        formData.set('has_debit_card_declaration', (hasDebitInput && hasDebitInput.checked) ? '1' : '0');
        formData.append('submission_token', freshToken);

        // Lock button & show spinner
        setSubmitLoading(true);
        isSubmitting = true;

        fetch(config.ajax_url, {
          method: 'POST',
          body: formData,
        })
          .then(function (res) { return res.json(); })
          .then(function (res) {
            setSubmitLoading(false);
            isSubmitting = false;

            if (res.success && res.data) {
              applicationData = res.data;
              populateDecisionScreen(res.data);
              populateConfirmationScreen(res.data);
              goToScreen(4);
            } else {
              let errMsg = res.data && res.data.message ? res.data.message : 'An error occurred processing your application.';
              if (res.data && res.data.errors) {
                const errList = [];
                for (const field in res.data.errors) {
                  errList.push(res.data.errors[field]);
                }
                if (errList.length > 0) {
                  errMsg = errList.join(' ');
                }
                if (res.data.errors.treatment_quote && quoteInput) {
                  quoteInput.classList.add('nexafi-input-invalid');
                  quoteInput.focus();
                }
                if (res.data.errors.rep_id && repInput) {
                  repInput.classList.add('nexafi-input-invalid');
                }
              }
              showAlert(alertBox3, errMsg);
            }
          })
          .catch(function () {
            setSubmitLoading(false);
            isSubmitting = false;
            showAlert(alertBox3, 'Network communication error. Please check your internet connection.');
          });
      });
    }

    function setSubmitLoading(loading) {
      if (!btnSubmit) return;
      const label = btnSubmit.querySelector('.nexafi-btn-label');
      const arrow = btnSubmit.querySelector('.nexafi-btn-arrow') || btnSubmit.querySelector('.nexafi-icon-arrow');
      const spinner = btnSubmit.querySelector('.nexafi-spinner');

      btnSubmit.disabled = loading;
      if (loading) {
        if (label) label.textContent = 'Processing Application...';
        if (arrow) arrow.style.display = 'none';
        if (spinner) spinner.style.display = 'inline-block';
      } else {
        if (label) label.textContent = 'Submit Application';
        if (arrow) arrow.style.display = 'inline-block';
        if (spinner) spinner.style.display = 'none';
      }
    }

    // -----------------------------------------------------------------------
    // SCREEN 4: Decision Rendering & Review & Accept Action
    // -----------------------------------------------------------------------
    function populateDecisionScreen(data) {
      const screen4 = screens[4];
      if (!screen4) return;

      const outcomeApproved = screen4.querySelector('.nexafi-outcome-approved');
      const outcomePrelim = screen4.querySelector('.nexafi-outcome-preliminary');
      const outcomeIneligible = screen4.querySelector('.nexafi-outcome-ineligible');

      // Hide all outcomes initially
      if (outcomeApproved) outcomeApproved.style.display = 'none';
      if (outcomePrelim) outcomePrelim.style.display = 'none';
      if (outcomeIneligible) outcomeIneligible.style.display = 'none';

      const calc = (data && data.calculation) ? data.calculation : {};
      const rep = (data && data.representative) ? data.representative : {};
      const result = (data && data.eligibility_result ? data.eligibility_result : 'approved').toString().toLowerCase();

      // Read user entered quote from input if not supplied in response calculation
      const rawQuoteInput = quoteInput ? quoteInput.value.replace(/[^\d.]/g, '') : '';
      const userQuote = parseFloat(rawQuoteInput) || 10000;

      // Financing terms (70% upfront, 30% financed, 15% charge, 12 months)
      const upfrontPct = (calc.upfront_pct !== undefined && calc.upfront_pct !== null) ? parseFloat(calc.upfront_pct) : 70;
      const financedPct = (calc.financed_pct !== undefined && calc.financed_pct !== null) ? parseFloat(calc.financed_pct) : 30;
      const chargePct = (calc.charge_pct !== undefined && calc.charge_pct !== null) ? parseFloat(calc.charge_pct) : 15;
      const term = (calc.repayment_term !== undefined && calc.repayment_term !== null) ? parseInt(calc.repayment_term, 10) : 12;

      const qAmt = (calc.treatment_quote !== undefined && calc.treatment_quote !== null) ? parseFloat(calc.treatment_quote) : userQuote;
      const upAmt = (calc.upfront_amount !== undefined && calc.upfront_amount !== null) ? parseFloat(calc.upfront_amount) : Math.round(qAmt * (upfrontPct / 100));
      const finAmt = (calc.financed_amount !== undefined && calc.financed_amount !== null) ? parseFloat(calc.financed_amount) : Math.round(qAmt * (financedPct / 100));
      const chgAmt = (calc.charge_amount !== undefined && calc.charge_amount !== null) ? parseFloat(calc.charge_amount) : Math.round(finAmt * (chargePct / 100));
      const totRepay = (calc.total_repayment !== undefined && calc.total_repayment !== null) ? parseFloat(calc.total_repayment) : (finAmt + chgAmt);
      const monAmt = (calc.monthly_payment !== undefined && calc.monthly_payment !== null) ? parseFloat(calc.monthly_payment) : Math.round((totRepay / term) * 100) / 100;

      // Currency strings formatted cleanly without unnecessary decimal zeros
      const fmtQuote = calc.formatted_quote || ('$' + Math.round(qAmt).toLocaleString('en-US'));
      const fmtUpfront = calc.formatted_upfront || ('$' + Math.round(upAmt).toLocaleString('en-US'));
      const fmtFinanced = calc.formatted_financed || ('$' + Math.round(finAmt).toLocaleString('en-US'));
      const fmtCharge = calc.formatted_charge || ('$' + Math.round(chgAmt).toLocaleString('en-US'));
      const fmtTotal = calc.formatted_repayment || ('$' + Math.round(totRepay).toLocaleString('en-US'));
      const fmtMonthly = calc.formatted_monthly || ('$' + monAmt.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

      if (result !== 'ineligible') {
        if (outcomeApproved) {
          outcomeApproved.style.display = 'block';
          if (outcomePrelim) outcomePrelim.style.display = 'none';
          if (outcomeIneligible) outcomeIneligible.style.display = 'none';

          // Populate dynamic figures calculated from requirement
          setValText(outcomeApproved, '.nexafi-val-financed', fmtFinanced);
          setValText(outcomeApproved, '.nexafi-val-quote', fmtQuote);
          setValText(outcomeApproved, '.nexafi-val-upfront-pct', upfrontPct + '%');
          setValText(outcomeApproved, '.nexafi-val-upfront', fmtUpfront);
          setValText(outcomeApproved, '.nexafi-val-financed-pct', financedPct + '%');
          setValText(outcomeApproved, '.nexafi-val-financed-sub', fmtFinanced);
          setValText(outcomeApproved, '.nexafi-val-charge-pct', chargePct + '%');
          setValText(outcomeApproved, '.nexafi-val-charge', fmtCharge);
          setValText(outcomeApproved, '.nexafi-val-term', term.toString());
          setValText(outcomeApproved, '.nexafi-val-total-repayment', fmtTotal);
          setValText(outcomeApproved, '.nexafi-val-monthly', fmtMonthly);
          setValText(outcomeApproved, '.nexafi-val-term-sub', term.toString());
          setValText(outcomeApproved, '.nexafi-val-charge-sub', chargePct + '%');
        } else if (outcomePrelim) {
          outcomePrelim.style.display = 'block';
          setValText(outcomePrelim, '.nexafi-val-quote', fmtQuote);
          setValText(outcomePrelim, '.nexafi-val-upfront-pct', upfrontPct + '%');
          setValText(outcomePrelim, '.nexafi-val-upfront', fmtUpfront);
          setValText(outcomePrelim, '.nexafi-val-financed-pct', financedPct + '%');
          setValText(outcomePrelim, '.nexafi-val-financed-sub', fmtFinanced);
          setValText(outcomePrelim, '.nexafi-val-monthly', fmtMonthly);
          setValText(outcomePrelim, '.nexafi-val-term', term.toString());
        }
      } else {
        // Outcome B: Ineligible under declaration rule
        if (outcomeIneligible) {
          outcomeIneligible.style.display = 'block';
          if (outcomeApproved) outcomeApproved.style.display = 'none';
          if (outcomePrelim) outcomePrelim.style.display = 'none';

          const chosenRepName = (rep && rep.name) ? rep.name : (submittedRep ? ('Representative ' + submittedRep) : (config.default_rep_name || 'Caleb Johnson'));
          const chosenRepId = (rep && rep.rep_id) ? rep.rep_id : (submittedRep ? submittedRep : (config.default_rep_id || 'GDG1027'));

          setValText(outcomeIneligible, '.nexafi-val-rep-name', chosenRepName);
          setValText(outcomeIneligible, '.nexafi-val-rep-id', chosenRepId);

          const repCodeEl = outcomeIneligible.querySelector('.nexafi-rep-contact-id');
          if (repCodeEl) {
            repCodeEl.innerHTML = 'ID: <span class="nexafi-val-rep-id">' + chosenRepId + '</span>';
          }

          const repAvatar = outcomeIneligible.querySelector('.nexafi-val-rep-avatar');
          if (repAvatar && rep && rep.photo_url) {
            repAvatar.src = rep.photo_url;
          }

          const ineligHeadline = (data && data.headline) ? data.headline : 'We’re Unable to Offer In-House Financing Right Now';
          setValText(outcomeIneligible, '.nexafi-ineligible-heading', ineligHeadline);

          const defaultIneligReason = 'Unfortunately, you don’t currently meet the requirements for our in-house financing program. Don’t worry! Your GDG Smiles representative can help you explore other available payment options.';
          const cleanReason = (data && data.reason) ? data.reason : defaultIneligReason;
          setValText(outcomeIneligible, '.nexafi-ineligible-reason', cleanReason);

          if (data && data.message) {
            setValText(outcomeIneligible, '.nexafi-ineligible-detail-text', data.message);
          }

          const phoneLine = outcomeIneligible.querySelector('.nexafi-rep-phone-line');
          const phoneLink = outcomeIneligible.querySelector('.nexafi-val-rep-phone');
          const emailLine = outcomeIneligible.querySelector('.nexafi-rep-email-line');
          const emailLink = outcomeIneligible.querySelector('.nexafi-val-rep-email');
          const fallback = outcomeIneligible.querySelector('.nexafi-rep-contact-fallback');
          const contactBtn = outcomeIneligible.querySelector('.nexafi-btn-contact-rep');

          if (rep && rep.phone) {
            if (phoneLine) phoneLine.style.display = 'inline-block';
            if (phoneLink) {
              phoneLink.textContent = rep.phone;
              phoneLink.href = 'tel:' + rep.phone.replace(/[^\d+]/g, '');
            }
            if (contactBtn) contactBtn.href = 'tel:' + rep.phone.replace(/[^\d+]/g, '');
          } else if (rep && rep.email) {
            if (emailLine) emailLine.style.display = 'inline-block';
            if (emailLink) {
              emailLink.textContent = rep.email;
              emailLink.href = 'mailto:' + rep.email;
            }
            if (contactBtn) contactBtn.href = 'mailto:' + rep.email;
          } else {
            if (fallback) fallback.textContent = data.fallback_contact_text || 'Please speak with your representative directly.';
            if (contactBtn) contactBtn.href = '#';
          }

          const returnBtn = outcomeIneligible.querySelector('.nexafi-btn-return-home');
          if (returnBtn && (config.return_url || config.home_url)) {
            returnBtn.href = config.return_url || config.home_url;
          }
        }
      }
    }

    // Action button on Screen 4: Proceed to Screen 5
    const btnAccept = wrapper.querySelectorAll('.nexafi-btn-accept, .nexafi-decision-outcome button[data-target-screen="5"]');
    btnAccept.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        // Record terms acceptance asynchronously if approved
        if (applicationData && applicationData.app_id && applicationData.eligibility_result === 'approved') {
          const termsBox = wrapper.querySelector('.nexafi-terms-checkbox');
          if (termsBox && termsBox.checked) {
            sendTermsAcceptance(applicationData.app_id, applicationData.reference_number);
          }
        }
        goToScreen(5);
      });
    });

    function sendTermsAcceptance(appId, refNum) {
      const formData = new FormData();
      formData.append('action', 'nexafi_accept_terms');
      formData.append('security', config.nonce);
      formData.append('app_id', appId);
      formData.append('reference', refNum);

      fetch(config.ajax_url, {
        method: 'POST',
        body: formData,
      });
    }

    // -----------------------------------------------------------------------
    // SCREEN 5: Confirmation Rendering
    // -----------------------------------------------------------------------
    function populateConfirmationScreen(data) {
      const screen5 = screens[5];
      if (!screen5) return;

      const titleEl = screen5.querySelector('.nexafi-confirm-title');
      const subtitleEl = screen5.querySelector('.nexafi-confirm-subtitle');
      const refNumEl = screen5.querySelector('.nexafi-ref-number');
      const dateEl = screen5.querySelector('.nexafi-ref-date');
      const timeEl = screen5.querySelector('.nexafi-ref-time');
      const repEl = screen5.querySelector('.nexafi-ref-rep');
      const statusEl = screen5.querySelector('.nexafi-ref-status');

      const stepsEligible = screen5.querySelector('.nexafi-steps-eligible');
      const stepsIneligible = screen5.querySelector('.nexafi-steps-ineligible');

      if (refNumEl) refNumEl.textContent = data.reference_number || 'NEXA-20250826-1047';
      if (dateEl) dateEl.textContent = data.submission_date || 'Today';
      if (timeEl) timeEl.textContent = data.submission_time || '';

      const repName = (data.representative && data.representative.name) ? data.representative.name : (config.default_rep_name || 'kalijo');
      const repCode = (data.representative && data.representative.rep_id) ? data.representative.rep_id : (config.default_rep_id || 'GDG1122');
      if (repEl) repEl.textContent = repName + (repCode ? ' (' + repCode + ')' : '');

      if (data.eligibility_result === 'approved') {
        if (titleEl) titleEl.textContent = "You're All Set!";
        if (subtitleEl) subtitleEl.textContent = 'Your financing application has been completed and approved.';
        if (statusEl) {
          statusEl.textContent = 'Approved';
          statusEl.className = 'nexafi-badge-status nexafi-ref-status';
        }
        if (stepsEligible) stepsEligible.style.display = 'flex';
        if (stepsIneligible) stepsIneligible.style.display = 'none';
      } else {
        if (titleEl) titleEl.textContent = 'Application Received';
        if (subtitleEl) subtitleEl.textContent = 'Your application has been received and recorded.';
        if (statusEl) {
          statusEl.textContent = 'Under Review';
          statusEl.className = 'nexafi-badge-status nexafi-ref-status';
          statusEl.style.background = '#fef3c7';
          statusEl.style.color = '#92400e';
        }
        if (stepsEligible) stepsEligible.style.display = 'none';
        if (stepsIneligible) stepsIneligible.style.display = 'flex';
      }
    }

    // -----------------------------------------------------------------------
    // Core Screen Navigation Helper
    // -----------------------------------------------------------------------
    function goToScreen(screenNumber) {
      if (!screens[screenNumber]) return;

      Object.keys(screens).forEach(function (num) {
        if (screens[num]) {
          screens[num].classList.remove('nexafi-active');
        }
      });

      screens[screenNumber].classList.add('nexafi-active');
      currentScreen = screenNumber;

      // Smooth scroll to top of card
      wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // -----------------------------------------------------------------------
    // Utility Helpers
    // -----------------------------------------------------------------------
    function setValText(container, selector, text) {
      const el = container.querySelector(selector);
      if (el) el.textContent = text || '';
    }

    function showAlert(box, message) {
      if (!box) return;
      const msgEl = box.querySelector('.nexafi-alert-message');
      if (msgEl) msgEl.textContent = message;
      box.style.display = 'flex';
    }

    function hideAlert(box) {
      if (!box) return;
      box.style.display = 'none';
    }

    function isValidEmail(email) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function formatCurrencyInputOnType(input) {
      input.addEventListener('input', function () {
        let val = input.value.replace(/[^\d]/g, '');
        if (val) {
          input.value = parseInt(val, 10).toLocaleString('en-US');
        } else {
          input.value = '';
        }
      });
    }

    function generateSubmissionToken() {
      return 'tok_' + Math.random().toString(36).substring(2, 15) + '_' + Date.now();
    }
  }
})();
