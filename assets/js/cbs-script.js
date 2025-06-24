jQuery(document).ready(function($) {

    const maxSlotsPerDay = 2;
    let bookingCounts = {};

    function formatDateLocal(dateObj) {
        const y = dateObj.getFullYear();
        const m = (dateObj.getMonth() + 1).toString().padStart(2, '0');
        const d = dateObj.getDate().toString().padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    $.post(cbs_ajax.ajax_url, { action: 'cbs_get_booking_counts' }, function (response) {
        try {
            bookingCounts = JSON.parse(response);
        } catch (e) {
            console.error("Error parsing booking counts", e);
        }

        flatpickr("#booking-date", {
            dateFormat: "Y-m-d",
            minDate: "today",
            onDayCreate: function (dObj, dStr, fp, dayElem) {
                const date = formatDateLocal(dayElem.dateObj);
                const booked = bookingCounts[date] || 0;
                const slotsLeft = maxSlotsPerDay - booked;

                if (booked >= maxSlotsPerDay) {
                    dayElem.classList.add("fully-booked");
                    dayElem.classList.add("flatpickr-disabled");
                    dayElem.setAttribute("title", "Fully booked");
                } else if (booked >= 0) {
                    dayElem.setAttribute("title", `${booked} of ${maxSlotsPerDay} slots booked`);
                }
            }
        });
    });

    $('#calculate-price').on('click', function()  {
        var date = $('#booking-date').val();
        var start = $('#start-location').val();
        var end = $('#end-location').val();

        if (!date || !start || !end) {
            alert('Please fill all fields.');
            return;
        }

        $.post(cbs_ajax.ajax_url, {
            action: 'cbs_calculate_price',
            booking_date: date,
            start_location: start,
            end_location: end
        }, function(response) {
            var data = JSON.parse(response);

            if (data.error) {
                $('#quote-output').html('<span style="color:red">' + data.error + '</span>');
            } else {
                $('#quote-output').html(
                    'Garage → Pickup: ' + data.garage_to_start + ' km (' + data.garage_to_start_charge + ' BDT)' +
                    '<br>Pickup → Drop-off: ' + data.dist_between + ' km (' + data.pickup_to_drop_charge + ' BDT)' +
                    '<br>Total Distance: ' + data.distance + ' km' +
                    (data.surcharge_urgent ? '<br><strong>Urgent Booking Charge Applied (' + data.urgent_charge + '%)</strong>' : '') +
                    (data.surcharge_weekend ? '<br><strong>Weekend Charge Applied (' + data.weekend_charge + '%)</strong>' : '') +
                    '<br><strong>Total Price: ' + data.final_price + ' BDT</strong>'
                );
                $('#confirm-booking').show();
                $('#customer-fields').show();
                $('#confirm-booking').data('price', data.final_price);
                $('#confirm-booking').data('distance', data.distance);
            }

        });
    });

    $('#car-booking-form').on('submit', function(e) {
        e.preventDefault();
        var date = $('#booking-date').val();
        var start = $('#start-location').val();
        var end = $('#end-location').val();
        var price = $('#confirm-booking').data('price');
        var distance = $('#confirm-booking').data('distance');
        var name = $('#cbs-name').val();
        var phone = $('#cbs-phone').val();
        var email = $('#cbs-email').val();

        $.post(cbs_ajax.ajax_url, {
            action: 'cbs_save_booking',
            booking_date: date,
            start_location: start,
            end_location: end,
            price: price,
            distance: distance,
            name: name,
            phone: phone,
            email: email
        }, function(response) {
            var data = JSON.parse(response);
            if (data.success) {
                $('#quote-output').html('<span style="color:green">Booking confirmed!</span>');
                $('#confirm-booking').hide();
                $('#customer-fields').hide();
            }
        });
    });

    // --- Address Autocomplete ---
    function setupAutocomplete(inputId) {
        var $input = $('#' + inputId);
        var $dropdown = $('<div class="cbs-autocomplete-dropdown"></div>').css({position:'absolute',zIndex:9999,background:'#fff',border:'1px solid #ccc',display:'none',maxHeight:'200px',overflowY:'auto'});
        $input.after($dropdown);
        $input.on('input', function() {
            var val = $input.val();
            if (val.length < 3) { $dropdown.hide(); return; }
            $.get(cbs_ajax.ajax_url, {
                action: 'cbs_places_autocomplete',
                q: val
            }, function(data) {
                $dropdown.empty();
                if (Array.isArray(data) && data.length) {
                    data.forEach(function(place) {
                        var $item = $('<div class="cbs-autocomplete-item"></div>').text(place).css({padding:'5px',cursor:'pointer'});
                        $item.on('mousedown', function(e) {
                            e.preventDefault();
                            $input.val(place);
                            $dropdown.hide();
                        });
                        $dropdown.append($item);
                    });
                    var offset = $input.offset();
                    $dropdown.css({top: $input.outerHeight(), left: 0, width: $input.outerWidth()});
                    $dropdown.show();
                } else {
                    $dropdown.hide();
                }
            });
        });
        $input.on('blur', function() { setTimeout(function() { $dropdown.hide(); }, 200); });
    }
    setupAutocomplete('start-location');
    setupAutocomplete('end-location');
});