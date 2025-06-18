jQuery(document).ready(function($) {
    let startPlace, endPlace;

    const startInput = document.getElementById('start-location');
    const endInput = document.getElementById('end-location');

    const autocompleteStart = new google.maps.places.Autocomplete(startInput);
    const autocompleteEnd = new google.maps.places.Autocomplete(endInput);

    autocompleteStart.addListener('place_changed', function() {
        startPlace = autocompleteStart.getPlace();
    });

    autocompleteEnd.addListener('place_changed', function() {
        endPlace = autocompleteEnd.getPlace();
    });

    $('#calculate-price').on('click', function(e) {
        e.preventDefault();
        const date = $('#booking-date').val();
        if (!startPlace || !endPlace || !date) {
            alert('Fill all fields.');
            return;
        }

        $.post(cbs_ajax.ajax_url, {
            action: 'cbs_calculate_price',
            start_lat: startPlace.geometry.location.lat(),
            start_lng: startPlace.geometry.location.lng(),
            end_lat: endPlace.geometry.location.lat(),
            end_lng: endPlace.geometry.location.lng(),
            booking_date: date
        }, function(res) {
            let result = JSON.parse(res);
            $('#quote-output').html('<strong>Price: $' + result.price + '</strong>');
            $('#confirm-booking').show().data('price', result.price);
        });
    });

    $('#car-booking-form').on('submit', function(e) {
        e.preventDefault();
        $.post(cbs_ajax.ajax_url, {
            action: 'cbs_save_booking',
            booking_date: $('#booking-date').val(),
            start_location: $('#start-location').val(),
            end_location: $('#end-location').val(),
            price: $('#confirm-booking').data('price')
        }, function(res) {
            let result = JSON.parse(res);
            if (result.success) {
                alert('Booking saved!');
                location.reload();
            }
        });
    });
});
