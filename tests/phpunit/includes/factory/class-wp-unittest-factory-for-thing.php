<?php

/**
 * An abstract class that serves as a basis for all WordPress object-type factory classes.
 */
abstract class WP_UnitTest_Factory_For_Thing {

	var $default_generation_definitions;
	var $factory;

	/**
	 * Creates a new factory, which will create objects of a specific Thing
	 *
	 * @param object $factory Global factory that can be used to create other objects on the system
	 * @param array $default_generation_definitions Defines what default values should the properties of the object have. The default values
	 * can be generators -- an object with next() method. There are some default generators: {@link WP_UnitTest_Generator_Sequence},
	 * {@link WP_UnitTest_Generator_Locale_Name}, {@link WP_UnitTest_Factory_Callback_After_Create}.
	 */
	function __construct( $factory, $default_generation_definitions = array() ) {
		$this->factory                        = $factory;
		$this->default_generation_definitions = $default_generation_definitions;
	}

	/**
	 * Creates an object.
	 *
	 * @param array $args The arguments.
	 *
	 * @return mixed The result. Can be anything.
	 */
	abstract function create_object( $args );

	/**
	 * Updates an existing object.
	 *
	 * @param int   $object The object id.
	 * @param array $fields The values to update.
	 *
	 * @return mixed The result. Can be anything.
	 */
	abstract function update_object( $object, $fields );

	/**
	 * Creates an object.
	 *
	 * @param array $args                   Optional. The arguments for the object to create. Default is empty array.
	 * @param null  $generation_definitions Optional. The default values for the object. Default is null.
	 *
	 * @return mixed The result. Can be anything.
	 */
	function create( $args = array(), $generation_definitions = null ) {
		if ( is_null( $generation_definitions ) ) {
			$generation_definitions = $this->default_generation_definitions;
		}

		$generated_args = $this->generate_args( $args, $generation_definitions, $callbacks );
		$created        = $this->create_object( $generated_args );
		if ( ! $created || is_wp_error( $created ) ) {
			return $created;
		}

		if ( $callbacks ) {
			$updated_fields = $this->apply_callbacks( $callbacks, $created );
			$save_result    = $this->update_object( $created, $updated_fields );
			if ( ! $save_result || is_wp_error( $save_result ) ) {
				return $save_result;
			}
		}
		return $created;
	}

	/**
	 * Creates an object and returns its object.
	 *
	 * @param array $args                   Optional. The arguments for the object to create. Default is empty array.
	 * @param null  $generation_definitions Optional. The default values for the object. Default is null.
	 *
	 * @return mixed The created object. Can be anything.
	 */
	function create_and_get( $args = array(), $generation_definitions = null ) {
		$object_id = $this->create( $args, $generation_definitions );
		return $this->get_object_by_id( $object_id );
	}

	/**
	 * Retrieves an object by ID.
	 *
	 * @param int $object_id The object id.
	 *
	 * @return mixed The object. Can be anything.
	 */
	abstract function get_object_by_id( $object_id );

	/**
	 * Creates multiple objects.
	 *
	 * @param int   $count                  Amount of objects to create.
	 * @param array $args                   Optional. The arguments for the object to create. Default is empty array.
	 * @param null  $generation_definitions Optional. The default values for the object. Default is null.
	 *
	 * @return array
	 */
	function create_many( $count, $args = array(), $generation_definitions = null ) {
		$results = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$results[] = $this->create( $args, $generation_definitions );
		}
		return $results;
	}

	/**
	 * Combines the given argument with the generation_definitions (defaults) and applies
	 * possibly set callbacks on it.
	 *
	 * @param array       $args                   Optional. The arguments to combine with defaults. Default is empty array.
	 * @param array|null  $generation_definitions Optional. The defaults. Default is null.
	 * @param array|null  $callbacks              Optional. Array with callbacks to apply on the fields. Default is null.
	 *
	 * @return array|WP_Error Combined array on success. WP_Error when default value is incorrent.
	 */
	function generate_args( $args = array(), $generation_definitions = null, &$callbacks = null ) {
		$callbacks = array();
		if ( is_null( $generation_definitions ) ) {
			$generation_definitions = $this->default_generation_definitions;
		}

		// Use the same incrementor for all fields belonging to this object.
		$gen  = new WP_UnitTest_Generator_Sequence();
		$incr = $gen->get_incr();

		foreach ( array_keys( $generation_definitions ) as $field_name ) {
			if ( ! isset( $args[ $field_name ] ) ) {
				$generator = $generation_definitions[ $field_name ];
				if ( is_scalar( $generator ) ) {
					$args[ $field_name ] = $generator;
				} elseif ( is_object( $generator ) && method_exists( $generator, 'call' ) ) {
					$callbacks[ $field_name ] = $generator;
				} elseif ( is_object( $generator ) ) {
					$args[ $field_name ] = sprintf( $generator->get_template_string(), $incr );
				} else {
					return new WP_Error( 'invalid_argument', 'Factory default value should be either a scalar or an generator object.' );
				}
			}
		}

		return $args;
	}


	/**
	 * Applies the callbacks on the created object.
	 *
	 * @param WP_UnitTest_Factory_Callback_After_Create[] $callbacks Array with callback functions.
	 * @param mixed                                       $created   The object to apply callbacks for.
	 *
	 * @return array The altered fields.
	 */
	function apply_callbacks( $callbacks, $created ) {
		$updated_fields = array();

		foreach ( $callbacks as $field_name => $generator ) {
			$updated_fields[ $field_name ] = $generator->call( $created );
		}
		return $updated_fields;
	}

	/**
	 * Instantiates a callback objects for the given function name.
	 *
	 * @param string $function The callback function.
	 *
	 * @return WP_UnitTest_Factory_Callback_After_Create
	 */
	function callback( $function ) {
		return new WP_UnitTest_Factory_Callback_After_Create( $function );
	}

	/**
	 * Adds slashes to the given value.
	 *
	 * @param array|object|string|mixed $value The value to add slashes to.
	 *
	 * @return array|string The value with the possibly applied slashes.
	 */
	function addslashes_deep( $value ) {
		if ( is_array( $value ) ) {
			$value = array_map( array( $this, 'addslashes_deep' ), $value );
		} elseif ( is_object( $value ) ) {
			$vars = get_object_vars( $value );
			foreach ( $vars as $key => $data ) {
				$value->{$key} = $this->addslashes_deep( $data );
			}
		} elseif ( is_string( $value ) ) {
			$value = addslashes( $value );
		}

		return $value;
	}

}
