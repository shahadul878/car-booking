jQuery(document).ready(function($) {
    flatpickr("#booking-date", {
        altInput: true,
        altFormat: "F j, Y",
        dateFormat: "Y-m-d"
    });

    $('#calculate-price').on('click', function() {
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
                $('#quote-output').html('Total Price:' + data.price +'Taka' + '<br>Total Distance: ' + data.distance + ' km<br>Garage to Start Location Distance: ' + data.garage_to_start + ' km');
                $('#confirm-booking').show();
                $('#customer-fields').show();
                $('#confirm-booking').data('price', data.price);
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
});