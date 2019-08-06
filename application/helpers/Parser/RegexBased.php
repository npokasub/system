<?php
/**
 * @package	Billing
 * @copyright	Copyright (C) 2012-2018 BillRun Technologies Ltd. All rights reserved.
 * @license	GNU Affero General Public License Version 3; see LICENSE.txt
 */
/**
 * 
 * IPMappingRecord
 * @package  Application
 * @subpackage Plugins
 * @since    5.8
 */
class Parser_RegexBased extends Billrun_Parser_Separator
{



	public function __construct($options)
	{
	    parent::__construct($options);
	}


	public function parse()
	{
		$row=[];
		foreach($this->structure as $regexVals) {
			if(preg_match('/'.$regexVals['match'].'/',$this->line)) {
				foreach($regexVals['fields'] as $fieldName => $fieldRegex) {
					$matches = [];
					if ( preg_match_all('/'.$fieldRegex.'/',$this->line,$matches) ) {
						array_shift($matches);
						$vals = reset($matches);
						$row[$fieldName] =  count($vals) == 1 ? reset($vals) : $vals;
					}
				}
				$row['stamp'] = md5(serialize($row));
			}
		}
		return $row;
	}

}
