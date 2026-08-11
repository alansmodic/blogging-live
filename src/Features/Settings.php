<?php
/**
 * Plugin settings.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\Feature;

defined( 'ABSPATH' ) || exit;

final class Settings implements Feature {
	public const OPTION = 'blogging_live_settings';

	/**
	 * @var array<string, int|bool>
	 */
	private const DEFAULTS = [
		'entries_per_page' => 10,
		'poll_interval'    => 15,
		'auto_append'      => true,
		'touch_host'       => true,
	];

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public static function add_defaults(): void {
		add_option( self::OPTION, self::DEFAULTS );
	}

	public static function get( string $key ): int|bool|null {
		$settings = wp_parse_args( get_option( self::OPTION, [] ), self::DEFAULTS );
		return $settings[ $key ] ?? null;
	}

	public function add_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostTypes::LIVEBLOG_POST_TYPE,
			__( 'Blogging Live settings', 'blogging-live' ),
			__( 'Settings', 'blogging-live' ),
			'manage_options',
			'blogging-live-settings',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			'blogging_live',
			self::OPTION,
			[
				'type'              => 'object',
				'default'           => self::DEFAULTS,
				'sanitize_callback' => [ $this, 'sanitize' ],
			]
		);

		add_settings_section( 'blogging_live_general', __( 'General behavior', 'blogging-live' ), '__return_false', 'blogging-live-settings' );

		$this->add_number_field( 'entries_per_page', __( 'Entries per request', 'blogging-live' ), 1, 50 );
		$this->add_number_field( 'poll_interval', __( 'Polling interval in seconds', 'blogging-live' ), 5, 300 );
		$this->add_checkbox_field( 'auto_append', __( 'Append liveblogs to host content automatically', 'blogging-live' ) );
		$this->add_checkbox_field( 'touch_host', __( 'Update the host post modified time when an entry changes', 'blogging-live' ) );
	}

	/**
	 * @param mixed $value Untrusted option value.
	 * @return array<string, int|bool>
	 */
	public function sanitize( mixed $value ): array {
		$value = is_array( $value ) ? $value : [];

		return [
			'entries_per_page' => max( 1, min( 50, absint( $value['entries_per_page'] ?? self::DEFAULTS['entries_per_page'] ) ) ),
			'poll_interval'    => max( 5, min( 300, absint( $value['poll_interval'] ?? self::DEFAULTS['poll_interval'] ) ) ),
			'auto_append'      => ! empty( $value['auto_append'] ),
			'touch_host'       => ! empty( $value['touch_host'] ),
		];
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Blogging Live settings', 'blogging-live' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'blogging_live' );
				do_settings_sections( 'blogging-live-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	private function add_number_field( string $key, string $label, int $min, int $max ): void {
		add_settings_field(
			$key,
			$label,
			static function () use ( $key, $min, $max ): void {
				printf(
					'<input class="small-text" type="number" min="%1$s" max="%2$s" name="%3$s[%4$s]" value="%5$d">',
					esc_attr( (string) $min ),
					esc_attr( (string) $max ),
					esc_attr( self::OPTION ),
					esc_attr( $key ),
					absint( self::get( $key ) )
				);
			},
			'blogging-live-settings',
			'blogging_live_general'
		);
	}

	private function add_checkbox_field( string $key, string $label ): void {
		add_settings_field(
			$key,
			$label,
			static function () use ( $key ): void {
				printf(
					'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
					esc_attr( self::OPTION ),
					esc_attr( $key ),
					checked( (bool) self::get( $key ), true, false ),
					esc_html__( 'Enabled', 'blogging-live' )
				);
			},
			'blogging-live-settings',
			'blogging_live_general'
		);
	}
}
