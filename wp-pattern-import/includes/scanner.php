<?php
/**
 * Scanner helpers for WP Pattern Import.
 *
 * @package WPPatternImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch source HTML using WordPress safe HTTP handling.
 *
 * @param string $url Source URL.
 * @return string|WP_Error
 */
function wpi_fetch_html( $url ) {
	$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );

	if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
		return new WP_Error( 'wpi_invalid_url', __( 'Please enter a valid HTTP or HTTPS URL.', 'wp-pattern-import' ) );
	}

	$response = wp_safe_remote_get(
		$url,
		array(
			'timeout'             => 15,
			'redirection'         => 3,
			'limit_response_size' => 1024 * 1024 * 2,
			'user-agent'          => 'WP Pattern Import/' . WPI_VERSION . '; ' . home_url( '/' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error(
			'wpi_http_error',
			sprintf(
				/* translators: %d: HTTP response status code. */
				__( 'The source URL returned HTTP %d.', 'wp-pattern-import' ),
				$status
			)
		);
	}

	$body = wp_remote_retrieve_body( $response );
	if ( '' === trim( $body ) ) {
		return new WP_Error( 'wpi_empty_response', __( 'The source URL returned an empty response.', 'wp-pattern-import' ) );
	}

	return $body;
}

/**
 * Find repeated class-based HTML patterns.
 *
 * @param string $html HTML document.
 * @param string $source_url Source URL for resolving links.
 * @return array<int,array<string,mixed>>
 */
function wpi_find_patterns( $html, $source_url = '' ) {
	$dom = wpi_dom_from_html( $html );
	if ( ! $dom ) {
		return array();
	}

	$xpath  = new DOMXPath( $dom );
	$nodes  = $xpath->query( '//*[@class]' );
	$groups = array();

	foreach ( $nodes as $node ) {
		if ( ! $node instanceof DOMElement ) {
			continue;
		}

		$classes = preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) );
		foreach ( $classes as $class ) {
			if ( '' === $class || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $class ) ) {
				continue;
			}

			$key = strtolower( $node->tagName ) . '.' . $class;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'tag'   => strtolower( $node->tagName ),
					'class' => $class,
					'nodes' => array(),
				);
			}

			$groups[ $key ]['nodes'][] = $node;
		}
	}

	$candidates = array();

	foreach ( $groups as $group ) {
		$matches = count( $group['nodes'] );
		if ( $matches < 3 ) {
			continue;
		}

		$score = wpi_score_pattern_nodes( $group['nodes'] );
		if ( $score <= 0 ) {
			continue;
		}

		$samples = wpi_build_pattern_samples( $group['nodes'], $source_url, 3 );
		if ( count( $samples ) < 3 ) {
			continue;
		}

		$candidates[] = array(
			'selector' => '.' . $group['class'],
			'matches'  => $matches,
			'sample'   => $samples[0]['text'],
			'samples'  => $samples,
			'_score'   => $score,
		);
	}

	usort(
		$candidates,
		function ( $a, $b ) {
			if ( $a['_score'] === $b['_score'] ) {
				return $b['matches'] <=> $a['matches'];
			}

			return $b['_score'] <=> $a['_score'];
		}
	);

	$candidates = array_slice( $candidates, 0, 20 );

	foreach ( $candidates as &$candidate ) {
		unset( $candidate['_score'] );
	}

	return $candidates;
}

/**
 * Scan a URL and any first-level iframe URLs for repeated patterns.
 *
 * @param string $url Source URL.
 * @param string $item_selector Optional item selector for field scanning.
 * @return array<string,mixed>|WP_Error
 */
function wpi_scan_url_for_patterns( $url, $item_selector = '' ) {
	$html = wpi_fetch_html( $url );
	if ( is_wp_error( $html ) ) {
		return $html;
	}

	$sources = array(
		array(
			'url'  => $url,
			'html' => $html,
		),
	);

	foreach ( wpi_find_iframe_sources( $html, $url ) as $iframe_url ) {
		$iframe_html = wpi_fetch_html( $iframe_url );
		if ( is_wp_error( $iframe_html ) ) {
			continue;
		}

		$sources[] = array(
			'url'  => $iframe_url,
			'html' => $iframe_html,
		);
	}

	$patterns = array();
	$item_scan = array();
	foreach ( $sources as $source ) {
		foreach ( wpi_find_patterns( $source['html'], $source['url'] ) as $pattern ) {
			$patterns[] = $pattern;
		}
	}

	if ( '' !== $item_selector ) {
		foreach ( $sources as $source ) {
			$item_scan = wpi_scan_item_fields( $source['html'], $item_selector, $source['url'] );
			if ( ! empty( $item_scan['count'] ) ) {
				break;
			}
		}
	}

	usort(
		$patterns,
		function ( $a, $b ) {
			if ( $a['matches'] === $b['matches'] ) {
				return strcmp( $a['selector'], $b['selector'] );
			}

			return $b['matches'] <=> $a['matches'];
		}
	);

	return array(
		'patterns' => array_slice( $patterns, 0, 30 ),
		'item'     => $item_scan,
		'sources'  => wp_list_pluck( $sources, 'url' ),
	);
}

/**
 * Scan repeated items for likely field selectors.
 *
 * @param string $html HTML document.
 * @param string $item_selector Item selector.
 * @param string $source_url Source URL for resolving links.
 * @return array<string,mixed>
 */
function wpi_scan_item_fields( $html, $item_selector, $source_url = '' ) {
	$dom = wpi_dom_from_html( $html );
	if ( ! $dom ) {
		return array(
			'count'  => 0,
			'fields' => array(),
		);
	}

	$items = wpi_query_selector_all( $dom, $item_selector );
	if ( empty( $items ) ) {
		return array(
			'count'  => 0,
			'fields' => array(),
		);
	}

	return array(
		'count'  => count( $items ),
		'fields' => wpi_find_field_patterns_for_items( $items, $source_url ),
	);
}

/**
 * Scan one detail page for likely field selectors.
 *
 * @param string $html Detail page HTML.
 * @param string $source_url Detail page URL.
 * @return array<int,array<string,mixed>>
 */
function wpi_scan_detail_fields( $html, $source_url = '' ) {
	$dom = wpi_dom_from_html( $html );
	if ( ! $dom ) {
		return array();
	}

	$context = $dom->documentElement;
	if ( ! $context ) {
		return array();
	}

	$candidates = array(
		array( 'selector' => 'h1', 'attr' => 'text', 'label' => 'Heading text' ),
		array( 'selector' => 'h2', 'attr' => 'text', 'label' => 'Heading text' ),
		array( 'selector' => '.field-content', 'attr' => 'text', 'label' => 'Field content' ),
		array( 'selector' => '.field--name-body', 'attr' => 'html', 'label' => 'Body HTML' ),
		array( 'selector' => 'article', 'attr' => 'html', 'label' => 'Article HTML' ),
		array( 'selector' => 'main', 'attr' => 'html', 'label' => 'Main HTML' ),
		array( 'selector' => 'p', 'attr' => 'text', 'label' => 'Paragraph text' ),
		array( 'selector' => 'img', 'attr' => 'src', 'label' => 'Image URL' ),
		array( 'selector' => 'time', 'attr' => 'text', 'label' => 'Time text' ),
		array( 'selector' => '.published-date', 'attr' => 'text', 'label' => 'Published date' ),
	);

	$rows = array();
	$seen = array();
	foreach ( $candidates as $candidate ) {
		$samples = wpi_collect_field_samples( array( $context ), $candidate['selector'], $candidate['attr'], $source_url, 3 );
		if ( empty( $samples ) ) {
			continue;
		}

		$fingerprint = $candidate['attr'] . '|' . implode( '|', $samples );
		if ( isset( $seen[ $fingerprint ] ) ) {
			continue;
		}
		$seen[ $fingerprint ] = true;

		$rows[] = array(
			'label'    => $candidate['label'],
			'selector' => $candidate['selector'],
			'attr'     => $candidate['attr'],
			'samples'  => $samples,
		);
	}

	return $rows;
}

/**
 * Find likely field selectors inside repeated item nodes.
 *
 * @param array<int,DOMElement> $items Item nodes.
 * @param string                $source_url Source URL.
 * @return array<int,array<string,mixed>>
 */
function wpi_find_field_patterns_for_items( $items, $source_url ) {
	$candidates = array(
		array( 'selector' => 'h1 a', 'attr' => 'text', 'label' => 'Heading link text' ),
		array( 'selector' => 'h2 a', 'attr' => 'text', 'label' => 'Heading link text' ),
		array( 'selector' => 'h3 a', 'attr' => 'text', 'label' => 'Heading link text' ),
		array( 'selector' => 'h1', 'attr' => 'text', 'label' => 'Heading text' ),
		array( 'selector' => 'h2', 'attr' => 'text', 'label' => 'Heading text' ),
		array( 'selector' => 'h3', 'attr' => 'text', 'label' => 'Heading text' ),
		array( 'selector' => 'h1 a', 'attr' => 'href', 'label' => 'Detail URL' ),
		array( 'selector' => 'h2 a', 'attr' => 'href', 'label' => 'Detail URL' ),
		array( 'selector' => 'h3 a', 'attr' => 'href', 'label' => 'Detail URL' ),
		array( 'selector' => 'a', 'attr' => 'href', 'label' => 'Detail URL' ),
		array( 'selector' => 'img', 'attr' => 'src', 'label' => 'Image URL' ),
		array( 'selector' => 'p', 'attr' => 'text', 'label' => 'Paragraph text' ),
		array( 'selector' => '.published-date', 'attr' => 'text', 'label' => 'Published date' ),
	);

	$rows = array();
	$seen = array();
	foreach ( $candidates as $candidate ) {
		$samples = wpi_collect_field_samples( $items, $candidate['selector'], $candidate['attr'], $source_url, 3 );
		if ( count( $samples ) < 3 ) {
			continue;
		}

		$fingerprint = $candidate['attr'] . '|' . implode( '|', $samples );
		if ( isset( $seen[ $fingerprint ] ) ) {
			continue;
		}
		$seen[ $fingerprint ] = true;

		$rows[] = array(
			'label'    => $candidate['label'],
			'selector' => $candidate['selector'],
			'attr'     => $candidate['attr'],
			'samples'  => $samples,
		);
	}

	return $rows;
}

/**
 * Collect sample values for a field candidate across item nodes.
 *
 * @param array<int,DOMElement> $items Item nodes.
 * @param string                $selector Field selector.
 * @param string                $attr Field attribute.
 * @param string                $source_url Source URL.
 * @param int                   $limit Sample limit.
 * @return array<int,string>
 */
function wpi_collect_field_samples( $items, $selector, $attr, $source_url, $limit = 3 ) {
	$samples = array();

	foreach ( $items as $item ) {
		$nodes = wpi_query_selector_all( $item, $selector );
		if ( empty( $nodes ) ) {
			continue;
		}

		$value = '';
		foreach ( $nodes as $node ) {
			$value = wpi_extract_node_value_for_scan( $node, $attr, $source_url );
			if ( '' !== $value ) {
				break;
			}
		}
		if ( '' === $value ) {
			continue;
		}

		$samples[] = $value;
		if ( count( $samples ) >= $limit ) {
			break;
		}
	}

	return $samples;
}

/**
 * Extract a node value for scanner display.
 *
 * @param DOMElement $node Node.
 * @param string     $attr Attribute.
 * @param string     $source_url Source URL.
 * @return string
 */
function wpi_extract_node_value_for_scan( $node, $attr, $source_url ) {
	if ( 'href' === $attr || 'src' === $attr ) {
		return wpi_resolve_url( $node->getAttribute( $attr ), $source_url );
	}

	return wpi_trim_text( $node->textContent, 180 );
}

/**
 * Extract a few text samples for an item selector.
 *
 * @param string $html HTML document.
 * @param string $item_selector Item selector.
 * @param int    $limit Maximum samples.
 * @return array<int,string>
 */
function wpi_extract_sample_items( $html, $item_selector, $limit = 3 ) {
	$dom = wpi_dom_from_html( $html );
	if ( ! $dom ) {
		return array();
	}

	$nodes   = wpi_query_selector_all( $dom, $item_selector );
	$samples = array();

	foreach ( $nodes as $node ) {
		$samples[] = wpi_trim_text( $node->textContent, 200 );
		if ( count( $samples ) >= $limit ) {
			break;
		}
	}

	return $samples;
}

/**
 * Find iframe src values and resolve them against the source URL.
 *
 * @param string $html HTML document.
 * @param string $source_url Source URL.
 * @return array<int,string>
 */
function wpi_find_iframe_sources( $html, $source_url ) {
	$dom = wpi_dom_from_html( $html );
	if ( ! $dom ) {
		return array();
	}

	$urls  = array();
	$nodes = $dom->getElementsByTagName( 'iframe' );
	foreach ( $nodes as $node ) {
		if ( ! $node instanceof DOMElement ) {
			continue;
		}

		$src = trim( $node->getAttribute( 'src' ) );
		if ( '' === $src ) {
			continue;
		}

		$url = wpi_resolve_url( $src, $source_url );
		if ( $url && wp_http_validate_url( $url ) ) {
			$urls[] = $url;
		}
	}

	return array_values( array_unique( $urls ) );
}

/**
 * Build three useful examples for a repeated selector.
 *
 * @param array<int,DOMElement> $nodes Nodes.
 * @param string                $source_url Source URL.
 * @param int                   $limit Sample limit.
 * @return array<int,array{text:string,url:string}>
 */
function wpi_build_pattern_samples( $nodes, $source_url, $limit = 3 ) {
	$samples = array();

	foreach ( $nodes as $node ) {
		$text = wpi_trim_text( $node->textContent, 180 );
		$url  = wpi_find_detail_url_for_node( $node, $source_url );

		if ( '' === $text ) {
			continue;
		}

		$samples[] = array(
			'text' => $text,
			'url'  => $url,
		);

		if ( count( $samples ) >= $limit ) {
			break;
		}
	}

	return $samples;
}

/**
 * Find the best detail URL inside a node.
 *
 * @param DOMElement $node Node.
 * @param string     $source_url Source URL.
 * @return string
 */
function wpi_find_detail_url_for_node( $node, $source_url ) {
	$xpath = new DOMXPath( $node->ownerDocument );
	$links = $xpath->query( './/a[@href]', $node );

	foreach ( $links as $link ) {
		if ( ! $link instanceof DOMElement ) {
			continue;
		}

		$href = trim( $link->getAttribute( 'href' ) );
		if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === stripos( $href, 'javascript:' ) ) {
			continue;
		}

		$url = wpi_resolve_url( $href, $source_url );
		if ( $url && wp_http_validate_url( $url ) ) {
			return $url;
		}
	}

	return '';
}

/**
 * Resolve a relative URL against a base URL.
 *
 * @param string $url URL.
 * @param string $base_url Base URL.
 * @return string
 */
function wpi_resolve_url( $url, $base_url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}

	if ( preg_match( '#^https?://#i', $url ) ) {
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	$base = wp_parse_url( $base_url );
	if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
		return '';
	}

	if ( 0 === strpos( $url, '//' ) ) {
		return esc_url_raw( $base['scheme'] . ':' . $url, array( 'http', 'https' ) );
	}

	$root = $base['scheme'] . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );
	if ( 0 === strpos( $url, '/' ) ) {
		return esc_url_raw( $root . $url, array( 'http', 'https' ) );
	}

	$path = isset( $base['path'] ) ? $base['path'] : '/';
	$dir  = preg_replace( '#/[^/]*$#', '/', $path );

	return esc_url_raw( $root . $dir . $url, array( 'http', 'https' ) );
}

/**
 * Parse HTML into a DOMDocument.
 *
 * @param string $html HTML document.
 * @return DOMDocument|null
 */
function wpi_dom_from_html( $html ) {
	if ( ! class_exists( 'DOMDocument' ) ) {
		return null;
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
	libxml_clear_errors();

	return $loaded ? $dom : null;
}

/**
 * Run a deliberately small CSS selector subset via XPath.
 *
 * Supports tag, .class, #id, tag.class, and descendant selectors.
 *
 * @param DOMDocument|DOMElement $context DOM context.
 * @param string                 $selector CSS-ish selector.
 * @return array<int,DOMElement>
 */
function wpi_query_selector_all( $context, $selector ) {
	$selector = trim( (string) $selector );
	if ( '' === $selector ) {
		return array();
	}

	$xpath = $context instanceof DOMDocument ? new DOMXPath( $context ) : new DOMXPath( $context->ownerDocument );
	$query = wpi_selector_to_xpath( $selector, $context instanceof DOMElement );
	if ( '' === $query ) {
		return array();
	}

	$nodes = $xpath->query( $query, $context instanceof DOMElement ? $context : null );
	if ( ! $nodes ) {
		return array();
	}

	$results = array();
	foreach ( $nodes as $node ) {
		if ( $node instanceof DOMElement ) {
			$results[] = $node;
		}
	}

	return $results;
}

/**
 * Convert a small selector subset to XPath.
 *
 * @param string $selector CSS-ish selector.
 * @param bool   $relative Whether the query runs below an element.
 * @return string
 */
function wpi_selector_to_xpath( $selector, $relative = false ) {
	$parts = preg_split( '/\s+/', trim( $selector ) );
	if ( empty( $parts ) ) {
		return '';
	}

	$xpath_parts = array();
	foreach ( $parts as $part ) {
		$xpath_part = wpi_selector_part_to_xpath( $part );
		if ( '' === $xpath_part ) {
			return '';
		}
		$xpath_parts[] = $xpath_part;
	}

	return ( $relative ? './/' : '//' ) . implode( '//', $xpath_parts );
}

/**
 * Convert one simple selector part to XPath.
 *
 * @param string $part Simple selector part.
 * @return string
 */
function wpi_selector_part_to_xpath( $part ) {
	if ( ! preg_match( '/^(?:(?P<tag>[A-Za-z][A-Za-z0-9_-]*)|\*)?(?:(?P<id>#[A-Za-z][A-Za-z0-9_-]*))?(?P<classes>(?:\.[A-Za-z][A-Za-z0-9_-]*)*)$/', $part, $matches ) ) {
		return '';
	}

	$tag        = ! empty( $matches['tag'] ) ? strtolower( $matches['tag'] ) : '*';
	$conditions = array();

	if ( ! empty( $matches['id'] ) ) {
		$conditions[] = '@id="' . substr( $matches['id'], 1 ) . '"';
	}

	if ( ! empty( $matches['classes'] ) ) {
		preg_match_all( '/\.([A-Za-z][A-Za-z0-9_-]*)/', $matches['classes'], $class_matches );
		foreach ( $class_matches[1] as $class ) {
			$conditions[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
		}
	}

	return $tag . ( $conditions ? '[' . implode( ' and ', $conditions ) . ']' : '' );
}

/**
 * Score repeated nodes by whether they contain useful content.
 *
 * @param array<int,DOMElement> $nodes Nodes.
 * @return int
 */
function wpi_score_pattern_nodes( $nodes ) {
	$score = 0;
	$limit = min( count( $nodes ), 5 );

	for ( $i = 0; $i < $limit; $i++ ) {
		$node  = $nodes[ $i ];
		$xpath = new DOMXPath( $node->ownerDocument );
		$text  = wpi_trim_text( $node->textContent, 300 );

		if ( strlen( $text ) >= 20 ) {
			$score++;
		}
		if ( $xpath->query( './/a', $node )->length ) {
			$score += 2;
		}
		if ( $xpath->query( './/h1|.//h2|.//h3|.//h4', $node )->length ) {
			$score += 2;
		}
		if ( $xpath->query( './/img', $node )->length ) {
			$score++;
		}
	}

	return $score;
}

/**
 * Normalize and trim text.
 *
 * @param string $text Text.
 * @param int    $limit Character limit.
 * @return string
 */
function wpi_trim_text( $text, $limit = 160 ) {
	$text = preg_replace( '/\s+/', ' ', trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) ) );
	if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $limit ) {
		return mb_substr( $text, 0, $limit - 3 ) . '...';
	}

	if ( strlen( $text ) > $limit ) {
		return substr( $text, 0, $limit - 3 ) . '...';
	}

	return $text;
}
