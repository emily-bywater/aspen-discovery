<?php


abstract class CircEntry extends DataObject
{
	public $id;
	public $type;
	public $source;
	public $userId;
	public $sourceId;
	public $recordId;
	public $groupedWorkId;

	public function getShortId(){
		if (!empty($this->shortId)){
			return $this->shortId;
		}else{
			return $this->recordId;
		}
	}

	protected $_recordDriver = null;
	/**
	 * @return GroupedWorkSubDriver|false
	 */
	public function getRecordDriver(){
		if ($this->_recordDriver == null) {
			if ($this->type == 'ils') {
				require_once ROOT_DIR . '/RecordDrivers/MarcRecordDriver.php';
				$this->_recordDriver = new MarcRecordDriver($this->recordId);
				if (!$this->_recordDriver->isValid()){
					$this->_recordDriver = false;
				}
			} elseif ($this->type == 'axis360') {
				require_once ROOT_DIR . '/RecordDrivers/Axis360RecordDriver.php';
				$this->_recordDriver = new Axis360RecordDriver($this->recordId);
				if (!$this->_recordDriver->isValid()){
					$this->_recordDriver = false;
				}
			} elseif ($this->type == 'cloud_library') {
				require_once ROOT_DIR . '/RecordDrivers/CloudLibraryRecordDriver.php';
				$this->_recordDriver = new CloudLibraryRecordDriver($this->recordId);
				if (!$this->_recordDriver->isValid()){
					$this->_recordDriver = false;
				}
			} elseif ($this->type == 'hoopla') {
				require_once ROOT_DIR . '/RecordDrivers/HooplaRecordDriver.php';
				$this->_recordDriver = new HooplaRecordDriver($this->recordId);
				if (!$this->_recordDriver->isValid()){
					$this->_recordDriver = false;
				}
			} elseif ($this->type == 'overdrive') {
				require_once ROOT_DIR . '/RecordDrivers/OverDriveRecordDriver.php';
				$this->_recordDriver = new OverDriveRecordDriver($this->recordId);
				if (!$this->_recordDriver->isValid()){
					$this->_recordDriver = false;
				}
			} elseif ($this->type == 'rbdigital') {
				if ($this->source == 'rbdigital'){
					require_once ROOT_DIR . '/RecordDrivers/RBdigitalRecordDriver.php';
					$this->_recordDriver = new RBdigitalRecordDriver($this->recordId);
					if (!$this->_recordDriver->isValid()){
						$this->_recordDriver = false;
					}
				} elseif ($this->source == 'rbdigital_magazine') {
					require_once ROOT_DIR . '/RecordDrivers/RBdigitalMagazineDriver.php';
					$this->_recordDriver = new RBdigitalMagazineDriver($this->recordId);
					if (!$this->_recordDriver->isValid()) {
						$this->_recordDriver = false;
					}
				}
			} else {
				$this->_recordDriver = false;
			}
		}
		return $this->_recordDriver;
	}

	public function getTitle(){
		$recordDriver = $this->getRecordDriver();
		if ($recordDriver != false){
			return $recordDriver->getTitle();
		}else{
			return $this->title;
		}
	}

	public function getSubtitle(){
		$recordDriver = $this->getRecordDriver();
		if ($recordDriver != false){
			return $recordDriver->getSubtitle();
		}else{
			return $this->title;
		}
	}

	public function getSortTitle(){
		$recordDriver = $this->getRecordDriver();
		if ($recordDriver != false){
			return $recordDriver->getSortableTitle();
		}else{
			return preg_replace('/^The\s|^A\s/i', '', $this->title); // remove beginning The or A
		}
	}

	public function getAuthor(){
		$recordDriver = $this->getRecordDriver();
		if ($recordDriver != false){
			return $recordDriver->getAuthor();
		}else{
			return $this->author; // remove beginning The or A
		}
	}

	public function getFormats(){
		$recordDriver = $this->getRecordDriver();
		if ($recordDriver != false){
			return $recordDriver->getFormats();
		}else{
			return 'Unknown';
		}
	}

	public function getPrimaryFormat(){
		$recordDriver = $this->getRecordDriver();
		if ($recordDriver != false){
			return $recordDriver->getPrimaryFormat();
		}else{
			return 'Unknown';
		}
	}

	public function getIsbn(){
		$recordDriver = $this->getRecordDriver();
		if ($recordDriver != false){
			return $recordDriver->getCleanISBN();
		}else{
			return null;
		}
	}

	public function getUPC(){
		$recordDriver = $this->getRecordDriver();
		if ($recordDriver != false){
			return $recordDriver->getCleanUPC();
		}else{
			return null;
		}
	}

	public function getFormatCategory(){
		$recordDriver = $this->getRecordDriver();
		if ($recordDriver != false){
			return $recordDriver->getFormatCategory();
		}else{
			return null;
		}
	}

	public function getCoverUrl(){
		$recordDriver = $this->getRecordDriver();
		if ($recordDriver != false){
			return $recordDriver->getBookcoverUrl('medium', true);
		}else{
			return null;
		}
	}

	public function getLinkUrl(){
		$recordDriver = $this->getRecordDriver();
		if ($recordDriver != false){
			return $recordDriver->getLinkUrl();
		}else{
			return null;
		}
	}

	public function getRatingData(){
		$recordDriver = $this->getRecordDriver();
		if ($recordDriver != false){
			return $recordDriver->getRatingData();
		}else{
			return null;
		}
	}

	public function getGroupedWorkId(){
		if (!empty($this->groupedWorkId)) {
			return $this->groupedWorkId;
		}else{
			$recordDriver = $this->getRecordDriver();
			if ($recordDriver != false) {
				return $recordDriver->getGroupedWorkId();
			} else {
				return null;
			}
		}
	}

	public function getPublicationDates(){
		$recordDriver = $this->getRecordDriver();
		if ($recordDriver != false){
			return $recordDriver->getPublicationDates();
		}else{
			return null;
		}
	}

	/** @var User */
	protected $_user = null;
	public function getUser(){
		if ($this->_user == null){
			$this->_user = new User();
			$this->_user->id = $this->userId;
			if (!$this->_user->find(true)){
				$this->_user = false;
			}
		}
		return $this->_user;
	}

	/** @noinspection PhpUnused */
	public function getUserName(){
		if ($this->getUser()) {
			return $this->getUser()->getNameAndLibraryLabel();
		}else{
			return 'Unknown user';
		}
	}
}