<?php
/**
 * @package WPSEO\Internals
 */

if ( ! defined( 'WPSEO_VERSION' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
}


/**
 * Test whether force rewrite should be enabled or not.
 */
function wpseo_title_test() {
	$options = get_option( 'wpseo_titles' );

	$options['forcerewritetitle'] = false;
	$options['title_test']        = 1;
	update_option( 'wpseo_titles', $options );

	// Setting title_test to > 0 forces the plugin to output the title below through a filter in class-frontend.php.
	$expected_title = 'This is a Yoast Test Title';

	WPSEO_Utils::clear_cache();


	$args = array(
		'user-agent' => sprintf( 'WordPress/%1$s; %2$s - Yoast', $GLOBALS['wp_version'], get_site_url() ),
	);
	$resp = wp_remote_get( get_bloginfo( 'url' ), $args );

	if ( ( $resp && ! is_wp_error( $resp ) ) && ( 200 == $resp['response']['code'] && isset( $resp['body'] ) ) ) {
		$res = preg_match( '`<title>([^<]+)</title>`im', $resp['body'], $matches );

		if ( $res && strcmp( $matches[1], $expected_title ) !== 0 ) {
			$options['forcerewritetitle'] = true;

			$resp = wp_remote_get( get_bloginfo( 'url' ), $args );
			$res  = false;
			if ( ( $resp && ! is_wp_error( $resp ) ) && ( 200 == $resp['response']['code'] && isset( $resp['body'] ) ) ) {
				$res = preg_match( '`/<title>([^>]+)</title>`im', $resp['body'], $matches );
			}
		}

		if ( ! $res || $matches[1] != $expected_title ) {
			$options['forcerewritetitle'] = false;
		}
	}
	else {
		// If that dies, let's make sure the titles are correct and force the output.
		$options['forcerewritetitle'] = true;
	}

	$options['title_test'] = 0;
	update_option( 'wpseo_titles', $options );
}

// Commented out? add_filter( 'switch_theme', 'wpseo_title_test', 0 ); R.
/**
 * Test whether the active theme contains a <meta> description tag.
 *
 * @since 1.4.14 Moved from dashboard.php and adjusted - see changelog
 *
 * @return void
 */
function wpseo_description_test() {
	$options = get_option( 'wpseo' );

	// Reset any related options - dirty way of getting the default to make sure it works on activation.
	$options['theme_has_description']   = WPSEO_Option_Wpseo::$desc_defaults['theme_has_description'];
	$options['theme_description_found'] = WPSEO_Option_Wpseo::$desc_defaults['theme_description_found'];

	/**
	 * @internal Should this be reset too ? Best to do so as test is done on re-activate and switch_theme
	 * as well and new warning would be warranted then. Only might give irritation on theme upgrade.
	 */
	$options['ignore_meta_description_warning'] = WPSEO_Option_Wpseo::$desc_defaults['ignore_meta_description_warning'];

	$file = false;
	if ( file_exists( get_stylesheet_directory() . '/header.php' ) ) {
		// Theme or child theme.
		$file = get_stylesheet_directory() . '/header.php';
	}
	elseif ( file_exists( get_template_directory() . '/header.php' ) ) {
		// Parent theme in case of a child theme.
		$file = get_template_directory() . '/header.php';
	}

	if ( is_string( $file ) && $file !== '' ) {
		$header_file = file_get_contents( $file );
		$issue       = preg_match_all( '#<\s*meta\s*(name|content)\s*=\s*("|\')(.*)("|\')\s*(name|content)\s*=\s*("|\')(.*)("|\')(\s+)?/?>#i', $header_file, $matches, PREG_SET_ORDER );
		if ( $issue === false || $issue === 0 ) {
			$options['theme_has_description'] = false;
		}
		else {
			foreach ( $matches as $meta ) {
				if ( ( strtolower( $meta[1] ) == 'name' && strtolower( $meta[3] ) == 'description' ) || ( strtolower( $meta[5] ) == 'name' && strtolower( $meta[7] ) == 'description' ) ) {
					$options['theme_description_found']         = $meta[0];
					$options['ignore_meta_description_warning'] = false;
					break; // No need to run through the rest of the meta's.
				}
			}
			if ( $options['theme_description_found'] !== '' ) {
				$options['theme_has_description'] = true;
			}
			else {
				$options['theme_has_description'] = false;
			}
		}
	}
	update_option( 'wpseo', $options );
}

add_filter( 'after_switch_theme', 'wpseo_description_test', 0 );

if ( version_compare( $GLOBALS['wp_version'], '3.6.99', '>' ) ) {
	// Use the new and *sigh* adjusted action hook WP 3.7+.
	add_action( 'upgrader_process_complete', 'wpseo_upgrader_process_complete', 10, 2 );
}
elseif ( version_compare( $GLOBALS['wp_version'], '3.5.99', '>' ) ) {
	// Use the new action hook WP 3.6+.
	add_action( 'upgrader_process_complete', 'wpseo_upgrader_process_complete', 10, 3 );
}
else {
	// Abuse filters to do our action.
	add_filter( 'update_theme_complete_actions', 'wpseo_update_theme_complete_actions', 10, 2 );
	add_filter( 'update_bulk_theme_complete_actions', 'wpseo_update_theme_complete_actions', 10, 2 );
}


/**
 * Check if the current theme was updated and if so, test the updated theme
 * for the title and meta description tag
 *
 * @since    1.4.14
 *
 * @param WP_Upgrader $upgrader_object Upgrader object instance.
 * @param array       $context_array   Context data array.
 * @param mixed       $themes          Optional themes set.
 *
 * @return  void
 */
function wpseo_upgrader_process_complete( $upgrader_object, $context_array, $themes = null ) {
	$options = get_option( 'wpseo' );

	// Break if admin_notice already in place.
	if ( ( ( isset( $options['theme_has_description'] ) && $options['theme_has_description'] === true ) || $options['theme_description_found'] !== '' ) && $options['ignore_meta_description_warning'] !== true ) {
		return;
	}
	// Break if this is not a theme update, not interested in installs as after_switch_theme would still be called.
	if ( ! isset( $context_array['type'] ) || $context_array['type'] !== 'theme' || ! isset( $context_array['action'] ) || $context_array['action'] !== 'update' ) {
		return;
	}

	$theme = get_stylesheet();
	if ( ! isset( $themes ) ) {
		// WP 3.7+.
		$themes = array();
		if ( isset( $context_array['themes'] ) && $context_array['themes'] !== array() ) {
			$themes = $context_array['themes'];
		}
		elseif ( isset( $context_array['theme'] ) && $context_array['theme'] !== '' ) {
			$themes = $context_array['theme'];
		}
	}

	if ( ( isset( $context_array['bulk'] ) && $context_array['bulk'] === true ) && ( is_array( $themes ) && count( $themes ) > 0 ) ) {

		if ( in_array( $theme, $themes ) ) {
			// Commented out? wpseo_title_test(); R.
			wpseo_description_test();
		}
	}
	elseif ( is_string( $themes ) && $themes === $theme ) {
		// Commented out? wpseo_title_test(); R.
		wpseo_description_test();
	}

	return;
}

/**
 * Abuse a filter to check if the current theme was updated and if so, test the updated theme
 * for the title and meta description tag
 *
 * @since 1.4.14
 *
 * @param   array           $update_actions Updated actions set.
 * @param   WP_Theme|string $updated_theme  Theme object instance or stylesheet name.
 *
 * @return  array  $update_actions    Unchanged array
 */
function wpseo_update_theme_complete_actions( $update_actions, $updated_theme ) {
	$options = get_option( 'wpseo' );

	// Break if admin_notice already in place.
	if ( ( ( isset( $options['theme_has_description'] ) && $options['theme_has_description'] === true ) || $options['theme_description_found'] !== '' ) && $options['ignore_meta_description_warning'] !== true ) {
		return $update_actions;
	}

	$theme = get_stylesheet();
	if ( is_object( $updated_theme ) ) {
		/*
		Bulk update and $updated_theme only contains info on which theme was last in the list
		   of updated themes, so go & test
		*/

		// Commented out? wpseo_title_test(); R.
		wpseo_description_test();
	}
	elseif ( $updated_theme === $theme ) {
		/*
		Single theme update for the active theme
		*/

		// Commented out? wpseo_title_test(); R.
		wpseo_description_test();
	}

	return $update_actions;
}


/**
 * Adds an SEO admin bar menu with several options. If the current user is an admin he can also go straight to several settings menu's from here.
 */
function wpseo_admin_bar_menu() {
	// If the current user can't write posts, this is all of no use, so let's not output an admin menu.
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	global $wp_admin_bar, $post;

	$focuskw = '';
	$score   = '';
	$seo_url = get_admin_url( null, 'admin.php?page=wpseo_dashboard' );

	if ( ( is_singular() || ( is_admin() && in_array( $GLOBALS['pagenow'], array(
					'post.php',
					'post-new.php',
				), true ) ) ) && isset( $post ) && is_object( $post ) && apply_filters( 'wpseo_use_page_analysis', true ) === true
	) {
		$focuskw    = WPSEO_Meta::get_value( 'focuskw', $post->ID );
		$perc_score = WPSEO_Meta::get_value( 'linkdex', $post->ID );
		$txtscore   = WPSEO_Utils::translate_score( $perc_score );
		$title      = WPSEO_Utils::translate_score( $perc_score, false );
		$score      = '<div title="' . esc_attr( $title ) . '" class="' . esc_attr( 'wpseo-score-icon ' . $txtscore . ' ' . $perc_score ) .
		              ' adminbar-seo-score' . '"></div>';

		$seo_url = get_edit_post_link( $post->ID );
	}

	// Notification information.
	$notification_center     = Yoast_Notification_Center::get();
	$notification_count      = $notification_center->get_notification_count();
	$new_notifications       = $notification_center->get_new_notifications();
	$new_notifications_count = count( $new_notifications );

	$new_notifications_html = '';
	if ( $new_notifications_count ) {
		$notification = _n( 'You have a new issue concerning your SEO!', 'You have %d new issues concerning your SEO!', $new_notifications_count, 'wordpress-seo' );
		$new_notifications_html .= '<div class="yoast-issue-added">' . $notification . '</div>';

		// Show alerts page when there are alerts.
		$seo_url = get_admin_url( null, 'admin.php?page=wpseo_alerts' );
	}

	$counter = ( $notification_count ) ? sprintf( ' <div class="yoast-issue-counter">%d</div>', $notification_count ) : '';

	// Yoast Icon.
	$title = '<div class="wp-menu-image svg" style="width: 26px;background-image: url(&quot;data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz48IURPQ1RZUEUgc3ZnIFBVQkxJQyAiLS8vVzNDLy9EVEQgU1ZHIDEuMS8vRU4iICJodHRwOi8vd3d3LnczLm9yZy9HcmFwaGljcy9TVkcvMS4xL0RURC9zdmcxMS5kdGQiPjxzdmcgdmVyc2lvbj0iMS4xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbDpzcGFjZT0icHJlc2VydmUiIGZpbGw9IiM4Mjg3OGMiIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCA1MTIgNTEyIj48Zz48Zz48Zz48Zz48cGF0aCBzdHlsZT0iZmlsbDojODI4NzhjIiBkPSJNMjAzLjYsMzk1YzYuOC0xNy40LDYuOC0zNi42LDAtNTRsLTc5LjQtMjA0aDcwLjlsNDcuNywxNDkuNGw3NC44LTIwNy42SDExNi40Yy00MS44LDAtNzYsMzQuMi03Niw3NlYzNTdjMCw0MS44LDM0LjIsNzYsNzYsNzZIMTczQzE4OSw0MjQuMSwxOTcuNiw0MTAuMywyMDMuNiwzOTV6Ii8+PC9nPjxnPjxwYXRoIHN0eWxlPSJmaWxsOiM4Mjg3OGMiIGQ9Ik00NzEuNiwxNTQuOGMwLTQxLjgtMzQuMi03Ni03Ni03NmgtM0wyODUuNywzNjVjLTkuNiwyNi43LTE5LjQsNDkuMy0zMC4zLDY4aDIxNi4yVjE1NC44eiIvPjwvZz48L2c+PHBhdGggc3R5bGU9ImZpbGw6IzgyODc4YyIgc3Ryb2tlLXdpZHRoPSIyLjk3NCIgc3Ryb2tlLW1pdGVybGltaXQ9IjEwIiBkPSJNMzM4LDEuM2wtOTMuMywyNTkuMWwtNDIuMS0xMzEuOWgtODkuMWw4My44LDIxNS4yYzYsMTUuNSw2LDMyLjUsMCw0OGMtNy40LDE5LTE5LDM3LjMtNTMsNDEuOWwtNy4yLDF2NzZoOC4zYzgxLjcsMCwxMTguOS01Ny4yLDE0OS42LTE0Mi45TDQzMS42LDEuM0gzMzh6IE0yNzkuNCwzNjJjLTMyLjksOTItNjcuNiwxMjguNy0xMjUuNywxMzEuOHYtNDVjMzcuNS03LjUsNTEuMy0zMSw1OS4xLTUxLjFjNy41LTE5LjMsNy41LTQwLjcsMC02MGwtNzUtMTkyLjdoNTIuOGw1My4zLDE2Ni44bDEwNS45LTI5NGg1OC4xTDI3OS40LDM2MnoiLz48L2c+PC9nPjwvc3ZnPg==&quot;) !important;height: 30px;background-size: 20px;background-repeat: no-repeat;background-position: 0px 6px;float: left;"></div>';

	$wp_admin_bar->add_menu( array(
		'id'    => 'wpseo-menu',
		'title' => $title . $score . $counter . $new_notifications_html,
		'href'  => $seo_url,
	) );
	$wp_admin_bar->add_menu( array(
		'parent' => 'wpseo-menu',
		'id'     => 'wpseo-kwresearch',
		'title'  => __( 'Keyword Research', 'wordpress-seo' ),
		'#',
	) );
	$wp_admin_bar->add_menu( array(
		'parent' => 'wpseo-kwresearch',
		'id'     => 'wpseo-adwordsexternal',
		'title'  => __( 'AdWords External', 'wordpress-seo' ),
		'href'   => 'http://adwords.google.com/keywordplanner',
		'meta'   => array( 'target' => '_blank' ),
	) );
	$wp_admin_bar->add_menu( array(
		'parent' => 'wpseo-kwresearch',
		'id'     => 'wpseo-googleinsights',
		'title'  => __( 'Google Insights', 'wordpress-seo' ),
		'href'   => 'http://www.google.com/insights/search/#q=' . urlencode( $focuskw ) . '&cmpt=q',
		'meta'   => array( 'target' => '_blank' ),
	) );
	$wp_admin_bar->add_menu( array(
		'parent' => 'wpseo-kwresearch',
		'id'     => 'wpseo-wordtracker',
		'title'  => __( 'SEO Book', 'wordpress-seo' ),
		'href'   => 'http://tools.seobook.com/keyword-tools/seobook/?keyword=' . urlencode( $focuskw ),
		'meta'   => array( 'target' => '_blank' ),
	) );

	if ( ! is_admin() ) {
		$url = WPSEO_Frontend::get_instance()->canonical( false );

		if ( is_string( $url ) ) {
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-menu',
				'id'     => 'wpseo-analysis',
				'title'  => __( 'Analyze this page', 'wordpress-seo' ),
				'#',
			) );
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-analysis',
				'id'     => 'wpseo-inlinks-ose',
				'title'  => __( 'Check Inlinks (OSE)', 'wordpress-seo' ),
				'href'   => '//moz.com/researchtools/ose/links?site=' . urlencode( $url ),
				'meta'   => array( 'target' => '_blank' ),
			) );
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-analysis',
				'id'     => 'wpseo-kwdensity',
				'title'  => __( 'Check Keyword Density', 'wordpress-seo' ),
				'href'   => '//www.zippy.co.uk/keyworddensity/index.php?url=' . urlencode( $url ) . '&keyword=' . urlencode( $focuskw ),
				'meta'   => array( 'target' => '_blank' ),
			) );
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-analysis',
				'id'     => 'wpseo-cache',
				'title'  => __( 'Check Google Cache', 'wordpress-seo' ),
				'href'   => '//webcache.googleusercontent.com/search?strip=1&q=cache:' . urlencode( $url ),
				'meta'   => array( 'target' => '_blank' ),
			) );
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-analysis',
				'id'     => 'wpseo-header',
				'title'  => __( 'Check Headers', 'wordpress-seo' ),
				'href'   => '//quixapp.com/headers/?r=' . urlencode( $url ),
				'meta'   => array( 'target' => '_blank' ),
			) );
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-analysis',
				'id'     => 'wpseo-richsnippets',
				'title'  => __( 'Check Rich Snippets', 'wordpress-seo' ),
				'href'   => '//www.google.com/webmasters/tools/richsnippets?q=' . urlencode( $url ),
				'meta'   => array( 'target' => '_blank' ),
			) );
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-analysis',
				'id'     => 'wpseo-facebookdebug',
				'title'  => __( 'Facebook Debugger', 'wordpress-seo' ),
				'href'   => '//developers.facebook.com/tools/debug/og/object?q=' . urlencode( $url ),
				'meta'   => array( 'target' => '_blank' ),
			) );
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-analysis',
				'id'     => 'wpseo-pinterestvalidator',
				'title'  => __( 'Pinterest Rich Pins Validator', 'wordpress-seo' ),
				'href'   => '//developers.pinterest.com/rich_pins/validator/?link=' . urlencode( $url ),
				'meta'   => array( 'target' => '_blank' ),
			) );
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-analysis',
				'id'     => 'wpseo-htmlvalidation',
				'title'  => __( 'HTML Validator', 'wordpress-seo' ),
				'href'   => '//validator.w3.org/check?uri=' . urlencode( $url ),
				'meta'   => array( 'target' => '_blank' ),
			) );
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-analysis',
				'id'     => 'wpseo-cssvalidation',
				'title'  => __( 'CSS Validator', 'wordpress-seo' ),
				'href'   => '//jigsaw.w3.org/css-validator/validator?uri=' . urlencode( $url ),
				'meta'   => array( 'target' => '_blank' ),
			) );
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-analysis',
				'id'     => 'wpseo-pagespeed',
				'title'  => __( 'Google Page Speed Test', 'wordpress-seo' ),
				'href'   => '//developers.google.com/speed/pagespeed/insights/?url=' . urlencode( $url ),
				'meta'   => array( 'target' => '_blank' ),
			) );
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-analysis',
				'id'     => 'wpseo-modernie',
				'title'  => __( 'Modern IE Site Scan', 'wordpress-seo' ),
				'href'   => '//www.modern.ie/en-us/report#' . urlencode( $url ),
				'meta'   => array( 'target' => '_blank' ),
			) );
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-analysis',
				'id'     => 'wpseo-google-mobile-friendly',
				'title'  => __( 'Mobile-Friendly Test', 'wordpress-seo' ),
				'href'   => 'https://www.google.com/webmasters/tools/mobile-friendly/?url=' . urlencode( $url ),
				'meta'   => array( 'target' => '_blank' ),
			) );
		}
	}

	$admin_menu = current_user_can( 'manage_options' );

	if ( ! $admin_menu && is_multisite() ) {
		$options    = get_site_option( 'wpseo_ms' );
		$admin_menu = ( $options['access'] === 'superadmin' && is_super_admin() );
	}

	// @todo: add links to bulk title and bulk description edit pages.
	if ( $admin_menu ) {
		$wp_admin_bar->add_menu( array(
			'parent' => 'wpseo-menu',
			'id'     => 'wpseo-settings',
			'title'  => __( 'SEO Settings', 'wordpress-seo' ),
		) );
		$wp_admin_bar->add_menu( array(
			'parent' => 'wpseo-settings',
			'id'     => 'wpseo-general',
			'title'  => __( 'General', 'wordpress-seo' ),
			'href'   => admin_url( 'admin.php?page=wpseo_dashboard' ),
		) );
		$wp_admin_bar->add_menu( array(
			'parent' => 'wpseo-settings',
			'id'     => 'wpseo-titles',
			'title'  => __( 'Titles &amp; Metas', 'wordpress-seo' ),
			'href'   => admin_url( 'admin.php?page=wpseo_titles' ),
		) );
		$wp_admin_bar->add_menu( array(
			'parent' => 'wpseo-settings',
			'id'     => 'wpseo-social',
			'title'  => __( 'Social', 'wordpress-seo' ),
			'href'   => admin_url( 'admin.php?page=wpseo_social' ),
		) );
		$wp_admin_bar->add_menu( array(
			'parent' => 'wpseo-settings',
			'id'     => 'wpseo-xml',
			'title'  => __( 'XML Sitemaps', 'wordpress-seo' ),
			'href'   => admin_url( 'admin.php?page=wpseo_xml' ),
		) );
		$wp_admin_bar->add_menu( array(
			'parent' => 'wpseo-settings',
			'id'     => 'wpseo-wpseo-advanced',
			'title'  => __( 'Advanced', 'wordpress-seo' ),
			'href'   => admin_url( 'admin.php?page=wpseo_advanced' ),
		) );
		$wp_admin_bar->add_menu( array(
			'parent' => 'wpseo-settings',
			'id'     => 'wpseo-tools',
			'title'  => __( 'Tools', 'wordpress-seo' ),
			'href'   => admin_url( 'admin.php?page=wpseo_tools' ),
		) );
		$wp_admin_bar->add_menu( array(
			'parent' => 'wpseo-settings',
			'id'     => 'wpseo-search-console',
			'title'  => __( 'Search Console', 'wordpress-seo' ),
			'href'   => admin_url( 'admin.php?page=wpseo_search_console' ),
		) );
		$wp_admin_bar->add_menu( array(
			'parent' => 'wpseo-settings',
			'id'     => 'wpseo-licenses',
			'title'  => '<span style="color:#f18500">' . __( 'Extensions', 'wordpress-seo' ) . '</span>',
			'href'   => admin_url( 'admin.php?page=wpseo_licenses' ),
		) );

		if ( $notification_count ) {
			$wp_admin_bar->add_menu( array(
				'parent' => 'wpseo-menu',
				'id'     => 'wpseo-alerts',
				'title'  => __( 'SEO Alerts', 'wordpress-seo' ),
				'href'   => admin_url( 'admin.php?page=wpseo_alerts' ),
			) );
		}
	}
}

add_action( 'admin_bar_menu', 'wpseo_admin_bar_menu', 95 );

/**
 * Enqueue a tiny bit of CSS to show so the adminbar shows right.
 */
function wpseo_admin_bar_style() {

	$enqueue_style = false;

	// Single post in the frontend.
	if ( ! is_admin() && is_admin_bar_showing() ) {
		$enqueue_style = ( is_singular() || is_category() );
	}

	// Single post in the backend.
	if ( is_admin() ) {
		$screen = get_current_screen();

		// Post (every post_type) or category page.
		if ( 'post' === $screen->base || 'edit-tags' === $screen->base ) {
			$enqueue_style = true;
		}
	}

	$asset_manager = new WPSEO_Admin_Asset_Manager();
	$asset_manager->register_assets();
	$asset_manager->enqueue_style( 'adminbar' );
}

add_action( 'wp_enqueue_scripts', 'wpseo_admin_bar_style' );
add_action( 'admin_enqueue_scripts', 'wpseo_admin_bar_style' );

/**
 * Allows editing of the meta fields through weblog editors like Marsedit.
 *
 * @param array $allcaps Capabilities that must all be true to allow action.
 * @param array $cap     Array of capabilities to be checked, unused here.
 * @param array $args    List of arguments for the specific cap to be checked.
 *
 * @return array $allcaps
 */
function allow_custom_field_edits( $allcaps, $cap, $args ) {
	// $args[0] holds the capability.
	// $args[2] holds the post ID.
	// $args[3] holds the custom field.
	// Make sure the request is to edit or add a post meta (this is usually also the second value in $cap,
	// but this is safer to check).
	if ( in_array( $args[0], array( 'edit_post_meta', 'add_post_meta' ) ) ) {
		// Only allow editing rights for users who have the rights to edit this post and make sure
		// the meta value starts with _yoast_wpseo (WPSEO_Meta::$meta_prefix).
		if ( ( isset( $args[2] ) && current_user_can( 'edit_post', $args[2] ) ) && ( ( isset( $args[3] ) && $args[3] !== '' ) && strpos( $args[3], WPSEO_Meta::$meta_prefix ) === 0 ) ) {
			$allcaps[ $args[0] ] = true;
		}
	}

	return $allcaps;
}

add_filter( 'user_has_cap', 'allow_custom_field_edits', 0, 3 );


/********************** DEPRECATED FUNCTIONS **********************/

/**
 * Set the default settings.
 *
 * @deprecated 1.5.0
 * @deprecated use WPSEO_Options::initialize()
 * @see        WPSEO_Options::initialize()
 */
function wpseo_defaults() {
	_deprecated_function( __FUNCTION__, 'WPSEO 1.5.0', 'WPSEO_Options::initialize()' );
	WPSEO_Options::initialize();
}

/**
 * Translates a decimal analysis score into a textual one.
 *
 * @deprecated 1.5.6.1
 * @deprecated use WPSEO_Utils::translate_score()
 * @see        WPSEO_Utils::translate_score()
 *
 * @param int  $val       The decimal score to translate.
 * @param bool $css_value Whether to return the i18n translated score or the CSS class value.
 *
 * @return string
 */
function wpseo_translate_score( $val, $css_value = true ) {
	_deprecated_function( __FUNCTION__, 'WPSEO 1.5.6.1', 'WPSEO_Utils::translate_score()' );

	return WPSEO_Utils::translate_score();
}


/**
 * Check whether file editing is allowed for the .htaccess and robots.txt files
 *
 * @deprecated 1.5.6.1
 * @deprecated use WPSEO_Utils::allow_system_file_edit()
 * @see        WPSEO_Utils::allow_system_file_edit()
 *
 * @internal   current_user_can() checks internally whether a user is on wp-ms and adjusts accordingly.
 *
 * @return bool
 */
function wpseo_allow_system_file_edit() {
	_deprecated_function( __FUNCTION__, 'WPSEO 1.5.6.1', 'WPSEO_Utils::allow_system_file_edit()' );

	return WPSEO_Utils::allow_system_file_edit();
}
