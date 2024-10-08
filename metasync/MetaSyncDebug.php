<?php
/**
 * Transforms a wp-config.php file.
 */
class WPConfigTransformerMetaSync {
	/**
	 * Append to end of file
	 */
	const ANCHOR_EOF = 'EOF';

	/**
	 * Path to the wp-config.php file.
	 *
	 * @var string
	 */
	protected $wp_config_path;

	/**
	 * Original source of the wp-config.php file.
	 *
	 * @var string
	 */
	protected $wp_config_src;

	/**
	 * Array of parsed configs.
	 *
	 * @var array
	 */
	protected $wp_configs = array();

	/**
	 * Instantiates the class with a valid wp-config.php.
	 *
	 * @throws Exception If the wp-config.php file is missing.
	 * @throws Exception If the wp-config.php file is not writable.
	 *
	 * @param string $wp_config_path Path to a wp-config.php file.
	 */
	public function __construct( $wp_config_path ) {
		$basename = basename( $wp_config_path );

		if ( ! file_exists( $wp_config_path ) ) {
			throw new \Exception( "{$basename} does not exist." );
		}

		if ( ! is_writable( $wp_config_path ) ) {
			throw new \Exception( "{$basename} is not writable." );
		}

		$this->wp_config_path = $wp_config_path;
	}

	/**
	 * Checks if a config exists in the wp-config.php file.
	 *
	 * @throws Exception If the wp-config.php file is empty.
	 * @throws Exception If the requested config type is invalid.
	 *
	 * @param string $type Config type (constant or variable).
	 * @param string $name Config name.
	 *
	 * @return bool
	 */
	public function exists( $type, $name ) {
		$wp_config_src = file_get_contents( $this->wp_config_path );
		
		if ( ! trim( $wp_config_src ) ) {
			throw new \Exception( 'Config file is empty.' );
		}
		
		// Normalize the newline to prevent an issue coming from OSX.
		$this->wp_config_src = str_replace( array( "\n\r", "\r" ), "\n", $wp_config_src );
		$this->wp_configs    = $this->parse_wp_config( $this->wp_config_src );

		if ( ! isset( $this->wp_configs[ $type ] ) ) {
		
			throw new \Exception( "Config type '{$type}' does not exist." );
			
            return false;
		}
		if($name =='WP_DEBUG_LOG'){
			
		 }
		return isset( $this->wp_configs[ $type ][ $name ] );
	}

	/**
	 * Get the value of a config in the wp-config.php file.
	 *
	 * @throws Exception If the wp-config.php file is empty.
	 * @throws Exception If the requested config type is invalid.
	 *
	 * @param string $type Config type (constant or variable).
	 * @param string $name Config name.
	 *
	 * @return array
	 */
	public function get_value( $type, $name ) {
		$wp_config_src = file_get_contents( $this->wp_config_path );
		
		if ( ! trim( $wp_config_src ) ) {
			throw new \Exception( 'Config file is empty.' );
		}

		$this->wp_config_src = $wp_config_src;
		$this->wp_configs    = $this->parse_wp_config( $this->wp_config_src );
		
		if ( ! isset( $this->wp_configs[ $type ] ) ) {
//			throw new \Exception( "Config type '{$type}' does not exist." );
            return null;
		}
	
		return $this->wp_configs[ $type ][ $name ]['value'];
	}

	/**
	 * Adds a config to the wp-config.php file.
	 *
	 * @throws Exception If the config value provided is not a string.
	 * @throws Exception If the config placement anchor could not be located.
	 *
	 * @param string $type    Config type (constant or variable).
	 * @param string $name    Config name.
	 * @param string $value   Config value.
	 * @param array  $options (optional) Array of special behavior options.
	 *
	 * @return bool
	 */
	public function add( $type, $name, $value, array $options = array() ) {
		
		
		if ( ! is_string( $value ) ) {
            return ;
			throw new \Exception( 'Config value must be a string.' );
		}
		
		if ( $this->exists( $type, $name ) ) {
			return false;
		}
		
		$defaults = array(
			'raw'       => false, // Display value in raw format without quotes.
			'anchor'    => "/* That's all, stop editing!", // Config placement anchor string.
			'separator' => PHP_EOL, // Separator between config definition and anchor string.
			'placement' => 'before', // Config placement direction (insert before or after).
		);

		list( $raw, $anchor, $separator, $placement ) = array_values( array_merge( $defaults, $options ) );

		$raw       = (bool) $raw;
		$anchor    = (string) $anchor;
		$separator = (string) $separator;
		$placement = (string) $placement;
		
		if ( self::ANCHOR_EOF === $anchor ) {
			
			$contents = $this->wp_config_src . $this->normalize( $type, $name, $this->format_value( $value, $raw ) );
		} else {
			
			if ( false === strpos( $this->wp_config_src, $anchor ) ) {
				throw new \Exception( 'Unable to locate placement anchor.' );
			}

			$new_src  = $this->normalize( $type, $name, $this->format_value( $value, $raw ) );
			$new_src  = ( 'after' === $placement ) ? $anchor . $separator . $new_src : $new_src . $separator . $anchor;
			$contents = str_replace( $anchor, $new_src, $this->wp_config_src );
		}
		
		
		return $this->save( $contents );
	}

	/**
	 * Updates an existing config in the wp-config.php file.
	 *
	 * @throws Exception If the config value provided is not a string.
	 *
	 * @param string $type    Config type (constant or variable).
	 * @param string $name    Config name.
	 * @param string $value   Config value.
	 * @param array  $options (optional) Array of special behavior options.
	 *
	 * @return bool
	 */
	public function update( $type, $name, $value, array $options = array() ) {
		if ( ! is_string( $value ) ) {
			throw new \Exception( 'Config value must be a string.' );
		}
		

		$defaults = array(
			'add'       => true, // Add the config if missing.
			'raw'       => false, // Display value in raw format without quotes.
			'normalize' => true, // Normalize config output using WP Coding Standards.
		);
		
		list( $add, $raw, $normalize ) = array_values( array_merge( $defaults, $options ) );

		$add       = (bool) $add;
		$raw       = (bool) $raw;
		$normalize = (bool) $normalize;
		
		if ( ! $this->exists( $type, $name ) ) {
			
			return ( $add ) ? $this->add( $type, $name, $value, $options ) : false;
		}else{
			
		}

		$old_src   = $this->wp_configs[ $type ][ $name ]['src'];
		$old_value = $this->wp_configs[ $type ][ $name ]['value'];
		$new_value = $this->format_value( $value, $raw );

		if ( $normalize ) {
			$new_src = $this->normalize( $type, $name, $new_value );
		} else {
			$new_parts    = $this->wp_configs[ $type ][ $name ]['parts'];
			$new_parts[1] = str_replace( $old_value, $new_value, $new_parts[1] ); // Only edit the value part.
			$new_src      = implode( '', $new_parts );
		}
		// echo json_encode([$this->wp_config_src]);
		// echo '<br/><br/><br/>';
		if($value=="true"){
			$contents = preg_replace(
				sprintf( '/(?<=^|;|<\?php\s|<\?\s)(\s*?)%s/m', preg_quote( trim( $old_src ), '/' ) ),
				'$1' . str_replace( '$', '\$', trim( $new_src ) ),
				$this->wp_config_src
			);
		}else{
			if ( ! $this->exists( $type, $name ) ) {
				return $this->save( '' );
			}
	
			$pattern  = sprintf( '/(?<=^|;|<\?php\s|<\?\s)%s\s*(\S|$)/m', preg_quote( $this->wp_configs[ $type ][ $name ]['src'], '/' ) );
			$contents = preg_replace( $pattern, '$1', $this->wp_config_src );
	
		}
		
		return $this->save( $contents );
	}

	/**
	 * Removes a config from the wp-config.php file.
	 *
	 * @param string $type Config type (constant or variable).
	 * @param string $name Config name.
	 *
	 * @return bool
	 */
	public function remove( $type, $name ) {
		if ( ! $this->exists( $type, $name ) ) {
			return false;
		}

		$pattern  = sprintf( '/(?<=^|;|<\?php\s|<\?\s)%s\s*(\S|$)/m', preg_quote( $this->wp_configs[ $type ][ $name ]['src'], '/' ) );
		$contents = preg_replace( $pattern, '$1', $this->wp_config_src );

		return $this->save( $contents );
	}

	/**
	 * Applies formatting to a config value.
	 *
	 * @throws Exception When a raw value is requested for an empty string.
	 *
	 * @param string $value Config value.
	 * @param bool   $raw   Display value in raw format without quotes.
	 *
	 * @return mixed
	 */
	protected function format_value( $value, $raw ) {
		if ( $raw && '' === trim( $value ) ) {
			throw new \Exception( 'Raw value for empty string not supported.' );
		}

		return ( $raw ) ? $value : var_export( $value, true );
	}

	/**
	 * Normalizes the source output for a name/value pair.
	 *
	 * @throws Exception If the requested config type does not support normalization.
	 *
	 * @param string $type  Config type (constant or variable).
	 * @param string $name  Config name.
	 * @param mixed  $value Config value.
	 *
	 * @return string
	 */
	protected function normalize( $type, $name, $value ) {
		if ( 'constant' === $type ) {
			$placeholder = "define( '%s', %s );";
		} elseif ( 'variable' === $type ) {
			$placeholder = '$%s = %s;';
		} else {
			throw new \Exception( "Unable to normalize config type '{$type}'." );
		}

		return sprintf( $placeholder, $name, $value );
	}

	/**
	 * Parses the source of a wp-config.php file.
	 *
	 * @param string $src Config file source.
	 *
	 * @return array
	 */
	protected function parse_wp_config( $src ) {
		$configs             = array();
		$configs['constant'] = array();
		$configs['variable'] = array();

		// Strip comments.
		foreach ( token_get_all( $src ) as $token ) {
			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$src = str_replace( $token[1], '', $src );
			}
		}

		preg_match_all( '/(?<=^|;|<\?php\s|<\?\s)(\h*define\s*\(\s*[\'"](\w*?)[\'"]\s*)(,\s*(\'\'|""|\'.*?[^\\\\]\'|".*?[^\\\\]"|.*?)\s*)((?:,\s*(?:true|false)\s*)?\)\s*;)/ims', $src, $constants );
		preg_match_all( '/(?<=^|;|<\?php\s|<\?\s)(\h*\$(\w+)\s*=)(\s*(\'\'|""|\'.*?[^\\\\]\'|".*?[^\\\\]"|.*?)\s*;)/ims', $src, $variables );

		if ( ! empty( $constants[0] ) && ! empty( $constants[1] ) && ! empty( $constants[2] ) && ! empty( $constants[3] ) && ! empty( $constants[4] ) && ! empty( $constants[5] ) ) {
			foreach ( $constants[2] as $index => $name ) {
				$configs['constant'][ $name ] = array(
					'src'   => $constants[0][ $index ],
					'value' => $constants[4][ $index ],
					'parts' => array(
						$constants[1][ $index ],
						$constants[3][ $index ],
						$constants[5][ $index ],
					),
				);
			}
		}

		if ( ! empty( $variables[0] ) && ! empty( $variables[1] ) && ! empty( $variables[2] ) && ! empty( $variables[3] ) && ! empty( $variables[4] ) ) {
			// Remove duplicate(s), last definition wins.
			$variables[2] = array_reverse( array_unique( array_reverse( $variables[2], true ) ), true );
			foreach ( $variables[2] as $index => $name ) {
				$configs['variable'][ $name ] = array(
					'src'   => $variables[0][ $index ],
					'value' => $variables[4][ $index ],
					'parts' => array(
						$variables[1][ $index ],
						$variables[3][ $index ],
					),
				);
			}
		}

		return $configs;
	}

	/**
	 * Saves new contents to the wp-config.php file.
	 *
	 * @throws Exception If the config file content provided is empty.
	 * @throws Exception If there is a failure when saving the wp-config.php file.
	 *
	 * @param string $contents New config contents.
	 *
	 * @return bool
	 */
	protected function save( $contents ) {
		
		if ( ! trim( $contents ) ) {
			throw new \Exception( 'Cannot save the config file with empty contents.' );
		}

		if ( $contents === $this->wp_config_src ) {
			return false;
		}

		$result = file_put_contents( $this->wp_config_path, $contents, LOCK_EX );
		// die(json_encode( $result));
		if ( false === $result ) {
			throw new \Exception( 'Failed to update the config file.' );
		}

		return true;
	}
	

}



class ConfigControllerMetaSync
{
    const WPDD_DEBUGGING_PREDEFINED_CONSTANTS_STATE = 'dlct_data_initial';
    private static $configfilePath;
    
    protected $optionKey = 'debuglogconfigtool_updated_constant';
    public $debugConstants = ['WP_DEBUG', 'WP_DEBUG_LOG', 'SCRIPT_DEBUG'];
    protected $config_file_manager;
    private static $configArgs = [
        'normalize' => true,
        'raw'       => true,
        'add'       => true,
    ];
    
    public function __construct()
    {
        $this->initialize();
    }
    
    private function initialize()
    {
        self::$configfilePath = $this->getConfigFilePath();
        //set anchor for the constants to write
        $configContents = file_get_contents(self::$configfilePath);
        if (false === strpos($configContents, "/* That's all, stop editing!")) {
            preg_match('@\$table_prefix = (.*);@', $configContents, $matches);
            self::$configArgs['anchor'] = $matches[0] ?? '';
            self::$configArgs['placement'] = 'after';
        }
        
        if (!is_writable(self::$configfilePath)) {
            add_action('admin_notices', function () {
                $class = 'notice notice-error is-dismissible';
                $message = 'Config file not writable';
                printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
            });
            return;
        }
        
        $this->config_file_manager = new WPConfigTransformerMetaSync(self::$configfilePath);
    }
    
    
    public function store()
    {
        try {
            $updatedConstants = [];
			$wp_debug_enabled = get_option('wp_debug_enabled', 'false');
			$wp_debug_log_enabled = get_option('wp_debug_log_enabled', 'false');
			$wp_debug_display_enabled = get_option('wp_debug_display_enabled', 'false');
           $constants = [
				'WP_DEBUG'         => [
					'name'  => 'WP_DEBUG',
					'value' => ($wp_debug_enabled=='true'?true:false),
					'info'  => 'Enable WP_DEBUG mode',
				],
				'WP_DEBUG_LOG'     => [
					'name'  => 'WP_DEBUG_LOG',
					'value' =>  ($wp_debug_log_enabled=='true'?true:false),
					'info'  => 'Enable Debug logging to the /wp-content/debug.log file',
				],
				'WP_DEBUG_DISPLAY' => [
					'name'  => 'WP_DEBUG_DISPLAY',
					'value' => ($wp_debug_display_enabled=='true'?true:false),
					'info'  => 'Disable or hide display of errors and warnings in html pages'
				]
			];
            $this->maybeRemoveDeletedConstants($constants);
            
            foreach ($constants as $constant) {

                $key = sanitize_title($constant['name']);
                $value = sanitize_text_field($constant['value']);
                if (empty($key)) {
                    continue;
                }
                $key = strtoupper($key);
                $value = str_replace("'", '', stripslashes($value));
                $value = $value ? 'true' : 'false';
                $this->config_file_manager->update('constant', $key, $value, self::$configArgs);
				//  if($key =='WP_DEBUG_LOG'){
				// 	die(json_encode(['constant', $key, $value, self::$configArgs]));
				// }
                $updatedConstants[] = $constant;
            }
			// die(json_encode($updatedConstants));
            // update_option($this->optionKey, json_encode($updatedConstants));
            // wp_send_json_success([
            //     'message' => 'Constant Updated!',
            //     'success' => true
            // ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'success' => false
            ]);
        }
    }
    
    public function exists($constant)
    {
        return $this->config_file_manager->exists('constant', strtoupper($constant));
    }
    
    public function getValue($constant)
    {
        if ($this->exists(strtoupper($constant))) {
			
            return $this->config_file_manager->get_value('constant', strtoupper($constant));
        }
        return null;
    }
    
    
    public function update($key, $value)
    {
        try{
            //By default, when attempting to update a config that doesn't exist, one will be added.
            $option = self::$configArgs;
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            return $this->config_file_manager->update('constant', strtoupper($key), $value, $option);
        } catch (\Exception $e){
        
        }
       
    }
    
    public function getConfigFilePath()
    {
        $file = ABSPATH . 'wp-config.php';
        if (!file_exists($file)) {
            if (@file_exists(dirname(ABSPATH) . '/wp-config.php')) {
                $file = dirname(ABSPATH) . '/wp-config.php';
            }
        }
        return apply_filters('wp_dlct_config_file_manager_path', $file);
    }
    
    /**
     * remove deleted constant from config
     * @param $constants
     */
    protected function maybeRemoveDeletedConstants($constants)
    {
        $previousSavedData = [];
        $deletedConstant = array_diff(array_column($constants, 'name'), array_column($constants, 'name'));
		//die(json_encode($constants));
        foreach ($deletedConstant as $item) {
            $this->config_file_manager->remove('constant', strtoupper($item));
        }
    }
    
}


