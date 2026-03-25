<?php
/**
 * FC2Blog2WP core class
 *
 * @license GPL-2.0-or-later
 */

class FC2Blog2WP {

	/**
	 * HTML Parser instance
	 * @var FC2HtmlParser
	 */
	private $parser;

	/**
	 * Blog ID extracted from blog URL (e.g. "recordeurasia")
	 * @var string
	 */
	private $blogId;

	/**
	 * Temporary directory path
	 * @var string
	 */
	private $tempDir;

	/**
	 * Progress file path
	 * @var string
	 */
	private $progressFile;

	/**
	 * Constructor
	 */
	public function __construct() {
		require_once dirname( __FILE__ ) . '/fc2_html_parser.php';
		$this->parser       = new FC2HtmlParser();
		$this->blogId       = '';
		$this->tempDir      = '';
		$this->progressFile = '';
	}

	/**
	 * Extract blog ID from URL and initialize temp directory
	 *
	 * @param string $blogUrl e.g. https://example.blog.fc2.com/
	 * @return bool
	 */
	public function initTempDir( $blogUrl ) {
		// Extract blog ID from fc2.com URL
		// Patterns: https://example.blog.fc2.com/ or https://blog.fc2.com/example/
		if ( preg_match( '/\/\/([^.]+)\.blog\.fc2\.com/', $blogUrl, $m ) ) {
			$this->blogId = $m[1];
		} elseif ( preg_match( '/blog\.fc2\.com\/([^\/]+)/', $blogUrl, $m ) ) {
			$this->blogId = $m[1];
		} else {
			return false;
		}

		$wp_content_dir   = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		$this->tempDir    = $wp_content_dir . '/fc2blog2wp/' . $this->blogId;

		if ( ! file_exists( $this->tempDir ) ) {
			if ( ! wp_mkdir_p( $this->tempDir ) ) {
				@mkdir( $this->tempDir, 0755, true );
			}
		}

		$this->progressFile = $this->tempDir . '/progress.json';

		return file_exists( $this->tempDir );
	}

	/**
	 * Get temp directory path
	 * @return string
	 */
	public function getTempDir() {
		return $this->tempDir;
	}

	/**
	 * Load progress data
	 * @return array
	 */
	public function loadProgress() {
		if ( ! file_exists( $this->progressFile ) ) {
			return [
				'completed_posts' => [],
				'total_posts'     => 0,
				'started_at'      => date( 'Y-m-d H:i:s' ),
			];
		}

		$json = file_get_contents( $this->progressFile );
		$data = json_decode( $json, true );

		return $data ? $data : [ 'completed_posts' => [], 'total_posts' => 0 ];
	}

	/**
	 * Save progress data
	 * @param array $progress
	 */
	public function saveProgress( $progress ) {
		$progress['last_updated'] = date( 'Y-m-d H:i:s' );
		file_put_contents( $this->progressFile, json_encode( $progress, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), LOCK_EX );
	}

	/**
	 * Reset progress
	 */
	public function resetProgress() {
		if ( file_exists( $this->progressFile ) ) {
			@unlink( $this->progressFile );
		}
	}

	/**
	 * Check if a post URL has already been completed
	 * @param string $postUrl
	 * @param array $progress
	 * @return bool
	 */
	public function isPostCompleted( $postUrl, $progress ) {
		return in_array( $postUrl, $progress['completed_posts'] );
	}

	/**
	 * Mark a post URL as completed
	 * @param string $postUrl
	 * @param array &$progress
	 */
	public function markPostCompleted( $postUrl, &$progress ) {
		if ( ! in_array( $postUrl, $progress['completed_posts'] ) ) {
			$progress['completed_posts'][] = $postUrl;
			$this->saveProgress( $progress );
		}
	}

	/**
	 * Get archive index URL (= the blog top page)
	 * @param string $blogUrl
	 * @return string|false
	 */
	public function getArchiveIndexUrl( $blogUrl ) {
		if ( strpos( $blogUrl, 'fc2.com' ) === false ) {
			return false;
		}

		// Normalize: ensure trailing slash
		if ( ! preg_match( '/\/$/', $blogUrl ) ) {
			$blogUrl .= '/';
		}

		return $blogUrl;
	}

	/**
	 * Get all monthly archive URLs from the blog top page sidebar
	 *
	 * @param string $indexUrl
	 * @return array
	 */
	public function getArchivesUrl( $indexUrl ) {
		$archivesUrl = [ $indexUrl ];

		$html = $this->parser->fetchHtml( $indexUrl );
		if ( $html === false ) {
			return $archivesUrl;
		}

		$monthlyUrls = $this->parser->extractMonthlyArchiveUrls( $html, $indexUrl );

		foreach ( $monthlyUrls as $url ) {
			if ( ! in_array( $url, $archivesUrl ) ) {
				$archivesUrl[] = $url;
			}
		}

		return $archivesUrl;
	}

	/**
	 * Get all post URLs from archive pages
	 * Handles FC2 autopager pagination (?page=N&more)
	 *
	 * @param array $archivesUrl
	 * @return array
	 */
	public function getPostsUrl( $archivesUrl ) {
		$postsUrl = [];
		$seen     = [];

		foreach ( $archivesUrl as $archiveUrl ) {
			// Fetch pages with pagination until no new posts found
			for ( $page = 1; $page <= 20; $page++ ) {
				$url = $page === 1 ? $archiveUrl : $archiveUrl . '?page=' . $page . '&more';

				$html = $this->parser->fetchHtml( $url );
				if ( $html === false ) {
					break;
				}

				$newUrls = $this->parser->extractPostUrls( $html, $archiveUrl );

				$added = 0;
				foreach ( $newUrls as $postUrl ) {
					if ( ! isset( $seen[ $postUrl ] ) ) {
						$postsUrl[]          = $postUrl;
						$seen[ $postUrl ]    = true;
						$added++;
					}
				}

				// Stop paginating if no new posts found on this page
				if ( $added === 0 ) {
					break;
				}
			}
		}

		return array_values( $postsUrl );
	}

	/**
	 * Fetch and parse a single post page
	 *
	 * @param string $postUrl
	 * @return array|false Post data array or false on failure
	 */
	public function getPostData( $postUrl ) {
		$html = $this->parser->fetchHtml( $postUrl );
		if ( $html === false ) {
			return false;
		}

		$title    = $this->parser->extractTitle( $html );
		$body     = $this->parser->extractBody( $html );
		$date     = $this->parser->extractDate( $html );
		$category = $this->parser->extractCategory( $html );
		$tags     = $this->parser->extractTags( $html );
		$comments = $this->parser->extractComments( $html );

		if ( empty( $title ) && empty( $body ) ) {
			return false;
		}

		return [
			'title'        => $title,
			'content'      => $this->convertToBlocks( $body ),
			'raw_content'  => $body,
			'date'         => $date,
			'category'     => $category,
			'tags'         => $tags,
			'comments'     => $comments,
			'original_url' => $postUrl,
		];
	}

	/**
	 * Convert HTML content to Gutenberg blocks
	 *
	 * @param string $html
	 * @return string Block markup
	 */
	public function convertToBlocks( $html ) {
		if ( empty( trim( $html ) ) ) {
			return '';
		}

		$dom = new DOMDocument();
		@$dom->loadHTML(
			'<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return '<!-- wp:html -->' . $html . '<!-- /wp:html -->';
		}

		$blocks = '';
		foreach ( $body->childNodes as $node ) {
			$blocks .= $this->nodeToBlock( $dom, $node );
		}

		return trim( $blocks );
	}

	/**
	 * Convert a single DOM node to a Gutenberg block
	 *
	 * @param DOMDocument $dom
	 * @param DOMNode $node
	 * @return string
	 */
	private function nodeToBlock( $dom, $node ) {
		// Text node
		if ( $node->nodeType === XML_TEXT_NODE ) {
			$text = trim( $node->textContent );
			if ( empty( $text ) ) {
				return '';
			}
			return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->' . "\n";
		}

		if ( $node->nodeType !== XML_ELEMENT_NODE ) {
			return '';
		}

		$tag      = strtolower( $node->nodeName );
		$outer    = $dom->saveHTML( $node );
		$inner    = '';
		foreach ( $node->childNodes as $child ) {
			$inner .= $dom->saveHTML( $child );
		}

		switch ( $tag ) {
			case 'p':
				// Check if paragraph contains only an image
				$img_nodes = $node->getElementsByTagName( 'img' );
				if ( $img_nodes->length === 1 && trim( $node->textContent ) === '' ) {
					return $this->imgNodeToBlock( $img_nodes->item( 0 ) );
				}
				if ( empty( trim( $inner ) ) ) {
					return '';
				}
				return '<!-- wp:paragraph -->' . $outer . '<!-- /wp:paragraph -->' . "\n";

			case 'img':
				return $this->imgNodeToBlock( $node );

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );
				return '<!-- wp:heading {"level":' . $level . '} -->' . $outer . '<!-- /wp:heading -->' . "\n";

			case 'ul':
				return '<!-- wp:list -->' . $outer . '<!-- /wp:list -->' . "\n";

			case 'ol':
				return '<!-- wp:list {"ordered":true} -->' . $outer . '<!-- /wp:list -->' . "\n";

			case 'blockquote':
				return '<!-- wp:quote --><blockquote class="wp-block-quote">' . $inner . '</blockquote><!-- /wp:quote -->' . "\n";

			case 'br':
			case 'hr':
				return '';

			default:
				$trimmed = trim( $outer );
				if ( empty( $trimmed ) ) {
					return '';
				}
				return '<!-- wp:html -->' . $outer . '<!-- /wp:html -->' . "\n";
		}
	}

	/**
	 * Convert an img DOM node to a wp:image block
	 *
	 * @param DOMElement $img_node
	 * @return string
	 */
	private function imgNodeToBlock( $img_node ) {
		$src = $img_node->getAttribute( 'src' );
		$alt = $img_node->getAttribute( 'alt' );

		if ( empty( $src ) ) {
			return '';
		}

		return '<!-- wp:image -->' . "\n" .
			'<figure class="wp-block-image"><img src="' . esc_attr( $src ) . '" alt="' . esc_attr( $alt ) . '"/></figure>' . "\n" .
			'<!-- /wp:image -->' . "\n";
	}

	/**
	 * Create a WordPress post from post data
	 *
	 * @param array $postData
	 * @return string|false Post ID or false on failure
	 */
	public function createPost( $postData ) {
		// Extract entry number from URL for slug
		$slug = '';
		if ( preg_match( '/blog-entry-(\d+)\.html/', $postData['original_url'], $m ) ) {
			$slug = 'fc2-entry-' . $m[1];
		}

		$this->putTempFile( $postData['content'] );
		$temp_file = $this->tempDir . '/tmp.txt';

		$cmd = 'wp post create ' . escapeshellarg( $temp_file ) .
			' --post_title=' . escapeshellarg( $postData['title'] ) .
			' --post_type=post' .
			' --post_status=' . escapeshellarg( isset( $postData['status'] ) ? $postData['status'] : 'publish' ) .
			' --post_date=' . escapeshellarg( $postData['date'] );

		if ( $slug ) {
			$cmd .= ' --post_name=' . escapeshellarg( $slug );
		}

		if ( ! empty( $postData['excerpt'] ) ) {
			$cmd .= ' --post_excerpt=' . escapeshellarg( $postData['excerpt'] );
		}

		$cmd .= ' --porcelain 2>/dev/null';

		$output = [];
		exec( $cmd, $output );

		$postId = null;
		foreach ( $output as $line ) {
			if ( is_numeric( trim( $line ) ) ) {
				$postId = trim( $line );
				break;
			}
		}

		if ( ! $postId ) {
			return false;
		}

		// Set category
		if ( ! empty( $postData['category'] ) ) {
			exec( 'wp post term set ' . $postId . ' category ' . escapeshellarg( $postData['category'] ) . ' 2>/dev/null' );
		}

		// Set tags
		if ( ! empty( $postData['tags'] ) ) {
			$tag_args = implode( ' ', array_map( 'escapeshellarg', $postData['tags'] ) );
			exec( 'wp post term set ' . $postId . ' post_tag ' . $tag_args . ' 2>/dev/null' );
		}

		// Save original URL as custom field
		exec( 'wp post meta set ' . $postId . ' original_url ' . escapeshellarg( $postData['original_url'] ) . ' 2>/dev/null' );

		return $postId;
	}

	/**
	 * Extract FC2 image URLs from post content
	 * Matches src attributes containing blog-imgs-*.fc2.com
	 *
	 * @param string $postContent Raw HTML content
	 * @return array Array of image data [['src' => url], ...]
	 */
	public function getImagesUrl( $postContent ) {
		$imagesUrl = [];
		$seen      = [];

		$dom = new DOMDocument();
		@$dom->loadHTML(
			mb_convert_encoding( $postContent, 'HTML-ENTITIES', 'UTF-8' ),
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		$xpath  = new DOMXPath( $dom );
		$images = $xpath->query( '//img' );

		foreach ( $images as $img ) {
			$src = $img->getAttribute( 'src' );

			if ( empty( $src ) || strpos( $src, 'data:' ) === 0 ) {
				continue;
			}

			// Only import FC2 CDN images
			if ( strpos( $src, 'fc2.com' ) === false ) {
				continue;
			}

			if ( isset( $seen[ $src ] ) ) {
				continue;
			}

			$seen[ $src ] = true;
			$imagesUrl[]  = [ 'src' => $src ];
		}

		return $imagesUrl;
	}

	/**
	 * Import images to WordPress media library
	 *
	 * @param string $postId
	 * @param array $imagesUrl
	 * @return array Map of old_url => ['url' => new_url, 'id' => attachment_id]
	 */
	public function importImage( $postId, $imagesUrl ) {
		$imported = [];

		foreach ( $imagesUrl as $image ) {
			$src = isset( $image['src'] ) ? $image['src'] : '';
			if ( empty( $src ) ) {
				continue;
			}

			$output      = [];
			$return_code = 0;
			exec( 'wp media import ' . escapeshellarg( $src ) . ' --post_id=' . $postId . ' --porcelain 2>/dev/null', $output, $return_code );

			$attachment_id = null;
			foreach ( $output as $line ) {
				if ( is_numeric( trim( $line ) ) ) {
					$attachment_id = trim( $line );
					break;
				}
			}

			if ( ! $attachment_id ) {
				continue;
			}

			$new_url_output = [];
			exec( 'wp post get ' . $attachment_id . ' --field=guid 2>/dev/null', $new_url_output );

			$new_url = null;
			foreach ( $new_url_output as $line ) {
				$line = trim( $line );
				if ( ! empty( $line ) && strpos( $line, 'http' ) === 0 ) {
					$new_url = $line;
					break;
				}
			}

			if ( $new_url ) {
				$imported[ $src ] = [
					'url' => $new_url,
					'id'  => (int) $attachment_id,
				];
			}
		}

		return $imported;
	}

	/**
	 * Replace old image URLs with new WordPress media URLs in post content
	 * Also converts img tags to proper wp:image blocks
	 *
	 * @param string $postId
	 * @param array $importedImages [old_url => ['url' => new_url, 'id' => attachment_id]]
	 */
	public function searchReplace( $postId, $importedImages ) {
		if ( empty( $importedImages ) ) {
			return;
		}

		$content_output = [];
		exec( 'wp post get ' . $postId . ' --field=post_content 2>/dev/null', $content_output );

		// Filter out non-content lines (warnings etc.)
		$content_output = array_filter( $content_output, function ( $line ) {
			return strpos( $line, 'Failed loading' ) !== 0
				&& strpos( $line, 'Warning:' ) !== 0
				&& strpos( $line, 'Xdebug' ) !== 0;
		} );

		if ( empty( $content_output ) ) {
			return;
		}

		$current_content = implode( "\n", $content_output );
		$new_content     = $current_content;

		foreach ( $importedImages as $old_url => $image_data ) {
			$new_url       = $image_data['url'];
			$attachment_id = $image_data['id'];

			$replacement = '<!-- wp:image {"id":' . $attachment_id . '} -->' . "\n" .
				'<figure class="wp-block-image"><img src="' . $new_url . '" alt="" class="wp-image-' . $attachment_id . '"/></figure>' . "\n" .
				'<!-- /wp:image -->';

			// Replace wp:image block with old src
			$new_content = preg_replace(
				'/<!\-\- wp:image \-\->\s*<figure[^>]*><img[^>]*src=["\']' . preg_quote( $old_url, '/' ) . '["\'][^>]*><\/figure>\s*<!\-\- \/wp:image \-\->/is',
				$replacement,
				$new_content
			);

			// Also replace any remaining bare img tags or plain URLs
			$new_content = str_replace( $old_url, $new_url, $new_content );
		}

		if ( $current_content !== $new_content ) {
			$temp_file = $this->tempDir . '/content_' . $postId . '.txt';
			file_put_contents( $temp_file, $new_content, LOCK_EX );
			exec( 'wp post update ' . $postId . ' ' . escapeshellarg( $temp_file ) . ' 2>/dev/null' );
			@unlink( $temp_file );
		}
	}

	/**
	 * Create comments for a post
	 *
	 * @param string $postId
	 * @param array $commentsData
	 */
	public function createComments( $postId, $commentsData ) {
		foreach ( $commentsData as $comment ) {
			$author  = isset( $comment['author'] ) ? $comment['author'] : '';
			$date    = isset( $comment['date'] ) ? $comment['date'] : date( 'Y-m-d H:i:s' );
			$text    = isset( $comment['text'] ) ? $comment['text'] : '';

			if ( empty( $text ) ) {
				continue;
			}

			exec(
				'wp comment create' .
				' --comment_post_ID=' . $postId .
				' --comment_content=' . escapeshellarg( $text ) .
				' --comment_author=' . escapeshellarg( $author ) .
				' --comment_date=' . escapeshellarg( $date ) .
				' 2>/dev/null'
			);
		}
	}

	/**
	 * Write content to temp file
	 * @param string $body
	 */
	public function putTempFile( $body ) {
		file_put_contents( $this->tempDir . '/tmp.txt', $body, LOCK_EX );
	}
}
