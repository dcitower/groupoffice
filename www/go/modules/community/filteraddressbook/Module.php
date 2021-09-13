<?php
namespace go\modules\community\filteraddressbook;
							
use go\core;
use Exception;
							
/**						
 * @copyright (c) 2021, Intermesh BV http://www.intermesh.nl
 * @author Merijn Schering <mschering@intermesh.nl>
 * @license http://www.gnu.org/licenses/agpl-3.0.html AGPLv3
 */
class Module extends core\Module {

	protected $myFilesDir;
	protected $myBackupDir;
	protected $baseDir;

	protected $installFiles = [
		'go/core/jmap/' => 'EntityController.php',
		'go/modules/community/carddav/' => 'Backend.php',
		'go/core/views/extjs3/form/' => 'Dialog.js',
		'go/modules/community/addressbook/views/extjs3/' => 'AddressBookTree.js'
	];
							
	public function getAuthor() {
		return "Intermesh BV <info@intermesh.nl>";
	}

	protected function __construct()
	{
		parent::__construct();

		$filesDir = realpath(__DIR__ . '/files');
		$this->myFilesDir = $filesDir . '/mine/';
		$this->myBackupDir = $filesDir . '/orig/';

		$this->baseDir = realpath(__DIR__ . '/../../../../') . '/';
	}

	/* 
	* Change core method codes after install 
	*/
	protected function afterInstall(\go\core\model\Module $model)
	{
		foreach( $this->installFiles as $moveDir => $moveFile ) {

			$origFile = $this->baseDir . $moveDir . $moveFile;
			$myFile = $this->myFilesDir . $moveFile . '.mine';
			$backupFile = $this->myBackupDir . $moveFile . '.backup';

			// sanity check
			if (!file_exists($origFile) ) {
				throw new Exception("Orig File does not exist: $origFile");
			}

			if (!file_exists($myFile) ) {
				throw new Exception("My File does not exist: $myFile");
			}

			// copy original file to files/orig
			copy($origFile, $backupFile);

			// replace original with file from files/mine
			copy($myFile, $origFile);
		}

		return parent::afterInstall($model);
	}

	protected function beforeUninstall()
	{
		foreach( $this->installFiles as $moveDir => $moveFile ) {
			
			$origFile = $this->baseDir . $moveDir . $moveFile;
			$backupFile = $this->myBackupDir . $moveFile . '.backup';

			// sanity check
			if (!file_exists($origFile) ) {
				throw new Exception("Orig File does not exist: $origFile");
			}

			if (!file_exists($backupFile) ) {
				throw new Exception("My Backup File does not exist: $backupFile");
			}

			// copy backup file to original location
			copy($backupFile, $origFile);
		}

		return parent::beforeUninstall();
	}
							
}