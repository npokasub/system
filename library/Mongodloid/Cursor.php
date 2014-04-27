<?php

/**
 * @package         Mongodloid
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */
class Mongodloid_Cursor implements Iterator, Countable {

	private $_cursor;

	public function __construct(MongoCursor $cursor) {
		$this->_cursor = $cursor;
	}

	public function count($foundOnly = true) {
		return $this->_cursor->count($foundOnly);
	}

	public function current() {
		//If before the start of the vector move to the first element.
		if (!$this->_cursor->current() && $this->_cursor->hasNext()) {
			$this->_cursor->next();
		}
		return new Mongodloid_Entity($this->_cursor->current());
	}

	public function key() {
		return $this->_cursor->key();
	}

	public function next() {
		return $this->_cursor->next();
	}

	public function rewind() {
		$this->_cursor->rewind();
		return $this;
	}

	public function valid() {
		return $this->_cursor->valid();
	}

	public function sort(array $fields) {
		$this->_cursor->sort($fields);
		return $this;
	}

	public function limit($limit) {
		$this->_cursor->limit(intval($limit));
		return $this;
	}

	public function skip($limit) {
		$this->_cursor->skip(intval($limit));
		return $this;
	}

	public function hint(array $key_pattern) {
		if (empty($key_pattern)) {
			return;
		}
		$this->_cursor->hint($key_pattern);
		return $this;
	}

	public function explain() {
		return $this->_cursor->explain();
	}

	/**
	 * method to set read preference of cursor connection
	 * 
	 * @param string $readPreference The read preference mode: RP_PRIMARY, RP_PRIMARY_PREFERRED, RP_SECONDARY, RP_SECONDARY_PREFERRED or RP_NEAREST
	 * @param array $tags An array of zero or more tag sets, where each tag set is itself an array of criteria used to match tags on replica set members
	 * 
	 * @return Mongodloid_Collection self object
	 */
	public function setReadPreference($readPreference, array $tags = array()) {
		if (defined('MongoClient::' . $readPreference)) {
			$this->_cursor->setReadPreference(constant('MongoClient::' . $readPreference), $tags);
		}
		
		return $this;
	}

	/**
	 * method to get read preference of cursor connection
	 * 
	 * @param boolean $includeTage if to include tags in the return value, else return only the read preference
	 * 
	 * @return mixed array in case of include tage else string (the string would be the rp constant)
	 */
	public function getReadPreference($includeTage = false) {
		$ret = $this->_cursor->setReadPreference();
		if ($includeTage) {
			return $ret;
		}
		
		switch ($ret['type']) {
			case MongoClient::RP_PRIMARY:
				return 'RP_PRIMARY';
			case MongoClient::RP_PRIMARY_PREFERRED:
				return 'RP_PRIMARY_PREFERRED';
			case MongoClient::RP_SECONDARY:
				return 'RP_SECONDARY';
			case MongoClient::RP_SECONDARY_PREFERRED:
				return 'RP_SECONDARY_PREFERRED';
			case MongoClient::RP_NEAREST:
				return 'RP_NEAREST';
			default:
				return MongoClient::RP_PRIMARY_PREFERRED;
		}

	}

	public function timeout($ms) {
		$this->_cursor->timeout($ms);
		return $this;
	}

	public function immortal($liveForever = true) {
		$this->_cursor->immortal($liveForever);
		return $this;
	}
	
	public function fields(array $fields) {
		$this->_cursor->fields($fields);
		return $this;
	}

}
