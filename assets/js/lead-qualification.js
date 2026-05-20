jQuery(function($){

  // Legacy cleanup: older versions stored this as a cookie, which caused HTTP 431.
  if (document.cookie.indexOf('lead_qualification=') !== -1) {
    document.cookie = 'lead_qualification=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
  }

  // --- Early redirect if already qualified and on the start page ---
  const path = window.location.pathname;
  const storedLead = sessionStorage.getItem('lead_qualification')
    || localStorage.getItem('lead_qualification');

  try {
    const parsed = JSON.parse(storedLead || '{}');
    if (path === '/see-if-you-qualify/' && parsed?.response?.lead_id) {
      const goEhail = !!(parsed?.request?.e_hailing); // prefer e-hailing if previously chosen
      showLoading();
      window.location.href = goEhail
        ? '/e-hailing-services/'
        : '/see-if-you-qualify-result/';
      return;
    }
  } catch (e) {
    console.warn('Invalid stored lead data:', e);
  }

  // Prefill source (if any)
  const savedLead = sessionStorage.getItem('lead_qualification')
    || localStorage.getItem('lead_qualification');
  let parsedLead = null;
  if (savedLead) {
    try { parsedLead = JSON.parse(savedLead); }
    catch (e) { console.warn('Invalid stored lead data:', e); }
  }

  // --- Loading overlay ---
  function showLoading() {
    if (!$('#gf5-loading-overlay').length && !$('#gform-loading-overlay').length) {
      $('body').append(`
        <div id="gform-loading-overlay">
          <div class="spinner"></div>
          <div class="loading-text">Processing your information, please wait…</div>
        </div>
      `);
    }
  }
  function hideLoading() {
    $('#gform-loading-overlay, #gf5-loading-overlay').remove();
  }

  // --- Validation helpers ---
  // South African mobile number: accept any input the user might paste — with
  // spaces, dashes, parens, country code prefix, etc. — and validate the
  // underlying digit string only. Two shapes are allowed:
  //   national:      0XXXXXXXXX  (10 digits, leading 0)
  //   international: 27XXXXXXXXX (11 digits, leading 27, with or without +)
  function isValidSAPhone(s) {
    const digits = String(s || '').replace(/\D+/g, '');
    return /^0\d{9}$/.test(digits) || /^27\d{9}$/.test(digits);
  }
  const emailRx = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  // South African ID: format-only check (13 digits). PACE itself does the
  // deeper validation against the Home Affairs database, so we deliberately
  // don't run a client-side Luhn checksum here — it was rejecting real IDs
  // for users in the wild.
  const idRx = /^\d{13}$/;

  // --- Prefill helpers for booleans -> selects ---
  function setYesNoSelectFromBool($select, boolVal){
    let chosen = null;
    $select.find('option').each(function(){
      const v = (($(this).val() || '').trim().toLowerCase());
      const t = (($(this).text()||'').trim().toLowerCase());
      if (boolVal) {
        if (v === 'yes' || t === 'yes' || t.startsWith('yes')) { chosen = $(this).val(); return false; }
      } else {
        if (v === 'no' || t === 'no' || t.startsWith('no') || t.startsWith('not')) { chosen = $(this).val(); return false; }
      }
    });
    if (chosen != null) $select.val(chosen);
    setTimeout(() => $select.trigger('change'), 0);
  }
  function setInitFeeSelectFromBool($select, boolVal){
    let chosen = null;
    $select.find('option').each(function(){
      const v = (($(this).val() || '').trim().toLowerCase());
      const t = (($(this).text()||'').trim().toLowerCase());
      if (boolVal) {
        if (v === 'yes' || t === 'yes' || t.startsWith('yes')) { chosen = $(this).val(); return false; }
      } else {
        if (v === 'no' || t === 'no' || t.startsWith('no') || t.startsWith('not')) { chosen = $(this).val(); return false; }
      }
    });
    if (chosen != null) $select.val(chosen);
    setTimeout(() => $select.trigger('change'), 0);
  }

  // --- Per-form config ---
  const formConfigs = [
    {
      id: '1',
      redirectYesGroup: 'input_27', // e-hailing yes/no radio group (values: yes/no)
      fields: [
        { sel:'#input_1_1_3',   name:'First Name',     type:'text'     },
        { sel:'#input_1_1_6',   name:'Surname',        type:'text'     },
        { sel:'#input_1_18',    name:'ID Number',      type:'id'       },
        { sel:'#input_1_3',     name:'Phone',          type:'tel'      },
        { sel:'#input_1_4',     name:'Email',          type:'email'    },
        { sel:'#input_1_6',     name:'Location',       type:'select'   },
        { sel:'#input_1_28',    name:'Other Location', type:'text'     },
        { sel:'#input_1_21',    name:'Take Home',      type:'text'     },
        { sel:'#input_1_8',     name:'Valid License',  type:'select'   },
        { sel:'#input_1_22',    name:'Initiation Fee', type:'select'   },
        { sel:'#choice_1_19_1', name:'Accept Terms',   type:'checkbox' }
      ]
    },
    {
      id: '3',
      redirectYesGroup: 'input_20', // e-hailing yes/no radio group (values: yes/no)
      fields: [
        { sel:'#input_3_1_3',   name:'First Name',     type:'text'     },
        { sel:'#input_3_1_6',   name:'Surname',        type:'text'     },
        { sel:'#input_3_11',    name:'ID Number',      type:'id'       },
        { sel:'#input_3_3',     name:'Phone',          type:'tel'      },
        { sel:'#input_3_4',     name:'Email',          type:'email'    },
        { sel:'#input_3_5',     name:'Location',       type:'select'   },
        { sel:'#input_3_21',    name:'Other Location', type:'text'     },
        { sel:'#input_3_17',    name:'Take Home',      type:'text'     },
        { sel:'#input_3_7',     name:'Valid License',  type:'select'   },
        { sel:'#input_3_18',    name:'Initiation Fee', type:'select'   },
        { sel:'#choice_3_9_1',  name:'Accept Terms',   type:'checkbox' }
      ]
    }
  ];

  // --- Main per-form logic ---
  formConfigs.forEach(cfg => {
    const form = $(`#gform_${cfg.id}`);
    if (!form.length) return;

    const submitBtn   = $(`#gform_submit_button_${cfg.id}`);
    const spinnerSbmt = $(`#gform_ajax_spinner_${cfg.id}`);

    // Prefill from cookie (if any) — includes booleans -> select/radio normalization
    if (parsedLead && parsedLead.request) {
      cfg.fields.forEach(fld => {
        const $el = $(fld.sel);
        if (!$el.length) return;

        const fieldMap = {
          '1': {
            '#input_1_1_3': 'name',
            '#input_1_1_6': 'surname',
            '#input_1_18': 'id_number',
            '#input_1_3': 'cellphone_number',
            '#input_1_4': 'your_email',
            '#input_1_6': 'location',
            '#input_1_28': 'other_location',
            '#input_1_21': 'take_home',
            '#input_1_8': 'valid_license',   // boolean in cookie
            '#input_1_22': 'initiation_fee'  // boolean in cookie
          },
          '3': {
            '#input_3_1_3': 'name',
            '#input_3_1_6': 'surname',
            '#input_3_11': 'id_number',
            '#input_3_3': 'cellphone_number',
            '#input_3_4': 'your_email',
            '#input_3_5': 'location',
            '#input_3_21': 'other_location',
            '#input_3_17': 'take_home',
            '#input_3_7': 'valid_license',   // boolean in cookie
            '#input_3_18': 'initiation_fee'  // boolean in cookie
          }
        };

        const inputKey = fieldMap[cfg.id]?.[fld.sel];
        if (!inputKey) return;

        const stored = parsedLead.request[inputKey];
        if (stored === undefined) return;

        if (fld.type === 'checkbox') {
          $el.prop('checked', !!stored);

        } else if (fld.type === 'select') {
          // Normalize boolean -> select option
          if ((fld.sel === '#input_1_8' || fld.sel === '#input_3_7') && typeof stored === 'boolean') {
            setYesNoSelectFromBool($el, stored);
            return;
          }
          if ((fld.sel === '#input_1_22' || fld.sel === '#input_3_18') && typeof stored === 'boolean') {
            setInitFeeSelectFromBool($el, stored);
            return;
          }

          // Direct set with case-insensitive fallback (matches by text/value)
          let selectedVal = stored;
          $el.val(selectedVal);
          if ($el.val() !== selectedVal) {
            const lower = (selectedVal || '').toString().trim().toLowerCase();
            const byText = $el.find('option').filter(function(){
              return ($(this).text() || '').trim().toLowerCase() === lower;
            });
            if (byText.length) $el.val(byText.first().val());
            else {
              const byVal = $el.find('option').filter(function(){
                return (($(this).val() || '').trim().toLowerCase() === lower);
              });
              if (byVal.length) $el.val(byVal.first().val());
            }
          }
          setTimeout(() => $el.trigger('change'), 0);

        } else {
          // text inputs
          $el.val(stored);
        }
      });

      // Prefill radios for e_hailing if present in cookie
      if (parsedLead.request.e_hailing !== undefined && cfg.redirectYesGroup) {
        const bool = !!parsedLead.request.e_hailing;
        const name = cfg.redirectYesGroup; // 'input_27' or 'input_20'
        const $radios = form.find(`input[name="${name}"]`);
        let matched = false;

        $radios.each(function(){
          const v = (($(this).val() || '').trim().toLowerCase());
          if ((bool && (v==='yes' || v==='true' || v==='1')) ||
              (!bool && (v==='no' || v==='false' || v==='0'))) {
            $(this).prop('checked', true);
            matched = true;
            return false;
          }
        });
        if (!matched) {
          $radios.each(function(){
            const id = $(this).attr('id');
            const t  = (form.find(`label[for="${id}"]`).text() || '').trim().toLowerCase();
            if ((bool && t.startsWith('yes')) || (!bool && (t.startsWith('no') || t.startsWith('not')))) {
              $(this).prop('checked', true);
              return false;
            }
          });
        }
      }
    }

    // --- Passive fix: ensure visible conditional inputs aren't disabled ---
    function enableIfVisible($field){
      if ($field.is(':visible')) {
        $field.find(':input:disabled').prop('disabled', false).prop('readonly', false);
      }
    }
    const $other1 = $('#field_1_28'); // Form 1 Other Location wrapper
    const $other3 = $('#field_3_21'); // Form 3 Other Location wrapper

    function afterGFToggle(){
      setTimeout(() => {
        if (cfg.id === '1' && $other1.length) enableIfVisible($other1);
        if (cfg.id === '3' && $other3.length) enableIfVisible($other3);
      }, 0);
    }

    if (window.gform && gform.addAction) {
      gform.addAction('gform_post_render', (formId) => {
        if (formId.toString() === cfg.id) afterGFToggle();
      });
      gform.addAction('gform_post_conditional_logic', (formId) => {
        if (formId.toString() === cfg.id) afterGFToggle();
      });
    } else {
      afterGFToggle();
    }
    if (cfg.id === '1') $('#input_1_6').on('change', afterGFToggle);
    if (cfg.id === '3') $('#input_3_5').on('change', afterGFToggle);

    // --- Validation (race-proof) ---
    function validateAllFields(){
      let allValid = true;

      cfg.fields.forEach(fld => {
        const $el = $(fld.sel);
        if (!$el.length) return;

        const $wrap = $el.closest('.gfield');

        const gfHidden   = $wrap.hasClass('gform_hidden') || $wrap.hasClass('gfield_visibility_hidden') || !$wrap.is(':visible');
        const isDisabled = $el.is(':disabled');
        if (gfHidden || isDisabled) {
          $wrap.removeClass('gfield_error').find('.js-inline-error').remove();
          return;
        }

        const val = (fld.type === 'checkbox') ? $el.is(':checked') : (($el.val()||'').trim());
        let valid = true, msg = '';
        switch (fld.type) {
          case 'text':    valid = val.length > 0; msg = `${fld.name} is required.`; break;
          case 'tel':     valid = isValidSAPhone(val); msg = 'Please enter a valid SA phone number.'; break;
          case 'email':   valid = emailRx.test(val); msg = 'Please enter a valid email address.'; break;
          case 'id':
            if (!val.length) { valid = false; msg = `${fld.name} is required.`; }
            else if (!idRx.test(val)) { valid = false; msg = 'ID Number must be 13 digits.'; }
            break;
          case 'select':  valid = val !== ''; msg = `Please select a ${fld.name.toLowerCase()}.`; break;
          case 'checkbox':valid = val === true; msg = 'You must accept the terms.'; break;
          default:        valid = val.length > 0; msg = `${fld.name} is required.`;
        }

        $wrap.removeClass('gfield_error').find('.js-inline-error').remove();
        if (!valid) {
          allValid = false;
          $wrap.addClass('gfield_error')
               .append(`<div class="gfield_description js-inline-error">${msg}</div>`);
        }
      });

      submitBtn.prop('disabled', !allValid);
      return allValid;
    }

    let _vfTimer = null;
    const validateDebounced = () => {
      if (_vfTimer) clearTimeout(_vfTimer);
      _vfTimer = setTimeout(validateAllFields, 60);
    };
    form.on('input change', 'input, select, textarea', validateDebounced);

    if (window.gform && gform.addAction) {
      gform.addAction('gform_post_conditional_logic', formId => {
        if (formId.toString() !== cfg.id) return;
        afterGFToggle();
        validateDebounced();
      });
      gform.addAction('gform_post_render', formId => {
        if (formId.toString() !== cfg.id) return;
        afterGFToggle();
        validateDebounced();
      });
    }

    validateAllFields(); // initial

    // --- Submit handler ---
    form.on('submit', function(e){
      if (form.data('processed')) return true;

      e.preventDefault();
      if (!validateAllFields()) return;

      submitBtn.prop('disabled', true);
      spinnerSbmt.show();

      // helpers
      const isYesSelect = (selectSel) => {
        const $sel = $(selectSel);
        const v = ($sel.val() || '').trim().toLowerCase();
        const t = ($sel.find('option:selected').text() || '').trim().toLowerCase();
        return v === 'yes' || t.startsWith('yes');
      };
      const isOtherSelected = (selectSel) => {
        const $sel = $(selectSel);
        const v = ($sel.val() || '').trim().toLowerCase();
        const t = ($sel.find('option:selected').text() || '').trim().toLowerCase();
        return v === 'other' || t === 'other';
      };
      const readLocationRaw = (selectSel) => {
        const $sel   = $(selectSel);
        const valRaw = ($sel.val() || '').trim();
        const txtRaw = ($sel.find('option:selected').text() || '').trim();
        return valRaw || txtRaw; // prefer <option value>, fallback to label
      };
      const readYesNoRadioByName = (name) => {
        const $checked = form.find(`input[name="${name}"]:checked`);
        if (!$checked.length) return false;
        const raw = ($checked.val() || '').toString().trim().toLowerCase();
        if (['yes','true','1'].includes(raw)) return true;
        if (['no','false','0'].includes(raw)) return false;
        const labelTxt = (form.find(`label[for="${$checked.attr('id')}"]`).text() || '').trim().toLowerCase();
        return labelTxt.startsWith('yes');
      };

      const data = (cfg.id === '1')
        ? {
            name:              $('#input_1_1_3').val().trim(),
            surname:           $('#input_1_1_6').val().trim(),
            id_number:         $('#input_1_18').val().trim(),
            cellphone_number:  $('#input_1_3').val().trim(),
            your_email:        $('#input_1_4').val().trim(),
            location:          readLocationRaw('#input_1_6'),
            other_location:    isOtherSelected('#input_1_6') ? ($('#input_1_28').val() || '').trim() : '',
            e_hailing:         readYesNoRadioByName('input_27'),
            take_home:         $('#input_1_21').val().trim(),
            valid_license:     isYesSelect('#input_1_8'),
            initiation_fee:    isYesSelect('#input_1_22'),
            accept_terms:      $('#choice_1_19_1').is(':checked')
          }
        : {
            name:              $('#input_3_1_3').val().trim(),
            surname:           $('#input_3_1_6').val().trim(),
            id_number:         $('#input_3_11').val().trim(),
            cellphone_number:  $('#input_3_3').val().trim(),
            your_email:        $('#input_3_4').val().trim(),
            location:          readLocationRaw('#input_3_5'),
            other_location:    isOtherSelected('#input_3_5') ? ($('#input_3_21').val() || '').trim() : '',
            e_hailing:         readYesNoRadioByName('input_20'),
            take_home:         $('#input_3_17').val().trim(),
            valid_license:     isYesSelect('#input_3_7'),
            initiation_fee:    isYesSelect('#input_3_18'),
            accept_terms:      $('#choice_3_9_1').is(':checked')
          };

      $.ajax({
        url:    '/wp-json/samotorlease/v1/qualify-lead',
        method: 'POST',
        data:   data,
        beforeSend: showLoading,
        success: function(response) {
          if (response.lead_id) {
            const takeHome = parseFloat((data.take_home || '').replace(/[^0-9.]/g, ''));

            const otherPicked = (cfg.id === '1')
              ? isOtherSelected('#input_1_6')
              : isOtherSelected('#input_3_5');

            // 1) API FAILED — "Other" dominates here too
            if (response.state === 'failed') {
              if (otherPicked) {
                hideLoading();
                window.location.href = '/see-if-you-qualify-you-do-not-qualify';
                return;
              }
              if (takeHome > 17000 && data.valid_license && response.qualify_area) {
                showGFormError('Something went wrong. Please try again or <a href="/contact-us">contact us</a> directly.');
                submitBtn.prop('disabled', false);
                spinnerSbmt.hide();
                hideLoading();
              } else {
                window.location.href = '/see-if-you-qualify-you-do-not-qualify';
              }
              return;
            }

            // 2) API SUCCESS — "Other" still dominates
            if (otherPicked) {
              hideLoading();
              window.location.href = '/see-if-you-qualify-you-do-not-qualify';
              return;
            }

            // Persist lead (not in "Other" redirect path)
            const leadData = {
              request:  data,
              response: {
                lead_id:         response.lead_id,
                state:           response.state,
                qualify_salary:  response.qualify_salary,
                qualify_license: response.qualify_license,
                qualify_area:    response.qualify_area,
                rental_limit:    response.rental_limit,
                fast_track:      response.fast_track
              }
            };
            sessionStorage.setItem('lead_qualification', JSON.stringify(leadData));
            localStorage.setItem('lead_qualification', JSON.stringify(leadData));

            // E-hailing redirect (only if not "Other")
            let goEhail = false;
            if (cfg.redirectYesGroup) {
              const v = (form.find(`input[name="${cfg.redirectYesGroup}"]:checked`).val() || '').toString().toLowerCase();
              goEhail = (v === 'yes');
            }
            if (goEhail) {
              hideLoading();
              window.location.href = '/e-hailing-services/';
              return;
            }

            // Otherwise continue to normal GF submit
            form.data('processed', true)[0].submit();

          } else {
            showGFormError('Something went wrong. Please try again or <a href="/contact-us">contact us</a> directly.');
            submitBtn.prop('disabled', false);
            spinnerSbmt.hide();
            hideLoading();
          }
        },
        error: function(xhr) {
          let msg = 'API error. Please try again.';
          try {
            const res = JSON.parse(xhr.responseText);
            if (res.error) msg = res.error;
          } catch (e) {}
          showGFormError(msg);
          submitBtn.prop('disabled', false);
          spinnerSbmt.hide();
          hideLoading();
        }
      });
    });

    function showGFormError(message){
      form.find('.gform_validation_errors').remove();
      form.prepend(
        `<div class="gform_validation_errors" tabindex="-1">
           <h2 class="gform_submission_error">${message}</h2>
         </div>`
      );
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  });
});
