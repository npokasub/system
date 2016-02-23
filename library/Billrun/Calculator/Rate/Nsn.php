<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing calculator class for nsn records
 *
 * @package  calculator
 * @since    0.5
 */
class Billrun_Calculator_Rate_Nsn extends Billrun_Calculator_Rate {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = "nsn";

	public function __construct($options = array()) {
		parent::__construct($options);
		$this->loadRates();
	}

	/**
	 * @see Billrun_Calculator_Rate::getLineVolume
	 */
	protected function getLineVolume($row, $usage_type) {
		if (in_array($usage_type, array('call', 'incoming_call'))) {
			if (isset($row['duration'])) {
				return $row['duration'];
			}
		}
		if ($usage_type == 'sms') {
			return 1;
		}
		return null;
	}

	/**
	 * @see Billrun_Calculator_Rate::getLineUsageType
	 */
	protected function getLineUsageType($row) {
		switch ($row['record_type']) {
			case '08':
			case '09':
				return 'sms';
			case '02':
			case '12':
			case '30':
				return 'incoming_call';
			case '11':
			case '01':
			case '31':
			default:
				return 'call';
		}
		return 'call';
	}

	/**
	 * @see Billrun_Calculator_Rate::getLineRate
	 */
	protected function getLineRate($row, $usage_type) {
		$record_type = $row->get('record_type');
		$called_number = $row->get('called_number');
		$ocg = $row->get('out_circuit_group');
		$icg = $row->get('in_circuit_group');
		$line_time = $row->get('urt');
		$matchedRate = false;

		if ($record_type == "01" || //MOC call
				($record_type == "11" && in_array($icg, Billrun_Util::getRoamingCircuitGroups()) &&
			$ocg != '3060' && $ocg != '3061') // Roaming on Cellcom and not redirection
		) {
			$matchedRate = $this->getRateByParams($called_number, $usage_type, $line_time, $ocg);
		} else if ($record_type == '30' && isset($row['ild_prefix'])) {
			$called_number = preg_replace('/^016/', '', $called_number);
			$matchedRate = $this->getRateByParams($called_number, $usage_type, $line_time);
		} else if ($record_type == "31" //STC call
			&& in_array($icg, Billrun_Util::getRoamingCircuitGroups()) &&
			$ocg != '3060' && $ocg != '3061' // Roaming on Cellcom and not redirection
		) { 
			$matchedRate = $this->getRateByParams($called_number, $usage_type, $line_time, $ocg);
		}

		return $matchedRate;
	}

	/**
	 * Get a matching rate by the supplied params
	 * @param string $called_number the number called
	 * @param string $usage_type the usage type (call / sms ...)
	 * @param MongoDate $urt the time of the event
	 * @param string $ocg the out circuit group of the event. If not supplied, ocg will be ignored in the search.
	 * @return Mongodloid_Entity the matched rate or UNRATED rate if none found
	 */
	protected function getRateByParams($called_number, $usage_type, $urt, $ocg = null) {
		$matchedRate = $this->rates['UNRATED'];
		$called_number_prefixes = Billrun_Util::getPrefixes($called_number);
		foreach ($called_number_prefixes as $prefix) {
			if (isset($this->rates[$prefix])) {
				foreach ($this->rates[$prefix] as $rate) {
					if (isset($rate['rates'][$usage_type]) && (!isset($rate['params']['fullEqual']) || $prefix == $called_number)) {
						if ($rate['from'] <= $urt && $rate['to'] >= $urt) {
							if (is_null($ocg)) {
								$matchedRate = $rate;
								break 2;
							} else {
								foreach ($rate['params']['out_circuit_group'] as $groups) {
									if ($groups['from'] <= $ocg && $groups['to'] >= $ocg) {
										$matchedRate = $rate;
										break 3;
									}
								}
							}
						}
					}
				}
			}
		}
		return $matchedRate;
	}
	//todo: move the regex and rate keys to config
	protected function getLineAdditionalValues($row) {
		$circuit_groups = Billrun_Factory::config()->getConfigValue('Rate_Nsn.calculator.whloesale_incoming_rate_key');
		$rate_key = null;
		if( in_array($row['record_type'],array('30','11')) && ($row['in_circuit_group'] > $circuit_groups['icg']['min']) && 
			($row['in_circuit_group'] < $circuit_groups['icg']['max']) ) {
			if(preg_match('/^(997|972)?1800016/',$row['called_number'])) {
				$rate_key = 'IL_TF';
			} else if(preg_match('/^(997|972)?1700016/',$row['called_number'])) {
				$rate_key = 'IL_1700';
			}
		} else if(in_array($row['record_type'],array('12','02')) && preg_match('/(^RCEL)|(^$)/',$row['out_circuit_group_name'])
			&& (preg_match('/^(972)?5/',$row['called_number']))) {
			$rate_key = 'IL_MOBILE';
		}
		$additional_properties = $this->getAdditionalProperties();
		if(isset($rate_key)){
			return array($additional_properties['wholesale_rate_key'] => $rate_key);
		}
		return array();
	}
		
	protected function getAdditionalProperties() {
		return Billrun_Factory::config()->getConfigValue('Rate_Nsn.calculator.additional_properties');
	}
		
}
