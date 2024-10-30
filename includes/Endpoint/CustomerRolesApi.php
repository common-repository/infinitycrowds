<?php
/**
 * InfCrowds
 *
 *
 * @package   InfCrowds
 * @author    InfCrowds
 * @license   GPL-3.0
 * @link      https://goInfCrowds.com
 * @copyright 2017 InfCrowds (Pty) Ltd
 */
namespace InfCrowds\WPR\Endpoint;
use InfCrowds\WPR;


class CustomerRolesApi extends BaseApi
{
    function __construct($ns)
    {
        parent::__construct($ns, '/customer-roles/');
        if (!function_exists('get_editable_roles')) {
            require_once(ABSPATH . '/wp-admin/includes/user.php');
        }
    }


    public function register_routes()
    {
        $this->add_route('get_possible_rule_values', array());
    }

    public function get_possible_rule_values() {
        $result = array();

        $editable_roles = get_editable_roles();

        if ( $editable_roles ) {
            foreach ( $editable_roles as $role => $details ) {
                $name = translate_user_role( $details['name'] );

//                $result[ $role ] = $name;
                array_push($result, array("id" => $role, "name"=> $name));
            }
        }

        return new \WP_REST_Response( array(
            'success' => true,
            'value' => $result
        ), 200 );
    }
}
