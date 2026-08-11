<?php
/**
 * Individual liveblog entry template.
 *
 * Themes may override this file at blogging-live/entry.php.
 *
 * @package BloggingLive
 *
 * @var array<string, mixed> $data Template data.
 */

defined( 'ABSPATH' ) || exit;

$entry   = $data['entry'];
$classes = [ 'blogging-live-entry' ];

if ( $entry['is_pinned'] ) {
	$classes[] = 'blogging-live-entry--pinned';
}
if ( $entry['is_key_event'] ) {
	$classes[] = 'blogging-live-entry--key-event';
}
?>
<article
	id="blogging-live-entry-<?php echo esc_attr( (string) $entry['id'] ); ?>"
	class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
	data-entry-id="<?php echo esc_attr( (string) $entry['id'] ); ?>"
>
	<header class="blogging-live-entry__header">
		<div class="blogging-live-entry__meta">
			<a href="<?php echo esc_url( $entry['permalink'] ); ?>">
				<time datetime="<?php echo esc_attr( $entry['published_gmt'] ); ?>"><?php echo esc_html( $entry['published_text'] ); ?></time>
			</a>
			<?php if ( $entry['is_pinned'] ) : ?>
				<span class="blogging-live-entry__badge"><?php esc_html_e( 'Pinned', 'blogging-live' ); ?></span>
			<?php endif; ?>
			<?php if ( $entry['is_key_event'] ) : ?>
				<span class="blogging-live-entry__badge"><?php esc_html_e( 'Key event', 'blogging-live' ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $entry['title'] ) : ?>
			<h3 class="blogging-live-entry__title"><?php echo esc_html( $entry['title'] ); ?></h3>
		<?php endif; ?>

		<?php if ( ! empty( $entry['authors'] ) ) : ?>
			<p class="blogging-live-entry__authors">
				<?php esc_html_e( 'By', 'blogging-live' ); ?>
				<?php foreach ( $entry['authors'] as $index => $author ) : ?>
					<?php
					if ( $index > 0 ) :
						?>
						, <?php endif; ?>
					<a href="<?php echo esc_url( $author['url'] ); ?>"><?php echo esc_html( $author['name'] ); ?></a>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>
	</header>

	<div class="blogging-live-entry__content">
		<?php
		/**
		 * Filters trusted, rendered entry content before output.
		 *
		 * @param string $content Rendered block content.
		 * @param int    $entry_id Entry ID.
		 */
		echo apply_filters( 'blogging_live_entry_content_html', $entry['content']['rendered'], $entry['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</div>
</article>
