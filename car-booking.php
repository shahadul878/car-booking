<?php
/**
 * Plugin Name: Car Booking System
 * Description: Car rental quote and booking plugin with distance-based pricing, weekend and urgent booking charges.
 * Version: 1.1
 * Author: Shahadul Islam
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CarBookingSystem {

	private $table_name;
	private $garage_lat = 23.7745;
	private $garage_lng = 90.3654;

	private $locations_option_key = 'cbs_locations';

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'car_bookings';

		register_activation_hook( __FILE__, [ $this, 'create_booking_table' ] );
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_shortcode( 'car_booking_form', [ $this, 'render_booking_form' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		add_action( 'wp_ajax_cbs_calculate_price', [ $this, 'calculate_price' ] );
		add_action( 'wp_ajax_nopriv_cbs_calculate_price', [ $this, 'calculate_price' ] );
		add_action( 'wp_ajax_cbs_save_booking', [ $this, 'save_booking' ] );
		add_action( 'wp_ajax_nopriv_cbs_save_booking', [ $this, 'save_booking' ] );
		add_action( 'wp_ajax_cbs_get_booked_dates', [ $this, 'get_fully_booked_dates' ] );
		add_action( 'wp_ajax_nopriv_cbs_get_booked_dates', [ $this, 'get_fully_booked_dates' ] );
		add_action( 'wp_ajax_cbs_get_booking_counts', [ $this, 'get_booking_counts' ] );
		add_action( 'wp_ajax_nopriv_cbs_get_booking_counts', [ $this, 'get_booking_counts' ] );
	}

	private function get_locations() {
		$default_locations = [
			'Dhanmondi'   => [ 'lat' => 23.7461, 'lng' => 90.3742 ],
			'Uttara'      => [ 'lat' => 23.8748, 'lng' => 90.3984 ],
			'Mirpur'      => [ 'lat' => 23.8041, 'lng' => 90.3667 ],
			'Mohammadpur' => [ 'lat' => 23.7638, 'lng' => 90.3586 ],
			'Banani'      => [ 'lat' => 23.7936, 'lng' => 90.4042 ]
		];

		return get_option( $this->locations_option_key, $default_locations );
	}

	public function create_booking_table() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_date DATE NOT NULL,
            start_location TEXT NOT NULL,
            end_location TEXT NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            distance DECIMAL(10,2) NOT NULL,
            name VARCHAR(100),
            phone VARCHAR(30),
            email VARCHAR(100),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr', [], null, true );
		wp_enqueue_script( 'cbs-script', plugin_dir_url( __FILE__ ) . 'assets/js/cbs-script.js', [ 'jquery' ], null, true );
		wp_enqueue_style( 'flatpickr-style', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css' );
		wp_enqueue_style( 'cbs-style', plugin_dir_url( __FILE__ ) . 'assets/css/cbs-style.css' );
		wp_localize_script( 'cbs-script', 'cbs_ajax', [
			'ajax_url' => admin_url( 'admin-ajax.php' )
		] );
	}

	public function render_booking_form() {
		$locations = $this->get_locations();
		ob_start(); ?>
        <div class="cbs-booking-wrapper">
            <h2>Car Booking System</h2>
            <form id="car-booking-form">
                <label for="booking-date">Select Booking Date:</label>
                <input type="text" id="booking-date" name="booking_date" class="cbs-input" placeholder="YYYY-MM-DD"
                       required>

                <label for="start-location">Start Location:</label>
                <select id="start-location" name="start_location" class="cbs-select" required>
                    <option value="">-- Select Start Location --</option>
					<?php foreach ( $locations as $loc => $coords ): ?>
                        <option value="<?php echo esc_attr( $loc ); ?>"><?php echo esc_html( $loc ); ?></option>
					<?php endforeach; ?>
                </select>

                <label for="end-location">End Location:</label>
                <select id="end-location" name="end_location" class="cbs-select" required>
                    <option value="">-- Select End Location --</option>
					<?php foreach ( $locations as $loc => $coords ): ?>
                        <option value="<?php echo esc_attr( $loc ); ?>"><?php echo esc_html( $loc ); ?></option>
					<?php endforeach; ?>
                </select>

                <button type="button" id="calculate-price" class="cbs-button">Calculate Price</button>
                <div id="quote-output" class="cbs-quote-output"></div>
                <div id="customer-fields" style="display:none;">
                    <label for="cbs-name">Name:</label>
                    <input type="text" id="cbs-name" name="name" class="cbs-input" required>

                    <label for="cbs-phone">Phone:</label>
                    <input type="text" id="cbs-phone" name="phone" class="cbs-input" required>

                    <label for="cbs-email">Email:</label>
                    <input type="email" id="cbs-email" name="email" class="cbs-input" required>
                </div>
                <button type="submit" id="confirm-booking" class="cbs-button" style="display:none;">Confirm Booking
                </button>
            </form>
        </div>
		<?php
		return ob_get_clean();
	}

	public function calculate_price() {
		$locations    = $this->get_locations();
		$start        = sanitize_text_field( $_POST['start_location'] );
		$end          = sanitize_text_field( $_POST['end_location'] );
		$booking_date = sanitize_text_field( $_POST['booking_date'] );

		if ( ! isset( $locations[ $start ] ) || ! isset( $locations[ $end ] ) ) {
			echo json_encode( [ 'error' => 'Invalid location' ] );
			wp_die();
		}

		$start_coords = $locations[ $start ];
		$end_coords   = $locations[ $end ];

		$dist_to_start  = $this->haversine( $this->garage_lat, $this->garage_lng, $start_coords['lat'], $start_coords['lng'] );
		$dist_between   = $this->haversine( $start_coords['lat'], $start_coords['lng'], $end_coords['lat'], $end_coords['lng'] );
		$total_distance = $dist_to_start + $dist_between;

		$price = ( $dist_to_start * 10 ) + ( $dist_between * 15 );
		$price = max( 85, $price );

		$date = new DateTime( $booking_date );
		$now  = new DateTime();

		if ( ( $date->getTimestamp() - $now->getTimestamp() ) < 86400 ) {
			$price *= 1.2;
		}

		if ( in_array( $date->format( 'N' ), [ 6, 7 ] ) ) {
			$price *= 1.05;
		}

		echo json_encode( [
			'price'           => round( $price, 2 ),
			'dist_between'    => round( $dist_between, 2 ),
			'distance'        => round( $total_distance, 2 ),
			'garage_to_start' => round( $dist_to_start, 2 )
		] );
		wp_die();
	}

	public function save_booking() {
		global $wpdb;

		$booking_date = sanitize_text_field( $_POST['booking_date'] );

		// Check how many bookings exist for this date
		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table_name} WHERE booking_date = %s", $booking_date )
		);


		if ( $count >= 2 ) {
			echo json_encode( [
				'success' => false,
				'message' => 'Maximum 2 bookings allowed per day. Please choose another date.'
			] );
			wp_die();
		}

		// Proceed to insert if allowed
		$wpdb->insert( $this->table_name, [
			'booking_date'   => $booking_date,
			'start_location' => sanitize_text_field( $_POST['start_location'] ),
			'end_location'   => sanitize_text_field( $_POST['end_location'] ),
			'price'          => sanitize_text_field( $_POST['price'] ),
			'distance'       => sanitize_text_field( $_POST['distance'] ),
			'name'           => sanitize_text_field( $_POST['name'] ),
			'phone'          => sanitize_text_field( $_POST['phone'] ),
			'email'          => sanitize_email( $_POST['email'] ),
			'created_at'     => current_time( 'mysql' )
		] );

		echo json_encode( [ 'success' => true ] );
		wp_die();
	}

	public function get_booking_counts() {
		global $wpdb;

		$results = $wpdb->get_results( "
        SELECT booking_date, COUNT(*) as count 
        FROM {$this->table_name} 
        GROUP BY booking_date
    " );

		$data = [];
		foreach ( $results as $row ) {
			$data[ $row->booking_date ] = (int) $row->count;
		}

		echo json_encode( $data );
		wp_die();
	}

	public function get_fully_booked_dates() {
		global $wpdb;

		$results = $wpdb->get_col( "
        SELECT booking_date 
        FROM {$this->table_name} 
        GROUP BY booking_date 
        HAVING COUNT(*) >= 2
    " );

		echo json_encode( $results );
		wp_die();
	}

	public function register_admin_menu() {
		add_menu_page( 'Car Bookings', 'Car Bookings', 'manage_options', 'cbs-bookings', [
			$this,
			'render_admin_page'
		], 'dashicons-car', 25 );
		add_submenu_page( 'cbs-bookings', 'Manage Locations', 'Locations', 'manage_options', 'cbs-locations', [
			$this,
			'render_locations_page'
		] );
	}

	public function render_admin_page() {
		global $wpdb;
		$results = $wpdb->get_results( "SELECT * FROM {$this->table_name} ORDER BY created_at DESC" );

		echo '<div class="wrap"><h1>Car Bookings</h1>';
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Date</th><th>Start</th><th>End</th><th>Distance (km)</th><th>Price</th><th>Name</th><th>Phone</th><th>Email</th><th>Created</th></tr></thead><tbody>';
		foreach ( $results as $r ) {
			echo "<tr><td>{$r->id}</td><td>{$r->booking_date}</td><td>{$r->start_location}</td><td>{$r->end_location}</td><td>{$r->distance}</td><td>{$r->price} ৳ </td><td>{$r->name}</td><td>{$r->phone}</td><td>{$r->email}</td><td>{$r->created_at}</td></tr>";
		}
		echo '</tbody></table></div>';
	}

	public function render_locations_page() {
		if ( isset( $_POST['cbs_locations'] ) && current_user_can( 'manage_options' ) ) {
			check_admin_referer( 'cbs_save_locations' );
			$new_locations = json_decode( stripslashes( $_POST['cbs_locations'] ), true );
			update_option( $this->locations_option_key, $new_locations );
			echo '<div class="updated"><p>Locations updated.</p></div>';
		}

		$locations = $this->get_locations();
		?>
        <div class="wrap">
            <h1>Manage Locations</h1>
            <form method="post">
				<?php wp_nonce_field( 'cbs_save_locations' ); ?>
                <textarea name="cbs_locations" rows="15"
                          style="width:100%; font-family:monospace;"><?php echo esc_textarea( json_encode( $locations, JSON_PRETTY_PRINT ) ); ?></textarea>
                <p class="submit"><input type="submit" class="button-primary" value="Save Locations"></p>
            </form>
        </div>
		<?php
	}

	private function haversine( $lat1, $lon1, $lat2, $lon2, $earthRadius = 6371 ) {
		$lat1     = deg2rad( $lat1 );
		$lon1     = deg2rad( $lon1 );
		$lat2     = deg2rad( $lat2 );
		$lon2     = deg2rad( $lon2 );
		$latDelta = $lat2 - $lat1;
		$lonDelta = $lon2 - $lon1;

		$angle = 2 * asin( sqrt( pow( sin( $latDelta / 2 ), 2 ) +
		                         cos( $lat1 ) * cos( $lat2 ) * pow( sin( $lonDelta / 2 ), 2 ) ) );

		return $angle * $earthRadius;
	}
}

new CarBookingSystem();
