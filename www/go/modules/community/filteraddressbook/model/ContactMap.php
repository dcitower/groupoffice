<?php
namespace go\modules\community\filteraddressbook\model;
						
use go\core\orm\Property;
use go\core\jmap\Entity;

						
/**
 * ContactMap model
 *
 * @copyright (c) 2021, Intermesh BV http://www.intermesh.nl
 * @author Merijn Schering <mschering@intermesh.nl>
 * @license http://www.gnu.org/licenses/agpl-3.0.html AGPLv3
 */
class ContactMap extends Entity {
	
	/** @var int  */							
	public $id;

	/** @var int  */							
	public $addressBookId;

	/** @var int  */							
	public $contactId;

	protected static function defineMapping() {
		return parent::defineMapping()
						->addTable("filteraddressbook_contact_map");
	}

}