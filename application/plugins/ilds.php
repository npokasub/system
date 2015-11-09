<?php

class ildsPlugin extends Billrun_Plugin_BillrunPluginFraud {

	/**
	 * plugin name
	 *
	 * @var string
	 */
	protected $name = 'ilds';
	
	protected $threshold;
	
	protected $fraud_event_name = 'ILDS';

	public function __construct($options = array()) {
		parent::__construct($options);
		$this->threshold = floatval( Billrun_Factory::config()->getConfigValue($this->name . '.threshold', 100));
	}

	/**
	 * method to collect data which need to be handle by event
	 * db.lines.aggregate(
	 * {$match:{source:"ilds", unified_record_time:{$gt:ISODate('2015-08-#ilds.billrun.charging_day# 00:00:00+03:00')}, price_customer:{$exists:1}, billrun:{$exists:0}}}, 
	 * {$group:{_id:"$caller_phone_no", s:{$sum:"$price_customer"}}}, 
	 * {$match:{"s":{$gte:#ilds.threshold#}}}, 
	 * {$group:{_id:null, s:{$sum:1}}}
	 * )
	 */
	public function handlerCollect($options) {
		if($this->getName() != $options['type']) { 
			return FALSE;
		}
		
		Billrun_Factory::log()->log(strtoupper($this->name) .  " fraud collect handler triggered",  Zend_Log::DEBUG);

		$ilds_db_settings = Billrun_Factory::config()->getConfigValue('ilds.db');
		$lines = Billrun_Factory::db($ilds_db_settings)->linesCollection();
		$charge_time = Billrun_Util::getLastChargeTime(true, Billrun_Factory::config()->getConfigValue('ilds.billrun.charging_day', 8));

		$base_match = array(
			'$match' => array(
				'source' => $this->name,
			)
		);

		$where = array(
			'$match' => array(
				'event_stamp' => array('$exists' => false),
				'deposit_stamp' => array('$exists' => false),
//				'call_start_dt' => array('$gte' => $charge_time),
				'unified_record_time' => array('$gte' => new MongoDate($charge_time)),
				'price_customer' => array('$exists' => true),
				'billrun' => array('$exists' => false),
			),
		);

		$group = array(
			'$group' => array(
				"_id" => '$caller_phone_no',
				'msisdn' => array('$first' => '$caller_phone_no'),
				"total" => array('$sum' => '$price_customer'),
				'lines_stamps' => array('$addToSet' => '$stamp'),
			),
		);

		$project = array(
			'$project' => array(
				'caller_phone_no' => '$_id',
				'_id' => 0,
				'msidsn' => 1,
				'total' => 1,
				'lines_stamps' => 1,
			),
		);

		$having = array(
			'$match' => array(
				'total' => array(
					'$gte' => $this->threshold,
				)
			),
		);

		$sort = array(
			'$sort' => array(
				'total' => -1
			),
		);
		
		$ret = $lines->aggregate($base_match, $where, $group, $project, $having, $sort);
		Billrun_Factory::log()->log(strtoupper($this->name) . " fraud plugin found " . count($ret) . " items",  Zend_Log::DEBUG);

		return $ret;
	}

	/**
	 * Add data that is needed to use the event object/DB document later
	 * @param Array|Object $event the event to add fields to.
	 * @return Array|Object the event object with added fields
	 */
	protected function addAlertData(&$newEvent) {
		
		$newEvent['units']	= 'NIS';
		$newEvent['value']	= $newEvent['total'];
		$newEvent['threshold'] = $this->threshold;
		$newEvent['event_type']	= $this->fraud_event_name;
		$newEvent['msisdn']	= $newEvent['caller_phone_no'];
		return $newEvent;
	}
	
	public function handlerMarkDown(&$items, $pluginName) {
		parent::handlerMarkDown($items, $pluginName);
		if ($pluginName != $this->getName() || !$items) {
			return;
		}
		
		$ret = array();
		$ilds_db_settings = Billrun_Factory::config()->getConfigValue('ilds.db');
		$lines = Billrun_Factory::db($ilds_db_settings)->linesCollection();
		foreach ($items as &$item) {
			$ret[] = $lines->update(	array('stamp' => array('$in' => $item['lines_stamps'])),
									array('$set' => array(
										'deopsit_stamp' => $item['event_stamp'],
										'event_stamp' => $item['event_stamp'],
									)),
									array('multiple' => 1));
		}
		return $ret;
	}

}