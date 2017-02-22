<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing realtime controller class
 * Used for events in real-time
 * 
 * @package  Controller
 * @since    5.3
 */
class RealtimeController extends ApiController {

	use Billrun_Traits_Api_UserPermissions;

	protected $config = null;
	protected $event = null;
	protected $file_type = null;

	public function indexAction() {
		$this->execute();
	}

	/**
	 * method to execute real-time event
	 */
	public function execute() {
		$this->allowed();
		Billrun_Factory::log("Execute realtime event", Zend_Log::INFO);
		$this->setDataFromRequest();
		$this->setEventData();
		$data = $this->process();
		return $this->respond($data);
	}

	/**
	 * Gets the data sent to the controller
	 */
	protected function setDataFromRequest() {
		$request = $this->getRequest()->getRequest();
		$this->config = Billrun_Factory::config()->getFileTypeSettings($request['file_type']);
		$decoder = Billrun_Decoder_Manager::getDecoder(array(
				'decoder' => $this->config['parser']['type']
		));
		if (!$decoder) {
			Billrun_Factory::log('Cannot get decoder', Zend_Log::ALERT);
			return false;
		}

		if (!empty($request['request'])) {
			$requestBody = $request['request'];
		} else {
			$requestBody = file_get_contents("PHP://input");
		}

		$this->event['uf'] = Billrun_Util::parseDataToBillrunConvention($decoder->decode($requestBody));
	}

	/**
	 * Sets the data of $this->event
	 */
	protected function setEventData() {
		$this->event['source'] = 'realtime';
		$this->event['type'] = $this->getEventType();
		$this->event['request_type'] = $this->getRequestType();
		$this->event['request_num'] = $this->getRequestNum();
		$this->event['session_id'] = $this->getSessionId();
		$this->event['rand'] = rand(1, 1000000);
		$this->event['stamp'] = Billrun_Util::generateArrayStamp($this->event);
		$this->event['record_type'] = $this->getDataRecordType($this->event);
		$this->event['billrun_pretend'] = $this->isPretend($this->event);

		Billrun_Factory::dispatcher()->trigger('realtimeAfterSetEventData', array(&$this->event));
	}

	protected function getDataRecordType($data) {
		$requestCode = $data['request_type'];
		$requestTypes = Billrun_Factory::config()->getConfigValue('realtimeevent.requestType', array());
		foreach ($requestTypes as $requestTypeDesc => $requestTypeCode) {
			if ($requestCode == $requestTypeCode) {
				return strtolower($requestTypeDesc);
			}
		}

		Billrun_Factory::log("No record type found. Params: " . print_R($data, 1), Zend_Log::ERR);
		return false;
	}

	/**
	 * Gets the event type for rates calculator
	 * 
	 * @return string event type
	 * @todo Get values from config
	 */
	protected function getEventType() {
		return $this->config['file_type'];
	}

	/**
	 * Gets the request type from the request
	 * 
	 * @return string request type
	 */
	protected function getRequestType() {
		if (isset($this->config['realtime']['postpay_charge']) && $this->config['realtime']['postpay_charge']) {
			$this->event['skip_calc'] = array('unify');
			return Billrun_Factory::config()->getConfigValue('realtimeevent.requestType.POSTPAY_CHARGE_REQUEST', "4");
		}
		$requestTypeField = $this->config['realtime']['request_type_field'];
		if (!$requestTypeField) {
			return (isset($this->event['uf']['request_type']) ? $this->event['uf']['request_type'] : null);
		}
		return $this->event['uf'][$requestTypeField];
	}
	
	/**
	 * Gets the request num from the request
	 * 
	 * @return string request num
	 */
	protected function getRequestNum() {
		if (!isset($this->config['realtime']['request_num_field'])) {
			return (isset($this->event['uf']['request_num']) ? $this->event['uf']['request_num'] : null);
		}
		$requestNumField = $this->config['realtime']['request_num_field'];
		return $this->event['uf'][$requestNumField];
	}

	/**
	 * Gets the request session id from the request
	 * 
	 * @return string session id
	 */
	protected function getSessionId() {
		if (!isset($this->config['realtime']['session_id_fields'])) {
			return (isset($this->event['uf']['session_id']) ? $this->event['uf']['session_id'] : Billrun_Util::generateRandomNum());
		}
		$sessionIdFields = $this->config['realtime']['session_id_fields'];
		$sessionIdArr = array_intersect_key($this->event['uf'], array_flip($sessionIdFields));
		return Billrun_Util::generateArrayStamp($sessionIdArr);
	}

	/**
	 * Runs Billrun process
	 * 
	 * @return type Data generated by process
	 */
	protected function process() {
		$options = $this->config;
		$options['parser'] = 'none';
		$processor = Billrun_Processor::getInstance($options);
		if ($processor) {
			$processor->addDataRow($this->event);
			$processor->process($this->config);
			$data = $processor->getData()['data'];
			return current($processor->getAllLines());
		}
	}

	/**
	 * Send respond
	 * 
	 * @param type $data
	 * @return boolean
	 */
	protected function respond($data) {
		$encoder = Billrun_Encoder_Manager::getEncoder(array(
				'encoder' => $this->config['response']['encode']
		));
		if (!$encoder) {
			Billrun_Factory::log('Cannot get encoder', Zend_Log::ALERT);
			return false;
		}

		$responderParams = array(
			'data' => $data,
			'config' => $this->config,
		);
		$responder = Billrun_ActionManagers_Realtime_Responder_Manager::getResponder($responderParams);
		if (!$responder) {
			Billrun_Factory::log('Cannot get responder', Zend_Log::ALERT);
			return false;
		}

		$params = array('root' => 'response');
		$response = $encoder->encode($responder->getResponse(), $params);
		$this->setOutput(array($response, 1));

		return $response;
	}

	/**
	 * Checks if the row should really decrease balance from the subscriber's balance, or just pretend
	 * 
	 * @return boolean
	 */
	protected function isPretend($event) {
		$pretendField = isset($this->config['realtime']['pretend_field']) ? $this->config['realtime']['pretend_field'] : 'pretend';
		return (isset($event['uf'][$pretendField]) && $event['uf'][$pretendField]);
	}

	protected function getPermissionLevel() {
		return Billrun_Traits_Api_IUserPermissions::PERMISSION_ADMIN;
	}

}
