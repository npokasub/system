﻿<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing 013 Responder file processor
 *
 * @package  Billing
 * @since    1.0
 * TODO ! ACTUALLY IMPLEMENT!! THERESCURRENTLY NO SPEC'S FOR THIS ! TODO
 */
class Billrun_Responder_015 extends Billrun_Responder_Base_Ilds {

	public function __construct( $options = false ) {
		parent::__construct( $options );
		$this->type = "015";

		$this->data_structure = array(
			'record_type' => '%1s',
			'call_type' => '%2s',
			'caller_phone_no' => '%10s',
			'called_no' => '%28s',
			'call_start_dt' => '%14s',
			'chrgbl_call_dur' => '%6s',
			'rate_code' => '%1s',
			'call_charge_sign' => '%1s',
			'call_charge' => '%11s',
			'charge_code' => '%2s',
			'record_status' => '%2s',
		);

		$this->header_structure = array(
			'record_type' => '%1s',
			'file_type' => '%15s',
			'sending_company_id' => '%10s',
			'receiving_company_id' => '%10s',
			'sequence_no' => '%06s',
			'file_creation_date' => '%14s',
			'file_received_date' => '%14s',
			'file_status' => '%02s',
		);

		$this->trailer_structure = array(
			'record_type' => '%1s',
			'file_type' => '%15s',
			'sending_company_id' => '%10s',
			'receiving_company_id' => '%10s',
			'sequence_no' => '%6s',
			'file_creation_date' => '%14s',
			'total_phone_number' => '%15s',
			'total_charge_sign' => '%1s',
			'total_charge' => '%15s',
			'total_rec_no' => '%6s',
			'total_err_rec_no' => '%6s',
		);	}

}
