<?php
/**
 * Plugin Name: WP Dynamic OpenAPI Generator
 * Description: Dynamically inspects live WordPress REST API routes and outputs an OpenAPI 3.0 specification with Basic and Bearer Auth.
 * Version: 1.0.2
 * Author: Google Gemini and Vira System Paydar Pars 
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class WP_Dynamic_OpenAPI {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_openapi_route' ] );
        add_filter( 'all_plugins', [ $this, 'dynamic_plugin_description' ] );
    }

    /**
     * Dynamically update the plugin description in the WP Admin dashboard with the live spec URL.
     */
    public function dynamic_plugin_description( $plugins ) {
        $plugin_file = plugin_basename( __FILE__ );
        if ( isset( $plugins[ $plugin_file ] ) ) {
            $spec_url = esc_url( rest_url( 'wp-dynamic-openapi/v1/spec' ) );
            $plugins[ $plugin_file ]['Description'] = sprintf(
                'Dynamically inspects live WordPress REST API routes and outputs an OpenAPI 3.0 specification with Basic and Bearer Auth at <a href="%1$s" target="_blank">%1$s</a>',
                $spec_url
            );
        }
        return $plugins;
    }

    /**
     * Register the custom endpoint to output the OpenAPI JSON spec.
     */
    public function register_openapi_route() {
        //'myplugin/v1', '/submit-data/'
        register_rest_route( 'wp-dynamic-openapi/v1', '/spec/', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'generate_openapi_spec' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * Inspects live WP REST server routes and builds the OpenAPI structure.
     */
    public function generate_openapi_spec( WP_REST_Request $request ) {
        $rest_server = rest_get_server();
        $routes      = $rest_server->get_routes();

        $openapi = [
            'openapi' => '3.0.3',
            'info'    => [
                'title'       => get_bloginfo( 'name' ) . ' - Live REST API',
                'description' => 'Dynamically generated OpenAPI spec from WordPress live REST routes.',
                'version'     => get_bloginfo( 'version' ),
            ],
            'servers' => [
                [
                    'url'         => esc_url_raw( rest_url() ),
                    'description' => 'WordPress REST API Root',
                ],
            ],
            'paths'      => [],
            'components' => [
                'securitySchemes' => [
                    'BasicAuth' => [
                        'type'        => 'http',
                        'scheme'      => 'basic',
                        'description' => 'Standard WordPress Basic Authentication (or Application Passwords).',
                    ],
                    'BearerAuth' => [
                        'type'        => 'http',
                        'scheme'      => 'bearer',
                        'description' => 'JWT or Bearer Token Authentication header.',
                    ],
                ],
            ],
            'security' => [
                [ 'BasicAuth' => [] ],
                [ 'BearerAuth' => [] ],
            ],
        ];

        foreach ( $routes as $route_pattern => $route_endpoints ) {
            if ( strpos( $route_pattern, 'wp-dynamic-openapi' ) !== false ) {
                continue;
            }

            $oa_path = preg_replace( '/\(\?P<([^>]+)>[^)]+\)/', '{$1}', $route_pattern );
            
            if ( $oa_path[0] !== '/' ) {
                $oa_path = '/' . $oa_path;
            }

            $path_item = [];

            foreach ( $route_endpoints as $endpoint ) {
                $methods = isset( $endpoint['methods'] ) ? $endpoint['methods'] : [];
                if ( is_string( $methods ) ) {
                    $methods = [ $methods ];
                }

                foreach ( $methods as $method_name => $is_allowed ) {
                    if ( is_numeric( $method_name ) ) {
                        $method_name = $is_allowed;
                    }
                    
                    $http_method = strtolower( $method_name );
                    if ( ! in_array( $http_method, [ 'get', 'post', 'put', 'patch', 'delete' ], true ) ) {
                        continue;
                    }

                    $operation = [
                        'summary'     => 'Endpoint for ' . $oa_path,
                        'parameters'  => [],
                        'responses'   => [
                            '200' => [
                                'description' => 'Successful response',
                            ],
                        ],
                        'security'    => [
                            [ 'BasicAuth' => [] ],
                            [ 'BearerAuth' => [] ],
                        ],
                    ];

                    preg_match_all( '/\{([^}]+)\}/', $oa_path, $matches );
                    if ( ! empty( $matches[1] ) ) {
                        foreach ( $matches[1] as $param_name ) {
                            $operation['parameters'][] = [
                                'name'        => $param_name,
                                'in'          => 'path',
                                'required'    => true,
                                'schema'      => [ 'type' => 'string' ],
                                'description' => 'Path parameter',
                            ];
                        }
                    }

                    if ( ! empty( $endpoint['args'] ) && is_array( $endpoint['args'] ) ) {
                        $request_body_properties = [];

                        foreach ( $endpoint['args'] as $arg_name => $arg_options ) {
                            $arg_type = isset( $arg_options['type'] ) ? $arg_options['type'] : 'string';
                            if ( $arg_type === 'integer' ) {
                                $oa_type = 'integer';
                            } elseif ( $arg_type === 'boolean' ) {
                                $oa_type = 'boolean';
                            } elseif ( $arg_type === 'number' ) {
                                $oa_type = 'number';
                            } else {
                                $oa_type = 'string';
                            }

                            $arg_desc    = isset( $arg_options['description'] ) ? $arg_options['description'] : '';
                            $is_required = ! empty( $arg_options['required'] );

                            if ( $http_method === 'get' ) {
                                $is_path_param = false;
                                foreach ( $operation['parameters'] as $existing_param ) {
                                    if ( $existing_param['name'] === $arg_name && $existing_param['in'] === 'path' ) {
                                        $is_path_param = true;
                                        break;
                                    }
                                }

                                if ( ! $is_path_param ) {
                                    $operation['parameters'][] = [
                                        'name'        => $arg_name,
                                        'in'          => 'query',
                                        'required'    => $is_required,
                                        'schema'      => [ 'type' => $oa_type ],
                                        'description' => $arg_desc,
                                    ];
                                }
                            } else {
                                $request_body_properties[ $arg_name ] = [
                                    'type'        => $oa_type,
                                    'description' => $arg_desc,
                                ];
                                if ( isset( $arg_options['default'] ) ) {
                                    $request_body_properties[ $arg_name ]['default'] = $arg_options['default'];
                                }
                            }
                        }

                        if ( in_array( $http_method, [ 'post', 'put', 'patch' ], true ) && ! empty( $request_body_properties ) ) {
                            $operation['requestBody'] = [
                                'required' => false,
                                'content'  => [
                                    'application/json' => [
                                        'schema' => [
                                            'type'       => 'object',
                                            'properties' => $request_body_properties,
                                        ],
                                    ],
                                ],
                            ];
                        }
                    }

                    if ( empty( $operation['parameters'] ) ) {
                        unset( $operation['parameters'] );
                    }

                    $path_item[ $http_method ] = $operation;
                }
            }

            if ( ! empty( $path_item ) ) {
                $openapi['paths'][ $oa_path ] = $path_item;
            }
        }

        return new WP_REST_Response( $openapi, 200 );
    }
}

new WP_Dynamic_OpenAPI();