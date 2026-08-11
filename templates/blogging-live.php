<?php
/**
 * Main liveblog template.
 *
 * Themes may override this file at blogging-live/blogging-live.php.
 *
 * @package BloggingLive
 *
 * @var array<string, mixed> $data Template data.
 */

defined( 'ABSPATH' ) || exit;

$liveblog             = $data['liveblog'];
$entries              = $data['entries'];
$blogging_live_status = $data['status'];
$status_labels        = [
	'scheduled' => __( 'Scheduled', 'blogging-live' ),
	'live'      => __( 'Live', 'blogging-live' ),
	'ended'     => __( 'Ended', 'blogging-live' ),
];
?>
<section
	id="blogging-live-<?php echo esc_attr( (string) $liveblog->ID ); ?>"
	class="blogging-live blogging-live--<?php echo esc_attr( $blogging_live_status ); ?>"
	data-blogging-live-id="<?php echo esc_attr( (string) $liveblog->ID ); ?>"
	data-endpoint="<?php echo esc_url( $data['endpoint'] ); ?>"
	data-refresh="<?php echo esc_attr( (string) $data['refresh'] ); ?>"
	data-status="<?php echo esc_attr( $blogging_live_status ); ?>"
	data-order="<?php echo esc_attr( $data['order'] ); ?>"
	data-latest-cursor="<?php echo esc_attr( $data['newest'] ); ?>"
	data-oldest-cursor="<?php echo esc_attr( $data['oldest'] ); ?>"
>
	<?php if ( $data['show_header'] ) : ?>
		<header class="blogging-live__header">
			<div>
				<p class="blogging-live__eyebrow"><?php esc_html_e( 'Live coverage', 'blogging-live' ); ?></p>
				<h2 class="blogging-live__title"><?php echo esc_html( get_the_title( $liveblog ) ); ?></h2>
			</div>
			<span class="blogging-live__status" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Event status. */ __( 'Liveblog status: %s', 'blogging-live' ), $status_labels[ $blogging_live_status ] ?? $blogging_live_status ) ); ?>">
				<?php echo esc_html( $status_labels[ $blogging_live_status ] ?? ucfirst( $blogging_live_status ) ); ?>
			</span>
		</header>
	<?php endif; ?>

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
