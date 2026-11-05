<?php
/**
 * Main Blogging Live template.
 *
 * Themes may override this file at blogging-live/blogging-live.php.
 *
 * @package BloggingLive
 *
 * @var array<string, mixed> $data Template data.
 */

defined( 'ABSPATH' ) || exit;

$blogging_live        = $data['blogging_live'];
$entries              = $data['entries'];
$pinned_entries       = $data['pinned'];
$blogging_live_status = $data['status'];
?>
<section
	id="blogging-live-<?php echo esc_attr( (string) $blogging_live->ID ); ?>"
	class="blogging-live blogging-live--<?php echo esc_attr( $blogging_live_status ); ?>"
	data-blogging-live-id="<?php echo esc_attr( (string) $blogging_live->ID ); ?>"
	data-endpoint="<?php echo esc_url( $data['endpoint'] ); ?>"
	data-refresh="<?php echo esc_attr( (string) $data['refresh'] ); ?>"
	data-status="<?php echo esc_attr( $blogging_live_status ); ?>"
	data-order="<?php echo esc_attr( $data['order'] ); ?>"
	data-latest-cursor="<?php echo esc_attr( $data['newest'] ); ?>"
	data-oldest-cursor="<?php echo esc_attr( $data['oldest'] ); ?>"
	data-server-time="<?php echo esc_attr( $data['server_time'] ); ?>"
	data-pinned-limit="<?php echo esc_attr( (string) $data['pinned_limit'] ); ?>"
>
	<?php if ( $data['show_header'] ) : ?>
		<header class="blogging-live__header">
			<div>
				<p class="blogging-live__eyebrow"><?php esc_html_e( 'Live coverage', 'blogging-live' ); ?></p>
				<h2 class="blogging-live__title"><?php echo esc_html( get_the_title( $blogging_live ) ); ?></h2>
			</div>
			<span class="blogging-live__status" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Event status. */ __( 'Blogging Live status: %s', 'blogging-live' ), $data['status_label'] ) ); ?>">
				<?php echo esc_html( $data['status_label'] ); ?>
			</span>
		</header>
	<?php endif; ?>

	<section class="blogging-live__pinned" aria-label="<?php esc_attr_e( 'Pinned updates', 'blogging-live' ); ?>" <?php echo empty( $pinned_entries ) ? 'hidden' : ''; ?>>
		<?php foreach ( $pinned_entries as $pinned_entry ) : ?>
			<?php echo $pinned_entry['pinned_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in entry template. ?>
		<?php endforeach; ?>
	</section>

	<button class="blogging-live__new-updates" type="button" hidden aria-live="polite"></button>

	<div class="blogging-live__entries">
		<?php if ( empty( $entries ) ) : ?>
			<p class="blogging-live__empty"><?php esc_html_e( 'Updates will appear here as they are published.', 'blogging-live' ); ?></p>
		<?php else : ?>
			<?php foreach ( $entries as $entry ) : ?>
				<?php echo $entry['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in entry template. ?>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<button class="blogging-live__load-older" type="button" <?php echo $data['has_more'] ? '' : 'hidden'; ?>>
		<?php esc_html_e( 'Load older updates', 'blogging-live' ); ?>
	</button>
	<p class="blogging-live__message" role="status" aria-live="polite"></p>
</section>
