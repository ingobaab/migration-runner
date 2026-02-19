<?php

namespace FlyWp\Migrator\Api;

use FlyWP\Migrator\Api;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Database API Handler Class
 */
class Database {

    /**
     * Register database related routes
     *
     * @param string $namespace API namespace
     *
     * @return void
     */
    public function register_routes( $namespace ) {
        register_rest_route(
            $namespace,
            '/tables',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_tables'],
                'permission_callback' => [Api::class, 'check_permission'],
            ]
        );

        register_rest_route(
            $namespace,
            '/table/(?P<table>[\w-]+)/structure',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_table_structure'],
                'permission_callback' => [Api::class, 'check_permission'],
            ]
        );

        register_rest_route(
            $namespace,
            '/table/(?P<table>[\w-]+)/data',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_table_data'],
                'permission_callback' => [Api::class, 'check_permission'],
                'args'                => [
                    'offset' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                    'limit' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'default'           => 1000,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            $namespace,
            '/tables/structure',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_all_tables_structure'],
                'permission_callback' => [Api::class, 'check_permission'],
            ]
        );
    }

    /**
     * Get all tables
     *
     * @return WP_REST_Response
     */
    public function get_tables() {
        global $wpdb;

        $tables     = $wpdb->get_col( 'SHOW TABLES' );
        $table_info = [];

        foreach ( $tables as $table ) {
            $count        = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table ) . '`' );
            $table_info[] = [
                'count' => $count,
                'name'  => $table,
                'rows'  => (int) $count,
            ];
        }

        return rest_ensure_response( [
            'tables' => $table_info,
            'prefix' => $wpdb->prefix,
        ] );
    }

    /**
     * Get table structure
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_table_structure( $request ) {
        global $wpdb;

        $table  = $request->get_param( 'table' );

        if ( empty( $table ) ) {
            return new WP_Error( 'invalid_table', __( 'Table name is required', 'flywp-migrator' ) );
        }

        $structure = $wpdb->get_results( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`', ARRAY_A );

        if ( empty( $structure ) ) {
            return new WP_Error( 'invalid_table', __( 'Table not found', 'flywp-migrator' ) );
        }

        $structure = $structure[0]['Create Table'];
        $structure = str_replace( "CREATE TABLE `$table`", "DROP TABLE IF EXISTS `$table`; CREATE TABLE `$table`", $structure );

        return rest_ensure_response( [
            'structure' => $structure,
        ] );
    }

    /**
     * Get table data as raw SQL INSERT statement
     *
     * @param WP_REST_Request $request
     *
     * @return void Direct output of SQL
     */
    public function get_table_data( $request ) {
        global $wpdb;

        // Set headers for SQL download
        header( 'Content-Type: text/plain' );
        header( 'X-Content-Type-Options: nosniff' );

        $table  = $request->get_param( 'table' );
        $offset = (int) $request->get_param( 'offset' );
        $limit  = (int) $request->get_param( 'limit' );

        if ( empty( $table ) ) {
            echo '-- Error: Table name is required';
            exit;
        }

        // Get table columns
        $columns      = $wpdb->get_results( 'DESCRIBE `' . esc_sql( $table ) . '`', ARRAY_A );
        $column_names = [];

        foreach ( $columns as $column ) {
            $column_names[] = '`' . $column['Field'] . '`';
        }

        // First output a safer SQL mode
        echo "SET SESSION sql_mode='';\n";

        // Start a transaction
        echo "START TRANSACTION;\n";

        // Get the data with limit and offset
        $data  = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $table ) . '` LIMIT %d, %d', $offset, $limit ),
            ARRAY_A
        );

        if ( empty( $data ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo "-- No data found for table {$table}";
            echo "\nCOMMIT;\n";
            exit;
        }

        // Generate values for INSERT statement
        $all_values = [];

        foreach ( $data as $row ) {
            $values = [];

            foreach ( $row as $column => $value ) {
                $prepared_value = $this->prepare_value( $value, $column );

                if ( is_null( $prepared_value ) ) {
                    $values[] = 'NULL';
                } elseif ( is_numeric( $prepared_value ) ) {
                    $values[] = $prepared_value;
                } else {
                    $values[] = "'" . $prepared_value . "'";
                }
            }
            $all_values[] = '(' . implode( ', ', $values ) . ')';
        }

        // Generate SQL in smaller chunks to avoid max packet issues
        $chunk_size = 100; // Adjust based on typical row size
        $chunks     = array_chunk( $all_values, $chunk_size );

        foreach ( $chunks as $chunk ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo "INSERT IGNORE INTO `{$table}` (" . implode( ', ', $column_names ) . ') VALUES ' . implode( ",\n", $chunk ) . ";\n";
        }

        // Commit the transaction
        echo "COMMIT;\n";
        exit;
    }

    /**
     * Prepare value with proper escaping and serialization handling
     *
     * @param mixed  $value  Value to prepare
     * @param string $column Column name
     *
     * @return mixed Prepared value
     */
    private function prepare_value( $value, $column ) {
        global $wpdb;

        // Handle NULL values
        if ( is_null( $value ) ) {
            return null;
        }

        // For numbers, return as is
        if ( is_numeric( $value ) ) {
            return $value;
        }

        // For strings, check for serialized data and fix it
        if ( is_string( $value ) ) {
            // Check for serialized data
            if ( is_serialized( $value ) ) {
                // Fix serialized data
                $fixed_value = $this->recursively_fix_serialized_string( $value );
                // Escape the fixed serialized data and remove placeholder
                return $wpdb->remove_placeholder_escape(
                    $wpdb->_real_escape( $fixed_value )
                );
            }

            // Regular string, escape it and remove placeholder
            return $wpdb->remove_placeholder_escape(
                $wpdb->_real_escape( $value )
            );
        }

        // Handle boolean values
        if ( is_bool( $value ) ) {
            return $value ? 1 : 0;
        }

        // Return the value as-is for other types
        return $value;
    }

    /**
     * Recursively fix serialized strings with multiple regex checks
     * Inspired by Everest Backup plugin
     *
     * @param string $serialized Serialized data string
     * @param int    $key        Current regex pattern index
     *
     * @return string Fixed serialized string
     */
    private function recursively_fix_serialized_string( $serialized, $key = 0 ) {
        if ( !$serialized || !is_string( $serialized ) ) {
            return $serialized;
        }

        // Array of regex patterns to fix serialized strings
        $regexes = [
            // Beaver Builder contents type compatible
            '/(?<=^|\{|;)s:(\d+):\"(.*?)\";(?=[asbdiO]\:\d|N;|\}|$)/s',

            // Elementor contents type compatible
            '/s\:(\d+)\:\"(.*?)\";/s',

            // General all-purpose final check
            '#s:(\d+):"(.*?)";(?=\\}*(?:[aOsidbN][:;]|\\z))#s',
        ];

        if ( !isset( $regexes[$key] ) ) {
            return $serialized;
        }

        $regex = $regexes[$key];

        $fixed_string = preg_replace_callback(
            $regex,
            function ( $matches ) {
                return 's:' . strlen( $matches[2] ) . ':"' . $matches[2] . '";';
            },
            $serialized
        );

        // If we're at the last regex, return the result
        if ( $key === count( $regexes ) - 1 ) {
            return $fixed_string;
        }

        // Otherwise, continue with the next regex
        return $this->recursively_fix_serialized_string( $fixed_string, $key + 1 );
    }

    /**
     * Get structure of all tables
     *
     * @return WP_REST_Response
     */
    public function get_all_tables_structure() {
        global $wpdb;

        $tables     = $wpdb->get_col( 'SHOW TABLES' );
        $structures = [];

        foreach ( $tables as $table ) {
            $structure = $wpdb->get_results( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`', ARRAY_A );

            if ( !empty( $structure ) ) {
                $create_table       = $structure[0]['Create Table'];
                $create_table       = str_replace( "CREATE TABLE `$table`", "DROP TABLE IF EXISTS `$table`; CREATE TABLE `$table`", $create_table );
                $structures[$table] = $create_table;
            }
        }

        return rest_ensure_response( [
            'success'    => true,
            'structures' => $structures,
        ] );
    }
}
