<?php
/**
 * InfinityCrowds
 *
 *
 * @package   InfinityCrowds
 * @author    Lior Chen
 * @license   GPL-3.0
 * @link      https://infinitycrowds.com
 * @copyright 2019 InfCrowds Ltd
 */

namespace InfCrowds\WPR;

/**
 * @subpackage Widget
 */
class Widget extends \WP_Widget {

	/**
	 * Initialize the widget
	 *
	 * @since 1.0.0
	 */
	public function __construct($store) {
		$this->_store = $store;
		$plugin = Plugin::get_instance();
		$this->plugin_slug = $plugin->get_plugin_slug();
		$this->version = $plugin->get_plugin_version();

		$widget_ops = array(
			'description' => esc_html__( 'Infinity Crowds widget.', $this->plugin_slug ),
		);

		parent::__construct( 'wpr-widget', esc_html__( 'Infinity Crowds', $this->plugin_slug ), $widget_ops );
	}

	/**
	 * Outputs the content of the widget
	 *
	 * @param array $args
	 * @param array $instance
	 */
	public function widget( $args, $instance ) {
		wp_enqueue_script( $this->plugin_slug . '-widget-script', plugins_url( 'assets/js/widget.js', dirname( __FILE__ ) ), array( 'jquery' ), $this->version );
		wp_enqueue_style( $this->plugin_slug . '-widget-style', plugins_url( 'assets/css/widget.css', dirname( __FILE__ ) ), $this->version );

		$object_name = 'wpr_object_' . uniqid();

		$object = array(
			'title'       => $instance['title'],
			'api_nonce'   => wp_create_nonce( 'wp_rest' ),
			'api_url'	  => rest_url( $this->plugin_slug . '/v1/' ),
			'ic_base_url' => $this->_store->get('ic_server_baseurl'),
		);

		wp_localize_script( $this->plugin_slug . '-widget-script', $object_name, $object );

		echo $args['before_widget'];

		?><div class="infcrouds" data-object-id="<?php echo $object_name ?>"></div><?php

		echo $args['after_widget'];
	}


	/**
	 * Outputs the options form on admin
	 *
	 * @param array $instance
	 * @return string|void
	 */
	public function form( $instance ) {
	}

	/**
	 * Processing widget options on save
	 *
	 * @param array $new_instance The new options
	 * @param array $old_instance The previous options
	 */
	public function update( $new_instance, $old_instance ) {
		// $instance = array();

		// $instance['title'] = sanitize_text_field( $new_instance['title'] );

		return $instance;
	}
}
