<?php
/**
 * HTML Parser for FC2 BLOG
 * Uses DOMDocument for parsing FC2 blog pages
 *
 * @license GPL-2.0-or-later
 */

class FC2HtmlParser {

	/**
	 * Fetch HTML content from URL
	 *
	 * @param string $url URL to fetch
	 * @return string|false HTML content or false on failure
	 */
	public function fetchHtml( $url ) {
		$context = stream_context_create( [
			'http' => [
				'method'  => 'GET',
				'header'  => "User-Agent: Mozilla/5.0 (compatible; FC2Blog2WP/1.0)\r\n",
				'timeout' => 30,
			],
		] );

		$html = @file_get_contents( $url, false, $context );
		return $html !== false ? $html : false;
	}

	/**
	 * Parse HTML and create DOMDocument
	 *
	 * @param string $html HTML content
	 * @return DOMDocument|false
	 */
	public function parseHtml( $html ) {
		if ( empty( $html ) ) {
			return false;
		}

		$dom = new DOMDocument();
		@$dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		return $dom;
	}

	/**
	 * Create XPath object from DOMDocument
	 *
	 * @param DOMDocument $dom
	 * @return DOMXPath
	 */
	public function createXPath( $dom ) {
		return new DOMXPath( $dom );
	}

	/**
	 * Find elements by XPath query
	 *
	 * @param DOMXPath $xpath
	 * @param string $query
	 * @return DOMNodeList
	 */
	public function query( $xpath, $query ) {
		return $xpath->query( $query );
	}

	/**
	 * Get text content from element
	 *
	 * @param DOMNode $node
	 * @return string
	 */
	public function getTextContent( $node ) {
		return $node ? trim( $node->textContent ) : '';
	}

	/**
	 * Get attribute from element
	 *
	 * @param DOMNode $node
	 * @param string $attr
	 * @return string
	 */
	public function getAttribute( $node, $attr ) {
		return $node && method_exists( $node, 'getAttribute' ) ? $node->getAttribute( $attr ) : '';
	}

	/**
	 * Get inner HTML content from element
	 *
	 * @param DOMDocument $dom
	 * @param DOMNode $node
	 * @return string
	 */
	public function getHtmlContent( $dom, $node ) {
		if ( ! $node ) {
			return '';
		}

		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}

		return $html;
	}

	/**
	 * Extract post title from FC2 blog post page
	 * Selector: h1.entryTitle
	 *
	 * @param string $html
	 * @return string
	 */
	public function extractTitle( $html ) {
		$dom = $this->parseHtml( $html );
		if ( ! $dom ) {
			return '';
		}

		$xpath = $this->createXPath( $dom );

		// Try h1.entryTitle first
		$nodes = $this->query( $xpath, '//h1[contains(@class, "entryTitle")]' );
		if ( $nodes->length > 0 ) {
			return $this->getTextContent( $nodes->item( 0 ) );
		}

		// Fallback: any element with entryTitle class
		$nodes = $this->query( $xpath, '//*[contains(@class, "entryTitle")]' );
		if ( $nodes->length > 0 ) {
			return $this->getTextContent( $nodes->item( 0 ) );
		}

		return '';
	}

	/**
	 * Extract post body HTML from FC2 blog post page
	 * Selector: div.l-entryBody
	 *
	 * @param string $html
	 * @return string Inner HTML of the body element
	 */
	public function extractBody( $html ) {
		$dom = $this->parseHtml( $html );
		if ( ! $dom ) {
			return '';
		}

		$xpath = $this->createXPath( $dom );

		$nodes = $this->query( $xpath, '//div[contains(@class, "l-entryBody")]' );
		if ( $nodes->length > 0 ) {
			return $this->getHtmlContent( $dom, $nodes->item( 0 ) );
		}

		// Fallback
		$nodes = $this->query( $xpath, '//div[contains(@class, "entryBody")]' );
		if ( $nodes->length > 0 ) {
			return $this->getHtmlContent( $dom, $nodes->item( 0 ) );
		}

		return '';
	}

	/**
	 * Extract post date from FC2 blog post page
	 * Selector: .entryDate with child spans entryDate_y, entryDate_m, entryDate_d
	 *
	 * @param string $html
	 * @return string Date in "Y-m-d H:i:s" format
	 */
	public function extractDate( $html ) {
		$dom = $this->parseHtml( $html );
		if ( ! $dom ) {
			return date( 'Y-m-d H:i:s' );
		}

		$xpath = $this->createXPath( $dom );

		// Try individual year/month/day spans
		$year_nodes  = $this->query( $xpath, '//*[contains(@class, "entryDate_y")]' );
		$month_nodes = $this->query( $xpath, '//*[contains(@class, "entryDate_m")]' );
		$day_nodes   = $this->query( $xpath, '//*[contains(@class, "entryDate_d")]' );

		if ( $year_nodes->length > 0 ) {
			$year_text = $this->getTextContent( $year_nodes->item( 0 ) );

			// If entryDate_y contains full date like "2026/03/01"
			if ( preg_match( '/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $year_text, $m ) ) {
				return sprintf( '%04d-%02d-%02d 00:00:00', $m[1], $m[2], $m[3] );
			}

			// If entryDate_y contains only year
			if ( $month_nodes->length > 0 && $day_nodes->length > 0 ) {
				$year  = (int) $year_text;
				$month = (int) $this->getTextContent( $month_nodes->item( 0 ) );
				$day   = (int) $this->getTextContent( $day_nodes->item( 0 ) );

				if ( $year > 0 && $month > 0 && $day > 0 ) {
					return sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day );
				}
			}
		}

		// Fallback: look for any date pattern in .entryDate text
		$date_nodes = $this->query( $xpath, '//*[contains(@class, "entryDate")]' );
		if ( $date_nodes->length > 0 ) {
			$date_text = $this->getTextContent( $date_nodes->item( 0 ) );
			if ( preg_match( '/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $date_text, $m ) ) {
				return sprintf( '%04d-%02d-%02d 00:00:00', $m[1], $m[2], $m[3] );
			}
		}

		return date( 'Y-m-d H:i:s' );
	}

	/**
	 * Extract category from FC2 blog post page
	 * Selector: .entryCat
	 *
	 * @param string $html
	 * @return string Category name
	 */
	public function extractCategory( $html ) {
		$dom = $this->parseHtml( $html );
		if ( ! $dom ) {
			return '';
		}

		$xpath = $this->createXPath( $dom );

		$nodes = $this->query( $xpath, '//*[contains(@class, "entryCat")]' );
		if ( $nodes->length > 0 ) {
			// Skip the first if it's just a label; look for link text
			foreach ( $nodes as $node ) {
				$text = $this->getTextContent( $node );
				if ( ! empty( $text ) ) {
					return $text;
				}
			}
		}

		return '';
	}

	/**
	 * Extract tags from FC2 blog post page
	 * Selector: .entryTag_list a
	 *
	 * @param string $html
	 * @return array Array of tag names
	 */
	public function extractTags( $html ) {
		$dom = $this->parseHtml( $html );
		if ( ! $dom ) {
			return [];
		}

		$xpath = $this->createXPath( $dom );
		$tags  = [];

		$nodes = $this->query( $xpath, '//*[contains(@class, "entryTag_list")]//a' );
		foreach ( $nodes as $node ) {
			$text = $this->getTextContent( $node );
			if ( ! empty( $text ) ) {
				$tags[] = $text;
			}
		}

		return $tags;
	}

	/**
	 * Extract comments from FC2 blog post page
	 * Selector: .commentList .commentList_item
	 *
	 * @param string $html
	 * @return array Array of comment data
	 */
	public function extractComments( $html ) {
		$dom = $this->parseHtml( $html );
		if ( ! $dom ) {
			return [];
		}

		$xpath    = $this->createXPath( $dom );
		$comments = [];

		$items = $this->query( $xpath, '//*[contains(@class, "commentList_item")]' );

		foreach ( $items as $item ) {
			$author_nodes = $this->query( $xpath, './/*[contains(@class, "commentList_author")]', );
			$date_nodes   = $this->query( $xpath, './/*[contains(@class, "commentList_date")]' );
			$text_nodes   = $this->query( $xpath, './/*[contains(@class, "commentList_text")]' );

			// Use item-scoped XPath
			$item_xpath   = new DOMXPath( $dom );
			$author_nodes = $item_xpath->query( './/*[contains(@class, "commentList_author")]', $item );
			$date_nodes   = $item_xpath->query( './/*[contains(@class, "commentList_date")]', $item );
			$text_nodes   = $item_xpath->query( './/*[contains(@class, "commentList_text")]', $item );

			$author = $author_nodes->length > 0 ? $this->getTextContent( $author_nodes->item( 0 ) ) : '';
			$date   = $date_nodes->length > 0 ? $this->getTextContent( $date_nodes->item( 0 ) ) : '';
			$text   = $text_nodes->length > 0 ? $this->getTextContent( $text_nodes->item( 0 ) ) : '';

			if ( empty( $text ) ) {
				continue;
			}

			// Parse date string like "2026/03/01"
			$parsed_date = date( 'Y-m-d H:i:s' );
			if ( preg_match( '/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $date, $dm ) ) {
				$parsed_date = sprintf( '%04d-%02d-%02d 00:00:00', $dm[1], $dm[2], $dm[3] );
			}

			$comments[] = [
				'author' => $author,
				'date'   => $parsed_date,
				'text'   => $text,
			];
		}

		return $comments;
	}

	/**
	 * Extract monthly archive URLs from FC2 blog main/index page
	 * Finds all links with href containing "blog-date-"
	 *
	 * @param string $html
	 * @param string $base_url Base blog URL for relative URL resolution
	 * @return array Array of absolute archive URLs
	 */
	public function extractMonthlyArchiveUrls( $html, $base_url ) {
		$dom = $this->parseHtml( $html );
		if ( ! $dom ) {
			return [];
		}

		$xpath = $this->createXPath( $dom );
		$urls  = [];
		$seen  = [];

		$links = $this->query( $xpath, '//a[contains(@href, "blog-date-")]' );

		foreach ( $links as $link ) {
			$href = $this->getAttribute( $link, 'href' );
			if ( empty( $href ) ) {
				continue;
			}

			$href = $this->toAbsoluteUrl( $href, $base_url );

			if ( ! isset( $seen[ $href ] ) ) {
				$urls[]       = $href;
				$seen[ $href ] = true;
			}
		}

		return $urls;
	}

	/**
	 * Extract post URLs from FC2 blog archive/index page
	 * Finds all links with href containing "blog-entry-"
	 *
	 * @param string $html
	 * @param string $base_url
	 * @return array Array of absolute post URLs
	 */
	public function extractPostUrls( $html, $base_url ) {
		$dom = $this->parseHtml( $html );
		if ( ! $dom ) {
			return [];
		}

		$xpath = $this->createXPath( $dom );
		$urls  = [];
		$seen  = [];

		$links = $this->query( $xpath, '//a[contains(@href, "blog-entry-")]' );

		foreach ( $links as $link ) {
			$href = $this->getAttribute( $link, 'href' );
			if ( empty( $href ) ) {
				continue;
			}

			// Remove fragment and query string
			$href = preg_replace( '/[#?].*$/', '', $href );

			if ( ! preg_match( '/blog-entry-\d+\.html$/', $href ) ) {
				continue;
			}

			$href = $this->toAbsoluteUrl( $href, $base_url );

			if ( ! isset( $seen[ $href ] ) ) {
				$urls[]       = $href;
				$seen[ $href ] = true;
			}
		}

		return $urls;
	}

	/**
	 * Convert a relative URL to absolute URL
	 *
	 * @param string $href
	 * @param string $base_url
	 * @return string
	 */
	private function toAbsoluteUrl( $href, $base_url ) {
		if ( strpos( $href, 'http' ) === 0 ) {
			return $href;
		}

		// Extract scheme + host from base_url
		if ( preg_match( '/^(https?:\/\/[^\/]+)/', $base_url, $m ) ) {
			return rtrim( $m[1], '/' ) . '/' . ltrim( $href, '/' );
		}

		return $href;
	}
}
