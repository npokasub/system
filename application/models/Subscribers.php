<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */


/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * This class is to hold the logic for the subscribers module.
 *
 * @package  Models
 * @subpackage Table
 * @since    4.0
 */
class SubscribersModel extends TabledateModel{
	
	protected $subscribers_coll;
	
	/**
	 * constructor
	 * 
	 * @param array $params of parameters to preset the object
	 */
	public function __construct(array $params = array()) {
		$params['collection'] = Billrun_Factory::db()->subscribers;
		parent::__construct($params);
		$this->subscribers_coll = Billrun_Factory::db()->subscribersCollection();
		$this->search_key = "sid";
	}
	
	public function getTableColumns() {
		$columns = array(
			'sid' => 'Subscriber',
			'aid' => 'Account',
			'msisdn' => 'MSISDN',
			'plan' => 'Plan',
			'language' => 'Language',
			'service_provider' => 'Service Provider',
			'from' => 'From',
			'to' => 'To',
			'_id' => 'Id',
		);
		return $columns;
	}

	public function getSortFields() {
		$sort_fields = array(
			'sid' => 'Subscriber',
			'aid' => 'Account',
			'msisdn' => 'MSISDN',
			'plan' => 'Plan',
			'language' => 'Language',
			'service_provider' => 'Service Provider'
		);
		return array_merge($sort_fields, parent::getSortFields());
	}
	
	public function getFilterFields() {
		$planModel = new PlansModel();
		$names = $planModel->getData(
			array(
				'$or' => array(
					array(
						'type' => 'customer'
						), 
					array(
						'type' => array(
							'$exists' => false
						)
					)
				)
			)
		);
		$planNames = array();
		foreach($names as $name) {
			$planNames[$name['name']] = $name['name'];
		}

		$filter_fields = array(
			'sid' => array(
				'key' => 'sid',
				'db_key' => 'sid',
				'input_type' => 'number',
				'comparison' => 'equals',
				'display' => 'Subscriber',
				'default' => '',
			),			
			'aid' => array(
				'key' => 'aid',
				'db_key' => 'aid',
				'input_type' => 'number',
				'comparison' => 'equals',
				'display' => 'Account',
				'default' => '',
			),			
			'msisdn' => array(
				'key' => 'msisdn',
				'db_key' => 'msisdn',
				'input_type' => 'text',
				'comparison' => 'contains',
				'display' => 'MSISDN',
				'default' => '',
			),
			'imsi' => array(
				'key' => 'imsi',
				'db_key' => 'imsi',
				'input_type' => 'text',
				'comparison' => '$in',
				'display' => 'IMSI',
				'default' => ''
			),
			'plan' => array(
				'key' => 'plan',
				'db_key' => 'current_plan',
				'input_type' => 'multiselect',
				'comparison' => '$in',
				'ref_coll' => 'plans',
				'ref_key' => 'name',
				'display' => 'Plan',
				'values' => $planNames,
				'default' => array(),
			),		
			'service_provider' => array(
				'key' => 'service_provider',
				'db_key' => 'service_provider',
				'input_type' => 'text',
				'comparison' => 'contains',
				'display' => 'Service Provider',
				'default' => '',
			)
		);
		return array_merge($filter_fields, parent::getFilterFields());
	}

	public function getFilterFieldsOrder() {
		$filter_field_order = array(
			array(
				'sid' => array(
					'width' => 2,
				),				
				'aid' => array(
					'width' => 2
				),
				'msisdn' => array(
					'width' => 2,
				)
			),
			array(
				'imsi' => array(
					'width' => 2,
				),
				'plan' => array(
					'width' => 2
				),				
				'service_provider' => array(
					'width' => 2
				)
			)
		);
		return array_merge($filter_field_order, parent::getFilterFieldsOrder());
	}

	public function getProtectedKeys($entity, $type) {
		$parentKeys = parent::getProtectedKeys($entity, $type);
		return array_merge($parentKeys, 
						   array());
	}
}
