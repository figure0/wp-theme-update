<?php
/*
Plugin Name: WP Theme Update
Plugin URI: https://github.com/rafacesar/wp-theme-update
Version: 1.0.4
*/

//Insert 'Custom Update URI' as a field in the top of your style.css

//Adding new header param to the stylesheet meta information
add_action( 'extra_theme_headers', 'custom_theme_updater_url_header' );
function custom_theme_updater_url_header( $headers ) {
    $headers['Custom Update URI'] = 'Custom Update URI';
    return $headers;
}


//This function requests and parse the url specified in the stylesheet header
add_filter('site_transient_update_themes', 'custom_theme_updater_searcher');
function custom_theme_updater_searcher($data) {
	
	//Getting installed themes
	$themes = wp_get_themes();
	
	//Looping through themes
	foreach ( (array) $themes as $theme_title => $theme ) {
		
		//URL defined in the style.css
		$update_url = $theme->get('Custom Update URI');
		
		//Theme stylesheet path
		$theme_id = $theme->stylesheet;
		
		//Stops if there isn't custom url to update
		if( !$update_url )
			continue;
		
		//Theme version
		$version = $theme->version;
		
		$function_path = WP_CONTENT_DIR . '/themes/' . $theme_id . '/update.php';
		
		if( file_exists($function_path) ) {
			include_once($function_path);
		}
		else
			error_log('update.php Not Found.');
		
		
		
		//If needed some adjust to the request URL (e.g. Login info)
		$url = apply_filters( 'custom_theme_updater_request_url', $update_url, $theme_id );
		
		//Getting data from cache
		// Note: WP transients fail if key is long than 45 characters
		$request_data = get_transient( md5( $update_url ) ); 
		
		//If there isn't any information in cache...
		if( empty( $request_data ) ) {
			
			//Requesting information about available versions
			$request = wp_remote_get($url, array('sslverify' => false, 'timeout' => 10));
			
			//Some error happened in the request
			if(is_wp_error($request)) {
				$data->request[$theme_id]['error'] = 'Error response from ' . $update_url;
				error_log('Error response from ' . $update_url . ' => ' . print_r($request, true));
				continue;
			}
			
			//Parsing the request information
			$request_data = apply_filters('custom_theme_updater_parse_request', $request, $theme_id, $update_url, $version);
			
			//If the requested information have not the right information
			if( !isset($request_data->version) || !isset($request_data->package) ) {
				error_log('Invalid Upgrade Object');
				continue;
			}
			
			//If the requested information have some error...
			if( isset( $request_data->error ) && !empty( $request_data->error ) ) {
				$data->request[$theme_id]['error'] = $request_data->error;
				error_log($request_data->error);
				continue;
			}
			
			//Caching the data
			set_transient( md5( $update_url ), $request_data, 60 );
		}
		
		//If there is a new version, ...
		if( version_compare( $version, $request_data->version, '<' ) ) {
			
			//... the new version data is appended to the response object
			$data->response[$theme_id] = array(
				'new_version'	=> $request_data->version,
				'url'			=> $update_url,
				'package'		=> $request_data->package
			);
			
		}
		
	}
	
	return $data;
}

//This is a copy of WP_Upgrader::download_package
//But here you can 'patch' the download url
add_filter('upgrader_pre_download', 'custom_theme_updater_download_package', 10, 3);
function custom_theme_updater_download_package( $false, $url, $instance ) {
	
	if ( ! preg_match('!^(http|https|ftp)://!i', $url) && file_exists($url) ) //Local file or remote?
		return $url; //must be a local file..

	if ( empty($url) )
		return new WP_Error('no_package', $instance->strings['no_package']);

	$instance->skin->feedback('downloading_package', $url);
	
	$tema = @$instance->skin->theme_info->template;
	
	if(!isset($tema))
		$tema = @$instance->skin->theme;
	
	//Here is my change
	$url = apply_filters('custom_theme_updater_download_url', $url, $tema);
	
	$download_file = download_url($url);

	if ( is_wp_error($download_file) )
		return new WP_Error('download_failed', $instance->strings['download_failed'], $download_file->get_error_message());

	return $download_file;
	
}