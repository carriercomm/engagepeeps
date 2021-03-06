<?php
/**
 * Admin Dialog modal boxes
 *
 * @package blesta
 * @subpackage blesta.app.controllers
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AdminDialog extends AppController {
	
	public function preAction() {
		parent::preAction();
		
		Language::loadLang(array("admin_dialog"));
	}
	
	public function confirm() {
		
		$this->setMessage("notice", isset($this->get['message']) ? $this->get['message'] : null, false, array('show_close'=>false));
		
		echo $this->view->fetch("admin_dialog_confirm");
		return false;
	}
}
?>