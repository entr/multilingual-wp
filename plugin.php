<?php
/*
Plugin Name: Multilingual WP
Version: 0.1
Description: Add Multilingual functionality to your WordPress site.
Author: nikolov.tmw
Author URI: http://themoonwatch.com
Plugin URI: http://themoonwatch.com/multilingual-wp


Copyright (C) 2012 Nikola Nikolov (nikolov.tmw@gmail.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/


require dirname(__FILE__) . '/scb/load.php';
require_once dirname( __FILE__ ) . '/flags_data.php';

/**
* 
*/
class Multilingual_WP {
	public static $options;
	public $plugin_url;
	public $languages_meta_key = '_mlwp_langs';
	public $rel_p_meta_key = '_mlwp_rel_post';

	public $link_type = 'pre';

	public $current_lang = '';
	public $locale = '';

	public $ID;
	public $post;
	public $post_type;
	public $rel_langs;
	public $rel_posts;

	/**
	* Late Filter Priority
	* 
	* Holds the priority for filters that need to be applied last - therefore it should be a really hight number
	* 
	* @var Integer
	*/
	public $late_fp = 10000;

	const LT_PRE = 'pre';
	const LT_QUERY = 'qa';
	const LT_SD = 'sd';

	private $_doing_save = false;
	private $pt_prefix = 'mlwp_';

	public function plugin_init() {
		// Creating an options object
		self::$options = new scbOptions( 'mlwp_options', __FILE__, array(
			'languages' => array(
				'en' => array(
					'locale' => 'en_US',
					'label' => 'English',
					'icon' => 'united-states.png',
					'na_message' => 'Sorry, but this article is not available in English.',
					'date_format' => '',
					'time_format' => '',
				),
				'bg' => array(
					'locale' => 'bg_BG',
					'label' => 'Български',
					'icon' => 'bulgaria.png',
					'na_message' => 'Sorry, but this article is not available in Bulgarian.',
					'date_format' => '',
					'time_format' => '',
				)
			),
			'default_lang' => false,
			'enabled_langs' => array(  ),
			'dfs' => '24',
			'enabled_pt' => array( 'post', 'page' ),
			'generated_pt' => array(),
			'show_ui' => false,
		) );

		// Creating settings page objects
		if ( is_admin() ) {
			require_once( dirname( __FILE__ ) . '/settings-page.php' );
			new Multilingual_WP_Admin_Page( __FILE__, self::$options );
		}

	}

	function __construct() {
		add_action( 'init', array( $this, 'init' ), 100 );

		add_action( 'plugins_loaded', array( $this, 'setup_locale' ), $this->late_fp );

		add_filter( 'locale', array( $this, 'set_locale' ), $this->late_fp );
	}

	public function init() {
		$this->plugin_url = plugin_dir_url( __FILE__ );

		wp_register_script( 'multilingual-wp-js', $this->plugin_url . 'js/multilingual-wp.js', array( 'jquery' ) );

		wp_register_style( 'multilingual-wp-css', $this->plugin_url . 'css/multilingual-wp.css' );

		$this->register_post_types();

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

		add_action( 'submitpost_box', array( $this, 'insert_editors' ), 0 );
		add_action( 'submitpage_box', array( $this, 'insert_editors' ), 0 );

		add_action( 'save_post', array( $this, 'save_post_action' ), 10 );

		if ( ! is_admin() ) {
			add_action( 'parse_request', array( $this, 'set_locale_from_query' ), 0 );

			add_filter( 'query_vars', array( $this, 'add_lang_query_var' ) );

			add_filter( 'the_posts', array( $this, 'filter_posts' ), $this->late_fp, 2 );
		}

		add_action( 'generate_rewrite_rules', array( $this, 'add_rewrite_rules' ), $this->late_fp );
	}

	public function add_rewrite_rules( $wp_rewrite ) {
		static $did_rules = false;
		if ( ! $did_rules ) {
			$additional_rules = array();
			foreach ( $wp_rewrite->rules as $regex => $match ) {
				$additional_rules[ "([a-z]{2})/$regex" ] = $this->add_query_arg( 'mlwp_lang', '$matches[1]', preg_replace_callback( '~\[(\d*?)\]~', array( $this, 'fix_rewrite_rules' ), $match ) );
			}
			$wp_rewrite->rules = array_merge( $additional_rules, $wp_rewrite->rules );
		}
	}

	public function setup_locale(  ) {
		if ( ! is_admin() ) {
			$request = $_SERVER['REQUEST_URI'];
			$home = home_url( '/' );
			$home = preg_replace( '~^.*' . preg_quote( $_SERVER['SERVER_NAME'], '~' ) . '~', '', $home );
			$request = str_replace( $home, '', $request );
			$lang = preg_match( '~([a-z]{2})~', $request, $matches );
			if ( ! empty( $matches ) && $this->is_enabled( $matches[0] ) ) {
				$this->current_lang = $matches[0];
			} else {
				$this->current_lang = self::$options->default_lang;
			}
			$this->locale = self::$options->languages[ $this->current_lang ]['locale'];
		}
	}

	public function set_locale( $locale ) {
		if ( $this->locale ) {
			$locale = $this->locale;
		}
		return $locale;
	}

	public function filter_posts( $posts, $wp_query ) {
		$language = isset( $wp_query->query['mlwp_lang'] ) ? $wp_query->query['mlwp_lang'] : $this->current_lang;
		if ( $language && $this->is_enabled( $language ) ) {
			$old_id = $this->ID;

			foreach ( $posts as $key => $post ) {
				$posts[ $key ] = $this->filter_post( $post, $language, false );
			}

			if ( $old_id ) {
				$this->setup_post_vars( $old_id );
			}
		}

		return $posts;
	}

	public function filter_post( $post, $language = false, $preserve_post_vars = true ) {
		$language = $language ? $language : $this->current_lang;
		if ( $language && ( ! isset( $post->mlwp_lang ) || $post->mlwp_lang != $lang ) && $this->is_enabled_pt( $post->post_type ) ) {
			if ( $preserve_post_vars ) {
				$old_id = $this->ID;
			}

			$this->setup_post_vars( $post->ID );
			if ( isset( $this->rel_langs[ $language ] ) && ( $_post = get_post( $this->rel_langs[ $language ] ) ) ) {
				$post->mlwp_lang = $language;
				$post->post_content = $_post->post_content;
				$post->post_title = $_post->post_title;
				$post->post_name = $_post->post_name;
				$post->post_excerpt = $_post->post_excerpt;
			}

			if ( $preserve_post_vars ) {
				$this->setup_post_vars( $old_id );
			}
		}
		return $post;
	}

	public function fix_rewrite_rules( $matches ) {
		$matches[1] = intval( $matches[1] ) + 1;
		return '[' . $matches[1] . ']';
	}

	public function add_query_arg( $key, $value, $target ) {
		$target .= strpos( $target, '?' ) !== false ? "&{$key}={$value}" : trailingslashit( $target ) . "?{$key}={$value}";
		return $target;
	}

	public function add_lang_query_var( $vars ) {
		$vars[] = 'mlwp_lang';

		return $vars;
	}

	public function set_locale_from_query( $wp ) {
		# If the query has detected a language, use it.
		if ( isset( $wp->query_vars['mlwp_lang'] ) && $this->is_enabled( $wp->query_vars['mlwp_lang'] ) ) {
			// Set the current language
			$this->current_lang = $wp->query_vars['mlwp_lang'];
			// Set the locale
			$this->locale = self::$options->languages[ $this->current_lang ]['locale'];
		} elseif ( ! $this->current_lang || ! $this->locale ) { // Otherwise if we don't have languge or locale set - set some defaults
			$this->current_lang = self::$options->default_lang;

			// Fallback
			$this->current_lang = $this->current_lang ? $this->current_lang : 'en';

			// Set the locale
			$this->locale = self::$options->languages[ $this->current_lang ]['locale'];
		}
	}

	public function is_enabled( $language ) {
		return in_array( $language, self::$options->enabled_langs );
	}

	public function is_enabled_pt( $pt ) {
		return in_array( $pt, self::$options->enabled_pt );
	}

	public function clear_lang_info( $subject, $lang = false ) {
		$lang = $lang ? $lang : $this->current_lang;
		if ( ! $lang ) {
			return false;
		}
		if ( is_array( $subject ) ) {
			$_subject = $subject;
			foreach ( $subject as $key => $value ) {
				$_subject[ $key ] = $this->clear_lang_info( $value, $lang );
			}
		} else {
			return preg_replace( '~' . $lang . '/~', '', $subject );
		}
	}

	public function slashes( $subject, $action = 'decode' ) {
		if ( $action == 'encode' ) {
			return str_replace( '/', '%2F', $subject );
		} else {
			return str_replace( '%2F', '/', $subject );
		}
	}

	public function save_post( $data, $wp_error = false ) {
		$this->_doing_save = true;

		$data = is_array( $data ) ? $data : (array) $data;

		if ( ! isset( $data['ID'] ) ) {
			$result = wp_insert_post( $data, $wp_error );
		} else {
			$result = wp_update_post( $data, $wp_error );
		}

		$this->_doing_save = false;

		return $result;
	}

	public function save_post_action( $post_id, $post = false ) {
		// If this is called during a post insert/update initiated by the plugin, skip it
		if ( $this->_doing_save ) {
			return;
		}
		$post = $post ? (object) $post : get_post( $post_id );

		// If this is an update on one of the posts generated by the plugin - skip it.
		if ( in_array( $post->post_type, self::$options->generated_pt ) ) {
			return;
		}

		// If the current post type is not in the supported post types, skip it
		if ( ! $this->is_enabled_pt( $post->post_type ) ) {
			return;
		}

		global $pagenow;

		if ( 'post.php' == $pagenow && 'POST' == $_SERVER['REQUEST_METHOD'] ) {
			$this->setup_post_vars( $post_id );

			$this->update_rel_langs();
		} else {
			$this->update_rel_default_language( $post_id, $post );
		}
	}

	public function update_rel_default_language( $post_id, $post = false ) {
		$post = $post ? $post : get_post( $post_id );

		$rel_langs = get_post_meta( $post_id, $this->languages_meta_key, true );
		if ( ! $rel_langs ) {
			return false;
		}

		$post = (array) $post;
		$default_lang = self::$options->default_lang;

		foreach ($rel_langs as $lang => $id) {
			$_post = get_post( $id, ARRAY_A );

			// Merge the newest values from the post with the related post
			$__post = array_merge( $_post, $post );

			if ( $lang != $default_lang ) {
				// If this is not the default language, we want to preserve the old content, title, etc
				$__post['post_title'] = $_post['post_title'];
				$__post['post_name'] = $_post['post_name'];
				$__post['post_content'] = $_post['postcontent'];
				// $__post['post_title'] = $_post['post_title'];
			}

			// Update the post
			$this->save_post( $__post );
		}
	}

	public function setup_post_vars( $post_id = false ) {
		// Store the current post's ID for quick access
		$this->ID = $post_id ? $post_id : get_the_ID();

		// Store the current post data for quick access
		$this->post = get_post( $this->ID );

		// Store the current post's related languages data
		$this->rel_langs = get_post_meta( $this->ID, $this->languages_meta_key, true );
		$this->post_type = get_post_type( $this->ID );

		// Related posts to the current post
		$this->rel_posts = array();
	}

	public function update_rel_langs( $post = false ) {
		if ( $post ) {
			$this->setup_post_vars( $post->ID );
		}

		if ( $this->rel_langs ) {
			foreach ( self::$options->enabled_langs as $lang ) {
				if ( ! isset( $this->rel_langs[ $lang ] ) || ! ( $_post = get_post( $this->rel_langs[ $lang ], ARRAY_A ) ) ) {
					continue;
				}
				if ( isset( $_POST[ "content_{$lang}" ] ) ) {
					$_post['post_content'] = $_POST[ "content_{$lang}" ];
				}
				if ( isset( $_POST[ "title_{$lang}" ] ) ) {
					$_post['post_title'] = $_POST[ "title_{$lang}" ];
				}
				$this->save_post( $_post );
			}
		}

		if ( $post ) {
			$this->setup_post_vars( get_the_ID() );
		}
	}

	private function register_post_types() {
		$enabled_pt = self::$options->enabled_pt;

		$generated_pt = array();

		if ( $enabled_pt ) {
			$enabled_langs = self::$options->enabled_langs;
			if ( ! $enabled_langs ) {
				return false;
			}

			$post_types = get_post_types( array(  ), 'objects' );

			$languages = self::$options->languages;
			$show_ui = (bool) self::$options->show_ui;

			foreach ( $enabled_pt as $pt_name ) {
				$pt = isset( $post_types[$pt_name] ) ? $post_types[$pt_name] : false;
				if ( ! $pt ) {
					continue;
				}
				foreach ($enabled_langs as $lang) {
					$name = "{$this->pt_prefix}{$pt_name}_{$lang}";
					$labels = array_merge(
						(array) $pt->labels,
						array( 'menu_name' => $pt->labels->menu_name . ' - ' . $languages[ $lang ]['label'], )
					);
					$args = array(
						'labels' => $labels,
						'public' => true,
						'exclude_from_search' => true,
						'show_ui' => $show_ui, 
						'query_var' => false,
						'rewrite' => true,
						'capability_type' => $pt->capability_type,
						'capabilities' => (array) $pt->cap,
						'map_meta_cap' => $pt->map_meta_cap,
						'hierarchical' => $pt->hierarchical,
						'menu_position' => 9999,
						'has_archive' => $pt->has_archive,
						'supports' => isset( $pt->supports ) ? $pt->supports : array(),
						'can_export' => $pt->can_export,
					);
					$result = register_post_type($name, $args);
					if ( ! is_wp_error( $result ) ) {
						$generated_pt[] = $name;
					}
				}

			}
		}

		// Update the option
		self::$options->generated_pt = $generated_pt;
	}

	public function admin_scripts( $hook ) {
		if ( 'post.php' == $hook && $this->is_enabled_pt( get_post_type( get_the_ID() ) ) ) {
			$this->setup_post_vars();
			
			$to_create = array();

			// Check the related languages
			if ( ! $this->rel_langs || ! is_array( $this->rel_langs ) ) {
				// If there are no language relantions currently set, add all enabled languages to the creation queue
				$to_create = self::$options->enabled_langs;
			} else {
				// Otherwise loop throuh all enabled languages
				foreach (self::$options->enabled_langs as $lang) {
					// If there is no relation for this language, or the related post no longer exists, add it to the creation queue
					if ( ! isset( $this->rel_langs[ $lang ] ) || ! ( $this->rel_posts[ $lang ] = get_post( $this->rel_langs[ $lang ] ) ) ) {
						$to_create[] = $lang;
					}
				}
			}

			// If the creation queue is not empty, loop through all languages and create corresponding posts
			if ( ! empty( $to_create ) ) {
				foreach ( $to_create as $lang ) {
					$pt = "{$this->pt_prefix}{$this->post_type}_{$lang}";
					$data = array(
						'post_title'     => $this->post->post_title,
						'post_name'      => $this->post->post_name,
						'post_content'   => '',
						'post_excerpt'   => '',
						'post_status'    => $this->post->post_status,
						'post_type'      => $pt,
						'post_author'    => $this->post->post_author,
						'ping_status'    => $this->post->ping_status, 
						'comment_status' => $this->post->comment_status,
						'post_parent'    => 0,
						'menu_order'     => $this->post->menu_order,
						'post_password'  => $this->post->post_password,
					);
					// If this is the default language, set the content and excerpt to the current post's content and excerpt
					if ( $lang == self::$options->default_lang ) {
						$data['post_content'] = $this->post->post_content;
						$data['post_excerpt'] = $this->post->post_excerpt;
					}
					$id = $this->save_post( $data );
					if ( $id ) {
						$this->rel_langs[ $lang ] = $id;
						$this->rel_posts[ $lang ] = (object) $data;
						update_post_meta( $id, $this->rel_p_meta_key, $this->ID );
					}
				}

				// Update the related languages data
				update_post_meta( $this->ID, $this->languages_meta_key, $this->rel_langs );
			}

			// Enqueue scripts and styles
			wp_enqueue_script( 'multilingual-wp-js' );
			wp_enqueue_style( 'multilingual-wp-css' );
		}
	}

	public function insert_editors() {
		global $pagenow;
		if ( 'post.php' == $pagenow && $this->is_enabled_pt( get_post_type( get_the_ID() ) ) ) {
			echo '<div class="hide-if-js" id="mlwp-editors">';
				echo '<h2>' . __( 'Language', 'multilingual-wp' ) . '</h2>';
				foreach (self::$options->enabled_langs as $i => $lang) {
					echo '<div class="js-tab mlwp-lang-editor lang-' . $lang . ( $lang == self::$options->default_lang ? ' mlwp-deflang' : '' ) . '" id="mlwp_tab_lang_' . $lang . '" title="' . self::$options->languages[ $lang ]['label'] . '">';
					
					echo '<input type="text" class="mlwp-title" name="title_' . $lang . '" size="30" value="' . esc_attr( $this->rel_posts[ $lang ]->post_title ) . '" id="title_' . $lang . '" autocomplete="off" />';

					wp_editor( $this->rel_posts[ $lang ]->post_content, "content_{$lang}" );

					echo '</div>';
				}
			echo '</div>';
		}
	}
}

scb_init( array( 'Multilingual_WP', 'plugin_init' ) );

global $Multilingual_WP;

// Let's allow anyone to override our class definition - this way anyone can extend the plugin and add/overwrite functionality without having the need to modify the plugin files
$mlwp_class_name = apply_filters( 'mlwp_class_name', 'Multilingual_WP' );
$Multilingual_WP = new $mlwp_class_name();