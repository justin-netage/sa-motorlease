jQuery(document).ready(function ($) {

  // Legacy cleanup: older versions stored this as a cookie, which caused HTTP 431.
  if (document.cookie.indexOf('lead_qualification=') !== -1) {
    document.cookie = 'lead_qualification=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
  }

  const FORM_ID = 5;
  const form = $('#gform_' + FORM_ID);
  const submitBtn = $('#gform_submit_button_' + FORM_ID);
  const nextBtn = $('#gform_next_button_5_36');
  const leadInput = $('#input_5_42'); // Hidden field for lead_id

  window.dataLayer = window.dataLayer || [];

  // ✅ Wait for GTM to be ready before pushing custom events
  function pushAfterGtmReady(eventObj, callback) {
    const alreadyLoaded = window.dataLayer.some(e => e.event === 'gtm.js');
    if (alreadyLoaded) {
      window.dataLayer.push(eventObj);
      console.log("✅ Data Layer Event Pushed Immediately:", eventObj.event);
      if (typeof callback === 'function') callback();
      return;
    }

    const originalPush = window.dataLayer.push;
    window.dataLayer.push = function () {
      const args = Array.from(arguments);
      for (const arg of args) {
        if (arg && arg.event === 'gtm.js') {
          window.dataLayer.push = originalPush;
          window.dataLayer.push(eventObj);
          console.log("✅ Data Layer Event Pushed After gtm.js:", eventObj.event);
          if (typeof callback === 'function') callback();
        }
      }
      return originalPush.apply(window.dataLayer, args);
    };
  }

  // ✅ Show Redirect Popup
  function showRedirectPopup() {
    if (!$('#gf5-redirect-overlay').length) {
      $('body').append(`
        <div id="gf5-redirect-overlay" style="position: fixed; z-index: 9999; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(255,255,255,0.95); display: flex; flex-direction: column; align-items: center; justify-content: center; font-family: sans-serif;">
          <div class="spinner" style="border: 6px solid #f3f3f3; border-top: 6px solid #333; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite;"></div>
          <p style="margin-top: 20px; font-size: 18px; color: #333;">Redirecting, please wait...</p>
        </div>
        <style>
          @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
          }
        </style>
      `);
    }
  }

  // ✅ Lead ID Check
  let leadId = '';
  try {
    const stored = JSON.parse(sessionStorage.getItem('lead_qualification') || '{}');
    leadId = stored.response?.lead_id || '';

    if (!leadId) {
      console.log("No lead_id found. Redirecting...");
      showRedirectPopup();
      setTimeout(() => {
        window.location.href = '/see-if-you-qualify';
      }, 1500);
    } else {
      leadInput.val(leadId);
      console.log("lead_id found:", leadId);
    }
  } catch (err) {
    console.log("Session/cookie parse error. Redirecting...");
    showRedirectPopup();
    setTimeout(() => {
      window.location.href = '/see-if-you-qualify';
    }, 1500);
  }

  // ✅ Final Loading Overlay
  function showLoading() {
    if (!$('#gf5-loading-overlay').length) {
      $('body').append(`
        <div id="gf5-loading-overlay">
          <div class="spinner"></div>
          <div class="loading-text">Submitting your information, please wait…</div>
        </div>
      `);
    }
  }

  function hideLoading() {
    $('#gf5-loading-overlay').remove();
  }

  // ✅ Error Message
  function showGFormError(message) {
    $(".gform_validation_errors").remove();
    const $topError = form.find('.validation_error');
    if ($topError.length) {
      $topError.text(message).show();
    } else {
      form.prepend(`<div class="gform_validation_errors" style="background-color:#fff;" id="gform_2_validation_container" data-js="gform-focus-validation-error" tabindex="-1"><h2 class="gform_submission_error hide_summary"><span class="gform-icon gform-icon--circle-error"></span>${message}</h2></div>`);
    }
    $('#gform_ajax_spinner_3').remove();
    $('#gform_ajax_spinner_1').remove();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  // ✅ Next Button (Step 1 Save)
  nextBtn.on('click', function (e) {
    e.preventDefault();

    const requiredFields = {
      home_address: {
        street: $('#input_5_44').val(),
        suburb: $('#input_5_45').val(),
        city: $('#input_5_46').val(),
        province: $('#input_5_47').val(),
        country: 'South Africa',
      },
      occupation: $('#input_5_18').val(),
      employment_status: $('#input_5_20').val(),
      employer_name: $('#input_5_21').val(),
      work_email: $('#input_5_23').val(),
      work_phone: $('#input_5_24').val(),
      work_address: {
        street: $('#input_5_48').val(),
        suburb: $('#input_5_49').val(),
        city: $('#input_5_50').val(),
        province: $('#input_5_51').val(),
        country: 'South Africa',
      },
      proof_address: $('#input_5_35').is(':checked') ? 'true' : 'false',
      lead_id: leadId,
      bank_statements: { objData: [] }
    };

    console.log("Partial save payload:", requiredFields);

    // ✅ Validate only the required ones
    const isValid =
      requiredFields.home_address.street?.trim() &&
      requiredFields.home_address.suburb?.trim() &&
      requiredFields.home_address.city?.trim() &&
      requiredFields.home_address.province?.trim() &&
      requiredFields.employment_status?.trim() &&
      requiredFields.employer_name?.trim() &&
      requiredFields.work_address.street?.trim() &&
      requiredFields.work_address.suburb?.trim();

    if (isValid) {
      window.dataLayer = window.dataLayer || [];
      window.dataLayer.push({
        event: "application_form_step_1_success",
        formStep: 1,
        formName: "Application Form",
        formStatus: "success"
      });
      console.log("✅ GTM: Step 1 success event pushed");
    } else {
      console.log("⚠️ Missing required fields — GTM event not pushed");
    }

    $.ajax({
      url: '/wp-json/samotorlease/v1/partial-save',
      method: 'POST',
      data: JSON.stringify(requiredFields),
      contentType: 'application/json',
      success: function (response) {
        console.log('✅ Partial save success:', response);

        // Push GTM event, then continue to next form page
        pushAfterGtmReady({
          event: "application_form_step_1_success",
          formStep: 1,
          formName: "Application Form",
          formStatus: "success"
        }, function () {
          $('#gform_target_page_number_5').val('2');
          $('#gform_5').trigger('submit', [true]);
        });
      },
      error: function (xhr, status, error) {
        console.error('❌ Partial save failed:');
        console.log('Status:', status);
        console.log('Error:', error);
        console.log('Response Text:', xhr.responseText);
        alert("Something went wrong. Please try again.");
      }
    }).fail(function (xhr, textStatus, errorThrown) {
      console.error('❌ AJAX request failed completely:', {
        status: textStatus,
        error: errorThrown,
        response: xhr.responseText
      });
    });
  });

  // ✅ Final Submit Button (Step 2)
  submitBtn.on('click', function (e) {
    window.dataLayer.push({
      event: "application_form_step_2_success",
      formStep: 2,
      formName: "Application Form",
      formStatus: "success"
    });
    console.log("✅ Data Layer Event Pushed: Step 2");

    showLoading();

    let leadId = '';
    try {
      const stored = JSON.parse(sessionStorage.getItem('lead_qualification') || '{}');
      leadId = stored.response?.lead_id || '';
    } catch (_) {}

    if (!leadId) {
      console.log("lead_id is missing on submit");
      e.preventDefault();
      showGFormError('Lead ID is missing. Please complete the first form.');
      hideLoading();
      return false;
    }

    console.log("Lead ID inserted:", leadId);
    leadInput.val(leadId);
  });
});
