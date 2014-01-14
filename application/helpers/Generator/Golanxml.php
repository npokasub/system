<?php

/**
 * @category   Application
 * @package    Helpers
 * @subpackage Generator
 * @copyright  Copyright (C) 2013 S.D.O.C. LTD. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

/**
 * Golan invoices generator
 *
 * @todo this class should inherit from abstract class Generator_Golan
 * @package    Generator
 * @subpackage Golanxml
 * @since      1.0
 */
class Generator_Golanxml extends Billrun_Generator {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	protected static $type = 'golanxml';
	protected $offset = 0;
	protected $size = 10000;
	protected $data = array();
	protected $extras_start_date;
	protected $extras_end_date;
	protected $flat_start_date;
	protected $flat_end_date;
	protected $rates;
	protected $plans;
	protected $data_rate;
	protected $lines_coll;

	public function __construct($options) {
		parent::__construct($options);
		if (isset($options['page'])) {
			$this->offset = intval($options['page']);
		}
		if (isset($options['size'])) {
			$this->size = intval($options['size']);
		}

		$this->lines_coll = Billrun_Factory::db()->linesCollection();
		$this->loadRates();
		$this->loadPlans();
	}

	public function load() {
		$billrun = Billrun_Factory::db()->billrunCollection();
		Billrun_Factory::log()->log('Loading ' . $this->size . ' billrun documents with offset ' . $this->offset, Zend_Log::INFO);
		$resource = $billrun
				->query('billrun_key', $this->stamp)
				->exists('invoice_id')
//				->notExists('invoice_file')
				->cursor()->timeout(-1)
				->sort(array("aid" => 1))
				->skip($this->offset * $this->size)
				->limit($this->size);

		// @TODO - there is issue with the timeout; need to be fixed
		//         meanwhile, let's pull the lines right after the query
		foreach ($resource as $row) {
			$this->data[] = $row;
		}
		Billrun_Factory::log()->log("aggregator documents loaded: " . count($this->data), Zend_Log::INFO);

		Billrun_Factory::dispatcher()->trigger('afterGeneratorLoadData', array('generator' => $this));
	}

	public function generate() {
		Billrun_Factory::log('Generating invoices...', Zend_log::INFO);
		// use $this->export_directory
		$i = 1;
		foreach ($this->data as $row) {
			Billrun_Factory::log('Current index ' . $i++);
			$this->createXmlInvoice($row);
		}
	}

	/**
	 * create xml invoice
	 * can be called from outside
	 * 
	 * @param Mongodloid_Entity $row billrun collection document
	 * @param array $lines array of lines to get into the xml
	 */
	public function createXmlInvoice($row, $lines = null) {
		$invoice_id = $row->get('invoice_id');
		$invoice_filename = $row['billrun_key'] . '_' . str_pad($row['aid'], 9, '0', STR_PAD_LEFT) . '_' . str_pad($invoice_id, 11, '0', STR_PAD_LEFT) . '.xml';
		$invoice_file_path = $this->export_directory . '/' . $invoice_filename;
		if (!file_exists($invoice_file_path)) {
			$xml = $this->getXML($row, $lines);
			$this->createXmlFile($invoice_filename, $xml->asXML());
			$this->setFileStamp($row, $invoice_filename);
			Billrun_Factory::log()->log("invoice file " . $invoice_filename . " created for account " . $row->get('aid'), Zend_Log::INFO);
		} else {
			Billrun_Factory::log()->log('Skipping filename ' . $invoice_filename, Zend_Log::INFO);
		}
//		$this->addRowToCsv($invoice_id, $row->get('aid'), $total, $total_ilds);
	}

	/**
	 * receives a billrun document (account invoice)
	 * @param Mongodloid_Entity $row
	 * @return SimpleXMLElement the invoice in xml format
	 */
	protected function getXML($row, $lines = null) {
		$invoice_total_gift = 0;
		$invoice_total_above_gift = 0;
		$invoice_total_outside_gift_vat = 0;
		$invoice_total_manual_correction = 0;
		$invoice_total_manual_correction_credit = 0;
		$invoice_total_manual_correction_charge = 0;
		$invoice_total_outside_gift_novat = 0;
		$billrun_key = $row['billrun_key'];
		$aid = $row['aid'];
		Billrun_Factory::log()->log("xml account " . $aid, Zend_Log::INFO);
		// @todo refactoring the xml generation to another class
		$xml = $this->basic_xml();
		$xml->TELECOM_INFORMATION->VAT_VALUE = $this->displayVAT($row['vat']);
		$xml->INV_CUSTOMER_INFORMATION->CUSTOMER_CONTACT->EXTERNALACCOUNTREFERENCE = $aid;
		if (is_null($lines) && (!isset($this->subscribers) || in_array(0, $this->subscribers))) {
			$lines = $this->get_lines($row);
		}
		foreach ($row['subs'] as $subscriber) {
			$sid = $subscriber['sid'];
			$subscriber_flat_costs = $this->getFlatCosts($subscriber);
			if (!is_array($subscriber_flat_costs) || empty($subscriber_flat_costs)) {
				Billrun_Factory::log('Missing flat costs for subscriber ' . $sid, Zend_Log::INFO);
			}
			if (is_null($subscriber['current_plan']) && is_null($subscriber['next_plan'])) {
				continue;
			}

			$subscriber_inf = $xml->addChild('SUBSCRIBER_INF');
			$subscriber_inf->SUBSCRIBER_DETAILS->SUBSCRIBER_ID = $subscriber['sid'];

			$billing_records = $subscriber_inf->addChild('BILLING_LINES');

			if ($this->billingLinesNeeded($sid)) {
				if (is_null($lines)) {
					$subscriber_lines = $this->get_lines($subscriber);
				} else {
					$func = function($line) use ($sid) {
								return $line['sid'] == $sid;
							};
					$subscriber_lines = array_filter($lines, $func);
				}
				foreach ($subscriber_lines as $line) {
					if (!$line->isEmpty() && $line['type'] != 'ggsn') {
						$line->collection($this->lines_coll);
						$billing_record = $billing_records->addChild('BILLING_RECORD');
						$this->updateBillingRecord($billing_record, $this->getDate($line), $this->getTariffItem($line, $subscriber), $this->getCalledNo($line), $this->getCallerNo($line), $this->getUsageVolume($line), $this->getCharge($line), $this->getCredit($line), $this->getTariffKind($line['usaget']), $this->getAccessPrice($line), $this->getInterval($line), $this->getRate($line), $this->getIntlFlag($line), $this->getDiscountUsage($line), $this->getRoaming($line), $this->getServingNetwork($line), $this->getLineTypeOfBillingChar($line));
					}
				}
				$subscriber_aggregated_data = $this->get_subscriber_aggregated_data_lines($subscriber);
				foreach ($subscriber_aggregated_data as $line) {
					$billing_record = $billing_records->addChild('BILLING_RECORD');
					$this->updateBillingRecord($billing_record, $line['day'], $line['rate_key'], '', '', $line['usage_volume'], $line['aprice'], 0, $line['tariff_kind'], 0, $line['interval'], $line['rate_price'], 0, $line['discount_usage'], 0, '', 'D');
				}
			}

			$subscriber_gift_usage = $subscriber_inf->addChild('SUBSCRIBER_GIFT_USAGE');
			$subscriber_gift_usage->GIFTID_GIFTCLASSNAME = "GC_GOLAN";
			$subscriber_gift_usage->GIFTID_GIFTNAME = $this->getPlanName($subscriber);
			$subscriber_gift_usage->TOTAL_FREE_COUNTER_COST = (isset($subscriber_flat_costs['vatable']) ? $subscriber_flat_costs['vatable'] : 0) + (isset($subscriber_flat_costs['vat_free']) ? $subscriber_flat_costs['vat_free'] : 0);
			//$subscriber_gift_usage->VOICE_COUNTERVALUEBEFBILL = ???;
			//$subscriber_gift_usage->VOICE_FREECOUNTER = ???;
			//$subscriber_gift_usage->VOICE_FREECOUNTERCOST = ???;
			$subscriber_gift_usage->VOICE_FREEUSAGE = 0; // flat calls usage
			$subscriber_gift_usage->VOICE_ABOVEFREECOST = 0; // over plan calls cost
			$subscriber_gift_usage->VOICE_ABOVEFREEUSAGE = 0; // over plan calls usage
			$subscriber_gift_usage->SMS_FREEUSAGE = 0; // flat sms usage
			$subscriber_gift_usage->SMS_ABOVEFREECOST = 0; // over plan sms cost
			$subscriber_gift_usage->SMS_ABOVEFREEUSAGE = 0; // over plan sms usage
			$subscriber_gift_usage->DATA_FREEUSAGE = 0; // flat data usage
			$subscriber_gift_usage->DATA_ABOVEFREECOST = 0; // over plan data cost
			$subscriber_gift_usage->DATA_ABOVEFREEUSAGE = 0; // over plan data usage
			$subscriber_gift_usage->MMS_FREEUSAGE = 0; // flat mms usage
			$subscriber_gift_usage->MMS_ABOVEFREECOST = 0; // over plan mms cost
			$subscriber_gift_usage->MMS_ABOVEFREEUSAGE = 0; // over plan mms usage
			if (isset($subscriber['breakdown']['over_plan']) && is_array($subscriber['breakdown']['over_plan'])) {
				foreach ($subscriber['breakdown']['over_plan'] as $category) {
					foreach ($category as $zone) {
						$subscriber_gift_usage->VOICE_ABOVEFREECOST+=$this->getZoneTotalsFieldByUsage($zone, 'cost', 'call');
						$subscriber_gift_usage->SMS_ABOVEFREECOST+=$this->getZoneTotalsFieldByUsage($zone, 'cost', 'sms');
						$subscriber_gift_usage->DATA_ABOVEFREECOST+=$this->getZoneTotalsFieldByUsage($zone, 'cost', 'data');
						$subscriber_gift_usage->MMS_ABOVEFREECOST+=$this->getZoneTotalsFieldByUsage($zone, 'cost', 'mms');
						$subscriber_gift_usage->VOICE_ABOVEFREEUSAGE+= $this->getZoneTotalsFieldByUsage($zone, 'usagev', 'call');
						$subscriber_gift_usage->SMS_ABOVEFREEUSAGE+= $this->getZoneTotalsFieldByUsage($zone, 'usagev', 'sms');
						$subscriber_gift_usage->DATA_ABOVEFREEUSAGE+=$this->bytesToKB($this->getZoneTotalsFieldByUsage($zone, 'usagev', 'data'));
						$subscriber_gift_usage->MMS_ABOVEFREEUSAGE+= $this->getZoneTotalsFieldByUsage($zone, 'usagev', 'mms');
					}
				}
			}
			if (isset($subscriber['breakdown']['in_plan']) && is_array($subscriber['breakdown']['in_plan'])) {
				foreach ($subscriber['breakdown']['in_plan'] as $category) {
					foreach ($category as $zone) {
						$subscriber_gift_usage->VOICE_FREEUSAGE+=$this->getZoneTotalsFieldByUsage($zone, 'usagev', 'call');
						$subscriber_gift_usage->SMS_FREEUSAGE+=$this->getZoneTotalsFieldByUsage($zone, 'usagev', 'sms');
						$subscriber_gift_usage->DATA_FREEUSAGE+=$this->bytesToKB($this->getZoneTotalsFieldByUsage($zone, 'usagev', 'data'));
						$subscriber_gift_usage->MMS_FREEUSAGE+=$this->getZoneTotalsFieldByUsage($zone, 'usagev', 'mms');
					}
				}
			}

			$subscriber_sumup = $subscriber_inf->addChild('SUBSCRIBER_SUMUP');
			$subscriber_sumup->TOTAL_GIFT = floatval($subscriber_gift_usage->TOTAL_FREE_COUNTER_COST);
//			$subscriber_sumup->TOTAL_ABOVE_GIFT = floatval((isset($subscriber['costs']['over_plan']['vatable']) ? $subscriber['costs']['over_plan']['vatable'] : 0) + (isset($subscriber['costs']['out_plan']['vatable']) ? $subscriber['costs']['out_plan']['vatable'] : 0)); // vatable over/out plan cost
			$subscriber_sumup->TOTAL_ABOVE_GIFT = floatval((isset($subscriber['costs']['over_plan']['vatable']) ? $subscriber['costs']['over_plan']['vatable'] : 0)); // vatable overplan cost
			$subscriber_sumup->TOTAL_OUTSIDE_GIFT_VAT = floatval(isset($subscriber['costs']['out_plan']['vatable']) ? $subscriber['costs']['out_plan']['vatable'] : 0);
			$subscriber_sumup->TOTAL_MANUAL_CORRECTION_CHARGE = floatval(isset($subscriber['costs']['credit']['charge']['vatable']) ? $subscriber['costs']['credit']['charge']['vatable'] : 0) + floatval(isset($subscriber['costs']['credit']['charge']['vat_free']) ? $subscriber['costs']['credit']['charge']['vat_free'] : 0);
			$subscriber_sumup->TOTAL_MANUAL_CORRECTION_CREDIT = floatval(isset($subscriber['costs']['credit']['refund']['vatable']) ? $subscriber['costs']['credit']['refund']['vatable'] : 0) + floatval(isset($subscriber['costs']['credit']['refund']['vat_free']) ? $subscriber['costs']['credit']['refund']['vat_free'] : 0);
			$subscriber_sumup->TOTAL_MANUAL_CORRECTION = floatval($subscriber_sumup->TOTAL_MANUAL_CORRECTION_CHARGE) + floatval($subscriber_sumup->TOTAL_MANUAL_CORRECTION_CREDIT);
			$subscriber_sumup->TOTAL_OUTSIDE_GIFT_NOVAT = floatval((isset($subscriber['costs']['out_plan']['vat_free']) ? $subscriber['costs']['out_plan']['vat_free'] : 0));
			$subscriber_before_vat = $this->getSubscriberTotalBeforeVat($subscriber);
			$subscriber_after_vat = $this->getSubscriberTotalAfterVat($subscriber);
			$subscriber_sumup->TOTAL_VAT = $subscriber_after_vat - $subscriber_before_vat;
			$subscriber_sumup->TOTAL_CHARGE_NO_VAT = $subscriber_before_vat;
			$subscriber_sumup->TOTAL_CHARGE = $subscriber_after_vat;

			$invoice_total_gift+= floatval($subscriber_sumup->TOTAL_GIFT);
			$invoice_total_above_gift+= floatval($subscriber_sumup->TOTAL_ABOVE_GIFT);
			$invoice_total_outside_gift_vat+= floatval($subscriber_sumup->TOTAL_OUTSIDE_GIFT_VAT);
			$invoice_total_manual_correction += floatval($subscriber_sumup->TOTAL_MANUAL_CORRECTION);
			$invoice_total_manual_correction_credit += floatval($subscriber_sumup->TOTAL_MANUAL_CORRECTION_CREDIT);
			$invoice_total_manual_correction_charge += floatval($subscriber_sumup->TOTAL_MANUAL_CORRECTION_CHARGE);
			$invoice_total_outside_gift_novat +=floatval($subscriber_sumup->TOTAL_OUTSIDE_GIFT_NOVAT);

			$subscriber_breakdown = $subscriber_inf->addChild('SUBSCRIBER_BREAKDOWN');
			$breakdown_topic_over_plan = $subscriber_breakdown->addChild('BREAKDOWN_TOPIC');
			$breakdown_topic_over_plan->addAttribute('name', 'GIFT_XXX_OUT_OF_USAGE');
			$out_of_usage_entry = $breakdown_topic_over_plan->addChild('BREAKDOWN_ENTRY');
			$out_of_usage_entry->addChild('TITLE', 'SERVICE-GIFT-GC_GOLAN-' . $this->getNextPlanName($subscriber));
			$out_of_usage_entry->addChild('UNITS', 1);
			$out_of_usage_entry->addChild('COST_WITHOUTVAT', isset($subscriber['breakdown']['in_plan']['base']['service']['cost']) ? $subscriber['breakdown']['in_plan']['base']['service']['cost'] : 0);
			$out_of_usage_entry->addChild('VAT', $this->displayVAT($row['vat']));
			$out_of_usage_entry->addChild('VAT_COST', floatval($out_of_usage_entry->COST_WITHOUTVAT) * floatval($out_of_usage_entry->VAT) / 100);
			$out_of_usage_entry->addChild('TOTAL_COST', floatval($out_of_usage_entry->COST_WITHOUTVAT) + floatval($out_of_usage_entry->VAT_COST));
			$out_of_usage_entry->addChild('TYPE_OF_BILLING', 'GIFT');
//				$out_of_usage_entry->addChild('TYPE_OF_BILLING_CHAR', ?);
			$over_plan_base = isset($subscriber['breakdown']['over_plan']['base']) && is_array($subscriber['breakdown']['over_plan']['base']) ? $subscriber['breakdown']['over_plan']['base'] : array();
			$out_plan_base = isset($subscriber['breakdown']['out_plan']['base']) && is_array($subscriber['breakdown']['out_plan']['base']) ? $subscriber['breakdown']['out_plan']['base'] : array();
			$over_out_plan_base = array_merge_recursive($over_plan_base, $out_plan_base);
			foreach ($over_out_plan_base as $zone_name => $zone) {
				if ($zone_name != 'service') {
//							$out_of_usage_entry->addChild('TITLE', ?);
					foreach (array('call', 'sms', 'data', 'incoming_call', 'mms', 'incoming_sms') as $type) {
						$usagev = $this->getZoneTotalsFieldByUsage($zone, 'usagev', $type);
						if ($usagev > 0) {
							$out_of_usage_entry = $breakdown_topic_over_plan->addChild('BREAKDOWN_ENTRY');
							$out_of_usage_entry->addChild('TITLE', $this->getBreakdownEntryTitle($this->getTariffKind($type), $zone_name));
							$out_of_usage_entry->addChild('UNITS', ($type == "data" ? $this->bytesToKB($usagev) : $usagev));
							$out_of_usage_entry->addChild('COST_WITHOUTVAT', $this->getZoneTotalsFieldByUsage($zone, 'cost', $type));
							$out_of_usage_entry->addChild('VAT', $this->displayVAT($this->getZoneVat($zone)));
							$out_of_usage_entry->addChild('VAT_COST', floatval($out_of_usage_entry->COST_WITHOUTVAT) * floatval($out_of_usage_entry->VAT) / 100);
							$out_of_usage_entry->addChild('TOTAL_COST', floatval($out_of_usage_entry->COST_WITHOUTVAT) + floatval($out_of_usage_entry->VAT_COST));
							$out_of_usage_entry->addChild('TYPE_OF_BILLING', strtoupper($type));
//									$out_of_usage_entry->addChild('TYPE_OF_BILLING_CHAR', ?);
						}
					}
				}
			}

			$breakdown_topic_international = $subscriber_breakdown->addChild('BREAKDOWN_TOPIC');
			$breakdown_topic_international->addAttribute('name', 'INTERNATIONAL');
			$subscriber_intl = array();
			if (isset($subscriber['breakdown']) && is_array($subscriber['breakdown'])) {
				foreach ($subscriber['breakdown'] as $plan) {
					if (isset($plan['intl'])) {
						foreach ($plan['intl'] as $zone_name => $zone) {
							foreach ($zone['totals'] as $usage_type => $usage_totals) {
								if ($usage_totals['cost'] > 0 || $usage_totals['usagev'] > 0) {
									if (isset($subscriber_intl[$zone_name][$usage_type])) {
										$subscriber_intl[$zone_name]['totals'][$usage_type]['usagev']+=$usage_totals['usagev'];
										$subscriber_intl[$zone_name]['totals'][$usage_type]['cost']+=$usage_totals['cost'];
									} else {
										$subscriber_intl[$zone_name]['totals'][$usage_type]['usagev'] = $usage_totals['usagev'];
										$subscriber_intl[$zone_name]['totals'][$usage_type]['cost'] = $usage_totals['cost'];
										$subscriber_intl[$zone_name]['vat'] = $zone['vat'];
									}
								}
							}
						}
					}
				}
			}
			foreach ($subscriber_intl as $zone_name => $zone) {
				foreach ($zone['totals'] as $usage_type => $usage_totals) {
//						$out_of_usage_entry->addChild('TITLE', ?);
					$international_entry = $breakdown_topic_international->addChild('BREAKDOWN_ENTRY');
					$international_entry->addChild('TITLE', $this->getBreakdownEntryTitle($this->getTariffKind($usage_type), $zone_name));
					$international_entry->addChild('UNITS', $usage_totals['usagev']);
					$international_entry->addChild('COST_WITHOUTVAT', $usage_totals['cost']);
					$international_entry->addChild('VAT', $this->displayVAT($zone['vat']));
					$international_entry->addChild('VAT_COST', floatval($international_entry->COST_WITHOUTVAT) * floatval($international_entry->VAT) / 100);
					$international_entry->addChild('TOTAL_COST', floatval($international_entry->COST_WITHOUTVAT) + floatval($international_entry->VAT_COST));
					$international_entry->addChild('TYPE_OF_BILLING', strtoupper($usage_type));
//						$international_entry->addChild('TYPE_OF_BILLING_CHAR', ?);
				}
			}

			$breakdown_topic_special = $subscriber_breakdown->addChild('BREAKDOWN_TOPIC');
			$breakdown_topic_special->addAttribute('name', 'SPECIAL_SERVICES');
			$subscriber_special = array();
			if (isset($subscriber['breakdown']) && is_array($subscriber['breakdown'])) {
				foreach ($subscriber['breakdown'] as $plan) {
					if (isset($plan['special'])) {
						foreach ($plan['special'] as $zone_name => $zone) {
							foreach ($zone['totals'] as $usage_type => $usage_totals) {
								if ($usage_totals['cost'] > 0 || $usage_totals['usagev'] > 0) {
									if (isset($subscriber_special[$zone_name][$usage_type])) {
										$subscriber_special[$zone_name]['totals'][$usage_type]['usagev']+=$usage_totals['usagev'];
										$subscriber_special[$zone_name]['totals'][$usage_type]['cost']+=$usage_totals['cost'];
									} else {
										$subscriber_special[$zone_name]['totals'][$usage_type]['usagev'] = $usage_totals['usagev'];
										$subscriber_special[$zone_name]['totals'][$usage_type]['cost'] = $usage_totals['cost'];
										$subscriber_special[$zone_name]['vat'] = $zone['vat'];
									}
								}
							}
						}
					}
				}
			}
			foreach ($subscriber_special as $zone_name => $zone) {
				foreach ($zone['totals'] as $usage_type => $usage_totals) {
//						$out_of_usage_entry->addChild('TITLE', ?);
					$special_entry = $breakdown_topic_special->addChild('BREAKDOWN_ENTRY');
					$special_entry->addChild('TITLE', $this->getBreakdownEntryTitle($this->getTariffKind($usage_type), $zone_name));
					$special_entry->addChild('UNITS', $usage_totals['usagev']);
					$special_entry->addChild('COST_WITHOUTVAT', $usage_totals['cost']);
					$special_entry->addChild('VAT', $this->displayVAT($zone['vat']));
					$special_entry->addChild('VAT_COST', floatval($special_entry->COST_WITHOUTVAT) * floatval($special_entry->VAT) / 100);
					$special_entry->addChild('TOTAL_COST', floatval($special_entry->COST_WITHOUTVAT) + floatval($special_entry->VAT_COST));
					$special_entry->addChild('TYPE_OF_BILLING', strtoupper($usage_type));
//						$special_entry->addChild('TYPE_OF_BILLING_CHAR', ?);
				}
			}

			$breakdown_topic_roaming = $subscriber_breakdown->addChild('BREAKDOWN_TOPIC');
			$breakdown_topic_roaming->addAttribute('name', 'ROAMING');
			$subscriber_roaming = array();
			if (isset($subscriber['breakdown']) && is_array($subscriber['breakdown'])) {
				foreach ($subscriber['breakdown'] as $plan) {
					if (isset($plan['roaming'])) {
						foreach ($plan['roaming'] as $zone_name => $zone) {
							foreach ($zone['totals'] as $usage_type => $usage_totals) {
								if ($usage_totals['cost'] > 0 || $usage_totals['usagev'] > 0) {
									if (isset($subscriber_roaming[$zone_name][$usage_type])) {
										$subscriber_roaming[$zone_name]['totals'][$usage_type]['usagev']+=$usage_totals['usagev'];
										$subscriber_roaming[$zone_name]['totals'][$usage_type]['cost']+=$usage_totals['cost'];
									} else {
										$subscriber_roaming[$zone_name]['totals'][$usage_type]['usagev'] = $usage_totals['usagev'];
										$subscriber_roaming[$zone_name]['totals'][$usage_type]['cost'] = $usage_totals['cost'];
										$subscriber_roaming[$zone_name]['vat'] = $zone['vat'];
									}
								}
							}
						}
					}
				}
			}
			foreach ($subscriber_roaming as $zone_key => $zone) {
				$subtopic_entry = $breakdown_topic_roaming->addChild('BREAKDOWN_SUBTOPIC');
				$subtopic_entry->addAttribute("name", "");
				$subtopic_entry->addAttribute("plmn", $zone_key);
				foreach ($zone['totals'] as $usage_type => $usage_totals) {
//						$out_of_usage_entry->addChild('TITLE', ?);
					$roaming_entry = $subtopic_entry->addChild('BREAKDOWN_ENTRY');
					$roaming_entry->addChild('TITLE', $this->getBreakdownEntryTitle($usage_type, $this->getNsoftRoamingRate($usage_type)));
					$roaming_entry->addChild('UNITS', ($usage_type == "data" ? $this->bytesToKB($usage_totals['usagev']) : $usage_totals['usagev']));
					$roaming_entry->addChild('COST_WITHOUTVAT', $usage_totals['cost']);
					$roaming_entry->addChild('VAT', $this->displayVAT($zone['vat']));
					$roaming_entry->addChild('VAT_COST', floatval($roaming_entry->COST_WITHOUTVAT) * floatval($roaming_entry->VAT) / 100);
					$roaming_entry->addChild('TOTAL_COST', floatval($roaming_entry->COST_WITHOUTVAT) + floatval($roaming_entry->VAT_COST));
					$roaming_entry->addChild('TYPE_OF_BILLING', strtoupper($usage_type));
//						$roaming_entry->addChild('TYPE_OF_BILLING_CHAR', ?);
				}
			}

			$breakdown_topic_charge = $subscriber_breakdown->addChild('BREAKDOWN_TOPIC');
			$breakdown_topic_charge->addAttribute('name', 'CHARGE_PER_CLI');
			if (isset($subscriber['breakdown']['credit']['charge_vatable']) && is_array($subscriber['breakdown']['credit']['charge_vatable'])) {
				foreach ($subscriber['breakdown']['credit']['charge_vatable'] as $reason => $cost) {
					$charge_entry = $breakdown_topic_charge->addChild('BREAKDOWN_ENTRY');
					$charge_entry->addChild('TITLE', $this->getBreakdownEntryTitle($this->getTariffKind("credit"), $reason));
					$charge_entry->addChild('UNITS', 1);
					$charge_entry->addChild('COST_WITHOUTVAT', $cost);
					$charge_entry->addChild('VAT', $xml->TELECOM_INFORMATION->VAT_VALUE);
					$charge_entry->addChild('VAT_COST', floatval($charge_entry->COST_WITHOUTVAT) * floatval($charge_entry->VAT) / 100);
					$charge_entry->addChild('TOTAL_COST', floatval($charge_entry->COST_WITHOUTVAT) + floatval($charge_entry->VAT_COST));
//					$charge_entry->addChild('TYPE_OF_BILLING', strtoupper($type));
//						$charge_entry->addChild('TYPE_OF_BILLING_CHAR', ?);
				}
			}
			if (isset($subscriber['breakdown']['credit']['charge_vat_free']) && is_array($subscriber['breakdown']['credit']['charge_vat_free'])) {
				foreach ($subscriber['breakdown']['credit']['charge_vat_free'] as $reason => $cost) {
					$charge_entry = $breakdown_topic_charge->addChild('BREAKDOWN_ENTRY');
					$charge_entry->addChild('TITLE', $this->getBreakdownEntryTitle($this->getTariffKind("credit"), $reason));
					$charge_entry->addChild('UNITS', 1);
					$charge_entry->addChild('COST_WITHOUTVAT', $cost);
					$charge_entry->addChild('VAT', 0);
					$charge_entry->addChild('VAT_COST', floatval($charge_entry->COST_WITHOUTVAT) * floatval($charge_entry->VAT) / 100);
					$charge_entry->addChild('TOTAL_COST', floatval($charge_entry->COST_WITHOUTVAT) + floatval($charge_entry->VAT_COST));
//					$charge_entry->addChild('TYPE_OF_BILLING', strtoupper($type));
//						$charge_entry->addChild('TYPE_OF_BILLING_CHAR', ?);
				}
			}

			$breakdown_topic_refund = $subscriber_breakdown->addChild('BREAKDOWN_TOPIC');
			$breakdown_topic_refund->addAttribute('name', 'REFUND_PER_CLI');
			if (isset($subscriber['breakdown']['credit']['refund_vatable']) && is_array($subscriber['breakdown']['credit']['refund_vatable'])) {
				foreach ($subscriber['breakdown']['credit']['refund_vatable'] as $reason => $cost) {
					$refund_entry = $breakdown_topic_refund->addChild('BREAKDOWN_ENTRY');
					$refund_entry->addChild('TITLE', $this->getBreakdownEntryTitle($this->getTariffKind("credit"), $reason));
					$refund_entry->addChild('UNITS', 1);
					$refund_entry->addChild('COST_WITHOUTVAT', $cost);
					$refund_entry->addChild('VAT', $xml->TELECOM_INFORMATION->VAT_VALUE);
					$refund_entry->addChild('VAT_COST', floatval($refund_entry->COST_WITHOUTVAT) * floatval($refund_entry->VAT) / 100);
					$refund_entry->addChild('TOTAL_COST', floatval($refund_entry->COST_WITHOUTVAT) + floatval($refund_entry->VAT_COST));
//					$refund_entry->addChild('TYPE_OF_BILLING', strtoupper($type));
//						$refund_entry->addChild('TYPE_OF_BILLING_CHAR', ?);
				}
			}
			if (isset($subscriber['breakdown']['credit']['refund_vat_free']) && is_array($subscriber['breakdown']['credit']['refund_vat_free'])) {
				foreach ($subscriber['breakdown']['credit']['refund_vat_free'] as $reason => $cost) {
					$refund_entry = $breakdown_topic_refund->addChild('BREAKDOWN_ENTRY');
					$refund_entry->addChild('TITLE', $this->getBreakdownEntryTitle($this->getTariffKind("credit"), $reason));
					$refund_entry->addChild('UNITS', 1);
					$refund_entry->addChild('COST_WITHOUTVAT', $cost);
					$refund_entry->addChild('VAT', 0);
					$refund_entry->addChild('VAT_COST', floatval($refund_entry->COST_WITHOUTVAT) * floatval($refund_entry->VAT) / 100);
					$refund_entry->addChild('TOTAL_COST', floatval($refund_entry->COST_WITHOUTVAT) + floatval($refund_entry->VAT_COST));
//					$refund_entry->addChild('TYPE_OF_BILLING', strtoupper($type));
//						$refund_entry->addChild('TYPE_OF_BILLING_CHAR', ?);
				}
			}
		}

		$inv_invoice_total = $xml->addChild('INV_INVOICE_TOTAL');
		$inv_invoice_total->addChild('INVOICE_NUMBER', $row['invoice_id']);
		$inv_invoice_total->addChild('FIRST_GENERATION_TIME', $this->getFlatStartDate());
		$inv_invoice_total->addChild('FROM_PERIOD', date('Y/m/d', Billrun_Util::getStartTime($billrun_key)));
		$inv_invoice_total->addChild('TO_PERIOD', date('Y/m/d', Billrun_Util::getEndTime($billrun_key)));
		$inv_invoice_total->addChild('SUBSCRIBER_COUNT', count($row['subs']));
		$inv_invoice_total->addChild('CUR_MONTH_CADENCE_START', $this->getExtrasStartDate());
		$inv_invoice_total->addChild('CUR_MONTH_CADENCE_END', $this->getExtrasEndDate());
		$inv_invoice_total->addChild('NEXT_MONTH_CADENCE_START', $this->getFlatStartDate());
		$inv_invoice_total->addChild('NEXT_MONTH_CADENCE_END', $this->getFlatEndDate());
		$account_before_vat = $this->getAccTotalBeforeVat($row);
		$account_after_vat = $this->getAccTotalAfterVat($row);
		$inv_invoice_total->addChild('TOTAL_CHARGE', $account_after_vat);
		$inv_invoice_total->addChild('TOTAL_CREDIT', $invoice_total_manual_correction_credit);
		$gifts = $inv_invoice_total->addChild('GIFTS');
		$invoice_sumup = $inv_invoice_total->addChild('INVOICE_SUMUP');
		$invoice_sumup->addChild('TOTAL_GIFT', $invoice_total_gift);
		$invoice_sumup->addChild('TOTAL_ABOVE_GIFT', $invoice_total_above_gift);
		$invoice_sumup->addChild('TOTAL_OUTSIDE_GIFT_VAT', $invoice_total_outside_gift_vat);
		$invoice_sumup->addChild('TOTAL_MANUAL_CORRECTION', $invoice_total_manual_correction);
		$invoice_sumup->addChild('TOTAL_MANUAL_CORRECTION_CREDIT', $invoice_total_manual_correction_credit);
		$invoice_sumup->addChild('TOTAL_MANUAL_CORRECTION_CHARGE', $invoice_total_manual_correction_charge);
		$invoice_sumup->addChild('TOTAL_OUTSIDE_GIFT_NOVAT', $invoice_total_outside_gift_novat);
		$invoice_sumup->addChild('TOTAL_VAT', $account_after_vat - $account_before_vat);
		$invoice_sumup->addChild('TOTAL_CHARGE_NO_VAT', $account_before_vat);
		$invoice_sumup->addChild('TOTAL_CHARGE', $account_after_vat);
		return $xml;
	}

	/**
	 * 
	 * @param type $fileName
	 * @param type $xmlContent
	 * @return type
	 * @todo do not override files?
	 */
	protected function createXmlFile($fileName, $xmlContent) {
		Billrun_Factory::log()->log("create xml file " . $fileName, Zend_Log::INFO);
		$path = $this->export_directory . '/' . $fileName;
		$ret = file_put_contents($path, $xmlContent);
		Billrun_Factory::log()->log("create xml file " . $fileName . ' - finished', Zend_Log::INFO);
		return $ret;
	}

//	/**
//	 * 
//	 * @param array $subscriber subscriber billrun entry
//	 * @return type
//	 */
//	protected function get_subscriber_lines_refs($subscriber) {
//		$refs = array();
//		if (isset($subscriber['lines'])) {
//			foreach ($subscriber['lines'] as $usage_type => $lines_by_usage_type) {
//				if ($usage_type != 'data' && isset($lines_by_usage_type["refs"]) && is_array($lines_by_usage_type["refs"])) {
//					$refs = array_merge($refs, $lines_by_usage_type["refs"]);
//				}
//			}
//		}
//		return $refs;
//	}

	/**
	 * 
	 * @param array $entity account or subscriber document (billrun collection)
	 * @return type
	 */
	protected function get_lines($entity) {
//		$start_time = new MongoDate(0);
		$end_time = new MongoDate(Billrun_Util::getEndTime($this->stamp));
		if (isset($entity['aid'])) {
			$field = 'aid';
		} else if (isset($entity['sid'])) {
			$field = 'sid';
		} else {
			// throw warning
			return false;
		}

		$filter = array(
			$field => $entity[$field],
		);
		$query = array_merge($filter, array(
			'urt' => array(
				'$lte' => $end_time, // to filter out next billrun lines
			),
			'billrun' => array(
				'$in' => array($this->stamp),
			),
			'type' => array(
				'$ne' => 'ggsn',
			),
		));

		$sort = array(
			$field => 1,
			'urt' => 1,
		);

		$lines = $this->lines_coll->query($query)->cursor()->sort($sort)->hint($sort);
		if (rand(1, 100) >= $this->loadBalanced) {
			$lines = $lines->setReadPreference(MongoClient::RP_SECONDARY_PREFERRED);
		}
		Billrun_Factory::log()->log('Pulling lines of ' . $field . ' ' . $entity[$field], Zend_Log::DEBUG);
		$ret = array();
		foreach ($lines as $line) {
			$ret[] = $line;
		}
		Billrun_Factory::log()->log('Pulling lines of ' . $field . ' ' . $entity[$field] . ' - finished', Zend_Log::DEBUG);
		return $ret;
	}

	/**
	 * 
	 * @param array $subscriber subscriber billrun entry
	 * @return type
	 */
	protected function get_subscriber_aggregated_data_lines($subscriber) {
		$aggregated_lines = array();
		if (isset($subscriber['lines']['data']['counters'])) {
			foreach ($subscriber['lines']['data']['counters'] as $day => $data_by_day) {
				$aggregated_line = array();
				$aggregated_line['day'] = date_create_from_format("Ymd", $day)->format('Y/m/d 00:00:00');
				$aggregated_line['rate_key'] = $this->data_rate['key'];
				$aggregated_line['usage_volume'] = $this->bytesToKB($data_by_day['usagev']);
				$aggregated_line['aprice'] = $data_by_day['aprice'];
				$aggregated_line['tariff_kind'] = $this->getTariffKind('data');
				$aggregated_line['interval'] = $this->getIntervalByRate($this->data_rate, 'data');
				$aggregated_line['rate_price'] = $this->getPriceByRate($this->data_rate, 'data');
				$aggregated_line['discount_usage'] = $this->getDiscountUsageByPlanFlag($data_by_day['plan_flag']);
				$aggregated_lines[] = $aggregated_line;
			}
		}
		return $aggregated_lines;
	}

	protected function getUsageVolume($line) {
		if (isset($line['usagev']) && isset($line['usaget'])) {
			switch ($line['usaget']) {
				case 'call':
				case 'incoming_call':
				case 'sms':
				case 'mms':
				case 'incoming_sms':
					return $line['usagev'];
				case 'data':
//					if ($line['type'] == 'tap3') {
//						$arate = $this->getRowRate($line); 
//						return $this->bytesToKB($line['usagev'], $arate['rates']['data']['rate'][0]['interval']);
//					} else {
					return $this->bytesToKB($line['usagev']);
//					}
				default:
					break;
			}
		}
		return 0;
	}

	protected function getCharge($line) {
		if (!($line['type'] == 'credit' && isset($line['credit_type']) && $line['credit_type'] == 'refund')) {
			return abs($line['aprice']);
		}
		return 0;
	}

	protected function getCredit($line) {
		if ($line['type'] == 'credit' && isset($line['credit_type']) && $line['credit_type'] == 'refund') {
			return abs($line['aprice']);
		}
		return 0;
	}

	protected function getAccessPrice($line) {
		$arate = $this->getRowRate($line);
		if (isset($line['usaget']) && isset($arate['rates'][$line['usaget']]['access'])) {
			return $arate['rates'][$line['usaget']]['access'];
		}
		return 0;
	}

	protected function getInterval($line) {
		$arate = $this->getRowRate($line);
		if (isset($line['usaget']) && isset($arate['rates'][$line['usaget']]['rate'][0]['interval'])) {
			return $this->getIntervalByRate($arate, $line['usaget']);
		}
		return 0;
	}

	protected function getIntervalByRate($rate, $usage_type) {
		$interval = $rate['rates'][$usage_type]['rate'][0]['interval'];
		if ($usage_type == 'data' && $rate['rates'][$usage_type]['category'] == 'roaming') {
			$interval = $interval / 1024;
		}
		return $interval;
	}

	protected function getPriceByRate($rate, $usage_type) {
		if (isset($rate['rates'][$usage_type]['rate'][0]['price']) && $usage_type != 'credit') {
			if (in_array($usage_type, array('call', 'data', 'incoming_call')) && isset($rate['rates'][$usage_type]['rate'][0]['interval']) && $rate['rates'][$usage_type]['rate'][0]['interval'] == 1) {
				return $rate['rates'][$usage_type]['rate'][0]['price'] * ($usage_type == 'data' ? 1024 : 60);
			}
			if ($usage_type == 'data' && $rate['rates'][$usage_type]['category'] == 'roaming') {
				return $rate['rates'][$usage_type]['rate'][0]['price'] * 1048576 / $rate['rates'][$usage_type]['rate'][0]['interval'];
			}
			return $rate['rates'][$usage_type]['rate'][0]['price'];
		}
		return 0;
	}

	protected function getRate($line) {
		$arate = $this->getRowRate($line);
		if (isset($line['usaget']) && $arate) {
			return $this->getPriceByRate($arate, $line['usaget']);
		}
		return 0;
	}

	/**
	 * Get a rate from the row
	 * @param Mongodloid_Entity the row to get rate from
	 * @return Mongodloid_Entity the rate of the row
	 */
	protected function getRowRate($row) {
		$rate = false;
		$raw_rate = $row->get('arate', true);
		if ($raw_rate) {
			$id_str = strval($raw_rate['$id']);
			$rate = $this->getRateById($id_str);
		}
		return $rate;
	}

	/**
	 * Get a rate by hexadecimal id
	 * @param string $id hexadecimal id of rate (taken from Mongo ID)
	 * @return Mongodloid_Entity the corresponding rate
	 */
	protected function getRateById($id) {
		if (!isset($this->rates[$id])) {
			$rates = Billrun_Factory::db()->ratesCollection();
			$this->rates[$id] = $rates->findOne($id);
		}
		return $this->rates[$id];
	}

	/**
	 * Get a rate by hexadecimal id
	 * @param string $id hexadecimal id of rate (taken from Mongo ID)
	 * @return Mongodloid_Entity the corresponding rate
	 */
	protected function getPlanById($id) {
		if (!isset($this->plans[$id])) {
			$plans_coll = Billrun_Factory::db()->plansCollection();
			$this->plans[$id] = $plans_coll->findOne($id);
		}
		return $this->plans[$id];
	}

	protected function getIntlFlag($line) {
		$arate = $this->getRowRate($line);
		if (isset($line['usaget']) && isset($arate['rates'][$line['usaget']]['category'])) {
			$category = $arate['rates'][$line['usaget']]['category'];
			if ($category == 'intl' || $category == 'roaming') {
				return 1;
			}
		}
		return 0;
	}

	protected function getTariffKind($usage_type) {
		switch ($usage_type) {
			case 'call':
				return 'Call';
			case 'data':
				return 'Internet Access';
			case 'sms':
				return 'SMS';
			case 'incoming_call':
				return 'Incoming Call';
			case 'mms':
				return 'MMS';
			case 'incoming_sms': // in theory...
				return 'Incoming SMS';
			case 'credit':
			case 'flat':
				return 'Service';
			default:
				return '';
		}
	}

	protected function getTariffItem($line, $subscriber) {
		$tariffItem = '';
		if ($line['type'] == 'flat') {
			$tariffItem = 'GIFT-GC_GOLAN-' . $this->getNextPlanName($subscriber);
		} else if ($line['type'] == 'credit' && isset($line['service_name'])) {
			$tariffItem = $line['service_name'];
		} else {
			if ($line['type'] == 'tap3') {
				$tariffItem = $this->getNsoftRoamingRate($line['usaget']);
			} else {
				$arate = $this->getRowRate($line);
				if (isset($arate['key'])) {
					$tariffItem = $arate['key'];
				}
			}
		}
		return $tariffItem;
	}

	protected function getNsoftRate($line) {
		
	}

	protected function getNsoftRoamingRate($usage_type) {
		switch ($usage_type) {
			case 'incoming_call':
			case 'incoming_sms':
				$rate = '$DEFAULT';
				break;
			case 'call':
			case 'sms':
			case 'mms': // a guess
				$rate = 'ROAM_ALL_DEST';
				break;
			case 'data':
				$rate = 'INTERNET_BILL_BY_VOLUME';
				break;
			default:
				$rate = '';
				break;
		}
		return $rate;
	}

	protected function getCallerNo($line) {
		$calling_number = '';
		if (isset($line['calling_number'])) {
			$calling_number = $line['calling_number'];
		}
		return $calling_number;
	}

	protected function getCalledNo($line) {
		$called_number = '';
		if ($line['type'] == 'tap3') {
			if ($line['usaget'] == 'incoming_call') {
				$called_number = $line['calling_number'];
			} else {
				$called_number = $line['called_number'];
			}
		} else if (isset($line['called_number'])) { // mmsc might not have called_number
			$called_number = $this->beautifyPhoneNumber($line['called_number']);
		}
		return $called_number;
	}

	protected function getDiscountUsage($line) {
		if (isset($line['out_plan']) || $line['type'] == 'credit') {
			$plan_flag = 'out';
		} else if (isset($line['over_plan']) && ($line['usagev'] == $line['over_plan'])) {
			$plan_flag = 'over';
		} else if ($line['type'] == 'flat' || (isset($line['over_plan']) && ($line['usagev'] > $line['over_plan']))) {
			$plan_flag = 'partial';
		} else {
			$plan_flag = 'in';
		}
		return $this->getDiscountUsageByPlanFlag($plan_flag);
	}

	protected function getDiscountUsageByPlanFlag($plan_flag) {
		switch ($plan_flag) {
			case 'over':
				return 'DISCOUNT_OUT';
			case 'out':
				return 'DISCOUNT_NONE';
			case 'partial':
				return 'DISCOUNT_PARTIAL';
			case 'in':
			default:
				return 'DISCOUNT_FULL';
		}
	}

	protected function basic_xml() {
		$xml = <<<EOI
<?xml version="1.0" encoding="UTF-8"?>
<INVOICE>
	<TELECOM_INFORMATION>
	</TELECOM_INFORMATION>
	<INV_CUSTOMER_INFORMATION>
		<CUSTOMER_CONTACT>
		</CUSTOMER_CONTACT>
	</INV_CUSTOMER_INFORMATION>
</INVOICE>
EOI;
		return simplexml_load_string($xml);
	}

	protected function setFileStamp($line, $filename) {
		$current = $line->getRawData();
		$added_values = array(
			'invoice_file' => $filename,
		);

		$newData = array_merge($current, $added_values);
		$line->setRawData($newData);
		$line->save(Billrun_Factory::db()->billrunCollection());
		return true;
	}

	/**
	 * 
	 * @param int $bytes
	 * @return int interval to ceil by in bytes
	 */
	protected function bytesToKB($bytes, $interval = null) {
		$bytes_to_price = $bytes;
		if (!is_null($interval)) {
			$bytes_to_price = ceil($bytes / $interval) * $interval;
		}
//		$ret = ceil($bytes_to_price / 1024); // we won't imitate nsoft here
		$ret = $bytes_to_price / 1024;
		return $ret;
	}

	/**
	 * 
	 * @param float $vat vat value
	 * @return mixed
	 */
	protected function displayVAT($vat) {
		return $vat * 100;
	}

	protected function getDate($line) {
		$timsetamp = $line['urt']->sec;
		if (isset($line['tzoffset'])) {
			// TODO change this to regex
			$tzoffset = $line['tzoffset'];
			$sign = substr($tzoffset, 0, 1);
			$hours = substr($tzoffset, 1, 2);
			$minutes = substr($tzoffset, 3, 2);
			$time = $hours . ' hours ' . $minutes . ' minutes';
			if ($sign == "-") {
				$time .= ' ago';
			}
			$timsetamp = strtotime($time, $timsetamp);
			$zend_date = new Zend_Date($timsetamp);
			$zend_date->setTimezone('UTC');
		} else {
			$zend_date = new Zend_Date($timsetamp);
		}
		return $this->getGolanDate($zend_date);
	}

	/**
	 * 
	 * @param Zend_Date $date
	 * @return type
	 */
	protected function getGolanDate($date) {
		return $date->toString('YYYY/MM/dd HH:mm:ss');
	}

	/**
	 * 
	 * @param array $subscriber the subscriber billrun entry
	 */
	protected function getPlanName($subscriber) {
		$current_plan_ref = $subscriber['current_plan'];
		if (MongoDBRef::isRef($current_plan_ref)) {
			$current_plan = $this->getPlanById(strval($current_plan_ref['$id']));
			$current_plan_name = $current_plan['name'];
		} else {
			$current_plan_name = '';
		}
		return $current_plan_name;
	}

	/**
	 * 
	 * @param array $subscriber the subscriber billrun entry
	 */
	protected function getNextPlanName($subscriber) {
		$next_plan_ref = $subscriber['next_plan'];
		if (MongoDBRef::isRef($next_plan_ref)) {
			$next_plan = $this->getPlanById(strval($next_plan_ref['$id']));
			$next_plan_name = $next_plan['name'];
		} else {
			$next_plan_name = '';
		}
		return $next_plan_name;
	}

	/**
	 * 
	 * @param array $subscriber subscriber entry from billrun collection
	 * @return array
	 */
	protected function getFlatCosts($subscriber) {
		$flat_costs = array();
		if (isset($subscriber['costs']['flat'])) {
			$flat_costs = $subscriber['costs']['flat'];
		}
		return $flat_costs;
	}

	protected function billingLinesNeeded($sid) {
		return true;
	}

	protected function getZoneTotalsFieldByUsage($zone, $field, $usage_type) {
		if (isset($zone['totals'][$usage_type][$field])) {
			if (is_array($zone['totals'][$usage_type][$field])) {
				return array_sum($zone['totals'][$usage_type][$field]);
			} else {
				return $zone['totals'][$usage_type][$field];
			}
		} else {
			return 0;
		}
	}

	protected function getZoneVat($zone) {
		if (isset($zone['vat'])) {
			if (is_array($zone['vat'])) {
				return current($zone['vat']);
			} else {
				return $zone['vat'];
			}
		} else {
			return 0;
		}
	}

	/**
	 * 
	 * @param array $subscriber subscriber entry from billrun collection
	 * @return int
	 */
	protected function getSubscriberTotalBeforeVat($subscriber) {
		return isset($subscriber['totals']['before_vat']) ? $subscriber['totals']['before_vat'] : 0;
	}

	/**
	 * 
	 * @param array $subscriber subscriber entry from billrun collection
	 * @return int
	 */
	protected function getSubscriberTotalAfterVat($subscriber) {
		return isset($subscriber['totals']['after_vat']) ? $subscriber['totals']['after_vat'] : 0;
	}

	protected function getAccTotalBeforeVat($row) {
		return isset($row['totals']['before_vat']) ? $row['totals']['before_vat'] : 0;
	}

	protected function getAccTotalAfterVat($row) {
		return isset($row['totals']['after_vat']) ? $row['totals']['after_vat'] : 0;
	}

	protected function getExtrasStartDate() {
		return date('d/m/Y', Billrun_Util::getStartTime($this->stamp));
	}

	protected function getExtrasEndDate() {
		return date('d/m/Y', Billrun_Util::getEndTime($this->stamp));
	}

	protected function getFlatStartDate() {
		return date('d/m/Y', strtotime('+ 1 month', Billrun_Util::getStartTime($this->stamp)));
	}

	protected function getFlatEndDate() {
		return date('d/m/Y', strtotime('+ 1 month', Billrun_Util::getEndTime($this->stamp)));
	}

	protected function getBreakdownEntryTitle($taarif_kind, $rate_key) {
		return str_replace(' ', '_', strtoupper($taarif_kind . '-' . $rate_key));
	}

	protected function updateBillingRecord($billing_record, $golan_date, $tariff_item, $called_number, $caller_number, $volume, $charge, $credit, $tariff_kind, $access_price, $interval, $rate, $intl_flag, $discount_usage, $roaming, $serving_network, $type_of_billing_char) {
		$billing_record->TIMEOFBILLING = $golan_date;
		$billing_record->TARIFFITEM = $tariff_item;
		$billing_record->CTXT_CALL_OUT_DESTINATIONPNB = $called_number; //@todo maybe save dest_no in all processors and use it here
		$billing_record->CTXT_CALL_IN_CLI = $caller_number; //@todo maybe save it in all processors and use it here
		$billing_record->CHARGEDURATIONINSEC = $volume;
		$billing_record->CHARGE = $charge;
		$billing_record->CREDIT = $credit;
		$billing_record->TARIFFKIND = $tariff_kind;
		$billing_record->TTAR_ACCESSPRICE1 = $access_price;
		$billing_record->TTAR_SAMPLEDELAYINSEC1 = $interval;
		$billing_record->TTAR_SAMPPRICE1 = $rate;
		$billing_record->INTERNATIONAL = $intl_flag;
		$billing_record->DISCOUNT_USAGE = $discount_usage;
		$billing_record->ROAMING = $roaming;
		$billing_record->SERVINGPLMN = $serving_network;
		$billing_record->TYPE_OF_BILLING_CHAR = $type_of_billing_char;
	}

	protected function getDataRate() {
		$rates = Billrun_Factory::db()->ratesCollection();
		$query = array(
			'key' => 'INTERNET_BILL_BY_VOLUME',
			'from' => array(
				'$lte' => new MongoDate(Billrun_Util::getStartTime($this->stamp)),
			),
			'to' => array(
				'$gte' => new MongoDate(Billrun_Util::getStartTime($this->stamp)),
			),
		);
		return $rates->query($query)->cursor()->setReadPreference(MongoClient::RP_SECONDARY_PREFERRED)->current();
	}

	/**
	 * Load all rates from db into memory
	 */
	protected function loadRates() {
		$rates_coll = Billrun_Factory::db()->ratesCollection();
		$rates = $rates_coll->query()->cursor()->setReadPreference(MongoClient::RP_SECONDARY_PREFERRED);
		foreach ($rates as $rate) {
			$rate->collection($rates_coll);
			$this->rates[strval($rate->getId())] = $rate;
		}
		$this->data_rate = $this->getDataRate();
	}

	/**
	 * Load all rates from db into memory
	 */
	protected function loadPlans() {
		$plans_coll = Billrun_Factory::db()->plansCollection();
		$plans = $plans_coll->query()->cursor()->setReadPreference(MongoClient::RP_SECONDARY_PREFERRED);
		foreach ($plans as $plan) {
			$plan->collection($plans_coll);
			$this->plans[strval($plan->getId())] = $plan;
		}
	}

	protected function beautifyPhoneNumber($phone_number) {
		$separator = "-";
		$phone_number = intval($phone_number);
		if (substr($phone_number, 0, 3) == "972") {
			$phone_number = intval(substr($phone_number, 3));
		}
		$length = strlen($phone_number);
		if ($length == 8) {
			$phone_number = "0" . substr($phone_number, 0, 1) . $separator . substr($phone_number, 1);
		} else if ($length == 9) {
			$phone_number = "0" . substr($phone_number, 0, 2) . $separator . substr($phone_number, 2);
		}
		return $phone_number;
	}

	protected function getRoaming($line) {
		return $line['type'] == 'tap3' ? 1 : 0;
	}

	protected function getServingNetwork($line) {
		return isset($line['serving_network']) ? $line['serving_network'] : '';
	}

	protected function getLineTypeOfBillingChar($line) {
		$type = $line['type'];
		$usaget = $line['usaget'];
		$char = '';
		if ($usaget == 'call') {
			$char = 'S';
		} else if ($usaget == 'sms') {
			$char = 'T';
		} else if ($type == 'credit') {
			$credit_type = $line['credit_type'];
			if ($credit_type == 'refund') {
				$char = 'R';
			} else if ($credit_type == 'charge') {
				$char = 'C';
			}
		} else if ($type == 'tap3') {
			if ($usaget == 'incoming_call') {
				$char = 'I';
			} else if ($usaget == 'data') {
				$char = 'W';
			}
		} else if ($type == 'flat') {
			$char = 'G';
		} else if ($type == 'mmsc') {
			$char = 'P';
		} else if ($type == 'ggsn') {
			$char = 'D';
		}
		return $char;
	}

}