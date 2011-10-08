<?php
/**
 * Crowd Source Plugin
 *
 * Allows unauthenticated users to create posts.
 *
 **/

class CrowdSource extends Plugin
{
	function action_init()
	{
		$this->load_text_domain( 'crowdsource' );
		$this->add_template( 'block.crowd_source', dirname(__FILE__) . '/block.crowd_source.php' );
	}

	/**
	 * Add to the list of possible block types.
	 **/
	public function filter_block_list( $block_list )
	{
		$block_list[ 'crowd_source' ] = _t( 'Crowd Source Form', 'crowdsource' );
		return $block_list;
	}

	/**
	 * Output the content of the block, and nothing else.
	 **/
	public function action_block_content_crowd_source( $block, $theme )
	{
		$block->form = $this->get_form();
		return $block;
	}

	public function action_plugin_activation( $file )
	{
		// create the user and group at activation.
		self::create_crowd_group( "crowd" );
	}

	public static function create_crowd_group( $name = "crowd" )
	{
		// Create the new group (Should there be only one? Maybe one per block?)
		if ( ! UserGroup::exists( "{$name} group" ) and ! User::get( "{$name} user" ) ) {
			$group = UserGroup::create( array( 'name' => "{$name} group" ) );
		}
		else { // group or user already exists.
			// @TODO: let somebody know about it.
			return false;
		}
		if ( ! $group ) {
			return false;
		}
		$group->grant( 'post_entry', 'read' );
		$group->grant( 'post_page', 'read' );
		$group->grant( 'post_entry', 'create' );

		// Create one user for this group
		$crowd_user = User::create( array(
			'username' => "{$name} user"
			) );

		if ( ! $crowd_user ) {
			$group->delete();
			// @TODO: let somebody know about it.
			return false;
		}

		$group->add( $crowd_user->id );
		// remove new user from the Authenticated Users group, into which it is added automatically
		if ( UserGroup::exists( 'authenticated' ) ) {
			$authgroup = UserGroup::get( 'authenticated' );
//			if ( $authgroup->member( $crowd_user->id ) ) { 	/* I can't figure out why this warns about null for parameter 2. */
				$authgroup->remove( $crowd_user->id ); 	/* So, just assume the new user is a member of 'authenticated' */
//			}						/* and remove accordingly. */
		}
		return true;
	}

	/**
	 * Returns a form for creating a post
	 * @param string $context The context the form is being created in, most often 'admin'
	 * @return FormUI A form appropriate for creating and updating this post.
	 */
	public function get_form( $name = "crowd" /* username, content type, etc? */ )
	{
		$form = new FormUI( 'create-content' );

		// Create the Title field
		$form->append( 'text', 'title', 'null:null', _t( 'Title', 'crowdsource' ) /*, 'admincontrol_text'*/ );
		$form->title->tabindex = 1;

		// Create the Content field
		$form->append( 'textarea', 'content', 'null:null', _t( 'Content', 'crowdsource' )/*, 'admincontrol_textarea'*/ );
		$form->content->class[] = 'resizable';
		$form->content->tabindex = 2;

		// Create the Save button
		$form->append( 'submit', 'save', _t( 'Submit your thing', 'crowdsource' ) /*, 'admincontrol_submit'*/ );
		$form->save->tabindex = 4;

		// Add required hidden controls
		$form->append( 'hidden', 'content_type', 'null:null' );
		$form->content_type->id = 'content_type';
		$form->content_type->value = Post::type( 'entry' ); // should this be something else?
		$form->append( 'hidden', 'userid', 'null:null' );
		$form->userid->value = ( User::get( "{$name} user" ) ? User::get_id( "{$name} user" ) : die("now what?")); // @TODO: need to get this out of the block
		$form->userid->id = 'userid';

		$form->on_success( array( $this, 'crowd_form_publish_success' ) );


		// Return the form object
		return $form;
	}

	public function crowd_form_publish_success( FormUI $form )
	{
		// REFACTOR: don't do this here, it's duplicated in Post::create()
		$post = new Post();

		$post->pubdate = HabariDateTime::date_create();
		$status = Options::get( 'crowdsource__substatus', Post::status( 'published' ) );

		$postdata = array(
			'user_id' => $form->userid->value,
			'pubdate' => $post->pubdate,
			'status' => $status,
			'content_type' => $form->content_type->value,
		);

		// Don't try to add form values that have been removed by plugins
		$expected = array( 'title', 'content' );

		foreach ( $expected as $field ) {
			if ( isset( $form->$field ) ) {
				$postdata[$field] = $form->$field->value;
			}
		}
		$minor = false;

		// REFACTOR: consider using new Post( $postdata ) instead and call ->insert() manually
		$post = Post::create( $postdata );

		// REFACTOR: we should not have to update a post we just created, this should be moved to the post-update functionality above and only called if changes have been made
		// alternately, perhaps call ->update() or ->insert() as appropriate here, so things that apply to each operation (like comments_disabled) can still be included once outside the conditions above
		$post->update();

//		$permalink = ( $post->status != Post::status( 'published' ) ) ? $post->permalink . '?preview=1' : $post->permalink;
//		Session::notice( sprintf( _t( 'The post %1$s has been saved as %2$s.' ), sprintf( '<a href="%1$s">\'%2$s\'</a>', $permalink, Utils::htmlspecialchars( $post->title ) ), Post::status_name( $post->status ) ) );
//		Utils::redirect( URL::get( 'admin', 'page=publish&id=' . $post->id ) );
	}

	public function configure()
	{
		$ui = new FormUI( 'crowdsource' );
		// @TODO: do this the smarter way
		$substatus = $ui->append( 'select', 'substatus', 'crowdsource__substatus', _t( 'Status for submission entries:', 'crowdsource' ) );
		$substatus->options = array( Post::status( 'published' ) => _t( 'published', 'crowdsource' ), Post::status( 'draft' ) =>  _t( 'draft', 'crowdsource' ) );
		// @TODO: set the value

		$ui->append( 'submit', 'save', _t( 'Save', 'crowdsource' ) );
		$ui->on_success( array( $this, 'updated_config' ) );
		return $ui;
	}

	/**
	 * Give the user a session message to confirm options were saved.
	 **/
	public function updated_config( FormUI $ui )
	{
		Session::notice( _t( 'Options saved.', 'crowdsource' ) );
		$ui->save();
	}

}
?>
