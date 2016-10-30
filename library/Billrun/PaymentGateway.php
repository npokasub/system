<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */
require_once APPLICATION_PATH . '/library/vendor/autoload.php';
require_once APPLICATION_PATH . '/application/controllers/Action/Pay.php';
require_once APPLICATION_PATH . '/application/controllers/Action/Collect.php';

/**
 * This class represents a payment gateway
 *
 * @since    5.2
 */
abstract class Billrun_PaymentGateway {

	use Billrun_Traits_Api_PageRedirect;

	protected $omnipayName;
	protected $omnipayGateway;
	protected static $paymentGateways;
	protected $redirectUrl;
	protected $EndpointUrl;
	protected $saveDetails;
	protected $billrunName;
	protected $transactionId;
	protected $subscribers;
	protected $returnUrl;

	private function __construct() {

		if ($this->supportsOmnipay()) {
			$this->omnipayGateway = Omnipay\Omnipay::create($this->getOmnipayName());
		}

		if (empty($this->returnUrl)) {
			$this->returnUrl = Billrun_Factory::config()->getConfigValue('billrun.return_url');
		}
	}


	public function __call($name, $arguments) {
		if ($this->supportsOmnipay()) {
			return call_user_func_array(array($this->omnipayGateway, $name), $arguments);
		}
		throw new Exception('Method ' . $name . ' is not supported');
	}

	/**
	 * 
	 * @param string $name the payment gateway name
	 * @return Billrun_PaymentGateway
	 */
	public static function getInstance($name) {
		if (isset(self::$paymentGateways[$name])) {
			$paymentGateway = self::$paymentGateways[$name];
		} else {
			$subClassName = __CLASS__ . '_' . $name;
			if (@class_exists($subClassName)) {
				$paymentGateway = new $subClassName();
				self::$paymentGateways[$name] = $paymentGateway;
			}
		}
		return isset($paymentGateway) ? $paymentGateway : NULL;
	}

	public function supportsOmnipay() {
		return !is_null($this->omnipayName);
	}

	public function getOmnipayName() {
		return $this->omnipayName;
	}

	/**
	 * Redirect to the payment gateway page of card details for getting Billing Agreement id.
	 * 
	 * @param Int $aid - Account id
	 * @param String $returnUrl - The page to redirect the client after success of the whole process.
	 * @param Int $timestamp - Unix timestamp
	 * @return Int - Account id
	 */
	public function redirectForToken($aid, $returnUrl, $timestamp) {
		$this->getToken($aid, $returnUrl);
		$this->updateSessionTransactionId();

		// Signal starting process.
		$this->signalStartingProcess($aid, $timestamp);
		$this->forceRedirect($this->redirectUrl);
	}

	/**
	 * Check if the payment gateway is supported by Billrun.
	 * 
	 * @param $gateway - Payment Gateway object.
	 * @return Boolean
	 */
	public function isSupportedGateway($gateway) {
		$supported = Billrun_Factory::config()->getConfigValue('PaymentGateways.supported');
		return in_array($gateway, $supported);
	}

	
	/**
	 * Updates the current transactionId.
	 * 
	 */
	abstract function updateSessionTransactionId();
	
	/**
	 * Get the Redirect url of the payment gateway.
	 * 
	 * @param $result - response from the payment gateway.
	 */
	abstract protected function updateRedirectUrl($result);

	/**
	 * Build request for start a transaction of getting Billing Agreement id.
	 * 
	 * @param Int $aid - Account id
	 * @param String $returnUrl - The page to redirect the client after success of the whole process.
	 * @param String $okPage - the action to be called after success in filling personal details.
	 * @return array - represents the request
	 */
	abstract protected function buildPostArray($aid, $returnUrl, $okPage);

	/**
	 *  Build request to Query for getting transaction details.
	 * 
	 * @param string $txId - String that represents the transaction.
	 * @return array - represents the request
	 */
	abstract protected function buildTransactionPost($txId);

	/**
	 * Get the name of the parameter that the payment gateway returns to represent billing agreement id.
	 * 
	 * @return String - the name.
	 */
	abstract public function getTransactionIdName();

	/**
	 * Query the response to getting needed details.
	 * 
	 * @param $result - response from the payment gateway.
	 */
	abstract protected function getResponseDetails($result);

	/**
	 * Choosing the wanted details from the response to save in the db.
	 * 
	 * @return array - payment gateway object with the wanted details
	 */
	abstract protected function buildSetQuery();

	/**
	 * Sets necessary parameters for connection to chosen payment gateway.
	 * 
	 * @param Array $params - details of the payment gateway.
	 * @return Array - Array of gateway credentials.
	 */
	abstract protected function setConnectionParameters($params);

	/**
	 * Checks against the chosen payment gateway if the credentials passed are correct.
	 * 
	 * @param Array $params - details of the payment gateway.
	 * @return Boolean - true if the credentials are correct.
	 */
	abstract public function authenticateCredentials($params);

	/**
	 * Sending request to chosen payment gateway to charge the subscriber according to his bills.
	 * 
	 * @param array $gatewayDetails - Details of the chosen payment gateway
	 * @return String - Status of the payment.
	 */
	abstract protected function pay($gatewayDetails);

	/**
	 * Checks if the payment is pending.
	 * 
	 * @param String $status - status of the payment that returned from the payment gateway
	 * @return Boolean - true if the status means pending payment
	 */
	abstract protected function isPending($status);

	/**
	 * Checks if the payment is rejected.
	 * 
	 * @param String $status - status of the payment that returned from the payment gateway
	 * @return Boolean - true if the status means rejected payment
	 */
	abstract protected function isRejected($status);

	/**
	 * Checks if the payment is accepted.
	 * 
	 * @param String $status - status of the payment that returned from the payment gateway
	 * @return Boolean - true if the status means completed payment
	 */
	abstract protected function isCompleted($status);

	/**
	 * Check the status of previously pending payment.
	 * 
	 * @param string $txId - String that represents the transaction.
	 * @return string - Payment status.
	 */
	abstract public function verifyPending($txId);

	/**
	 * True if the payment gateway can return pending has a status of a payment. 
	 * 
	 */
	abstract public function hasPendingStatus();

	/**
	 * Redirect to the payment gateway page of card details.
	 * 
	 * @param $aid - Account id of the client.
	 * @param $returnUrl - The page to redirect the client after success of the whole process.
	 */
	protected function getToken($aid, $returnUrl) {
		$okTemplate = Billrun_Factory::config()->getConfigValue('PaymentGateways.ok_page');
		$okPage = sprintf($okTemplate, $this->billrunName);
		$postArray = $this->buildPostArray($aid, $returnUrl, $okPage);
		$postString = http_build_query($postArray);
		if (function_exists("curl_init")) {
			$result = Billrun_Util::sendRequest($this->EndpointUrl, $postString, Zend_Http_Client::POST, array('Accept-encoding' => 'deflate'), null, 0);
		}
		$this->updateRedirectUrl($result);
	}

	/**
	 * Saving Details to Subscribers collection and redirect to our success page or the merchant page if suppiled.
	 * 
	 * @param $txId - String that represents the transaction.
	 */
	public function saveTransactionDetails($txId) {
		$postArray = $this->buildTransactionPost($txId);
		$postString = http_build_query($postArray);
		if (function_exists("curl_init")) {
			$result = Billrun_Util::sendRequest($this->EndpointUrl, $postString, Zend_Http_Client::POST, array('Accept-encoding' => 'deflate'), null, 0);
		}
		if ($this->getResponseDetails($result) === FALSE) {
			throw new Exception("Operation Failed. Try Again...");
		}
		if (empty($this->saveDetails['aid'])) {
			$this->saveDetails['aid'] = $this->getAidFromProxy($txId);
		}
		if (!$this->validatePaymentProcess($txId)) {
			throw new Exception("Too much time passed");
		}
		$this->saveAndRedirect();
	}

	protected function saveAndRedirect() {
		$today = new MongoDate();
		$this->subscribers = Billrun_Factory::db()->subscribersCollection();
		$setQuery = $this->buildSetQuery();
		$this->subscribers->update(array('aid' => (int) $this->saveDetails['aid'], 'from' => array('$lte' => $today), 'to' => array('$gte' => $today), 'type' => "account"), array('$set' => $setQuery));

		if (isset($this->saveDetails['return_url'])) {
			$returnUrl = (string) $this->saveDetails['return_url'];
		} else {
			$account = $this->subscribers->query(array('aid' => (int) $this->saveDetails['aid'], 'from' => array('$lte' => $today), 'to' => array('$gte' => $today), 'type' => "account"))->cursor()->current();
			$returnUrl = $account['tennant_return_url'];
		}
		$this->forceRedirect($returnUrl);
	}

	protected function signalStartingProcess($aid, $timestamp) {
		$paymentColl = Billrun_Factory::db()->creditproxyCollection();
		$query = array("name" => $this->billrunName, "tx" => $this->transactionId, "aid" => $aid);
		$paymentRow = $paymentColl->query($query)->cursor()->current();
		if (!$paymentRow->isEmpty()) {
			if (isset($paymentRow['done'])) {
				return;
			}
			return;
		}

		// Signal start process
		$query['t'] = $timestamp;
		$paymentColl->insert($query);
	}

	/**
	 * Check that the process that has now ended, actually started, and not too long ago.
	 * 
	 * @param string $txId -String that represents the transaction.
	 * @return boolean
	 */
	protected function validatePaymentProcess($txId) {
		$paymentColl = Billrun_Factory::db()->creditproxyCollection();

		// Get is started
		$query = array("name" => $this->billrunName, "tx" => $txId, "aid" => $this->saveDetails['aid']);
		$paymentRow = $paymentColl->query($query)->cursor()->current();
		if ($paymentRow->isEmpty()) {
			// Received message for completed charge, 
			// but no indication for charge start
			return false;
		}

		// Check how long has passed.
		$timePassed = time() - $paymentRow['t'];

		// Three minutes
		// TODO: What value should we put here?
		// TODO: Change to 4 hours, move to conf
		if ($timePassed > 60 * 60 * 4) {
			// Change indication in DB for failure.
			$paymentRow['done'] = false;
		} else {
			// Signal done
			$paymentRow['done'] = true;
		}

		$paymentColl->updateEntity($paymentRow);

		return $paymentRow['done'];
	}

	/**
	 * Get aid from proxy collection if doesn't passed through the payment gateway.
	 * 
	 * @param string $txId -String that represents the transaction.
	 * @return Int - Account id
	 */
	protected function getAidFromProxy($txId) {
		$paymentColl = Billrun_Factory::db()->creditproxyCollection();
		$query = array("name" => $this->billrunName, "tx" => $txId);
		$paymentRow = $paymentColl->query($query)->cursor()->current();
		return $paymentRow['aid'];
	}

	/**
	 * Responsible for paying payments and classifying payments responses: completed, pending or rejected.
	 * 
	 * @param string $stamp - Billrun key that represents the cycle.
	 *
	 */
	public function makePayment($stamp) {
		$today = new MongoDate();
		$paymentParams = array(
			'dd_stamp' => $stamp
		);
		if (!Billrun_Bill_Payment::removePayments($paymentParams)) { // removePayments if this is a rerun
			throw new Exception('Error removing payments before rerun');
		}
		$customers = iterator_to_array(self::getCustomers());
		$involvedAccounts = array();
		$options = array('collect' => FALSE);
		$data = array();
		$subscribers = Billrun_Factory::db()->subscribersCollection();
		$customers_aid = array_map(function($ele) {
			return $ele['aid'];
		}, $customers);

		$subscribers = $subscribers->query(array('aid' => array('$in' => $customers_aid), 'from' => array('$lte' => $today), 'to' => array('$gte' => $today), 'type' => "account"))->cursor();
		foreach ($subscribers as $subscriber) {
			$subscribers_in_array[$subscriber['aid']] = $subscriber;
		}

		foreach ($customers as $customer) {
			$subscriber = $subscribers_in_array[$customer['aid']];
			$involvedAccounts[] = $paymentParams['aid'] = $customer['aid'];
			$paymentParams['billrun_key'] = $customer['billrun_key'];
			$paymentParams['amount'] = $customer['due'];
			$paymentParams['source'] = $customer['source'];
			$gatewayDetails = $subscriber['payment_gateway'];
			$gatewayDetails['amount'] = $customer['due'];
			$gatewayDetails['currency'] = $customer['currency'];
			$gatewayName = $gatewayDetails['name'];
			$gateway = self::getInstance($gatewayName);
			$payment = payAction::pay('credit', array($paymentParams), $options)[0];
			$paymentStatus = $gateway->pay($gatewayDetails);
			$response = self::checkPaymentStatus($paymentStatus, $gateway);
			$payment->setPaymentGateway($gatewayName, $gateway->transactionId);
			self::updateAccordingToStatus($response, $payment, $gatewayName);
		}
	}

	protected function getCustomers() {
		$billsColl = Billrun_Factory::db()->billsCollection();
		$sort = array(
			'$sort' => array(
				'type' => 1,
				'due_date' => -1,
			),
		);
		$group = array(
			'$group' => array(
				'_id' => '$aid',
				'suspend_debit' => array(
					'$first' => '$suspend_debit',
				),
				'type' => array(
					'$first' => '$type',
				),
				'payment_method' => array(
					'$first' => '$payment_method',
				),
				'due' => array(
					'$sum' => '$due',
				),
				'aid' => array(
					'$first' => '$aid',
				),
				'billrun_key' => array(
					'$first' => '$billrun_key',
				),
				'lastname' => array(
					'$first' => '$lastname',
				),
				'firstname' => array(
					'$first' => '$firstname',
				),
				'bill_unit' => array(
					'$first' => '$bill_unit',
				),
				'bank_name' => array(
					'$first' => '$bank_name',
				),
				'due_date' => array(
					'$first' => '$due_date',
				),
				'source' => array(
					'$first' => '$source',
				),
				'currency' => array(
					'$first' => '$currency',
				),
			),
		);
		$match = array(
			'$match' => array(
				'due' => array(
					'$gt' => Billrun_Bill::precision,
				),
				'payment_method' => array(
					'$in' => array('Credit'),
				),
				'suspend_debit' => NULL,
			),
		);
		$res = $billsColl->aggregate($sort, $group, $match);
		return $res;
	}

	/**
	 * Get from config the merchant credentials for connecting to the payment gateway. 
	 * 
	 * @param paymentGateway $gateway - the gateway the client chose to pay through.
	 * @return array - the credentials that are a must for connection.
	 */
	protected function getConnectionCredentials($gateway) {
		$paymentGateways = Billrun_Factory::config()->getConfigValue('payment_gateways');
		$gatewayName = $gateway->billrunName;
		$gatewayDetails = array_filter($paymentGateways, function($paymentGateway) use ($gatewayName) {
			return $paymentGateway['name'] == $gatewayName;
		});
		$gatewayCredentials = current($gatewayDetails);
		$requiredCredentials = $gateway->setConnectionParameters($gatewayCredentials['params']);
		return $requiredCredentials;
	}

	/**
	 * Checking the state of the payment - completed, pending or rejected. 
	 * 
	 * @param paymentGateway $status - status returned from the payment gateway.
	 * @param paymentGateway $gateway - the gateway the client chose to pay through.
	 * @return Array - the status and stage of the payment.
	 */
	public function checkPaymentStatus($status, $gateway) {
		if ($gateway->isCompleted($status)) {
			return array('status' => $status, 'stage' => "Completed");
		} else if ($gateway->isPending($status)) {
			return array('status' => $status, 'stage' => "Pending");
		} else if ($gateway->isRejected($status)) {
			return array('status' => $status, 'stage' => "Rejected");
		} else {
			throw new Exception("Unknown status");
		}
	}

	public function updateAccordingToStatus($response, $payment, $gatewayName) {
		if ($response['stage'] == "Completed") { // payment succeeded 
			$payment->updateConfirmation();
			$payment->setPaymentStatus($response['status'], $gatewayName);
		} else if ($response['stage'] == "Pending") { // handle pending
			$payment->setPaymentStatus($response['status'], $gatewayName);
		} else { //handle rejections
			if (!$payment->isRejected()) {
				Billrun_Factory::log('Rejecting transaction  ' . $payment->getId(), Zend_Log::DEBUG);
				$rejection = $payment->getRejectionPayment($response['status']);
				$rejection->save();
				$payment->markRejected();
			} else {
				Billrun_Factory::log('Transaction ' . $payment->getId() . ' already rejected', Zend_Log::NOTICE);
			}
		}
	}

	protected function getGatewayCredentials($gatewayName) {
		$gateways = Billrun_Factory::config()->getConfigValue(payment_gateways);
		$gateway = array_filter($gateways, function($paymentGateway) use ($gatewayName) {
			return $paymentGateway['name'] == $gatewayName;
		});
		$gatewayDetails = current($gateway);
		return $gatewayDetails['params'];
	}

}
