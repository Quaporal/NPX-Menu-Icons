<?php
/**
 * Custom Walker for the nav menus admin screen.
 *
 * Extends Walker_Nav_Menu_Edit to inject the NPX Menu Icons picker button
 * into the handle bar of each menu item row, before .menu-item-handle.
 *
 * This file is required on-demand from the 'wp_edit_nav_menu_walker' filter
 * callback. By the time that filter fires, Walker_Nav_Menu_Edit is already
 * loaded by wp-admin/includes/nav-menu.php, so extending it here is safe.
 *
 * @package NPX_Menu_Icons
 */

if ( ! class_exists( 'NPX_Menu_Icons_Walker' ) ) {

	class NPX_Menu_Icons_Walker extends Walker_Nav_Menu_Edit {

		/**
		 * Render one menu item row and inject our icon button into the
		 * handle bar — before .menu-item-handle, so it sits in the same
		 * visual row as the item title (matches wp-menu-icons behaviour).
		 */
		public function start_el( &$output, $data_object, $depth = 0, $args = null, $current_object_id = 0 ) {
			$item_output = '';
			parent::start_el( $item_output, $data_object, $depth, $args, $current_object_id );

			// Inject the icon handle button right before <div class="menu-item-handle">.
			$item_output = preg_replace(
				'/(?=<div[^>]+class="[^"]*menu-item-handle)/',
				npx_mi_handle_button( $data_object ),
				$item_output,
				1
			);

			$output .= $item_output;
		}
	}
}
