<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */
require_once APPLICATION_PATH . '/application/controllers/Action/Api.php';

/**
 * This class holds the api logic for the settings.
 *
 * @package     Controllers
 * @subpackage  Action
 * @since       4.0
 */
class SettingsAction extends ApiAction {
	use Billrun_Traits_Api_UserPermissions;
	
	protected $model;

	/**
	 * This method is for initializing the API Action's model.
	 */
	protected function initializeModel() {
		$this->model = new ConfigModel();
	}

	/**
	 * The logic to be executed when this API plugin is called.
	 */
	public function execute() {
		$this->allowed();
		$request = $this->getRequest();
		try {
			$this->initializeModel();
			$category = $request->get('category');
			$rawData = $request->get('data');
			$data = json_decode($rawData, TRUE);
			if (json_last_error()) {
				$this->setError('Illegal data', $request->getPost());
				return TRUE;
			}
			if (!($category)) {
				$this->setError('Missing category parameter', $request->getPost());
				return TRUE;
			}
			// TODO: Create action managers for the settings module.
			$action = $request->get('action');
			$success = true;
			$output = array();
			if ($action === 'set') {
				$success = $this->model->updateConfig($category, $data);
				
				// Get all the errors.
				$errors = $this->model->getInvalidFields();
				if(!empty($errors)) {
					$output = $errors;
				}
			} else if ($action === 'unset') {
				$success = $this->model->unsetFromConfig($category, $data);
			} else {
				$output = $this->model->getFromConfig($category, $data);
			}
			
			$this->getController()->setOutput(array(array(
					'status' => $success ? 1 : 0,
					'desc' => $success ? 'success' : 'error',
					'input' => $request->getPost(),
					'details' => is_bool($output)? array() : $output,
			)));
		} catch (Exception $ex) {
			$this->setError($ex->getMessage(), $request->getPost());
			return TRUE;
		}
		return TRUE;
	}

	protected function getPermissionLevel() {
		return Billrun_Traits_Api_IUserPermissions::PERMISSION_ADMIN;
	}

}