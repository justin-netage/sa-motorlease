jQuery(function($){

  // Legacy cleanup: older versions stored this as a cookie, which caused HTTP 431.
  if (document.cookie.indexOf('lead_qualification=') !== -1) {
    document.cookie = 'lead_qualification=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
  }

  // --- Try sessionStorage first, then fallback to localStorage ---
  let stored = sessionStorage.getItem('lead_qualification');
  if (!stored) stored = localStorage.getItem('lead_qualification');
  if (!stored) return;

  let lead;
  try {
    lead = JSON.parse(stored);
  } catch (e) {
    console.warn('Invalid lead_qualification data:', e);
    return;
  }

  let rawLimit = lead.response?.rental_limit;
  if (typeof rawLimit !== 'string' && typeof rawLimit !== 'number') return;

  rawLimit = String(rawLimit).replace(/[^\d.]/g, '');
  const userRentalLimit = parseFloat(rawLimit);
  if (isNaN(userRentalLimit)) {
    console.warn('Invalid limit:', rawLimit);
    return;
  }

  // --- 3. Display rental limit ---
  const formattedLimit = new Intl.NumberFormat('en-ZA', {
    style: 'currency', currency: 'ZAR', minimumFractionDigits: 2
  }).format(userRentalLimit);

  $('#rateLimitResult').text(formattedLimit);
  $('#rateLimitResultMobile').text(formattedLimit);

  const baseLink = $('#qualifiedVehiclesLink').attr('href') || '';
  $('#qualifiedVehiclesLink')
    .attr('href', baseLink.replace(/\/$/, '') + userRentalLimit);

  // --- 4. Fetch qualified vehicles ---
  $.getJSON(`/wp-json/samotorlease/v1/qualified-vehicles?rental_limit=${userRentalLimit}`)
    .done(function(products){
      if (!products.length) {
        $('#qualifiedVehiclesLink')
          .prop('disabled', true)
          .addClass('disabled')
          .attr('href', "#")
          .text('No vehicles available for the maximum monthly rental')
          .click(function(e) { e.preventDefault(); });
        return;
      }

      const topTen = products.slice(0, 10);

      const flickityOpts = {
        imagesLoaded:    true,
        groupCells:      "100%",
        dragThreshold:   5,
        cellAlign:       "left",
        wrapAround:      true,
        prevNextButtons: true,
        percentPosition: true,
        pageDots:        true,
        autoPlay:        7000
      };

      const $row = $('<div/>', {
        class: 'row hp-carousel equalize-box large-columns-5 medium-columns-2 small-columns-1 ' +
               'row-normal row-full-width has-shadow row-box-shadow-2 ' +
               'row-box-shadow-5-hover slider row-slider slider-nav-circle ' +
               'slider-nav-push is-draggable flickity-enabled',
        tabindex: 0
      }).attr('data-flickity-options', JSON.stringify(flickityOpts));

      topTen.forEach(p => {
        $row.append(`
          <div class="product-small col has-hover product type-product instock purchasable product-type-simple">
            <div class="col-inner">
              <div class="badge-container absolute left top z-1"></div>
              <div class="product-small box">
                <div class="box-image" style="position:relative;overflow:hidden;">
                  <img
                    src="${p.images.main}"
                    class="main-image"
                    style="width:100%;height:200px;object-fit:cover;display:block;"
                  >
                  <img
                    src="${p.images.hover}"
                    class="hover-image"
                    style="position:absolute;top:0;left:0;width:100%;height:200px;object-fit:cover;
                           opacity:0;transition:opacity .3s;"
                  >
                </div>
                <div class="box-text box-text-products">
                  <div class="title-wrapper">
                    <p class="name product-title">
                      <a href="${p.link}" class="woocommerce-LoopProduct-link">${p.title}</a>
                    </p>
                  </div>
                  <div class="price-wrapper">
                    ${p.price} <small class="woocommerce-price-suffix">p/m</small>
                  </div>
                </div>
              </div>
            </div>
          </div>
        `);
      });

      $('#qualifiedVehiclesCarousel')
        .empty()
        .append($('<div class="col-inner"></div>').append($row));

      $row.flickity(flickityOpts);

      $row.on('mouseenter', '.product-small', function(){
        $(this).find('.hover-image').css('opacity', 1);
      }).on('mouseleave', '.product-small', function(){
        $(this).find('.hover-image').css('opacity', 0);
      });
    })
    .fail(function(){
      console.error('Failed to load qualified vehicles.');
    });
});
