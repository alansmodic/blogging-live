<?php
/**
 * Editorial meta boxes and list-table additions.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\Feature;
use BloggingLive\Repositories\LiveblogRepository;
use DateTimeImmutable;
use DateTimeZone;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class Admin implements Feature {
	private bool $updating_entry_parent = false;

	public function __construct( private readonly LiveblogRepository $liveblogs ) {}

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_host' ], 10, 2 );
		add_action( 'save_post_' . PostTypes::LIVEBLOG_POST_TYPE, [ $this, 'save_liveblog' ], 10, 2 );
		add_action( 'save_post_' . PostTypes::ENTRY_POST_TYPE, [ $this, 'save_entry' ], 10, 2 );
		add_filter( 'manage_' . PostTypes::LIVEBLOG_POST_TYPE . '_posts_columns', [ $this, 'blogging_live_columns' ] );
		add_action( 'manage_' . PostTypes::LIVEBLOG_POST_TYPE . '_posts_custom_column', [ $this, 'render_blogging_live_column' ], 10, 2 );
		add_filter( 'manage_' . PostTypes::ENTRY_POST_TYPE . '_posts_columns', [ $this, 'entry_columns' ] );
		add_action( 'manage_' . PostTypes::ENTRY_POST_TYPE . '_posts_custom_column', [ $this, 'render_entry_column' ], 10, 2 );
		add_filter( 'post_updated_messages', [ $this, 'messages' ] );
	}

	public function add_meta_boxes(): void {
		foreach ( PostTypes::host_post_types() as $post_type ) {
			add_meta_box(
				'blogging-live-host',
				__( 'Liveblog', 'blogging-live' ),
				[ $this, 'render_host_box' ],
				$post_type,
				'side',
				'high'
			);
		}

		add_meta_box(
			'blogging-live-settings',
			__( 'Liveblog details', 'blogging-live' ),
			[ $this, 'render_blogging_live_box' ],
			PostTypes::LIVEBLOG_POST_TYPE,
			'side',
			'high'
		);

		add_meta_box(
			'blogging-live-entry',
			__( 'Update details', 'blogging-live' ),
			[ $this, 'render_entry_box' ],
			PostTypes::ENTRY_POST_TYPE,
			'side',
			'high'
		);
	}

	public function render_host_box( WP_Post $post ): void {
		$enabled  = (bool) get_post_meta( $post->ID, '_blogging_live_enabled', true );
		$liveblog = $this->liveblogs->find_for_host( $post->ID );
		wp_nonce_field( 'blogging_live_save_host', 'blogging_live_host_nonce' );
		?>
		<p>
			<label>
				<input type="checkbox" name="blogging_live_enabled" value="1" <?php checked( $enabled ); ?>>
				<?php esc_html_e( 'Enable liveblogging for this content', 'blogging-live' ); ?>
			</label>
		</p>
		<?php if ( $liveblog ) : ?>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( get_edit_post_link( $liveblog->ID ) ?? '' ); ?>">
					<?php esc_html_e( 'Manage liveblog', 'blogging-live' ); ?>
				</a>
				<a class="button button-secondary" href="<?php echo esc_url( $this->new_entry_url( $liveblog->ID ) ); ?>">
					<?php esc_html_e( 'Add update', 'blogging-live' ); ?>
				</a>
			</p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'A liveblog container will be created when this post is saved.', 'blogging-live' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_blogging_live_box( WP_Post $post ): void {
		$status_meta = (string) get_post_meta( $post->ID, '_blogging_live_status', true );
		$order_meta  = (string) get_post_meta( $post->ID, '_blogging_live_order', true );
		$status      = $status_meta ? $status_meta : 'scheduled';
		$order       = $order_meta ? $order_meta : 'desc';
		$refresh     = absint( get_post_meta( $post->ID, '_blogging_live_refresh_interval', true ) );
		$host_id     = absint( $post->post_parent );
		$start       = $this->utc_to_local_input( (string) get_post_meta( $post->ID, '_blogging_live_start_gmt', true ) );
		$end         = $this->utc_to_local_input( (string) get_post_meta( $post->ID, '_blogging_live_end_gmt', true ) );
		wp_nonce_field( 'blogging_live_save_liveblog', 'blogging_live_blogging_live_nonce' );
		?>
		<p>
			<label for="blogging-live-status"><strong><?php esc_html_e( 'Event status', 'blogging-live' ); ?></strong></label><br>
			<select class="widefat" id="blogging-live-status" name="blogging_live_status">
				<option value="scheduled" <?php selected( $status, 'scheduled' ); ?>><?php esc_html_e( 'Scheduled', 'blogging-live' ); ?></option>
				<option value="live" <?php selected( $status, 'live' ); ?>><?php esc_html_e( 'Live', 'blogging-live' ); ?></option>
				<option value="ended" <?php selected( $status, 'ended' ); ?>><?php esc_html_e( 'Ended', 'blogging-live' ); ?></option>
			</select>
		</p>
		<p>
			<label for="blogging-live-start"><strong><?php esc_html_e( 'Coverage starts', 'blogging-live' ); ?></strong></label><br>
			<input class="widefat" id="blogging-live-start" type="datetime-local" name="blogging_live_start" value="<?php echo esc_attr( $start ); ?>">
		</p>
		<p>
			<label for="blogging-live-end"><strong><?php esc_html_e( 'Coverage ends', 'blogging-live' ); ?></strong></label><br>
			<input class="widefat" id="blogging-live-end" type="datetime-local" name="blogging_live_end" value="<?php echo esc_attr( $end ); ?>">
		</p>
		<p>
			<label for="blogging-live-order"><strong><?php esc_html_e( 'Display order', 'blogging-live' ); ?></strong></label><br>
			<select class="widefat" id="blogging-live-order" name="blogging_live_order">
				<option value="desc" <?php selected( $order, 'desc' ); ?>><?php esc_html_e( 'Newest first', 'blogging-live' ); ?></option>
				<option value="asc" <?php selected( $order, 'asc' ); ?>><?php esc_html_e( 'Oldest first', 'blogging-live' ); ?></option>
			</select>
		</p>
		<p>
			<label for="blogging-live-refresh"><strong><?php esc_html_e( 'Polling interval', 'blogging-live' ); ?></strong></label><br>
			<input class="small-text" id="blogging-live-refresh" type="number" min="0" max="300" name="blogging_live_refresh" value="<?php echo esc_attr( (string) $refresh ); ?>"> <?php esc_html_e( 'seconds', 'blogging-live' ); ?>
			<br><span class="description"><?php esc_html_e( 'Use 0 to inherit the global setting.', 'blogging-live' ); ?></span>
		</p>
		<?php if ( $host_id ) : ?>
			<p><a href="<?php echo esc_url( get_edit_post_link( $host_id ) ?? '' ); ?>"><?php esc_html_e( 'Edit host content', 'blogging-live' ); ?></a></p>
		<?php endif; ?>
		<p><a class="button button-primary" href="<?php echo esc_url( $this->new_entry_url( $post->ID ) ); ?>"><?php esc_html_e( 'Add liveblog update', 'blogging-live' ); ?></a></p>
		<?php
	}

	public function render_entry_box( WP_Post $post ): void {
		$selected_id = absint( $post->post_parent );
		if ( ! $selected_id && isset( $_GET['blogging_live_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$selected_id = absint( wp_unslash( $_GET['blogging_live_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$liveblogs = get_posts(
			[
				'post_type'        => PostTypes::LIVEBLOG_POST_TYPE,
				'post_status'      => [ 'publish', 'draft', 'private', 'pending', 'future' ],
				'posts_per_page'   => 100,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			]
		);

		wp_nonce_field( 'blogging_live_save_entry', 'blogging_live_entry_nonce' );
		?>
		<p>
			<label for="blogging-live-parent"><strong><?php esc_html_e( 'Liveblog', 'blogging-live' ); ?></strong></label><br>
			<select class="widefat" id="blogging-live-parent" name="blogging_live_parent" required>
				<option value=""><?php esc_html_e( 'Select a liveblog', 'blogging-live' ); ?></option>
				<?php foreach ( $liveblogs as $liveblog ) : ?>
					<option value="<?php echo esc_attr( (string) $liveblog->ID ); ?>" <?php selected( $selected_id, $liveblog->ID ); ?>>
						<?php echo esc_html( $liveblog->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label><input type="checkbox" name="blogging_live_is_pinned" value="1" <?php checked( (bool) get_post_meta( $post->ID, '_blogging_live_is_pinned', true ) ); ?>> <?php esc_html_e( 'Pin this update', 'blogging-live' ); ?></label>
		</p>
		<p>
			<label><input type="checkbox" name="blogging_live_is_key_event" value="1" <?php checked( (bool) get_post_meta( $post->ID, '_blogging_live_is_key_event', true ) ); ?>> <?php esc_html_e( 'Mark as a key event', 'blogging-live' ); ?></label>
		</p>
		<?php
	}

	public function save_host( int $post_id, WP_Post $post ): void {
		if ( ! in_array( $post->post_type, PostTypes::host_post_types(), true ) || ! $this->can_save( $post_id, 'blogging_live_host_nonce', 'blogging_live_save_host' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by can_save() above.
		$enabled = isset( $_POST['blogging_live_enabled'] );
		update_post_meta( $post_id, '_blogging_live_enabled', $enabled );

		if ( $enabled && current_user_can( 'create_blogging_lives' ) ) {
			$blogging_live_id = $this->liveblogs->create_for_host( $post_id );
			if ( ! is_wp_error( $blogging_live_id ) && 'publish' === $post->post_status && 'publish' !== get_post_status( $blogging_live_id ) ) {
				wp_update_post(
					[
						'ID'          => $blogging_live_id,
						'post_status' => 'publish',
					]
				);
			}
		}
	}

	public function save_liveblog( int $post_id, WP_Post $post ): void {
		unset( $post );

		if ( ! $this->can_save( $post_id, 'blogging_live_blogging_live_nonce', 'blogging_live_save_liveblog' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by can_save() above.
		$status = sanitize_key( wp_unslash( $_POST['blogging_live_status'] ?? 'scheduled' ) );
		$order  = sanitize_key( wp_unslash( $_POST['blogging_live_order'] ?? 'desc' ) );
		update_post_meta( $post_id, '_blogging_live_status', in_array( $status, [ 'scheduled', 'live', 'ended' ], true ) ? $status : 'scheduled' );
		update_post_meta( $post_id, '_blogging_live_order', in_array( $order, [ 'asc', 'desc' ], true ) ? $order : 'desc' );
		update_post_meta( $post_id, '_blogging_live_refresh_interval', min( 300, absint( $_POST['blogging_live_refresh'] ?? 0 ) ) );
		update_post_meta( $post_id, '_blogging_live_start_gmt', $this->local_input_to_utc( sanitize_text_field( wp_unslash( $_POST['blogging_live_start'] ?? '' ) ) ) );
		update_post_meta( $post_id, '_blogging_live_end_gmt', $this->local_input_to_utc( sanitize_text_field( wp_unslash( $_POST['blogging_live_end'] ?? '' ) ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public function save_entry( int $post_id, WP_Post $post ): void {
		if ( $this->updating_entry_parent || ! $this->can_save( $post_id, 'blogging_live_entry_nonce', 'blogging_live_save_entry' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by can_save() above.
		$parent_id = absint( $_POST['blogging_live_parent'] ?? 0 );
		if ( $parent_id && $this->liveblogs->find( $parent_id ) && absint( $post->post_parent ) !== $parent_id ) {
			$this->updating_entry_parent = true;
			wp_update_post(
				[
					'ID'          => $post_id,
					'post_parent' => $parent_id,
				]
			);
			$this->updating_entry_parent = false;
		}

		update_post_meta( $post_id, '_blogging_live_is_pinned', isset( $_POST['blogging_live_is_pinned'] ) );
		update_post_meta( $post_id, '_blogging_live_is_key_event', isset( $_POST['blogging_live_is_key_event'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! get_post_meta( $post_id, '_blogging_live_author_ids', true ) ) {
			update_post_meta( $post_id, '_blogging_live_author_ids', [ absint( $post->post_author ) ] );
		}
	}

	/**
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function blogging_live_columns( array $columns ): array {
		$columns['blogging_live_status'] = __( 'Event status', 'blogging-live' );
		$columns['blogging_live_host']   = __( 'Host content', 'blogging-live' );
		return $columns;
	}

	public function render_blogging_live_column( string $column, int $post_id ): void {
		if ( 'blogging_live_status' === $column ) {
			$status = (string) get_post_meta( $post_id, '_blogging_live_status', true );
			echo esc_html( ucfirst( $status ? $status : 'scheduled' ) );
		}

		if ( 'blogging_live_host' === $column ) {
			$host_id = $this->liveblogs->host_id( $post_id );
			echo $host_id ? '<a href="' . esc_url( get_edit_post_link( $host_id ) ?? '' ) . '">' . esc_html( get_the_title( $host_id ) ) . '</a>' : '&mdash;';
		}
	}

	/**
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function entry_columns( array $columns ): array {
		$columns['blogging_live_parent'] = __( 'Liveblog', 'blogging-live' );
		$columns['blogging_live_flags']  = __( 'Flags', 'blogging-live' );
		return $columns;
	}

	public function render_entry_column( string $column, int $post_id ): void {
		if ( 'blogging_live_parent' === $column ) {
			$entry     = get_post( $post_id );
			$parent_id = $entry ? absint( $entry->post_parent ) : 0;
			echo $parent_id ? '<a href="' . esc_url( get_edit_post_link( $parent_id ) ?? '' ) . '">' . esc_html( get_the_title( $parent_id ) ) . '</a>' : '&mdash;';
		}

		if ( 'blogging_live_flags' === $column ) {
			$flags = [];
			if ( get_post_meta( $post_id, '_blogging_live_is_pinned', true ) ) {
				$flags[] = __( 'Pinned', 'blogging-live' );
			}
			if ( get_post_meta( $post_id, '_blogging_live_is_key_event', true ) ) {
				$flags[] = __( 'Key event', 'blogging-live' );
			}
			$flag_text = implode( ', ', $flags );
			echo esc_html( $flag_text ? $flag_text : '—' );
		}
	}

	/**
	 * @param array<string, array<int, string>> $messages Messages by post type.
	 * @return array<string, array<int, string>>
	 */
	public function messages( array $messages ): array {
		$messages[ PostTypes::ENTRY_POST_TYPE ][1]    = __( 'Liveblog update saved.', 'blogging-live' );
		$messages[ PostTypes::LIVEBLOG_POST_TYPE ][1] = __( 'Liveblog saved.', 'blogging-live' );
		return $messages;
	}

	private function can_save( int $post_id, string $nonce_field, string $action ): bool {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return false;
		}

		if ( empty( $_POST[ $nonce_field ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), $action ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	private function new_entry_url( int $blogging_live_id ): string {
		return add_query_arg(
			[
				'post_type'        => PostTypes::ENTRY_POST_TYPE,
				'blogging_live_id' => $blogging_live_id,
			],
			admin_url( 'post-new.php' )
		);
	}

	private function local_input_to_utc( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$date = DateTimeImmutable::createFromFormat( 'Y-m-d\\TH:i', $value, wp_timezone() );
		return $date ? $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : '';
	}

	private function utc_to_local_input( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return $date ? $date->setTimezone( wp_timezone() )->format( 'Y-m-d\\TH:i' ) : '';
	}
}
