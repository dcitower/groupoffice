<?php
namespace go\modules\community\task\controller;

use go\core\jmap\EntityController;
use go\modules\community\task\model;

/**
 * The controller for the Tasklist entity
 *
 * @copyright (c) 2018, Intermesh BV http://www.intermesh.nl
 * @author Merijn Schering <mschering@intermesh.nl>
 * @license http://www.gnu.org/licenses/agpl-3.0.html AGPLv3
 */ 
class Tasklist extends EntityController {
	
	/**
	 * The class name of the entity this controller is for.
	 * 
	 * @return string
	 */
	protected function entityClass() {
		return model\Tasklist::class;
	}	
	
	/**
	 * Handles the Tasklist entity's Tasklist/query command
	 * 
	 * @param array $params
	 * @see https://jmap.io/spec-core.html#/query
	 */
	public function query($params) {
		return $this->defaultQuery($params);
	}
	
	/**
	 * Handles the Tasklist entity's Tasklist/get command
	 * 
	 * @param array $params
	 * @see https://jmap.io/spec-core.html#/get
	 */
	public function get($params) {
		return $this->defaultGet($params);
	}
	
	/**
	 * Handles the Tasklist entity's Tasklist/set command
	 * 
	 * @see https://jmap.io/spec-core.html#/set
	 * @param array $params
	 */
	public function set($params) {
		return $this->defaultSet($params);
	}
	
	
	/**
	 * Handles the Tasklist entity's Tasklist/changes command
	 * 
	 * @param array $params
	 * @see https://jmap.io/spec-core.html#/changes
	 */
	public function changes($params) {
		return $this->defaultChanges($params);
	}
}

