<?php
/**
 * Plugin Name: NPX Menu Icons
 * Description: Add Material Symbols icons to WordPress navigation menu items.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:      Nolasoft
 * Text Domain: npx-menu-icons
 * Domain Path: /lang
 * License:     GPL-2.0-or-later
 *
 * @package NPX_Menu_Icons
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NPX_MENU_ICONS_VERSION',  '1.0.0' );
define( 'NPX_MENU_ICONS_FILE',     __FILE__ );
define( 'NPX_MENU_ICONS_DIR',      plugin_dir_path( __FILE__ ) );
define( 'NPX_MENU_ICONS_URL',      plugin_dir_url( __FILE__ ) );
define( 'NPX_MENU_ICONS_META_KEY', '_npx_menu_icon' );
define( 'NPX_MENU_ICONS_FONT_URL', 'https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&family=Material+Symbols+Sharp:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block' );

// ── Walker ───────────────────────────────────────────────────────────────────

// The wp_edit_nav_menu_walker filter fires inside wp_get_nav_menu_to_edit()
// during nav-menus.php rendering. By that point Walker_Nav_Menu_Edit is
// already loaded by nav-menu.php, so it is safe to require and extend it here.
add_filter( 'wp_edit_nav_menu_walker', function ( $class ) {
	require_once NPX_MENU_ICONS_DIR . 'includes/class-walker.php';
	return class_exists( 'NPX_Menu_Icons_Walker' ) ? 'NPX_Menu_Icons_Walker' : $class;
}, 99 );

// ── Admin ────────────────────────────────────────────────────────────────────
add_action( 'admin_enqueue_scripts',          'npx_mi_admin_enqueue' );
add_action( 'wp_nav_menu_item_custom_fields', 'npx_mi_hidden_inputs', 10, 4 );
add_action( 'wp_update_nav_menu_item',        'npx_mi_save_item', 10, 3 );

// ── Frontend ─────────────────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'npx_mi_frontend_enqueue' );
add_filter( 'nav_menu_item_title', 'npx_mi_render_title', 10, 4 );


// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Default field values for a nav menu icon entry.
 *
 * @return array
 */
function npx_mi_defaults() {
	return array(
		'name'       => '',
		'style'      => 'material-symbols-sharp',
		'fill'       => '0',
		'weight'     => '400',
		'position'   => 'before',
		'size'       => '1.5em',
		'color'      => '',
		'hide_label' => '0',
	);
}

/**
 * Load the Material Symbols icon list.
 *
 * Resolution order:
 *  1. Filtered path (custom override).
 *  2. Plugin-bundled JSON (includes/material-icons.json).
 *  3. Theme JSON (includes/data/material-icons.json) — legacy fallback.
 *
 * @return array Array of { n: string, c: string } objects.
 */
function npx_mi_get_icons() {
	// Serve from transient cache to avoid reading 150 KB JSON on every admin load.
	$cached = get_transient( 'npx_mi_icons' );
	if ( false !== $cached ) {
		return $cached;
	}

	$plugin_file = NPX_MENU_ICONS_DIR . 'includes/material-icons.json';
	$theme_file  = get_template_directory() . '/includes/data/material-icons.json';

	$default = file_exists( $plugin_file ) ? $plugin_file : $theme_file;

	/**
	 * Filters the path to the Material Symbols JSON data file.
	 *
	 * @param string $path Absolute path to the JSON file.
	 */
	$file = apply_filters( 'npx_menu_icons_data_file', $default );

	if ( ! file_exists( $file ) ) {
		return array();
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$raw  = file_get_contents( $file );
	$data = json_decode( $raw, true );
	$data = is_array( $data ) ? $data : array();

	// Cache for 24 hours. The refresh AJAX action busts this transient.
	set_transient( 'npx_mi_icons', $data, DAY_IN_SECONDS );

	return $data;
}

/**
 * AJAX handler: fetch a fresh icon list from the Google Fonts metadata API
 * and overwrite the plugin-bundled JSON. Mirrors the theme's npx_refresh_icons
 * handler exactly (same API URL, XSSI strip, dedup, category normalisation,
 * WP_Filesystem write).
 */
function npx_mi_ajax_refresh_icons() {
	// Capability check before nonce check — avoids leaking nonce validity to
	// lower-privilege users.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	check_ajax_referer( 'npx_mi_refresh_icons', 'nonce' );

	$url      = 'https://fonts.google.com/metadata/icons?incomplete=1&key=material_symbols';
	$response = wp_remote_get( $url, array( 'timeout' => 30 ) );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( array( 'message' => $response->get_error_message() ) );
	}

	$http_code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== (int) $http_code ) {
		/* translators: %d: HTTP status code */
		wp_send_json_error( array( 'message' => sprintf( __( 'Google API returned HTTP %d.', 'npx-menu-icons' ), $http_code ) ) );
	}

	$raw_data   = wp_remote_retrieve_body( $response );
	$clean_json = substr( $raw_data, 5 ); // Strip Google's ")]}'\n" XSSI prefix.
	$data       = json_decode( $clean_json, true );

	if ( ! isset( $data['icons'] ) ) {
		wp_send_json_error( array( 'message' => 'Invalid response from Google.' ) );
	}

	// Same normalisation map as the theme.
	$normalization_map = array(
		'av'          => 'audio_video',
		'audiovideo'  => 'audio_video',
		'audio_video' => 'audio_video',
		'actions'     => 'action',
		'ui_actions'  => 'action',
		'communicate' => 'communication',
		'images'      => 'image',
		'household'   => 'home',
		'transit'     => 'maps',
		'travel'      => 'maps',
	);

	$output = array();
	$seen   = array();

	foreach ( $data['icons'] as $icon ) {
		$name = $icon['name'];
		if ( isset( $seen[ $name ] ) ) {
			continue;
		}

		$raw_cat   = ! empty( $icon['categories'] ) ? $icon['categories'][0] : 'action';
		$clean_cat = strtolower( str_replace( array( ' ', '&' ), array( '_', '' ), $raw_cat ) );
		$final_cat = isset( $normalization_map[ $clean_cat ] ) ? $normalization_map[ $clean_cat ] : $clean_cat;

		$output[]      = array( 'n' => $name, 'c' => $final_cat );
		$seen[ $name ] = true;
	}

	// Write via WP_Filesystem for hosting compatibility.
	global $wp_filesystem;
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! WP_Filesystem() || ! $wp_filesystem ) {
		wp_send_json_error( array( 'message' => 'Filesystem initialization failed.' ) );
	}

	$dest = NPX_MENU_ICONS_DIR . 'includes/material-icons.json';
	if ( ! $wp_filesystem->put_contents( $dest, wp_json_encode( $output ), FS_CHMOD_FILE ) ) {
		wp_send_json_error( array( 'message' => 'Could not write icons file.' ) );
	}

	// Bust the transient so the next admin page load reads the fresh file.
	delete_transient( 'npx_mi_icons' );

	wp_send_json_success( array(
		'count' => count( $output ),
	) );
}
add_action( 'wp_ajax_npx_mi_refresh_icons', 'npx_mi_ajax_refresh_icons' );

/**
 * Sanitize a CSS color value.
 * Allows: letters, digits, #, ( ) , . % space, hyphen — covers hex, rgb(),
 * rgba(), hsl(), named colors, inherit, currentColor, CSS custom properties.
 *
 * @param  string $color Raw color string.
 * @return string        Sanitized color, or empty string if invalid.
 */
function npx_mi_sanitize_color( $color ) {
	$color = trim( (string) $color );

	if ( '' === $color ) {
		return '';
	}

	// Allow only characters that are valid in CSS color values.
	if ( preg_match( '/^[a-zA-Z0-9#()\-,.\s%]+$/', $color ) ) {
		return $color;
	}

	return '';
}


// ── Admin enqueue ────────────────────────────────────────────────────────────

/**
 * Enqueue scripts and styles on the Menus admin screen.
 *
 * @param string $hook Current admin page hook.
 */
function npx_mi_admin_enqueue( $hook ) {
	if ( 'nav-menus.php' !== $hook ) {
		return;
	}

	// Google Fonts — use the same handle as the theme to avoid duplicate loads.
	wp_enqueue_style(
		'npx-material-symbols',
		NPX_MENU_ICONS_FONT_URL,
		array(),
		null
	);

	// Load wp-components stylesheet so components-button classes render correctly.
	wp_enqueue_style( 'wp-components' );

	wp_enqueue_style(
		'npx-menu-icons-admin',
		NPX_MENU_ICONS_URL . 'assets/css/admin.css',
		array( 'wp-components' ),
		NPX_MENU_ICONS_VERSION
	);

	wp_enqueue_script(
		'npx-menu-icons-admin',
		NPX_MENU_ICONS_URL . 'assets/js/admin.js',
		array( 'jquery' ),
		NPX_MENU_ICONS_VERSION,
		true
	);

	// Build theme color palette — same pattern used by the blocks editor.
	$theme_colors = array();
	if ( function_exists( 'npx_color_config' ) ) {
		foreach ( npx_color_config() as $slug => $color ) {
			$saved = get_option( 'option_color_' . $slug, $color['hex'] );
			$theme_colors[] = array(
				'name'  => $color['label'],
				'slug'  => $slug,
				'color' => $saved ?: $color['hex'],
			);
		}
	}

	wp_localize_script(
		'npx-menu-icons-admin',
		'npxMenuIconsData',
		array(
			'icons'       => npx_mi_get_icons(),
			'themeColors' => $theme_colors,
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'npx_mi_refresh_icons' ),
		)
	);
}


// ── Handle bar button ────────────────────────────────────────────────────────

/**
 * Returns the HTML for the icon indicator button injected into the handle bar.
 * Shows the current icon glyph (if set) or a subtle "+" indicator (if not).
 *
 * @param  WP_Post $item The menu item post object.
 * @return string        Button HTML.
 */
function npx_mi_handle_button( $item ) {
	$saved    = get_post_meta( $item->ID, NPX_MENU_ICONS_META_KEY, true );
	$icon     = wp_parse_args( is_array( $saved ) ? $saved : array(), npx_mi_defaults() );
	$has_icon = ! empty( $icon['name'] );

	$fv = "font-variation-settings: 'FILL' " . esc_attr( $icon['fill'] )
		. ", 'wght' " . esc_attr( $icon['weight'] ) . ';';

	ob_start();
	?>
	<button type="button"
	        class="npx-mi-handle-btn<?php echo $has_icon ? ' has-icon' : ''; ?>"
	        data-item-id="<?php echo esc_attr( $item->ID ); ?>"
	        title="<?php echo $has_icon
				? esc_attr( str_replace( '_', ' ', $icon['name'] ) )
				: esc_attr__( 'Add icon', 'npx-menu-icons' ); ?>">
		<?php if ( $has_icon ) : ?>
			<span class="<?php echo esc_attr( $icon['style'] ); ?>"
			      style="<?php echo esc_attr( $fv ); ?>"><?php echo esc_html( $icon['name'] ); ?></span>
		<?php else : ?>
			<span class="material-symbols-sharp npx-mi-add-glyph">add_circle</span>
		<?php endif; ?>
	</button>
	<?php
	return ob_get_clean();
}


// ── Hidden inputs (accordion) ────────────────────────────────────────────────

/**
 * Output hidden form inputs inside the accordion. These carry all values to
 * the form POST when the user saves the menu. JS writes to them via the modal.
 *
 * @param int      $item_id Menu item post ID.
 * @param WP_Post  $item    Menu item post object.
 * @param int      $depth   Depth of the item in the tree.
 * @param stdClass $args    Nav menu args.
 */
function npx_mi_hidden_inputs( $item_id, $item, $depth, $args ) {
	$saved = get_post_meta( $item_id, NPX_MENU_ICONS_META_KEY, true );
	$icon  = wp_parse_args( is_array( $saved ) ? $saved : array(), npx_mi_defaults() );
	$id    = esc_attr( $item_id );
	?>
	<div class="npx-mi-inputs" style="display:none;">
		<input type="hidden" class="npx-mi-inp-name"     name="npx_menu_icon[<?php echo $id; ?>][name]"       value="<?php echo esc_attr( $icon['name'] ); ?>">
		<input type="hidden" class="npx-mi-inp-style"    name="npx_menu_icon[<?php echo $id; ?>][style]"      value="<?php echo esc_attr( $icon['style'] ); ?>">
		<input type="hidden" class="npx-mi-inp-fill"     name="npx_menu_icon[<?php echo $id; ?>][fill]"       value="<?php echo esc_attr( $icon['fill'] ); ?>">
		<input type="hidden" class="npx-mi-inp-weight"   name="npx_menu_icon[<?php echo $id; ?>][weight]"     value="<?php echo esc_attr( $icon['weight'] ); ?>">
		<input type="hidden" class="npx-mi-inp-position" name="npx_menu_icon[<?php echo $id; ?>][position]"   value="<?php echo esc_attr( $icon['position'] ); ?>">
		<input type="hidden" class="npx-mi-inp-size"     name="npx_menu_icon[<?php echo $id; ?>][size]"       value="<?php echo esc_attr( $icon['size'] ); ?>">
		<input type="hidden" class="npx-mi-inp-color"    name="npx_menu_icon[<?php echo $id; ?>][color]"      value="<?php echo esc_attr( $icon['color'] ); ?>">
		<input type="hidden" class="npx-mi-inp-hide"     name="npx_menu_icon[<?php echo $id; ?>][hide_label]" value="<?php echo esc_attr( $icon['hide_label'] ); ?>">
	</div>
	<?php
}


// ── Save ─────────────────────────────────────────────────────────────────────

/**
 * Validate and save icon meta when a nav menu item is updated.
 *
 * @param int   $menu_id         The ID of the updated menu.
 * @param int   $menu_item_db_id The post ID of the menu item.
 * @param array $menu_item_args  The menu item args passed to wp_update_post.
 */
function npx_mi_save_item( $menu_id, $menu_item_db_id, $menu_item_args ) {
	if ( wp_doing_ajax() ) {
		return;
	}

	// The nonce is verified by WordPress before this action fires; verify again
	// as defence-in-depth.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( ! check_admin_referer( 'update-nav_menu', 'update-nav-menu-nonce' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
	if ( empty( $_POST['npx_menu_icon'][ $menu_item_db_id ] ) ) {
		delete_post_meta( $menu_item_db_id, NPX_MENU_ICONS_META_KEY );
		delete_transient( 'npx_mi_has_icons' );
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
	$raw = (array) $_POST['npx_menu_icon'][ $menu_item_db_id ];

	$valid_styles    = array( 'material-symbols-sharp', 'material-symbols-rounded' );
	$valid_fills     = array( '0', '1' );
	$valid_weights   = array( '100', '200', '300', '400', '500', '600', '700' );
	$valid_positions = array( 'before', 'after' );

	// Icon names from Google are lowercase alphanumeric + underscores only.
	$name = sanitize_key( $raw['name'] ?? '' );
	// sanitize_key also lowercases and strips non-alphanumeric except hyphens;
	// additionally reject anything that isn't a valid symbol name.
	if ( ! preg_match( '/^[a-z0-9_]+$/', $name ) ) {
		$name = '';
	}

	if ( empty( $name ) ) {
		delete_post_meta( $menu_item_db_id, NPX_MENU_ICONS_META_KEY );
		delete_transient( 'npx_mi_has_icons' );
		return;
	}

	$style    = $raw['style']    ?? 'material-symbols-sharp';
	$fill     = $raw['fill']     ?? '0';
	$weight   = $raw['weight']   ?? '400';
	$position = $raw['position'] ?? 'before';

	// Parse size string (e.g. "1.5em", "24px", "1.25rem").
	$raw_size = sanitize_text_field( $raw['size'] ?? '1.5em' );
	if ( preg_match( '/^(\d+(?:\.\d+)?)(px|rem|em)$/', $raw_size, $sm ) ) {
		$unit     = $sm[2];
		$num      = ( 'px' === $unit )
			? round( max( 8.0, min( 128.0, (float) $sm[1] ) ) )
			: round( max( 0.5, min( 4.0,   (float) $sm[1] ) ), 2 );
		$size = $num . $unit;
	} else {
		$size = '1.5em';
	}

	$icon = array(
		'name'       => $name,
		'style'      => in_array( $style,    $valid_styles,    true ) ? $style    : 'material-symbols-sharp',
		'fill'       => in_array( $fill,     $valid_fills,     true ) ? $fill     : '0',
		'weight'     => in_array( $weight,   $valid_weights,   true ) ? $weight   : '400',
		'position'   => in_array( $position, $valid_positions, true ) ? $position : 'before',
		'size'       => $size,
		'color'      => npx_mi_sanitize_color( $raw['color'] ?? '' ),
		'hide_label' => isset( $raw['hide_label'] ) && '1' === (string) $raw['hide_label'] ? '1' : '0',
	);

	update_post_meta( $menu_item_db_id, NPX_MENU_ICONS_META_KEY, $icon );
	// Ensure the frontend enqueue check picks up the new state.
	delete_transient( 'npx_mi_has_icons' );
}


// ── Frontend enqueue ─────────────────────────────────────────────────────────

/**
 * Enqueue the Material Symbols font and plugin stylesheet on the frontend.
 */
function npx_mi_frontend_enqueue() {
	// Only load assets when at least one nav menu item has an icon set.
	// Cache the result to avoid a DB query on every frontend page load.
	// The transient is busted by npx_mi_save_item on any icon save or removal.
	$cached = get_transient( 'npx_mi_has_icons' );

	if ( false === $cached ) {
		global $wpdb;
		$count  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				NPX_MENU_ICONS_META_KEY
			)
		);
		$cached = $count > 0 ? '1' : '0';
		set_transient( 'npx_mi_has_icons', $cached, 12 * HOUR_IN_SECONDS );
	}

	if ( '0' === $cached ) {
		return;
	}

	// Use the same handle as the theme so WordPress deduplicates on overlap.
	wp_enqueue_style(
		'npx-material-symbols',
		NPX_MENU_ICONS_FONT_URL,
		array(),
		null
	);

	wp_enqueue_style(
		'npx-menu-icons-frontend',
		NPX_MENU_ICONS_URL . 'assets/css/frontend.css',
		array(),
		NPX_MENU_ICONS_VERSION
	);
}


// ── Frontend render ──────────────────────────────────────────────────────────

/**
 * Inject the icon <span> into the nav menu item title on the frontend.
 *
 * @param  string   $title     The menu item title.
 * @param  WP_Post  $menu_item The current menu item post object.
 * @param  stdClass $args      Nav menu arguments.
 * @param  int      $depth     Depth of the item.
 * @return string              Possibly modified title HTML.
 */
function npx_mi_render_title( $title, $menu_item, $args, $depth ) {
	if ( is_admin() || wp_doing_ajax() ) {
		return $title;
	}

	$icon = get_post_meta( $menu_item->ID, NPX_MENU_ICONS_META_KEY, true );

	if ( ! is_array( $icon ) || empty( $icon['name'] ) ) {
		return $title;
	}

	// Merge with defaults to handle partial/legacy saves.
	$icon = wp_parse_args( $icon, npx_mi_defaults() );

	$valid_fills   = array( '0', '1' );
	$valid_weights = array( '100', '200', '300', '400', '500', '600', '700' );

	$family     = 'material-symbols-rounded' === $icon['style']
		? 'Material Symbols Rounded'
		: 'Material Symbols Sharp';
	$fill_val   = in_array( $icon['fill'],   $valid_fills,   true ) ? $icon['fill']   : '0';
	$weight_val = in_array( $icon['weight'], $valid_weights, true ) ? $icon['weight'] : '400';

	// Parse size — supports px / rem / em.
	$size_str = sanitize_text_field( $icon['size'] );
	if ( preg_match( '/^(\d+(?:\.\d+)?)(px|rem|em)$/', $size_str, $sm ) ) {
		$size_css = $size_str;
	} else {
		// Legacy: plain number saved as em.
		$size_css = round( max( 0.5, min( 4.0, (float) $size_str ) ), 2 ) . 'em';
	}

	$inline  = "font-family: '" . esc_attr( $family ) . "' !important;";
	$inline .= " font-variation-settings: 'FILL' {$fill_val}, 'wght' {$weight_val};";
	$inline .= ' font-size: ' . esc_attr( $size_css ) . ';';

	$color = npx_mi_sanitize_color( $icon['color'] );
	if ( $color ) {
		$inline .= ' color: ' . esc_attr( $color ) . ';';
	}

	$classes   = 'npx-menu-icon ' . esc_attr( $icon['style'] );
	$icon_html = '<span class="' . $classes . '" aria-hidden="true" style="' . $inline . '">'
		. esc_html( $icon['name'] )
		. '</span>';

	// Always wrap the label text so it can be targeted with CSS independently
	// of the icon span (e.g. to adjust spacing or hide it via the theme).
	if ( '1' === $icon['hide_label'] ) {
		$label_html = '<span class="npx-menu-icon-label screen-reader-text">' . $title . '</span>';
	} else {
		$label_html = '<span class="npx-menu-icon-label">' . $title . '</span>';
	}

	return 'after' === $icon['position']
		? $label_html . $icon_html
		: $icon_html . $label_html;
}
