<?php
/**
 * Main plugin class.
 *
 * @package Viney\PostQRCodes
 */

namespace Viney\PostQRCodes;

use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Common\Mode;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public const OPTION_NAME       = 'viney_post_qr_codes_options';
	public const META_PATH         = '_viney_post_qr_code_path';
	public const META_GENERATED_AT = '_viney_post_qr_code_generated_at';
	public const META_URL          = '_viney_post_qr_url';
	public const META_URL_HASH     = '_viney_post_qr_code_url_hash';
	public const META_SETTINGS_HASH = '_viney_post_qr_code_settings_hash';
	public const META_SHORT_URL    = '_viney_post_qr_short_url';
	public const META_SHORT_TOKEN  = '_viney_post_qr_short_token';

	private const REST_NAMESPACE = 'viney-post-qr-codes/v1';
	private const UPLOAD_DIRNAME = 'viney-qr-codes';
	private const OUTPUT_SIZE    = 2048;
	private const RENDER_SCALE_SQUARE = 32;
	private const RENDER_SCALE_CIRCLES = 80;
	private const RENDER_SCALE_ROUNDED = 80;
	private const GENERATION_VERSION = '6';
	private const DB_VERSION     = '1';
	private const DB_VERSION_OPTION = 'viney_post_qr_codes_db_version';
	private const SHORTLINK_PATH = 'qr';
	private const TOKEN_LENGTH   = 8;
	private const USER_META_CUSTOM_APPEARANCE = 'viney_post_qr_codes_custom_appearance';
	private const USER_META_MATCH_GLOBAL = 'viney_post_qr_codes_custom_match_global';

	private static ?self $instance = null;
	private bool $suspend_auto_generation = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_create_shortlinks_table' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_menu', array( $this, 'add_custom_generator_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_ajax_viney_post_qr_codes_batch', array( $this, 'handle_regenerate_batch' ) );
		add_action( 'transition_post_status', array( $this, 'handle_transition_post_status' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'handle_post_updated' ), 10, 3 );
		add_action( 'save_post', array( $this, 'handle_save_post' ), 20, 3 );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_shortlink_redirect' ) );
	}

	public static function activate(): void {
		self::instance()->create_shortlinks_table();
		self::instance()->register_rewrite_rules();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public function register_rewrite_rules(): void {
		add_rewrite_tag( '%viney_qr_token%', '([A-Za-z0-9]{' . self::TOKEN_LENGTH . '})' );
		add_rewrite_rule( '^' . self::SHORTLINK_PATH . '/([A-Za-z0-9]{' . self::TOKEN_LENGTH . '})/?$', 'index.php?viney_qr_token=$matches[1]', 'top' );
	}

	public function add_query_vars( array $vars ): array {
		$vars[] = 'viney_qr_token';

		return $vars;
	}

	public function handle_shortlink_redirect(): void {
		$token = get_query_var( 'viney_qr_token' );

		if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9]{' . self::TOKEN_LENGTH . '}$/', $token ) ) {
			return;
		}

		$link = $this->get_shortlink_by_token( $token );

		if ( ! $link ) {
			return;
		}

		$this->record_shortlink_visit( $token );

		if ( wp_redirect( $link->destination_url, 302, 'Viney Post QR Codes' ) ) {
			exit;
		}
	}

	public function register_settings(): void {
		register_setting(
			'viney_post_qr_codes',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => $this->default_options(),
			)
		);
	}

	public function add_settings_page(): void {
		add_options_page(
			__( 'QR Codes', 'viney-post-qr-codes' ),
			__( 'QR Codes', 'viney-post-qr-codes' ),
			'manage_options',
			'viney-post-qr-codes',
			array( $this, 'render_settings_page' )
		);
	}

	public function add_custom_generator_page(): void {
		add_management_page(
			__( 'Generate QR Code', 'viney-post-qr-codes' ),
			__( 'Generate QR Code', 'viney-post-qr-codes' ),
			'edit_posts',
			'viney-generate-qr-code',
			array( $this, 'render_custom_generator_page' )
		);
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'settings_page_viney-post-qr-codes', 'tools_page_viney-generate-qr-code' ), true ) ) {
			return;
		}

		$is_custom_page = 'tools_page_viney-generate-qr-code' === $hook_suffix;
		$asset_version  = defined( 'VINEY_POST_QR_CODES_VERSION' ) ? VINEY_POST_QR_CODES_VERSION : '1.0.0';

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_media();
		wp_enqueue_style( 'viney-post-qr-codes-admin', plugins_url( 'assets/admin.css', dirname( __FILE__ ) ), array(), $asset_version );
		wp_enqueue_script( 'viney-post-qr-codes-admin', plugins_url( 'assets/admin.js', dirname( __FILE__ ) ), array( 'jquery', 'wp-color-picker' ), $asset_version, true );

		wp_localize_script(
			'viney-post-qr-codes-admin',
			'VineyPostQRCodesAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'restUrl'    => esc_url_raw( rest_url( self::REST_NAMESPACE . '/preview' ) ),
				'restBase'   => esc_url_raw( rest_url( self::REST_NAMESPACE ) ),
				'ajaxNonce'  => wp_create_nonce( 'viney_post_qr_codes_regenerate' ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'optionName' => self::OPTION_NAME,
				'mode'       => $is_custom_page ? 'custom' : 'settings',
				'i18n'       => array(
					'generating' => __( 'Generated %1$d of %2$d QR codes. Do not leave this page while QR codes are being generated.', 'viney-post-qr-codes' ),
					'complete'   => __( 'Generated %1$d of %2$d QR codes.', 'viney-post-qr-codes' ),
					'failed'     => __( 'QR code generation failed.', 'viney-post-qr-codes' ),
				),
			)
		);
	}

	public function enqueue_editor_assets(): void {
		$enabled_post_types = $this->get_enabled_post_types();

		if ( empty( $enabled_post_types ) ) {
			return;
		}

		$asset_version = defined( 'VINEY_POST_QR_CODES_VERSION' ) ? VINEY_POST_QR_CODES_VERSION : '1.0.0';

		wp_enqueue_script(
			'viney-post-qr-codes-editor',
			plugins_url( 'assets/editor.js', dirname( __FILE__ ) ),
			array( 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-notices', 'wp-plugins' ),
			$asset_version,
			true
		);

		wp_localize_script(
			'viney-post-qr-codes-editor',
			'VineyPostQRCodesEditor',
			array(
				'enabledPostTypes' => array_values( $enabled_post_types ),
				'restBase'         => esc_url_raw( rest_url( self::REST_NAMESPACE ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_preview' ),
				'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/download/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_download' ),
				'permission_callback' => static fn ( WP_REST_Request $request ): bool => current_user_can( 'edit_post', (int) $request['id'] ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/custom',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_custom' ),
				'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
			)
		);
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options          = $this->get_options();
		$post_types       = $this->get_public_post_type_objects();
		$enabled_types    = $this->get_enabled_post_types();
		$dependency_error = $this->get_generation_dependency_error();
		?>
		<div class="wrap viney-post-qr-codes-settings">
			<h1><?php esc_html_e( 'QR Codes', 'viney-post-qr-codes' ); ?></h1>

			<?php if ( $dependency_error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $dependency_error ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php" id="viney-post-qr-codes-settings-form">
				<?php settings_fields( 'viney_post_qr_codes' ); ?>

				<h2><?php esc_html_e( 'URL Settings', 'viney-post-qr-codes' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Shorten URLs', 'viney-post-qr-codes' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[shorten_urls]" value="1" <?php checked( ! empty( $options['shorten_urls'] ) ); ?> />
									<?php esc_html_e( 'Use short /qr/ links in generated QR codes', 'viney-post-qr-codes' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Post Types', 'viney-post-qr-codes' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<?php foreach ( $post_types as $post_type => $post_type_object ) : ?>
							<?php
							$is_enabled = in_array( $post_type, $enabled_types, true );
							$utm        = $options['utm'][ $post_type ] ?? array();
							?>
							<tr>
								<th scope="row">
									<label>
										<input type="checkbox" class="viney-post-qr-codes-post-type-toggle" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled_post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( $is_enabled ); ?> data-post-type="<?php echo esc_attr( $post_type ); ?>" />
										<?php echo esc_html( $post_type_object->labels->name ); ?>
									</label>
								</th>
								<td>
									<div class="viney-post-qr-codes-utm-fields <?php echo $is_enabled ? '' : 'is-hidden'; ?>" data-utm-fields="<?php echo esc_attr( $post_type ); ?>">
										<label><?php esc_html_e( 'Anchor', 'viney-post-qr-codes' ); ?><input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[utm][<?php echo esc_attr( $post_type ); ?>][anchor]" value="<?php echo esc_attr( $utm['anchor'] ?? '' ); ?>" /></label>
										<label><?php esc_html_e( 'Source', 'viney-post-qr-codes' ); ?><input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[utm][<?php echo esc_attr( $post_type ); ?>][source]" value="<?php echo esc_attr( $utm['source'] ?? '' ); ?>" /></label>
										<label><?php esc_html_e( 'Medium', 'viney-post-qr-codes' ); ?><input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[utm][<?php echo esc_attr( $post_type ); ?>][medium]" value="<?php echo esc_attr( $utm['medium'] ?? '' ); ?>" /></label>
										<label><?php esc_html_e( 'Campaign', 'viney-post-qr-codes' ); ?><input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[utm][<?php echo esc_attr( $post_type ); ?>][campaign]" value="<?php echo esc_attr( $utm['campaign'] ?? '' ); ?>" /></label>
										<label class="viney-post-qr-codes-term-field">
											<?php esc_html_e( 'Term', 'viney-post-qr-codes' ); ?>
											<input type="text" class="viney-post-qr-codes-term-input" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[utm][<?php echo esc_attr( $post_type ); ?>][term]" value="<?php echo esc_attr( $utm['term'] ?? '' ); ?>" <?php disabled( ! empty( $utm['term_use_title'] ) ); ?> />
											<span>
												<input type="checkbox" class="viney-post-qr-codes-term-use-title" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[utm][<?php echo esc_attr( $post_type ); ?>][term_use_title]" value="1" <?php checked( ! empty( $utm['term_use_title'] ) ); ?> />
												<?php esc_html_e( 'Use title', 'viney-post-qr-codes' ); ?>
											</span>
										</label>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Appearance', 'viney-post-qr-codes' ); ?></h2>
				<div class="viney-post-qr-codes-appearance">
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Background colour', 'viney-post-qr-codes' ); ?></th>
								<td>
									<input type="text" class="viney-post-qr-codes-color" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[appearance][background_color]" value="<?php echo esc_attr( $options['appearance']['background_color'] ); ?>" />
									<label class="viney-post-qr-codes-inline-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[appearance][transparent]" value="1" <?php checked( $options['appearance']['transparent'] ); ?> /> <?php esc_html_e( 'Transparent', 'viney-post-qr-codes' ); ?></label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Foreground colour', 'viney-post-qr-codes' ); ?></th>
								<td><input type="text" class="viney-post-qr-codes-color" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[appearance][foreground_color]" value="<?php echo esc_attr( $options['appearance']['foreground_color'] ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="viney-post-qr-codes-margin"><?php esc_html_e( 'Margin', 'viney-post-qr-codes' ); ?></label></th>
								<td><input type="number" id="viney-post-qr-codes-margin" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[appearance][margin]" min="1" max="20" step="1" value="<?php echo esc_attr( $options['appearance']['margin'] ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="viney-post-qr-codes-module-shape"><?php esc_html_e( 'Module shape', 'viney-post-qr-codes' ); ?></label></th>
								<td>
									<select id="viney-post-qr-codes-module-shape" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[appearance][module_shape]">
										<option value="square" <?php selected( $options['appearance']['module_shape'], 'square' ); ?>><?php esc_html_e( 'Square', 'viney-post-qr-codes' ); ?></option>
										<option value="rounded" <?php selected( $options['appearance']['module_shape'], 'rounded' ); ?>><?php esc_html_e( 'Circles', 'viney-post-qr-codes' ); ?></option>
										<option value="styled_rounded" <?php selected( $options['appearance']['module_shape'], 'styled_rounded' ); ?>><?php esc_html_e( 'Rounded', 'viney-post-qr-codes' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Logo', 'viney-post-qr-codes' ); ?></th>
								<td>
									<input type="hidden" id="viney-post-qr-codes-logo-id" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[appearance][logo_id]" value="<?php echo esc_attr( $options['appearance']['logo_id'] ); ?>" />
									<div class="viney-post-qr-codes-logo-preview">
										<?php if ( ! empty( $options['appearance']['logo_id'] ) ) : ?>
											<?php echo wp_get_attachment_image( (int) $options['appearance']['logo_id'], 'thumbnail' ); ?>
										<?php endif; ?>
									</div>
									<button type="button" class="button" id="viney-post-qr-codes-select-logo"><?php esc_html_e( 'Select Logo', 'viney-post-qr-codes' ); ?></button>
									<button type="button" class="button <?php echo empty( $options['appearance']['logo_id'] ) ? 'is-hidden' : ''; ?>" id="viney-post-qr-codes-remove-logo"><?php esc_html_e( 'Remove Logo', 'viney-post-qr-codes' ); ?></button>
									<p class="description"><?php esc_html_e( 'Choose a PNG image. It will be center-cropped to a square before being embedded.', 'viney-post-qr-codes' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
					<div class="viney-post-qr-codes-preview">
						<h3><?php esc_html_e( 'Preview', 'viney-post-qr-codes' ); ?></h3>
						<div class="viney-post-qr-codes-preview-frame">
							<img id="viney-post-qr-codes-preview-image" alt="<?php esc_attr_e( 'QR code preview', 'viney-post-qr-codes' ); ?>" />
							<span id="viney-post-qr-codes-preview-status"><?php esc_html_e( 'Loading preview...', 'viney-post-qr-codes' ); ?></span>
						</div>
					</div>
				</div>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Regenerate QR Codes', 'viney-post-qr-codes' ); ?></h2>
			<div class="viney-post-qr-codes-regenerate">
				<label for="viney-post-qr-codes-regenerate-post-type"><?php esc_html_e( 'Post type', 'viney-post-qr-codes' ); ?></label>
				<select id="viney-post-qr-codes-regenerate-post-type">
					<option value="all"><?php esc_html_e( 'All post types', 'viney-post-qr-codes' ); ?></option>
					<?php foreach ( $enabled_types as $post_type ) : ?>
						<?php if ( isset( $post_types[ $post_type ] ) ) : ?>
							<option value="<?php echo esc_attr( $post_type ); ?>"><?php echo esc_html( $post_types[ $post_type ]->labels->name ); ?></option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button button-secondary" id="viney-post-qr-codes-regenerate-button" <?php disabled( empty( $enabled_types ) || (bool) $dependency_error ); ?>><?php esc_html_e( 'Regenerate All', 'viney-post-qr-codes' ); ?></button>
				<p id="viney-post-qr-codes-regenerate-status" class="description" aria-live="polite"></p>
			</div>
		</div>
		<?php
	}

	public function render_custom_generator_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$dependency_error = $this->get_generation_dependency_error();
		$match_global     = $this->get_custom_match_global();
		$appearance       = $this->get_custom_user_appearance();
		?>
		<div class="wrap viney-post-qr-codes-settings viney-post-qr-codes-custom-page">
			<h1><?php esc_html_e( 'Generate QR Code', 'viney-post-qr-codes' ); ?></h1>

			<?php if ( $dependency_error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $dependency_error ); ?></p></div>
			<?php endif; ?>

			<div id="viney-post-qr-codes-custom-form" class="viney-post-qr-codes-custom-layout">
				<div class="viney-post-qr-codes-custom-main">
					<h2><?php esc_html_e( 'URL', 'viney-post-qr-codes' ); ?></h2>
					<div class="viney-post-qr-codes-custom">
						<label for="viney-post-qr-codes-custom-url"><?php esc_html_e( 'URL', 'viney-post-qr-codes' ); ?></label>
						<input type="url" id="viney-post-qr-codes-custom-url" class="regular-text" placeholder="https://example.com/page" />
					</div>

					<h2><?php esc_html_e( 'Tracking', 'viney-post-qr-codes' ); ?></h2>
					<p class="description"><?php esc_html_e( 'UTM fields add tracking parameters to the URL for analytics. Anchor adds a #section jump target to the final URL.', 'viney-post-qr-codes' ); ?></p>
					<div class="viney-post-qr-codes-utm-fields viney-post-qr-codes-custom-utm-fields">
						<label><?php esc_html_e( 'Anchor', 'viney-post-qr-codes' ); ?><input type="text" id="viney-post-qr-codes-custom-anchor" placeholder="eg. section-name" /></label>
						<label><?php esc_html_e( 'Source', 'viney-post-qr-codes' ); ?><input type="text" id="viney-post-qr-codes-custom-source" placeholder="eg. qr_code" /></label>
						<label><?php esc_html_e( 'Medium', 'viney-post-qr-codes' ); ?><input type="text" id="viney-post-qr-codes-custom-medium" placeholder="eg. print" /></label>
						<label><?php esc_html_e( 'Campaign', 'viney-post-qr-codes' ); ?><input type="text" id="viney-post-qr-codes-custom-campaign" placeholder="eg. summer_event" /></label>
						<label><?php esc_html_e( 'Term', 'viney-post-qr-codes' ); ?><input type="text" id="viney-post-qr-codes-custom-term" placeholder="eg. brochure" /></label>
					</div>

					<h2><?php esc_html_e( 'Appearance', 'viney-post-qr-codes' ); ?></h2>
					<div class="viney-post-qr-codes-appearance">
						<div>
							<p>
								<label>
									<input type="checkbox" id="viney-post-qr-codes-match-global" <?php checked( $match_global ); ?> />
									<?php esc_html_e( 'Match global styles', 'viney-post-qr-codes' ); ?>
								</label>
							</p>
							<div class="viney-post-qr-codes-custom-appearance-fields">
								<table class="form-table" role="presentation">
									<tbody>
										<tr>
											<th scope="row"><?php esc_html_e( 'Background colour', 'viney-post-qr-codes' ); ?></th>
											<td>
												<input type="text" class="viney-post-qr-codes-color" id="viney-post-qr-codes-custom-background-color" value="<?php echo esc_attr( $appearance['background_color'] ); ?>" />
												<label class="viney-post-qr-codes-inline-check"><input type="checkbox" id="viney-post-qr-codes-custom-transparent" <?php checked( $appearance['transparent'] ); ?> /> <?php esc_html_e( 'Transparent', 'viney-post-qr-codes' ); ?></label>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php esc_html_e( 'Foreground colour', 'viney-post-qr-codes' ); ?></th>
											<td><input type="text" class="viney-post-qr-codes-color" id="viney-post-qr-codes-custom-foreground-color" value="<?php echo esc_attr( $appearance['foreground_color'] ); ?>" /></td>
										</tr>
										<tr>
											<th scope="row"><label for="viney-post-qr-codes-custom-margin"><?php esc_html_e( 'Margin', 'viney-post-qr-codes' ); ?></label></th>
											<td><input type="number" id="viney-post-qr-codes-custom-margin" min="1" max="20" step="1" value="<?php echo esc_attr( $appearance['margin'] ); ?>" /></td>
										</tr>
										<tr>
											<th scope="row"><label for="viney-post-qr-codes-custom-module-shape"><?php esc_html_e( 'Module shape', 'viney-post-qr-codes' ); ?></label></th>
											<td>
												<select id="viney-post-qr-codes-custom-module-shape">
													<option value="square" <?php selected( $appearance['module_shape'], 'square' ); ?>><?php esc_html_e( 'Square', 'viney-post-qr-codes' ); ?></option>
													<option value="rounded" <?php selected( $appearance['module_shape'], 'rounded' ); ?>><?php esc_html_e( 'Circles', 'viney-post-qr-codes' ); ?></option>
													<option value="styled_rounded" <?php selected( $appearance['module_shape'], 'styled_rounded' ); ?>><?php esc_html_e( 'Rounded', 'viney-post-qr-codes' ); ?></option>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php esc_html_e( 'Logo', 'viney-post-qr-codes' ); ?></th>
											<td>
												<input type="hidden" id="viney-post-qr-codes-logo-id" value="<?php echo esc_attr( $appearance['logo_id'] ); ?>" />
												<div class="viney-post-qr-codes-logo-preview">
													<?php if ( ! empty( $appearance['logo_id'] ) ) : ?>
														<?php echo wp_get_attachment_image( (int) $appearance['logo_id'], 'thumbnail' ); ?>
													<?php endif; ?>
												</div>
												<button type="button" class="button" id="viney-post-qr-codes-select-logo"><?php esc_html_e( 'Select Logo', 'viney-post-qr-codes' ); ?></button>
												<button type="button" class="button <?php echo empty( $appearance['logo_id'] ) ? 'is-hidden' : ''; ?>" id="viney-post-qr-codes-remove-logo"><?php esc_html_e( 'Remove Logo', 'viney-post-qr-codes' ); ?></button>
											</td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>

						<div class="viney-post-qr-codes-preview">
							<h3><?php esc_html_e( 'Preview', 'viney-post-qr-codes' ); ?></h3>
							<div class="viney-post-qr-codes-preview-frame">
								<img id="viney-post-qr-codes-preview-image" alt="<?php esc_attr_e( 'QR code preview', 'viney-post-qr-codes' ); ?>" />
								<span id="viney-post-qr-codes-preview-status"><?php esc_html_e( 'Loading preview...', 'viney-post-qr-codes' ); ?></span>
							</div>
						</div>
					</div>

					<p>
						<button type="button" class="button button-primary" id="viney-post-qr-codes-custom-download" <?php disabled( (bool) $dependency_error ); ?>><?php esc_html_e( 'Download QR Code', 'viney-post-qr-codes' ); ?></button>
					</p>
					<p id="viney-post-qr-codes-custom-status" class="description" aria-live="polite"></p>
				</div>

			</div>
		</div>
		<?php
	}

	public function sanitize_options( mixed $input ): array {
		$input       = is_array( $input ) ? $input : array();
		$post_types  = array_keys( $this->get_public_post_type_objects() );
		$enabled_raw = isset( $input['enabled_post_types'] ) && is_array( $input['enabled_post_types'] ) ? $input['enabled_post_types'] : array();
		$enabled     = array_values( array_intersect( array_map( 'sanitize_key', $enabled_raw ), $post_types ) );
		$utm         = array();

		foreach ( $enabled as $post_type ) {
			$post_type_utm = $input['utm'][ $post_type ] ?? array();
			$utm[ $post_type ] = array(
				'anchor'         => isset( $post_type_utm['anchor'] ) ? $this->sanitize_anchor( $post_type_utm['anchor'] ) : '',
				'source'         => isset( $post_type_utm['source'] ) ? sanitize_text_field( wp_unslash( $post_type_utm['source'] ) ) : '',
				'medium'         => isset( $post_type_utm['medium'] ) ? sanitize_text_field( wp_unslash( $post_type_utm['medium'] ) ) : '',
				'campaign'       => isset( $post_type_utm['campaign'] ) ? sanitize_text_field( wp_unslash( $post_type_utm['campaign'] ) ) : '',
				'term'           => isset( $post_type_utm['term'] ) ? sanitize_text_field( wp_unslash( $post_type_utm['term'] ) ) : '',
				'term_use_title' => ! empty( $post_type_utm['term_use_title'] ),
			);
		}

		$appearance_input = isset( $input['appearance'] ) && is_array( $input['appearance'] ) ? $input['appearance'] : array();
		$module_shape     = isset( $appearance_input['module_shape'] ) ? sanitize_key( $appearance_input['module_shape'] ) : 'square';

		if ( 'circle' === $module_shape ) {
			$module_shape = 'rounded';
		}

		if ( ! in_array( $module_shape, array( 'square', 'rounded', 'styled_rounded' ), true ) ) {
			$module_shape = 'square';
		}

		return array(
			'shorten_urls'       => ! empty( $input['shorten_urls'] ),
			'enabled_post_types' => $enabled,
			'utm'                => $utm,
			'appearance'         => $this->sanitize_appearance( $appearance_input, $module_shape ),
		);
	}

	private function sanitize_appearance( array $appearance_input, ?string $module_shape = null ): array {
		$module_shape = $module_shape ?? ( isset( $appearance_input['module_shape'] ) ? sanitize_key( $appearance_input['module_shape'] ) : 'square' );

		if ( 'circle' === $module_shape ) {
			$module_shape = 'rounded';
		}

		if ( ! in_array( $module_shape, array( 'square', 'rounded', 'styled_rounded' ), true ) ) {
			$module_shape = 'square';
		}

		return array(
			'background_color' => $this->sanitize_hex_color_with_default( $appearance_input['background_color'] ?? '', '#ffffff' ),
			'foreground_color' => $this->sanitize_hex_color_with_default( $appearance_input['foreground_color'] ?? '', '#000000' ),
			'transparent'      => ! empty( $appearance_input['transparent'] ),
			'margin'           => max( 1, min( 20, absint( $appearance_input['margin'] ?? 4 ) ) ),
			'module_shape'     => $module_shape,
			'logo_id'          => $this->sanitize_logo_id( $appearance_input['logo_id'] ?? 0 ),
		);
	}

	public function handle_transition_post_status( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			$this->maybe_generate_for_post( $post->ID );
		}
	}

	public function handle_post_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( $post_after->post_name !== $post_before->post_name || $post_after->post_parent !== $post_before->post_parent || $post_after->post_status !== $post_before->post_status ) {
			$this->maybe_generate_for_post( $post_id );
		}
	}

	public function handle_save_post( int $post_id, \WP_Post $post, bool $update ): void {
		if ( $update ) {
			$this->maybe_generate_for_post( $post_id );
		}
	}

	public function handle_regenerate_batch(): void {
		check_ajax_referer( 'viney_post_qr_codes_regenerate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to regenerate QR codes.', 'viney-post-qr-codes' ) ), 403 );
		}

		$dependency_error = $this->get_generation_dependency_error();

		if ( $dependency_error ) {
			wp_send_json_error( array( 'message' => $dependency_error ), 500 );
		}

		$post_type = isset( $_POST['postType'] ) ? sanitize_key( wp_unslash( $_POST['postType'] ) ) : 'all';
		$offset    = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$limit     = isset( $_POST['limit'] ) ? max( 1, min( 25, absint( $_POST['limit'] ) ) ) : 5;
		$types     = $this->resolve_regeneration_post_types( $post_type );

		if ( empty( $types ) ) {
			wp_send_json_success( array( 'total' => 0, 'generated' => 0, 'nextOffset' => 0, 'done' => true ) );
		}

		$total_query = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$total       = (int) $total_query->found_posts;
		$query       = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$generated = 0;

		foreach ( $query->posts as $post_id ) {
			$result = $this->generate_for_post( (int) $post_id, true );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							__( 'Post %1$d: %2$s', 'viney-post-qr-codes' ),
							$post_id,
							$result->get_error_message()
						),
					),
					500
				);
			}

			++$generated;
		}

		wp_send_json_success(
			array(
				'total'      => $total,
				'generated'  => min( $total, $offset + $generated ),
				'nextOffset' => $offset + $generated,
				'done'       => ( $offset + $generated ) >= $total,
			)
		);
	}

	public function rest_preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$dependency_error = $this->get_generation_dependency_error();

		if ( $dependency_error ) {
			return new WP_Error( 'viney_post_qr_codes_dependency_error', $dependency_error, array( 'status' => 500 ) );
		}

		$params = $request->get_json_params() ?: array();
		$url    = home_url( '/' );
		$is_custom_request = isset( $params['url'] ) || isset( $params['utm'] ) || isset( $params['match_global_styles'] );

		if ( $is_custom_request ) {
			$raw_url = isset( $params['url'] ) ? trim( sanitize_text_field( wp_unslash( $params['url'] ) ) ) : '';

			if ( '' !== $raw_url && ! $this->is_absolute_url( $raw_url ) ) {
				return new WP_Error( 'viney_post_qr_codes_invalid_custom_url', __( 'Enter a valid URL beginning with http:// or https://.', 'viney-post-qr-codes' ), array( 'status' => 400 ) );
			}

			$url = '' === $raw_url ? home_url( '/' ) : $this->build_tracking_url( $raw_url, $this->sanitize_tracking_fields( $params['utm'] ?? array() ) );
		}

		$appearance = $is_custom_request ? $this->get_custom_request_appearance( $params, true ) : $this->sanitize_options( array( 'appearance' => $params ) )['appearance'];

		try {
			$data_uri = $this->render_qr_code( $url, null, $appearance );
		} catch ( \Throwable $exception ) {
			return new WP_Error( 'viney_post_qr_codes_preview_failed', $exception->getMessage(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'src' => $data_uri ) );
	}

	public function rest_download( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request['id'];
		$result  = $this->generate_for_post( $post_id, false );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$path = get_post_meta( $post_id, self::META_PATH, true );
		$file = $this->uploads_relative_to_file_path( $path );
		$url  = $this->uploads_relative_to_url( $path );

		if ( ! $file || ! $url || ! file_exists( $file ) ) {
			return new WP_Error( 'viney_post_qr_codes_missing_file', __( 'The QR code file could not be found.', 'viney-post-qr-codes' ), array( 'status' => 404 ) );
		}

		$post     = get_post( $post_id );
		$filename = sanitize_file_name( ( $post?->post_name ?: 'qr-code' ) . '.png' );

		return new WP_REST_Response(
			array(
				'url'      => esc_url_raw( $url ),
				'filename' => $filename,
			)
		);
	}

	public function rest_custom( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$dependency_error = $this->get_generation_dependency_error();

		if ( $dependency_error ) {
			return new WP_Error( 'viney_post_qr_codes_dependency_error', $dependency_error, array( 'status' => 500 ) );
		}

		$params = $request->get_json_params() ?: array();
		$data   = isset( $params['url'] ) ? trim( sanitize_text_field( wp_unslash( $params['url'] ) ) ) : '';

		if ( '' === $data ) {
			return new WP_Error( 'viney_post_qr_codes_empty_custom_url', __( 'Enter a URL to generate a QR code.', 'viney-post-qr-codes' ), array( 'status' => 400 ) );
		}

		if ( ! $this->is_absolute_url( $data ) ) {
			return new WP_Error( 'viney_post_qr_codes_invalid_custom_url', __( 'Enter a valid URL beginning with http:// or https://.', 'viney-post-qr-codes' ), array( 'status' => 400 ) );
		}

		$destination_url = $this->build_tracking_url( $data, $this->sanitize_tracking_fields( $params['utm'] ?? array() ) );
		$encoded_data    = $destination_url;
		$appearance      = $this->get_custom_request_appearance( $params, true );

		if ( $this->should_shorten_urls() ) {
			$short_url = $this->create_custom_shortlink( $destination_url );

			if ( is_wp_error( $short_url ) ) {
				return $short_url;
			}

			$encoded_data = $short_url;
		}

		try {
			$data_uri = $this->render_qr_code( $encoded_data, null, $appearance );
		} catch ( \Throwable $exception ) {
			return new WP_Error( 'viney_post_qr_codes_custom_failed', $exception->getMessage(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'src'      => $data_uri,
				'filename' => 'custom-qr-code.png',
			)
		);
	}

	private function maybe_generate_for_post( int $post_id ): void {
		if ( $this->suspend_auto_generation || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status || ! $this->is_post_type_enabled( $post->post_type ) ) {
			return;
		}

		$qr_url       = $this->get_post_qr_url( $post_id );
		$encoded_url  = $this->get_post_encoded_qr_url( $post, $qr_url );

		if ( is_wp_error( $encoded_url ) ) {
			return;
		}

		$current_hash = hash( 'sha256', $qr_url . '|' . $encoded_url );
		$settings_hash = $this->get_generation_settings_hash();
		$stored_hash  = (string) get_post_meta( $post_id, self::META_URL_HASH, true );
		$stored_settings_hash = (string) get_post_meta( $post_id, self::META_SETTINGS_HASH, true );
		$path         = (string) get_post_meta( $post_id, self::META_PATH, true );
		$file         = $this->uploads_relative_to_file_path( $path );

		if ( $stored_hash === $current_hash && $stored_settings_hash === $settings_hash && $file && file_exists( $file ) ) {
			return;
		}

		$this->generate_for_post( $post_id, true );
	}

	private function generate_for_post( int $post_id, bool $force ): true|WP_Error {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return new WP_Error( 'viney_post_qr_codes_invalid_post', __( 'Revisions and autosaves cannot have QR codes.', 'viney-post-qr-codes' ), array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'viney_post_qr_codes_invalid_post', __( 'Invalid post.', 'viney-post-qr-codes' ), array( 'status' => 404 ) );
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'viney_post_qr_codes_unpublished_post', __( 'Only published posts can have QR codes.', 'viney-post-qr-codes' ), array( 'status' => 400 ) );
		}

		if ( ! $this->is_post_type_enabled( $post->post_type ) ) {
			return new WP_Error( 'viney_post_qr_codes_disabled_post_type', __( 'QR codes are not enabled for this post type.', 'viney-post-qr-codes' ), array( 'status' => 400 ) );
		}

		$dependency_error = $this->get_generation_dependency_error();

		if ( $dependency_error ) {
			return new WP_Error( 'viney_post_qr_codes_dependency_error', $dependency_error, array( 'status' => 500 ) );
		}

		$qr_url       = $this->get_post_qr_url( $post_id );
		$encoded_url  = $this->get_post_encoded_qr_url( $post, $qr_url );

		if ( is_wp_error( $encoded_url ) ) {
			return $encoded_url;
		}

		$current_hash = hash( 'sha256', $qr_url . '|' . $encoded_url );
		$settings_hash = $this->get_generation_settings_hash();
		$stored_hash  = (string) get_post_meta( $post_id, self::META_URL_HASH, true );
		$stored_settings_hash = (string) get_post_meta( $post_id, self::META_SETTINGS_HASH, true );
		$path         = (string) get_post_meta( $post_id, self::META_PATH, true );
		$file         = $this->uploads_relative_to_file_path( $path );

		if ( ! $force && $stored_hash === $current_hash && $stored_settings_hash === $settings_hash && $file && file_exists( $file ) ) {
			return true;
		}

		$target_file = $this->get_post_qr_file_path( $post );

		if ( ! $target_file ) {
			return new WP_Error( 'viney_post_qr_codes_upload_dir_failed', __( 'The QR code upload directory could not be prepared.', 'viney-post-qr-codes' ), array( 'status' => 500 ) );
		}

		try {
			$this->render_qr_code( $encoded_url, $target_file, $this->get_options()['appearance'] );
		} catch ( \Throwable $exception ) {
			return new WP_Error( 'viney_post_qr_codes_generation_failed', $exception->getMessage(), array( 'status' => 500 ) );
		}

		$this->suspend_auto_generation = true;
		update_post_meta( $post_id, self::META_PATH, $this->file_path_to_uploads_relative( $target_file ) );
		update_post_meta( $post_id, self::META_GENERATED_AT, current_time( 'mysql', true ) );
		update_post_meta( $post_id, self::META_URL, esc_url_raw( $qr_url ) );
		update_post_meta( $post_id, self::META_URL_HASH, $current_hash );
		update_post_meta( $post_id, self::META_SETTINGS_HASH, $settings_hash );
		$this->suspend_auto_generation = false;

		return true;
	}

	private function render_qr_code( string $url, ?string $target, array $appearance ): string {
		$foreground = $this->hex_to_rgb( (string) $appearance['foreground_color'] );
		$background = $this->hex_to_rgb( (string) $appearance['background_color'] );
		$shape      = (string) $appearance['module_shape'];
		$logo_path  = $this->get_logo_path( (int) ( $appearance['logo_id'] ?? 0 ) );
		$module_values = $this->get_module_values( $foreground, $background );
		$is_circles = 'rounded' === $shape;
		$is_styled_rounded = 'styled_rounded' === $shape;
		$scale      = $is_styled_rounded ? self::RENDER_SCALE_ROUNDED : ( $is_circles ? self::RENDER_SCALE_CIRCLES : self::RENDER_SCALE_SQUARE );
		$option_values = array(
			'outputInterface'     => $is_styled_rounded ? QRGdStyledRounded::class : QRGdImagePNG::class,
			'outputBase64'        => false,
			'eccLevel'            => $logo_path ? EccLevel::H : EccLevel::L,
			'scale'               => $scale,
			'addQuietzone'        => true,
			'quietzoneSize'       => (int) $appearance['margin'],
			'bgColor'             => $background,
			'imageTransparent'    => ! empty( $appearance['transparent'] ),
			'transparencyColor'   => $background,
			'drawLightModules'    => false,
			'drawCircularModules' => $is_circles || $is_styled_rounded,
			'circleRadius'        => 0.35,
			'keepAsSquare'        => array(
				QRMatrix::M_FINDER_DARK,
				QRMatrix::M_FINDER_DOT,
				QRMatrix::M_ALIGNMENT_DARK,
			),
			'moduleValues'        => $module_values,
		);

		$logo_space_modules = 0;

		if ( $logo_path ) {
			$probe_qrcode = new QRCode( new QROptions( $option_values ) );
			$this->add_data_segment_to_qrcode( $probe_qrcode, $url );
			$probe_matrix       = $probe_qrcode->getQRMatrix();
			$qr_dimension       = max( 1, $probe_matrix->moduleCount - ( (int) $appearance['margin'] * 2 ) );
			$logo_space_modules = $this->get_centered_module_span( $qr_dimension, 0.24, 9 );

			$option_values['addLogoSpace']    = true;
			$option_values['logoSpaceWidth']  = $logo_space_modules;
			$option_values['logoSpaceHeight'] = $logo_space_modules;
		}

		$qrcode = new QRCode( new QROptions( $option_values ) );
		$this->add_data_segment_to_qrcode( $qrcode, $url );
		$matrix     = $qrcode->getQRMatrix();
		$image_data = $qrcode->renderMatrix( $matrix );

		if ( ! empty( $appearance['transparent'] ) ) {
			$image_data = $this->make_png_color_transparent( $image_data, $background );
		}

		$image_data = $this->resize_png_data( $image_data, self::OUTPUT_SIZE, $is_circles || $is_styled_rounded );

		if ( ! empty( $appearance['transparent'] ) ) {
			$image_data = $this->make_png_color_transparent( $image_data, $background );
		}

		if ( $logo_path ) {
			$image_data = $this->add_logo_to_png_data( $image_data, $logo_path, $background, ! empty( $appearance['transparent'] ), $matrix->moduleCount, $logo_space_modules );
		}

		if ( $target ) {
			if ( false === file_put_contents( $target, $image_data ) ) {
				throw new \RuntimeException( esc_html__( 'The QR code file could not be written.', 'viney-post-qr-codes' ) );
			}

			return $image_data;
		}

		return 'data:image/png;base64,' . base64_encode( $image_data );
	}

	private function get_module_values( array $foreground, array $background ): array {
		$module_values = array();

		foreach ( QRGdImagePNG::DEFAULT_MODULE_VALUES as $module => $is_dark ) {
			$module_values[ $module ] = $is_dark ? $foreground : $background;
		}

		return $module_values;
	}

	private function add_data_segment_to_qrcode( QRCode $qrcode, string $data ): void {
		foreach ( Mode::INTERFACES as $data_interface ) {
			if ( $data_interface::validateString( $data ) ) {
				$qrcode->addSegment( new $data_interface( $data ) );

				return;
			}
		}
	}

	private function get_generation_settings_hash(): string {
		$appearance = $this->get_options()['appearance'];
		$appearance['output_size'] = self::OUTPUT_SIZE;
		$appearance['generation_version'] = self::GENERATION_VERSION;
		$appearance['shorten_urls'] = $this->should_shorten_urls();

		return hash( 'sha256', wp_json_encode( $appearance ) ?: '' );
	}

	private function get_post_qr_url( int $post_id ): string {
		$post    = get_post( $post_id );
		$url     = (string) get_permalink( $post_id );
		$options = $this->get_options();
		$utm     = $post ? ( $options['utm'][ $post->post_type ] ?? array() ) : array();

		if ( ! empty( $utm['term_use_title'] ) && $post ) {
			$utm['term'] = $post->post_title;
		}

		return $this->build_tracking_url( $url, $utm );
	}

	private function build_tracking_url( string $url, array $tracking ): string {
		$args = array();

		if ( ! empty( $tracking['anchor'] ) ) {
			$fragment_position = strpos( $url, '#' );

			if ( false !== $fragment_position ) {
				$url = substr( $url, 0, $fragment_position );
			}
		}

		if ( ! empty( $tracking['source'] ) ) {
			$args['utm_source'] = $tracking['source'];
		}

		if ( ! empty( $tracking['medium'] ) ) {
			$args['utm_medium'] = $tracking['medium'];
		}

		if ( ! empty( $tracking['campaign'] ) ) {
			$args['utm_campaign'] = $tracking['campaign'];
		}

		if ( ! empty( $tracking['term'] ) ) {
			$args['utm_term'] = $tracking['term'];
		}

		$tracked_url = empty( $args ) ? $url : add_query_arg( $args, $url );

		if ( ! empty( $tracking['anchor'] ) ) {
			$tracked_url .= '#' . rawurlencode( $tracking['anchor'] );
		}

		return $tracked_url;
	}

	private function sanitize_tracking_fields( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();

		return array(
			'anchor'   => isset( $input['anchor'] ) ? $this->sanitize_anchor( $input['anchor'] ) : '',
			'source'   => isset( $input['source'] ) ? sanitize_text_field( wp_unslash( $input['source'] ) ) : '',
			'medium'   => isset( $input['medium'] ) ? sanitize_text_field( wp_unslash( $input['medium'] ) ) : '',
			'campaign' => isset( $input['campaign'] ) ? sanitize_text_field( wp_unslash( $input['campaign'] ) ) : '',
			'term'     => isset( $input['term'] ) ? sanitize_text_field( wp_unslash( $input['term'] ) ) : '',
		);
	}

	private function get_custom_request_appearance( array $params, bool $save_user_preferences ): array {
		$match_global = ! isset( $params['match_global_styles'] ) || ! empty( $params['match_global_styles'] );

		if ( $match_global ) {
			if ( $save_user_preferences ) {
				update_user_meta( get_current_user_id(), self::USER_META_MATCH_GLOBAL, '1' );
			}

			return $this->get_options()['appearance'];
		}

		$appearance = $this->sanitize_appearance( is_array( $params['appearance'] ?? null ) ? $params['appearance'] : array() );

		if ( $save_user_preferences ) {
			update_user_meta( get_current_user_id(), self::USER_META_MATCH_GLOBAL, '0' );
			update_user_meta( get_current_user_id(), self::USER_META_CUSTOM_APPEARANCE, $appearance );
		}

		return $appearance;
	}

	private function get_custom_match_global(): bool {
		$value = get_user_meta( get_current_user_id(), self::USER_META_MATCH_GLOBAL, true );

		return '' === $value ? true : '1' === $value;
	}

	private function get_custom_user_appearance(): array {
		$appearance = get_user_meta( get_current_user_id(), self::USER_META_CUSTOM_APPEARANCE, true );

		return wp_parse_args( is_array( $appearance ) ? $appearance : array(), $this->get_options()['appearance'] );
	}

	private function get_post_encoded_qr_url( \WP_Post $post, string $destination_url ): string|WP_Error {
		if ( ! $this->should_shorten_urls() ) {
			return $destination_url;
		}

		$shortlink = $this->create_or_update_post_shortlink( $post, $destination_url );

		if ( is_wp_error( $shortlink ) ) {
			return $shortlink;
		}

		return $shortlink['short_url'];
	}

	private function get_post_qr_file_path( \WP_Post $post ): ?string {
		$upload = wp_get_upload_dir();

		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
			return null;
		}

		$directory = trailingslashit( $upload['basedir'] ) . self::UPLOAD_DIRNAME . '/' . sanitize_key( $post->post_type );

		if ( ! wp_mkdir_p( $directory ) ) {
			return null;
		}

		$slug = $post->post_name ?: (string) $post->ID;

		return trailingslashit( $directory ) . sanitize_file_name( $slug . '.png' );
	}

	private function file_path_to_uploads_relative( string $file ): string {
		$upload  = wp_get_upload_dir();
		$basedir = wp_normalize_path( trailingslashit( $upload['basedir'] ) );
		$file    = wp_normalize_path( $file );

		return ltrim( str_replace( $basedir, '', $file ), '/' );
	}

	private function uploads_relative_to_file_path( string $path ): ?string {
		if ( '' === $path ) {
			return null;
		}

		$upload = wp_get_upload_dir();

		if ( empty( $upload['basedir'] ) ) {
			return null;
		}

		if ( str_starts_with( wp_normalize_path( $path ), wp_normalize_path( $upload['basedir'] ) ) ) {
			return $path;
		}

		return trailingslashit( $upload['basedir'] ) . ltrim( $path, '/\\' );
	}

	private function uploads_relative_to_url( string $path ): ?string {
		if ( '' === $path ) {
			return null;
		}

		$upload = wp_get_upload_dir();

		if ( empty( $upload['baseurl'] ) ) {
			return null;
		}

		return trailingslashit( $upload['baseurl'] ) . str_replace( '\\', '/', ltrim( $path, '/\\' ) );
	}

	private function resolve_regeneration_post_types( string $post_type ): array {
		$enabled = $this->get_enabled_post_types();

		if ( 'all' === $post_type ) {
			return $enabled;
		}

		return in_array( $post_type, $enabled, true ) ? array( $post_type ) : array();
	}

	private function get_enabled_post_types(): array {
		return $this->get_options()['enabled_post_types'];
	}

	private function is_post_type_enabled( string $post_type ): bool {
		return in_array( $post_type, $this->get_enabled_post_types(), true );
	}

	private function get_public_post_type_objects(): array {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		unset( $post_types['attachment'] );

		return $post_types;
	}

	private function get_options(): array {
		$options = get_option( self::OPTION_NAME, array() );
		$options = wp_parse_args( is_array( $options ) ? $options : array(), $this->default_options() );
		$options['appearance'] = wp_parse_args( $options['appearance'] ?? array(), $this->default_options()['appearance'] );

		return $options;
	}

	private function default_options(): array {
		return array(
			'shorten_urls'       => false,
			'enabled_post_types' => array(),
			'utm'                => array(),
			'appearance'         => array(
				'background_color' => '#ffffff',
				'foreground_color' => '#000000',
				'transparent'      => false,
				'margin'           => 4,
				'module_shape'     => 'square',
				'logo_id'          => 0,
			),
		);
	}

	private function get_generation_dependency_error(): string {
		if ( ! class_exists( QRCode::class ) || ! class_exists( QROptions::class ) || ! class_exists( QRGdImagePNG::class ) ) {
			return __( 'The chillerlan/php-qrcode dependency is missing. Run Composer install for this plugin.', 'viney-post-qr-codes' );
		}

		if ( ! extension_loaded( 'mbstring' ) ) {
			return __( 'The PHP mbstring extension is required to generate QR codes.', 'viney-post-qr-codes' );
		}

		if ( ! extension_loaded( 'gd' ) ) {
			return __( 'The PHP GD extension is required to generate PNG QR codes.', 'viney-post-qr-codes' );
		}

		return '';
	}

	private function should_shorten_urls(): bool {
		return ! empty( $this->get_options()['shorten_urls'] );
	}

	private function get_shortlinks_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'viney_post_qr_short_links';
	}

	private function create_shortlinks_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = $this->get_shortlinks_table();
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token varchar(8) NOT NULL,
				destination_url longtext NOT NULL,
				source_type varchar(20) NOT NULL DEFAULT 'post',
				post_id bigint(20) unsigned NULL,
				post_type varchar(100) NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				last_accessed_at datetime NULL,
				hit_count bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY token (token),
				KEY source (source_type, post_id),
				KEY post_type (post_type)
			) {$charset_collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public function maybe_create_shortlinks_table(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			$this->create_shortlinks_table();
		}
	}

	private function create_or_update_post_shortlink( \WP_Post $post, string $destination_url ): array|WP_Error {
		global $wpdb;

		$this->maybe_create_shortlinks_table();

		$table = $this->get_shortlinks_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_type = %s AND post_id = %d AND destination_url = %s ORDER BY id DESC LIMIT 1",
				'post',
				$post->ID,
				esc_url_raw( $destination_url )
			)
		);

		if ( ! $row ) {
			$token = $this->generate_unique_token();

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$now = current_time( 'mysql', true );
			$inserted = $wpdb->insert(
				$table,
				array(
					'token'           => $token,
					'destination_url' => esc_url_raw( $destination_url ),
					'source_type'     => 'post',
					'post_id'         => $post->ID,
					'post_type'       => $post->post_type,
					'created_at'      => $now,
					'updated_at'      => $now,
					'hit_count'       => 0,
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
			);

			if ( false === $inserted ) {
				return new WP_Error( 'viney_post_qr_shortlink_create_failed', __( 'The short URL could not be created.', 'viney-post-qr-codes' ), array( 'status' => 500 ) );
			}
		} else {
			$token = $row->token;
			$wpdb->update(
				$table,
				array(
					'post_type'       => $post->post_type,
					'updated_at'      => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		$short_url = $this->get_short_url( $token );

		update_post_meta( $post->ID, self::META_SHORT_TOKEN, $token );
		update_post_meta( $post->ID, self::META_SHORT_URL, esc_url_raw( $short_url ) );

		return array(
			'token'     => $token,
			'short_url' => $short_url,
		);
	}

	private function create_custom_shortlink( string $destination_url ): string|WP_Error {
		global $wpdb;

		$this->maybe_create_shortlinks_table();

		$token = $this->generate_unique_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			$this->get_shortlinks_table(),
			array(
				'token'           => $token,
				'destination_url' => esc_url_raw( $destination_url ),
				'source_type'     => 'custom',
				'created_at'      => $now,
				'updated_at'      => $now,
				'hit_count'       => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'viney_post_qr_shortlink_create_failed', __( 'The short URL could not be created.', 'viney-post-qr-codes' ), array( 'status' => 500 ) );
		}

		return $this->get_short_url( $token );
	}

	private function generate_unique_token(): string|WP_Error {
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			$token = $this->generate_base62_token();

			if ( ! $this->shortlink_token_exists( $token ) ) {
				return $token;
			}
		}

		return new WP_Error( 'viney_post_qr_shortlink_token_failed', __( 'A unique short URL token could not be generated.', 'viney-post-qr-codes' ), array( 'status' => 500 ) );
	}

	private function generate_base62_token(): string {
		$alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$token    = '';

		while ( strlen( $token ) < self::TOKEN_LENGTH ) {
			$bytes = random_bytes( self::TOKEN_LENGTH );

			for ( $index = 0; $index < strlen( $bytes ) && strlen( $token ) < self::TOKEN_LENGTH; $index++ ) {
				$value = ord( $bytes[ $index ] );

				if ( $value > 247 ) {
					continue;
				}

				$token .= $alphabet[ $value % 62 ];
			}
		}

		return $token;
	}

	private function shortlink_token_exists( string $token ): bool {
		global $wpdb;

		if ( ! preg_match( '/^[A-Za-z0-9]{' . self::TOKEN_LENGTH . '}$/', $token ) ) {
			return true;
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $this->get_shortlinks_table() . ' WHERE token = %s LIMIT 1',
				$token
			)
		);
	}

	private function get_shortlink_by_token( string $token ): ?object {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->get_shortlinks_table() . ' WHERE token = %s LIMIT 1',
				$token
			)
		) ?: null;
	}

	private function record_shortlink_visit( string $token ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $this->get_shortlinks_table() . ' SET hit_count = hit_count + 1, last_accessed_at = %s WHERE token = %s',
				current_time( 'mysql', true ),
				$token
			)
		);
	}

	private function get_short_url( string $token ): string {
		return home_url( '/' . self::SHORTLINK_PATH . '/' . $token );
	}

	private function is_absolute_url( string $value ): bool {
		$scheme = wp_parse_url( $value, PHP_URL_SCHEME );
		$host   = wp_parse_url( $value, PHP_URL_HOST );

		return is_string( $scheme ) && in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) && is_string( $host ) && '' !== $host;
	}

	private function sanitize_hex_color_with_default( mixed $value, string $fallback ): string {
		$color = sanitize_hex_color( is_string( $value ) ? $value : '' );

		return $color ?: $fallback;
	}

	private function sanitize_anchor( mixed $value ): string {
		$anchor = sanitize_text_field( wp_unslash( is_string( $value ) ? $value : '' ) );

		return ltrim( trim( $anchor ), "# \t\n\r\0\x0B" );
	}

	private function sanitize_logo_id( mixed $value ): int {
		$logo_id = absint( $value );

		if ( ! $logo_id || 'image/png' !== get_post_mime_type( $logo_id ) ) {
			return 0;
		}

		return $logo_id;
	}

	private function get_logo_path( int $logo_id ): ?string {
		if ( ! $logo_id || 'image/png' !== get_post_mime_type( $logo_id ) ) {
			return null;
		}

		$file = get_attached_file( $logo_id );

		return $file && file_exists( $file ) ? $file : null;
	}

	private function resize_png_data( string $png_data, int $size, bool $smooth = false ): string {
		$image = imagecreatefromstring( $png_data );

		if ( false === $image ) {
			return $png_data;
		}

		if ( $smooth ) {
			$resized = imagecreatetruecolor( $size, $size );

			if ( false !== $resized ) {
				imagealphablending( $resized, false );
				imagesavealpha( $resized, true );

				$transparent = imagecolorallocatealpha( $resized, 0, 0, 0, 127 );

				if ( false !== $transparent ) {
					imagefilledrectangle( $resized, 0, 0, $size, $size, $transparent );
				}

				imagecopyresampled( $resized, $image, 0, 0, 0, 0, $size, $size, imagesx( $image ), imagesy( $image ) );
			}
		} else {
			$resized = imagescale( $image, $size, $size, IMG_NEAREST_NEIGHBOUR );
		}

		imagedestroy( $image );
		if ( false === $resized ) {
			return $png_data;
		}

		imagesavealpha( $resized, true );

		ob_start();
		imagepng( $resized );
		$output = ob_get_clean();

		imagedestroy( $resized );

		return false === $output ? $png_data : $output;
	}

	private function make_png_color_transparent( string $png_data, array $transparent_color ): string {
		$image = imagecreatefromstring( $png_data );

		if ( false === $image ) {
			return $png_data;
		}

		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		$transparent = imagecolorallocatealpha( $image, $transparent_color[0], $transparent_color[1], $transparent_color[2], 127 );

		if ( false === $transparent ) {
			imagedestroy( $image );

			return $png_data;
		}

		$width  = imagesx( $image );
		$height = imagesy( $image );
		$target = ( $transparent_color[0] << 16 ) | ( $transparent_color[1] << 8 ) | $transparent_color[2];

		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				if ( ( imagecolorat( $image, $x, $y ) & 0xFFFFFF ) === $target ) {
					imagesetpixel( $image, $x, $y, $transparent );
				}
			}
		}

		ob_start();
		imagepng( $image );
		$output = ob_get_clean();

		imagedestroy( $image );

		return false === $output ? $png_data : $output;
	}

	private function add_logo_to_png_data( string $qr_data, string $logo_path, array $background, bool $transparent, int $module_count, int $logo_space_modules ): string {
		$qr_image   = imagecreatefromstring( $qr_data );
		$logo_image = imagecreatefrompng( $logo_path );

		if ( false === $qr_image || false === $logo_image ) {
			return $qr_data;
		}

		imagealphablending( $qr_image, true );
		imagesavealpha( $qr_image, true );

		$qr_width    = imagesx( $qr_image );
		$qr_height   = imagesy( $qr_image );
		$logo_width  = imagesx( $logo_image );
		$logo_height = imagesy( $logo_image );
		$source_size = min( $logo_width, $logo_height );
		$source_x    = (int) floor( ( $logo_width - $source_size ) / 2 );
		$source_y    = (int) floor( ( $logo_height - $source_size ) / 2 );
		$module_size = min( $qr_width, $qr_height ) / max( 1, $module_count );
		$pad_modules = max( 1, $logo_space_modules );
		$logo_modules = max( 1, $pad_modules - 2 );
		$pad_size    = (int) round( $pad_modules * $module_size );
		$target_size = (int) round( $logo_modules * $module_size );
		$pad_x       = (int) round( ( ( $module_count - $pad_modules ) / 2 ) * $module_size );
		$pad_y       = (int) round( ( ( $module_count - $pad_modules ) / 2 ) * $module_size );
		$target_x    = (int) round( ( ( $module_count - $logo_modules ) / 2 ) * $module_size );
		$target_y    = (int) round( ( ( $module_count - $logo_modules ) / 2 ) * $module_size );

		if ( $transparent ) {
			$pad_color = imagecolorallocatealpha( $qr_image, $background[0], $background[1], $background[2], 127 );
		} else {
			$pad_color = imagecolorallocate( $qr_image, $background[0], $background[1], $background[2] );
		}

		if ( false !== $pad_color ) {
			imagealphablending( $qr_image, ! $transparent );
			imagefilledrectangle( $qr_image, $pad_x, $pad_y, $pad_x + $pad_size, $pad_y + $pad_size, $pad_color );
			imagealphablending( $qr_image, true );
		}

		imagecopyresampled( $qr_image, $logo_image, $target_x, $target_y, $source_x, $source_y, $target_size, $target_size, $source_size, $source_size );

		ob_start();
		imagepng( $qr_image );
		$output = ob_get_clean();

		imagedestroy( $qr_image );
		imagedestroy( $logo_image );

		return false === $output ? $qr_data : $output;
	}

	private function get_centered_module_span( int $module_count, float $target_ratio, int $minimum ): int {
		$span = max( $minimum, (int) round( $module_count * $target_ratio ) );

		if ( ( $module_count - $span ) % 2 !== 0 ) {
			++$span;
		}

		if ( $span >= $module_count ) {
			$span = $module_count - 1;
		}

		if ( ( $module_count - $span ) % 2 !== 0 ) {
			--$span;
		}

		return max( 1, $span );
	}

	private function hex_to_rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );

		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}
}
