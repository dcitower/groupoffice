<?php
namespace go\modules\community\files\model;

use go\core\orm;

class Storage extends orm\Property {

	
	public $id;
	/**
	 * @var \go\core\util\DateTime
	 */
	public $createdAt;
	/**
	 * @var \go\core\util\DateTime
	 */
	public $modifiedAt;
	public $ownedBy;
	public $modifiedBy;
	
	public $quota;
	public $usage;
	
	protected static function defineMapping() {
		return parent::defineMapping()->addTable("files_storage");
	}

	public function getRootFolder(){
		return Node::find()->where(['storageId'=>$this->id,'parentId'=>0])->single();
	}
	
}