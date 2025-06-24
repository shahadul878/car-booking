<?php
/**
 * Plugin Name: Car Booking System
 * Description: Car rental quote and booking plugin with distance-based pricing, weekend and urgent booking charges.
 * Version: 1.2
 * Author: Shahadul Islam
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CarBookingSystem {

	private $table_name;
	private $garage_address_option = 'cbs_garage_address';
	private $garage_lat_option = 'cbs_garage_lat';
	private $garage_lng_option = 'cbs_garage_lng';
	private $location_type_option = 'cbs_location_type';
	private $gomaps_api_key_option = 'cbs_gomaps_api_key';
	private $locations_option_key = 'cbs_locations';
	private $booking_limit_per_day = 'cbs_max_bookings_per_day';
	private $garage_to_pickup_rate_option = 'cbs_rate_garage_to_pickup'; // 10 BDT/km
	private $pickup_to_drop_rate_option = 'cbs_rate_pickup_to_drop';     // 15 BDT/km
	private $urgent_surcharge_option = 'cbs_urgent_surcharge';           // 20 (%)
	private $weekend_surcharge_option = 'cbs_weekend_surcharge';         // 5 (%)
	private $minimum_price_option = 'cbs_minimum_price';                 // 85 BDT
	private $CBS_VERSION = '1.2';


	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'car_bookings';

		register_activation_hook( __FILE__, [ $this, 'create_booking_table' ] );
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
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
		add_action( 'wp_ajax_cbs_places_autocomplete', [ $this, 'ajax_places_autocomplete' ] );
		add_action( 'wp_ajax_nopriv_cbs_places_autocomplete', [ $this, 'ajax_places_autocomplete' ] );
		add_action( 'admin_init', [ $this, 'handle_delete_booking' ] );
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
		if ( get_option( 'cbs_max_bookings_per_day' ) === false ) {
			update_option( 'cbs_max_bookings_per_day', 2 );
		}
	}

	public function enqueue_scripts() {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr', [], null, true );
		wp_enqueue_script( 'cbs-script', plugin_dir_url( __FILE__ ) . 'assets/js/cbs-script.js', [ 'jquery' ], $this->CBS_VERSION, true );
		wp_enqueue_style( 'flatpickr-style', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css' );
		wp_enqueue_style( 'cbs-style', plugin_dir_url( __FILE__ ) . 'assets/css/cbs-style.css',[],$this->CBS_VERSION, 'all' );
		wp_localize_script( 'cbs-script', 'cbs_ajax', [
			'ajax_url' => admin_url( 'admin-ajax.php' )
		] );
	}

	public function render_booking_form() {
		$locations     = $this->get_locations();
		$location_type = get_option( $this->location_type_option, 'manual' );
		ob_start(); ?>
        <div class="cbs-booking-wrapper">
            <h2>Car Booking System</h2>
            <form id="car-booking-form">
                <label for="booking-date">Select Booking Date:</label>
                <input type="text" id="booking-date" name="booking_date" class="cbs-input" placeholder="YYYY-MM-DD"
                       required>

				<?php if ( $location_type === 'google' ) : ?>
                    <label for="start-location">Start Location:</label>
                    <input type="text" id="start-location" name="start_location" class="cbs-input"
                           placeholder="Enter start address" autocomplete="off" required>

                    <label for="end-location">End Location:</label>
                    <input type="text" id="end-location" name="end_location" class="cbs-input"
                           placeholder="Enter end address" autocomplete="off" required>
				<?php else : ?>
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
				<?php endif; ?>

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
		$locations       = $this->get_locations();
		$start           = sanitize_text_field( $_POST['start_location'] );
		$end             = sanitize_text_field( $_POST['end_location'] );
		$booking_date    = sanitize_text_field( $_POST['booking_date'] );
		$date            = new DateTime( $booking_date );
		$now             = new DateTime();
		$urgent          = false;
		$weekend         = false;
		$location_type   = get_option( $this->location_type_option, 'manual' );
		$garage_address  = get_option( $this->garage_address_option, 'Adabor Thana Bus stand' );
		$garage_lat      = get_option( $this->garage_lat_option, '23.7745' );
		$garage_lng      = get_option( $this->garage_lng_option, '90.3654' );
		$garage_to_rate  = floatval( get_option( $this->garage_to_pickup_rate_option, 10 ) );
		$pickup_to_rate  = floatval( get_option( $this->pickup_to_drop_rate_option, 15 ) );
		$urgent_percent  = floatval( get_option( $this->urgent_surcharge_option, 20 ) );
		$weekend_percent = floatval( get_option( $this->weekend_surcharge_option, 5 ) );
		$min_price       = floatval( get_option( $this->minimum_price_option, 85 ) );

		if ( $location_type === 'google' ) {
			$garage    = $garage_address;
			$distance1 = $this->get_distance_gomapspro( $garage, $start );
			$distance2 = $this->get_distance_gomapspro( $start, $end );
			if ( $distance1 === false || $distance2 === false ) {
				echo json_encode( [ 'error' => 'Could not get distance from gomaps.pro API.' ] );
				wp_die();
			}
			$dist_to_start = $distance1;
			$dist_between  = $distance2;
		} else {
			if ( ! isset( $locations[ $start ] ) || ! isset( $locations[ $end ] ) ) {
				echo json_encode( [ 'error' => 'Invalid location' ] );
				wp_die();
			}
			$start_coords  = $locations[ $start ];
			$end_coords    = $locations[ $end ];
			$dist_to_start = $this->haversine( $garage_lat, $garage_lng, $start_coords['lat'], $start_coords['lng'] );
			$dist_between  = $this->haversine( $start_coords['lat'], $start_coords['lng'], $end_coords['lat'], $end_coords['lng'] );
		}

		$garage_to_start_charge = $dist_to_start * $garage_to_rate;
		$pickup_to_drop_charge  = $dist_between * $pickup_to_rate;

		$base_price    = $garage_to_start_charge + $pickup_to_drop_charge;
		$original_base = $base_price;

		if ( ( $date->getTimestamp() - $now->getTimestamp() ) < 86400 ) {
			$base_price *= ( 1 + $urgent_percent / 100 );
			$urgent     = true;
		}

		if ( in_array( $date->format( 'N' ), [ 6, 7 ] ) ) {
			$base_price *= ( 1 + $weekend_percent / 100 );
			$weekend    = true;
		}

		if ( $base_price < $min_price ) {
			$base_price = $min_price;
		}

		echo json_encode( [
			'garage_to_start'        => round( $dist_to_start, 2 ),
			'dist_between'           => round( $dist_between, 2 ),
			'distance'               => round( $dist_to_start + $dist_between, 2 ),
			'garage_to_start_charge' => round( $garage_to_start_charge, 2 ),
			'pickup_to_drop_charge'  => round( $pickup_to_drop_charge, 2 ),
			'surcharge_urgent'       => $urgent,
			'surcharge_weekend'      => $weekend,
			'weekend_charge'         => $weekend_percent,
			'urgent_charge'          => $urgent_percent,
			'price_before_minimum'   => round( $base_price, 2 ),
			'final_price'            => round( $base_price, 2 )
		] );
		wp_die();
	}

	// Helper: gomaps.pro API
	private function get_distance_gomapspro( $origin, $destination ) {
		$origin      = urlencode( $origin );
		$destination = urlencode( $destination );
		$api_key     = get_option( $this->gomaps_api_key_option, '' );
		if ( $api_key ) {
			$url      = "https://maps.gomaps.pro/maps/api/distancematrix/json?destinations={$origin}&origins={$destination}&key={$api_key}";
			$response = wp_remote_get( $url );
			if ( is_wp_error( $response ) ) {
				return false;
			}
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			if ( ! isset( $data['rows'][0]['elements'][0]['distance']['value'] ) || ! is_numeric( $data['rows'][0]['elements'][0]['distance']['value'] ) ) {
				return false;
			}

			// Convert meters to kilometers
			return floatval( $data['rows'][0]['elements'][0]['distance']['value'] ) / 1000;
		}
	}

	public function save_booking() {
		global $wpdb;

		$booking_date = sanitize_text_field( $_POST['booking_date'] );

		// Check how many bookings exist for this date
		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table_name} WHERE booking_date = %s", $booking_date )
		);

		$limit = (int) get_option( 'cbs_max_bookings_per_day', 2 );
		if ( $count >= $limit ) {
			echo json_encode( [
				'success' => false,
				'message' => "Maximum {$limit} bookings allowed per day. Please choose another date."
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
		add_submenu_page( 'cbs-bookings', 'Settings', 'Settings', 'manage_options', 'cbs-settings', [
			$this,
			'settings_page'
		] );


	}

	public function render_admin_page() {
		global $wpdb;
		$results = $wpdb->get_results( "SELECT * FROM {$this->table_name} ORDER BY created_at DESC" );
		if ( isset( $_GET['deleted'] ) && $_GET['deleted'] == 1 ) {
			echo '<div class="notice notice-success is-dismissible"><p>Booking deleted successfully.</p></div>';
		}
		echo '<div class="wrap"><h1>Car Bookings</h1>';
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Date</th><th>Start</th><th>End</th><th>Distance (km)</th><th>Price</th><th>Name</th><th>Phone</th><th>Email</th><th>Created</th><th>Action</th></tr></thead><tbody>';
		foreach ( $results as $r ) {
			$delete_url = wp_nonce_url(
				admin_url( 'admin.php?page=cbs-bookings&action=delete_booking&id=' . $r->id ),
				'cbs_delete_booking_' . $r->id
			);
			echo "<tr><td>{$r->id}</td><td>{$r->booking_date}</td><td>{$r->start_location}</td><td>{$r->end_location}</td><td>{$r->distance}</td><td>{$r->price} ৳ </td><td>{$r->name}</td><td>{$r->phone}</td><td>{$r->email}</td><td>{$r->created_at}</td><td><a href='" . esc_url( $delete_url ) . "' class='button button-small' onclick=\"return confirm('Are you sure you want to delete this booking?');\">Delete</a></td></tr>";
		}
		echo '</tbody></table></div>';
	}

	public function handle_delete_booking() {
		if (
			is_admin() &&
			current_user_can( 'manage_options' ) &&
			isset( $_GET['action'], $_GET['id'] ) &&
			$_GET['action'] === 'delete_booking'
		) {
			$booking_id = intval( $_GET['id'] );
			if ( wp_verify_nonce( $_GET['_wpnonce'], 'cbs_delete_booking_' . $booking_id ) ) {
				global $wpdb;
				$wpdb->delete( $this->table_name, [ 'id' => $booking_id ], [ '%d' ] );
				wp_safe_redirect( admin_url( 'admin.php?page=cbs-bookings&deleted=1' ) );
				exit;
			}
		}
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

	public function register_settings() {
		register_setting( 'cbs_settings_group', $this->garage_address_option );
		register_setting( 'cbs_settings_group', $this->garage_lat_option );
		register_setting( 'cbs_settings_group', $this->garage_lng_option );
		register_setting( 'cbs_settings_group', $this->location_type_option );
		register_setting( 'cbs_settings_group', $this->gomaps_api_key_option );
		register_setting( 'cbs_settings_group', $this->booking_limit_per_day );
		register_setting( 'cbs_settings_group', $this->garage_to_pickup_rate_option );
		register_setting( 'cbs_settings_group', $this->pickup_to_drop_rate_option );
		register_setting( 'cbs_settings_group', $this->urgent_surcharge_option );
		register_setting( 'cbs_settings_group', $this->weekend_surcharge_option );
		register_setting( 'cbs_settings_group', $this->minimum_price_option );

	}

	public function settings_page() {
		?>
        <div class="wrap">
            <h1>Car Booking Settings</h1>
            <form method="post" action="options.php">
				<?php settings_fields( 'cbs_settings_group' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Location Type</th>
                        <td>
                            <label><input type="radio" name="<?php echo esc_attr( $this->location_type_option ); ?>"
                                          value="manual" <?php checked( get_option( $this->location_type_option, 'manual' ), 'manual' ); ?>>
                                Manual (Dropdown)</label><br>
                            <label><input type="radio" name="<?php echo esc_attr( $this->location_type_option ); ?>"
                                          value="google" <?php checked( get_option( $this->location_type_option ), 'google' ); ?>>
                                gomaps.pro API</label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Garage Address</th>
                        <td><input type="text" name="<?php echo esc_attr( $this->garage_address_option ); ?>"
                                   value="<?php echo esc_attr( get_option( $this->garage_address_option, 'Adabor Thana Bus stand' ) ); ?>"
                                   class="regular-text"></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Garage Latitude</th>
                        <td><input type="text" name="<?php echo esc_attr( $this->garage_lat_option ); ?>"
                                   value="<?php echo esc_attr( get_option( $this->garage_lat_option, '23.7745' ) ); ?>"
                                   class="regular-text"></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Garage Longitude</th>
                        <td><input type="text" name="<?php echo esc_attr( $this->garage_lng_option ); ?>"
                                   value="<?php echo esc_attr( get_option( $this->garage_lng_option, '90.3654' ) ); ?>"
                                   class="regular-text"></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">gomaps.pro API Key (optional)</th>
                        <td><input type="text" name="<?php echo esc_attr( $this->gomaps_api_key_option ); ?>"
                                   value="<?php echo esc_attr( get_option( $this->gomaps_api_key_option, '' ) ); ?>"
                                   class="regular-text">
                            <p class="description">Leave blank if not required by gomaps.pro.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Max Bookings Per Day</th>
                        <td><input type="number" name="cbs_max_bookings_per_day"
                                   value="<?php echo esc_attr( get_option( 'cbs_max_bookings_per_day', 2 ) ); ?>"
                                   min="1"/></td>
                    </tr>
                    <tr>
                        <th scope="row">Garage to Pickup Rate (BDT/km)</th>
                        <td><input type="number" name="<?php echo esc_attr( $this->garage_to_pickup_rate_option ); ?>"
                                   value="<?php echo esc_attr( get_option( $this->garage_to_pickup_rate_option, 10 ) ); ?>"
                                   step="0.01" class="small-text"/></td>
                    </tr>
                    <tr>
                        <th scope="row">Pickup to Drop Rate (BDT/km)</th>
                        <td><input type="number" name="<?php echo esc_attr( $this->pickup_to_drop_rate_option ); ?>"
                                   value="<?php echo esc_attr( get_option( $this->pickup_to_drop_rate_option, 15 ) ); ?>"
                                   step="0.01" class="small-text"/></td>
                    </tr>
                    <tr>
                        <th scope="row">Urgent Booking Surcharge (%)</th>
                        <td><input type="number" name="<?php echo esc_attr( $this->urgent_surcharge_option ); ?>"
                                   value="<?php echo esc_attr( get_option( $this->urgent_surcharge_option, 20 ) ); ?>"
                                   step="0.01" class="small-text"/></td>
                    </tr>
                    <tr>
                        <th scope="row">Weekend Surcharge (%)</th>
                        <td><input type="number" name="<?php echo esc_attr( $this->weekend_surcharge_option ); ?>"
                                   value="<?php echo esc_attr( get_option( $this->weekend_surcharge_option, 5 ) ); ?>"
                                   step="0.01" class="small-text"/></td>
                    </tr>
                    <tr>
                        <th scope="row">Minimum Price (BDT)</th>
                        <td><input type="number" name="<?php echo esc_attr( $this->minimum_price_option ); ?>"
                                   value="<?php echo esc_attr( get_option( $this->minimum_price_option, 85 ) ); ?>"
                                   step="1" class="small-text"/></td>
                    </tr>

                </table>
				<?php submit_button(); ?>
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

	// AJAX handler for address autocomplete
	public function ajax_places_autocomplete() {
		$q = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';
		if ( strlen( $q ) < 3 ) {
			wp_send_json( [] );
		}
		$api_key = get_option( $this->gomaps_api_key_option, '' );
		if ( $api_key ) {
			$url      = 'https://maps.gomaps.pro/maps/api/place/autocomplete/json?input=' . urlencode( $q ) . '&key=' . urlencode( $api_key );
			$response = wp_remote_get( $url );
			if ( is_wp_error( $response ) ) {
				wp_send_json( [] );
			}
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			if ( ! isset( $data['predictions'] ) || ! is_array( $data['predictions'] ) ) {
				wp_send_json( [] );
			}
			$suggestions = array();
			foreach ( $data['predictions'] as $prediction ) {
				if ( isset( $prediction['description'] ) ) {
					$suggestions[] = $prediction['description'];
				}
			}
			wp_send_json( $suggestions );
		} else {
			// fallback to old gomaps.pro API
			$url      = 'https://gomaps.pro/api/places?q=' . urlencode( $q );
			$response = wp_remote_get( $url );
			if ( is_wp_error( $response ) ) {
				wp_send_json( [] );
			}
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) ) {
				wp_send_json( [] );
			}
			wp_send_json( $data );
		}
	}
}

new CarBookingSystem();
