<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billapi balances model for rates entity
 *
 * @package  Billapi
 * @since    5.3
 */
class Models_Rates extends Models_Entity {	
	protected function init($params) {
		parent::init($params);
		
		if (isset($this->update['tariff_category']) && $this->update['tariff_category'] == 'retail') {
			$this->update['add_to_retail'] = true;
		}
	}
	
	/**
	 * method to add entity custom fields values from request
	 * 
	 * @param array $fields array of field settings
	 */
	protected function getCustomFields($update = array()) {
		$customFields = parent::getCustomFields();
		$play = Billrun_Util::getIn($update, 'play', Billrun_Util::getIn($this->before, 'play', ''));
		return Billrun_Utils_Plays::filterCustomFields($customFields, $play);
	}
}
