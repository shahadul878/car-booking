<?php
/**
 * Plugin Name: Car Booking System
 * Description: Car rental quote and booking plugin with distance-based pricing, weekend and urgent booking charges.
 * Version: 1.0
 * Author: Shahadul Islam
 */

if (!defined('ABSPATH')) exit;

class CarBookingSystem {

    private $table_name;
    private $garage_lat = 23.7745;
    private $garage_lng = 90.3654;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'car_bookings';

        register_activation_hook(__FILE__, [$this, 'create_booking_table']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_shortcode('car_booking_form', [$this, 'render_booking_form']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        add_action('wp_ajax_cbs_calculate_price', [$this, 'calculate_price']);
        add_action('wp_ajax_nopriv_cbs_calculate_price', [$this, 'calculate_price']);
        add_action('wp_ajax_cbs_save_booking', [$this, 'save_booking']);
        add_action('wp_ajax_nopriv_cbs_save_booking', [$this, 'save_booking']);
    }

    public function create_booking_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_date DATE NOT NULL,
            start_location TEXT NOT NULL,
            end_location TEXT NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function enqueue_scripts() {
        wp_enqueue_script('google-maps', 'https://maps.googleapis.com/maps/api/js?key=AIzaSyCDdOEP4uq7MbfGzxCkvUYFRrEM4-1E5Vk&libraries=places', [], null, true);
        wp_enqueue_script('cbs-script', plugin_dir_url(__FILE__) . 'assets/js/cbs-script.js', ['jquery'], null, true);
        wp_enqueue_style('cbs-style', plugin_dir_url(__FILE__) . 'assets/css/cbs-style.css');
        wp_localize_script('cbs-script', 'cbs_ajax', [
            'ajax_url' => admin_url('admin-ajax.php')
        ]);
    }

    public function render_booking_form() {
        ob_start(); ?>
        <div class="cbs-booking-wrapper">
            <form id="car-booking-form">
                <label for="booking-date">Date:</label>
                <input type="date" id="booking-date" name="booking_date" required>

                <label for="start-location">Start Location:</label>
                <input type="text" id="start-location" name="start_location" required>

                <label for="end-location">End Location:</label>
                <input type="text" id="end-location" name="end_location" required>

                <button type="button" id="calculate-price" class="cbs-button">Calculate Price</button>

                <div id="quote-output" class="cbs-quote-output"></div>

                <button type="submit" id="confirm-booking" class="cbs-button" style="display:none;">Confirm Booking</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function calculate_price() {
        $start_lat = $_POST['start_lat'];
        $start_lng = $_POST['start_lng'];
        $end_lat = $_POST['end_lat'];
        $end_lng = $_POST['end_lng'];
        $booking_date = $_POST['booking_date'];

        $dist_to_start = $this->haversine($this->garage_lat, $this->garage_lng, $start_lat, $start_lng);
        $dist_between = $this->haversine($start_lat, $start_lng, $end_lat, $end_lng);

        $price = ($dist_to_start * 10) + ($dist_between * 15);
        $price = max(85, $price);

        $date = new DateTime($booking_date);
        $now = new DateTime();

        if (($date->getTimestamp() - $now->getTimestamp()) < 86400) {
            $price *= 1.2;
        }

        if (in_array($date->format('N'), [6, 7])) {
            $price *= 1.05;
        }

        echo json_encode(['price' => round($price, 2)]);
        wp_die();
    }

    public function save_booking() {
        global $wpdb;

        $wpdb->insert($this->table_name, [
            'booking_date' => sanitize_text_field($_POST['booking_date']),
            'start_location' => sanitize_text_field($_POST['start_location']),
            'end_location' => sanitize_text_field($_POST['end_location']),
            'price' => sanitize_text_field($_POST['price']),
            'created_at' => current_time('mysql')
        ]);

        echo json_encode(['success' => true]);
        wp_die();
    }

    public function register_admin_menu() {
        add_menu_page('Car Bookings', 'Car Bookings', 'manage_options', 'cbs-bookings', [$this, 'render_admin_page'], 'dashicons-car', 25);
    }

    public function render_admin_page() {
        global $wpdb;
        $results = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY created_at DESC");

        echo '<div class="wrap"><h1>Car Bookings</h1>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Date</th><th>Start</th><th>End</th><th>Price</th><th>Created</th></tr></thead><tbody>';
        foreach ($results as $r) {
            echo "<tr><td>{$r->id}</td><td>{$r->booking_date}</td><td>{$r->start_location}</td><td>{$r->end_location}</td><td>\${$r->price}</td><td>{$r->created_at}</td></tr>";
        }
        echo '</tbody></table></div>';
    }

    private function haversine($lat1, $lon1, $lat2, $lon2, $earthRadius = 6371) {
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);
        $latDelta = $lat2 - $lat1;
        $lonDelta = $lon2 - $lon1;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
                cos($lat1) * cos($lat2) * pow(sin($lonDelta / 2), 2)));
        return $angle * $earthRadius;
    }
}

new CarBookingSystem();