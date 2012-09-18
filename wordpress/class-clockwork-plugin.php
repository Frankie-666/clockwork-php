<?php
/*  Copyright 2012, Mediaburst Limited.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

// Require the Clockwork API
if( !class_exists('Clockwork') ) {
  require_once( 'clockwork/class-Clockwork.php' );
}
if( !class_exists('WordPressClockwork') ) {
  require_once( 'clockwork/class-WordPressClockwork.php' );
}

/**
 * Base class for Clockwork plugins
 *
 * @package Clockwork
 * @author James Inman
 */
abstract class Clockwork_Plugin {
  
  /**
   * Version of the Clockwork Wordpress wrapper
   */
  const VERSION = '1.0.1';
	/**
	 * URL to signup for a new Clockwork account
	 */
	const SIGNUP_URL = 'http://www.clockworksms.com/platforms/wordpress/?utm_source=wpadmin&utm_medium=plugin&utm_campaign=wp-clockwork';
	/**
	 * URL to top up message credit
	*/
	const BUY_URL = 'https://app.clockworksms.com/purchase/?utm_source=wpadmin&utm_medium=plugin&utm_campaign=wp-clockwork';
	/**
	 * URL for support
	 */
	const SUPPORT_URL = 'http://www.clockworksms.com/support/?utm_source=wpadmin&utm_medium=plugin&utm_campaign=wp-clockwork';
  
  /**
   * @param $callback Callback function for the plugin's menu item
   *
   * @author James Inman
   */
  public $plugin_callback = null;
  
  /**
   * @param $plugin_dir Plugin directory name 
   *
   * @author James Inman
   */
  public $plugin_dir = null;
  
  /**
	 * Instance of WordPressClockwork
	 *
	 * @var WordPressClockwork
	 * @author James Inman
	 */
  protected $clockwork = null;

  /**
   * Setup admin panel menu, notices and settings
   *
   * @author James Inman
   */
  public function __construct() {
    // If Clockwork API key isn't set, convert existing username and password into API key
    $this->convert_existing_username_and_password();
    
    // Setup clockwork
    try {
      $options = get_option( 'clockwork_options' );
      if( is_array( $options ) && isset( $options['api_key'] ) ) {
        $this->clockwork = new WordPressClockwork( $options['api_key'] );
      }
    } catch( Exception $e ) {
    }
  
    // Register the activation hook to install
    register_activation_hook( __FILE__, array( $this, 'install' ) );
    
    add_action( 'admin_head', array( $this, 'setup_admin_head' ) );  
    add_action( 'admin_menu', array( $this, 'setup_admin_navigation' ) );
    add_action( 'admin_notices', array( $this, 'setup_admin_message' ) ); 
    add_action( 'admin_bar_menu', array( $this, 'setup_admin_bar' ), 999 );
    add_action( 'admin_init', array( $this, 'setup_admin_init' ) );
    
    $this->plugin_callback = array( $this, 'main' );
  }
    
  /**
   * Return the username and password from the plugin's existing options
   *
   * @return array Array of 'username' and 'password'
   * @author James Inman
   */
  abstract public function get_existing_username_and_password();
  
  /**
   * Setup HTML for the admin <head>
   *
   * @return void
   * @author James Inman
   */
  abstract public function setup_admin_head();
    
  /**
   * Convert existing username and password to a new API key
   *
   * @return void
   * @author James Inman
   */
  public function convert_existing_username_and_password() {
    $options = get_option( 'clockwork_options' );
    if( !is_array( $options ) || !isset( $options['api_key'] ) ) {
      $existing_details = $this->get_existing_username_and_password();
      
      if( is_array( $existing_details ) && isset( $existing_details['username'] ) && isset( $existing_details['password'] ) ) {
        try {
          // We have a username and password, now go and convert them
          $this->clockwork = new WordPressClockwork( $existing_details['username'], $existing_details['password'] );
          $key = $this->clockwork->createAPIKey( 'WordPress - ' . home_url() );
          // Set the Clockwork API key to be the newly created key
          update_option( 'clockwork_options', array( 'api_key' => $key ) );          
        } catch( ClockworkException $e ) {
          return;
        }
      }        
    }
  }
  
  /**
   * Called on plugin activation
   *
   * @return void
   * @author James Inman
   */
  public function install() {
  }
  
  /**
   * Tell the user to update their Clockwork options on every admin panel page if they haven't already
   *
   * @return void
   * @author James Inman
   */
  public function setup_admin_message() {
    // Don't bother showing the "You need to set your Clockwork options" message if it's that form we're viewing
    if( !isset( $this->clockwork ) && ( get_current_screen()->base != 'toplevel_page_clockwork_options' ) ) {
      $this->show_admin_message('You need to set your <a href="' . site_url() . '/wp-admin/admin.php?page=clockwork_options">Clockwork options</a> before you can use ' . $this->plugin_name . '.');
    }  
  }
  
  /**
	 * Add the Clockwork balance to the admin bar
	 *
	 * @return void
	 * @author James Inman
	 */
  public function setup_admin_bar() {
		global $wp_admin_bar;
		if ( !is_super_admin() || !is_admin_bar_showing() ) {
			return;
		}
		// Display a low credit notification if there's no credit
    try {
      if( !isset( $this->clockwork ) ) {
        $options = get_option( 'clockwork_options' );
        
        $clockwork = new WordPressClockwork( $options['api_key'] );
      }
  		$balance = $this->clockwork->checkBalance();
  		if( $balance <= 0 ) {
  			$balance_string = '£0. Top up now!'; 
  		} else {
  			$balance_string = $balance['symbol'] . $balance['balance'];
  		}
  		// Add a node to the Admin bar
  	  $wp_admin_bar->add_node( array(
  	  	'id' => 'clockwork_balance',
  			'title' => 'Clockwork: ' . $balance_string,
  			'href' => self::BUY_URL ) 
  		);
    } catch( Exception $e ) {
      // Don't kill the entire admin panel because we can't get the balance
    }
  }
  
  /**
   * Setup admin navigation: callback for 'admin_menu'
   *
   * @return void
   * @author James Inman
   */
  public function setup_admin_navigation() {
    global $menu;
    
    $menu_exists = false;
    foreach( $menu as $k => $item ) {
      if( $item[0] == "Clockwork SMS" ) {
        $menu_exists = true;
        break;
      }
    }

    // Setup global Clockwork options
    if( !$menu_exists ) {    
      add_menu_page( __( 'Clockwork SMS', $this->language_string ), __( 'Clockwork SMS', $this->language_string ), 'manage_options', 'clockwork_options', array( $this, 'clockwork_options' ), plugins_url( 'images/logo_16px_16px.png', dirname( __FILE__ ) ) );
      add_submenu_page( 'clockwork_options', __( 'Clockwork Options', $this->language_string ), __( 'Clockwork Options', $this->language_string ), 'manage_options', 'clockwork_options', array( $this, 'clockwork_options' ) );
    }
    
    // Setup options for this plugin
    add_submenu_page( 'clockwork_options', __( $this->plugin_name, $this->language_string ), __( $this->plugin_name, $this->language_string ), 'manage_options', $this->plugin_callback[1], $this->plugin_callback );
  }
  
  /**
   * Register global Clockwork settings for API keys 
   *
   * @return void
   * @author James Inman
   */
  public function setup_admin_init() {
    register_setting( 'clockwork_options', 'clockwork_options', array( $this, 'clockwork_options_validate' ) );
    add_settings_section( 'clockwork_api_keys', 'API Key', array( $this, 'settings_api_key_text' ), 'clockwork' );
    add_settings_field( 'clockwork_api_key', 'Your API Key', array( $this, 'settings_api_key_input' ), 'clockwork', 'clockwork_api_keys' );    
  }
  
  /**
   * Introductory text for the API keys part of the form
   *
   * @return void
   * @author James Inman
   */
  public function settings_api_key_text() {
		echo '<p>You need an API key to use the Clockwork plugins.</p>';
	}
  
  /**
   * Input box for the API key
   *
   * @return void
   * @author James Inman
   */
  public function settings_api_key_input() {
    try {
      if( !isset( $this->clockwork ) ) {
        $options = get_option( 'clockwork_options' );
        $this->clockwork = new WordPressClockwork( $options['api_key'] );
      }
      
      echo "<input id='clockwork_api_key' name='clockwork_options[api_key]' size='40' type='text' value='{$this->clockwork->key}' />";
      
      // Show balance
      $balance = $this->clockwork->checkBalance();
      if( $balance ) {
	      echo '<p><strong>Balance:</strong> ' . $balance['symbol'] . $balance['balance'] . '&nbsp;&nbsp;&nbsp;<a href="' . self::BUY_URL . '" class="button">Buy More</a></p>';
	    } else { // We can't get the credits for some reason
		    echo '<p><a href="' . self::BUY_URL . '" class="button">Buy More Credit</a></p>';
	    } 
      
    } catch( ClockworkException $e ) {
      echo "<input id='clockwork_api_key' name='clockwork_options[api_key]' size='40' type='text' value='' />";
      echo '<p><a href="' . self::SIGNUP_URL . '" class="button">Get An API Key</a></p>';        
    }
  }
  
  /**
   * Validation for the API key
   *
   * @return void
   * @author James Inman
   */
  public function clockwork_options_validate( $val ) {
    try {
      $key = trim( $val['api_key'] );
      if( $key ) {
        $clockwork = new WordPressClockwork( $key );
        $clockwork->checkKey();
        $this->clockwork = $clockwork;
        add_settings_error( 'clockwork_options', 'clockwork_options', 'Your settings were saved! You can now start using Clockwork SMS.', 'updated' );
      } else {
	      $key = '';
	      add_settings_error( 'clockwork_options', 'clockwork_options', 'You cannot enter a blank API key.', 'error' );
      }
    } catch( ClockworkException $ex ) {
      add_settings_error( 'clockwork_options', 'clockwork_options', 'Your API key was incorrect. Please enter it again.', 'error' );
    }
    return $val;
  }
  
  /**
   * Render the main Clockwork options page
   *
   * @return void
   * @author James Inman
   */
  public function clockwork_options() {
    $this->render_template( 'clockwork-options' );
  }
  
  /**
   * Show a message at the top of the administration panel
   *
   * @param string $message Error message to show (can include HTML) 
   * @param bool $errormsg True to display as a red 'error message'
   * @return void
   * @author James Inman
   */
  protected function show_admin_message( $message, $errormsg = false ) {
    if( $errormsg ) {
      echo '<div id="message" class="error">';
    } else {
      echo '<div id="message" class="updated fade">';
    }
  
    echo "<p><strong>$message</strong></p></div>";
  }
  
  /**
   * Render a template file from the templates directory
   *
   * @param string $name Path to template file, excluding .php extension
   * @param array $data Array of data to include in template
   * @return void
   * @author James Inman
   */
  protected function render_template( $name, $data = array() ) {
    include( WP_PLUGIN_DIR . '/' . $this->plugin_dir . '/templates/' . $name . '.php');
  }

}
