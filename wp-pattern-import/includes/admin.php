<?php
/**
 * Admin UI for WP Pattern Import.
 *
 * @package WPPatternImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'wpi_register_admin_page' );
add_action( 'admin_enqueue_scripts', 'wpi_enqueue_admin_assets' );
add_action( 'admin_post_wpi_scan', 'wpi_handle_scan' );
add_action( 'admin_post_wpi_save_recipe', 'wpi_handle_save_recipe' );
add_action( 'admin_post_wpi_run_import', 'wpi_handle_run_import' );
add_action( 'admin_post_wpi_test_import', 'wpi_handle_test_import' );

/**
 * Register Tools page.
 *
 * @return void
 */
function wpi_register_admin_page() {
	add_management_page(
		__( 'Pattern Import', 'wp-pattern-import' ),
		__( 'Pattern Import', 'wp-pattern-import' ),
		'manage_options',
		'wp-pattern-import',
		'wpi_render_admin_page'
	);
}

/**
 * Enqueue admin JavaScript.
 *
 * @param string $hook Current admin hook.
 * @return void
 */
function wpi_enqueue_admin_assets( $hook ) {
	if ( 'tools_page_wp-pattern-import' !== $hook ) {
		return;
	}

	wp_enqueue_script( 'wpi-admin', WPI_PLUGIN_URL . 'assets/admin.js', array(), WPI_VERSION, true );
}

/**
 * Render the admin page.
 *
 * @return void
 */
function wpi_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-pattern-import' ) );
	}

	$recipe  = wpi_get_recipe_for_form();
	if ( isset( $_GET['wpi_pattern'] ) ) {
		$recipe['active_pattern'] = max( 0, absint( $_GET['wpi_pattern'] ) );
		$recipe = wpi_sync_active_pattern_to_top_level( $recipe );
	}
	$last    = get_option( 'wpi_last_run', array() );
	$scan    = get_transient( 'wpi_scan_' . get_current_user_id() );
	$message = isset( $_GET['wpi_message'] ) ? sanitize_key( wp_unslash( $_GET['wpi_message'] ) ) : '';
	if ( 'recipe_saved' === $message ) {
		delete_transient( 'wpi_scan_' . get_current_user_id() );
		$scan = false;
	}
	if ( is_array( $scan ) && ! empty( $scan['recipe'] ) && is_array( $scan['recipe'] ) ) {
		$recipe = wp_parse_args( $scan['recipe'], $recipe );
	}
	$error   = isset( $_GET['wpi_error'] ) ? sanitize_text_field( wp_unslash( $_GET['wpi_error'] ) ) : '';
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'WP Pattern Import', 'wp-pattern-import' ); ?></h1>

		<?php if ( $message ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( wpi_admin_message_text( $message ) ); ?></p></div>
		<?php endif; ?>

		<?php if ( $error ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>

		<?php wpi_render_saved_patterns_list( $recipe ); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wpi_save_recipe' ); ?>
			<input type="hidden" name="action" value="wpi_save_recipe" />
			<input type="hidden" name="scan_mode" value="loop" />
			<input type="hidden" name="active_pattern" value="<?php echo esc_attr( $recipe['active_pattern'] ); ?>" />

			<nav class="nav-tab-wrapper wpi-tabs" aria-label="<?php echo esc_attr__( 'Pattern import steps', 'wp-pattern-import' ); ?>">
				<a href="#wpi-tab-plan" class="nav-tab nav-tab-active"><?php echo esc_html__( '1. URL', 'wp-pattern-import' ); ?></a>
				<a href="#wpi-tab-map" class="nav-tab"><?php echo esc_html__( '2. Select & Map', 'wp-pattern-import' ); ?></a>
				<a href="#wpi-tab-detail" class="nav-tab"><?php echo esc_html__( '3. Detail Page', 'wp-pattern-import' ); ?></a>
				<a href="#wpi-tab-content" class="nav-tab"><?php echo esc_html__( '4. Content Pattern', 'wp-pattern-import' ); ?></a>
				<a href="#wpi-tab-import" class="nav-tab"><?php echo esc_html__( '5. Destination & Import', 'wp-pattern-import' ); ?></a>
			</nav>

			<div id="wpi-tab-plan" class="wpi-tab-panel">
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="wpi-url"><?php echo esc_html__( 'Source URL', 'wp-pattern-import' ); ?></label></th>
							<td><input name="url" id="wpi-url" type="url" class="regular-text code" value="<?php echo esc_attr( $recipe['url'] ); ?>" required /></td>
						</tr>
					</tbody>
				</table>
				<p>
					<button type="submit" class="button" name="action" value="wpi_scan">
						<?php echo esc_html__( 'Scan Page for Item Selector', 'wp-pattern-import' ); ?>
					</button>
				</p>
				<?php wpi_render_loop_scan_results( $scan ); ?>
			</div>

			<div id="wpi-tab-map" class="wpi-tab-panel" hidden>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Source URL', 'wp-pattern-import' ); ?></th>
							<td><input type="url" class="regular-text code" value="<?php echo esc_attr( $recipe['url'] ); ?>" disabled /></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpi-item-selector"><?php echo esc_html__( 'Item selector', 'wp-pattern-import' ); ?></label></th>
							<td><input name="item_selector" id="wpi-item-selector" type="text" class="regular-text code" value="<?php echo esc_attr( $recipe['item_selector'] ); ?>" placeholder=".views-row" /></td>
						</tr>
					</tbody>
				</table>
				<p>
					<button type="submit" class="button" name="action" value="wpi_scan" onclick="this.form.scan_mode.value='fields';">
						<?php echo esc_html__( 'Scan Selected Items for Fields', 'wp-pattern-import' ); ?>
					</button>
					<button type="submit" class="button button-primary" name="save_context" value="map">
						<?php echo esc_html__( 'Save Mapping', 'wp-pattern-import' ); ?>
					</button>
				</p>
				<?php wpi_render_field_mapping_results( $scan, $recipe ); ?>
			</div>

			<div id="wpi-tab-detail" class="wpi-tab-panel" hidden>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Detail URL field', 'wp-pattern-import' ); ?></th>
							<td><input type="text" class="regular-text code" value="<?php echo esc_attr( $recipe['unique_target'] ); ?>" disabled /></td>
						</tr>
					</tbody>
				</table>
				<p>
					<button type="submit" class="button" name="action" value="wpi_scan" onclick="this.form.scan_mode.value='detail';">
						<?php echo esc_html__( 'Scan First Detail Page for Fields', 'wp-pattern-import' ); ?>
					</button>
					<button type="submit" class="button button-primary" name="save_context" value="detail">
						<?php echo esc_html__( 'Save Detail Mapping', 'wp-pattern-import' ); ?>
					</button>
				</p>
				<?php wpi_render_detail_mapping_results( $scan, $recipe ); ?>
			</div>

			<div id="wpi-tab-content" class="wpi-tab-panel" hidden>
				<h2><?php echo esc_html__( 'Content Pattern', 'wp-pattern-import' ); ?></h2>
				<p><?php echo esc_html__( 'Available tokens can be used in the HTML below. Rendered content is appended to mapped post_content.', 'wp-pattern-import' ); ?></p>
				<?php wpi_render_content_tokens( $recipe, $scan ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="wpi-content-template"><?php echo esc_html__( 'HTML template', 'wp-pattern-import' ); ?></label></th>
							<td>
								<textarea name="content_template" id="wpi-content-template" class="large-text code" rows="10"><?php echo esc_textarea( $recipe['content_template'] ); ?></textarea>
							</td>
						</tr>
					</tbody>
				</table>
				<p>
					<button type="submit" class="button button-primary" name="save_context" value="content">
						<?php echo esc_html__( 'Save Content Pattern', 'wp-pattern-import' ); ?>
					</button>
				</p>
			</div>

			<div id="wpi-tab-import" class="wpi-tab-panel" hidden>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="wpi-post-type"><?php echo esc_html__( 'Target post type', 'wp-pattern-import' ); ?></label></th>
							<td><?php wpi_render_post_type_select( $recipe['post_type'] ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpi-import-post-status"><?php echo esc_html__( 'Import as post_status', 'wp-pattern-import' ); ?></label></th>
							<td><?php wpi_render_post_status_select( $recipe['import_post_status'] ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpi-unique-target"><?php echo esc_html__( 'Unique key', 'wp-pattern-import' ); ?></label></th>
							<td><input name="unique_target" id="wpi-unique-target" type="text" class="regular-text code" value="<?php echo esc_attr( $recipe['unique_target'] ); ?>" placeholder="meta:source_url" /></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Schedule', 'wp-pattern-import' ); ?></th>
							<td>
								<label><input type="radio" name="schedule" value="manual" <?php checked( $recipe['schedule'], 'manual' ); ?> /> <?php echo esc_html__( 'Manual only', 'wp-pattern-import' ); ?></label><br />
								<label><input type="radio" name="schedule" value="daily" <?php checked( $recipe['schedule'], 'daily' ); ?> /> <?php echo esc_html__( 'Daily', 'wp-pattern-import' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpi-schedule-time"><?php echo esc_html__( 'Daily import time', 'wp-pattern-import' ); ?></label></th>
							<td><input name="schedule_time" id="wpi-schedule-time" type="time" value="<?php echo esc_attr( $recipe['schedule_time'] ); ?>" /></td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary" name="save_context" value="import"><?php echo esc_html__( 'Save Recipe', 'wp-pattern-import' ); ?></button>
					<button type="submit" class="button" name="action" value="wpi_test_import"><?php echo esc_html__( 'Test Import One Item From This Pattern', 'wp-pattern-import' ); ?></button>
					<button type="submit" class="button" name="action" value="wpi_run_import"><?php echo esc_html__( 'Run This Pattern', 'wp-pattern-import' ); ?></button>
				</p>

				<?php wpi_render_mapping_summary( $recipe ); ?>
				<?php wpi_render_last_run( $last ); ?>
			</div>
		</form>
	</div>
	<?php
}

/**
 * Handle scan action.
 *
 * @return void
 */
function wpi_handle_scan() {
	wpi_require_admin_action( 'wpi_save_recipe' );

	$recipe = wpi_sanitize_recipe( wp_unslash( $_POST ) );
	if ( ! wpi_is_valid_source_url( $recipe['url'] ) ) {
		wpi_redirect_with_error( __( 'Please enter a valid HTTP or HTTPS source URL.', 'wp-pattern-import' ) );
	}

	$scan_mode     = isset( $_POST['scan_mode'] ) ? sanitize_key( wp_unslash( $_POST['scan_mode'] ) ) : 'loop';
	$item_selector = in_array( $scan_mode, array( 'fields', 'detail' ), true ) ? $recipe['item_selector'] : '';
	$scan          = wpi_scan_url_for_patterns( $recipe['url'], $item_selector );
	if ( is_wp_error( $scan ) ) {
		wpi_redirect_with_error( $scan->get_error_message() );
	}

	if ( 'detail' === $scan_mode ) {
		$detail_scan = wpi_scan_first_detail_page( $recipe );
		if ( is_wp_error( $detail_scan ) ) {
			wpi_redirect_with_error( $detail_scan->get_error_message() );
		}
		$scan['detail'] = $detail_scan;
	}

	$samples = array();
	if ( ! empty( $recipe['item_selector'] ) ) {
		foreach ( $scan['sources'] as $source_url ) {
			$html = wpi_fetch_html( $source_url );
			if ( is_wp_error( $html ) ) {
				continue;
			}

			$samples = wpi_extract_sample_items( $html, $recipe['item_selector'] );
			if ( ! empty( $samples ) ) {
				break;
			}
		}
	}

	set_transient(
		'wpi_scan_' . get_current_user_id(),
		array(
			'patterns' => $scan['patterns'],
			'item'     => $scan['item'],
			'detail'   => isset( $scan['detail'] ) ? $scan['detail'] : array(),
			'sources'  => $scan['sources'],
			'samples'  => $samples,
			'recipe'   => $recipe,
		),
		10 * MINUTE_IN_SECONDS
	);

	$target_tab = 'fields' === $scan_mode ? '#wpi-tab-map' : '#wpi-tab-plan';
	if ( 'detail' === $scan_mode ) {
		$target_tab = '#wpi-tab-detail';
	}
	wp_safe_redirect( add_query_arg( 'wpi_message', 'scan_complete', wpi_admin_url() ) . $target_tab );
	exit;
}

/**
 * Scan the first linked detail page from the current recipe.
 *
 * @param array<string,mixed> $recipe Recipe.
 * @return array<string,mixed>|WP_Error
 */
function wpi_scan_first_detail_page( $recipe ) {
	$html = wpi_fetch_html( $recipe['url'] );
	if ( is_wp_error( $html ) ) {
		return $html;
	}

	$source = wpi_find_import_source( $html, $recipe, $recipe['url'] );
	$dom    = wpi_dom_from_html( $source['html'] );
	if ( ! $dom ) {
		return new WP_Error( 'wpi_detail_parse_error', __( 'Could not parse the source page.', 'wp-pattern-import' ) );
	}

	$items = wpi_query_selector_all( $dom, $recipe['item_selector'] );
	if ( empty( $items ) ) {
		return new WP_Error( 'wpi_detail_no_items', __( 'The item selector did not match any items.', 'wp-pattern-import' ) );
	}

	$detail_url = wpi_find_detail_url_from_item_node( $items[0], $recipe, $source['url'] );
	if ( empty( $detail_url ) ) {
		return new WP_Error( 'wpi_detail_no_url', __( 'Could not find a detail URL from the first item. Map an href field first.', 'wp-pattern-import' ) );
	}

	$detail_html = wpi_fetch_html( $detail_url );
	if ( is_wp_error( $detail_html ) ) {
		return $detail_html;
	}

	return array(
		'url'    => $detail_url,
		'fields' => wpi_scan_detail_fields( $detail_html, $detail_url ),
	);
}

/**
 * Find a detail URL from an item node using href mappings first.
 *
 * @param DOMElement          $item_node Item node.
 * @param array<string,mixed> $recipe Recipe.
 * @param string              $source_url Source URL.
 * @return string
 */
function wpi_find_detail_url_from_item_node( $item_node, $recipe, $source_url ) {
	foreach ( $recipe['fields'] as $field ) {
		if ( ! is_array( $field ) || empty( $field['selector'] ) || 'href' !== ( isset( $field['attr'] ) ? $field['attr'] : '' ) ) {
			continue;
		}

		$url = wpi_extract_field( $item_node, $field, $source_url );
		if ( $url && wp_http_validate_url( $url ) ) {
			return $url;
		}
	}

	$links = wpi_query_selector_all( $item_node, 'a' );
	foreach ( $links as $link ) {
		$url = wpi_resolve_url( $link->getAttribute( 'href' ), $source_url );
		if ( $url && wp_http_validate_url( $url ) ) {
			return $url;
		}
	}

	return '';
}

/**
 * Handle recipe save.
 *
 * @return void
 */
function wpi_handle_save_recipe() {
	wpi_require_admin_action( 'wpi_save_recipe' );

	$recipe = wpi_sanitize_recipe( wp_unslash( $_POST ) );
	if ( ! wpi_is_valid_source_url( $recipe['url'] ) ) {
		wpi_redirect_with_error( __( 'Please enter a valid HTTP or HTTPS source URL.', 'wp-pattern-import' ) );
	}
	if ( 'daily' === $recipe['schedule'] && ! wpi_recipe_has_unique_target( $recipe ) ) {
		wpi_redirect_with_error( __( 'Choose a mapped field for the unique target before saving a schedule.', 'wp-pattern-import' ) );
	}

	update_option( 'wpi_recipe', $recipe, false );
	wpi_update_schedule( $recipe['schedule'], $recipe['schedule_time'] );

	$save_context = isset( $_POST['save_context'] ) ? sanitize_key( wp_unslash( $_POST['save_context'] ) ) : 'import';
	$target_tab   = '#wpi-tab-import';
	if ( 'map' === $save_context ) {
		$target_tab = '#wpi-tab-detail';
	}
	if ( 'detail' === $save_context ) {
		$target_tab = '#wpi-tab-content';
	}
	if ( 'content' === $save_context ) {
		$target_tab = '#wpi-tab-import';
	}
	wp_safe_redirect( add_query_arg( 'wpi_message', 'recipe_saved', wpi_admin_url() ) . $target_tab );
	exit;
}

/**
 * Handle manual import.
 *
 * @return void
 */
function wpi_handle_run_import() {
	wpi_require_admin_action( 'wpi_save_recipe' );

	$posted_recipe = wpi_sanitize_recipe( wp_unslash( $_POST ) );
	if ( ! wpi_is_valid_source_url( $posted_recipe['url'] ) ) {
		wpi_redirect_with_error( __( 'Please enter a valid HTTP or HTTPS source URL.', 'wp-pattern-import' ) );
	}
	if ( 'daily' === $posted_recipe['schedule'] && ! wpi_recipe_has_unique_target( $posted_recipe ) ) {
		wpi_redirect_with_error( __( 'Choose a mapped field for the unique target before saving a schedule.', 'wp-pattern-import' ) );
	}

	if ( ! empty( $posted_recipe['url'] ) && ! empty( $posted_recipe['item_selector'] ) ) {
		update_option( 'wpi_recipe', $posted_recipe, false );
		wpi_update_schedule( $posted_recipe['schedule'], $posted_recipe['schedule_time'] );
	}

	$result = wpi_run_import( 'manual', 0, $posted_recipe['url'] );
	if ( 'error' === $result['status'] ) {
		wpi_redirect_with_error( $result['message'] );
	}

	wp_safe_redirect( add_query_arg( 'wpi_message', 'import_complete', wpi_admin_url() ) . '#wpi-tab-import' );
	exit;
}

/**
 * Handle test import of one item.
 *
 * @return void
 */
function wpi_handle_test_import() {
	wpi_require_admin_action( 'wpi_save_recipe' );

	$posted_recipe = wpi_sanitize_recipe( wp_unslash( $_POST ) );
	if ( ! wpi_is_valid_source_url( $posted_recipe['url'] ) ) {
		wpi_redirect_with_error( __( 'Please enter a valid HTTP or HTTPS source URL.', 'wp-pattern-import' ) );
	}
	if ( 'daily' === $posted_recipe['schedule'] && ! wpi_recipe_has_unique_target( $posted_recipe ) ) {
		wpi_redirect_with_error( __( 'Choose a mapped field for the unique target before saving a schedule.', 'wp-pattern-import' ) );
	}

	update_option( 'wpi_recipe', $posted_recipe, false );
	wpi_update_schedule( $posted_recipe['schedule'], $posted_recipe['schedule_time'] );

	$result = wpi_run_import( 'test', 1, $posted_recipe['url'] );
	if ( 'error' === $result['status'] ) {
		wpi_redirect_with_error( $result['message'] );
	}

	wp_safe_redirect( add_query_arg( 'wpi_message', 'test_complete', wpi_admin_url() ) . '#wpi-tab-import' );
	exit;
}

/**
 * Require capability and nonce for admin actions.
 *
 * @param string $nonce_action Nonce action.
 * @return void
 */
function wpi_require_admin_action( $nonce_action ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wp-pattern-import' ) );
	}

	check_admin_referer( $nonce_action );
}

/**
 * Sanitize a recipe from submitted data.
 *
 * @param array<string,mixed> $data Submitted data.
 * @return array<string,mixed>
 */
function wpi_sanitize_recipe( $data ) {
	$existing = wpi_normalize_recipe( get_option( 'wpi_recipe', array() ) );
	$post_type = isset( $data['post_type'] ) ? sanitize_key( $data['post_type'] ) : 'post';
	if ( ! post_type_exists( $post_type ) ) {
		$post_type = 'post';
	}

	$schedule = isset( $data['schedule'] ) && 'daily' === $data['schedule'] ? 'daily' : 'manual';

	$recipe = array(
		'url'           => isset( $data['url'] ) ? esc_url_raw( trim( $data['url'] ), array( 'http', 'https' ) ) : '',
		'post_type'     => $post_type,
		'import_post_status' => isset( $data['import_post_status'] ) ? wpi_sanitize_import_post_status( $data['import_post_status'] ) : 'draft',
		'item_selector' => isset( $data['item_selector'] ) ? wpi_sanitize_selector( $data['item_selector'] ) : '',
		'unique_target' => isset( $data['unique_target'] ) ? wpi_sanitize_target( $data['unique_target'] ) : 'meta:source_url',
		'schedule'      => $schedule,
		'schedule_time' => isset( $data['schedule_time'] ) ? wpi_sanitize_schedule_time( $data['schedule_time'] ) : '02:00',
		'active_pattern' => isset( $data['active_pattern'] ) ? max( 0, absint( $data['active_pattern'] ) ) : 0,
		'patterns'      => isset( $existing['patterns'] ) ? $existing['patterns'] : array(),
		'pattern_name'  => '',
		'content_template' => isset( $data['content_template'] ) ? wp_kses_post( $data['content_template'] ) : '',
		'fields'        => array(),
		'detail_fields' => array(),
	);

	if ( '' === $recipe['unique_target'] ) {
		$recipe['unique_target'] = 'meta:source_url';
	}

	$featured_image_field = isset( $data['featured_image_field'] ) ? sanitize_text_field( $data['featured_image_field'] ) : '';
	$recipe['fields'] = wpi_sanitize_mapping_fields(
		isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array(),
		$featured_image_field,
		'item'
	);
	$recipe['detail_fields'] = wpi_sanitize_mapping_fields(
		isset( $data['detail_fields'] ) && is_array( $data['detail_fields'] ) ? $data['detail_fields'] : array(),
		$featured_image_field,
		'detail'
	);
	$recipe = wpi_save_active_pattern_to_patterns( $recipe );
	$recipe = wpi_normalize_recipe( $recipe );

	return $recipe;
}

/**
 * Normalize a recipe into the current multi-pattern shape.
 *
 * @param mixed $recipe Recipe-like value.
 * @return array<string,mixed>
 */
function wpi_normalize_recipe( $recipe ) {
	if ( ! is_array( $recipe ) ) {
		$recipe = array();
	}

	$recipe = wp_parse_args(
		$recipe,
		array(
			'url'              => '',
			'post_type'        => 'post',
			'import_post_status' => 'draft',
			'item_selector'    => '',
			'unique_target'    => 'meta:source_url',
			'schedule'         => 'manual',
			'schedule_time'    => '02:00',
			'active_pattern'   => 0,
			'pattern_name'     => '',
			'content_template' => '',
			'fields'           => array(),
			'detail_fields'    => array(),
			'patterns'         => array(),
		)
	);

	if ( empty( $recipe['patterns'] ) || ! is_array( $recipe['patterns'] ) ) {
		$recipe['patterns'] = array(
			wpi_pattern_from_recipe_top_level( $recipe, __( 'Pattern 1', 'wp-pattern-import' ) ),
		);
	}

	foreach ( $recipe['patterns'] as $index => $pattern ) {
		$recipe['patterns'][ $index ] = wpi_normalize_pattern( $pattern, sprintf( __( 'Pattern %d', 'wp-pattern-import' ), $index + 1 ) );
	}

	$recipe['active_pattern'] = min( max( 0, absint( $recipe['active_pattern'] ) ), max( 0, count( $recipe['patterns'] ) - 1 ) );
	$recipe = wpi_sync_active_pattern_to_top_level( $recipe );
	$recipe = wpi_normalize_recipe_destinations( $recipe );

	return $recipe;
}

/**
 * Build a pattern from legacy top-level mapping keys.
 *
 * @param array<string,mixed> $recipe Recipe.
 * @param string              $fallback_name Fallback name.
 * @return array<string,mixed>
 */
function wpi_pattern_from_recipe_top_level( $recipe, $fallback_name ) {
	return wpi_normalize_pattern(
		array(
			'name'             => ! empty( $recipe['url'] ) ? $recipe['url'] : $fallback_name,
			'url'              => isset( $recipe['url'] ) ? $recipe['url'] : '',
			'item_selector'    => isset( $recipe['item_selector'] ) ? $recipe['item_selector'] : '',
			'unique_target'    => isset( $recipe['unique_target'] ) ? $recipe['unique_target'] : 'meta:source_url',
			'content_template' => isset( $recipe['content_template'] ) ? $recipe['content_template'] : '',
			'fields'           => isset( $recipe['fields'] ) ? $recipe['fields'] : array(),
			'detail_fields'    => isset( $recipe['detail_fields'] ) ? $recipe['detail_fields'] : array(),
		),
		$fallback_name
	);
}

/**
 * Normalize one scraping pattern.
 *
 * @param mixed  $pattern Pattern-like value.
 * @param string $fallback_name Fallback name.
 * @return array<string,mixed>
 */
function wpi_normalize_pattern( $pattern, $fallback_name ) {
	if ( ! is_array( $pattern ) ) {
		$pattern = array();
	}

	$pattern = wp_parse_args(
		$pattern,
		array(
			'name'             => $fallback_name,
			'url'              => '',
			'item_selector'    => '',
			'unique_target'    => 'meta:source_url',
			'content_template' => '',
			'fields'           => array(),
			'detail_fields'    => array(),
		)
	);

	$pattern['url']              = isset( $pattern['url'] ) ? esc_url_raw( trim( $pattern['url'] ), array( 'http', 'https' ) ) : '';
	$pattern['name']             = $pattern['url'] ? $pattern['url'] : sanitize_text_field( $fallback_name );
	$pattern['item_selector']    = wpi_sanitize_selector( $pattern['item_selector'] );
	$pattern['unique_target']    = wpi_sanitize_target( $pattern['unique_target'] ) ? wpi_sanitize_target( $pattern['unique_target'] ) : 'meta:source_url';
	$pattern['content_template'] = wp_kses_post( $pattern['content_template'] );
	$pattern['fields']           = is_array( $pattern['fields'] ) ? $pattern['fields'] : array();
	$pattern['detail_fields']    = is_array( $pattern['detail_fields'] ) ? $pattern['detail_fields'] : array();

	return $pattern;
}

/**
 * Copy the active pattern to legacy top-level keys used by the current UI.
 *
 * @param array<string,mixed> $recipe Recipe.
 * @return array<string,mixed>
 */
function wpi_sync_active_pattern_to_top_level( $recipe ) {
	if ( empty( $recipe['patterns'] ) || ! is_array( $recipe['patterns'] ) ) {
		return $recipe;
	}

	$index   = min( max( 0, isset( $recipe['active_pattern'] ) ? absint( $recipe['active_pattern'] ) : 0 ), count( $recipe['patterns'] ) - 1 );
	$pattern = wpi_normalize_pattern( $recipe['patterns'][ $index ], sprintf( __( 'Pattern %d', 'wp-pattern-import' ), $index + 1 ) );

	$recipe['active_pattern']   = $index;
	$recipe['pattern_name']     = $pattern['name'];
	$recipe['url']              = $pattern['url'];
	$recipe['item_selector']    = $pattern['item_selector'];
	$recipe['unique_target']    = $pattern['unique_target'];
	$recipe['content_template'] = $pattern['content_template'];
	$recipe['fields']           = $pattern['fields'];
	$recipe['detail_fields']    = $pattern['detail_fields'];
	$recipe['patterns'][ $index ] = $pattern;

	return $recipe;
}

/**
 * Save the current form mapping back to its active pattern.
 *
 * @param array<string,mixed> $recipe Recipe.
 * @return array<string,mixed>
 */
function wpi_save_active_pattern_to_patterns( $recipe ) {
	if ( empty( $recipe['patterns'] ) || ! is_array( $recipe['patterns'] ) ) {
		$recipe['patterns'] = array();
	}

	$index = wpi_find_pattern_index_by_url( $recipe['patterns'], $recipe['url'] );
	if ( null === $index ) {
		$index = min( max( 0, absint( $recipe['active_pattern'] ) ), max( 0, count( $recipe['patterns'] ) - 1 ) );
		if ( ! empty( $recipe['patterns'][ $index ]['url'] ) && $recipe['patterns'][ $index ]['url'] !== $recipe['url'] ) {
			$index = count( $recipe['patterns'] );
		}
	}
	$recipe['patterns'][ $index ] = wpi_normalize_pattern(
		array(
			'name'             => $recipe['url'],
			'url'              => $recipe['url'],
			'item_selector'    => $recipe['item_selector'],
			'unique_target'    => $recipe['unique_target'],
			'content_template' => $recipe['content_template'],
			'fields'           => $recipe['fields'],
			'detail_fields'    => $recipe['detail_fields'],
		),
		sprintf( __( 'Pattern %d', 'wp-pattern-import' ), $index + 1 )
	);

	$recipe['active_pattern'] = $index;

	return $recipe;
}

/**
 * Find a saved pattern index by source URL.
 *
 * @param array<int,array<string,mixed>> $patterns Patterns.
 * @param string                         $url Source URL.
 * @return int|null
 */
function wpi_find_pattern_index_by_url( $patterns, $url ) {
	$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
	foreach ( $patterns as $index => $pattern ) {
		if ( ! is_array( $pattern ) ) {
			continue;
		}
		$pattern_url = isset( $pattern['url'] ) ? esc_url_raw( trim( $pattern['url'] ), array( 'http', 'https' ) ) : '';
		if ( $pattern_url && $pattern_url === $url ) {
			return (int) $index;
		}
	}

	return null;
}

/**
 * Normalize cross-mapping destinations.
 *
 * @param array<string,mixed> $recipe Recipe.
 * @return array<string,mixed>
 */
function wpi_normalize_recipe_destinations( $recipe ) {
	$detail_has_image = false;
	foreach ( $recipe['detail_fields'] as $field ) {
		if ( ! empty( $field['featured_image'] ) ) {
			$detail_has_image = true;
			break;
		}
	}

	if ( $detail_has_image ) {
		foreach ( $recipe['fields'] as &$field ) {
			$field['featured_image'] = 0;
		}
		unset( $field );
	}

	if ( ! empty( $recipe['patterns'] ) && is_array( $recipe['patterns'] ) ) {
		foreach ( $recipe['patterns'] as $index => $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}
			$pattern['fields']        = isset( $pattern['fields'] ) && is_array( $pattern['fields'] ) ? $pattern['fields'] : array();
			$pattern['detail_fields'] = isset( $pattern['detail_fields'] ) && is_array( $pattern['detail_fields'] ) ? $pattern['detail_fields'] : array();

			$pattern_detail_has_image = false;
			foreach ( $pattern['detail_fields'] as $field ) {
				if ( ! empty( $field['featured_image'] ) ) {
					$pattern_detail_has_image = true;
					break;
				}
			}

			if ( $pattern_detail_has_image ) {
				foreach ( $pattern['fields'] as &$field ) {
					$field['featured_image'] = 0;
				}
				unset( $field );
			}

			$recipe['patterns'][ $index ] = $pattern;
		}
	}

	return $recipe;
}

/**
 * Sanitize mapping fields.
 *
 * @param array<string|int,array<string,mixed>> $fields Fields.
 * @param string                                $featured_image_field Featured image field ID.
 * @param string                                $scope Mapping scope.
 * @return array<int,array<string,mixed>>
 */
function wpi_sanitize_mapping_fields( $fields, $featured_image_field = '', $scope = 'item' ) {
	$clean_fields = array();

	foreach ( $fields as $field_id => $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}

		$field_id = is_int( $field_id ) ? (string) $field_id : sanitize_key( (string) $field_id );

		$post_field = isset( $field['post_field'] ) ? wpi_sanitize_post_field( $field['post_field'] ) : '';
		$meta_key   = isset( $field['meta_key'] ) ? sanitize_key( $field['meta_key'] ) : '';
		$acf_key    = isset( $field['acf_key'] ) ? sanitize_key( $field['acf_key'] ) : '';
		$featured_value = $scope . ':' . $field_id;
		$is_image       = $featured_image_field && $featured_image_field === $featured_value ? 1 : 0;

		$has_source = ! empty( $field['label'] ) || ! empty( $field['selector'] ) || ! empty( $field['samples'] );
		if ( ! $post_field && ! $meta_key && ! $acf_key && ! $is_image && empty( $field['target'] ) && ! $has_source ) {
			continue;
		}

		$clean_field = array(
			'label'          => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '',
			'selector'       => isset( $field['selector'] ) ? wpi_sanitize_selector( $field['selector'] ) : '',
			'attr'           => isset( $field['attr'] ) ? wpi_sanitize_attr( $field['attr'] ) : 'text',
			'samples'        => isset( $field['samples'] ) && is_array( $field['samples'] ) ? array_map( 'sanitize_text_field', $field['samples'] ) : array(),
			'post_field'     => $post_field,
			'meta_key'       => $meta_key,
			'acf_key'        => $acf_key,
			'featured_image' => $is_image,
		);

		if ( empty( $clean_field['post_field'] ) && empty( $clean_field['meta_key'] ) && empty( $clean_field['acf_key'] ) && empty( $clean_field['featured_image'] ) && ! empty( $field['target'] ) ) {
			$clean_field = wpi_upgrade_legacy_form_field( $clean_field, $field['target'] );
		}

		$clean_fields[] = $clean_field;
	}

	return $clean_fields;
}

/**
 * Get saved recipe with defaults.
 *
 * @return array<string,mixed>
 */
function wpi_get_recipe_for_form() {
	$defaults = array(
		'url'           => '',
		'post_type'     => 'post',
		'import_post_status' => 'draft',
		'item_selector' => '',
		'unique_target' => 'meta:source_url',
		'schedule'      => 'manual',
		'schedule_time' => '02:00',
		'active_pattern' => 0,
		'pattern_name'  => 'Pattern 1',
		'content_template' => '',
		'patterns'      => array(),
		'detail_fields' => array(),
		'fields'        => array(
			array(
				'label'          => 'Title',
				'selector'       => 'h2 a',
				'attr'           => 'text',
				'post_field'     => 'post_title',
				'meta_key'       => '',
				'acf_key'        => '',
				'featured_image' => 0,
			),
			array(
				'label'          => 'Source URL',
				'selector'       => 'h2 a',
				'attr'           => 'href',
				'post_field'     => '',
				'meta_key'       => 'source_url',
				'acf_key'        => '',
				'featured_image' => 0,
			),
		),
	);

	$recipe = get_option( 'wpi_recipe', array() );
	if ( ! is_array( $recipe ) ) {
		$recipe = array();
	}

	$recipe = wp_parse_args( $recipe, $defaults );
	if ( empty( $recipe['fields'] ) ) {
		$recipe['fields'] = $defaults['fields'];
	}

	return wpi_normalize_recipe( $recipe );
}

/**
 * Render a full list of saved scraping patterns.
 *
 * @param array<string,mixed> $recipe Recipe.
 * @return void
 */
function wpi_render_saved_patterns_list( $recipe ) {
	$patterns = isset( $recipe['patterns'] ) && is_array( $recipe['patterns'] ) ? $recipe['patterns'] : array();
	$patterns = array_filter(
		$patterns,
		function ( $pattern ) {
			return is_array( $pattern ) && ! empty( $pattern['url'] );
		}
	);

	if ( empty( $patterns ) ) {
		return;
	}
	?>
	<h2><?php echo esc_html__( 'Saved Scraping Patterns', 'wp-pattern-import' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php echo esc_html__( 'Source URL', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Item selector', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Item fields', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Detail fields', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Unique key', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Content pattern', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Action', 'wp-pattern-import' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $patterns as $index => $pattern ) : ?>
				<?php
				$pattern = wpi_normalize_pattern( $pattern, sprintf( __( 'Pattern %d', 'wp-pattern-import' ), $index + 1 ) );
				$edit_url = add_query_arg(
					array(
						'page'        => 'wp-pattern-import',
						'wpi_pattern' => $index,
					),
					admin_url( 'tools.php' )
				) . '#wpi-tab-plan';
				?>
				<tr>
					<td>
						<?php if ( ! empty( $pattern['url'] ) ) : ?>
							<a href="<?php echo esc_url( $edit_url ); ?>"><code><?php echo esc_html( $pattern['url'] ); ?></code></a>
						<?php else : ?>
							<em><?php echo esc_html__( 'Not set', 'wp-pattern-import' ); ?></em>
						<?php endif; ?>
						<?php if ( (int) $recipe['active_pattern'] === (int) $index ) : ?>
							<br /><em><?php echo esc_html__( 'Currently editing', 'wp-pattern-import' ); ?></em>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! empty( $pattern['item_selector'] ) ) : ?>
							<code><?php echo esc_html( $pattern['item_selector'] ); ?></code>
						<?php else : ?>
							<em><?php echo esc_html__( 'Not set', 'wp-pattern-import' ); ?></em>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( number_format_i18n( wpi_count_mapped_fields( $pattern['fields'] ) ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( wpi_count_mapped_fields( $pattern['detail_fields'] ) ) ); ?></td>
					<td><code><?php echo esc_html( $pattern['unique_target'] ); ?></code></td>
					<td><?php echo empty( $pattern['content_template'] ) ? esc_html__( 'No', 'wp-pattern-import' ) : esc_html__( 'Yes', 'wp-pattern-import' ); ?></td>
					<td><a class="button" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html__( 'Edit', 'wp-pattern-import' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Count fields with a real destination.
 *
 * @param array<int,array<string,mixed>> $fields Fields.
 * @return int
 */
function wpi_count_mapped_fields( $fields ) {
	if ( empty( $fields ) || ! is_array( $fields ) ) {
		return 0;
	}

	$count = 0;
	foreach ( $fields as $field ) {
		if ( is_array( $field ) && wpi_field_has_destination( wpi_normalize_field_for_form( $field, false ) ) ) {
			$count++;
		}
	}

	return $count;
}

/**
 * Render post type select.
 *
 * @param string $selected Selected post type.
 * @return void
 */
function wpi_render_post_type_select( $selected ) {
	$post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
	?>
	<select name="post_type" id="wpi-post-type">
		<?php foreach ( $post_types as $post_type ) : ?>
			<option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( $selected, $post_type->name ); ?>>
				<?php echo esc_html( $post_type->labels->singular_name ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * Render import post status select.
 *
 * @param string $selected Selected status.
 * @return void
 */
function wpi_render_post_status_select( $selected ) {
	$statuses = array(
		'draft'   => __( 'draft', 'wp-pattern-import' ),
		'pending' => __( 'pending', 'wp-pattern-import' ),
		'private' => __( 'private', 'wp-pattern-import' ),
		'publish' => __( 'publish', 'wp-pattern-import' ),
	);
	?>
	<select name="import_post_status" id="wpi-import-post-status">
		<?php foreach ( $statuses as $status => $label ) : ?>
			<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $selected, $status ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * Render a post object field select.
 *
 * @param string $name Field name.
 * @param string $selected Selected field.
 * @return void
 */
function wpi_render_post_field_select( $name, $selected = '' ) {
	$fields = array(
		''             => __( 'Do not map', 'wp-pattern-import' ),
		'post_title'   => __( 'post_title', 'wp-pattern-import' ),
		'post_name'    => __( 'post_name', 'wp-pattern-import' ),
		'post_status'  => __( 'post_status', 'wp-pattern-import' ),
		'post_date'    => __( 'post_date', 'wp-pattern-import' ),
		'post_excerpt' => __( 'post_excerpt', 'wp-pattern-import' ),
	);
	?>
	<select name="<?php echo esc_attr( $name ); ?>">
		<?php foreach ( $fields as $field => $label ) : ?>
			<option value="<?php echo esc_attr( $field ); ?>" <?php selected( $selected, $field ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * Normalize a scanned or saved field for mapping table display.
 *
 * @param array<string,mixed> $field Field.
 * @param bool                $suggest_destinations Whether to suggest destinations for unmapped fields.
 * @return array<string,mixed>
 */
function wpi_normalize_field_for_form( $field, $suggest_destinations = true ) {
	if ( ! empty( $field['target'] ) ) {
		$field = wpi_upgrade_legacy_form_field( $field, $field['target'] );
	}

	$field = wp_parse_args(
		$field,
		array(
			'label'          => '',
			'selector'       => '',
			'attr'           => 'text',
			'samples'        => array(),
			'post_field'     => '',
			'meta_key'       => '',
			'acf_key'        => '',
			'featured_image' => 0,
		)
	);
	$field['post_field'] = wpi_sanitize_post_field( $field['post_field'] );

	if ( $suggest_destinations && empty( $field['post_field'] ) && empty( $field['meta_key'] ) && empty( $field['acf_key'] ) && empty( $field['featured_image'] ) ) {
		$field = wpi_apply_suggested_destinations( $field );
	}

	return $field;
}

/**
 * Apply a light suggested mapping for newly scanned fields.
 *
 * @param array<string,mixed> $field Field.
 * @return array<string,mixed>
 */
function wpi_apply_suggested_destinations( $field ) {
	$label = strtolower( isset( $field['label'] ) ? $field['label'] : '' );
	$attr  = isset( $field['attr'] ) ? $field['attr'] : '';

	if ( 'href' === $attr ) {
		$field['meta_key'] = 'source_url';
	} elseif ( 'src' === $attr ) {
		$field['featured_image'] = 1;
	} elseif ( false !== strpos( $label, 'heading' ) ) {
		$field['post_field'] = 'post_title';
	} elseif ( false !== strpos( $label, 'paragraph' ) ) {
		$field['post_field'] = 'post_excerpt';
	} elseif ( false !== strpos( $label, 'date' ) ) {
		$field['post_field'] = 'post_date';
	}

	return $field;
}

/**
 * Upgrade legacy target value into destination columns.
 *
 * @param array<string,mixed> $field Field.
 * @param string              $target Legacy target.
 * @return array<string,mixed>
 */
function wpi_upgrade_legacy_form_field( $field, $target ) {
	$target = wpi_sanitize_target( $target );

	if ( 0 === strpos( $target, 'meta:' ) ) {
		$field['meta_key'] = substr( $target, 5 );
	} elseif ( 'featured_image' === $target ) {
		$field['featured_image'] = 1;
	} elseif ( '' !== $target ) {
		$field['post_field'] = $target;
	}

	return $field;
}

/**
 * Render item selector scan results.
 *
 * @param mixed $scan Scan transient.
 * @return void
 */
function wpi_render_loop_scan_results( $scan ) {
	if ( ! is_array( $scan ) ) {
		return;
	}
	?>
	<?php if ( ! empty( $scan['patterns'] ) ) : ?>
		<h2><?php echo esc_html__( 'Suggested Item Selectors', 'wp-pattern-import' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Selector', 'wp-pattern-import' ); ?></th>
					<th><?php echo esc_html__( 'Matches', 'wp-pattern-import' ); ?></th>
					<th><?php echo esc_html__( 'Example 1', 'wp-pattern-import' ); ?></th>
					<th><?php echo esc_html__( 'Example 2', 'wp-pattern-import' ); ?></th>
					<th><?php echo esc_html__( 'Example 3', 'wp-pattern-import' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $scan['patterns'] as $pattern ) : ?>
					<tr>
						<td><code><?php echo esc_html( $pattern['selector'] ); ?></code></td>
						<td><?php echo esc_html( number_format_i18n( $pattern['matches'] ) ); ?></td>
						<?php for ( $i = 0; $i < 3; $i++ ) : ?>
							<?php $sample = isset( $pattern['samples'][ $i ] ) ? $pattern['samples'][ $i ] : array( 'text' => '', 'url' => '' ); ?>
							<td><?php echo esc_html( $sample['text'] ); ?></td>
						<?php endfor; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p><?php echo esc_html__( 'No repeated class-based patterns found.', 'wp-pattern-import' ); ?></p>
	<?php endif; ?>
	<?php
}

/**
 * Render field mapping scan results.
 *
 * @param mixed               $scan Scan transient.
 * @param array<string,mixed> $recipe Current recipe.
 * @return void
 */
function wpi_render_field_mapping_results( $scan, $recipe ) {
	if ( is_array( $scan ) && ! empty( $scan['item']['count'] ) ) :
		?>
		<h2><?php echo esc_html__( 'Field Mapping', 'wp-pattern-import' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: matched item count. */
					__( 'Item selector matched %d items.', 'wp-pattern-import' ),
					(int) $scan['item']['count']
				)
			);
			?>
		</p>
		<?php wpi_render_mapping_table( $scan['item']['fields'], 'fields', 'item', true ); ?>
		<?php
	elseif ( ! empty( $recipe['fields'] ) ) :
		?>
		<h2><?php echo esc_html__( 'Saved Field Mapping', 'wp-pattern-import' ); ?></h2>
		<?php wpi_render_mapping_table( $recipe['fields'], 'fields', 'item', false ); ?>
		<?php
	elseif ( is_array( $scan ) && ! empty( $scan['recipe']['item_selector'] ) ) :
		?>
		<p><?php echo esc_html__( 'The item selector did not match useful repeated content. Try a suggested item selector from the URL tab.', 'wp-pattern-import' ); ?></p>
		<?php
	endif;
}

/**
 * Render detail page mapping scan results.
 *
 * @param mixed               $scan Scan transient.
 * @param array<string,mixed> $recipe Current recipe.
 * @return void
 */
function wpi_render_detail_mapping_results( $scan, $recipe ) {
	if ( is_array( $scan ) && ! empty( $scan['detail']['fields'] ) ) :
		?>
		<h2><?php echo esc_html__( 'Detail Page Mapping', 'wp-pattern-import' ); ?></h2>
		<p>
			<?php echo esc_html__( 'Scanned detail page:', 'wp-pattern-import' ); ?>
			<code><?php echo esc_html( $scan['detail']['url'] ); ?></code>
		</p>
		<?php wpi_render_mapping_table( $scan['detail']['fields'], 'detail_fields', 'detail', true ); ?>
		<?php
	elseif ( ! empty( $recipe['detail_fields'] ) ) :
		?>
		<h2><?php echo esc_html__( 'Saved Detail Page Mapping', 'wp-pattern-import' ); ?></h2>
		<?php wpi_render_mapping_table( $recipe['detail_fields'], 'detail_fields', 'detail', false ); ?>
		<?php
	else :
		?>
		<p><?php echo esc_html__( 'Scan the first detail page to map selectors from the destination page.', 'wp-pattern-import' ); ?></p>
		<?php
	endif;
}

/**
 * Render mapping table with destination columns.
 *
 * @param array<int,array<string,mixed>> $fields Fields.
 * @param string                         $field_name Form field name.
 * @param string                         $scope Mapping scope.
 * @param bool                           $suggest_destinations Whether to suggest destinations for unmapped fields.
 * @return void
 */
function wpi_render_mapping_table( $fields, $field_name = 'fields', $scope = 'item', $suggest_destinations = true ) {
	if ( empty( $fields ) ) {
		return;
	}
	?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php echo esc_html__( 'Detected field', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Examples', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Post object field', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Native meta key', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'ACF meta key', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Featured image', 'wp-pattern-import' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $fields as $index => $field ) : ?>
				<?php
				$field = wpi_normalize_field_for_form( $field, $suggest_destinations );
				$field_index = is_int( $index ) ? $index : sanitize_key( $index );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $field['label'] ); ?></strong><br />
						<code><?php echo esc_html( $field['selector'] ); ?></code>
						<code><?php echo esc_html( $field['attr'] ); ?></code>
						<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[<?php echo esc_attr( $field_index ); ?>][label]" value="<?php echo esc_attr( $field['label'] ); ?>" />
						<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[<?php echo esc_attr( $field_index ); ?>][selector]" value="<?php echo esc_attr( $field['selector'] ); ?>" />
						<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[<?php echo esc_attr( $field_index ); ?>][attr]" value="<?php echo esc_attr( $field['attr'] ); ?>" />
					</td>
					<td>
						<?php foreach ( array_slice( $field['samples'], 0, 3 ) as $sample_index => $sample ) : ?>
							<div><?php echo esc_html( $sample ); ?></div>
							<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[<?php echo esc_attr( $field_index ); ?>][samples][<?php echo esc_attr( $sample_index ); ?>]" value="<?php echo esc_attr( $sample ); ?>" />
						<?php endforeach; ?>
					</td>
					<td><?php wpi_render_post_field_select( $field_name . '[' . $field_index . '][post_field]', $field['post_field'] ); ?></td>
					<td><input type="text" name="<?php echo esc_attr( $field_name ); ?>[<?php echo esc_attr( $field_index ); ?>][meta_key]" value="<?php echo esc_attr( $field['meta_key'] ); ?>" class="regular-text code" /></td>
					<td><input type="text" name="<?php echo esc_attr( $field_name ); ?>[<?php echo esc_attr( $field_index ); ?>][acf_key]" value="<?php echo esc_attr( $field['acf_key'] ); ?>" class="regular-text code" /></td>
					<td><input type="radio" name="featured_image_field" value="<?php echo esc_attr( $scope . ':' . $field_index ); ?>" <?php checked( $field['featured_image'], 1 ); ?> /></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Render a concise mapping summary.
 *
 * @param array<string,mixed> $recipe Recipe.
 * @return void
 */
function wpi_render_mapping_summary( $recipe ) {
	$patterns = isset( $recipe['patterns'] ) && is_array( $recipe['patterns'] ) ? $recipe['patterns'] : array();
	?>
	<h2><?php echo esc_html__( 'Mapping Summary', 'wp-pattern-import' ); ?></h2>
	<p><?php echo esc_html__( 'Detail page mappings override item mappings when they write to the same destination.', 'wp-pattern-import' ); ?></p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php echo esc_html__( 'Source', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Selector', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Attribute', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Destinations', 'wp-pattern-import' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! empty( $patterns ) ) : ?>
				<?php foreach ( $patterns as $index => $pattern ) : ?>
					<?php $pattern = wpi_normalize_pattern( $pattern, sprintf( __( 'Pattern %d', 'wp-pattern-import' ), $index + 1 ) ); ?>
					<?php wpi_render_mapping_summary_rows( isset( $pattern['fields'] ) ? $pattern['fields'] : array(), $pattern['name'] . ' / ' . __( 'Item', 'wp-pattern-import' ) ); ?>
					<?php wpi_render_mapping_summary_rows( isset( $pattern['detail_fields'] ) ? $pattern['detail_fields'] : array(), $pattern['name'] . ' / ' . __( 'Detail', 'wp-pattern-import' ) ); ?>
					<?php if ( ! empty( $pattern['content_template'] ) ) : ?>
						<tr>
							<td><?php echo esc_html( $pattern['name'] . ' / ' . __( 'Content Pattern', 'wp-pattern-import' ) ); ?></td>
							<td><code><?php echo esc_html__( 'Template HTML', 'wp-pattern-import' ); ?></code></td>
							<td><code><?php echo esc_html__( 'tokens', 'wp-pattern-import' ); ?></code></td>
							<td><?php echo esc_html__( 'Appended to post_content', 'wp-pattern-import' ); ?></td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php else : ?>
				<?php wpi_render_mapping_summary_rows( isset( $recipe['fields'] ) ? $recipe['fields'] : array(), __( 'Item', 'wp-pattern-import' ) ); ?>
				<?php wpi_render_mapping_summary_rows( isset( $recipe['detail_fields'] ) ? $recipe['detail_fields'] : array(), __( 'Detail', 'wp-pattern-import' ) ); ?>
			<?php endif; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Render available content template tokens.
 *
 * @param array<string,mixed> $recipe Recipe.
 * @param mixed               $scan Scan transient.
 * @return void
 */
function wpi_render_content_tokens( $recipe, $scan = false ) {
	$tokens = wpi_get_pattern_tokens( $recipe, $scan );
	if ( empty( $tokens ) ) {
		?>
		<p><?php echo esc_html__( 'No tokens are available yet. Scan and save item or detail mappings first.', 'wp-pattern-import' ); ?></p>
		<?php
		return;
	}
	?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php echo esc_html__( 'Token', 'wp-pattern-import' ); ?></th>
				<th><?php echo esc_html__( 'Source field', 'wp-pattern-import' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $tokens as $token => $label ) : ?>
				<tr>
					<td><code><?php echo esc_html( '%' . $token . '%' ); ?></code></td>
					<td><?php echo esc_html( $label ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Get tokens for the active pattern.
 *
 * @param array<string,mixed> $recipe Recipe.
 * @param mixed               $scan Scan transient.
 * @return array<string,string>
 */
function wpi_get_pattern_tokens( $recipe, $scan = false ) {
	$item_fields   = isset( $recipe['fields'] ) && is_array( $recipe['fields'] ) ? $recipe['fields'] : array();
	$detail_fields = isset( $recipe['detail_fields'] ) && is_array( $recipe['detail_fields'] ) ? $recipe['detail_fields'] : array();

	if ( is_array( $scan ) && ! empty( $scan['item']['fields'] ) && is_array( $scan['item']['fields'] ) ) {
		$item_fields = $scan['item']['fields'];
	}
	if ( is_array( $scan ) && ! empty( $scan['detail']['fields'] ) && is_array( $scan['detail']['fields'] ) ) {
		$detail_fields = $scan['detail']['fields'];
	}

	$tokens = array();
	$fields = array(
		'item'   => $item_fields,
		'detail' => $detail_fields,
	);

	foreach ( $fields as $scope => $scope_fields ) {
		foreach ( $scope_fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$field = wpi_normalize_field_for_form( $field, false );
			$token = wpi_field_token_key( $field, $scope );
			if ( '' === $token ) {
				continue;
			}
			$tokens[ $token ] = ucfirst( $scope ) . ': ' . $field['label'];
		}
	}

	return $tokens;
}

/**
 * Build a stable token key for a mapped/scanned field.
 *
 * @param array<string,mixed> $field Field.
 * @param string              $scope item|detail.
 * @return string
 */
function wpi_field_token_key( $field, $scope = 'item' ) {
	if ( ! empty( $field['meta_key'] ) ) {
		return sanitize_key( $field['meta_key'] );
	}
	if ( ! empty( $field['acf_key'] ) ) {
		return sanitize_key( $field['acf_key'] );
	}
	if ( ! empty( $field['post_field'] ) ) {
		return sanitize_key( $field['post_field'] );
	}
	if ( ! empty( $field['featured_image'] ) ) {
		return 'featured_image';
	}
	if ( ! empty( $field['label'] ) ) {
		return sanitize_key( $scope . '_' . $field['label'] );
	}

	return '';
}

/**
 * Render mapping summary rows.
 *
 * @param array<int,array<string,mixed>> $fields Fields.
 * @param string                         $source Source label.
 * @return void
 */
function wpi_render_mapping_summary_rows( $fields, $source ) {
	if ( empty( $fields ) ) {
		return;
	}

	foreach ( $fields as $field ) :
		$field = wpi_normalize_field_for_form( $field, false );
		$destinations = wpi_field_destination_labels( $field );
		if ( empty( $destinations ) ) {
			continue;
		}
		?>
		<tr>
			<td><?php echo esc_html( $source ); ?></td>
			<td><code><?php echo esc_html( $field['selector'] ); ?></code></td>
			<td><code><?php echo esc_html( $field['attr'] ); ?></code></td>
			<td><?php echo esc_html( implode( ', ', $destinations ) ); ?></td>
		</tr>
		<?php
	endforeach;
}

/**
 * Build destination labels for a field.
 *
 * @param array<string,mixed> $field Field.
 * @return array<int,string>
 */
function wpi_field_destination_labels( $field ) {
	$labels = array();
	if ( ! empty( $field['post_field'] ) ) {
		$labels[] = 'post:' . $field['post_field'];
	}
	if ( ! empty( $field['meta_key'] ) ) {
		$labels[] = 'meta:' . $field['meta_key'];
	}
	if ( ! empty( $field['acf_key'] ) ) {
		$labels[] = 'acf:' . $field['acf_key'];
	}
	if ( ! empty( $field['featured_image'] ) ) {
		$labels[] = 'featured_image';
	}

	return $labels;
}

/**
 * Render last run result.
 *
 * @param mixed $last Last run option.
 * @return void
 */
function wpi_render_last_run( $last ) {
	?>
	<h2><?php echo esc_html__( 'Last Run Result', 'wp-pattern-import' ); ?></h2>
	<?php if ( ! is_array( $last ) || empty( $last ) ) : ?>
		<p><?php echo esc_html__( 'No imports have run yet.', 'wp-pattern-import' ); ?></p>
		<?php return; ?>
	<?php endif; ?>

	<table class="widefat striped">
		<tbody>
			<?php foreach ( array( 'status', 'ran_at', 'found', 'created', 'skipped', 'failed', 'message' ) as $key ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ); ?></th>
					<td><?php echo esc_html( isset( $last[ $key ] ) ? (string) $last[ $key ] : '' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Sanitize selector input to the supported selector subset.
 *
 * @param string $selector Selector.
 * @return string
 */
function wpi_sanitize_selector( $selector ) {
	return preg_replace( '/[^A-Za-z0-9_\-#\.\*\s]/', '', trim( (string) $selector ) );
}

/**
 * Sanitize target input.
 *
 * @param string $target Target.
 * @return string
 */
function wpi_sanitize_target( $target ) {
	$target = trim( (string) $target );
	$core   = array( 'post_title', 'post_name', 'post_status', 'post_excerpt', 'post_date', 'featured_image' );

	if ( preg_match( '/^post:[A-Za-z0-9_\-]+$/', $target ) ) {
		$post_field = wpi_sanitize_post_field( substr( $target, 5 ) );
		return $post_field ? 'post:' . $post_field : '';
	}

	if ( in_array( $target, $core, true ) ) {
		return $target;
	}

	if ( preg_match( '/^meta:[A-Za-z0-9_\-]+$/', $target ) ) {
		return 'meta:' . sanitize_key( substr( $target, 5 ) );
	}

	if ( preg_match( '/^acf:[A-Za-z0-9_\-]+$/', $target ) ) {
		return 'acf:' . sanitize_key( substr( $target, 4 ) );
	}

	return '';
}

/**
 * Sanitize a post object field destination.
 *
 * @param string $field Post field.
 * @return string
 */
function wpi_sanitize_post_field( $field ) {
	$field = sanitize_key( $field );
	return in_array( $field, array( 'post_title', 'post_name', 'post_status', 'post_date', 'post_excerpt' ), true ) ? $field : '';
}

/**
 * Sanitize global import post status.
 *
 * @param string $status Post status.
 * @return string
 */
function wpi_sanitize_import_post_status( $status ) {
	$status = sanitize_key( $status );
	return in_array( $status, array( 'draft', 'pending', 'private', 'publish' ), true ) ? $status : 'draft';
}

/**
 * Sanitize attribute input.
 *
 * @param string $attr Attribute.
 * @return string
 */
function wpi_sanitize_attr( $attr ) {
	$attr = sanitize_key( $attr );
	return in_array( $attr, array( 'text', 'html', 'href', 'src' ), true ) ? $attr : 'text';
}

/**
 * Sanitize schedule time.
 *
 * @param string $time HH:MM.
 * @return string
 */
function wpi_sanitize_schedule_time( $time ) {
	$time = trim( (string) $time );
	return preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time ) ? $time : '02:00';
}

/**
 * Check whether the unique target is present in the mapped fields.
 *
 * @param array<string,mixed> $recipe Recipe.
 * @return bool
 */
function wpi_recipe_has_unique_target( $recipe ) {
	if ( ! empty( $recipe['patterns'] ) && is_array( $recipe['patterns'] ) ) {
		foreach ( $recipe['patterns'] as $pattern ) {
			$pattern_recipe = $recipe;
			$pattern        = wpi_normalize_pattern( $pattern, __( 'Pattern', 'wp-pattern-import' ) );
			if ( empty( $pattern['item_selector'] ) ) {
				continue;
			}
			$pattern_recipe['unique_target'] = $pattern['unique_target'];
			$pattern_recipe['fields']        = $pattern['fields'];
			$pattern_recipe['detail_fields'] = $pattern['detail_fields'];
			if ( ! wpi_pattern_has_unique_target( $pattern_recipe ) ) {
				return false;
			}
		}
		return true;
	}

	return wpi_pattern_has_unique_target( $recipe );
}

/**
 * Check whether one pattern's unique target is present in its mapped fields.
 *
 * @param array<string,mixed> $recipe Pattern-shaped recipe.
 * @return bool
 */
function wpi_pattern_has_unique_target( $recipe ) {
	if ( empty( $recipe['unique_target'] ) ) {
		return false;
	}

	$fields = array();
	if ( ! empty( $recipe['fields'] ) && is_array( $recipe['fields'] ) ) {
		$fields = array_merge( $fields, $recipe['fields'] );
	}
	if ( ! empty( $recipe['detail_fields'] ) && is_array( $recipe['detail_fields'] ) ) {
		$fields = array_merge( $fields, $recipe['detail_fields'] );
	}

	foreach ( $fields as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}

		$field = wpi_normalize_field_for_form( $field, false );

		if ( 0 === strpos( $recipe['unique_target'], 'post:' ) && substr( $recipe['unique_target'], 5 ) === $field['post_field'] ) {
			return true;
		}
		if ( 0 === strpos( $recipe['unique_target'], 'meta:' ) && substr( $recipe['unique_target'], 5 ) === $field['meta_key'] ) {
			return true;
		}
		if ( 0 === strpos( $recipe['unique_target'], 'acf:' ) && substr( $recipe['unique_target'], 4 ) === $field['acf_key'] ) {
			return true;
		}
		if ( $recipe['unique_target'] === $field['post_field'] || $recipe['unique_target'] === $field['meta_key'] || $recipe['unique_target'] === $field['acf_key'] ) {
			return true;
		}
	}

	return false;
}

/**
 * Validate source URL for saving/running recipes.
 *
 * @param string $url Source URL.
 * @return bool
 */
function wpi_is_valid_source_url( $url ) {
	$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
	return ! empty( $url ) && (bool) wp_http_validate_url( $url );
}

/**
 * Redirect back with an error message.
 *
 * @param string $message Error message.
 * @return void
 */
function wpi_redirect_with_error( $message ) {
	wp_safe_redirect( add_query_arg( 'wpi_error', rawurlencode( $message ), wpi_admin_url() ) );
	exit;
}

/**
 * Admin page URL.
 *
 * @return string
 */
function wpi_admin_url() {
	return admin_url( 'tools.php?page=wp-pattern-import' );
}

/**
 * Translate admin status messages.
 *
 * @param string $message Message key.
 * @return string
 */
function wpi_admin_message_text( $message ) {
	$messages = array(
		'scan_complete'   => __( 'Scan complete.', 'wp-pattern-import' ),
		'recipe_saved'    => __( 'Recipe saved.', 'wp-pattern-import' ),
		'import_complete' => __( 'Import complete.', 'wp-pattern-import' ),
		'test_complete'   => __( 'Test import complete.', 'wp-pattern-import' ),
	);

	return isset( $messages[ $message ] ) ? $messages[ $message ] : __( 'Done.', 'wp-pattern-import' );
}
