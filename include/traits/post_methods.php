<?php

namespace threewp_broadcast\traits;

use threewp_broadcast\actions;
use threewp_broadcast\ajax;
use threewp_broadcast\post_bulk_actions;

/**
	@brief		Methods that have to do with posts and their broadcast data.
	@since		2014-10-19 15:00:44
**/
trait post_methods
{
	/**
		@brief		Adds post row actions
		@since		20131015
	**/
	public function add_post_row_actions_and_hooks()
	{
		if ( is_super_admin() || $this->role_at_least( $this->get_site_option( 'role_link' ) ) )
		{
			if (  $this->display_broadcast_columns )
			{
				$this->add_filter( 'manage_posts_columns' );
				$this->add_filter( 'manage_pages_columns', 'manage_posts_columns' );

				$this->add_action( 'manage_posts_custom_column', 10, 2 );
				$this->add_action( 'manage_pages_custom_column', 'manage_posts_custom_column', 10, 2 );
			}

			// Hook into the actions so that we can keep track of the broadcast data.
			$this->add_action( 'wp_trash_post', 'trash_post' );
			$this->add_action( 'trash_post' );
			$this->add_action( 'trash_page', 'trash_post' );

			$this->add_action( 'untrash_post' );
			$this->add_action( 'untrash_page', 'untrash_post' );

			$this->add_action( 'delete_post' );
			$this->add_action( 'delete_page', 'delete_post' );
		}
	}

	public function delete_post( $post_id)
	{
		$this->trash_untrash_delete_post( 'wp_delete_post', $post_id );
	}

	public function manage_posts_columns( $defaults )
	{
		$action = new actions\get_post_bulk_actions();
		$action->execute();
		echo $action->get_js();

		// Enqueue the popup js.
		wp_enqueue_script( 'magnific-popup', $this->paths[ 'url' ] . '/js/jquery.magnific-popup.min.js', '', $this->plugin_version );
		wp_enqueue_style( 'magnific-popup', $this->paths[ 'url' ] . '/css/magnific-popup.css', '', $this->plugin_version  );

		$defaults[ '3wp_broadcast' ] = '<span title="'.$this->_( 'Shows which blogs have posts linked to this one' ).'">'.$this->_( 'Broadcasted' ).'</span>';
		return $defaults;
	}

	public function manage_posts_custom_column( $column_name, $parent_post_id )
	{
		if ( $column_name != '3wp_broadcast' )
			return;

		$blog_id = get_current_blog_id();

		// Prep the bcd cache.
		$broadcast_data = $this->broadcast_data_cache()
			->expect_from_wp_query()
			->get_for( $blog_id, $parent_post_id );

		global $post;
		$action = new actions\manage_posts_custom_column();
		$action->post = $post;
		$action->parent_blog_id = $blog_id;
		$action->parent_post_id = $parent_post_id;
		$action->broadcast_data = $broadcast_data;
		$action->execute();

		echo $action->render();
	}

	/**
		@brief		Fill the action with all of the bulk actions we offer.
		@since		2014-10-31 14:11:10
	**/
	public function threewp_broadcast_get_post_bulk_actions( $action )
	{
		$ajax_action = 'broadcast_post_bulk_action';

		foreach( [
			'delete' => 'Delete broadcasts',
			'link_unlinked' => 'Link unlinked broadcasts',
			'restore' => 'Restore broadcasts',
			'trash' => 'Trash broadcasts',
			'unlink' => 'Unlink broadcasts',
		] as $subaction => $name )
		{
			$a = new post_bulk_actions\wp_ajax;
			$a->set_action( $ajax_action );
			$a->set_data( 'subaction', $subaction );
			$a->set_name( $name );
			$a->set_nonce( $ajax_action . $subaction );
			$action->add( $a );
		}
	}

	/**
		@brief		Handle the display of the custom column.
		@since		2014-04-18 08:30:19
	**/
	public function threewp_broadcast_manage_posts_custom_column( $action )
	{
		if ( $action->broadcast_data->get_linked_parent() !== false )
		{
			$parent = $action->broadcast_data->get_linked_parent();
			$parent_blog_id = $parent[ 'blog_id' ];
			switch_to_blog( $parent_blog_id );

			$html = $this->_(sprintf( '&#x21e6; %s', '<a href="' . get_bloginfo( 'url' ) . '/wp-admin/post.php?post=' .$parent[ 'post_id' ] . '&action=edit">' . get_bloginfo( 'name' ) . '</a>' ) );
			$action->html->put( 'linked_from', $html );
			restore_current_blog();
		}

		if ( $action->broadcast_data->has_linked_children() )
		{
			$children = $action->broadcast_data->get_linked_children();

			// Only display if there is something to display
			if ( count( $children ) > 0 )
			{
				// How many children to display?
				$max = $this->get_site_option( 'blogs_hide_overview' );
				if( count( $children ) > $max )
				{
					$html = sprintf( '<span class="broadcast_counter">&#x21e8; %s</span>', count( $children ) );
				}
				else
				{
					$links = [];
					foreach( $children as $child_blog_id => $child_post_id )
					{
						switch_to_blog( $child_blog_id );
						$blogname = get_bloginfo( 'blogname' );
						$links[ $blogname ] = sprintf( '<a href="%s">&#x21e8; %s</a>',
							get_bloginfo( 'url' ),
							$blogname
						);
						restore_current_blog();
					}
					ksort( $links );
					$html = implode( '<br/>', $links );
				}
				$action->html->put( 'broadcasted_to', $html );
			}

		}
		$action->finish();
	}

	/**
		@brief		Handle a post bulk action sent via Ajax.
		@since		2014-11-01 19:00:57
	**/
	public function wp_ajax_broadcast_post_bulk_action()
	{
		$r = (object)[];
		$r->json = new ajax\json();

		if ( ! isset( $_REQUEST[ 'nonce' ] ) )
			wp_die( 'No nonce.' );

		if ( ! isset( $_REQUEST[ 'subaction' ] ) )
			wp_die( 'No subaction.' );

		$nonce = $_REQUEST[ 'nonce' ];
		$r->subaction = $_REQUEST[ 'subaction' ];
		if ( ! wp_verify_nonce( $nonce, 'broadcast_post_bulk_action' . $r->subaction ) )
			wp_die( 'Invalid nonce.' );

		if ( ! isset( $_REQUEST[ 'post_ids' ] ) )
			wp_die( 'No post IDs' );

		$r->post_ids = $_REQUEST[ 'post_ids' ];
		$r->post_ids = explode( ',', $r->post_ids );

		$r->blog_id = get_current_blog_id();

		switch( $r->subaction )
		{
			// Delete all children
			case 'delete':
				foreach( $r->post_ids as $post_id )
				{
					$broadcast_data = $this->get_post_broadcast_data( $r->blog_id, $post_id );
					foreach( $broadcast_data->get_linked_children() as $child_blog_id => $child_post_id )
					{
						switch_to_blog( $child_blog_id );
						wp_delete_post( $child_post_id, true );
						$broadcast_data->remove_linked_child( $child_blog_id );
						restore_current_blog();
					}
					$broadcast_data = $this->set_post_broadcast_data( $r->blog_id, $post_id, $broadcast_data );
				}
			break;
			case 'link_unlinked':
				foreach( $r->post_ids as $post_id )
				{
					$post = get_post( $post_id );
					$broadcast_data = $this->get_post_broadcast_data( $r->blog_id, $post_id );
					// Get a list of blogs that this user can link to.
					$filter = new actions\get_user_writable_blogs( $this->user_id() );
					$blogs = $filter->execute()->blogs;
					foreach( $blogs as $blog )
					{
						if ( $blog->id == $r->blog_id )
							continue;

						if ( $broadcast_data->has_linked_child_on_this_blog( $blog->id ) )
							continue;

						$blog->switch_to();

						$args = array(
							'cache_results' => false,
							'name' => $post->post_name,
							'numberposts' => 2,
							'post_type'=> $post->post_type,
						);
						$posts = get_posts( $args );

						// An exact match was found.
						if ( count( $posts ) == 1 )
						{
							$unlinked = reset( $posts );
							$broadcast_data->add_linked_child( $blog->id, $unlinked->ID );

							// Add link info for the new child.
							$child_broadcast_data = $this->get_post_broadcast_data( $blog->id, $unlinked->ID );
							$child_broadcast_data->set_linked_parent( $r->blog_id, $post_id );
							$this->set_post_broadcast_data( $blog->id, $unlinked->ID, $child_broadcast_data );
						}

						$blog->switch_from();
					}
					$broadcast_data = $this->set_post_broadcast_data( $r->blog_id, $post_id, $broadcast_data );
				}
			break;
			case 'restore':
				foreach( $r->post_ids as $post_id )
				{
					$broadcast_data = $this->get_post_broadcast_data( $r->blog_id, $post_id );
					foreach( $broadcast_data->get_linked_children() as $child_blog_id => $child_post_id )
					{
						switch_to_blog( $child_blog_id );
						wp_publish_post( $child_post_id );
						restore_current_blog();
					}
				}
			break;
			case 'trash':
				foreach( $r->post_ids as $post_id )
				{
					$broadcast_data = $this->get_post_broadcast_data( $r->blog_id, $post_id );
					foreach( $broadcast_data->get_linked_children() as $child_blog_id => $child_post_id )
					{
						switch_to_blog( $child_blog_id );
						wp_trash_post( $child_post_id );
						restore_current_blog();
					}
				}
			break;
			case 'unlink':
				// TODO: Make this more flexible when we add parent / siblings.
				foreach( $r->post_ids as $post_id )
				{
					$broadcast_data = $this->get_post_broadcast_data( $r->blog_id, $post_id );
					$linked_children = $broadcast_data->get_linked_children();

					foreach( $linked_children as $linked_child_blog_id => $linked_child_post_id)
						$this->delete_post_broadcast_data( $linked_child_blog_id, $linked_child_post_id );

					$broadcast_data->remove_linked_children();
					$this->set_post_broadcast_data( $r->blog_id, $post_id, $broadcast_data );
				}
			break;
		}

		$r->json->output();
	}

	public function trash_post( $post_id)
	{
		$this->trash_untrash_delete_post( 'wp_trash_post', $post_id );
	}

	/**
	 * Issues a specific command on all the blogs that this post_id has linked children on.
	 * @param string $command Command to run.
	 * @param int $post_id Post with linked children
	 */
	private function trash_untrash_delete_post( $command, $post_id)
	{
		global $blog_id;
		$broadcast_data = $this->get_post_broadcast_data( $blog_id, $post_id );

		if ( $broadcast_data->has_linked_children() )
		{
			foreach( $broadcast_data->get_linked_children() as $childBlog=>$childPost)
			{
				if ( $command == 'wp_delete_post' )
				{
					// Delete the broadcast data of this child
					$this->delete_post_broadcast_data( $childBlog, $childPost );
				}
				switch_to_blog( $childBlog);
				$command( $childPost);
				restore_current_blog();
			}
		}

		if ( $command == 'wp_delete_post' )
		{
			global $blog_id;
			// Find out if this post has a parent.
			$linked_parent_broadcast_data = $this->get_post_broadcast_data( $blog_id, $post_id );
			$linked_parent_broadcast_data = $linked_parent_broadcast_data->get_linked_parent();
			if ( $linked_parent_broadcast_data !== false)
			{
				// Remove ourselves as a child.
				$parent_broadcast_data = $this->get_post_broadcast_data( $linked_parent_broadcast_data[ 'blog_id' ], $linked_parent_broadcast_data[ 'post_id' ] );
				$parent_broadcast_data->remove_linked_child( $blog_id );
				$this->set_post_broadcast_data( $linked_parent_broadcast_data[ 'blog_id' ], $linked_parent_broadcast_data[ 'post_id' ], $parent_broadcast_data );
			}

			$this->delete_post_broadcast_data( $blog_id, $post_id );
		}
	}

	public function untrash_post( $post_id)
	{
		$this->trash_untrash_delete_post( 'wp_untrash_post', $post_id );
	}

	/**
		Deletes a broadcasted post.
	**/
	public function user_delete()
	{
		// Nonce check
		global $blog_id;
		$nonce = $_GET[ '_wpnonce' ];
		$post_id = $_GET[ 'post' ];
		$child_blog_id = $_GET[ 'child' ];

		// Generate the nonce key to check against.
		$nonce_key = 'broadcast_delete';
		$nonce_key .= '_' . $child_blog_id;
		$nonce_key .= '_' . $post_id;

		if ( ! wp_verify_nonce( $nonce, $nonce_key) )
			die( __method__ . " security check failed." );

		$broadcast_data = $this->get_post_broadcast_data( $blog_id, $post_id );

		switch_to_blog( $child_blog_id );
		$broadcasted_post_id = $broadcast_data->get_linked_child_on_this_blog();

		if ( $broadcasted_post_id === null )
			wp_die( 'No broadcasted child post found on this blog!' );
		wp_delete_post( $broadcasted_post_id, true );
		$broadcast_data->remove_linked_child( $child_blog_id );

		restore_current_blog();

		$broadcast_data = $this->set_post_broadcast_data( $blog_id, $post_id, $broadcast_data );

		$message = $this->_( 'The child post has been deleted.' );

		echo $this->message( $message);
		echo sprintf( '<p><a href="%s">%s</a></p>',
			wp_get_referer(),
			$this->_( 'Back to post overview' )
		);
	}

	/**
		@brief		Restores a trashed post.
		@since		20131031
	**/
	public function user_restore()
	{
		// Nonce check
		global $blog_id;
		$nonce = $_GET[ '_wpnonce' ];
		$post_id = $_GET[ 'post' ];
		$child_blog_id = $_GET[ 'child' ];

		// Generate the nonce key to check against.
		$nonce_key = 'broadcast_restore';
		$nonce_key .= '_' . $child_blog_id;
		$nonce_key .= '_' . $post_id;

		if ( ! wp_verify_nonce( $nonce, $nonce_key) )
			die( __method__ . " security check failed." );

		$broadcast_data = $this->get_post_broadcast_data( $blog_id, $post_id );

		switch_to_blog( $child_blog_id );

		$child_post_id = $broadcast_data->get_linked_child_on_this_blog();
		wp_publish_post( $child_post_id );

		restore_current_blog();

		$message = $this->_( 'The child post has been restored.' );

		echo $this->message( $message);
		echo sprintf( '<p><a href="%s">%s</a></p>',
			wp_get_referer(),
			$this->_( 'Back to post overview' )
		);
	}

	/**
		Trashes a broadcasted post.
	**/
	public function user_trash()
	{
		// Nonce check
		global $blog_id;
		$nonce = $_GET[ '_wpnonce' ];
		$post_id = $_GET[ 'post' ];
		$child_blog_id = $_GET[ 'child' ];

		// Generate the nonce key to check against.
		$nonce_key = 'broadcast_trash';
		$nonce_key .= '_' . $child_blog_id;
		$nonce_key .= '_' . $post_id;

		if ( ! wp_verify_nonce( $nonce, $nonce_key) )
			die( __method__ . " security check failed." );

		$broadcast_data = $this->get_post_broadcast_data( $blog_id, $post_id );
		switch_to_blog( $child_blog_id );
		$broadcasted_post_id = $broadcast_data->get_linked_child_on_this_blog();
		wp_trash_post( $broadcasted_post_id );
		restore_current_blog();

		$message = $this->_( 'The broadcasted child post has been put in the trash.' );

		echo $this->message( $message);
		echo sprintf( '<p><a href="%s">%s</a></p>',
			wp_get_referer(),
			$this->_( 'Back to post overview' )
		);
	}

	public function user_unlink()
	{
		// Check that we're actually supposed to be removing the link for real.
		$nonce = $_GET[ '_wpnonce' ];
		$post_id = $_GET[ 'post' ];
		if ( isset( $_GET[ 'child' ] ) )
			$child_blog_id = $_GET[ 'child' ];

		// Generate the nonce key to check against.
		$nonce_key = 'broadcast_unlink';
		if ( isset( $child_blog_id) )
			$nonce_key .= '_' . $child_blog_id;
		else
			$nonce_key .= '_all';
		$nonce_key .= '_' . $post_id;

		if ( ! wp_verify_nonce( $nonce, $nonce_key) )
			die( __method__ . " security check failed." );

		global $blog_id;

		$broadcast_data = $this->get_post_broadcast_data( $blog_id, $post_id );
		$linked_children = $broadcast_data->get_linked_children();

		// Remove just one child?
		if ( isset( $child_blog_id ) )
		{
			$this->delete_post_broadcast_data( $child_blog_id, $linked_children[ $child_blog_id ] );
			$broadcast_data->remove_linked_child( $child_blog_id );
			$this->set_post_broadcast_data( $blog_id, $post_id, $broadcast_data );
			$message = $this->_( 'Link to child post has been removed.' );
		}
		else
		{
			$blogs_url = [];
			foreach( $linked_children as $linked_child_blog_id => $linked_child_post_id)
			{
				// And about the child blog
				switch_to_blog( $linked_child_blog_id );
				$blogs_url[] = '<a href="'.get_bloginfo( 'url' ).'">'.get_bloginfo( 'name' ).'</a>';
				restore_current_blog();
				$this->delete_post_broadcast_data( $linked_child_blog_id, $linked_child_post_id );
			}

			$broadcast_data = $this->get_post_broadcast_data( $blog_id, $post_id );
			$broadcast_data->remove_linked_children();
			$message = $this->_( 'All links to child posts have been removed!' );
		}

		$this->set_post_broadcast_data( $blog_id, $post_id, $broadcast_data );

		echo '
			'.$this->message( $message).'
			<p>
				<a href="'.wp_get_referer().'">Back to post overview</a>
			</p>
		';
	}
}