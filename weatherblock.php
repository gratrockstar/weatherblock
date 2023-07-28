<?php
/**
 * Plugin Name:       Weatherblock
 * Description:       Show the current weather for a location in a block.
 * Requires at least: 6.0
 * Requires PHP:      7.0
 * Version:           1.0.0
 * Author:            Garrett Baldwin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       weatherblock
 *
 * @package           weatherblock
 */

namespace WeatherblockPlugin;

if( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! class_exists( 'WeatherblockPlugin' ) ) {

	class WeatherblockPlugin {

		/**
		 * Plugin instance.
		 *
		 * @see get_instance()
		 * @var object
		 */
		protected static $instance = NULL;

		/**
		 * Plugin version
		 *
		 * @var string
		 */
		public $version;

		/**
		 * API Key Option key.
		 *
		 * @var string
		 */
		public $api_key_option;

		/**
		 * Get Instance.
		 *
		 * @return $instance
		 * @author Garrett Baldwin <garrett.baldwin@webdevstudios.com>
		 * @since  2023-04-28
		 */
		public static function get_instance() {
			NULL === self::$instance and self::$instance = new self;
			return self::$instance;
		}

		/**
		 * Constructor.
		 *
		 * @return void
		 * @author Garrett Baldwin <garrett.baldwin@webdevstudios.com>
		 * @since  2023-04-28
		 */
		public function __construct() {
			if( ! function_exists('get_plugin_data') ){
				require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
			}
			$plugin_data = \get_plugin_data( __FILE__ );
			$this->version = $plugin_data['Version'];
			$this->api_key_option = 'weatherblock_api_key';
		}

		/**
		 * Setup plugin hooks.
		 *
		 * @return void
		 * @author Garrett Baldwin <garrett.baldwin@webdevstudios.com>
		 * @since  2023-04-28
		 */
		public function plugin_setup() {

			// add settings page
			add_action('admin_menu', array( $this, 'add_plugin_admin_menu' ) );

			// set up our settings fields
			add_action( 'admin_init', [ $this, '_weatherblock_add_settings_fields' ] );

			// register the block.
			add_action( 'init', [ $this, 'create_block_weatherblock_block_init' ] );

			// register the REST API endpoint.
			add_action('rest_api_init', [ $this, 'create_weatherblock_rest_endpoint' ]);
		}

		/**
		 * Hook into admin_menu and add our item.
		 *
		 * @return void
		 * @author Garrett Baldwin <garrett.baldwin@webdevstudios.com>
		 * @since  2023-04-28
		 */
		function add_plugin_admin_menu() {
			add_submenu_page(
				'options-general.php',
				__( 'Weatherblock Settings', 'weatherblock' ),
				__( 'Weatherblock', 'weatherblock' ),
				'edit_pages',
				'weatherblock',
				[ $this, '_weatherblock_options_page' ],
			);
		}

		/**
		 * Callback to render the settings page.
		 *
		 * @return void
		 * @author Garrett Baldwin <garrett.baldwin@webdevstudios.com>
		 * @since  2023-04-28
		 */
		public function _weatherblock_options_page() {
			?>
			<div class="wrap">
        <h1><?php echo get_admin_page_title() ?></h1>
				<form method="post" action="options.php">
				<?php
					settings_fields( 'weatherblock_settings' ); // settings group name
					do_settings_sections( 'weatherblock' ); // just a page slug
					submit_button(); // "Save Changes" button
				?>
			</form>
    </div>
			<?php
		}

		public function _weatherblock_add_settings_fields() {

			$options_group = 'weatherblock_settings';

			add_settings_section(
				$this->api_key_option,
				__( 'Weatherapi.com API Key', 'weatherblock' ),
				'',
				'weatherblock',
				'weatherblock',
			);

			 register_setting(
				$options_group,
				$this->api_key_option,
				[],
			);

			add_settings_field(
				$this->api_key_option,
				__( 'API Key:', 'weatherblock' ),
				[ $this, '_weatherblock_api_key_field' ],
				'weatherblock',
				'weatherblock_api_key',
				[],
			);

		}

		public function _weatherblock_api_key_field() {
			$value = get_option( $this->api_key_option );
			echo '<input class="regular-text" id="weatherblock_api_key" name="weatherblock_api_key" type="text" value="' . $value . '">';
		}

		/**
		 * Registers the block using the metadata loaded from the `block.json` file.
		 * Behind the scenes, it registers also all assets so they can be enqueued
		 * through the block editor in the corresponding context.
		 *
		 * @see https://developer.wordpress.org/reference/functions/register_block_type/
		 *
		 * @return void
		 * @author Garrett Baldwin <garrett.baldwin@webdevstudios.com>
		 * @since  2023-04-28
		 */
		public function create_block_weatherblock_block_init() {

			// check for API Key before registering block.
			$api_key = get_option( $this->api_key_option );

			if ( $api_key && '' !== $api_key ) {
				register_block_type( __DIR__ . '/build',
					[
						'render_callback' => [ $this, 'weatherblock_render_weatherblock' ]
					]
				);
			}
		}

		/**
		 * Register the REST API endpoint.
		 *
		 * @return void
		 * @author Garrett Baldwin <garrett.baldwin@webdevstudios.com>
		 * @since  2023-04-28
		 */
		public function create_weatherblock_rest_endpoint(){
			register_rest_route( 'weatherblock/v1', '/weatherdata/(?P<id>[a-zA-Z0-9-%]+)', [
				'methods' => \WP_REST_Server::READABLE,
				'callback' => [ $this, '_weatherblock_get_weatherdata' ],
				'permission_callback' => '__return_true'
			], false );
		}

		/**
		 * Helper to get our data.
		 *
		 * @param array $data  Data to
		 * @return void
		 * @author Garrett Baldwin <garrett.baldwin@webdevstudios.com>
		 * @since  2023-03-31
		 */
		public function _weatherblock_get_weatherdata( $data ) {

			$location = $data['id'];

			$transient_id = 'weatherblock_data_' . $location;

			// api variables.
			$api_key = get_option( 'weatherblock_api_key' );
			$api_url = 'https://api.weatherapi.com/v1/';
			$forecast = 'forecast.json';

			// build api url.
			$api_url = add_query_arg(
				[
					'key' => $api_key,
					'aqi' => 'no',
					'days' => 1,
					'alerts' => 'no',
					'q' => $location,
				],
				$api_url . $forecast
			);

			if ( false === ( $data = get_transient( $transient_id ) ) )  {

				// make api call.
				$response = wp_remote_get( $api_url );

				if ( ! is_wp_error( $response ) ) {

					$data = wp_remote_retrieve_body( $response );

					// set transient for 15 mins if there's no error.
					if ( ! isset( $data->error ) ) {
						set_transient( $transient_id, $data, MINUTE_IN_SECONDS * 15 );
					}

				}

				// return error.
				else {
					return $response;
				}

			}

			return $data;

		}

		/**
		 * Block render callback.
		 *
		 * @param array  $attributes  Saved block attributes.
		 * @param array  $content  Block content.
		 * @param array  $block		Block
		 * @return string
		 * @author Garrett Baldwin <garrett.baldwin@webdevstudios.com>
		 * @since  2023-03-06
		 */
		public function weatherblock_render_weatherblock($attributes, $content, $block) {

			// get location from attributes.
			$location = $attributes['location'] ? $attributes['location'] : 'Los Angeles';

			$units = [
				'imperial' => [
					'tempunit' => 'f',
					'speedunit' => 'mph',
				],
				'metric' => [
					'tempunit' => 'c',
					'speedunit' => 'kph',
				],
			];

			// get our units.
			$tempunit = $units[$attributes['measurementunit']]['tempunit'];
			$speedunit = $units[$attributes['measurementunit']]['speedunit'];

			// get the data.
			$response = $this->_weatherblock_get_weatherdata( [ 'id' => $location ] );

			// check for error.
			if ( ! is_wp_error( $response ) ) {

				// json decode data.
				$data = json_decode( $response );

				if ( isset( $data->error ) ) {
					return '<div class="weather-block error">' . $data->error->message . '</div>';
				}

				ob_start();
				?>
				<section class="weather-block">
					<header>
						<h2><?php echo esc_html( $data->location->name ); ?>, <span><?php echo esc_html( $data->location->region ); ?></span></h2>
					</header>
					<div class="today">
						<div class="current-conditions">
							<div class="icon">
								<img src="<?php echo esc_attr( $data->current->condition->icon ); ?>" alt="<?php echo esc_attr( $data->current->condition->text ); ?>" />
							</div>
							<div class="weather-data">
								<p class="current-temp"><?php echo esc_html( round( $data->current->{'temp_' . $tempunit} ) ); ?>&deg;<span><?php echo esc_html( strtoupper( $tempunit ) ); ?></span></p>
								<p class="feels-like"><?php _e( 'Feels like', 'weatherblock' ); ?> <?php echo esc_html( round( $data->current->{'feelslike_' . $tempunit} ) ); ?>&deg;<span><?php echo esc_html( strtoupper( $tempunit ) ); ?></span></p>
							</div>
							<div class="weather-meta">
								<p><?php esc_html_e( 'Precipitation', 'weatherblock' ); ?>: <?php echo esc_html( $data->forecast->forecastday[0]->day->daily_chance_of_rain ); ?>%</p>
								<p><?php esc_html_e( 'Humidity', 'weatherblock' ); ?>: <?php echo esc_html( $data->current->humidity ); ?>%</p>
								<p><?php esc_html_e( 'Wind', 'weatherblock' ); ?>: <?php echo esc_html( $data->current->{ 'wind_' . $speedunit } ); ?><?php esc_html_e( $speedunit ); ?></p>
							</div>
							<div class="weather-datetime">
								<p class="last-updated-date">
										<?php echo esc_html( $this->_weatherblock_format_last_updated_date( $data->current->last_updated_epoch, $data->location->tz_id ) ); ?></p>
								<p class="last-updated-time">
									<?php echo esc_html( $this->_weatherblock_format_last_updated_time( $data->current->last_updated_epoch, $data->location->tz_id ) ); ?>
								</p>
								<p><?php echo esc_html( $data->current->condition->text ); ?></p>
							</div>
						</div>
					</div>
					<?php if ( $attributes['showHourly'] ) : ?>
					<div class="forecast">
						<h3><?php esc_html_e( 'Hourly', 'weatherblock' ); ?></h3>
						<ul>
						<?php foreach ( $data->forecast->forecastday[0]->hour as $hour ) : ?>
							<?php if ( $hour->time_epoch > \time() ) : ?>
							<li>
								<p class="temp"><?php echo esc_html( round( $hour->{'temp_' . $tempunit} ) ); ?>&deg;<span><?php echo esc_html( strtoupper( $tempunit ) ); ?></span></p>
								<img src="<?php echo esc_url( $hour->condition->icon ); ?>" alt="<?php echo esc_attr( $hour->condition->text ); ?>" />
								<p><?php echo esc_html( $this->_weatherblock_format_last_updated_time( $hour->time_epoch, $data->location->tz_id ) ); ?></p>
							</li>
							<?php endif; ?>
						<?php endforeach; ?>
						</ul>
					</div>
					<?php endif; ?>
				</section>
				<?php
				return ob_get_clean();

			}

			// handle error.
			else {
				ob_start(); ?>
				<p><?php __( 'Sorry, something went wrong with the request.  Please try again later.', 'weatherblock' ); ?></p>
				<?php return ob_get_clean();
			}

		}

		/**
		 * Format the last updated date based on the timezone of the returned location.
		 *
		 * @param int    $last_updated
		 * @param string $location_timezone
		 * @return string
		 * @author Garrett Baldwin <garrett.baldwin@webdevstudios.com>
		 * @since  2023-03-06
		 */
		private function _weatherblock_format_last_updated_date( $last_updated, $location_timezone ) {

			$time = new \DateTime( 'now', new \DateTimeZone( $location_timezone ) );

			$time->setTimestamp( $last_updated );

			$format = apply_filters( 'weatherblock_date_format', 'D F j, Y' );

			return $time->format( $format );

		}

		/**
		 * Format the last updated timestamp based on the timezone of the returned location.
		 *
		 * @param int    $last_updated
		 * @param string $location_timezone
		 * @return string
		 * @author Garrett Baldwin <garrett.baldwin@webdevstudios.com>
		 * @since  2023-03-06
		 */
		private function _weatherblock_format_last_updated_time( $last_updated, $location_timezone ) {

			$time = new \DateTime( 'now', new \DateTimeZone( $location_timezone ) );

			$time->setTimestamp( $last_updated );

			$format = apply_filters( 'weatherblock_date_format', 'g:i A' );

			return $time->format( $format );

		}

	}
	add_action( 'plugins_loaded', [ WeatherblockPlugin::get_instance(), 'plugin_setup' ] );

}

