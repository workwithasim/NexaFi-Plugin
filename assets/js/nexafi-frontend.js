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
    const config = Object.assign({
      ajax_url: '/wp-admin/admin-ajax.php',
      nonce: '',
      default_rep_id: '',
      min_quote: 0,
      max_quote: 50000,
      home_url: '/',
      return_url: '',
      privacy_url: '',
      i18n: {},
    }, window.nexafi_obj || {});

    // Sync config with wrapper data attributes
    const wrapperReturnUrl = wrapper.getAttribute('data-return-url');
    if (wrapperReturnUrl !== null && wrapperReturnUrl !== '') {
      config.return_url = wrapperReturnUrl;
    }
    const wrapperPrivacyUrl = wrapper.getAttribute('data-privacy-url');
    if (wrapperPrivacyUrl !== null && wrapperPrivacyUrl !== '') {
      config.privacy_url = wrapperPrivacyUrl;
    }

    // Cached page nonce refresh
    if (!config.nonce && config.ajax_url) {
      fetch(config.ajax_url + '?action=nexafi_get_nonce')
        .then(function (res) { return res.json(); })
        .then(function (res) {
          if (res.success && res.data && res.data.nonce) {
            config.nonce = res.data.nonce;
          }
        })
        .catch(function () {});
    }

    let currentScreen = parseInt(wrapper.getAttribute('data-start-screen') || '2', 10);
    if (isNaN(currentScreen) || currentScreen < 2) {
      currentScreen = 2;
    }
    let applicationData = null;
    let submissionToken = generateSubmissionToken();
    let isSubmitting = false;
    let submittedRep = '';

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
    // Form Progress Persistence (sessionStorage - §1.5)
    // -----------------------------------------------------------------------
    const STORAGE_KEY = 'nexafi_form_progress_' + (wrapper.id || 'default');

    function saveFormProgress() {
      try {
        const formData = {};
        const allInputs = wrapper.querySelectorAll('input, select, textarea');
        allInputs.forEach(function (inp) {
          if (!inp.name || inp.type === 'hidden' || inp.name === 'nexafi_hp' || inp.name === 'submission_token') return;
          if (inp.type === 'checkbox') {
            formData[inp.name] = inp.checked ? 1 : 0;
          } else {
            formData[inp.name] = inp.value;
          }
        });
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(formData));
      } catch (err) {}
    }

    function restoreFormProgress() {
      try {
        const raw = sessionStorage.getItem(STORAGE_KEY);
        if (!raw) return;
        const saved = JSON.parse(raw);
        if (!saved || typeof saved !== 'object') return;
        for (const name in saved) {
          const inp = wrapper.querySelector('[name="' + CSS.escape(name) + '"]');
          if (!inp) continue;
          if (inp.type === 'checkbox') {
            inp.checked = (saved[name] === 1 || saved[name] === true || saved[name] === '1');
          } else {
            if (saved[name] !== undefined && saved[name] !== null && saved[name] !== '') {
              inp.value = saved[name];
            }
          }
        }
        // Trigger Rep ID lookup if restored from session (§1.5)
        const restoredRepInput = wrapper.querySelector('input[name="rep_id"]');
        if (restoredRepInput && restoredRepInput.value && restoredRepInput.value.trim()) {
          if (typeof performRepLookup === 'function') {
            performRepLookup(restoredRepInput.value.trim());
          }
        }
      } catch (err) {}
    }

    function clearFormProgress() {
      try {
        sessionStorage.removeItem(STORAGE_KEY);
      } catch (err) {}
    }

    restoreFormProgress();
    wrapper.addEventListener('input', saveFormProgress);
    wrapper.addEventListener('change', saveFormProgress);

    // Clear progress on pagehide after submission (§1.5)
    window.addEventListener('pagehide', function () {
      if (applicationData && applicationData.app_id) {
        clearFormProgress();
      }
    });

    // Date of Birth: enforce max date = today minus 18 years
    const dobInputField = wrapper.querySelector('input[name="dob"]');
    if (dobInputField) {
      const todayDate = new Date();
      const maxYear = todayDate.getFullYear() - 18;
      const mm = String(todayDate.getMonth() + 1).padStart(2, '0');
      const dd = String(todayDate.getDate()).padStart(2, '0');
      dobInputField.max = `${maxYear}-${mm}-${dd}`;

      const clearDobError = function () {
        const dobErr = wrapper.querySelector('.nexafi-dob-error');
        if (dobErr) dobErr.style.display = 'none';
        dobInputField.classList.remove('nexafi-input-invalid');
      };
      dobInputField.addEventListener('input', clearDobError);
      dobInputField.addEventListener('change', clearDobError);
    }

    // -----------------------------------------------------------------------
    // Phone Auto-Formatting (XXX) XXX-XXXX (§1.2)
    // -----------------------------------------------------------------------
    const phoneInput = wrapper.querySelector('input[name="phone"]');
    if (phoneInput) {
      function formatPhoneValue(val) {
        let digits = val.replace(/\D/g, '');
        if (digits.length === 11 && digits.charAt(0) === '1') {
          digits = digits.substring(1);
        }
        digits = digits.substring(0, 10);
        if (digits.length === 0) return '';
        if (digits.length <= 3) return '(' + digits;
        if (digits.length <= 6) return '(' + digits.substring(0, 3) + ') ' + digits.substring(3);
        return '(' + digits.substring(0, 3) + ') ' + digits.substring(3, 6) + '-' + digits.substring(6, 10);
      }

      phoneInput.addEventListener('input', function () {
        phoneInput.value = formatPhoneValue(phoneInput.value);
      });

      phoneInput.addEventListener('paste', function () {
        setTimeout(function () {
          phoneInput.value = formatPhoneValue(phoneInput.value);
        }, 10);
      });

      if (phoneInput.value) {
        phoneInput.value = formatPhoneValue(phoneInput.value);
      }
    }

    // -----------------------------------------------------------------------
    // ZIP Code Digit-Only Live Filter (§1.3)
    // -----------------------------------------------------------------------
    const zipCodeInput = wrapper.querySelector('input[name="zip_code"]');
    if (zipCodeInput) {
      function sanitizeZip(val) {
        let digits = val.replace(/[^\d-]/g, '');
        let parts = digits.split('-');
        if (parts.length > 2) {
          digits = parts[0] + '-' + parts.slice(1).join('');
        }
        if (digits.length > 10) {
          digits = digits.substring(0, 10);
        }
        return digits;
      }

      zipCodeInput.addEventListener('input', function () {
        zipCodeInput.value = sanitizeZip(zipCodeInput.value);
      });

      zipCodeInput.addEventListener('paste', function () {
        setTimeout(function () {
          zipCodeInput.value = sanitizeZip(zipCodeInput.value);
        }, 10);
      });
    }

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

    // Home-back buttons: navigate to return URL or home page (used when form starts directly at Screen 2)
    const homeBackButtons = wrapper.querySelectorAll('.nexafi-nav-back-home');
    homeBackButtons.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const returnUrl = wrapper.getAttribute('data-return-url') || config.return_url || wrapper.getAttribute('data-home-url') || config.home_url || '/';
        window.location.href = returnUrl;
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

      // Validate Date of Birth (at least 18 years old, not future, not older than 120)
      const dobInput = formStep2.querySelector('input[name="dob"]');
      const dobError = formStep2.querySelector('.nexafi-dob-error');
      if (dobError) dobError.style.display = 'none';

      if (dobInput && !dobInput.disabled) {
        const dobVal = dobInput.value.trim();
        if (!dobVal) {
          dobInput.classList.add('nexafi-input-invalid');
          isValid = false;
          if (!firstInvalid) firstInvalid = dobInput;
        } else {
          const dobDate = new Date(dobVal + 'T00:00:00');
          const today = new Date();
          today.setHours(0, 0, 0, 0);

          let age = today.getFullYear() - dobDate.getFullYear();
          const m = today.getMonth() - dobDate.getMonth();
          if (m < 0 || (m === 0 && today.getDate() < dobDate.getDate())) {
            age--;
          }

          if (isNaN(dobDate.getTime()) || dobDate > today || age < 18 || age > 120) {
            dobInput.classList.add('nexafi-input-invalid');
            if (dobError) {
              dobError.textContent = 'You must be at least 18 years old to apply.';
              dobError.style.display = 'block';
            }
            isValid = false;
            if (!firstInvalid) firstInvalid = dobInput;
          }
        }
      }

      // Validate Phone 10 digits
      if (phoneInput && !phoneInput.disabled) {
        let pDigits = phoneInput.value.replace(/\D/g, '');
        if (pDigits.length === 11 && pDigits.charAt(0) === '1') {
          pDigits = pDigits.substring(1);
        }
        if (pDigits.length !== 10) {
          phoneInput.classList.add('nexafi-input-invalid');
          isValid = false;
          if (!firstInvalid) firstInvalid = phoneInput;
        }
      }

      // Validate ZIP Code (5 digits or 5+4)
      if (zipCodeInput && !zipCodeInput.disabled) {
        const cleanZip = zipCodeInput.value.trim();
        const zipRegex = /^\d{5}(-\d{4})?$/;
        if (!zipRegex.test(cleanZip)) {
          zipCodeInput.classList.add('nexafi-input-invalid');
          isValid = false;
          if (!firstInvalid) firstInvalid = zipCodeInput;
        }
      }

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

    // Privacy policy link visibility on Screen 3
    const privacyWrap = wrapper.querySelector('.nexafi-privacy-link-wrap');
    const privacyLink = wrapper.querySelector('.nexafi-privacy-link');
    const currentPrivacyUrl = config.privacy_url || wrapper.getAttribute('data-privacy-url') || '';
    if (privacyWrap) {
      if (currentPrivacyUrl) {
        if (privacyLink) privacyLink.href = currentPrivacyUrl;
        privacyWrap.style.display = '';
      } else {
        privacyWrap.style.display = 'none';
      }
    }

    // Live currency formatting for Treatment Quote
    if (quoteInput) {
      formatCurrencyInputOnType(quoteInput);
    }

    // Debounced GDG Representative Lookup
    let repLookupTimeout = null;
    let isRepValid = false;

    if (repInput) {
      repInput.placeholder = 'GDG ID';
      if (repStatusText) repStatusText.textContent = '';
      repInput.classList.remove('nexafi-input-invalid');

      // Preserve restored Rep ID (§1.5)
      if (repInput.value && repInput.value.trim()) {
        performRepLookup(repInput.value.trim());
      } else {
        hideRepPreview();
      }

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
              repStatusText.textContent = res.data && res.data.message ? res.data.message : 'Representative ID not found.';
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

        // Check if application was already submitted in this session (§1.5 duplicate guard)
        if (applicationData && applicationData.app_id) {
          showAlert(alertBox3, 'You have already submitted an application (Reference: ' + (applicationData.reference_number || '') + '). Proceeding to review your financing terms.');
          goToScreen(4);
          return;
        }

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

        const minQuote = parseFloat(config.min_quote || 0);
        if (minQuote > 0 && numQuote < minQuote) {
          showAlert(alertBox3, 'Treatment quote must be at least $' + minQuote.toLocaleString('en-US') + '.');
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
        submittedRep = repInput ? repInput.value.trim() : '';
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

        // Assemble Full Application Payload reusing single session token (§1.5)
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
        formData.append('submission_token', submissionToken);

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
              clearFormProgress();
              if (btnSubmit) btnSubmit.disabled = true;
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
    // SCREEN 4: Decision Rendering & Fail-Closed Logic (§2c, §3.4, §4)
    // -----------------------------------------------------------------------
    function populateDecisionScreen(data) {
      const screen4 = screens[4];
      if (!screen4) return;

      const outcomeApproved = screen4.querySelector('.nexafi-outcome-approved');
      const outcomeIneligible = screen4.querySelector('.nexafi-outcome-ineligible');

      // Hide all outcomes initially
      if (outcomeApproved) outcomeApproved.style.display = 'none';
      if (outcomeIneligible) outcomeIneligible.style.display = 'none';

      const calc = (data && data.calculation) ? data.calculation : {};
      const rep = (data && data.representative) ? data.representative : {};
      const result = (data && data.eligibility_result) ? data.eligibility_result.toString().toLowerCase() : '';
      const termsVerified = (data && data.terms_verified === true);

      // §2c Fail-Closed: Show approved view ONLY when result === 'approved' AND terms_verified === true
      if (result === 'approved' && termsVerified) {
        if (outcomeApproved) {
          outcomeApproved.style.display = 'block';

          // Visual Priority: 1. Approved Financing Amount, 2. Required Down Payment, 3. Your Monthly Payment
          setValText(outcomeApproved, '.nexafi-val-financed', calc.formatted_financed || '—');
          setValText(outcomeApproved, '.nexafi-val-down-payment', calc.formatted_upfront || '—');
          setValText(outcomeApproved, '.nexafi-val-monthly', calc.formatted_monthly || '—');

          // Deposit breakdown if booking deposit is configured (§5)
          const depositBreakdown = outcomeApproved.querySelector('.nexafi-deposit-credit-breakdown');
          if (depositBreakdown) {
            if (calc.booking_deposit && parseFloat(calc.booking_deposit) > 0) {
              depositBreakdown.style.display = 'block';
              setValText(depositBreakdown, '.nexafi-val-deposit-credited', calc.formatted_booking_deposit || '—');
              setValText(depositBreakdown, '.nexafi-val-deposit-remaining', calc.formatted_remaining_down_payment || calc.formatted_remaining_down || '—');
            } else {
              depositBreakdown.style.display = 'none';
            }
          }

          // Plan Details List: Total Treatment Price, Required Down Payment, Amount Financed, Financing Charge, Total Installment Payments, Your Monthly Payment (§2a)
          setValText(outcomeApproved, '.nexafi-val-quote', calc.formatted_quote || '—');
          setValText(outcomeApproved, '.nexafi-val-down-payment-table', calc.formatted_upfront || '—');
          setValText(outcomeApproved, '.nexafi-val-upfront', calc.formatted_upfront || '—');
          setValText(outcomeApproved, '.nexafi-val-financed-sub', calc.formatted_financed || '—');
          setValText(outcomeApproved, '.nexafi-val-charge', calc.formatted_charge || '—');
          setValText(outcomeApproved, '.nexafi-val-total-repayment', calc.formatted_repayment || '—');
          setValText(outcomeApproved, '.nexafi-val-monthly-table', calc.formatted_monthly || '—');

          // Financing Disclosures (§4)
          if (data.disclosure_text) {
            setValText(outcomeApproved, '.nexafi-val-disclosures', data.disclosure_text);
          }
          if (data.provider_disclosure) {
            setValText(outcomeApproved, '.nexafi-provider-disclosure-text', data.provider_disclosure);
          }

          // Reconciliation note (§3: when remainder_cents != 0 and terms verified)
          const reconciliationEl = outcomeApproved.querySelector('.nexafi-val-reconciliation');
          if (reconciliationEl) {
            const reconNote = calc.reconciliation_note || data.reconciliation_note;
            if (termsVerified && calc.remainder_cents && calc.remainder_cents !== 0 && reconNote) {
              reconciliationEl.textContent = reconNote;
              reconciliationEl.style.display = 'block';
            } else {
              reconciliationEl.textContent = '';
              reconciliationEl.style.display = 'none';
            }
          }

          // Acknowledgment Checkbox: Starts unchecked, user must manually check to enable next steps (§4.1)
          const ackCheckbox = outcomeApproved.querySelector('.nexafi-terms-checkbox');
          const btnAccept = outcomeApproved.querySelector('.nexafi-btn-accept');
          if (ackCheckbox && btnAccept) {
            ackCheckbox.checked = false;
            btnAccept.disabled = true;
            if (!ackCheckbox._hasChangeListener) {
              ackCheckbox._hasChangeListener = true;
              ackCheckbox.addEventListener('change', function () {
                btnAccept.disabled = !ackCheckbox.checked;
              });
            }
          }
        }
      } else {
        // §2c Fail-Closed Outcome: Ineligible, unverified terms, or preliminary authorization required
        if (outcomeIneligible) {
          outcomeIneligible.style.display = 'block';

          const isTrulyIneligible = (result === 'ineligible');
          const badgeCircle = outcomeIneligible.querySelector('.nexafi-badge-circle');

          if (badgeCircle) {
            if (isTrulyIneligible) {
              badgeCircle.className = 'nexafi-badge-circle nexafi-badge-amber';
              badgeCircle.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
              outcomeIneligible.classList.remove('nexafi-outcome-pending');
              outcomeIneligible.classList.add('nexafi-outcome-rejected');
            } else {
              // Calm info icon for pending application received state
              badgeCircle.className = 'nexafi-badge-circle nexafi-badge-blue';
              badgeCircle.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';
              outcomeIneligible.classList.remove('nexafi-outcome-rejected');
              outcomeIneligible.classList.add('nexafi-outcome-pending');
            }
          }

          const chosenRepName = (rep && rep.name) ? rep.name : (submittedRep ? ('Representative ' + submittedRep) : '—');
          const chosenRepId = (rep && rep.rep_id) ? rep.rep_id : (submittedRep ? submittedRep : '—');

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

          const fallbackHeading = isTrulyIneligible
            ? 'We’re Unable to Offer In-House Financing Right Now'
            : 'Your Application Has Been Received';
          const defaultReason = isTrulyIneligible
            ? 'Unfortunately, you don’t currently meet the requirements for our in-house financing program. Don’t worry! Your GDG Smiles representative can help you explore other available payment options.'
            : 'Your application has been received. Your GDG Smiles representative will confirm verified financing terms with you before proceeding.';

          let ineligHeadline = (data && data.headline) ? data.headline : fallbackHeading;
          if (ineligHeadline.toUpperCase().includes('APPROVED') || ineligHeadline.includes('Congratulations')) {
            ineligHeadline = fallbackHeading;
          }
          setValText(outcomeIneligible, '.nexafi-ineligible-heading', ineligHeadline);

          let cleanReason = (data && data.reason) ? data.reason : defaultReason;
          if (cleanReason.toUpperCase().includes('APPROVED') || cleanReason.includes('Congratulations') || cleanReason.includes('criteria satisfied')) {
            cleanReason = defaultReason;
          }
          setValText(outcomeIneligible, '.nexafi-ineligible-reason', cleanReason);

          if (data && data.message && isTrulyIneligible) {
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
            if (fallback) fallback.textContent = (data && data.fallback_contact_text) ? data.fallback_contact_text : 'Please speak with your representative directly.';
            if (contactBtn) contactBtn.href = '#';
          }

          const returnBtn = outcomeIneligible.querySelector('.nexafi-btn-return-home');
          if (returnBtn) {
            const currentReturnUrl = (data && data.return_url) || config.return_url || wrapper.getAttribute('data-return-url') || '';
            if (currentReturnUrl) {
              returnBtn.href = currentReturnUrl;
              returnBtn.style.display = 'flex';
            } else {
              returnBtn.style.display = 'none';
            }
          }
        }
      }
    }

    // Action button on Screen 4: Proceed to Screen 5 with explicit acceptance (§4)
    const btnAcceptList = wrapper.querySelectorAll('.nexafi-btn-accept');
    btnAcceptList.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const termsBox = wrapper.querySelector('.nexafi-terms-checkbox');
        if (termsBox && !termsBox.checked) {
          return;
        }
        if (applicationData && applicationData.app_id && termsBox && termsBox.checked) {
          sendTermsAcceptance(applicationData.app_id, applicationData.terms_token);
        }
        goToScreen(5);
      });
    });

    function sendTermsAcceptance(appId, termsToken) {
      const formData = new FormData();
      formData.append('action', 'nexafi_accept_terms');
      formData.append('security', config.nonce);
      formData.append('app_id', appId);
      formData.append('terms_token', termsToken || '');
      formData.append('terms_version', (applicationData && applicationData.terms_version) ? applicationData.terms_version : '1.0');

      fetch(config.ajax_url, {
        method: 'POST',
        body: formData,
      });
    }

    // -----------------------------------------------------------------------
    // SCREEN 5: Confirmation Rendering (§2c, §5)
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

      // Strictly real data only - no hardcoded fallbacks
      if (refNumEl) refNumEl.textContent = data.reference_number || '—';
      if (dateEl) dateEl.textContent = data.submission_date || '—';
      if (timeEl) timeEl.textContent = data.submission_time || '';

      const repName = (data.representative && data.representative.name) ? data.representative.name : '';
      const repCode = (data.representative && data.representative.rep_id) ? data.representative.rep_id : '';
      if (repEl) {
        repEl.textContent = repName ? (repName + (repCode ? ' (' + repCode + ')' : '')) : '—';
      }

      const isApproved = (data && data.eligibility_result === 'approved');
      const termsVerified = (data && data.terms_verified === true);

      if (isApproved && termsVerified) {
        if (titleEl) titleEl.textContent = "You're All Set!";
        if (subtitleEl) subtitleEl.textContent = 'Your financing application has been completed and approved.';
        if (statusEl) {
          statusEl.textContent = 'Approved';
          statusEl.className = 'nexafi-badge-status nexafi-ref-status';
        }
        if (stepsEligible) stepsEligible.style.display = 'block';
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
        if (stepsIneligible) stepsIneligible.style.display = 'block';
      }

      // Return button on Screen 5: plain direct link to nexafi_return_url, hidden if empty
      const returnBtn5 = screen5.querySelector('.nexafi-btn-return');
      const returnAction5 = returnBtn5 ? returnBtn5.closest('.nexafi-form-action') : null;
      if (returnBtn5) {
        const currentReturnUrl = (data && data.return_url) || config.return_url || wrapper.getAttribute('data-return-url') || '';
        if (currentReturnUrl) {
          returnBtn5.href = currentReturnUrl;
          if (returnAction5) returnAction5.style.display = 'block';
          returnBtn5.style.display = 'inline-flex';
        } else {
          if (returnAction5) returnAction5.style.display = 'none';
          returnBtn5.style.display = 'none';
        }
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
          screens[num].style.display = 'none';
        }
      });

      screens[screenNumber].classList.add('nexafi-active');
      screens[screenNumber].style.display = 'block';
      currentScreen = screenNumber;

      if (window.history && window.history.pushState) {
        try {
          const state = { nexafi_screen: screenNumber };
          if (!window.history.state || window.history.state.nexafi_screen !== screenNumber) {
            window.history.pushState(state, '', window.location.href);
          }
        } catch (e) {}
      }

      // Smooth scroll to top of card
      wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    window.addEventListener('popstate', function (e) {
      if (e.state && e.state.nexafi_screen && screens[e.state.nexafi_screen]) {
        Object.keys(screens).forEach(function (num) {
          if (screens[num]) {
            screens[num].classList.remove('nexafi-active');
            screens[num].style.display = 'none';
          }
        });
        screens[e.state.nexafi_screen].classList.add('nexafi-active');
        screens[e.state.nexafi_screen].style.display = 'block';
        currentScreen = e.state.nexafi_screen;
        restoreFormProgress();
      }
    });

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
