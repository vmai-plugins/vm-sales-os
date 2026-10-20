<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Modules\CRM;

/**
 * Lead capture form. Renders as a shortcode and posts to the public REST
 * endpoint; the submission lands in the same contacts table as chat leads,
 * so scoring and automation treat both the same way.
 */
final class LeadForm {

	public function boot(): void {
		add_shortcode( 'vmsos_lead_form', [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'registerAssets' ] );
	}

	public function registerAssets(): void {
		wp_register_script( 'vmsos-form', VMSOS_URL . 'assets/js/form.js', [], VMSOS_VERSION, true );
		wp_register_style( 'vmsos-form', VMSOS_URL . 'assets/css/widget.css', [], VMSOS_VERSION );

		wp_localize_script(
			'vmsos-form',
			'vmsosForm',
			[
				'endpoint' => esc_url_raw( rest_url( 'vmsos/v1/forms/submit' ) ),
				'strings'  => [
					'sending' => __( 'Sending…', 'vm-sales-os' ),
					'error'   => __( 'That did not go through. Try again.', 'vm-sales-os' ),
				],
			]
		);
	}

	/**
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'title'    => __( 'Tell us what you need', 'vm-sales-os' ),
				'button'   => __( 'Send', 'vm-sales-os' ),
				'source'   => 'form',
				'phone'    => 'yes',
				'message'  => 'yes',
				'consent'  => __( 'I agree to be contacted about this enquiry.', 'vm-sales-os' ),
				'success'  => __( 'Thanks — we will be in touch shortly.', 'vm-sales-os' ),
			],
			(array) $atts,
			'vmsos_lead_form'
		);

		wp_enqueue_script( 'vmsos-form' );
		wp_enqueue_style( 'vmsos-form' );

		ob_start();
		?>
		<form class="vmsos-form" data-source="<?php echo esc_attr( (string) $atts['source'] ); ?>" data-success="<?php echo esc_attr( (string) $atts['success'] ); ?>" novalidate>
			<?php if ( '' !== $atts['title'] ) : ?>
				<h3 class="vmsos-form__title"><?php echo esc_html( (string) $atts['title'] ); ?></h3>
			<?php endif; ?>

			<label class="vmsos-form__field">
				<span><?php esc_html_e( 'Name', 'vm-sales-os' ); ?></span>
				<input type="text" name="first_name" autocomplete="given-name" required>
			</label>

			<label class="vmsos-form__field">
				<span><?php esc_html_e( 'Email', 'vm-sales-os' ); ?></span>
				<input type="email" name="email" autocomplete="email" required>
			</label>

			<?php if ( 'yes' === $atts['phone'] ) : ?>
				<label class="vmsos-form__field">
					<span><?php esc_html_e( 'Phone', 'vm-sales-os' ); ?></span>
					<input type="tel" name="phone" autocomplete="tel">
				</label>
			<?php endif; ?>

			<?php if ( 'yes' === $atts['message'] ) : ?>
				<label class="vmsos-form__field">
					<span><?php esc_html_e( 'What do you need?', 'vm-sales-os' ); ?></span>
					<textarea name="requirements" rows="4"></textarea>
				</label>
			<?php endif; ?>

			<?php if ( '' !== $atts['consent'] ) : ?>
				<label class="vmsos-form__consent">
					<input type="checkbox" name="consent" value="1" required>
					<span><?php echo esc_html( (string) $atts['consent'] ); ?></span>
				</label>
			<?php endif; ?>

			<div class="vmsos-form__trap" aria-hidden="true">
				<label>Company website<input type="text" name="company_website" tabindex="-1" autocomplete="off"></label>
			</div>

			<button type="submit" class="vmsos-form__submit"><?php echo esc_html( (string) $atts['button'] ); ?></button>
			<p class="vmsos-form__status" role="status" aria-live="polite"></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}
