<?php
/**
 * Module manager. Handles installing/uninstalling and configuring modules.
 *
 * @package blesta
 * @subpackage blesta.app.models
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class ModuleManager extends AppModel {
	
	/**
	 * Initialize ModuleManager
	 */
	public function __construct() {
		parent::__construct();
		Language::loadLang(array("module_manager"));
	}
	
	/**
	 * Fetches a single installed module including all of its module rows and meta data
	 *
	 * @param int $module_id The ID of the module to fetch
	 * @param boolean $rows True to fetch all module rows with this entry, false otherwise
	 * @param boolean $groups True to fetch all module groups with this entry, false otherwise
	 * @return mixed A stdClass object representing the installed module, false if no such module exists
	 */
	public function get($module_id, $rows=true, $groups=true) {
		$fields = array("id", "company_id", "name", "class", "version");
		$module = $this->Record->select($fields)->from("modules")->
			where("id", "=", $module_id)->fetch();
		
		if ($module) {
			// Fetch all module meta data
			$module->meta = $this->getMeta($module_id);
			
			// Fetch all module rows and meta data for this module
			if ($rows)
				$module->rows = $this->getRows($module_id);
			
			// Fetch all module groups for this module
			if ($groups)
				$module->groups = $this->getGroups($module_id);
		}
		
		return $module;
	}
	
	/**
	 * Lists all installed modules
	 *
	 * @param int $company_id The company ID
	 * @param string $sort_by The field to sort by
	 * @param string $order The direction to order results
	 * @return array An array of stdClass objects representing installed modules
	 */
	public function getAll($company_id, $sort_by="name", $order="asc") {
		$fields = array("id", "company_id", "name", "class", "version");
		
		$modules = $this->Record->select($fields)->from("modules")->
			where("company_id", "=", $company_id)->
			order(array($sort_by=>$order))->fetchAll();
		
		$num_modules = count($modules);
		// Load each installed module to fetch module info
		for ($i=0; $i<$num_modules; $i++) {
			try {
				$mod = $this->loadModule($modules[$i]->class);
				
				// Set the installed version of the plugin
				$modules[$i]->installed_version = $modules[$i]->version;
			}
			catch (Exception $e) {
				// Module could not be loaded
				continue;
			}
			
			$info = $this->getModuleInfo($mod, $company_id);
			foreach ((array)$info as $key=>$value)
				$modules[$i]->$key = $value;
		}
		
		return $modules;
	}
	
	/**
	 * Retrieves a list of module rows for a given module ID, including meta data
	 *
	 * @param int $module_id The module ID
	 * @param int $module_group_id The ID of the module group to filter on (optional)
	 * @return array An array of stdClass objects reperesenting all module rows for this module
	 */ 
	public function getRows($module_id, $module_group_id=null) {
		$fields = array("id", "module_id");
		
		$this->Record->select($fields)->from("module_rows")->where("module_id", "=", $module_id);
		
		// Filter results based on module group assignment
		if ($module_group_id !== null) {
			$this->Record->innerJoin("module_row_groups", "module_row_groups.module_row_id", "=", "module_rows.id", false)->
				where("module_row_groups.module_group_id", "=", $module_group_id);
		}
		
		$rows = $this->Record->fetchAll();
		
		// Set all meta data for each module row
		if (is_array($rows)) {
			$num_rows = count($rows);
			
			for ($i=0; $i<$num_rows; $i++) {
				$rows[$i]->meta = $this->getRowMeta($rows[$i]->id);
			}
		}
		
		return $rows;
	}
	
	/**
	 * Retrieves a module row, including meta data
	 *
	 * @param int $module_row_id The ID of the module row to fetch
	 * @return stdClass A stdClass object representing a module row
	 */
	public function getRow($module_row_id) {
		$fields = array("id", "module_id");
		
		$row = $this->Record->select($fields)->from("module_rows")->where("id", "=", $module_row_id)->fetch();
		
		// Set all meta data for each module row
		if ($row)
			$row->meta = $this->getRowMeta($row->id);
		return $row;
	}
	
	/**
	 * Retrieves a list of module groups for this given module ID
	 *
	 * @param int $module_id The ID of the module to fetch groups for
	 * @return array An array of stdClass objects representing module groups
	 */
	public function getGroups($module_id) {
		$fields = array("id", "module_id", "add_order", "name");
		
		$groups = $this->Record->select($fields)->from("module_groups")->
			where("module_id", "=", $module_id)->fetchAll();
			
		// Set all rows assigned to this group
		if (is_array($groups)) {
			$num_groups = count($groups);
			
			for ($i=0; $i<$num_groups; $i++) {
				$groups[$i]->rows = $this->getRows($module_id, $groups[$i]->id);
			}
		}
		
		return $groups;
	}
	
	/**
	 * Retrieves a specific module group
	 *
	 * @param int $module_group_id The ID of the module group to fetch
	 * @return stdClass A stdClass objects representing the module group
	 */
	public function getGroup($module_group_id) {
		$fields = array("id", "module_id", "add_order", "name");
		
		$group = $this->Record->select($fields)->from("module_groups")->
			where("id", "=", $module_group_id)->fetch();
			
		// Set all rows assigned to this group
		if ($group)
			$group->rows = $this->getRows($group->module_id, $group->id);
		
		return $group;
	}
	
	/**
	 * Retrieves a list of module meta data for a given module ID
	 *
	 * @param int $module_id The module ID
	 * @param string $key The module meta key representing a specific meta value (optional)
	 * @return An array of stdClass objects reperesenting all module meta info for this module
	 */
	public function getMeta($module_id, $key=null) {
		$fields = array("key", "value", "serialized", "encrypted");
		
		$this->Record->select($fields)->from("module_meta")->
			where("module_id", "=", $module_id);
		
		if ($key != null)
			$this->Record->where("key", "=", $key);
		
		return $this->formatRawMeta($this->Record->fetchAll());
	}
	
	/**
	 * Retrieves a list of module row meta data for a given module row ID
	 *
	 * @param int $module_row_id The module row ID
	 * @param string $key The module row key representing a specific meta value (optional)
	 * @return An array of stdClass objects reperesenting all module rows for this module
	 */
	public function getRowMeta($module_row_id, $key=null) {
		$fields = array("module_row_id", "key", "value", "serialized", "encrypted");
		
		$this->Record->select($fields)->from("module_row_meta")->where("module_row_id", "=", $module_row_id);
		
		if ($key != null)
			$this->Record->where("key", "=", $key);
		
		return $this->formatRawMeta($this->Record->fetchAll());
	}
	
	/**
	 * Lists all available modules (those that exist on the file system)
	 *
	 * @param int $company_id The ID of the company to get available modules for
	 * @return array An array of stdClass objects representing available modules
	 */
	public function getAvailable($company_id=null) {
		$modules = array();
		
		$dir = opendir(COMPONENTDIR . "modules");
		while (false !== ($module = readdir($dir))) {
			// If the file is not a hidden file, and is a directory, accept it
			if (substr($module, 0, 1) != "." && is_dir(COMPONENTDIR . "modules" . DS . $module)) {
				
				try {
					$mod = $this->loadModule($module);
				}
				catch (Exception $e) {
					// The module could not be loaded, try the next
					continue;
				}
				
				$modules[] = (object)$this->getModuleInfo($mod, $company_id);
			}
		}
		return $modules;
	}
	
	/**
	 * Checks whether the given module is installed for the specified company
	 *
	 * @param string $class The module class (in file_case)
	 * @param string $company_id The ID of hte company to fetch for (null checks if the module is installed across any company)
	 * @return boolean True if the module is installed, false otherwise
	 */
	public function isInstalled($class, $company_id=null) {
		$this->Record->select(array("modules.id"))->from("modules")->
			where("class", "=", $class);
			
		if ($company_id)
			$this->Record->where("company_id", "=", $company_id);
			
		return (boolean)$this->Record->fetch();
	}
	
	/**
	 * Adds the module to the system, executing the Module::install() method
	 *
	 * @param array $vars An array of module data including:
	 * 	- company_id
	 * 	- class
	 * @return int The ID of the module installed, void on error
	 */
	public function add(array $vars) {
		$meta = null;
		
		$module = $this->loadModule(isset($vars['class']) ? $vars['class'] : null);
		$vars['name'] = $module->getName();
		$vars['version'] = $module->getVersion();
		
		// Attempt to install the module
		$meta = $module->install();
		
		// If the installation failed for some reason, return nothing
		// we can do
		if (($errors = $module->errors())) {
			$this->Input->setErrors($errors);
			return;
		}
		
		$rules = array(
			'company_id'=>array(
				'valid'=>array(
					'rule'=>array(array($this, "validateExists"), "id", "companies"),
					'message'=>$this->_("ModuleManager.!error.company_id.valid")
				)
			),
			'class'=>array(
				'valid'=>array(
					'rule'=>"isEmpty",
					'negate'=>true,
					'message'=>$this->_("ModuleManager.!error.class.valid")
				)
			),
			'name'=>array(
				'valid'=>array(
					'rule'=>"isEmpty",
					'negate'=>true,
					'message'=>$this->_("ModuleManager.!error.name.valid")					
				)
			),
			'version'=>array(
				'valid'=>array(
					'rule'=>"isEmpty",
					'negate'=>true,
					'message'=>$this->_("ModuleManager.!error.version.valid")
				)
			)
		);
		
		$this->Input->setRules($rules);
		
		// Add the module to the database
		if ($this->Input->validates($vars)) {
			$fields = array("company_id", "name", "class", "version");
			$this->Record->insert("modules", $vars, $fields);
		}
		
		$module_id = $this->Record->lastInsertId();
		
		if (!empty($meta))
			$this->setMeta($module_id, $meta);
			
		return $module_id;
	}
	
	/**
	 * Runs the module's upgrade method to upgrade the module to match that of the module's file version.
	 * Sets errors in ModuleManager::errors() if any errors are set by the module's upgrade method.
	 *
	 * @param int $module_id The ID of the module to upgrade
	 * @see ModuleManager::errors()
	 */
	public function upgrade($module_id) {
		$installed_module = $this->get($module_id, false, false);
		
		if (!$installed_module)
			return;
		
		$module = $this->loadModule($installed_module->class);
		$module->setModule($installed_module);
		
		$file_version = $module->getVersion();
		
		// Execute the upgrade if the installed version doesn't match the file version
		if (version_compare($file_version, $installed_module->version, "!=")) {
			$module->upgrade($installed_module->version);
			
			if (($errors = $module->errors()))
				$this->Input->setErrors($errors);
			else {
				// Update all installed modules to the given version
				$this->setVersion($installed_module->class, $file_version);
			}
		}
	}
	
	/**
	 * Updates the meta data for the given module, removing all existing data and replacing it with the given data
	 *
	 * @param int $module_id The ID of the module to update
	 * @param array $vars A numerically indexed array of meta data containing:
	 * 	- key The key for this meta field
	 * 	- value The value for this key
	 * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
	 */
	public function setMeta($module_id, array $vars) {
		
		// Delete all old meta data for this module
		$this->Record->from("module_meta")->
			where("module_id", "=", $module_id)->delete();
		
		// Add all new module data
		$fields = array("module_id", "key", "value", "serialized", "encrypted");
		$num_vars = count($vars);
		for ($i=0; $i<$num_vars; $i++) {
			$serialize = !is_scalar($vars[$i]['value']);
			$vars[$i]['module_id'] = $module_id;
			$vars[$i]['serialized'] = (int)$serialize;
			$vars[$i]['value'] = $serialize ? serialize($vars[$i]['value']) : $vars[$i]['value'];
			
			if (isset($vars[$i]['encrypted']) && $vars[$i]['encrypted'] == "1")
				$vars[$i]['value'] = $this->systemEncrypt($vars[$i]['value']);
			
			$this->Record->insert("module_meta", $vars[$i], $fields);
		}
	}
	
	
	/**
	 * Permanently and completely removes the module from the database,
	 * along with all module records. Executes the Modules::uninstall() method
	 *
	 * @param int $module_id The ID of the module to permanently and completely remove
	 */
	public function delete($module_id) {
		
		$installed_module = $this->get($module_id, false, false);
		
		$this->Record->from("modules")->where("id", "=", $module_id)->delete();
		$this->Record->from("module_groups")->where("module_id", "=", $module_id)->delete();
		$this->Record->from("module_meta")->where("module_id", "=", $module_id)->delete();
		$this->Record->from("module_rows")->
			leftJoin("module_row_meta", "module_row_meta.module_row_id", "=", "module_rows.id", false)->
			leftJoin("module_row_groups", "module_row_groups.module_row_id", "=", "module_rows.id", false)->
			where("module_rows.module_id", "=", $module_id)->
			delete(array("module_row_meta.*","module_rows.*","module_row_groups.*"));
			
		
		if ($installed_module) {
			// It's the responsibility of the module to remove any other tables or entries
			// it has created that are no longer relevant
			$module = $this->loadModule($installed_module->class);
			$module->setModule($installed_module);
			
			$module->uninstall($module_id, !$this->isInstalled($installed_module->class));
			
			if (($errors = $module->errors()))
				$this->Input->setErrors($errors);
		}
	}
	
	/**
	 * Adds a new module row for the given module
	 *
	 * @param int $module_id The ID of the module to add a row to
	 * @param array $vars An array of key/value pairs to be sent to the module for conversion into module row meta data
	 * @return int The module Row ID, void on error
	 * @see ModuleManager::errors()
	 */
	public function addRow($module_id, array $vars) {
		$module = $this->initModule($module_id);
		if ($module) {
			// Notify the module of the attempt to add a module row
			$meta = $module->addModuleRow($vars);
			
			// Set any errors encountered
			if (($errors = $module->errors()))
				$this->Input->setErrors($errors);
			// If no errors, record the data
			else {
				$this->Record->insert('module_rows', array('module_id'=>$module_id));
				
				$module_row_id = $this->Record->lastInsertId();
				
				$fields = array("module_row_id", "key", "value", "serialized", "encrypted");
				$num_meta = count($meta);
				for ($i=0; $i<$num_meta; $i++) {
					$serialize = !is_scalar($meta[$i]['value']);
					$meta[$i]['module_row_id'] = $module_row_id;
					$meta[$i]['serialized'] = (int)$serialize;
					$meta[$i]['value'] = $serialize ? serialize($meta[$i]['value']) : $meta[$i]['value'];
					
					if (isset($meta[$i]['encrypted']) && $meta[$i]['encrypted'])
						$meta[$i]['value'] = $this->systemEncrypt($meta[$i]['value']);
					
					$this->Record->insert("module_row_meta", $meta[$i], $fields);
				}
				return $module_row_id;
			}
		}
	}
	
	/**
	 * Edits a module row
	 *
	 * @param int $module_row_id The ID of the module row to update
	 * @param array $vars An array of key/value pairs to be sent to the module for conversion into module row meta data
	 * @return int The module Row ID, void on error
	 * @see ModuleManager::errors()
	 */
	public function editRow($module_row_id, array $vars) {
		$module_row = $this->getRow($module_row_id);
		
		if ($module_row) {
			$module = $this->initModule($module_row->module_id);

			// Notify the module of the attempt to edit a module row
			$meta = $module->editModuleRow($module_row, $vars);
			
			// Set any errors encountered
			if (($errors = $module->errors()))
				$this->Input->setErrors($errors);
			// If no errors, record the data
			else {
				
				// Remove old module row meta data
				$this->Record->from("module_row_meta")->where("module_row_id", "=", $module_row_id)->delete();
				
				// Insert new module row meta data
				$fields = array("module_row_id", "key", "value", "serialized", "encrypted");
				$num_meta = count($meta);
				for ($i=0; $i<$num_meta; $i++) {
					$serialize = !is_scalar($meta[$i]['value']);
					$meta[$i]['module_row_id'] = $module_row_id;
					$meta[$i]['serialized'] = (int)$serialize;
					$meta[$i]['value'] = $serialize ? serialize($meta[$i]['value']) : $meta[$i]['value'];
					
					if (isset($meta[$i]['encrypted']) && $meta[$i]['encrypted'])
						$meta[$i]['value'] = $this->systemEncrypt($meta[$i]['value']);
					
					$this->Record->insert("module_row_meta", $meta[$i], $fields);
				}
				return $module_row_id;
			}
		}
	}
	
	/**
	 * Permanently removes a module row and its related meta data if safe to do so.
	 *
	 * @param int $module_row_id The ID of the module row to remove
	 * @see ModuleManager::errors()
	 */
	public function deleteRow($module_row_id) {
		
		$vars = array('module_row_id'=>$module_row_id);
		$rules = array(
			'module_row_id'=>array(
				// Ensure row does not belong to an active service
				'assigned_service'=>array(
					'rule'=>array(array($this, "validateAssignedToService")),
					'message'=>$this->_("ModuleManager.!error.module_row_id.assigned_service"),
					'last'=>true
				),
				// Ensure row does not belong to a package
				'assigned_package'=>array(
					'rule'=>array(array($this, "validateExists"), "module_row", "packages"),
					'negate'=>true,
					'message'=>$this->_("ModuleManager.!error.module_row_id.assigned_package")
				)
			)
		);
		
		$this->Input->setRules($rules);
		
		if ($this->Input->validates($vars)) {
			
			$module_row = $this->getRow($module_row_id);
			
			// Delete the module row and its related data
			$this->Record->from("module_rows")->
				leftJoin("module_row_meta", "module_row_meta.module_row_id", "=", "module_rows.id", false)->
				leftJoin("module_row_groups", "module_row_groups.module_row_id", "=", "module_rows.id", false)->
				where("module_rows.id", "=", $module_row_id)->
				delete(array("module_row_meta.*","module_rows.*","module_row_groups.*"));
			
			// Attempt to notify the module about the deltion
			if ($module_row) {
				
				$module = $this->initModule($module_row->module_id);
				/*
				$installed_module = $this->get($module_row->module_id);
				
				$module = $this->loadModule($installed_module->class);
				$module->setModule($installed_module);
				*/
				
				// Notify the module about the deletion
				$module->deleteModuleRow($module_row);
				
				if (($errors = $module->errors()))
					$this->Input->setErrors($errors);
			}
		}
	}
	
	/**
	 * Adds a module group to the system
	 *
	 * @param int $module_id The ID of the module to add the group under
	 * @param array An array of module group data including:
	 * 	- name The name of the module group
	 * 	- module_rows A numerically indexed array of module row IDs to assign to this group
	 * 	- add_order A key used to determine the order in which module rows are selected from this group when provisioning a service
	 * @return int The module group ID, void if error
	 */
	public function addGroup($module_id, array $vars) {
		
		$vars['module_id'] = $module_id;
		if (!isset($vars['module_rows']))
			$vars['module_rows'] = array();
			
		$rules = array(
			'name'=>array(
				'empty'=>array(
					'rule'=>"isEmpty",
					'negate'=>true,
					'message'=>$this->_("ModuleManager.!error.name.empty")
				)
			),
			// verify that every module_row[] specified belongs to $module_id
			'module_rows[]'=>array(
				'valid'=>array(
					'rule'=>array(array($this, "validateBelongsToModule"), $module_id),
					'message'=>$this->_("ModuleManager.!error.module_rows[].valid")
				)
			)
		);
		$this->Input->setRules($rules);
		
		if ($this->Input->validates($vars)) {
			$fields = array("module_id", "add_order", "name");
			// Add the module group
			$this->Record->insert("module_groups", $vars, $fields);
			$module_group_id = $this->Record->lastInsertId();
			
			// Set assignments
			if (isset($vars['module_rows']))
				$this->setGroupAssignment($module_group_id, $vars['module_rows']);
			
			return $module_group_id;
		}		
	}
	
	/**
	 * Updates a module group to the system
	 *
	 * @param int $module_group_id The ID of the module group to update
	 * @param array An array of module group data including:
	 * 	- name The name of the module group
	 * 	- module_rows A numerically indexed array of module row IDs to assign to this group
	 */
	public function editGroup($module_group_id, array $vars) {
		$group = $this->getGroup($module_group_id);
		
		$vars['module_id'] = isset($group->module_id) ? $group->module_id : null;
		if (!isset($vars['module_rows']))
			$vars['module_rows'] = array();
			
		$rules = array(
			'name'=>array(
				'empty'=>array(
					'rule'=>"isEmpty",
					'negate'=>true,
					'message'=>$this->_("ModuleManager.!error.name.empty")
				)
			),
			// verify that every module_row[] specified belongs to $module_id
			'module_rows[]'=>array(
				'valid'=>array(
					'rule'=>array(array($this, "validateBelongsToModule"), $vars['module_id']),
					'message'=>$this->_("ModuleManager.!error.module_rows[].valid")
				)
			)
		);
		$this->Input->setRules($rules);
		
		if ($this->Input->validates($vars)) {
			$fields = array("add_order", "name");
			// Add the module group
			$this->Record->where("id", "=", $module_group_id)->
				update("module_groups", $vars, $fields);
			
			// Set assignments
			if (isset($vars['module_rows']))
				$this->setGroupAssignment($module_group_id, $vars['module_rows']);
			
			return $module_group_id;
		}
	}
	
	/**
	 * Permanently removes the module group if safe to do so.
	 *
	 * @param int $module_group_id The ID of the module group to remove
	 * @see ModuleManager::errors()
	 */
	public function deleteGroup($module_group_id) {
		
		$vars = array('module_group_id'=>$module_group_id);
		$rules = array(
			'module_group_id'=>array(
				// Ensure group does not belong to a package
				'assigned_package'=>array(
					'rule'=>array(array($this, "validateExists"), "module_group", "packages"),
					'negate'=>true,
					'message'=>$this->_("ModuleManager.!error.module_group_id.assigned_package")
				)
			)
		);
		
		$this->Input->setRules($rules);
		
		// Delete the module group if safe to do so
		if ($this->Input->validates($vars)) {
			$this->Record->from("module_groups")->where("id", "=", $module_group_id)->delete();
			$this->Record->from("module_row_groups")->where("module_group_id", "=", $module_group_id)->delete();
		}
	}
	
	/**
	 * Invokes the given method with the given parameters on the give module,
	 * returning the result from the module.
	 *
	 * @param int $module_id The ID of the module to initialize
	 * @param string $method The name of the method to invoke on the module
	 * @param array $params An array of parameters to pass to the method to be invoked
	 * @param int $module_row_id The module row ID to initialize with
	 * @return mixed The value returned from the module for the requested method
	 * @see ModuleManager::errors()
	 */
	public function moduleRpc($module_id, $method, array $params=null, $module_row_id=null) {
		
		$module = $this->initModule($module_id);
		
		if (!$module)
			return;
		
		if ($module_row_id)
			$module->setModuleRow($this->getRow($module_row_id));
		
		$result = call_user_func_array(array($module, $method), (array)$params);
		
		if (($errors = $module->errors()))
			$this->Input->setErrors($errors);
		return $result;
	}
	
	/**
	 * Initializes the module if it has been installed and returns its instance
	 *
	 * @param int $module_id The ID of the module to initialize
	 * @param int $company_id If set will check to ensure the module belongs to the given company_id
	 * @return Module An object of type Module if the requested module has been installed and exists, false otherwise
	 */
	public function initModule($module_id, $company_id=null) {
		$installed_module = $this->get($module_id, false, false);
		if ($installed_module && ($company_id === null || $company_id == $installed_module->company_id)) {
			$module = $this->loadModule($installed_module->class);
			$module->setModule($installed_module);
			
			return $module;
		}
		return false;
	}
	
	/**
	 * Validates that the given module row ID belongs to the given module ID
	 *
	 * @param $module_row_id The ID of the module row to check
	 * @param $module_id The ID of the module to check against the module row ID
	 * @return boolean True if the module row ID belongs to the module or is empty, false otherwise. 
	 */
	public function validateBelongsToModule($module_row_id, $module_id) {
		if ($module_row_id == "")
			return true;
		
		return (boolean)$this->Record->select("id")->from("module_rows")->
			where("id", "=", $module_row_id)->
			where("module_id", "=", $module_id)->fetch();
	}
	
	/**
	 * Validates that the given module row ID is not assigned to any active (i.e. uncanceled) service
	 *
	 * @param $module_row_id The ID of the module row to check
	 * @return boolean True if the module row is not assigned to any active service, false if it is
	 */
	public function validateAssignedToService($module_row_id) {
		
		return !(boolean)$this->Record->select(array("id"))->from("services")->
			where("module_row_id", "=", $module_row_id)->fetch();
	}
	
	/**
	 * Sets the group assignment for the given module group and set of rows
	 *
	 * @param int $module_group_id The ID of the module group to record row group assignments on
	 * @param array $module_rows A numerically indexed array of module row ID's to assign to this group
	 */
	private function setGroupAssignment($module_group_id, $module_rows) {
		// Remove all existing rows from this group
		$this->Record->from("module_row_groups")->
			where("module_group_id", "=", $module_group_id)->delete();
		
		// Add each row to the group
		for ($i=0; $i<count($module_rows); $i++) {
			$this->Record->set("module_group_id", $module_group_id)->
				set("module_row_id", $module_rows[$i])->
				insert("module_row_groups");
		}
	}
	
	/**
	 * Updates all installed modules with the version given
	 *
	 * @param string $class The class name of the module to update
	 * @param string $version The version number to set for each module instance
	 */
	private function setVersion($class, $version) {
		$this->Record->where("class", "=", $class)->update("modules", array('version'=>$version));
	}
	
	/**
	 * Instantiates the given module and returns its instance
	 *
	 * @param string $class The name of the class in file_case to load
	 * @return An instance of the module specified
	 */
	private function loadModule($class) {
		
		// Load the module factory if not already loaded
		if (!isset($this->Modules))
			Loader::loadComponents($this, array("Modules"));
		
		// Instantiate the module and return the instance
		return $this->Modules->create($class);
	}
	
	/**
	 * Fetch information about the given module object
	 *
	 * @param object $module The module object to fetch info on
	 * @param int $company_id The ID of the company to fetch the module info for
	 * @return array key=>value pairs of module info
	 */
	private function getModuleInfo($module, $company_id) {
		// Fetch supported interfaces
		$reflect = new ReflectionClass($module);
		$class = Loader::fromCamelCase($reflect->getName());
		
		$dirname = dirname($_SERVER['SCRIPT_NAME']);
		$info = array(
			'class'=>$class,
			'name'=>$module->getName(),
			'version'=>$module->getVersion(),
			'authors'=>$module->getAuthors(),
			'logo'=>Router::makeURI(($dirname == DS ? "" : $dirname) . DS . str_replace(ROOTWEBDIR, "", COMPONENTDIR . "modules" . DS . $class . DS . $module->getLogo())),
			'installed'=>$this->isInstalled($class, $company_id),
		);
		
		unset($reflect);
		
		return $info;
	}
	
	/**
	 * Formats an array of raw meta stdClass objects into a stdClass
	 * object whose public member variables represent meta keys and whose values
	 * are automatically decrypted and unserialized as necessary.
	 *
	 * @param array $raw_meta An array of stdClass objects representing meta data
	 */
	private function formatRawMeta($raw_meta) {
		
		$meta = new stdClass();
		// Decrypt data as necessary
		foreach ($raw_meta as &$data) {
			if ($data->encrypted > 0)
				$data->value = $this->systemDecrypt($data->value);
			
			if ($data->serialized > 0)
				$data->value = unserialize($data->value);
			
			$meta->{$data->key} = $data->value;
		}
		return $meta;
	}
}
?>