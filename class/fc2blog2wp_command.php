<?php
/**
 * WP-CLI command for FC2 BLOG to WordPress import
 *
 * @license GPL-2.0-or-later
 */

class FC2Blog2WP_Command {

	/**
	 * Import FC2 BLOG posts to WordPress
	 *
	 * ## OPTIONS
	 *
	 * <blog_url>
	 * : The FC2 blog URL (e.g., https://example.blog.fc2.com/)
	 *
	 * [--with-images]
	 * : Download and import images to the media library
	 *
	 * [--with-comments]
	 * : Import comments from posts
	 *
	 * [--reset]
	 * : Reset progress and start from the beginning
	 *
	 * ## EXAMPLES
	 *
	 *     # Import posts only
	 *     wp fc2 import https://example.blog.fc2.com/
	 *
	 *     # Import posts with images
	 *     wp fc2 import https://example.blog.fc2.com/ --with-images
	 *
	 *     # Import posts with images and comments
	 *     wp fc2 import https://example.blog.fc2.com/ --with-images --with-comments
	 *
	 *     # Reset progress and start from beginning
	 *     wp fc2 import https://example.blog.fc2.com/ --with-images --reset
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Associative arguments
	 */
	public function import( $args, $assoc_args ) {
		list( $blog_url ) = $args;

		$with_images   = isset( $assoc_args['with-images'] );
		$with_comments = isset( $assoc_args['with-comments'] );
		$reset         = isset( $assoc_args['reset'] );

		WP_CLI::log( 'Starting FC2 BLOG import...' );
		WP_CLI::log( 'Blog URL: ' . $blog_url );

		$fc2 = new FC2Blog2WP();

		// Initialize temporary directory
		if ( ! $fc2->initTempDir( $blog_url ) ) {
			WP_CLI::error( 'Invalid FC2 blog URL. Please provide a valid fc2.com URL.' );
		}

		WP_CLI::log( 'Temporary directory: ' . $fc2->getTempDir() );

		// Reset progress if requested
		if ( $reset ) {
			WP_CLI::log( 'Resetting progress...' );
			$fc2->resetProgress();
		}

		// Load progress
		$progress_data    = $fc2->loadProgress();
		$completed_count  = count( $progress_data['completed_posts'] );

		if ( $completed_count > 0 ) {
			WP_CLI::log( 'Resuming from previous session...' );
			WP_CLI::log( 'Already completed: ' . $completed_count . ' posts' );
		}

		// Validate URL
		$archive_index_url = $fc2->getArchiveIndexUrl( $blog_url );
		if ( ! $archive_index_url ) {
			WP_CLI::error( 'Invalid FC2 blog URL. Please provide a valid fc2.com URL.' );
		}

		// Get all monthly archive URLs
		WP_CLI::log( 'Getting monthly archive URLs...' );
		$archives_url = $fc2->getArchivesUrl( $archive_index_url );
		WP_CLI::log( 'Found ' . ( count( $archives_url ) - 1 ) . ' monthly archive pages.' );

		// Get all post URLs
		WP_CLI::log( 'Getting all post URLs...' );
		$posts_url = $fc2->getPostsUrl( $archives_url );
		WP_CLI::log( 'Found ' . count( $posts_url ) . ' posts.' );

		if ( empty( $posts_url ) ) {
			WP_CLI::error( 'No posts found. Please check the blog URL.' );
		}

		// Update total posts count
		$progress_data['total_posts'] = count( $posts_url );
		$fc2->saveProgress( $progress_data );

		// Filter out completed posts
		$remaining_posts = array_filter( $posts_url, function ( $url ) use ( $fc2, $progress_data ) {
			return ! $fc2->isPostCompleted( $url, $progress_data );
		} );

		if ( count( $remaining_posts ) === 0 ) {
			WP_CLI::success( 'All posts already imported!' );
			return;
		}

		WP_CLI::log( 'Remaining posts to import: ' . count( $remaining_posts ) );

		// Create progress bar
		$progress_bar = \WP_CLI\Utils\make_progress_bar( 'Importing posts', count( $remaining_posts ) );

		foreach ( $remaining_posts as $post_url ) {
			if ( $fc2->isPostCompleted( $post_url, $progress_data ) ) {
				$progress_bar->tick();
				continue;
			}

			// Fetch and parse post
			$post_data = $fc2->getPostData( $post_url );

			if ( ! $post_data ) {
				WP_CLI::warning( 'Failed to fetch post: ' . $post_url );
				$progress_bar->tick();
				continue;
			}

			// Create WordPress post
			$post_id = $fc2->createPost( $post_data );

			if ( $post_id ) {
				WP_CLI::log( 'Created post ID: ' . $post_id . ' - ' . $post_data['title'] );

				// Import images
				if ( $with_images ) {
					$images_url = $fc2->getImagesUrl( $post_data['raw_content'] );
					if ( ! empty( $images_url ) ) {
						$imported_images = $fc2->importImage( $post_id, $images_url );
						if ( ! empty( $imported_images ) ) {
							$fc2->searchReplace( $post_id, $imported_images );
						}
					}
				}

				// Import comments
				if ( $with_comments && ! empty( $post_data['comments'] ) ) {
					$fc2->createComments( $post_id, $post_data['comments'] );
				}

				// Mark as completed
				$fc2->markPostCompleted( $post_url, $progress_data );
			} else {
				WP_CLI::warning( 'Failed to create post: ' . $post_data['title'] );
			}

			$progress_bar->tick();
		}

		$progress_bar->finish();

		$final_progress = $fc2->loadProgress();
		WP_CLI::success(
			'Import completed! Total imported: ' .
			count( $final_progress['completed_posts'] ) . ' / ' .
			$final_progress['total_posts'] . ' posts.'
		);
	}
}
