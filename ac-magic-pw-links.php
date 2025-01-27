<?php
/*
Plugin Name: One Click Magic Links for Native WP Password Protected Pages
Description: Generates and manages one-click links that auto-set hashed WP password cookies, logs usage, and includes token names, copy-to-clipboard, and revoked tokens table. Now includes unified, filterable usage logs below the main table.
Version: 1
Author: Adam Chiaravalle @ ACWebDev, LLC.
*/

if ( ! defined('ABSPATH') ) {
	exit;
}

class One_Click_Magic_Links_Hashed_Named {

	public function __construct() {
		add_action('admin_menu', array($this, 'ocml_add_admin_menu'));
		add_action('template_redirect', array($this, 'ocml_handle_magic_token'), 1);
	}

	/**
	 * Add admin page
	 */
	public function ocml_add_admin_menu() {
		add_menu_page(
			'AC - Magic Links',
			'AC - Magic Links',
			'manage_options',
			'ocml_magic_links_hashed_named',
			array($this, 'ocml_admin_page'),
			'dashicons-admin-links',
			90
		);
	}

	/**
	 * Render the admin page
	 */
	public function ocml_admin_page() {
		// Very verbose console log
		echo "<script>console.log('One-Click Magic Links Admin Page: Loaded with super verbosity.');</script>";

		// Handle create token
		if ( isset($_POST['ocml_create_token']) && isset($_POST['ocml_post_id']) && current_user_can('manage_options') ) {
			$post_id        = intval($_POST['ocml_post_id']);
			$token_name     = isset($_POST['ocml_token_name']) ? sanitize_text_field($_POST['ocml_token_name']) : '';
			$this->ocml_create_token($post_id, $token_name);
			echo '<div class="notice notice-success"><p>New magic link token created!</p></div>';
		}

		// Handle revoke token
		if ( isset($_POST['ocml_revoke_token']) && isset($_POST['ocml_post_id']) && isset($_POST['ocml_token_value']) && current_user_can('manage_options') ) {
			$post_id = intval($_POST['ocml_post_id']);
			$token   = sanitize_text_field($_POST['ocml_token_value']);
			$this->ocml_revoke_token($post_id, $token);
			echo '<div class="notice notice-success"><p>Token revoked!</p></div>';
		}

		// Retrieve all password protected pages
		$args = array(
			'post_type'      => 'page',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'has_password'   => true,
		);
		$protected_pages = get_posts($args);

		echo '<div class="wrap">';
		echo '<h1>One-Click Magic Links (Named + Logging + Revoked)</h1>';
		echo '<p>Generate links that set hashed password cookies, name them, revoke them, and view usage logs below.</p>';

		if ( ! empty($protected_pages) ) {
			echo '<table class="widefat fixed">';
			echo '<thead><tr><th>Page</th><th>Active Tokens (and Logs)</th><th>Create Token</th></tr></thead>';
			echo '<tbody>';

			foreach ( $protected_pages as $page ) {
				$tokens = get_post_meta($page->ID, '_ocml_tokens_hashed_named', true);
				if ( ! is_array($tokens) ) {
					$tokens = array();
				}

				// Show page info
				echo '<tr>';
				echo '<td>';
				echo '<strong>' . esc_html($page->post_title) . '</strong><br>';
				echo 'ID: ' . $page->ID . '<br>';
				echo 'Permalink: <a href="' . get_permalink($page->ID) . '" target="_blank">' . get_permalink($page->ID) . '</a>';
				echo '</td>';

				// Active tokens & logs
				echo '<td>';
				$active_tokens = array_filter($tokens, function($t){ return !empty($t['active']); });

				if ( ! empty($active_tokens) ) {
					foreach ( $active_tokens as $tk ) {
						$token_value = $tk['token'];
						$token_name  = isset($tk['name']) ? $tk['name'] : '';

						// Build the direct link with slug
						$magic_link = esc_url( add_query_arg(
							array( 'magic_token' => $token_value ),
							get_permalink($page->ID)
						) );

						// Logs for this token
						$usage_logs = $this->ocml_get_token_usage_logs($page->ID, $token_value);

						echo '<div style="margin-bottom:10px; padding:8px; border:1px solid #ccc;">';
						echo '<strong>Token Name:</strong> ' . esc_html($token_name) . '<br>';
						echo '<strong>Token Value:</strong> <code>' . esc_html($token_value) . '</code><br>';
						echo '<label><strong>URL:</strong></label> <input type="text" style="width:70%;" readonly value="' . $magic_link . '" id="copy_field_' . esc_attr($token_value) . '"> ';
						echo '<button type="button" class="button" onclick="ocmlCopyLink(\'' . esc_js($token_value) . '\')">Copy</button>';

						// Revoke form
						echo '<form method="post" style="display:inline-block; margin-left:10px;">';
						echo '<input type="hidden" name="ocml_post_id" value="' . esc_attr($page->ID) . '">';
						echo '<input type="hidden" name="ocml_token_value" value="' . esc_attr($token_value) . '">';
						submit_button('Revoke', 'small', 'ocml_revoke_token', false);
						echo '</form>';

						// Display usage logs
						if ( ! empty( $usage_logs ) ) {
							echo '<div style="margin-top:10px;">';
							echo '<strong>Usage Logs:</strong>';
							echo '<table style="width:100%; border:1px solid #ccc; margin-top:5px;">';
							echo '<tr><th>Date/Time</th><th>IP</th><th>Location</th></tr>';
							foreach ( $usage_logs as $log ) {
								echo '<tr>';
								echo '<td>' . esc_html($log['datetime']) . '</td>';
								echo '<td>' . esc_html($log['ip']) . '</td>';
								echo '<td>' . esc_html($log['location']) . '</td>';
								echo '</tr>';
							}
							echo '</table>';
							echo '</div>';
						} else {
							echo '<p style="margin-top:10px;"><em>No usage logs yet.</em></p>';
						}

						echo '</div>';
					}
				} else {
					echo '<em>No active tokens.</em>';
				}
				echo '</td>';

				// Create new token form
				echo '<td>';
				echo '<form method="post">';
				echo '<p><input type="text" name="ocml_token_name" placeholder="Optional Token Name" style="width:95%;"></p>';
				echo '<input type="hidden" name="ocml_post_id" value="' . esc_attr($page->ID) . '">';
				submit_button('Create Token', 'small', 'ocml_create_token', false);
				echo '</form>';
				echo '</td>';

				echo '</tr>';

				// Now a sub-row for revoked tokens
				$revoked_tokens = array_filter($tokens, function($t){ return empty($t['active']); });
				if ( ! empty($revoked_tokens) ) {
					echo '<tr>';
					echo '<td colspan="3" style="background:#f9f9f9;">';
					echo '<strong>Revoked Tokens:</strong>';
					echo '<ul>';
					foreach ( $revoked_tokens as $rt ) {
						$r_name  = isset($rt['name']) ? $rt['name'] : '';
						$r_token = isset($rt['token']) ? $rt['token'] : '';
						echo '<li>';
						echo '<strong>Name:</strong> ' . esc_html($r_name) . ' &mdash; <code>' . esc_html($r_token) . '</code>';
						echo '</li>';
					}
					echo '</ul>';
					echo '</td>';
					echo '</tr>';
				}
			}
			echo '</tbody></table>';
		} else {
			echo '<p><em>No password protected pages found.</em></p>';
		}

		// Show the unified usage logs table (filterable with dropdowns)
		$this->ocml_render_usage_logs_table();

		// Add copy to clipboard JS
		?>
		<script>
		function ocmlCopyLink(tokenValue) {
			var copyField = document.getElementById('copy_field_' + tokenValue);
			copyField.select();
			copyField.setSelectionRange(0, 99999); /* for mobile devices */
			document.execCommand("copy");

			console.log('Copied to clipboard: ' + copyField.value);
			alert('Link copied to clipboard!');
		}
		</script>
		<?php

		echo '</div>';
	}

	/**
	 * Renders a unified, filterable usage logs table below the main table
	 */
	private function ocml_render_usage_logs_table() {
		// Very verbose console log
		echo "<script>console.log('Rendering unified usage logs table with intense verbosity.');</script>";

		// Gather all logs
		$all_logs = $this->ocml_get_all_usage_logs();

		// Build sets of unique values for dropdowns
		$page_ids     = array();
		$page_titles  = array();
		$tokens       = array();
		$token_names  = array();
		$ips          = array();
		$locations    = array();
		$date_values  = array(); // We'll store date portion only (YYYY-mm-dd)

		foreach ( $all_logs as $log ) {
			$page_ids[]    = $log['page_id'];
			$page_titles[] = $log['page_title'];
			$tokens[]      = $log['token'];
			$token_names[] = $log['token_name'];
			$ips[]         = $log['ip'];
			$locations[]   = $log['location'];

			// Extract date from datetime
			if ( isset($log['datetime']) ) {
				$date_part = substr($log['datetime'], 0, 10); // YYYY-mm-dd
				$date_values[] = $date_part;
			}
		}

		// Unique and sorted
		$page_ids     = array_unique($page_ids);
		$page_titles  = array_unique($page_titles);
		$tokens       = array_unique($tokens);
		$token_names  = array_unique($token_names);
		$ips          = array_unique($ips);
		$locations    = array_unique($locations);
		$date_values  = array_unique($date_values);

		sort($page_ids);
		sort($page_titles);
		sort($tokens);
		sort($token_names);
		sort($ips);
		sort($locations);
		sort($date_values);

		// Current filter selections
		$filter_page_id     = isset($_POST['ocml_filter_page_id']) ? sanitize_text_field($_POST['ocml_filter_page_id']) : '';
		$filter_page_title  = isset($_POST['ocml_filter_page_title']) ? sanitize_text_field($_POST['ocml_filter_page_title']) : '';
		$filter_token       = isset($_POST['ocml_filter_token']) ? sanitize_text_field($_POST['ocml_filter_token']) : '';
		$filter_token_name  = isset($_POST['ocml_filter_token_name']) ? sanitize_text_field($_POST['ocml_filter_token_name']) : '';
		$filter_ip          = isset($_POST['ocml_filter_ip']) ? sanitize_text_field($_POST['ocml_filter_ip']) : '';
		$filter_location    = isset($_POST['ocml_filter_location']) ? sanitize_text_field($_POST['ocml_filter_location']) : '';
		$filter_date_from   = isset($_POST['ocml_filter_date_from']) ? sanitize_text_field($_POST['ocml_filter_date_from']) : '';
		$filter_date_to     = isset($_POST['ocml_filter_date_to']) ? sanitize_text_field($_POST['ocml_filter_date_to']) : '';

		// Filter form with dropdowns
		echo '<h2>All Access Logs</h2>';
		echo '<p>Use these dropdowns to filter the consolidated usage logs below.</p>';
		echo '<form method="post" style="margin-bottom:20px;">';

		// Page ID
		echo '<select name="ocml_filter_page_id" style="margin-right:1%;">';
		echo '<option value="">All Page IDs</option>';
		foreach ( $page_ids as $pid ) {
			$selected = ( $filter_page_id == $pid ) ? 'selected' : '';
			echo '<option value="' . esc_attr($pid) . '" ' . $selected . '>' . esc_html($pid) . '</option>';
		}
		echo '</select>';

		// Page Title
		echo '<select name="ocml_filter_page_title" style="margin-right:1%;">';
		echo '<option value="">All Page Titles</option>';
		foreach ( $page_titles as $pt ) {
			$selected = ( $filter_page_title == $pt ) ? 'selected' : '';
			echo '<option value="' . esc_attr($pt) . '" ' . $selected . '>' . esc_html($pt) . '</option>';
		}
		echo '</select>';

		// Token
		echo '<select name="ocml_filter_token" style="margin-right:1%;">';
		echo '<option value="">All Tokens</option>';
		foreach ( $tokens as $tk ) {
			$selected = ( $filter_token == $tk ) ? 'selected' : '';
			echo '<option value="' . esc_attr($tk) . '" ' . $selected . '>' . esc_html($tk) . '</option>';
		}
		echo '</select>';

		// Token Name
		echo '<select name="ocml_filter_token_name" style="margin-right:1%;">';
		echo '<option value="">All Token Names</option>';
		foreach ( $token_names as $tn ) {
			$selected = ( $filter_token_name == $tn ) ? 'selected' : '';
			echo '<option value="' . esc_attr($tn) . '" ' . $selected . '>' . esc_html($tn) . '</option>';
		}
		echo '</select>';

		// IP
		echo '<select name="ocml_filter_ip" style="margin-right:1%;">';
		echo '<option value="">All IPs</option>';
		foreach ( $ips as $ipval ) {
			$selected = ( $filter_ip == $ipval ) ? 'selected' : '';
			echo '<option value="' . esc_attr($ipval) . '" ' . $selected . '>' . esc_html($ipval) . '</option>';
		}
		echo '</select>';

		// Location
		echo '<select name="ocml_filter_location" style="margin-right:1%;">';
		echo '<option value="">All Locations</option>';
		foreach ( $locations as $loc ) {
			$selected = ( $filter_location == $loc ) ? 'selected' : '';
			echo '<option value="' . esc_attr($loc) . '" ' . $selected . '>' . esc_html($loc) . '</option>';
		}
		echo '</select>';

		echo '<br><br>';

		// Date From
		echo '<label>From: </label>';
		echo '<select name="ocml_filter_date_from" style="margin-right:1%;">';
		echo '<option value="">All</option>';
		foreach ( $date_values as $dv ) {
			$selected = ( $filter_date_from == $dv ) ? 'selected' : '';
			echo '<option value="' . esc_attr($dv) . '" ' . $selected . '>' . esc_html($dv) . '</option>';
		}
		echo '</select>';

		// Date To
		echo '<label>To: </label>';
		echo '<select name="ocml_filter_date_to" style="margin-right:2%;">';
		echo '<option value="">All</option>';
		foreach ( $date_values as $dv ) {
			$selected = ( $filter_date_to == $dv ) ? 'selected' : '';
			echo '<option value="' . esc_attr($dv) . '" ' . $selected . '>' . esc_html($dv) . '</option>';
		}
		echo '</select>';

		submit_button('Filter Logs', 'small', 'ocml_filter_submit', false);
		echo '</form>';

		// Filter the logs
		$filtered_logs = array_filter($all_logs, function($log) use (
			$filter_page_id,
			$filter_page_title,
			$filter_token,
			$filter_token_name,
			$filter_ip,
			$filter_location,
			$filter_date_from,
			$filter_date_to
		) {
			// page_id
			if ( $filter_page_id !== '' && (string)$log['page_id'] !== $filter_page_id ) {
				return false;
			}
			// page_title
			if ( $filter_page_title !== '' && $log['page_title'] !== $filter_page_title ) {
				return false;
			}
			// token
			if ( $filter_token !== '' && $log['token'] !== $filter_token ) {
				return false;
			}
			// token_name
			if ( $filter_token_name !== '' && $log['token_name'] !== $filter_token_name ) {
				return false;
			}
			// ip
			if ( $filter_ip !== '' && $log['ip'] !== $filter_ip ) {
				return false;
			}
			// location
			if ( $filter_location !== '' && $log['location'] !== $filter_location ) {
				return false;
			}

			// date_from / date_to
			$date_part = substr($log['datetime'], 0, 10); 
			if ( $filter_date_from !== '' && $date_part < $filter_date_from ) {
				return false;
			}
			if ( $filter_date_to !== '' && $date_part > $filter_date_to ) {
				return false;
			}

			return true;
		});

		// Show logs table
		if ( ! empty($filtered_logs) ) {
			echo '<table class="widefat fixed" style="margin-top:10px;">';
			echo '<thead><tr>';
			echo '<th>Date/Time (Local)</th>';
			echo '<th>Page ID</th>';
			echo '<th>Page Title</th>';
			echo '<th>Token</th>';
			echo '<th>Token Name</th>';
			echo '<th>IP</th>';
			echo '<th>Location</th>';
			echo '</tr></thead>';
			echo '<tbody>';

			foreach ( $filtered_logs as $fl ) {
				echo '<tr>';
				echo '<td>' . esc_html($fl['datetime']) . '</td>';
				echo '<td>' . esc_html($fl['page_id']) . '</td>';
				echo '<td>' . esc_html($fl['page_title']) . '</td>';
				echo '<td><code>' . esc_html($fl['token']) . '</code></td>';
				echo '<td>' . esc_html($fl['token_name']) . '</td>';
				echo '<td>' . esc_html($fl['ip']) . '</td>';
				echo '<td>' . esc_html($fl['location']) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		} else {
			echo '<p><em>No logs found for these filters.</em></p>';
		}

		// Extra console logging
		$totalLogs = count($all_logs);
		echo "<script>console.log('Rendering logs table with a total of " . esc_js($totalLogs) . " logs before filtering.');</script>";
	}

	/**
	 * Creates a new token with optional name
	 */
	private function ocml_create_token($post_id, $token_name) {
		$token  = wp_generate_password(20, false);

		// Grab existing tokens or create array
		$tokens = get_post_meta($post_id, '_ocml_tokens_hashed_named', true);
		if ( ! is_array($tokens) ) {
			$tokens = array();
		}

		$tokens[] = array(
			'token'  => $token,
			'name'   => $token_name,
			'active' => true
		);
		update_post_meta($post_id, '_ocml_tokens_hashed_named', $tokens);
	}

	/**
	 * Sets a token's 'active' flag to false (revoked)
	 */
	private function ocml_revoke_token($post_id, $token_value) {
		$tokens = get_post_meta($post_id, '_ocml_tokens_hashed_named', true);
		if ( ! is_array($tokens) ) {
			return;
		}

		foreach ( $tokens as &$tk ) {
			if ( $tk['token'] === $token_value && ! empty($tk['active']) ) {
				$tk['active'] = false; // mark as revoked
			}
		}
		update_post_meta($post_id, '_ocml_tokens_hashed_named', $tokens);
	}

	/**
	 * Check for token in URL, set hashed cookie, log usage, etc.
	 */
	public function ocml_handle_magic_token() {
		if ( isset($_GET['magic_token']) ) {
			$token = sanitize_text_field($_GET['magic_token']);

			// Debug console message
			echo "<script>console.log('Detected hashed named token: " . esc_js($token) . "');</script>";

			$page_id = $this->ocml_find_page_by_token($token);
			if ( $page_id ) {
				$post = get_post($page_id);
				if ( ! empty($post->post_password) ) {
					global $wp_hasher;
					if ( empty($wp_hasher) ) {
						require_once ABSPATH . WPINC . '/class-phpass.php';
						$wp_hasher = new PasswordHash( 8, true );
					}

					// Hash the password exactly as WP does
					$hashed_password = $wp_hasher->HashPassword( stripslashes( $post->post_password ) );

					// Set the cookie
					$cookie_name = 'wp-postpass_' . COOKIEHASH;
					setcookie(
						$cookie_name,
						$hashed_password,
						time() + (10 * DAY_IN_SECONDS),
						COOKIEPATH,
						COOKIE_DOMAIN,
						is_ssl()
					);

					// Log usage
					$this->ocml_log_token_usage($page_id, $token);

					// Redirect
					wp_redirect( get_permalink($page_id) );
					exit;
				}
			}
		}
	}

	/**
	 * Locate the page containing this token, as long as it's active
	 */
	private function ocml_find_page_by_token($token) {
		$args = array(
			'post_type'      => 'page',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => '_ocml_tokens_hashed_named',
					'value'   => $token,
					'compare' => 'LIKE'
				)
			)
		);

		$pages = get_posts($args);
		if ( ! empty($pages) ) {
			foreach ( $pages as $page ) {
				$tokens = get_post_meta($page->ID, '_ocml_tokens_hashed_named', true);
				if ( is_array($tokens) ) {
					foreach ( $tokens as $tk ) {
						// Must match exact token and active
						if ( isset($tk['token']) && $tk['token'] === $token && ! empty($tk['active']) ) {
							return $page->ID;
						}
					}
				}
			}
		}
		return false;
	}

	/**
	 * Log usage for a token in post meta
	 */
	private function ocml_log_token_usage($page_id, $token) {
		// Log each usage in _ocml_token_usages_named meta
		echo "<script>console.log('Logging usage for token: " . esc_js($token) . " on page ID: " . esc_js($page_id) . "');</script>";

		$ip         = $this->ocml_get_ip_address();
		$location   = $this->ocml_get_geolocation($ip);
		$usage_data = get_post_meta($page_id, '_ocml_token_usages_named', true);
		if ( ! is_array($usage_data) ) {
			$usage_data = array();
		}

		// Attempt to find the token name
		$token_name = '';
		$tokens = get_post_meta($page_id, '_ocml_tokens_hashed_named', true);
		if ( is_array($tokens) ) {
			foreach ( $tokens as $t ) {
				if ( isset($t['token']) && $t['token'] === $token && isset($t['name']) ) {
					$token_name = $t['name'];
					break;
				}
			}
		}

		$usage_entry = array(
			'token'      => $token,
			'token_name' => $token_name,
			'ip'         => $ip,
			'location'   => $location,
			'datetime'   => current_time('mysql'),
		);

		$usage_data[] = $usage_entry;
		update_post_meta($page_id, '_ocml_token_usages_named', $usage_data);
	}

	/**
	 * Get usage logs for a specific token
	 */
	private function ocml_get_token_usage_logs($page_id, $token) {
		$usage_data = get_post_meta($page_id, '_ocml_token_usages_named', true);
		if ( ! is_array($usage_data) ) {
			return array();
		}

		// Filter logs for the specific token
		$logs_for_token = array();
		foreach ( $usage_data as $entry ) {
			if ( isset($entry['token']) && $entry['token'] === $token ) {
				$logs_for_token[] = $entry;
			}
		}
		return $logs_for_token;
	}

	/**
	 * Gather all usage logs from all pages, attaching page ID, page title, etc.
	 */
	private function ocml_get_all_usage_logs() {
		// Find all pages that might have usage logs
		$args = array(
			'post_type'      => 'page',
			'posts_per_page' => -1,
		);
		$pages = get_posts($args);
		$all_logs = array();

		foreach ( $pages as $page ) {
			$usage_data = get_post_meta($page->ID, '_ocml_token_usages_named', true);
			if ( is_array($usage_data) && ! empty($usage_data) ) {
				foreach ( $usage_data as $ud ) {
					// Attach page ID, title
					$ud['page_id']    = $page->ID;
					$ud['page_title'] = $page->post_title;
					$all_logs[] = $ud;
				}
			}
		}
		return $all_logs;
	}

	/**
	 * Basic IP detection
	 */
	private function ocml_get_ip_address() {
		if ( ! empty($_SERVER['HTTP_CLIENT_IP']) ) {
			return $_SERVER['HTTP_CLIENT_IP'];
		} elseif ( ! empty($_SERVER['HTTP_X_FORWARDED_FOR']) ) {
			return $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'UNKNOWN';
		}
	}

	/**
	 * Attempt to geolocate via ip-api
	 */
	private function ocml_get_geolocation($ip) {
		if ( $ip === 'UNKNOWN' ) {
			return 'Unknown IP';
		}

		$response = wp_remote_get('http://ip-api.com/json/' . $ip);
		if ( is_wp_error($response) ) {
			return 'Location lookup failed';
		}

		$body = wp_remote_retrieve_body($response);
		if ( empty($body) ) {
			return 'Location lookup failed';
		}

		$data = json_decode($body);
		if ( isset($data->status) && $data->status === 'success' ) {
			$city    = isset($data->city) ? $data->city : '';
			$region  = isset($data->regionName) ? $data->regionName : '';
			$country = isset($data->country) ? $data->country : '';
			return $city . ', ' . $region . ', ' . $country;
		}
		return 'Location lookup failed';
	}

}

new One_Click_Magic_Links_Hashed_Named();