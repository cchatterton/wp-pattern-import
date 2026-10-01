<?php
/**
 * Importer helpers for WP Pattern Import.
 *
 * @package WPPatternImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run the saved import recipe.
 *
 * @param string $run_type Run type: manual, test, or scheduled.
 * @param int    $limit Maximum number of new posts to create. Zero means no limit.
 * @param string $source_url Optional source URL to run one saved pattern.
 * @return array<string,mixed>
 */
function wpi_run_import( $run_type = 'manual', $limit = 0, $source_url = '' ) {
	$recipe = wpi_normalize_recipe( get_option( 'wpi_recipe', array() ) );
	$source_url = esc_url_raw( trim( (string) $source_url ), array( 'http', 'https' ) );
	$result = array(
		'status'  => 'success',
		'ran_at'  => current_time( 'mysql' ),
		'found'   => 0,
		'created' => 0,
		'skipped' => 0,
		'failed'  => 0,
		'message' => '',
		'type'    => sanitize_key( $run_type ),
	);

	if ( empty( $recipe['patterns'] ) ) {
		$result['status']  = 'error';
		$result['message'] = __( 'Save a recipe with a URL and at least one scraping pattern before importing.', 'wp-pattern-import' );
		update_option( 'wpi_last_run', $result, false );
		return $result;
	}

	$all_items = array();
	foreach ( $recipe['patterns'] as $pattern_index => $pattern ) {
		$pattern_recipe = wpi_recipe_for_pattern( $recipe, $pattern, $pattern_index );
		if ( $source_url && $pattern_recipe['url'] !== $source_url ) {
			continue;
		}
		if ( empty( $pattern_recipe['url'] ) || empty( $pattern_recipe['item_selector'] ) || empty( $pattern_recipe['fields'] ) ) {
			continue;
		}

		$html = wpi_fetch_html( $pattern_recipe['url'] );
		if ( is_wp_error( $html ) ) {
			$result['failed']++;
			continue;
		}

		$source = wpi_find_import_source( $html, $pattern_recipe, $pattern_recipe['url'] );
		$items  = wpi_extract_items( $source['html'], $pattern_recipe, $source['url'] );
		$all_items = array_merge( $all_items, $items );
	}

	$result['found'] = count( $all_items );
	if ( $source_url && 0 === $result['found'] ) {
		$result['status']  = 'error';
		$result['message'] = __( 'No items were found for the selected saved scraping pattern.', 'wp-pattern-import' );
		update_option( 'wpi_last_run', $result, false );
		return $result;
	}

	foreach ( $all_items as $item ) {
		if ( $limit > 0 && $result['created'] >= $limit ) {
			break;
		}

		$unique_value = wpi_get_unique_value_from_item( $item, $recipe );

		if ( '' === trim( (string) $unique_value ) ) {
			$unique_value = md5( wp_json_encode( $item ) );
		}

		if ( wpi_item_exists( $unique_value ) ) {
			$result['skipped']++;
			continue;
		}

		$post_id = wpi_create_post_from_item( $item, $recipe );
		if ( is_wp_error( $post_id ) ) {
			$result['failed']++;
			continue;
		}

		update_post_meta( $post_id, '_wpi_unique_value', sanitize_text_field( $unique_value ) );
		$item_source_url = isset( $item['_source_url'] ) ? $item['_source_url'] : $recipe['url'];
		update_post_meta( $post_id, '_wpi_source_url', esc_url_raw( $item_source_url ) );
		update_post_meta( $post_id, '_wpi_imported_at', current_time( 'mysql' ) );
		$result['created']++;
	}

	$result['message'] = sprintf(
		/* translators: 1: created count, 2: skipped count, 3: failed count. */
		__( 'Import complete. Created %1$d, skipped %2$d, failed %3$d.', 'wp-pattern-import' ),
		$result['created'],
		$result['skipped'],
		$result['failed']
	);

	if ( $result['failed'] > 0 ) {
		$result['status'] = 'partial';
	}

	update_option( 'wpi_last_run', $result, false );
	return $result;
}

/**
 * Build an import recipe for one scraping pattern.
 *
 * @param array<string,mixed> $recipe Recipe.
 * @param array<string,mixed> $pattern Pattern.
 * @param int                 $pattern_index Pattern index.
 * @return array<string,mixed>
 */
function wpi_recipe_for_pattern( $recipe, $pattern, $pattern_index = 0 ) {
	$pattern = wpi_normalize_pattern( $pattern, sprintf( __( 'Pattern %d', 'wp-pattern-import' ), $pattern_index + 1 ) );

	$pattern_recipe = $recipe;
	$pattern_recipe['active_pattern']   = $pattern_index;
	$pattern_recipe['pattern_name']     = $pattern['name'];
	$pattern_recipe['url']              = $pattern['url'];
	$pattern_recipe['item_selector']    = $pattern['item_selector'];
	$pattern_recipe['unique_target']    = $pattern['unique_target'];
	$pattern_recipe['content_template'] = $pattern['content_template'];
	$pattern_recipe['fields']           = $pattern['fields'];
	$pattern_recipe['detail_fields']    = $pattern['detail_fields'];

	return $pattern_recipe;
}

/**
 * Extract mapped items from HTML.
 *
 * @param string              $html HTML document.
 * @param array<string,mixed> $recipe Recipe.
 * @param string              $source_url Source URL for resolving link attributes.
 * @return array<int,array<string,string>>
 */
function wpi_extract_items( $html, $recipe, $source_url = '' ) {
	$dom = wpi_dom_from_html( $html );
	if ( ! $dom ) {
		return array();
	}

	$item_nodes = wpi_query_selector_all( $dom, $recipe['item_selector'] );
	$items      = array();

	foreach ( $item_nodes as $item_node ) {
		$item = array(
			'post'           => array(),
			'meta'           => array(),
			'acf'            => array(),
			'featured_image' => '',
			'tokens'         => array(),
			'_source_url'    => isset( $recipe['url'] ) ? $recipe['url'] : $source_url,
			'_unique_target' => isset( $recipe['unique_target'] ) ? $recipe['unique_target'] : 'meta:source_url',
			'_content_template' => isset( $recipe['content_template'] ) ? $recipe['content_template'] : '',
		);
		foreach ( $recipe['fields'] as $field ) {
			if ( empty( $field['selector'] ) ) {
				continue;
			}

			$value = wpi_extract_field( $item_node, $field, $source_url );
			if ( '' === $value ) {
				continue;
			}

			$item = wpi_apply_field_value_to_item( $item, $field, $value, 'item' );
		}

		if ( ! empty( $recipe['detail_fields'] ) ) {
			$item = wpi_apply_detail_fields_to_item( $item, $recipe );
		}

		$items[] = $item;
	}

	return $items;
}

/**
 * Fetch and apply detail page fields to an extracted item.
 *
 * @param array<string,mixed> $item Item.
 * @param array<string,mixed> $recipe Recipe.
 * @return array<string,mixed>
 */
function wpi_apply_detail_fields_to_item( $item, $recipe ) {
	$detail_url = wpi_get_detail_url_from_item( $item, $recipe );
	if ( empty( $detail_url ) || ! wp_http_validate_url( $detail_url ) ) {
		return $item;
	}

	$html = wpi_fetch_html( $detail_url );
	if ( is_wp_error( $html ) ) {
		return $item;
	}

	$dom = wpi_dom_from_html( $html );
	if ( ! $dom || ! $dom->documentElement ) {
		return $item;
	}

	foreach ( $recipe['detail_fields'] as $field ) {
		if ( empty( $field['selector'] ) ) {
			continue;
		}

		$value = wpi_extract_field( $dom->documentElement, $field, $detail_url );
		if ( '' === $value ) {
			continue;
		}

		$item = wpi_apply_field_value_to_item( $item, $field, $value, 'detail' );
	}

	return $item;
}

/**
 * Get a detail URL from an item.
 *
 * @param array<string,mixed> $item Item.
 * @param array<string,mixed> $recipe Recipe.
 * @return string
 */
function wpi_get_detail_url_from_item( $item, $recipe ) {
	foreach ( array( 'source_url', 'detail_url', 'url' ) as $key ) {
		if ( ! empty( $item['meta'][ $key ] ) ) {
			return $item['meta'][ $key ];
		}
		if ( ! empty( $item['acf'][ $key ] ) ) {
			return $item['acf'][ $key ];
		}
	}

	$unique = wpi_get_unique_value_from_item( $item, $recipe );
	return wp_http_validate_url( $unique ) ? $unique : '';
}

/**
 * Check whether a field has at least one destination.
 *
 * @param array<string,mixed> $field Field map.
 * @return bool
 */
function wpi_field_has_destination( $field ) {
	return ! empty( $field['post_field'] ) || ! empty( $field['meta_key'] ) || ! empty( $field['acf_key'] ) || ! empty( $field['featured_image'] ) || ! empty( $field['target'] );
}

/**
 * Apply an extracted value to the structured item array.
 *
 * @param array<string,mixed>  $item Item.
 * @param array<string,mixed>  $field Field map.
 * @param string              $value Extracted value.
 * @param string              $scope item|detail.
 * @return array<string,mixed>
 */
function wpi_apply_field_value_to_item( $item, $field, $value, $scope = 'item' ) {
	if ( ! empty( $field['target'] ) ) {
		$field = wpi_upgrade_legacy_field_target( $field );
	}

	$field = wpi_normalize_field_for_form( $field, false );
	$token = wpi_field_token_key( $field, $scope );
	if ( '' !== $token ) {
		$item['tokens'][ $token ] = $value;
	}

	if ( ! empty( $field['post_field'] ) ) {
		$item['post'][ $field['post_field'] ] = $value;
	}

	if ( ! empty( $field['meta_key'] ) ) {
		$item['meta'][ $field['meta_key'] ] = $value;
	}

	if ( ! empty( $field['acf_key'] ) ) {
		$item['acf'][ $field['acf_key'] ] = $value;
	}

	if ( ! empty( $field['featured_image'] ) ) {
		$item['featured_image'] = $value;
	}

	return $item;
}

/**
 * Convert legacy target fields to the new destination keys.
 *
 * @param array<string,mixed> $field Field map.
 * @return array<string,mixed>
 */
function wpi_upgrade_legacy_field_target( $field ) {
	$target = isset( $field['target'] ) ? $field['target'] : '';
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
 * Get the configured unique value from an extracted item.
 *
 * @param array<string,mixed> $item Item.
 * @param array<string,mixed> $recipe Recipe.
 * @return string
 */
function wpi_get_unique_value_from_item( $item, $recipe ) {
	$target = isset( $item['_unique_target'] ) ? trim( (string) $item['_unique_target'] ) : '';
	if ( '' === $target ) {
		$target = isset( $recipe['unique_target'] ) ? trim( (string) $recipe['unique_target'] ) : 'meta:source_url';
	}

	if ( 0 === strpos( $target, 'meta:' ) ) {
		$key = substr( $target, 5 );
		return isset( $item['meta'][ $key ] ) ? $item['meta'][ $key ] : '';
	}

	if ( 0 === strpos( $target, 'acf:' ) ) {
		$key = substr( $target, 4 );
		return isset( $item['acf'][ $key ] ) ? $item['acf'][ $key ] : '';
	}

	if ( 0 === strpos( $target, 'post:' ) ) {
		$key = substr( $target, 5 );
		return isset( $item['post'][ $key ] ) ? $item['post'][ $key ] : '';
	}

	if ( isset( $item['post'][ $target ] ) ) {
		return $item['post'][ $target ];
	}
	if ( isset( $item['meta'][ $target ] ) ) {
		return $item['meta'][ $target ];
	}
	if ( isset( $item['acf'][ $target ] ) ) {
		return $item['acf'][ $target ];
	}

	return '';
}

/**
 * Find the document that contains the configured item selector.
 *
 * @param string              $html HTML document.
 * @param array<string,mixed> $recipe Recipe.
 * @param string              $source_url Source URL.
 * @return array{html:string,url:string}
 */
function wpi_find_import_source( $html, $recipe, $source_url ) {
	$dom = wpi_dom_from_html( $html );
	if ( $dom && ! empty( $recipe['item_selector'] ) && wpi_query_selector_all( $dom, $recipe['item_selector'] ) ) {
		return array(
			'html' => $html,
			'url'  => $source_url,
		);
	}

	foreach ( wpi_find_iframe_sources( $html, $source_url ) as $iframe_url ) {
		$iframe_html = wpi_fetch_html( $iframe_url );
		if ( is_wp_error( $iframe_html ) ) {
			continue;
		}

		$iframe_dom = wpi_dom_from_html( $iframe_html );
		if ( $iframe_dom && wpi_query_selector_all( $iframe_dom, $recipe['item_selector'] ) ) {
			return array(
				'html' => $iframe_html,
				'url'  => $iframe_url,
			);
		}
	}

	return array(
		'html' => $html,
		'url'  => $source_url,
	);
}

/**
 * Extract one field from an item node.
 *
 * @param DOMElement          $item_node Item node.
 * @param array<string,mixed> $field Field map.
 * @param string              $source_url Source URL for resolving link attributes.
 * @return string
 */
function wpi_extract_field( $item_node, $field, $source_url = '' ) {
	$selector = isset( $field['selector'] ) ? trim( (string) $field['selector'] ) : '';
	$attr     = isset( $field['attr'] ) ? $field['attr'] : 'text';
	$nodes    = '' === $selector ? array( $item_node ) : wpi_query_selector_all( $item_node, $selector );

	if ( '' !== $selector && wpi_node_matches_simple_selector( $item_node, $selector ) ) {
		array_unshift( $nodes, $item_node );
	}

	if ( empty( $nodes ) ) {
		return '';
	}

	$node = null;
	foreach ( $nodes as $candidate ) {
		$value = wpi_extract_node_value( $candidate, $attr, $source_url );
		if ( '' !== $value ) {
			return $value;
		}
		$node = $candidate;
	}

	if ( ! $node ) {
		return '';
	}

	return wpi_extract_node_value( $node, $attr, $source_url );
}

/**
 * Check whether a node matches a simple non-descendant selector.
 *
 * @param DOMElement $node DOM node.
 * @param string     $selector Selector.
 * @return bool
 */
function wpi_node_matches_simple_selector( $node, $selector ) {
	$selector = trim( (string) $selector );
	if ( '' === $selector || false !== strpos( $selector, ' ' ) ) {
		return false;
	}

	if ( ! preg_match( '/^(?:(?P<tag>[A-Za-z][A-Za-z0-9_-]*)|\*)?(?:(?P<id>#[A-Za-z][A-Za-z0-9_-]*))?(?P<classes>(?:\.[A-Za-z][A-Za-z0-9_-]*)*)$/', $selector, $matches ) ) {
		return false;
	}

	if ( ! empty( $matches['tag'] ) && strtolower( $node->tagName ) !== strtolower( $matches['tag'] ) ) {
		return false;
	}

	if ( ! empty( $matches['id'] ) && $node->getAttribute( 'id' ) !== substr( $matches['id'], 1 ) ) {
		return false;
	}

	if ( ! empty( $matches['classes'] ) ) {
		$node_classes = ' ' . preg_replace( '/\s+/', ' ', trim( $node->getAttribute( 'class' ) ) ) . ' ';
		preg_match_all( '/\.([A-Za-z][A-Za-z0-9_-]*)/', $matches['classes'], $class_matches );
		foreach ( $class_matches[1] as $class ) {
			if ( false === strpos( $node_classes, ' ' . $class . ' ' ) ) {
				return false;
			}
		}
	}

	return true;
}

/**
 * Extract a node value.
 *
 * @param DOMElement $node Node.
 * @param string     $attr Attribute.
 * @param string     $source_url Source URL.
 * @return string
 */
function wpi_extract_node_value( $node, $attr, $source_url = '' ) {
	switch ( $attr ) {
		case 'html':
			$value = '';
			foreach ( $node->childNodes as $child ) {
				$value .= $node->ownerDocument->saveHTML( $child );
			}
			return trim( wp_kses_post( $value ) );

		case 'href':
		case 'src':
			$value = trim( $node->getAttribute( $attr ) );
			return $source_url ? wpi_resolve_url( $value, $source_url ) : $value;

		case 'text':
		default:
			return trim( preg_replace( '/\s+/', ' ', $node->textContent ) );
	}
}

/**
 * Check whether an item was already imported.
 *
 * @param string $unique_value Unique value.
 * @return bool
 */
function wpi_item_exists( $unique_value ) {
	$existing = get_posts(
		array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => '_wpi_unique_value',
					'value' => sanitize_text_field( $unique_value ),
				),
			),
		)
	);

	return ! empty( $existing );
}

/**
 * Create a post from an extracted item.
 *
 * @param array<string,string> $item Extracted item.
 * @param array<string,mixed>  $recipe Recipe.
 * @return int|WP_Error
 */
function wpi_create_post_from_item( $item, $recipe ) {
	$post_type = isset( $recipe['post_type'] ) && post_type_exists( $recipe['post_type'] ) ? $recipe['post_type'] : 'post';
	$post_data = isset( $item['post'] ) && is_array( $item['post'] ) ? $item['post'] : array();

	$content = isset( $post_data['post_content'] ) ? (string) $post_data['post_content'] : '';
	$content_template = isset( $item['_content_template'] ) ? $item['_content_template'] : ( isset( $recipe['content_template'] ) ? $recipe['content_template'] : '' );
	if ( ! empty( $content_template ) ) {
		$template_content = wpi_render_content_template( $content_template, $item );
		if ( '' !== $template_content ) {
			$content = trim( $content . "\n\n" . $template_content );
		}
	}

	$postarr = array(
		'post_type'    => $post_type,
		'post_status'  => isset( $recipe['import_post_status'] ) ? wpi_sanitize_import_post_status( $recipe['import_post_status'] ) : 'draft',
		'post_title'   => isset( $post_data['post_title'] ) && '' !== trim( $post_data['post_title'] ) ? sanitize_text_field( $post_data['post_title'] ) : __( 'Imported item', 'wp-pattern-import' ),
		'post_content' => wpi_format_post_content_blocks( $content ),
		'post_excerpt' => isset( $post_data['post_excerpt'] ) ? sanitize_textarea_field( $post_data['post_excerpt'] ) : '',
	);

	if ( ! empty( $post_data['post_name'] ) ) {
		$postarr['post_name'] = sanitize_title( $post_data['post_name'] );
	}

	if ( ! empty( $post_data['post_status'] ) && in_array( $post_data['post_status'], array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
		$postarr['post_status'] = $post_data['post_status'];
	}

	if ( ! empty( $post_data['post_date'] ) ) {
		$post_date = wpi_parse_post_date_value( $post_data['post_date'] );
		if ( $post_date ) {
			$postarr['post_date']     = $post_date;
			$postarr['post_date_gmt'] = get_gmt_from_date( $post_date );
		}
	}

	$post_id = wp_insert_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	$meta = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array();
	foreach ( $meta as $key => $value ) {
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			continue;
		}

		update_post_meta( $post_id, $key, sanitize_text_field( $value ) );
	}

	$acf = isset( $item['acf'] ) && is_array( $item['acf'] ) ? $item['acf'] : array();
	foreach ( $acf as $key => $value ) {
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			continue;
		}

		update_post_meta( $post_id, $key, sanitize_text_field( $value ) );
	}

	if ( ! empty( $item['featured_image'] ) ) {
		wpi_set_featured_image_from_url( $post_id, $item['featured_image'] );
	}

	return $post_id;
}

/**
 * Parse a mapped post_date value into a MySQL datetime.
 *
 * @param string $value Raw date value.
 * @return string
 */
function wpi_parse_post_date_value( $value ) {
	$value = trim( wp_strip_all_tags( (string) $value ) );
	if ( '' === $value ) {
		return '';
	}

	$value = preg_replace( '/\b(?:published|posted|updated|date)\b\s*:?\s*/i', '', $value );
	$value = trim( preg_replace( '/\s+/', ' ', $value ) );

	$formats = array(
		'j F Y',
		'd F Y',
		'F j, Y',
		'Y-m-d',
		'd/m/Y',
		'j/m/Y',
		'm/d/Y',
	);

	$timezone = wp_timezone();
	foreach ( $formats as $format ) {
		$date = DateTimeImmutable::createFromFormat( '!' . $format, $value, $timezone );
		if ( $date instanceof DateTimeImmutable ) {
			$errors = DateTimeImmutable::getLastErrors();
			if ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) {
				return $date->format( 'Y-m-d H:i:s' );
			}
		}
	}

	$timestamp = strtotime( $value );
	if ( ! $timestamp ) {
		return '';
	}

	return wp_date( 'Y-m-d H:i:s', $timestamp, $timezone );
}

/**
 * Format imported post content as block-friendly content.
 *
 * @param string $content Content.
 * @return string
 */
function wpi_format_post_content_blocks( $content ) {
	$content = trim( (string) $content );
	if ( '' === $content ) {
		return '';
	}

	if ( false !== strpos( $content, '<!-- wp:' ) ) {
		return wp_kses_post( $content );
	}

	$allowed_html = wp_kses_post( $content );
	if ( $allowed_html !== wp_strip_all_tags( $allowed_html ) ) {
		return "<!-- wp:html -->\n" . $allowed_html . "\n<!-- /wp:html -->";
	}

	return "<!-- wp:paragraph -->\n<p>" . esc_html( $content ) . "</p>\n<!-- /wp:paragraph -->";
}

/**
 * Replace content template tokens with extracted item values.
 *
 * @param string              $template Template HTML.
 * @param array<string,mixed> $item Extracted item.
 * @return string
 */
function wpi_render_content_template( $template, $item ) {
	$template = wp_kses_post( (string) $template );
	if ( '' === trim( $template ) ) {
		return '';
	}

	$tokens = isset( $item['tokens'] ) && is_array( $item['tokens'] ) ? $item['tokens'] : array();
	$tokens['featured_image'] = isset( $item['featured_image'] ) ? $item['featured_image'] : '';

	foreach ( array( 'post', 'meta', 'acf' ) as $bucket ) {
		if ( empty( $item[ $bucket ] ) || ! is_array( $item[ $bucket ] ) ) {
			continue;
		}
		foreach ( $item[ $bucket ] as $key => $value ) {
			$tokens[ sanitize_key( $key ) ] = $value;
		}
	}

	foreach ( $tokens as $token => $value ) {
		$template = str_replace( '%' . sanitize_key( $token ) . '%', esc_html( (string) $value ), $template );
	}

	return wp_kses_post( $template );
}

/**
 * Sideload and assign a featured image from a URL.
 *
 * @param int    $post_id Post ID.
 * @param string $url Image URL.
 * @return void
 */
function wpi_set_featured_image_from_url( $post_id, $url ) {
	$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
	if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );
	if ( is_wp_error( $attachment_id ) ) {
		update_post_meta( $post_id, '_wpi_featured_image_url', $url );
		return;
	}

	set_post_thumbnail( $post_id, $attachment_id );
	update_post_meta( $post_id, '_wpi_featured_image_url', $url );
}
