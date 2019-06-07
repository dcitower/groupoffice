<?php

namespace go\core\orm;

use Exception;
use go\core\App;
use go\core\data\Model;
use go\core\db\Column;
use go\core\db\Criteria;
use go\core\orm\Query;
use go\core\event\EventEmitterTrait;
use go\core\fs\Blob;
use go\core\util\DateTime;
use go\core\util\StringUtil;
use go\core\validate\ErrorCode;
use go\core\validate\ValidationTrait;
use PDO;
use PDOException;
use ReflectionClass;
use function GO;
use go\core\data\ModelHelper;

/**
 * Property model
 * 
 * Note: when changing database columns you need to run install/upgrade.php to 
 * rebuild the cache.
 * 
 * A property belongs to a {@see Entity}
 * 
 * It can only be saved, deleted or found through an {@see Entity}
 * 
 * @method static fetch() Not really a method but helps the IDE to autocomplete when using Property::find()->fetch();
 */
abstract class Property extends Model {

	use ValidationTrait;	
	
	use EventEmitterTrait;
	
	/**
	 * Fires when the mapping is defined. Other modules can add new properties
	 * 
	 * The event listener is called with the {@see Mapping} object.
	 */
	const EVENT_MAPPING = "mapping";

	/**
	 * Returns true is the model is new and not saved to the database yet.
	 * 
	 * @var boolean 
	 */
	private $isNew;

	/**
	 * Associative array with property name => old value. 
	 * @var array
	 */
	private $oldProps = [];

	/**
	 * The properties that were fetched by find
	 * 
	 * @var string[] 
	 */
	protected $fetchProperties;

	/**
	 * Keeps record of all the saved properties so we can commit or rollback after save.
	 * @var Property[]
	 */
	private $savedPropertyRelations = [];
	
	
	/**
	 * Holds primary keys per table alias. Used to track new state of records.
	 * 
	 * @example
	 * ```
	 * ['tableAlias' => ['id' => 1]]
	 * ```
	 * @var array
	 */
	private $primaryKeys = []; 



	/**
	 * Holds dynamic properties mapped by other modules with the EVENT_MAPPING
	 */
	private $dynamicProperties = [];

	/**
	 * Constructor
	 * 
	 * @param boolean $isNew Indicates if this model is saved to the database.
	 * @param string[] $fetchProperties The properties that were fetched by find. If empty then all properties are fetched
	 */
	public function __construct($isNew = true, $fetchProperties = []) {
		$this->isNew = $isNew;

		if (empty($fetchProperties)) {
			$fetchProperties = static::getDefaultFetchProperties();
		}

		$this->fetchProperties = $fetchProperties;
		
		$this->initDatabaseColumns($this->isNew);
		$this->initRelations();
		$this->trackModifications();
		$this->init();
	}


	/**
	 * Loads defaults from the database or casts the database value to the right type in PHP
	 * 
	 * @param boolean $loadDefault
	 */
	private function initDatabaseColumns($loadDefault) {
		foreach ($this->getMapping()->getTables() as $table) {
			foreach ($table->getColumns() as $colName => $column) {
				if (in_array($colName, $this->fetchProperties)) {
					$this->$colName = $loadDefault ? $column->castFromDb($column->default) : $column->castFromDb($this->$colName);
				}
			}
			foreach($table->getConstantValues() as $colName => $value) {
				if (in_array($colName, $this->fetchProperties)) {
					$this->$colName  = $value;
				}
			}
		}
	}

	/**
	 * Returns all relations that were requested in "fetchProperties".
	 * 
	 * @return Relation[]
	 */
	private function getFetchedRelations() {

		$fetchedRelations = [];

		$relations = $this->getMapping()->getRelations();
		foreach ($relations as $relation) {
			if (in_array($relation->name, $this->fetchProperties)) {
				$fetchedRelations[] = $relation;
			}
		}
		
		return $fetchedRelations;
	}

	/**
	 * Fetches the related properties when requested
	 */
	private function initRelations() {		
		foreach ($this->getFetchedRelations() as $relation) {
			$cls = $relation->entityName;

			$where = [];
			foreach ($relation->keys as $from => $to) {
				$where[$to] = $this->$from;
			}			
			if($relation->mapped) {
				$values = $cls::internalFind()->andWhere($where)->all();
				if(!count($values)) {
					$values = new \stdClass;
				} else{
					$o = [];
					foreach($values as $v) {
						$o[$this->buildMapKey($v, $relation)] = $v;
					}
					$this->{$relation->name} = $o;
				}
			} else if ($relation->many) {
				$props = $this->isNew() ? [] : $cls::internalFind()->andWhere($where)->all();
				$this->{$relation->name} = $props;
			} else if(!$this->isNew()){
				$prop = $cls::internalFind()->andWhere($where)->single();				
				$this->{$relation->name} = $prop ? $prop : null;
			}
		}
	}

	/**
	 * Build a key of the primary keys but omit the key from the releation because it's not needed as it's a property,
	 * 
	 */
	private function buildMapKey(Property $v, Relation $relation) {
		$pk = array_diff($v->getPrimaryKey(), array_values($relation->keys));

		$id = [];
		foreach($pk as $field) {
			$id[] = $v->$field;
		}

		return implode('-', $id);
	}
	
	/**
	 * Copies all properties so isModified() can detect changes.
	 */
	private function trackModifications() {
		//Watch db cols and relations
		$watch = array_keys($this->getMapping()->getProperties());
		
		//watch other props
		$watch = array_merge($watch, static::getPropNames());
		$watch = array_unique($watch);

		foreach ($watch as $propName) {
			$this->oldProps[$propName] = $this->$propName;
		}
	}

	/**
	 * Override this function to initialize your model
	 */
	protected function init() {
		
	}

	/**
	 * List of tables this entity uses
	 * 
	 * Note: When making changes to the mapping you need to run install/upgrade.php
	 * to rebuild the cache!
	 * 
	 * All tables must have identical primary keys.
	 * eg 
	 * 
	 * ````
	 * 	protected static function defineMapping() {
	 * 		return parent::defineMapping()
	 * 						->addTable('test_a', 'a')
	 * 						->addProperty('sumOfTableBIds', "SUM(b.id)", (new Query())->join('test_b', 'bc', 'bc.id=a.id')->groupBy(['a.id']))
	 * 						->addRelation('hasMany', AHasMany::class, ['id' => 'aId'], true)
	 * 						->addRelation('hasOne', AHasOne::class, ['id' => 'aId'], false);
	 * 	}
	 * ````
	 * 
	 * @return Mapping
	 */
	protected static function defineMapping() {
		return new Mapping(static::class);
	}

	/**
	 * Returns the mapping object that is defined in defineMapping()
	 * 
	 * @return Mapping;
	 */
	public final static function getMapping() {

		$cls = static::class;
		
		$cacheKey = 'mapping-' . str_replace('\\', '-', $cls);
		
		$mapping = GO()->getCache()->get($cacheKey);
		if(!$mapping) {			
			$mapping = static::defineMapping();			
			if(!static::fireEvent(self::EVENT_MAPPING, $mapping)) {
				throw new \Exception("Mapping event failed!");
			}
			
			GO()->getCache()->set($cacheKey, $mapping);
		}

		return $mapping;
	}

	/**
	 * Get ID which is are the primary keys combined with a "-".
	 * 
	 * @return string eg. "1" or with multiple keys: "1-2"
	 */
	public function id() {		
		$keys = $this->primaryKeyValues();
		return count($keys) > 1 ? implode("-", array_values($keys)) : array_values($keys)[0];
	}
	
	public static function getApiProperties() {		
		$cacheKey = 'property-getApiProperties-' . str_replace('\\', '-', static::class);
		
		$props = GO()->getCache()->get($cacheKey);
		
		if(!$props) {
		
			$props = parent::getApiProperties();

			//add dynamic relations		
			foreach(static::getMapping()->getProperties() as $propName => $type) {
				//do property_exists because otherwise it will add protected properties too.
				if(!isset($props[$propName])) {
					$props[$propName] = ['setter' => false, 'getter' => false, 'access' => \ReflectionProperty::IS_PUBLIC, 'dynamic' => true];
				}
			}

			if(method_exists(static::class, 'getCustomFields')) {
				$props['customFields'] = ['setter' => true, 'getter' => true, 'access' => null];
			}
			
			GO()->getCache()->set($cacheKey, $props);
		}
		return $props;
	}
	
	public function &__get($name) {
		if(static::getMapping()->hasProperty($name)) {
			if(!isset($this->dynamicProperties[$name])) {
				$this->dynamicProperties[$name] = null;
			}
			return $this->dynamicProperties[$name];
		}		
		throw new Exception("Can't get not existing property '$name' in '".static::class."'");			
		
	}
	
	public function __isset($name) {
			
		if(static::getMapping()->hasProperty($name)) {
			return isset($this->dynamicProperties[$name]);
		}
		return false;
	}
	
	public function __set($name, $value) {		
		if($this->setPrimaryKey($name, $value)) {
			return ;
		}

		//Support for dynamically mapped props via EVENT_MAP
		$props = static::getApiProperties();
		if(isset($props[$name]) && !empty($props[$name]['dynamic'])) {			
			$this->dynamicProperties[$name] = $value;
		} else
		{
			throw new Exception("Can't set not existing property '$name' in '".static::class."'");
		}
	}
	
	private function setPrimaryKey($name, $value) {
		if(strpos($name, ".") === false) {
			return false;
		}
		//this is a primary key value. See buildSelect()
		$parts = explode(".", $name);
		if(!isset($this->primaryKeys[$parts[0]])) {
			$this->primaryKeys[$parts[0]] = [];
		}
		$this->primaryKeys[$parts[0]][$parts[1]] = $value;

		return true;
	}

	/**
	 * Get the properties to fetch when using the find() method.
	 * These properties will be preloaded including related properties from other
	 * tables. They will also be returned to the client.
	 * 
	 * @return string[]
	 */
	protected static function getDefaultFetchProperties() {
		
		$cacheKey = 'property-getDefaultFetchProperties-' . str_replace('\\', '-', static::class);
		
		$props = GO()->getCache()->get($cacheKey);
		
		if(!$props) {
			$props = array_filter(static::getReadableProperties(), function($propName) {
				return !in_array($propName, ['modified', 'oldValues', 'validationErrors']);
			});

			GO()->getCache()->set($cacheKey, $props);
		}
		return $props;
	}	

	/**
	 * Find entities
	 * 
	 * @return static|Query
	 */
	protected static function internalFind(array $fetchProperties = []) {
		$tables = self::getMapping()->getTables();

		$mainTableName = array_keys($tables)[0];
		
		if (empty($fetchProperties)) {
			$fetchProperties = static::getDefaultFetchProperties();
		}

		$query = (new Query())
						->from($tables[$mainTableName]->getName(), $tables[$mainTableName]->getAlias())
						->fetchMode(PDO::FETCH_CLASS, static::class, [false, $fetchProperties])
						->setModel(static::class);

		self::joinAdditionalTables($tables, $query);
		self::buildSelect($query, $fetchProperties);

		return $query;
	}

	/**
	 * Find by ID's. 
	 * 
	 * It will search on the primary key field of the first mapped table.
	 * 
	 * @exanple
	 * ```
	 * $note = Note::findById(1);
	 * 
	 * //If a key has more than one column they can be combined with a "-". eg. "1-2"
	 * $models = ModelWithDoublePK::findById("1-1");
	 * ```
	 * 
	 * @param string $id 
	 * @param string[] $properties
	 * @return static
	 * @throws Exception
	 */
	protected static function internalFindById($id, array $properties = []) {
		$tables = static::getMapping()->getTables();
		$primaryTable = array_shift($tables);
		$keys = $primaryTable->getPrimaryKey();
		
		$query = static::internalFind($properties);		
		
		$ids = explode('-', $id);
		$keys = array_combine($keys, $ids);
		$query->where($keys);
		
		return $query->single();
	}

	protected static $propNames = [];

	private static function getPropNames() {
		$cls = static::class;
		$cacheKey = $cls . '-getPropNames';

		$propNames = GO()->getCache()->get($cacheKey);

		if (!$propNames) {
			$reflectionClass = new ReflectionClass($cls);
			$props = $reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED);
			$propNames = [];
			foreach ($props as $prop) {
				if(!$prop->isStatic()) {
					$propNames[] = $prop->getName();
				}
			}
			
			//add dynamic relations		
			foreach(static::getMapping()->getProperties() as $name => $type) {
				if(!in_array($name, $propNames)) {
					$propNames[] = $name;
				}
			}

			GO()->getCache()->set($cacheKey, $propNames);
		}

		return $propNames;
	}

	/**
	 * Evaluates the given fetchProperties and configures the query object to fetch them.
	 * 
	 * @param Query $query
	 * @param array $fetchProperties
	 */
	private static function buildSelect(Query $query, array $fetchProperties) {

		foreach (self::getMapping()->getTables() as $table) {
			
			if($table->isUserTable && !GO()->getUserId()) {
				continue;
			}		
			
			foreach($table->getMappedColumns() as $column) {		
				if($column->primary || in_array($column->name, $fetchProperties) || static::isProtectedProperty($column->name)) {
					$query->select($table->getAlias() . "." . $column->name, true);				
				}
			}
			
			//also select primary key values separately to check if tables were new when saving. They are stored in $this->primaryKeys when they go through the __set function.
			foreach($table->getPrimaryKey() as $pk) {				
				//$query->select("alias.id AS `alias.userId`");
				$query->select($table->getAlias() . "." . $pk . " AS `" . $table->getAlias() . "." . $pk ."`", true);				
			}

			if(!empty($table->getConstantValues())) {
				$query->andWhere($table->getConstantValues());
			}
		}

		$mappedQuery = static::getMapping()->getQuery();
		if (isset($mappedQuery)) {
			$query->mergeWith($mappedQuery);
		}
	}

	/**
	 * 
	 * @param MappedTable $tables
	 * @param Query $query
	 * 
	 * @todo implement fetch properties
	 */
	private static function joinAdditionalTables(array $tables, Query $query) {
		$first = array_shift($tables);

		$alias = $first->getAlias();
		foreach ($tables as $joinedTable) {
			static::joinTable($alias, $joinedTable, $query);
			$alias = $joinedTable->getAlias();
		}
	}
	
	private static function joinTable($lastAlias, MappedTable $joinedTable, Query $query) {
		foreach ($joinedTable->getKeys() as $from => $to) {
			if (!isset($on)) {
				$on = "";
			} else {
				$on .= " AND ";
			}
			
			if(strpos($from, '.') === false) {
				$from = $lastAlias . "." . $from;
			}
			
			if(strpos($to, '.') === false) {
				$to = $joinedTable->getAlias() . "." . $to;
			}
	
			$on .= $from . ' = ' . $to;
		}

		if($joinedTable->isUserTable) {
			if(!GO()->getUserId()) {
				//throw new \Exception("Can't join user table when not authenticated");
				GO()->debug("Can't join user table when not authenticated");
				return;
			}
			$on .= " AND " . $joinedTable->getAlias() . ".userId = " . GO()->getUserId();
		}

		if(!empty($joinedTable->getConstantValues())) {
			$on = Criteria::normalize($on)->andWhere($joinedTable->getConstantValues());
		}
		$query->join($joinedTable->getName(), $joinedTable->getAlias(), $on, "LEFT");
	}

	/**
	 * Get all the modified properties with their new and old values.
	 * 
	 * Only database columns and relations are tracked. Not the getters and setters.
	 * 
	 * @param array $properties If given only these properties will be checked for modifications.
	 * @return array ["propName" => [newval, oldval]]
	 */
	public function getModified(array $properties = []) {
		$modified = [];
		foreach ($this->oldProps as $key => $oldValue) {		
			if (!empty($properties) && !in_array($key, $properties)) {
				continue;
			}
			
			$newValue = $this->{$key};
			
			if($newValue instanceof Property) {
				if($newValue->isModified()) {
					$modified[$key] = [$newValue, null];
				}
			} else 
			{
				if ($newValue !== $oldValue) {
					$modified[$key] = [$newValue, $oldValue];
				} else if(is_array($newValue) && isset($newValue[0]) && $newValue[0] instanceof Property) {
					// Array comparison above might return false because the array contains identical objects but the objects itself might have changed.
					foreach($newValue as $v) {
						if($v->isModified()) {
							$modified[$key] = [$newValue, $oldValue];
							break;
						}
					}
				}
			}			
		}

		return $modified;
	}

	/**
	 * Check if entity or any of given property list is modified
	 * 
	 * Only database columns and relations are tracked. Not the getters and setters.
	 * 
	 * @param array|string $properties If empty then all properties are checked.
	 * @return boolean
	 */
	public function isModified($properties = []) {
		
		if(!is_array($properties)) {
			$properties = [$properties];
		}

		foreach ($this->oldProps as $key => $oldValue) {
			if (!empty($properties) && !in_array($key, $properties)) {
				continue;
			}
			$newValue = $this->{$key};
			
			if ($newValue !== $oldValue) {
				return true;
			}
			
			if($newValue instanceof Property && $newValue->isModified()) {
				return true;
			}
			
			if(is_array($newValue) && isset($newValue[0]) && $newValue[0] instanceof Property) {
				// Array comparison above might return false because the array contains identical objects but the objects itself might have changed.
				foreach($newValue as $v) {
					if($v->isModified()) {
						return true;
					}
				}
			}
		}
		
		return false;
	}
	
	/**
	 * Get a property value before it was modified
	 * 
	 * @param string $propName
	 * @return mixed
	 * @throws Exception
	 */
	public function getOldValue($propName) {
		if(!array_key_exists($propName, $this->oldProps)){
			throw new \Exception("Property " . $propName . " does not exist");
		}
		return $this->oldProps[$propName];
	}
	
	/**
	 * Get old values before they were modified
	 * 
	 * @return array [Name => value]
	 */
	public function getOldValues() {
		return $this->oldProps;
	}
	
	/**
	 * Saves the model and property relations to the database
	 * 
	 * Important: When you override this make sure you call this parent function first so
	 * that validation takes place!
	 * 
	 * @return boolean
	 */
	protected function internalSave() {
		
		if (!$this->validate()) {
			return false;
		}
		
		$modified = $this->getModified();				
		
		// make sure auto incremented values come first
		$tables = $this->getMapping()->getTables();
		usort($tables, function(\go\core\db\Table $a, \go\core\db\Table $b) {
			$aHasAI = $a->getAutoIncrementColumn();
			$bHasAI = $b->getAutoIncrementColumn();
			if($aHasAI && !$bHasAI) {
				return -1;
			}
			
			if($bHasAI && !$aHasAI) {
				return 1;
			}
			
			return 0;
			
		});
		
		foreach ($tables as $table) {			
			if (!$this->saveTable($table, $modified)) {				
				return false;
			}
		}
		
		$this->checkBlobs();

		if (!$this->saveRelatedProperties()) {
			return false;
		}

		return true;
	}
	
	/**
	 * Get all columns containing blob id's
	 * 
	 * @return Column[]
	 */
	private function getBlobColumns() {
		
		$refs = Blob::getReferences();
		$cols = [];
		foreach($this->getMapping()->getTables() as $table) {
			foreach($table->getMappedColumns() as $col) {
				foreach($refs as $r) {
					if($r['table'] == $table->getName() && $r['column'] == $col->name) {
						$cols[] = $col;
					}
				}
			}
		}
		
		return $cols;
	}
	
	private function checkBlobs() {
		$blobs = [];
		foreach($this->getBlobColumns() as $col) {
			if($this->isDeleted) {
				$blobId = $this->{$col->name};
				
				if(isset($blobId)) {
					$blobs[] = $blobId;
				}
				
			} else if($this->isModified([$col->name])) {				
				
				$mod = array_values($this->getModified([$col->name]))[0];
				
				if(isset($mod[0])) {
					$blobs[] = $mod[0];
				}
				
				if(isset($mod[1])) {
					$blobs[] = $mod[1];
				}
			}
		}
		
		foreach($blobs as $id) {
			Blob::findById($id)->setStaleIfUnused();
		}
	}
	
	/**
	 * Sets some default values such as modifiedAt and modifiedBy
	 */
	private function setSaveProps(\go\core\db\Table $table, $modifiedForTable) {
		
		if($table->getColumn("modifiedBy") && !isset($modifiedForTable["modifiedBy"])) {
			$this->modifiedBy = $modifiedForTable['modifiedBy'] = $this->getDefaultCreatedBy();
		}
		
		if($table->getColumn("modifiedAt") && !isset($modifiedForTable["modifiedAt"])) {
			$this->modifiedAt = $modifiedForTable['modifiedAt'] = new DateTime();
		}
		
		if(!$this->isNew()) {
			return $modifiedForTable;
		}
		
		if($table->getColumn("createdAt") && !isset($modifiedForTable["createdAt"])) {
			$this->createdAt = $modifiedForTable['createdAt'] = new DateTime();
		}
		
		if($table->getColumn("createdBy") && !isset($modifiedForTable["createdBy"])) {
			$this->createdBy = $modifiedForTable['createdBy']= $this->getDefaultCreatedBy();
		}
		
		return $modifiedForTable;
	}
	
	protected function getDefaultCreatedBy() {
		return !App::get()->getAuthState() || !App::get()->getAuthState()->getUserId() ? 1 : App::get()->getAuthState()->getUserId();
	}

	/**
	 * Saves all property relations
	 * 
	 * @return boolean
	 */
	private function saveRelatedProperties() {
		foreach ($this->getFetchedRelations() as $relation) {
			if ($relation->many) {
				if (!$this->saveRelatedHasMany($relation)) {
					$this->setValidationError($relation->name, ErrorCode::RELATIONAL, null, ['validationErrors' => $this->relatedValidationErrors, 'index' => $this->relatedValidationErrorIndex]);
					return false;
				}
			} else {
				if (!$this->saveRelatedHasOne($relation)) {
					$this->setValidationError($relation->name, ErrorCode::RELATIONAL, null, ['validationErrors' => $this->relatedValidationErrors]);
					return false;
				}
			}

		}
		return true;
	}

	private function saveRelatedHasOne(Relation $relation) {
		
		//remove old model if it's replaced
		$modified = $this->getModified([$relation->name]);
		if (isset($modified[$relation->name][1])) {
			if (!$modified[$relation->name][1]->internalDelete()) {
				$this->relatedValidationErrors = $modified[$relation->name][1]->getValidationErrors();
				return false;
			}
		}

		if (isset($this->{$relation->name})) {			
			$prop = $this->{$relation->name};
			$this->applyRelationKeys($relation, $prop);
			if (!$prop->internalSave()) {
				$this->relatedValidationErrors = $prop->getValidationErrors();
				return false;
			}

			$this->savedPropertyRelations[] = $this->{$relation->name};
		}

		return true;
	}
	
	/**
	 * Keeps record of the index when a related has many prop save fails. This
	 * will be returned to the client.
	 * 
	 * @var int 
	 */
	private $relatedValidationErrorIndex = 0;
	
	/**
	 * 
	 * @var array 
	 */
	private $relatedValidationErrors = [];

	private function saveRelatedHasMany(Relation $relation) {
		
		
		//remove models that are not present in	
		$modified = $this->getModified([$relation->name]);
		if(empty($modified)) {
			return true;
		}

		//copy for overloaded properties because __get can't return by reference because we also return null sometimes.
		$models = $this->{$relation->name} ?? [];		
		$this->relatedValidationErrorIndex = 0;


		if(!$relation->mapped) {
			//arrays will always be replaced entirely.
			//So we delete them with one query and set all models to be inserted as new records.
			$cls = $relation->entityName;
			$tables = $cls::getMapping()->getTables();
			$first = array_shift($tables);
			$where = [];
			foreach($relation->keys as $from => $to) {
				$where[$to] = $this->$from;
			}			
			\GO()->getDbConnection()->delete($first->getName(), $where)->execute();		
			
			//set state to new for all models. Models could have been saved if save() is called multiple times.
			$models = array_map(function($model) {
				return $model->internalCopy();
			}, $models);
			
		} else{
			if (isset($modified[$relation->name][1])) {			
				foreach ($modified[$relation->name][1] as $oldProp) {
					
					//if not in current value then delete it.
					//objects are compared by reference. 
					if (!in_array($oldProp, $models) && !$oldProp->internalDelete()) {
						$this->relatedValidationErrors = $oldProp->getValidationErrors();
						return false;
					}
				}
			}
		}
		
		$this->{$relation->name} = [];
		foreach ($models as &$newProp) {
			
			//Check for invalid input
			if(!($newProp instanceof Property)) {
				throw new \Exception("Invalid value given for '". $relation->name ."'. Should be a GO\Orm\Property");
			}
			
			$this->applyRelationKeys($relation, $newProp);
			if (!$newProp->internalSave()) {
				$this->relatedValidationErrors = $newProp->getValidationErrors();
				return false;
			}

			$this->savedPropertyRelations[] = $newProp;
			$this->relatedValidationErrorIndex++;

			if(!$relation->mapped) {
				$this->{$relation->name}[] = $newProp;
			} else
			{
				$this->{$relation->name}[$newProp->id()] = $newProp;
			}
		}	

		return true;
	}

	/**
	 * When the entity is saved and was new, the auto increment ID must be set to identifying relations
	 * 
	 * @param string $relation
	 * @param Property $property
	 */
	private function applyRelationKeys($relation, Property $property) {

		foreach ($relation->keys as $from => $to) {
			$property->$to = $this->$from;
		}
	}
	
	private function extractModifiedForTable(MappedTable $table, array $modified) {
		$modifiedForTable = [];

		$columns = $table->getColumns();
		foreach ($columns as $column) {
			if (isset($modified[$column->name])) {
				$modifiedForTable[$column->name] = $modified[$column->name][0];
			}
		}
		
		return $modifiedForTable;
	}
	
	private function recordIsNew(MappedTable $table) {		
		$primaryKeys = $table->getPrimaryKey();
		if(empty($primaryKeys)) {
			//no primary key. Always insert.
			return true;
		}
		foreach($primaryKeys as $pk) {
			if(empty($this->primaryKeys[$table->getAlias()][$pk])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Saves properties to the mapped table
	 * 
	 * @param MappedTable $table
	 * @param array $modified
	 * @return boolean
	 * @throws Exception
	 */
	private function saveTable(MappedTable $table, array &$modified) {

		if($table->isUserTable && !GO()->getAuthState()->isAuthenticated()) {
			//ignore user tables when not logged in.
			return true;
		}	

		$modifiedForTable = $this->extractModifiedForTable($table, $modified);
		$recordIsNew = $this->recordIsNew($table);
		if(!empty($modified) || $recordIsNew) {
			$modifiedForTable = $this->setSaveProps($table, $modifiedForTable);
		}

		if (empty($modifiedForTable) && !$recordIsNew) {
			return true;
		}		
		
		// if(empty($table->getPrimaryKey())) {
		// 	throw new Exception("No primary key defined for table: '" . $table->getName() . "'");
		// }
		
		try {
			if ($recordIsNew) {				
				//this if for cases when a second table extends the model but the key is not part of the properties
				//For example Password extends User but the ket "userId" of password is not part of the properties
				foreach($table->getKeys() as $from => $to) {
					$modifiedForTable[$to] = $this->{$from};
				}		
				
				if($table->isUserTable) {
					$modifiedForTable["userId"] = GO()->getUserId();
				}
				
				foreach($table->getConstantValues() as $colName => $value) {
					$modifiedForTable[$colName] = $value;
				}

				if(empty($modifiedForTable)) {
					//if there's no primary key we might get here.
					return true;
				}
				
				$stmt = App::get()->getDbConnection()->insert($table->getName(), $modifiedForTable);
				if (!$stmt->execute()) {
					throw new \Exception("Could not execute insert query");
				}

				$this->handleAutoIncrement($table, $modified);
				
				//update primary key data for new state
				$this->primaryKeys[$table->getAlias()] = [];
				foreach($table->getKeys() as $from => $to) {
					$this->primaryKeys[$table->getAlias()][$to] = $this->$from;
				}
			} else {	
				if (empty($modifiedForTable)) {
					return true;
				}
				
				$keys = $this->primaryKeys[$table->getAlias()];
				if($table->isUserTable) {
					$keys['userId'] = GO()->getUserId();
				}
				
				$stmt = App::get()->getDbConnection()->update($table->getName(), $modifiedForTable, $keys);
				if (!$stmt->execute()) {
					throw new \Exception("Could not execute update query");
				}				
//				if(!$stmt->rowCount()) {			
//					
//					throw new \Exception("No affected rows for update!");
//				}				
			}
		} catch (PDOException $e) {
			$uniqueKey = \go\core\db\Utils::isUniqueKeyException($e);
			if ($uniqueKey) {				
				$this->setValidationError($uniqueKey, ErrorCode::UNIQUE);				
				return false;
			} else {
				if(isset($stmt)) {
					GO()->error("Failed SQL: " . $stmt);
				}
				throw $e;
			}
		}

		return true;
	}

	/**
	 * Get's the auto increment ID after an insert query and sets the property in this model
	 * 
	 * @param MappedTable $table
	 * @param type $modified
	 * @throws Exception
	 */
	private function handleAutoIncrement(MappedTable $table, &$modified) {
		$aiCol = $table->getAutoIncrementColumn();

		if ($aiCol) {
			$lastInsertId = intval(App::get()->getDbConnection()->getPDO()->lastInsertId());

			if (empty($lastInsertId)) {
				throw new Exception("Auto increment column didn't increment!");
			}
			$modified[$aiCol->name] = [$lastInsertId, null];
			$this->{$aiCol->name} = $lastInsertId;
		}
	}

	/**
	 * Rollback the insert ID after a save failed
	 * 
	 * @param MappedTable $table
	 */
	private function rollBackAutoIncrement(MappedTable $table) {
		$aiCol = $table->getAutoIncrementColumn();

		if ($aiCol) {
			$this->{$aiCol->name} = null;
		}
	}

	/**
	 * Sets the isNew prop and reset oldDbProps so that the record is no longer
	 * in a modified state.
	 * This happens after all related tables and properties are saved.
	 * 
	 * @return boolean
	 */
	protected function commit() {
		
		foreach ($this->savedPropertyRelations as $property) {
			$property->commit();
		}

		$this->savedPropertyRelations = [];
		$this->isNew = false;
		$this->trackModifications();


		return true;
	}

	/**
	 * Rollback is called when something fails in the save operation.
	 * 
	 * @return boolean
	 */
	protected function rollBack() {

		foreach ($this->savedPropertyRelations as $property) {
			$property->rollBack();
		}

		$this->savedPropertyRelations = [];

		foreach ($this->getMapping()->getTables() as $table) {
			$this->rollBackAutoIncrement($table);
		}

		return true;
	}
	
	private $isDeleted = false;
	
	/**
	 * Check if this property was just deleted.
	 * 
	 * @var bool
	 */
	public function isDeleted() {
		return $this->isDeleted;
	}

	/**
	 * Delete this model
	 * 
	 * @return boolean
	 */
	protected function internalDelete() {
		$tables = $this->getMapping()->getTables();
		$primaryTable = array_shift($tables);
		$pk = [];
		foreach ($primaryTable->getPrimaryKey() as $key) {
			$pk[$key] = $this->{$key};
		}
		if(!App::get()->getDbConnection()->delete($primaryTable->getName(), $pk)->execute()) {			
			return false;
		}
		
		$this->isDeleted = true;
		
		$this->checkBlobs();
		
		return true;
	}

	private function validateTable(MappedTable $table) {		
		
		if(!$this->tableIsModified($table)) {
			// table record will not be validated and inserted if it has no modifications at all
			// todo: perhaps this should be configurable?
			return true;
		}
		
		foreach ($table->getMappedColumns() as $colName => $column) {
			//Assume constants are correct, and this makes it unessecary to declare the property
			if(array_key_exists($colName, $table->getConstantValues())) {
				continue;
			}

			if (!$this->validateColumn($column, $this->$colName)) {
				//only one error per column
				continue;
			}			
		}
	}
	
	
	private function validateColumn(Column $column, $value) {
		if (!$this->validateRequired($column)) {
			return false;
		}
		
		//Null is allowed because we checked this above.
		if(empty($value)) {
			return true;
		}

		switch ($column->dbType) {
			case 'date':
			case 'datetime':
				return $value instanceof DateTime || $value instanceof DateTimeImmutable;
				
			default:				
				return $this->validateColumnString($column, $value);		
		}
	}
	
	private function validateColumnString(Column $column, $value) {
		if(!is_scalar($value) && (!is_object($value) || !method_exists($value, '__toString'))) {
			$this->setValidationError($column->name, ErrorCode::MALFORMED, "Non scalar value given. Type: ". gettype($value));
			return false;
		} 

		if (!empty($column->length)){				
			if(StringUtil::length($value) > $column->length) {
				$this->setValidationError($column->name, ErrorCode::MALFORMED, 'Length can\'t be greater than ' . $column->length);
				return false;
			}
		}
		return true;		
	}
	
	private function tableIsModified(MappedTable $table) {
		return $this->isModified(array_keys($table->getColumns()));
	}

	private function validateRequired(Column $column) {

		if (!$column->required || $column->primary) {
			return true;
		}

		switch ($column->dbType) {

			case 'int':
			case 'tinyint':
			case 'bigint':

			case 'float':
			case 'double':
			case 'decimal':

			case 'datetime':

			case 'date':

			case 'binary':
				if (!isset($this->{$column->name})) {
					$this->setValidationError($column->name, ErrorCode::REQUIRED);
					return false;
				}
				break;
			default:
				if (empty($this->{$column->name})) {
					$this->setValidationError($column->name, ErrorCode::REQUIRED);
					return false;
				}
				break;
		}


		return true;
	}

	/**
	 * Set's validation errors on this model if there are any
	 * 
	 * Override this and implement custom validation
	 */
	protected function internalValidate() {
		foreach ($this->getMapping()->getTables() as $table) {
			$this->validateTable($table);
		}
	}

	public function toArray($properties = []) {
		if (empty($properties)) {
			$properties = $this->fetchProperties;
		}

		return parent::toArray($properties);
	}

	/**
	 * Set public properties with key value array.
	 * 
	 * This function should also normalize input when you extend this class.
	 * 
	 * For example dates in ISO format should be converted into DateTime objects
	 * and related models should be converted to an instance of their class.
	 * 
	 *
	 * @Example
	 * ```````````````````````````````````````````````````````````````````````````
	 * $model = User::findByIds([1]);
	 * $model->setValues(['username' => 'admin']);
	 * $model->save();
	 * ```````````````````````````````````````````````````````````````````````````
	 *
	 * 
	 * @param array $values  ["propNamne" => "value"]
	 * @return \static
	 */
	public function setValues(array $values) {
		foreach ($values as $propName => &$value) {
			$value = $this->normalizeValue($propName, $value);
		}

		return parent::setValues($values);
	}

	/**
	 * Set a property
	 * 
	 * @param string $name
	 * @param mixed $value
	 * @return $this
	 */
	public function setValue($name, $value) {
		$value = $this->normalizeValue($name, $value);
		ModelHelper::setValue($this, $name, $value);
		return $this;
	}

	/**
	 * Turns array values into relation models and ISO date strings into DateTime objects
	 * 
	 * @param string $propName
	 * @param mixed $value
	 * @return mixed
	 */
	private function normalizeValue($propName, $value) {
		$relation = static::getMapping()->getRelation($propName);
		if ($relation) {
			
			if(!$relation->many && isset($value) && isset($this->$propName)) {				
				//if a has one relation exists then apply the new values to the existing property instead of creating a new one.
				return $this->$propName->setValues($value);
			} else {
				if($relation->mapped) {
					return $this->patch($relation, $propName, $value);
				} else if($relation->many) {
					foreach($value as $key => $item) {
						$value[$key] = $this->internalNormalizeRelation($relation, $item);
					}
					return $value;
				} else{
					return $this->internalNormalizeRelation($relation, $value);
				}			
			}			
		}

		$column = static::getMapping()->getColumn($propName);
		if ($column) {
			return $column->normalizeInput($value);
		}

		return $value;
	}

	protected function patch(Relation $relation, $propName, $value) {
		foreach($value as $id => $patch) {
			if(!isset($patch)) {
				if(!array_key_exists($id, $this->$propName)) {
					GO()->warn("Key $id does not exist in ". static::class .'->'.$propName);
				}
				unset($this->$propName[$id]);
				continue;
			}

			$this->$propName[$id] = $this->internalNormalizeRelation($relation, $patch, $id);			 
		}

		return $this->$propName;
	}

	private function internalNormalizeRelation(Relation $relation, $value, $id = null) {
		$cls = $relation->entityName;
		if ($value instanceof $cls) {
			return $value;
		}

		if (is_array($value)) {
			$propName = $relation->name;
			if(isset($id) && isset($this->$propName[$id])) {
				$o = $this->$propName[$id];				
			} else {
				$o = new $cls;
			}
			
			$o->setValues($value);

			return $o;
		} else if (is_null($value)) {
			return null;
		} else {
			throw new Exception("Invalid value given to relation '" . $this->name . "'. Should be an array or an object of type '" . $relation->entityName . "': " . var_export($value, true));
		}
	}

	/**
	 * Returns true is the model is new and not saved to the database yet.
	 * 
	 * @return boolean
	 */
	public function isNew() {
		return $this->isNew;
	}
	
	/**
	 * Get the primary key value
	 * 
	 * @return array eg ['id' => 1]
	 */
	public function primaryKeyValues() {
		
		$keys = $this->getPrimaryKey();
		$v = [];
		foreach($keys as $key) {			
			$v[$key] = $this->$key;			
		}
		
		return $v;
	}
	
	/**
	 * Get the primary key column names.
	 * 
	 * @param boolean $withTableAlias 
	 * @return string[]
	 */
	public static function getPrimaryKey($withTableAlias = false) {
		$tables = static::getMapping()->getTables();
		$primaryTable = array_shift($tables);
		$keys = $primaryTable->getPrimaryKey();
		if(!$withTableAlias) {
			return $keys;
		}
		$keysWithAlias = [];
		foreach($keys as $key) {
			$keysWithAlias[] = $primaryTable->getAlias() . '.' . $key;
		}
		return $keysWithAlias;
	}
	
	/**
	 * Checks if the given property or entity is equal to this
	 * 
	 * @param self $property
	 * @return boolean
	 */
	public function equals($property) {
		if(get_class($property) != get_class($this)) {
			return false;
		}
		
		if($property->isNew() || $this->isNew()) {
			return false;
		}
		
		$pk1 = $this->primaryKeyValues();
		$pk2 = $property->primaryKeyValues();
		
		$diff = array_diff($pk1, $pk2);
		
		return empty($diff);
	}
	
	/**
	 * Cuts all properties to make sure they are not longer than the database can store.
	 * Useful when importing or syncing
	 */
	public function cutPropertiesToColumnLength() {
		
		$tables = self::getMapping()->getTables();
		foreach($tables as $table) {
			foreach ($table->getColumns() as $column) {
				if ($column->pdoType == PDO::PARAM_STR && $column->length) {
					$this->{$column->name} = StringUtil::cutString($this->{$column->name}, $column->length, false, null);
				}
			}
		}
	}
	
	/**
	 * Copy the property.
	 * 
	 * The property will not be saved to the database.
	 * The primary key values will not be copied.
	 * 
	 * @return \static
	 */
	protected function internalCopy() {
		$copy = new static;

		//copy public and protected columns except for auto increments.
		$props = $this->getApiProperties();
		foreach($props as $name => $p) {
			$col = static::getMapping()->getColumn($name);
			if(isset($p['access']) && (!$col || $col->autoIncrement == false)) {
				$copy->$name = $this->$name;
			}
		}

		return $copy;
	}
}
