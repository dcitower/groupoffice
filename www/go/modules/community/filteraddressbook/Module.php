<?php
namespace go\modules\community\filteraddressbook;
							
use go\core;
							
/**						
 * @copyright (c) 2021, Intermesh BV http://www.intermesh.nl
 * @author Merijn Schering <mschering@intermesh.nl>
 * @license http://www.gnu.org/licenses/agpl-3.0.html AGPLv3
 */
class Module extends core\Module {
							
	public function getAuthor() {
		return "Intermesh BV <info@intermesh.nl>";
	}

	/* 
	* Change core method codes after install 
	*/
	protected function afterInstall(\go\core\model\Module $model){

		// Alter the core method of go\core\jmap\EntityController
		
		uopz_add_function(\go\core\jmap\EntityController::class,'createEntitites', function () {
			foreach ($create as $clientId => $properties) {

				$entity = $this->create($properties);
				
				if(!$this->canCreate($entity)) {
					$result['notCreated'][$clientId] = new SetError("forbidden", go()->t("Permission denied"));
					continue;
				}
	
				if ($entity->save()) {
	
					// save address book
					if(isset($properties['filter'])){
						$addressBook = new \go\modules\community\addressbook\model\AddressBook();
						$addressBook->name = '~ '.$properties['name'];
						$addressBook->salutationTemplate = 'Dear [if {{contact.prefixes}}]{{contact.prefixes}}[else][if !{{contact.gender}}]Ms./Mr.[else][if {{contact.gender}}=="M"]Mr.[else]Ms.[/if][/if][/if][if {{contact.middleName}}] {{contact.middleName}}[/if] {{contact.lastName}}';
						$addressBook->aclId = $properties['acl'][2];
						
						$addressBook->save();
	
						
						$arr['sort'] = [];
						$subarr['property'] = 'name';
						$subarr['isAscending'] = 1;
						array_push($arr['sort'],$subarr);
						$arr['calculateTotal'] = 1;
						$arr['limit'] = 40; 
						
						$opr = $properties['filter']['operator'];
						$condition = $properties['filter']['conditions'];
						$flArr['operator'] = $opr;
						$flArr['conditions'] = $condition;
						$properties['filter']['conditions'] = [];
						array_push($properties['filter']['conditions'], $flArr);
						$arr['filter'] = $properties['filter'];
						
						$cont =  new \go\modules\community\addressbook\controller\Contact();
	
						$fl = $cont->query($arr);
	
						
						
						// Iterate through all IDs
						foreach ($fl['ids'] as $key => $id) {
							
							$customFilter = new \go\modules\community\filteraddressbook\model\ContactMap();
							$customFilter->addressBookId = $addressBook->id;
							$customFilter->contactId = $id;
							$customFilter->save();
						}
	
					}
					
					//refetch from server when mapping has a query object.
					if($entity::getMapping()->getQuery() != null) {
						$entity = $this->getEntity($entity->id());
					}
	
					$entityProps = new ArrayObject($entity->toArray());
					$diff = $entityProps->diff($properties);
					$diff['id'] = $entity->id();
					
					$result['created'][$clientId] = empty($diff) ? null : $diff;
				} else {				
					$result['notCreated'][$clientId] = new SetError("invalidProperties");
					$result['notCreated'][$clientId]->properties = array_keys($entity->getValidationErrors());
					$result['notCreated'][$clientId]->validationErrors = $entity->getValidationErrors();
				}
			}
		});
		return parent::afterInstall($model);
	}
							
}