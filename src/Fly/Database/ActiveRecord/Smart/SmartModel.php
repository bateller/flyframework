<?php namespace Fly\Database\ActiveRecord\Smart;

use Closure;
use Fly\Container\Container;
use Fly\Database\Capsule\Manager as DatabaseCapsule;
use Fly\Database\ActiveRecord\Relations\BelongsTo;
use Fly\Events\Dispatcher;
use Fly\Support\MessageBag;
use Fly\Support\Facades\Input;
use Fly\Support\Facades\Hash;
use Fly\Support\Facades\Validator;
use Fly\Database\ActiveRecord\Model;
use Fly\Support\Str;
use Fly\Validation\DatabasePresenceVerifier;
use Fly\Validation\Factory as ValidationFactory;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * SmartModel - Self-validating ActiveRecord model base class
 *
 */
abstract class SmartModel extends \Fly\Database\ActiveRecord\Model {

    /**
     * The rules to be applied to the data.
     *
     * @var array
     */
    public static $rules = array();

    /**
     * The array of custom error messages.
     *
     * @var array
     */
    public static $customMessages = array();

    /**
     * The message bag instance containing validation error messages
     *
     * @var \Fly\Support\MessageBag
     */
    public $validationErrors;

    /**
     * Makes the validation procedure throw an {@link InvalidModelException} instead of returning
     * false when validation fails.
     *
     * @var bool
     */
    public $throwOnValidation = false;

    /**
     * Forces the behavior of findOrFail in very find method - throwing a {@link ModelNotFoundException}
     * when the model is not found.
     *
     * @var bool
     */
    public static $throwOnFind = false;

    /**
     * If set to true, the object will automatically populate model attributes from Input::all()
     *
     * @var bool
     */
    public $autoHydrateEntityFromInput = false;

    /**
     * By default, SmartModel will attempt hydration only if the model object contains no attributes and
     * the $autoHydrateEntityFromInput property is set to true.
     * Setting $forceEntityHydrationFromInput to true will bypass the above check and enforce
     * hydration of model attributes.
     *
     * @var bool
     */
    public $forceEntityHydrationFromInput = false;

    /**
     * If set to true, the object will automatically remove redundant model
     * attributes (i.e. confirmation fields).
     *
     * @var bool
     */
    public $autoPurgeRedundantAttributes = false;

    /**
     * Array of closure functions which determine if a given attribute is deemed
     * redundant (and should not be persisted in the database)
     *
     * @var array
     */
    protected $purgeFilters = array();

    protected $purgeFiltersInitialized = false;

    /**
     * List of attribute names which should be hashed using the Bcrypt hashing algorithm.
     *
     * @var array
     */
    public static $passwordAttributes = array();

    /**
     * If set to true, the model will automatically replace all plain-text passwords
     * attributes (listed in $passwordAttributes) with hash checksums
     *
     * @var bool
     */
    public $autoHashPasswordAttributes = false;

    /**
     * If set to true will try to instantiate the validator as if it was outside Laravel.
     *
     * @var bool
     */
    protected static $externalValidator = false;

    /**
     * A Translator instance, to be used by standalone Ardent instances.
     *
     * @var \Fly\Validation\Factory
     */
    protected static $validationFactory;

    /**
     * Can be used to ease declaration of relationships in SmartModel models.
     * Follows closely the behavior of the relation methods used by ActiveRecord, but packing them into an indexed array
     * with relation constants make the code less cluttered.
     *
     * It should be declared with camel-cased keys as the relation name, and value being a mixed array with the
     * relation constant being the first (0) value, the second (1) being the classname and the next ones (optionals)
     * having named keys indicating the other arguments of the original methods: 'foreignKey' (belongsTo, hasOne,
     * belongsToMany and hasMany); 'table' and 'otherKey' (belongsToMany only); 'name', 'type' and 'id' (specific for
     * morphTo, morphOne and morphMany).
     * Exceptionally, the relation type MORPH_TO does not include a classname, following the method declaration of
     * {@link \Fly\Database\ActiveRecord\Model::morphTo}.
     *
     * Example:
     * <code>
     * class Order extends SmartModel {
     *     protected static $relations = array(
     *         'items'    => array(self::HAS_MANY, 'Item'),
     *         'owner'    => array(self::HAS_ONE, 'User', 'foreignKey' => 'user_id'),
     *         'pictures' => array(self::MORPH_MANY, 'Picture', 'name' => 'imageable')
     *     );
     * }
     * </code>
     *
     * @see \Fly\Database\ActiveRecord\Model::hasOne
     * @see \Fly\Database\ActiveRecord\Model::hasMany
     * @see \Fly\Database\ActiveRecord\Model::belongsTo
     * @see \Fly\Database\ActiveRecord\Model::belongsToMany
     * @see \Fly\Database\ActiveRecord\Model::morphTo
     * @see \Fly\Database\ActiveRecord\Model::morphOne
     * @see \Fly\Database\ActiveRecord\Model::morphMany
     *
     * @var array
     */
    protected static $relationsData = array();

    const HAS_ONE = 'hasOne';

    const HAS_MANY = 'hasMany';

    const BELONGS_TO = 'belongsTo';

    const BELONGS_TO_MANY = 'belongsToMany';

    const MORPH_TO = 'morphTo';

    const MORPH_ONE = 'morphOne';

    const MORPH_MANY = 'morphMany';

    /**
     * Array of relations used to verify arguments used in the {@link $relationsData}
     *
     * @var array
     */
    protected static $relationTypes = array(
        self::HAS_ONE, self::HAS_MANY,
        self::BELONGS_TO, self::BELONGS_TO_MANY,
        self::MORPH_TO, self::MORPH_ONE, self::MORPH_MANY
    );

    /**
     * Create a new SmartModel model instance.
     *
     * @param array $attributes
     * @return \Fly\Database\ActiveRecord\Smart\SmartModel
     */
    public function __construct(array $attributes = array()) {

        parent::__construct($attributes);
        $this->validationErrors = new MessageBag;
    }

    /**
     * The "booting" method of the model.
     * Overrided to attach before/after method hooks into the model events.
     *
     * @see \Fly\Database\ActiveRecord\Model::boot()
     * @return void
     */
    public static function boot() {
        parent::boot();

        $myself   = get_called_class();
        $hooks    = array('before' => 'ing', 'after' => 'ed');
        $radicals = array('sav', 'validat', 'creat', 'updat', 'delet');

        foreach ($radicals as $rad) {
            foreach ($hooks as $hook => $event) {
                $method = $hook.ucfirst($rad).'e';
                if (method_exists($myself, $method)) {
                    $eventMethod = $rad.$event;
                    self::$eventMethod(function($model) use ($method){
                        return $model->$method($model);
                    });
                }
            }
        }
    }

	/**
	 * Register a validating model event with the dispatcher.
	 *
	 * @param Closure|string $callback
	 * @return void
	 */
	public static function validating($callback) {
		static::registerModelEvent('validating', $callback);
	}

	/**
	 * Register a validated model event with the dispatcher.
	 *
	 * @param Closure|string $callback
	 * @return void
	 */
	public static function validated($callback) {
		static::registerModelEvent('validated', $callback);
	}

    /**
     * Looks for the relation in the {@link $relationsData} array and does the correct magic as ActiveRecord would require
     * inside relation methods. For more information, read the documentation of the mentioned property.
     *
     * @param string $relationName the relation key, camel-case version
     * @return \Fly\Database\ActiveRecord\Relations\Relation
     * @throws \InvalidArgumentException when the first param of the relation is not a relation type constant,
     *      or there's one or more arguments missing
     * @see SmartModel::relationsData
     */
    protected function handleRelationalArray($relationName) {
        $relation     = static::$relationsData[$relationName];
        $relationType = $relation[0];
        $errorHeader  = "Relation '$relationName' on model '".get_called_class();

        if (!in_array($relationType, static::$relationTypes)) {
            throw new \InvalidArgumentException($errorHeader.
            ' should have as first param one of the relation constants of the SmartModel class.');
        }
        if (!isset($relation[1]) && $relationType != self::MORPH_TO) {
            throw new \InvalidArgumentException($errorHeader.
            ' should have at least two params: relation type and classname.');
        }
        if (isset($relation[1]) && $relationType == self::MORPH_TO) {
            throw new \InvalidArgumentException($errorHeader.
            ' is a morphTo relation and should not contain additional arguments.');
        }

        $verifyArgs = function (array $opt, array $req = array()) use ($relationName, &$relation, $errorHeader) {
            $missing = array('req' => array(), 'opt' => array());

            foreach (array('req', 'opt') as $keyType) {
                foreach ($$keyType as $key) {
                    if (!array_key_exists($key, $relation)) {
                        $missing[$keyType][] = $key;
                    }
                }
            }

            if ($missing['req']) {
                throw new \InvalidArgumentException($errorHeader.'
                    should contain the following key(s): '.join(', ', $missing['req']));
            }
            if ($missing['opt']) {
                foreach ($missing['opt'] as $include) {
                    $relation[$include] = null;
                }
            }
        };

        switch ($relationType) {
            case self::HAS_ONE:
            case self::HAS_MANY:
            case self::BELONGS_TO:
                $verifyArgs(array('foreignKey'));
                return $this->$relationType($relation[1], $relation['foreignKey']);

            case self::BELONGS_TO_MANY:
                $verifyArgs(array('table', 'foreignKey', 'otherKey'));
                $relationship = $this->$relationType($relation[1], $relation['table'], $relation['foreignKey'], $relation['otherKey']);
                if(isset($relation['pivotKeys']) && is_array($relation['pivotKeys']))
                    $relationship->withPivot($relation['pivotKeys']);
                if(isset($relation['timestamps']) && $relation['timestamps']==true)
                    $relationship->withTimestamps();
                return $relationship;

            case self::MORPH_TO:
                $verifyArgs(array('name', 'type', 'id'));
                return $this->$relationType($relation['name'], $relation['type'], $relation['id']);

            case self::MORPH_ONE:
            case self::MORPH_MANY:
                $verifyArgs(array('type', 'id'), array('name'));
                return $this->$relationType($relation[1], $relation['name'], $relation['type'], $relation['id']);
        }
    }

    /**
     * Handle dynamic method calls into the method.
     * Overrided from {@link ActiveRecord\Model} to implement recognition of the {@link $relationsData} array.
     *
     * @param  string $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters) {
        if (array_key_exists($method, static::$relationsData)) {
            return $this->handleRelationalArray($method);
        }

        return parent::__call($method, $parameters);
    }


	/**
	 * Define an inverse one-to-one or many relationship.
	 * Overriden from {@link ActiveRecord\Model} to allow the usage of the intermediary methods to handle the {@link
	 * $relationsData} array.
	 *
	 * @param  string  $related
	 * @param  string  $foreignKey
	 * @param  string  $otherKey
	 * @return \Fly\Database\ActiveRecord\Relations\BelongsTo
	 */
	public function belongsTo($related, $foreignKey = NULL, $otherKey = NULL, $relation = NULL) {
		$backtrace = debug_backtrace(false);
		$caller = ($backtrace[1]['function'] == 'handleRelationalArray')? $backtrace[3] : $backtrace[1];

		// If no foreign key was supplied, we can use a backtrace to guess the proper
		// foreign key name by using the name of the relationship function, which
		// when combined with an "_id" should conventionally match the columns.
		$relation = $caller['function'];

		if (is_null($foreignKey)) {
			$foreignKey = snake_case($relation).'_id';
		}

		// Once we have the foreign key names, we'll just create a new ActiveRecord\Model query
		// for the related models and returns the relationship instance which will
		// actually be responsible for retrieving and hydrating every relations.
		$instance = new $related;
		
		$otherKey = $otherKey ?: $instance->getKeyName();
		
		$query = $instance->newQuery();

		return new BelongsTo($query, $this, $foreignKey, $otherKey, $relation);
	}

	/**
	 * Define an polymorphic, inverse one-to-one or many relationship.
	 * Overriden from {@link ActiveRecord\Model} to allow the usage of the intermediary methods to handle the {@link
	 * $relationsData} array.
	 *
	 * @param  string  $name
	 * @param  string  $type
	 * @param  string  $id
	 * @return \Fly\Database\ActiveRecord\Relations\BelongsTo
	 */
	public function morphTo($name = null, $type = null, $id = null) {
		// If no name is provided, we will use the backtrace to get the function name
		// since that is most likely the name of the polymorphic interface. We can
		// use that to get both the class and foreign key that will be utilized.
		if (is_null($name))
		{
			$backtrace = debug_backtrace(false);
			$caller = ($backtrace[1]['function'] == 'handleRelationalArray')? $backtrace[3] : $backtrace[1];

			$name = snake_case($caller['function']);
		}

		// Next we will guess the type and ID if necessary. The type and IDs may also
		// be passed into the function so that the developers may manually specify
		// them on the relations. Otherwise, we will just make a great estimate.
		list($type, $id) = $this->getMorphs($name, $type, $id);

		$class = $this->$type;

		return $this->belongsTo($class, $id);
	}

    /**
     * Get an attribute from the model.
     * Overrided from {@link ActiveRecord\Model} to implement recognition of the {@link $relationsData} array.
     *
     * @param  string $key
     * @return mixed
     */
    public function getAttribute($key) {
        $attr = parent::getAttribute($key);

        if ($attr === null) {
            $camelKey = camel_case($key);
            if (array_key_exists($camelKey, static::$relationsData)) {
                $this->relations[$key] = $this->$camelKey()->getResults();
                return $this->relations[$key];
            }
        }

        return $attr;
    }


    /**
     * Instatiates the validator used by the validation process
     *
     * @param $data
     * @param $rules
     * @param $customMessages
     * @return \Fly\Validation\Validator
     */
    protected static function makeValidator($data, $rules, $customMessages) {
        return Validator::make($data, $rules, $customMessages);
    }

    /**
     * Validate the model instance
     *
     * @param array $rules          Validation rules
     * @param array $customMessages Custom error messages
     * @return bool
     * @throws InvalidModelException
     */
    public function validate(array $rules = array(), array $customMessages = array()) {
        if ($this->fireModelEvent('validating') === false) {
            if ($this->throwOnValidation) {
                throw new InvalidModelException($this);
            } else {
                return false;
            }
        }

        // check for overrides, then remove any empty rules
        $rules = (empty($rules))? static::$rules : $rules;
        foreach ($rules as $field => $rls) {
            if ($rls == '') {
                unset($rules[$field]);
            }
        }

        if (empty($rules)) {
            $success = true;
        } else {
			$customMessages = (empty($customMessages))? static::$customMessages : $customMessages;

			if ($this->forceEntityHydrationFromInput || (empty($this->attributes) && $this->autoHydrateEntityFromInput)) {
				$this->fill(Input::all());
			}

			$data = $this->getAttributes(); // the data under validation

			// perform validation
			$validator = static::makeValidator($data, $rules, $customMessages);
			$success   = $validator->passes();

			if ($success) {
				// if the model is valid, unset old errors
				if ($this->validationErrors->count() > 0) {
					$this->validationErrors = new MessageBag;
				}
			} else {
				// otherwise set the new ones
				$this->validationErrors = $validator->messages();

				// stash the input to the current session
				if (!self::$externalValidator && Input::hasSession()) {
					Input::flash();
				}
			}
		}

        $this->fireModelEvent('validated', false);

	    if (!$success && $this->throwOnValidation) {
		    throw new InvalidModelException($this);
	    }

        return $success;
    }

    /**
     * Save the model to the database. Is used by {@link save()} and {@link forceSave()} as a way to DRY code.
     *
     * @param array   $rules
     * @param array   $customMessages
     * @param array   $options
     * @param Closure $beforeSave
     * @param Closure $afterSave
     * @param bool    $force          Forces saving invalid data.
     * 
     * @return bool
     * @see SmartModel::save()
     * @see SmartModel::forceSave()
     */
    protected function internalSave(array $rules = array(),
        array $customMessages = array(),
        array $options = array(),
        Closure $beforeSave = null,
        Closure $afterSave = null,
        $force = false
    ) {
        if ($beforeSave) {
            self::saving($beforeSave);
        }
        if ($afterSave) {
            self::saved($afterSave);
        }

        $valid = $this->validate($rules, $customMessages);

        if ($force || $valid) {
            return $this->performSave($options);
        } else {
            return false;
        }
    }

    /**
     * Save the model to the database.
     *
     * @param array   $rules
     * @param array   $customMessages
     * @param array   $options
     * @param Closure $beforeSave
     * @param Closure $afterSave
     *
     * @return bool
     * @see SmartModel::forceSave()
     */
    public function save(array $rules = array(),
        array $customMessages = array(),
        array $options = array(),
        Closure $beforeSave = null,
        Closure $afterSave = null
    ) {
        return $this->internalSave($rules, $customMessages, $options, $beforeSave, $afterSave, false);
    }

    /**
     * Force save the model even if validation fails.
     *
     * @param array   $rules
     * @param array   $customMessages
     * @param array   $options
     * @param Closure $beforeSave
     * @param Closure $afterSave
     * @return bool
     * @see SmartModel::save()
     */
    public function forceSave(array $rules = array(),
        array $customMessages = array(),
        array $options = array(),
        Closure $beforeSave = null,
        Closure $afterSave = null
    ) {
        return $this->internalSave($rules, $customMessages, $options, $beforeSave, $afterSave, true);
    }


    /**
     * Add the basic purge filters
     *
     * @return void
     */
    protected function addBasicPurgeFilters() {
        if ($this->purgeFiltersInitialized) {
            return;
        }

        $this->purgeFilters[] = function ($attributeKey) {
            // disallow password confirmation fields
            if (Str::endsWith($attributeKey, '_confirmation')) {
                return false;
            }

            // "_method" is used by Fly\Routing\Router to simulate custom HTTP verbs
            if (strcmp($attributeKey, '_method') === 0) {
                return false;
            }

            // "_token" is used by Fly\Html\FormBuilder to add CSRF protection
            if (strcmp($attributeKey, '_token') === 0) {
                return false;
            }

            return true;
        };

        $this->purgeFiltersInitialized = true;
    }

    /**
     * Removes redundant attributes from model
     *
     * @param array $array Input array
     * @return array
     */
    protected function purgeArray(array $array = array()) {

        $result = array();
        $keys   = array_keys($array);

        $this->addBasicPurgeFilters();

        if (!empty($keys) && !empty($this->purgeFilters)) {
            foreach ($keys as $key) {
                $allowed = true;

                foreach ($this->purgeFilters as $filter) {
                    $allowed = $filter($key);

                    if (!$allowed) {
                        break;
                    }
                }

                if ($allowed) {
                    $result[$key] = $array[$key];
                }
            }
        }

        return $result;
    }

    /**
     * Saves the model instance to database. If necessary, it will purge the model attributes
     * of unnecessary fields. It will also replace plain-text password fields with their hashes.
     *
     * @param array $options
     * @return bool
     */
    protected function performSave(array $options) {

        if ($this->autoPurgeRedundantAttributes) {
            $this->attributes = $this->purgeArray($this->getAttributes());
        }

        if ($this->autoHashPasswordAttributes) {
            $this->attributes = $this->hashPasswordAttributes($this->getAttributes(), static::$passwordAttributes);
        }

        return parent::save($options);
    }

    /**
     * Get validation error message collection for the Model
     *
     * @return \Fly\Support\MessageBag
     */
    public function errors() {
        return $this->validationErrors;
    }

    /**
     * Automatically replaces all plain-text password attributes (listed in $passwordAttributes)
     * with hash checksum.
     *
     * @param array $attributes
     * @param array $passwordAttributes
     * @return array
     */
    protected function hashPasswordAttributes(array $attributes = array(), array $passwordAttributes = array()) {

        if (empty($passwordAttributes) || empty($attributes)) {
            return $attributes;
        }

        $result = array();
        foreach ($attributes as $key => $value) {

            if (in_array($key, $passwordAttributes) && !is_null($value)) {
                if ($value != $this->getOriginal($key)) {
                    $result[$key] = Hash::make($value);
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * When given an ID and a FlyPHP validation rules array, this function
     * appends the ID to the 'unique' rules given. The resulting array can
     * then be fed to a SmartModel save so that unchanged values
     * don't flag a validation issue. Rules can be in either strings
     * with pipes or arrays, but the returned rules are in arrays.
     *
     * @param int   $id
     * @param array $rules
     *
     * @return array Rules with exclusions applied
     */
    protected function buildUniqueExclusionRules(array $rules = array()) {
      
        if (!count($rules))
          $rules = static::$rules;

        foreach ($rules as $field => &$ruleset) {
            // If $ruleset is a pipe-separated string, switch it to array
            $ruleset = (is_string($ruleset))? explode('|', $ruleset) : $ruleset;

            foreach ($ruleset as &$rule) {
              if (strpos($rule, 'unique') === 0) {
                $params = explode(',', $rule);

                $uniqueRules = array();
                
                // Append table name if needed
                $table = explode(':', $params[0]);
                if (count($table) == 1)
                  $uniqueRules[1] = $this->table;
                else
                  $uniqueRules[1] = $table[1];
               
                // Append field name if needed
                if (count($params) == 1)
                  $uniqueRules[2] = $field;
                else
                  $uniqueRules[2] = $params[1];

                if (isset($this->primaryKey)) {
                  $uniqueRules[3] = $this->{$this->primaryKey};
                  $uniqueRules[4] = $this->primaryKey;
                }
                else {
                  $uniqueRules[3] = $this->id;
                }
       
                $rule = 'unique:' . implode(',', $uniqueRules);  
              } // end if strpos unique
              
            } // end foreach ruleset
        }
        
        return $rules;
    }

    /**
     * Update a model, but filter uniques first to ensure a unique validation rule
     * does not fire
     *
     * @param array   $rules
     * @param array   $customMessages
     * @param array   $options
     * @param Closure $beforeSave
     * @param Closure $afterSave
     * @return bool
     */
    public function updateUniques(array $rules = array(),
        array $customMessages = array(),
        array $options = array(),
        Closure $beforeSave = null,
        Closure $afterSave = null
    ) {
        $rules = $this->buildUniqueExclusionRules($rules);
        
        return $this->save($rules, $customMessages, $options, $beforeSave, $afterSave);
    }

	/**
	 * Validates a model with unique rules properly treated.
	 *
	 * @param array $rules Validation rules
	 * @param array $customMessages Custom error messages
	 * @return bool
	 * @see SmartModel::validate()
	 */
	public function validateUniques(array $rules = array(), array $customMessages = array()) {
		$rules = $this->buildUniqueExclusionRules($rules);
		return $this->validate($rules, $customMessages);
	}

    /**
     * Find a model by its primary key.
     * If {@link $throwOnFind} is set, will use {@link findOrFail} internally.
     *
     * @param  mixed $id
     * @param  array $columns
     * @return SmartModel|Collection
     */
    public static function find($id, $columns = array('*')) {
        $debug = debug_backtrace(false);

        if (static::$throwOnFind && $debug[1]['function'] != 'findOrFail') {
            return self::findOrFail($id, $columns);
        } else {
            return parent::find($id, $columns);
        }
    }

	/**
	 * Get a new query builder for the model's table.
	 * Overriden from {@link \Model\ActiveRecord} to allow for usage of {@link throwOnFind}.
	 *
	 * @param  bool  $excludeDeleted
	 * @return \Fly\Database\ActiveRecord\Builder
	 */
	public function newQuery($excludeDeleted = true) {
		$builder = new SmartBuilder($this->newBaseQueryBuilder());
		$builder->throwOnFind = static::$throwOnFind;

		// Once we have the query builders, we will set the model instances so the
		// builder can easily access any information it may need from the model
		// while it is constructing and executing various queries against it.
		$builder->setModel($this)->with($this->with);

		if ($excludeDeleted and $this->softDelete)
		{
			$builder->whereNull($this->getQualifiedDeletedAtColumn());
		}

		return $builder;
	}
}
