<?php

/**
 * Class INFCWDS_Plugin_Compatibilities
 * Loads all the compatibilities files we have to provide compatibility with each plugin
 */
class INFCWDS_Plugin_Compatibilities {

	public static $plugin_compatibilities = array();

	public static function load_all_compatibilities() {
		// load all the INFCWDS_Compatibilities files automatically
		foreach ( glob( plugin_dir_path( INFCWDS_PLUGIN_FILE ) . '/compatibilities/*.php' ) as $_field_filename ) {
			$basename = basename( $_field_filename );
			if ( strpos( $basename, 'class-fake-kirki.php' ) !== false ) {
				continue;
			}
			require_once( $_field_filename );
		}
	}


	public static function register( $object, $slug ) {
		self::$plugin_compatibilities[ $slug ] = $object;
	}

	public static function get_compatibility_class( $slug ) {
		return ( isset( self::$plugin_compatibilities[ $slug ] ) ) ? self::$plugin_compatibilities[ $slug ] : false;
	}

	public static function get_fixed_currency_price( $price, $currency = null ) {

		if ( ! empty( self::$plugin_compatibilities ) ) {

			foreach ( self::$plugin_compatibilities as $plugins_class ) {

				if ( $plugins_class->is_enable() && is_callable( array( $plugins_class, 'alter_fixed_amount' ) ) ) {

					return call_user_func( array( $plugins_class, 'alter_fixed_amount' ), $price, $currency );
				}
			}
		}

		return $price;
	}

	public static function get_fixed_currency_price_reverse( $price, $from= null, $to = null ) {

		if ( ! empty( self::$plugin_compatibilities ) ) {

			foreach ( self::$plugin_compatibilities as $plugins_class ) {

				if ( $plugins_class->is_enable() && is_callable( array( $plugins_class, 'get_fixed_currency_price_reverse' ) ) ) {

					return call_user_func( array( $plugins_class, 'get_fixed_currency_price_reverse' ), $price, $from, $to );
				}
			}
		}

		return $price;
	}
}

//}

INFCWDS_Plugin_Compatibilities::load_all_compatibilities();

