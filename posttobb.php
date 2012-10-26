<?php
/**
 * @package Posts to bbPress
 * @version 0.1
 */
/*
Plugin Name: Posts to bbPress
Plugin URI: http://www.nosecreekweb.ca/
Description: Converts WordPress posts and comments to bbPress topics and replies.
Author: <a href="http://www.nosecreekweb.ca">Dustin Lammiman</a>
Version: 0.1
*/ 


if( is_admin() ) {
	add_action('admin_menu', 'posttobb_menu');
}

function posttobb_menu() {
	add_management_page('Posts -> bbPress', 'Posts -> bbPress', 'import', 'posttobb-settings', 'posttobb_settings');
}

function posttobb_settings() {
	if (!current_user_can('import'))  {
		wp_die( __('You do not have sufficient permissions to access this page.') );
	}
	?>
	<div class="wrap">
		<h2>Posts -> bbPress</h2>
	<?php
	if(!isset($_POST['to_forum'])) { ?>
			<form method="post" action="">
				<table class="form-table">
					<tbody>
						<tr valign="top">
							<th scope="row">
								Delete?
							</th>
							<td>
								<label for="delete-posts">
									<input name="delete_posts" type="checkbox" id="delete_posts" checked>
									Delete Posts After Converting to Topics?
								</label>
								<br>
								<label for="delete-posts">
									<input name="delete_comments" type="checkbox" id="delete_comments" checked>
									Delete Comments After Converting to Replies?
								</label>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label for="to_forum">Import to Forum</label>
							</th>
							<td>
								<select name="to_forum"  id="to_forum" class="postform">
									<?php
									global $wpdb, $wp_taxonomies, $wp_rewrite;
									$q = 'numberposts=-1&post_status=any&post_type=forum';
									$forums = get_posts($q);
									
									foreach( $forums as $forum ){
										echo '<option class="level-0" value="';
										echo $forum->ID;
										echo '">';
										echo $forum->post_name;
										echo '</option>';
									}
									?>
								</select>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								Map Comment Author to WP-Users
							</th>
							<td>
								
									<?php
									$comments = get_comments( );
									
									foreach($comments as $comment){
										$author = $comment->comment_author;
										$authors[$author] = $author;
									}
									
									$i = 0;
									foreach( $authors as $author ){
										$i++;
										if(!is_numeric($authors[$author])) {
											$id = 'user_' . $i;
											wp_dropdown_users(array('id'=>$id,'name'=>$id));
											echo '<label for="' . $id . '">';
											echo $authors[$author];
											echo '</label><br>';
										}
									}
									?>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
				
				<p>
					<br>
					<strong>Warning! Remember to Backup your Database first! This plugin has the potential to cause data-loss or prevent WordPress from working properly.</strong>
				</p>
				
				<p class="submit">
					<input type="submit" class="button-primary" value="<?php _e('Import!') ?>" />
				</p>
			</form>
		</div>
		<?php
		
	} else { //page has been submitted
		
		if( $_POST['delete_posts'] == 'on' ) {
			$delete_posts = true;
		} else {
			$delete_posts = false;
		}
		if( $_POST['delete_comments'] == 'on' ) {
			$delete_comments = true;
		} else {
			$delete_comments = false;
		}
		
		$comments = get_comments( );
		
		foreach($comments as $comment){
			$author = $comment->comment_author;
			$authors[$author] = $author;
		}
		
		$i = 0;
		foreach( $authors as $author ){
			$i++;
			if(!is_numeric($authors[$author])) {
				$id = 'user_' . $i;
				$send_authors[$author] = $_POST[$id];
			}
		}
		
		posts_to_bbpress($_POST['to_forum'], $send_authors, $delete_posts, $delete_comments);
		?>
	
	Posts Converted. To complete the process, please go to the <a href="tools.php?page=bbp-repair">Repair Forums</a> page, check all boxes, and choose Repair Items.
	
	<?php
	}
}

function posts_to_bbpress($to_forum, $authors, $delete_posts=false, $delete_comments=false) {
	
	/*** POSTS -> TOPICS ***/
	
	//query for the posts
	global $wpdb, $wp_taxonomies, $wp_rewrite;
	$q = 'numberposts=-1&post_status=any&post_type=post';
	$items = get_posts($q);
	
	if ($delete_posts == false) { //create a new entry
		foreach ($items as $item) { //for each post
			//Recreate the post into the database as a topic
			
			$my_post = array( //create the new topic
				 'post_title' => $item->post_title,
				 'post_content' => $item->post_content,
				 'post_status' => $item->post_status,
				 'post_author' => $item->post_author,
				 'post_type' => 'topic',
				 'post_name' => $item->post_name,
				 'post_date' => $item->post_date,
				 'post_date_gmt' => $item->post_date_gmt,
				 'post_parent' => $to_forum
			);      
			wp_insert_post( $my_post ); //insert the topic
		}
	
	} else { //update the entry
		foreach ($items as $item) {
			// Update the post into the database
			$update['ID'] = $item->ID;
			$update['post_type'] = 'topic';
			$update['post_parent'] = $to_forum;
			$converted = wp_update_post( $update );
		}
	}
	
	
	/*** COMMENTS -> REPLIES ***/
	
	//query for the comments
	$comments = get_comments();
	
	foreach($comments as $comment) { //for each comment
		//Recreate the comment into the database as a topic
		
		$post = get_post($comment->comment_post_ID); //get the parent post
		
		$title="Reply To: " . $post->post_title; //The proper title for a topic reply
		
		//Asign the proper comment author
		$author = $comment->comment_author;
		$post_author = 0;
		
		if($comment->user_id != 0) {
			$post_author = $comment->user_id;
		} else {
			if(is_numeric($author)) {
				$post_author = intval($author);
			}
			
			if(isset($authors[$author])){
				$post_author = $authors[$author];
			}
		}
		
		$my_post = array(
			 'post_title' => $title,
			 'post_content' => $comment->comment_content,
			 'post_status' => 'publish',
			 'post_author' => $post_author,
			 'post_type' => 'reply',
			 'post_name' => 'reply-to-' . $post->post_name,
			 'post_date' => $comment->comment_date,
			 'post_date_gmt' => $comment->comment_date_gmt,
			 'post_parent' => $post->ID
		);      
		wp_insert_post( $my_post );
	   
		if ($delete_posts == true) {
			wp_delete_comment( $comment->comment_ID ); //delete the comment
		}
	}
	
	
	/*** Remove rewrite rules and then recreate rewrite rules. ***/
	
	$wp_rewrite->flush_rules();
	
}
?>
