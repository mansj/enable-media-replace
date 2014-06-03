<?php
if (!current_user_can('upload_files'))
	wp_die(__('You do not have permission to upload files.'));

// Define DB table names
global $wpdb;
$table_name = $wpdb->prefix . "posts";
$postmeta_table_name = $wpdb->prefix . "postmeta";

function emr_delete_current_files($current_file) {
	// Delete old file

	// Find path of current file
	$current_path = substr($current_file, 0, (strrpos($current_file, "/")));
	
	// Check if old file exists first
	if (file_exists($current_file)) {
		// Now check for correct file permissions for old file
		clearstatcache();
		if (is_writable($current_file)) {
			// Everything OK; delete the file
			unlink($current_file);
		}
		else {
			// File exists, but has wrong permissions. Let the user know.
			printf(__('The file %1$s can not be deleted by the web server, most likely because the permissions on the file are wrong.', "enable-media-replace"), $current_file);
			exit;	
		}
	}
	
	// Delete old resized versions if this was an image
	$suffix = substr($current_file, (strlen($current_file)-4));
	$prefix = substr($current_file, 0, (strlen($current_file)-4));
	$imgAr = array(".png", ".gif", ".jpg");
	if (in_array($suffix, $imgAr)) { 
		// It's a png/gif/jpg based on file name
		// Get thumbnail filenames from metadata
		$metadata = wp_get_attachment_metadata($_POST["ID"]);
		if (is_array($metadata)) { // Added fix for error messages when there is no metadata (but WHY would there not be? I don't know…)
			foreach($metadata["sizes"] AS $thissize) {
				// Get all filenames and do an unlink() on each one;
				$thisfile = $thissize["file"];
				// Create array with all old sizes for replacing in posts later
				$oldfilesAr[] = $thisfile;
				// Look for files and delete them
				if (strlen($thisfile)) {
					$thisfile = $current_path . "/" . $thissize["file"];
					if (file_exists($thisfile)) {
						unlink($thisfile);
					}
				}
			}
		}
		// Old (brutal) method, left here for now
		//$mask = $prefix . "-*x*" . $suffix;
		//array_map( "unlink", glob( $mask ) );
	}

}

$current_file = get_post( absint( $_POST['ID'] ) );
$current_filename = $current_file->guid;
$current_filetype = $current_file->post_mime_type;

// Massage a bunch of vars
$current_guid = $current_filename;
$current_filename = substr($current_filename, (strrpos($current_filename, "/") + 1));

$current_file = get_attached_file((int) $_POST["ID"], true);
$current_path = substr($current_file, 0, (strrpos($current_file, "/")));
$current_file = str_replace("//", "/", $current_file);
$current_filename = basename($current_file);

$replace_type = $_POST["replace_type"];
// We have two types: replace / replace_and_search

if (is_uploaded_file($_FILES["userfile"]["tmp_name"])) {

	// New method for validating that the uploaded file is allowed, using WP:s internal wp_check_filetype_and_ext() function.
	$filedata = wp_check_filetype_and_ext($_FILES["userfile"]["tmp_name"], $_FILES["userfile"]["name"]);
	
	if ($filedata["ext"] == "") {
		echo __("File type does not meet security guidelines. Try another.");
		exit;
	}
	
	$new_filename = $_FILES["userfile"]["name"];
	$new_filesize = $_FILES["userfile"]["size"];
	$new_filetype = $filedata["type"];
	
	if ($replace_type == "replace") {
		// Drop-in replace and we don't even care if you uploaded something that is the wrong file-type.
		// That's your own fault, because we warned you!

		emr_delete_current_files($current_file);

		// Move new file to old location/name
		move_uploaded_file($_FILES["userfile"]["tmp_name"], $current_file);

		// Chmod new file to 644
		chmod($current_file, 0644);

		// Make thumb and/or update metadata
		wp_update_attachment_metadata( (int) $_POST["ID"], wp_generate_attachment_metadata( (int) $_POST["ID"], $current_file ) );

		// Trigger possible updates on CDN and other plugins 
		update_attached_file( (int) $_POST["ID"], $current_file);
	}

	else {
		// Replace file, replace file name, update meta data, replace links pointing to old file name

		emr_delete_current_files($current_file);

		// Massage new filename to adhere to WordPress standards
		$new_filename= wp_unique_filename( $current_path, $new_filename );

		// Move new file to old location, new name
		$new_file = $current_path . "/" . $new_filename;
		move_uploaded_file($_FILES["userfile"]["tmp_name"], $new_file);

		// Chmod new file to 644
		chmod($new_file, 0644);

		$new_filetitle = preg_replace('/\.[^.]+$/', '', basename($new_file));
		$new_filetitle = apply_filters( 'enable_media_replace_title', $new_filetitle ); // Thanks Jonas Lundman (http://wordpress.org/support/topic/add-filter-hook-suggestion-to)
		$new_guid = str_replace($current_filename, $new_filename, $current_guid);

		// Update database file name
		wp_update_post( array(
			'ID' => absint( $_POST['ID'] ),
			'post_type' => 'attachment',
			'post_title' => $new_filetitle,
			'post_name' => $new_filetitle,
			'guid' => $new_guid,
			'post_mime_type' => $new_filetype,
		));

		// Update the postmeta file name

		// Get old postmeta _wp_attached_file
		$old_meta_name = get_post_meta( absint( $_POST['id'] ), '_wp_attached_file', true );

		// Make new postmeta _wp_attached_file
		$new_meta_name = str_replace($current_filename, $new_filename, $old_meta_name);
		update_post_meta( absint( $_POST['ID'] ), '_wp_attached_file', $new_meta_name );

		// Make thumb and/or update metadata
		wp_update_attachment_metadata( (int) $_POST["ID"], wp_generate_attachment_metadata( (int) $_POST["ID"], $new_file) );

		// Search-and-replace filename in post database
		$sql = $wpdb->prepare(
			"SELECT ID, post_content FROM $table_name WHERE post_content LIKE %s;",
			'%' . $current_guid . '%'
		);

		$rs = $wpdb->get_results($sql, ARRAY_A);
		
		foreach($rs AS $rows) {

			// replace old guid with new guid
			$post_content = $rows["post_content"];
			$post_content = addslashes(str_replace($current_guid, $new_guid, $post_content));

			wp_update_post( array( 'ID' => absint( $rows['ID'] ), 'post_content' => $post_content ) );
		}
		
		// Trigger possible updates on CDN and other plugins 
		update_attached_file( (int) $_POST["ID"], $new_file);

	}

	$returnurl = get_bloginfo("wpurl") . "/wp-admin/post.php?post={$_POST["ID"]}&action=edit&message=1";
	
	// Execute hook actions - thanks rubious for the suggestion!
	if (isset($new_guid)) { do_action("enable-media-replace-upload-done", ($new_guid ? $new_guid : $current_guid)); }
	
} else {
	//TODO Better error handling when no file is selected.
	//For now just go back to media management
	$returnurl = get_bloginfo("wpurl") . "/wp-admin/upload.php";
}

if (FORCE_SSL_ADMIN) {
	$returnurl = str_replace("http:", "https:", $returnurl);
}

//save redirection
wp_redirect($returnurl);
?>	