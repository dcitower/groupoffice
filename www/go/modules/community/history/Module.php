<?php


namespace go\modules\community\history;

use GO\Base\Db\ActiveRecord;
use go\core;
use go\core\jmap\Entity;
use go\core\model\User;
use go\modules\community\history\model\LogEntry;

class Module extends core\Module
{

	public static $enabled = true;

	public function autoInstall()
	{
		return true;
	}

	public function getAuthor() {
		return "Intermesh BV <info@intermesh.nl>";
	}

	public function defineListeners() {
		Entity::on(Entity::EVENT_SAVE, static::class, 'onEntitySave');
		Entity::on(Entity::EVENT_BEFORE_DELETE, static::class, 'onEntityDelete');
		User::on(User::EVENT_LOGIN, static::class, 'onLogin');
		User::on(User::EVENT_LOGOUT, static::class, 'onLogout');
		User::on(User::EVENT_BADLOGIN, static::class, 'onBadLogin');
	}

	static function logActiveRecord(ActiveRecord $record, $action) {

		if(!self::$enabled) {
			return;
		}

		//hacky but works for old code
		if(!$record->aclField()) {
			return;
		}

		$log = new LogEntry();
		$log->setEntity($record);
		$log->setAction($action);
		$changes = $record->getLogJSON($action);
		if($action == 'update' && empty($changes)) {
			return;
		}
		$log->changes = json_encode($changes);

		$l = LogEntry::getMapping()->getColumn('changes')->length;
		if(mb_strlen($log->changes) > $l) {
			foreach($changes as $key => $v) {
				$changes[$key] = '... changes were too big to log ...';
			}
			$log->changes = json_encode($changes);
		}

		if(!$log->save()) {
			\go\core\ErrorHandler::log("Could not save log for " . $log->getEntity() . " (" . $log->entityId ."): " . var_export($log->getValidationErrors(), true));
		}
	}

	public static function onEntitySave(Entity $entity) {
		self::logEntity($entity, $entity->isNew() ? 'create' : 'update');
	}

	public static function onEntityDelete(core\orm\Query $query, $cls) {
		if(is_a($cls, LogEntry::class, true) || is_a($cls, core\model\Search::class, true)) {
			return;
		}

		//Don't delete ACL's because we're taking them over.
		if(is_a($cls, core\acl\model\AclOwnerEntity::class, true)) {
			$cls::keepAcls();
		}

		$entities = $cls::find()->mergeWith(clone $query);
		foreach($entities as $e) {
			static::logEntity($e, 'delete');
		}
	}

	private static function logEntity(Entity $entity, $action) {
		if(!self::$enabled) {
			return;
		}

		if(!$entity::loggable()) {
			return;
		}

		if($entity instanceof LogEntry || $entity instanceof core\model\Search  || $entity instanceof core\model\CronJobSchedule) {
			return;
		}

		$log = new LogEntry();
		$log->setEntity($entity);
		$log->setAction($action);

		if($action !== 'delete') {
			$changes = $entity->getModified();
			unset($changes['modifiedAt']);
			unset($changes['acl']);
			unset($changes['aclId']);
			unset($changes['createdBy']);
			unset($changes['createdAt']);
			unset($changes['modifiedBy']);
			unset($changes['permissionLevel']);
			unset($changes['filesFolderId']);

			if(empty($changes)) {
				return;
			}

			if($action == 'create') {
				$changes = array_map(function($c) {
					return $c[0];
				}, $changes);

				$changes = array_filter($changes, function($c){
					return $c !== "";
				});
			}
			$log->changes = json_encode($changes);

		} else {
			$log->changes = null;
		}

		$l = LogEntry::getMapping()->getColumn('changes')->length;
		if(mb_strlen($log->changes) > $l) {
			foreach($changes as $key => $v) {
				$changes[$key] = '... changes were too big to log ...';
			}
			$log->changes = json_encode($changes);
		}

		if(!$log->save()) {
			\go\core\ErrorHandler::log("Could not save log for " . $log->getEntity() . " (" . $log->entityId ."): " . var_export($log->getValidationErrors(), true));
		}
	}

	public static function onLogin(User $user) {
		$log = new LogEntry();
		$log->setEntity($user);
		$log->description = $user->username . ' [' . core\http\Request::get()->getRemoteIpAddress() . ']';
		$log->setAction('login');
		$log->changes = null;
		if(!$log->save()){
			throw new \Exception("Could not save log");
		}
	}

	public static function onBadLogin($username, User $user = null) {
		$log = new LogEntry();
		if(isset($user)) {
			$log->setEntity($user);
		} else{
			$log->entityTypeId = User::entityType()->getId();
		}
		$log->description = $username. ' [' . core\http\Request::get()->getRemoteIpAddress() . ']';
		$log->setAction('badlogin');
		$log->changes = null;
		if(!$log->save()){
			throw new \Exception("Could not save log");
		}
	}

	public static function onLogout(User $user) {
		$log = new LogEntry();
		$log->setEntity($user);
		$log->description = $user->username . ' [' . core\http\Request::get()->getRemoteIpAddress() . ']';
		$log->setAction('logout');
		$log->changes = null;
		if(!$log->save()){
			throw new \Exception("Could not save log");
		}
	}
}