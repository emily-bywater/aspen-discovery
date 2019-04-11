<?php

/**
 * Complete integration via APIs including availability and account information.
 */
require_once ROOT_DIR . '/Drivers/AbstractEContentDriver.php';
class OverDriveDriver extends AbstractEContentDriver{
	public $version = 3;

	protected $requirePin;
	protected $ILSName;


	protected $format_map = array(
		'ebook-epub-adobe' => 'Adobe EPUB eBook',
		'ebook-epub-open' => 'Open EPUB eBook',
		'ebook-pdf-adobe' => 'Adobe PDF eBook',
		'ebook-pdf-open' => 'Open PDF eBook',
		'ebook-kindle' => 'Kindle Book',
		'ebook-disney' => 'Disney Online Book',
		'ebook-overdrive' => 'OverDrive Read',
		'ebook-microsoft' => 'Microsoft eBook',
		'audiobook-wma' => 'OverDrive WMA Audiobook',
		'audiobook-mp3' => 'OverDrive MP3 Audiobook',
		'audiobook-streaming' => 'Streaming Audiobook',
		'music-wma' => 'OverDrive Music',
		'video-wmv' => 'OverDrive Video',
		'video-wmv-mobile' => 'OverDrive Video (mobile)',
		'periodicals-nook' => 'NOOK Periodicals',
		'audiobook-overdrive' => 'OverDrive Listen',
		'video-streaming' => 'OverDrive Video',
		'ebook-mediado' => 'MediaDo Reader',
	);

	private function _connectToAPI($forceNewConnection = false){
		/** @var Memcache $memCache */
		global $memCache;
		$tokenData = $memCache->get('overdrive_token');
		if ($forceNewConnection || $tokenData == false){
			global $configArray;
			if (isset($configArray['OverDrive']['clientKey']) && $configArray['OverDrive']['clientKey'] != '' && isset($configArray['OverDrive']['clientSecret']) && $configArray['OverDrive']['clientSecret'] != ''){
				$ch = curl_init("https://oauth.overdrive.com/token");
				curl_setopt($ch, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded;charset=UTF-8'));
				curl_setopt($ch, CURLOPT_USERPWD, $configArray['OverDrive']['clientKey'] . ":" . $configArray['OverDrive']['clientSecret']);
				curl_setopt($ch, CURLOPT_TIMEOUT, 10);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
				curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				$return = curl_exec($ch);
				curl_close($ch);
				$tokenData = json_decode($return);
				if ($tokenData){
					$memCache->set('overdrive_token', $tokenData, 0, $tokenData->expires_in - 10);
				}
			}else{
				//OverDrive is not configured
				return false;
			}
		}
		return $tokenData;
	}

	private function _connectToPatronAPI($user, $patronBarcode, $patronPin, $forceNewConnection = false){
		/** @var Memcache $memCache */
		global $memCache;
		global $timer;
		$patronTokenData = $memCache->get('overdrive_patron_token_' . $patronBarcode);
		if ($forceNewConnection || $patronTokenData == false){
			$tokenData = $this->_connectToAPI($forceNewConnection);
			$timer->logTime("Connected to OverDrive API");
			if ($tokenData){
				global $configArray;
				$ch = curl_init("https://oauth-patron.overdrive.com/patrontoken");
				if (!isset($configArray['OverDrive']['websiteId'])){
					return false;
				}
				$websiteId = $configArray['OverDrive']['websiteId'];

				$ilsname = $this->getILSName($user);
				if (!$ilsname) {
					return false;
				}

				if (!isset($configArray['OverDrive']['clientSecret'])){
					return false;
				}
				$clientSecret = $configArray['OverDrive']['clientSecret'];
				curl_setopt($ch, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
				$encodedAuthValue = base64_encode($configArray['OverDrive']['clientKey'] . ":" . $clientSecret);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array(
					'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
					"Authorization: Basic " . $encodedAuthValue,
					"User-Agent: Aspen Discovery"
				));
				curl_setopt($ch, CURLOPT_TIMEOUT, 10);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
				curl_setopt($ch, CURLOPT_POST, 1);

				if ($patronPin == null){
					$postFields = "grant_type=password&username={$patronBarcode}&password=ignore&password_required=false&scope=websiteId:{$websiteId}%20ilsname:{$ilsname}";
				}else{
					$postFields = "grant_type=password&username={$patronBarcode}&password={$patronPin}&password_required=true&scope=websiteId:{$websiteId}%20ilsname:{$ilsname}";
				}

				curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

				$return = curl_exec($ch);
				$timer->logTime("Logged $patronBarcode into OverDrive API");
				curl_close($ch);
				$patronTokenData = json_decode($return);
				$timer->logTime("Decoded return for login of $patronBarcode into OverDrive API");
				if ($patronTokenData){
					if (isset($patronTokenData->error)){
						if ($patronTokenData->error == 'unauthorized_client'){ // login failure
							// patrons with too high a fine amount will get this result.
							return false;
						}else{
							if ($configArray['System']['debug']){
								echo("Error connecting to overdrive apis ". $patronTokenData->error);
							}
						}
					}else{
						if (property_exists($patronTokenData, 'expires_in')){
							$memCache->set('overdrive_patron_token_' . $patronBarcode, $patronTokenData, 0, $patronTokenData->expires_in - 10);
						}
					}
				}
			}else{
				return false;
			}
		}
		return $patronTokenData;
	}

	public function _callUrl($url){
		$tokenData = $this->_connectToAPI();
		if ($tokenData){
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
			curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: {$tokenData->token_type} {$tokenData->access_token}", "User-Agent: VuFind-Plus"));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
			$return = curl_exec($ch);
			curl_close($ch);
			$returnVal = json_decode($return);
			//print_r($returnVal);
			if ($returnVal != null){
				if (!isset($returnVal->message) || $returnVal->message != 'An unexpected error has occurred.'){
					return $returnVal;
				}
			}
		}
		return null;
	}

	private function getILSName($user){
		if (!isset($this->ILSName)) {
			// use library setting if it has a value. if no library setting, use the configuration setting.
			global $library, $configArray;
			$patronHomeLibrary = Library::getPatronHomeLibrary($user);
			if (!empty($patronHomeLibrary->overdriveAuthenticationILSName)) {
				$this->ILSName = $patronHomeLibrary->overdriveAuthenticationILSName;
			}elseif (!empty($library->overdriveAuthenticationILSName)) {
				$this->ILSName = $library->overdriveAuthenticationILSName;
			} elseif (isset($configArray['OverDrive']['LibraryCardILS'])){
				$this->ILSName = $configArray['OverDrive']['LibraryCardILS'];
			}
		}
		return $this->ILSName;
	}

	/**
	 * @param $user User
	 * @return bool
	 */
	private function getRequirePin($user){
		if (!isset($this->requirePin)) {
			// use library setting if it has a value. if no library setting, use the configuration setting.
			global $library, $configArray;
			$patronHomeLibrary = Library::getLibraryForLocation($user->homeLocationId);
			if (!empty($patronHomeLibrary->overdriveRequirePin)) {
				$this->requirePin = $patronHomeLibrary->overdriveRequirePin;
			}elseif (isset($library->overdriveRequirePin)) {
				$this->requirePin = $library->overdriveRequirePin;
			} elseif (isset($configArray['OverDrive']['requirePin'])){
				$this->requirePin = $configArray['OverDrive']['requirePin'];
			} else {
				$this->requirePin = false;
			}
		}
		return $this->requirePin;
	}

    /**
     * @param User $user
     * @param $url
     * @param array $postParams
     * @return bool|mixed
     */
	public function _callPatronUrl($user, $url, $postParams = null){
		global $configArray;

		$userBarcode = $user->getBarcode();
		if ($this->getRequirePin($user)){
			$userPin = $user->getPasswordOrPin();
			$tokenData = $this->_connectToPatronAPI($user, $userBarcode, $userPin, false);
		}else{
			$tokenData = $this->_connectToPatronAPI($user, $userBarcode, null, false);
		}
		if ($tokenData){
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
			if (isset($tokenData->token_type) && isset($tokenData->access_token)){
				$authorizationData = $tokenData->token_type . ' ' . $tokenData->access_token;
				$headers = array(
					"Authorization: $authorizationData",
					"User-Agent: VuFind-Plus",
					"Host: patron.api.overdrive.com" // production
					//"Host: integration-patron.api.overdrive.com" // testing
				);
			}else{
				//The user is not valid
				if (isset($configArray['Site']['debug']) && $configArray['Site']['debug'] == true){
					print_r($tokenData);
				}
				return false;
			}

			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
			if ($postParams != null){
				curl_setopt($ch, CURLOPT_POST, 1);
				//Convert post fields to json
				$jsonData = array('fields' => array());
				foreach ($postParams as $key => $value){
					$jsonData['fields'][] = array(
						'name' => $key,
						'value' => $value
					);
				}
				$postData = json_encode($jsonData);
				//print_r($postData);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
				$headers[] = 'Content-Type: application/vnd.overdrive.content.api+json';
			}else{
				curl_setopt($ch, CURLOPT_HTTPGET, true);
			}
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

				$return = curl_exec($ch);
			curl_close($ch);
			$returnVal = json_decode($return);
			if ($returnVal != null){
				if (!isset($returnVal->message) || $returnVal->message != 'An unexpected error has occurred.'){
					return $returnVal;
				}
			}
		}
		return false;
	}

	private function _callPatronDeleteUrl($user, $patronBarcode, $patronPin, $url){
		$tokenData = $this->_connectToPatronAPI($user, $patronBarcode, $patronPin, false);
		//TODO: Remove || true when oauth works
		if ($tokenData || true){
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
			if ($tokenData){
				$authorizationData = $tokenData->token_type . ' ' . $tokenData->access_token;
				$headers = array(
					"Authorization: $authorizationData",
					"User-Agent: Aspen Discovery",
					"Host: patron.api.overdrive.com",
					//"Host: integration-patron.api.overdrive.com"
				);
			}else{
				$headers = array("User-Agent: VuFind-Plus", "Host: api.overdrive.com");
			}

			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

			$return = curl_exec($ch);
			$returnInfo = curl_getinfo($ch);
			if ($returnInfo['http_code'] == 204){
				$result = true;
			}else{
				//echo("Response code was " . $returnInfo['http_code']);
				$result = false;
			}
			curl_close($ch);
			$returnVal = json_decode($return);
			//print_r($returnVal);
			if ($returnVal != null){
				if (!isset($returnVal->message) || $returnVal->message != 'An unexpected error has occurred.'){
					return $returnVal;
				}
			}else{
				return $result;
			}
		}
		return false;
	}

	public function getLibraryAccountInformation(){
		global $configArray;
		$libraryId = $configArray['OverDrive']['websiteId'];
		return $this->_callUrl("https://api.overdrive.com/v1/libraries/$libraryId");
	}

	public function getAdvantageAccountInformation(){
		global $configArray;
		$libraryId = $configArray['OverDrive']['websiteId'];
		return $this->_callUrl("https://api.overdrive.com/v1/libraries/$libraryId/advantageAccounts");
	}

	public function getProductsInAccount($productsUrl = null, $start = 0, $limit = 25){
		global $configArray;
		if ($productsUrl == null){
			$libraryId = $configArray['OverDrive']['websiteId'];
			$productsUrl = "https://api.overdrive.com/v1/collections/$libraryId/products";
		}
		$productsUrl .= "?offset=$start&limit=$limit";
		return $this->_callUrl($productsUrl);
	}

	public function getProductById($overDriveId, $productsKey = null){
		$productsUrl = "https://api.overdrive.com/v1/collections/$productsKey/products";
		$productsUrl .= "?crossRefId=$overDriveId";
		return $this->_callUrl($productsUrl);
	}

	public function getProductMetadata($overDriveId, $productsKey = null){
		global $configArray;
		if ($productsKey == null){
			$productsKey = $configArray['OverDrive']['productsKey'];
		}
		$overDriveId= strtoupper($overDriveId);
		$metadataUrl = "https://api.overdrive.com/v1/collections/$productsKey/products/$overDriveId/metadata";
		return $this->_callUrl($metadataUrl);
	}

	public function getProductAvailability($overDriveId, $productsKey = null){
		global $configArray;
		if ($productsKey == null){
			$productsKey = $configArray['OverDrive']['productsKey'];
		}
		$availabilityUrl = "https://api.overdrive.com/v2/collections/$productsKey/products/$overDriveId/availability";
		return $this->_callUrl($availabilityUrl);
	}

	private $checkouts = array();
    /**
     * Loads information about items that the user has checked out in OverDrive
     *
     * @param User $patron
     * @param boolean $forSummary
     *
     * @return array
     */
	public function getCheckouts($patron, $forSummary = false){
		if (isset($this->checkouts[$patron->id])){
			return $this->checkouts[$patron->id];
		}
		global $configArray;
		global $logger;
		if (!$this->isUserValidForOverDrive($patron)){
			return array();
		}
		$url = $configArray['OverDrive']['patronApiUrl'] . '/v1/patrons/me/checkouts';
		$response = $this->_callPatronUrl($patron, $url);
		if ($response == false){
			//The user is not authorized to use OverDrive
			return array();
		}

		$checkedOutTitles = array();
		if (isset($response->checkouts)){
			foreach ($response->checkouts as $curTitle){
				$bookshelfItem = array();
				//Load data from api
				$bookshelfItem['checkoutSource'] = 'OverDrive';
				$bookshelfItem['overDriveId'] = $curTitle->reserveId;
				$bookshelfItem['expiresOn'] = $curTitle->expires;
                try {
                    $expirationDate = new DateTime($curTitle->expires);
                    $bookshelfItem['dueDate'] = $expirationDate->getTimestamp();
                } catch (Exception $e) {
                    $logger->log("Could not parse date for overdrive expiration " . $curTitle->expires, Logger::LOG_NOTICE);
                }
                try {
                    $checkOutDate = new DateTime($curTitle->checkoutDate);
                    $bookshelfItem['checkoutDate'] = $checkOutDate->getTimestamp();
                } catch (Exception $e) {
                    $logger->log("Could not parse date for overdrive checkout date " . $curTitle->checkoutDate, Logger::LOG_NOTICE);
                }
				$bookshelfItem['overdriveRead'] = false;
				if (isset($curTitle->isFormatLockedIn) && $curTitle->isFormatLockedIn == 1){
					$bookshelfItem['formatSelected'] = true;
				}else{
					$bookshelfItem['formatSelected'] = false;
				}
				$bookshelfItem['formats'] = array();
				if (!$forSummary){
					if (isset($curTitle->formats)){
						foreach ($curTitle->formats as $id => $format){
							if ($format->formatType == 'ebook-overdrive' || $format->formatType == 'ebook-mediado') {
								$bookshelfItem['overdriveRead'] = true;
							}else if ($format->formatType == 'audiobook-overdrive'){
									$bookshelfItem['overdriveListen'] = true;
							}else if ($format->formatType == 'video-streaming'){
								$bookshelfItem['overdriveVideo'] = true;
							}else{
								$bookshelfItem['selectedFormat'] = array(
									'name' => $this->format_map[$format->formatType],
									'format' => $format->formatType,
								);
							}
							$curFormat = array();
							$curFormat['id'] = $id;
							$curFormat['format'] = $format;
							$curFormat['name'] = $format->formatType;
							if (isset($format->links->self)){
								$curFormat['downloadUrl'] = $format->links->self->href . '/downloadlink';
							}
							if ($format->formatType != 'ebook-overdrive' && $format->formatType != 'ebook-mediado' && $format->formatType != 'audiobook-overdrive' && $format->formatType != 'video-streaming'){
								$bookshelfItem['formats'][] = $curFormat;
							}else{
								if (isset($curFormat['downloadUrl'])){
									if ($format->formatType = 'ebook-overdrive' || $format->formatType == 'ebook-mediado') {
										$bookshelfItem['overdriveReadUrl'] = $curFormat['downloadUrl'];
									}else if ($format->formatType == 'video-streaming') {
										$bookshelfItem['overdriveVideoUrl'] = $curFormat['downloadUrl'];
									}else{
										$bookshelfItem['overdriveListenUrl'] = $curFormat['downloadUrl'];
									}
								}
							}
						}
					}
					if (isset($curTitle->actions->format) && !$bookshelfItem['formatSelected']){
						//Get the options for the format which includes the valid formats
						$formatField = null;
						foreach ($curTitle->actions->format->fields as $curFieldIndex => $curField){
							if ($curField->name == 'formatType'){
								$formatField = $curField;
								break;
							}
						}
						if (isset($formatField->options)){
							foreach ($formatField->options as $index => $format){
								$curFormat = array();
								$curFormat['id'] = $format;
								$curFormat['name'] = $this->format_map[$format];
								$bookshelfItem['formats'][] = $curFormat;
							}
						//}else{
							//No formats found for the title, do we need to do anything special?
						}
					}

					if (isset($curTitle->actions->earlyReturn)){
						$bookshelfItem['earlyReturn']  = true;
					}
					//Figure out which eContent record this is for.
					require_once ROOT_DIR . '/RecordDrivers/OverDriveRecordDriver.php';
					$overDriveRecord = new OverDriveRecordDriver($bookshelfItem['overDriveId']);
					$bookshelfItem['recordId'] = $overDriveRecord->getUniqueID();
					$groupedWorkId = $overDriveRecord->getGroupedWorkId();
					if ($groupedWorkId != null){
						$bookshelfItem['groupedWorkId'] = $overDriveRecord->getGroupedWorkId();
					}
					$formats = $overDriveRecord->getFormats();
					$bookshelfItem['format']     = reset($formats);
					$bookshelfItem['coverUrl']   = $overDriveRecord->getCoverUrl('medium');
					$bookshelfItem['recordUrl']  = $configArray['Site']['path'] . '/OverDrive/' . $overDriveRecord->getUniqueID() . '/Home';
					$bookshelfItem['title']      = $overDriveRecord->getTitle();
					$bookshelfItem['author']     = $overDriveRecord->getAuthor();
					$bookshelfItem['linkUrl']    = $overDriveRecord->getLinkUrl(false);
					$bookshelfItem['ratingData'] = $overDriveRecord->getRatingData();
				}
				$bookshelfItem['user'] = $patron->getNameAndLibraryLabel();
				$bookshelfItem['userId'] = $patron->id;

				$key = $bookshelfItem['checkoutSource'] . $bookshelfItem['overDriveId'];
				$checkedOutTitles[$key] = $bookshelfItem;
			}
		}
		if (!$forSummary){
			$this->checkouts[$patron->id] = $checkedOutTitles;
		}
		return $checkedOutTitles;
	}

	private $holds = array();

	/**
     * @param User $user
     * @param bool $forSummary
     * @return array
     */
	public function getHolds($user, $forSummary = false){
		//Cache holds for the user just for this call.
		if (isset($this->holds[$user->id])){
			return $this->holds[$user->id];
		}
		global $configArray;
		$holds = array(
			'available' => array(),
			'unavailable' => array()
		);
		if (!$this->isUserValidForOverDrive($user)){
			return $holds;
		}
		$url = $configArray['OverDrive']['patronApiUrl'] . '/v1/patrons/me/holds';
		$response = $this->_callPatronUrl($user, $url);
		if (isset($response->holds)){
			foreach ($response->holds as $curTitle){
				$hold = array();
				$hold['overDriveId'] = $curTitle->reserveId;
				if ($curTitle->emailAddress){
					$hold['notifyEmail'] = $curTitle->emailAddress;
				}
				$datePlaced                = strtotime($curTitle->holdPlacedDate);
				if ($datePlaced) {
					$hold['create']            = $datePlaced;
				}
				$hold['holdQueueLength']   = $curTitle->numberOfHolds;
				$hold['holdQueuePosition'] = $curTitle->holdListPosition;
				$hold['position']          = $curTitle->holdListPosition;  // this is so that overdrive holds can be sorted by hold position with the IlS holds
				$hold['available']         = isset($curTitle->actions->checkout);
				if ($hold['available']){
					$hold['expire'] = strtotime($curTitle->holdExpires);
				}
				$hold['holdSource'] = 'OverDrive';

				//Figure out which eContent record this is for.
				require_once ROOT_DIR . '/RecordDrivers/OverDriveRecordDriver.php';
				if (!$forSummary){
					$overDriveRecord = new OverDriveRecordDriver($hold['overDriveId']);
					$hold['recordId'] = $overDriveRecord->getUniqueID();
					$hold['coverUrl'] = $overDriveRecord->getCoverUrl('medium');
					$hold['recordUrl'] = $configArray['Site']['path'] . '/OverDrive/' . $overDriveRecord->getUniqueID() . '/Home';
					$hold['title'] = $overDriveRecord->getTitle();
					$hold['sortTitle'] = $overDriveRecord->getTitle();
					$hold['author'] = $overDriveRecord->getAuthor();
					$hold['linkUrl'] = $overDriveRecord->getLinkUrl(false);
					$hold['format'] = $overDriveRecord->getFormats();
					$hold['ratingData'] = $overDriveRecord->getRatingData();
				}
				$hold['user'] = $user->getNameAndLibraryLabel();
				$hold['userId'] = $user->id;

				$key = $hold['holdSource'] . $hold['overDriveId'] . $hold['user'];
				if ($hold['available']){
					$holds['available'][$key] = $hold;
				}else{
					$holds['unavailable'][$key] = $hold;
				}
			}
		}
		if (!$forSummary){
			$this->holds[$user->id] = $holds;
		}
		return $holds;
	}

	/**
	 * Returns a summary of information about the user's account in OverDrive.
	 *
	 * @param User $patron
	 *
	 * @return array
	 */
	public function getAccountSummary($patron){
		/** @var Memcache $memCache */
		global $memCache;
		global $configArray;
		global $timer;

		if ($patron == false){
			return array(
				'numCheckedOut' => 0,
				'numAvailableHolds' => 0,
				'numUnavailableHolds' => 0,
				'checkedOut' => array(),
				'holds' => array()
			);
		}

		$summary = $memCache->get('overdrive_summary_' . $patron->id);
		if ($summary == false || isset($_REQUEST['reload'])){

			//Get account information from api

			//TODO: Optimize so we don't need to load all checkouts and holds
			$summary = array();
			$checkedOutItems = $this->getCheckouts($patron, true);
			$summary['numCheckedOut'] = count($checkedOutItems);

			$holds = $this->getHolds($patron, true);
			$summary['numAvailableHolds'] = count($holds['available']);
			$summary['numUnavailableHolds'] = count($holds['unavailable']);

			$summary['checkedOut'] = $checkedOutItems;
			$summary['holds'] = $holds;

			$timer->logTime("Finished loading titles from overdrive summary");
			$memCache->set('overdrive_summary_' . $patron->id, $summary, 0, $configArray['Caching']['overdrive_summary']);
		}

		return $summary;
	}

	/**
	 * Places a hold on an item within OverDrive
	 *
	 * @param string $overDriveId
	 * @param User $user
	 *
	 * @return array (result, message)
	 */
	public function placeHold($user, $overDriveId){
		global $configArray;
		/** @var Memcache $memCache */
		global $memCache;

		$url = $configArray['OverDrive']['patronApiUrl'] . '/v1/patrons/me/holds/' . $overDriveId;
		$params = array(
			'reserveId' => $overDriveId,
			'emailAddress' => trim($user->overdriveEmail)
		);
		$response = $this->_callPatronUrl($user, $url, $params);

		$holdResult = array();
		$holdResult['success'] = false;
		$holdResult['message'] = '';

		//print_r($response);
		if (isset($response->holdListPosition)){
			$holdResult['success'] = true;
			$holdResult['message'] = 'Your hold was placed successfully.  You are number ' . $response->holdListPosition . ' on the wait list.';
		}else{
			$holdResult['message'] = 'Sorry, but we could not place a hold for you on this title.';
			if (isset($response->message)) $holdResult['message'] .= "  {$response->message}";
		}
		$user->clearCache();
		$memCache->delete('overdrive_summary_' . $user->id);

		return $holdResult;
	}

	/**
     * @param User    $user
     * @param string  $overDriveId
     * @return array
	 */
	public function cancelHold($user, $overDriveId){
		global $configArray;
        /** @var Memcache $memCache */
		global $memCache;

		$url = $configArray['OverDrive']['patronApiUrl'] . '/v1/patrons/me/holds/' . $overDriveId;
		$userBarcode = $user->getBarcode();
		if ($this->getRequirePin($user)){
            $userPin = $user->getPasswordOrPin();
			$response = $this->_callPatronDeleteUrl($user, $userBarcode, $userPin, $url);
		}else{
			$response = $this->_callPatronDeleteUrl($user, $userBarcode, null, $url);
		}

		$cancelHoldResult = array();
		$cancelHoldResult['success'] = false;
		$cancelHoldResult['message'] = '';
		if ($response === true){
			$cancelHoldResult['success'] = true;
			$cancelHoldResult['message'] = 'Your hold was cancelled successfully.';
		}else{
			$cancelHoldResult['message'] = 'There was an error cancelling your hold.';
		    if (isset($response->message)) $cancelHoldResult['message'] .= "  {$response->message}";
		}
		$memCache->delete('overdrive_summary_' . $user->id);
		$user->clearCache();
		return $cancelHoldResult;
	}

	/**
	 * Checkout a title from OverDrive
	 *
	 * @param string $overDriveId
	 * @param User $user
	 *
	 * @return array results (success, message, noCopies)
	 */
	public function checkOutTitle($user, $overDriveId){

		global $configArray;
        /** @var Memcache $memCache */
		global $memCache;

		$url = $configArray['OverDrive']['patronApiUrl'] . '/v1/patrons/me/checkouts';
		$params = array(
			'reserveId' => $overDriveId,
		);
		$response = $this->_callPatronUrl($user, $url, $params);

		$result = array();
		$result['success'] = false;
		$result['message'] = '';

		//print_r($response);
		if (isset($response->expires)) {
			$result['success'] = true;
			$result['message'] = 'Your title was checked out successfully. You may now download the title from your Account.';
		}else{
			$result['message'] = 'Sorry, we could not checkout this title to you.';
			if (isset($response->errorCode) && $response->errorCode == 'PatronHasExceededCheckoutLimit'){
				$result['message'] .= "\r\n\r\nYou have reached the maximum number of OverDrive titles you can checkout one time.";
			}else{
				if (isset($response->message)) $result['message'] .= "  {$response->message}";
			}

			if (isset($response->errorCode) && ($response->errorCode == 'NoCopiesAvailable' || $response->errorCode == 'PatronHasExceededCheckoutLimit')) {
				$result['noCopies'] = true;
				$result['message'] .= "\r\n\r\nWould you like to place a hold instead?";
			}else{
				//Give more information about why it might gave failed, ie expired card or too much fines
				$result['message'] = 'Sorry, we could not checkout this title to you.  Please verify that your card has not expired and that you do not have excessive fines.';
			}

		}

		$memCache->delete('overdrive_summary_' . $user->id);
		$user->clearCache();
		return $result;
	}

    /**
     * @param $overDriveId
     * @param User $user
     * @return array
     */
	public function returnCheckout($user, $overDriveId){
		global $configArray;
        /** @var Memcache $memCache */
		global $memCache;

		$url = $configArray['OverDrive']['patronApiUrl'] . '/v1/patrons/me/checkouts/' . $overDriveId;
		$userBarcode = $user->getBarcode();
		if ($this->getRequirePin($user)){
			$userPin = $user->getPasswordOrPin();
			$response = $this->_callPatronDeleteUrl($user, $userBarcode, $userPin, $url);
		}else{
			$response = $this->_callPatronDeleteUrl($user, $userBarcode, null, $url);
		}

		$cancelHoldResult = array();
		$cancelHoldResult['success'] = false;
		$cancelHoldResult['message'] = '';
		if ($response === true){
			$cancelHoldResult['success'] = true;
			$cancelHoldResult['message'] = 'Your item was returned successfully.';
		}else{
			$cancelHoldResult['message'] = 'There was an error returning this item.';
			if (isset($response->message)) $cancelHoldResult['message'] .= "  {$response->message}";
		}

		$memCache->delete('overdrive_summary_' . $user->id);
		$user->clearCache();
		return $cancelHoldResult;
	}

	public function selectOverDriveDownloadFormat($overDriveId, $formatId, $user){
		global $configArray;
		/** @var Memcache $memCache */
		global $memCache;

		$url = $configArray['OverDrive']['patronApiUrl'] . '/v1/patrons/me/checkouts/' . $overDriveId . '/formats';
		$params = array(
			'reserveId' => $overDriveId,
			'formatType' => $formatId
		);
		$response = $this->_callPatronUrl($user, $url, $params);
		//print_r($response);

		$result = array();
		$result['success'] = false;
		$result['message'] = '';

		if (isset($response->linkTemplates->downloadLink)){
			$result['success'] = true;
			$result['message'] = 'This format was locked in';
			$downloadLink = $this->getDownloadLink($overDriveId, $formatId, $user);
			$result = $downloadLink;
		}else{
			$result['message'] = 'Sorry, but we could not select a format for you.';
			if (isset($response->message)) $result['message'] .= "  {$response->message}";
		}
		$memCache->delete('overdrive_summary_' . $user->id);

		return $result;
	}

	/**
	 * @param $user  User
	 * @return bool
	 */
	public function isUserValidForOverDrive($user){
		global $timer;
		$userBarcode = $user->getBarcode();
		if ($this->getRequirePin($user)){
			$userPin = $user->getPasswordOrPin();
			// determine which column is the pin by using the opposing field to the barcode. (between catalog password & username)
			$tokenData = $this->_connectToPatronAPI($user, $userBarcode, $userPin, false);
			// this worked for flatirons checkout.  plb 1-13-2015
		}else{
			$tokenData = $this->_connectToPatronAPI($user, $userBarcode, null, false);
		}
		$timer->logTime("Checked to see if the user $userBarcode is valid for OverDrive");
		return ($tokenData !== false) && ($tokenData !== null) && !array_key_exists('error', $tokenData);
	}

	public function getDownloadLink($overDriveId, $format, $user){
		global $configArray;

		$url = $configArray['OverDrive']['patronApiUrl'] . "/v1/patrons/me/checkouts/{$overDriveId}/formats/{$format}/downloadlink";
		$url .= '?errorpageurl=' . urlencode($configArray['Site']['url'] . '/Help/OverDriveError');
		if ($format == 'ebook-overdrive' || $format == 'ebook-mediado'){
			$url .= '&odreadauthurl=' . urlencode($configArray['Site']['url'] . '/Help/OverDriveError');
		}elseif ($format == 'audiobook-overdrive'){
			$url .= '&odreadauthurl=' . urlencode($configArray['Site']['url'] . '/Help/OverDriveError');
		}elseif ($format == 'video-streaming'){
			$url .= '&errorurl=' . urlencode($configArray['Site']['url'] . '/Help/OverDriveError');
			$url .= '&streamingauthurl=' . urlencode($configArray['Site']['url'] . '/Help/streamingvideoauth');
		}

		$response = $this->_callPatronUrl($user, $url);
		//print_r($response);

		$result = array();
		$result['success'] = false;
		$result['message'] = '';

		if (isset($response->links->contentlink)){
			$result['success'] = true;
			$result['message'] = 'Created Download Link';
			$result['downloadUrl'] = $response->links->contentlink->href;
		}else{
			$result['message'] = 'Sorry, but we could not get a download link for you.';
			if (isset($response->message)) $result['message'] .= "  {$response->message}";
		}

		return $result;
	}

	public function getLibraryScopingId(){
		//For econtent, we need to be more specific when restricting copies
		//since patrons can't use copies that are only available to other libraries.
		$searchLibrary = Library::getSearchLibrary();
		$searchLocation = Location::getSearchLocation();
		$activeLibrary = Library::getActiveLibrary();
        global $locationSingleton;
        $activeLocation = $locationSingleton->getActiveLocation();
		$homeLibrary = Library::getPatronHomeLibrary();

		//Load the holding label for the branch where the user is physically.
		if (!is_null($homeLibrary)){
			return $homeLibrary->includeOutOfSystemExternalLinks ? -1 : $homeLibrary->libraryId;
		}else if (!is_null($activeLocation)){
			$activeLibrary = Library::getLibraryForLocation($activeLocation->locationId);
			return $activeLibrary->includeOutOfSystemExternalLinks ? -1 : $activeLibrary->libraryId;
		}else if (isset($activeLibrary)) {
			return $activeLibrary->includeOutOfSystemExternalLinks ? -1 : $activeLibrary->libraryId;
		}else if (!is_null($searchLocation)){
			$searchLibrary = Library::getLibraryForLocation($searchLibrary->locationId);
			return $searchLibrary->includeOutOfSystemExternalLinks ? -1 : $searchLocation->libraryId;
		}else if (isset($searchLibrary)) {
			return $searchLibrary->includeOutOfSystemExternalLinks ? -1 : $searchLibrary->libraryId;
		}else{
			return -1;
		}
	}

	public function hasNativeReadingHistory()
    {
        return false;
    }

    public function hasFastRenewAll()
    {
        return false;
    }

    /**
     * Renew all titles currently checked out to the user.
     * This is not currently implemented
     *
     * @param $patron  User
     * @return mixed
     */
    public function renewAll($patron)
    {
        return false;
    }

    /**
     * Renew a single title currently checked out to the user
     * This is not currently implemented
     *
     * @param $patron     User
     * @param $recordId   string
     * @return mixed
     */
    public function renewCheckout($patron, $recordId)
    {
        return false;
    }
}
