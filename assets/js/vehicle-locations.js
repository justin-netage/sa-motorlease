// jQuery(function($){
//   // 1) Wrap the Vehicles heading in a link (if it isn't already)
//   var $firstCol = $('#menu-item-56 .sub-menu.nav-dropdown section.section .section-content')
//                     .find('> .row.align-equal.align-center > .col')
//                     .first();
//   var $h2 = $firstCol.find('h2').first();
//   if ($h2.length && !$h2.find('a').length) {
//     $h2.wrapInner('<a href="/listings"></a>');
//   }

//   // 2) Find the second column that holds the areas
//   var $megaRow    = $('#menu-item-56 .sub-menu.nav-dropdown section.section .section-content')
//                       .find('> .row.align-equal.align-center')
//                       .first();
//   var $wrapperCol = $megaRow.children('.col').eq(1);
//   var $innerRow   = $wrapperCol.find('.row.row-large.row-dashed').first();
//   if (!$innerRow.length) {
//     return;
//   }

//   // 3) Clear out old area links
//   $innerRow.empty();

//   // 4) Rebuild from VehicleLocationsData
//   VehicleLocationsData.forEach(function(prov){
//     // a) Province header
//     var $headerStack = $('<div>', { class: 'ux-menu stack stack-col justify-start ux-menu--divider-solid' })
//       .append(
//         $('<div>', { class: 'ux-menu-link flex menu-item pseudoh' })
//           .append(
//             $('<a>', {
//               class: 'ux-menu-link__link flex',
//               href: prov.url
//             }).append(
//               $('<span>', {
//                 class: 'ux-menu-link__text',
//                 text: prov.name
//               })
//             )
//           )
//       );

//     // b) Child area links
//     var $childStack = $('<div>', { class: 'ux-menu stack stack-col justify-start vmm ux-menu--divider-solid' });
//     prov.areas.forEach(function(area){
//       $childStack.append(
//         $('<div>', { class: 'ux-menu-link flex menu-item' })
//           .append(
//             $('<a>', {
//               class: 'ux-menu-link__link flex',
//               href: area.url
//             }).append(
//               $('<span>', {
//                 class: 'ux-menu-link__text',
//                 text: area.name
//               })
//             )
//           )
//       );
//     });

//     // c) Wrap in a 3-column block
//     var $col = $('<div>', { class: 'col medium-3 small-6 large-3' })
//       .append(
//         $('<div>', { class: 'col-inner' })
//           .append($headerStack)
//           .append($childStack)
//       );

//     // d) Append to the inner row
//     $innerRow.append($col);
//   });
// });
