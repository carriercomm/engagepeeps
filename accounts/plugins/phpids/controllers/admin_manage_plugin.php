<?php
/**
 * PHPIDS manage plugin controller
 * 
 * @package blesta
 * @subpackage blesta.plugins.phpids
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminManagePlugin extends AppController {
	
	/**
	 * Performs necessary initialization
	 */
	private function init() {
		// Require login
		$this->parent->requireLogin();
		
		Language::loadLang("phpids_manage_plugin", null, PLUGINDIR . "phpids" . DS . "language" . DS);
		
		$this->view->setView(null, "Phpids.default");
		
		// Set the page title
		$this->parent->structure->set("page_title", Language::_("PhpidsManagePlugin." . Loader::fromCamelCase($this->action ? $this->action : "index") . ".page_title", true));
	}

	/**
	 * Returns the view to be rendered when managing this plugin
	 */
	public function index() {
		$this->uses(array("Phpids.PhpidsSettings", "EmailGroups"));
		$this->init();
		$plugin_id = $this->get[0];
		$intervals = $this->getIntervals();
		$log_days = $this->getDays();
		
		$vars = $this->PhpidsSettings->getAll();
		
		$email_group = $this->EmailGroups->getByAction("Phpids.email_alert");
		
		if (!empty($this->post)) {
			if (!isset($this->post['compound_impact']))
				$this->post['compound_impact'] = "false";
			
			$this->PhpidsSettings->update($this->post);
			
			if (($errors = $this->PhpidsSettings->errors())) 
				$this->parent->setMessage("error", $errors);
			else
				$this->parent->setMessage("message", Language::_("PhpidsManagePlugin.!success.settings_updated", true));
				
			$vars = (object)$this->post;
		}
		
		// Set the view to render for all actions under this controller
		return $this->partial("admin_manage_plugin", compact(array("plugin_id", "intervals", "log_days", "vars", "email_group")));
	}
	
	/**
	 * View logs
	 */
	public function logs() {
		$this->uses(array("Phpids.PhpidsLogs"));

		$page = (int)(isset($this->get[1]) ? $this->get[1] : 1);
		$sort = (isset($this->get['sort']) ? $this->get['sort'] : "date_added");
		$order = (isset($this->get['order']) ? $this->get['order'] : "desc");
		
		$this->init();
		$plugin_id = $this->get[0];
		$logs = $this->PhpidsLogs->getList($page, array($sort => $order));
		$total_results = $this->PhpidsLogs->getListCount();
		
		// Overwrite default pagination settings
		$settings = array_merge(Configure::get("Blesta.pagination"), array(
				'total_results' => $total_results,
				'uri' => $this->base_uri . "settings/company/plugins/manage/" . $plugin_id . "/[p]/",
				'params' => array('sort' => $sort,'order' => $order)
			)
		);
		
		$this->helpers(array("Pagination" => array($this->get, $settings)));
		
		return $this->partial("admin_manage_plugin_logs", compact(array("plugin_id", "logs")));
	}
	
	/**
	 * Returns an array of key/value day intervals
	 *
	 * @return array An array of temporal intervals (key/value pairs)
	 */
	private function getDays() {
		$days = array();
		
		// 1 - 90 days
		for ($i=1; $i<=90; $i++) {
			$days[$i] = Language::_(($i > 1 ? "PhpidsManagePlugin.getDays.days_plural" : "PhpidsManagePlugin.getDays.days_singular"), true, $i);
		}
		$days[''] = Language::_("PhpidsManagePlugin.getDays.days_never", true);
		
		return $days;
	}
	
	/**
	 * Returns an array of key/value time intervals where the key is the number
	 * of seconds representing the interval and the value is the description of
	 * that interval.
	 * 
	 * @return array An array of temporal intervals (key/value pairs)
	 */
	private function getIntervals() {
		$intervals = array();
		
		// 1 - 60 mins
		for ($i=1; $i<=60; $i++) {
			$intervals[60*$i] = Language::_(($i > 1 ? "PhpidsManagePlugin.getIntervals.mins_plural" : "PhpidsManagePlugin.getIntervals.mins_singular"), true, $i);
		}
		
		return $intervals;
	}
}
?>