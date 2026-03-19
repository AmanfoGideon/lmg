<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Compose;
    
    use Zenoph\Notify\Utils\MessageUtil;
    use Zenoph\Notify\Utils\PhoneUtil;
    use Zenoph\Notify\Enums\DestinationMode;
    USE Zenoph\Notify\Enums\NumberAddInfo;
    use Zenoph\Notify\Compose\IComposer;
    use Zenoph\Notify\Store\UserData;
    use Zenoph\Notify\Store\AuthProfile;
    use Zenoph\Notify\Store\ComposerDestination;
    use Zenoph\Notify\Collections\ObjectStorage;
    use Zenoph\Notify\Collections\ComposerDestinationsList;
    
    abstract class Composer implements IComposer {
        protected UserData | null $_userData;
        protected ObjectStorage $_destinations;
        protected array $_destIdsMap;
        protected array $_destNumbersMap;
        protected $_scheduleDateTime = null;
        protected $_scheduleUTCOffset = null;
        protected int $_category;

        const __CUSTOM_DATA_LABEL__ = "data";
        const __DEST_COMP_LIST_LABEL__ = 'compDestsList';
        const __DEST_COUNTRYCODE_LABEL__ = 'countryCode';

        
        public function __construct(?AuthPRofile $authProfile = null) {
            if (!is_null($authProfile)){
                if ($authProfile instanceof AuthProfile === false)
                    throw new \Exception('Invalid parameter for initialising message object.');
                
                if (!$authProfile->authenticated())
                    throw new \Exception('User profile has not been authenticated.'); 
                
                $this->_userData = $authProfile->getUserData();
            }
            
            $this->_destinations = new ObjectStorage();
            $this->_destIdsMap = array();
            $this->_destNumbersMap = array();
        }
        
        public function setUserData(UserData $ud): void {
            // set data
            $this->_userData = $ud;
        }

        public function getCategory(): int {
            return $this->_category;
        }

        public function getDestinationCountry(string $phoneNumber): string | null {
            if (!isset($this->_userData))
                return null;
            
            if (!$this->destinationExists($phoneNumber))
                return null;
            
            $numberInfo = $this->formatPhoneNumber($phoneNumber, false);
            $fmtdNumber = $numberInfo[0];
            $countryCode = $this->getDestinationCountryCode($fmtdNumber);
            
            if (is_null($countryCode))
                return null;  
            
            $routeFilters = $this->_userData->getRouteFilters();
            
            if (!is_null($routeFilters) && isset($routeFilters[$countryCode]))
                return $routeFilters[$countryCode]['countryName'];
            
            return null;
        }
        
        public function getDefaultDestinationCountry() {
            if (is_null($this->_userData))
                throw new \Exception('Default destination country has not been loaded.');
            
            $defRouteInfo = $this->_userData->getDefaultRouteInfo();
            $countryName = $defRouteInfo['countryName'];
            $countryCode = $defRouteInfo['countryCode'];
            $dialCode = $defRouteInfo['dialCode'];
            
            return array($countryName, $countryCode, $dialCode);
        }
        
        protected function getDestinationCountryCode($phoneNumber): string | null {
            $countryCode = null;
            
            // If the phone number has already been added, then quickly get the country code
            if ($this->formattedDestinationExists($phoneNumber)){
                $countryCode = $this->_destNumbersMap[$phoneNumber][self::__DEST_COUNTRYCODE_LABEL__];
            }
            else {
                $numberInfo = $this->formatPhoneNumber($phoneNumber, false);
                
                if (is_null($numberInfo))
                    return null;
                
                // use the country code to get the country name
                $countryCode = $numberInfo[1];
            }
            
            return $countryCode;
        }

        public function getDestinationWriteMode(string $phoneNumber): int {
            // destination must exist
            if (!$this->destinationExists($phoneNumber))
                throw new \Exception("Phone number '{$phoneNumber}' does not exist in the destinations list.");
                
            // format in international format
            $numberInfo = $this->formatPhoneNumber($phoneNumber);
            $fmtdNumber = $numberInfo[0];
            
            $compDestsStore = $this->getMappedDestinations($fmtdNumber);
            
            // Here, message is not personalised message. For non-personalised
            // text messages, there shouldn't be multiple items
            if ($compDestsStore->getCount() > 1)
                throw new \Exception('There are multiple destination data information.');
            
            $compDestsArr = &$compDestsStore->getItems();
            $compDest = &$compDestsArr[0];
            
            // return destination mode
            return $compDest->getWriteMode();
        }
        
        public function getDestinationWriteModeById(string $messageId): int {
            // it should exit in the message Ids list
            if (!array_key_exists($messageId, $this->_destIdsMap))
                throw new \Exception("Message identifier '{$messageId}' does not exist.");
                
            // return the destination mode using the message id
            return $this->_destIdsMap[$messageId]->getWriteMode();
        }
        
        protected function getMappedDestinations(string $phoneNumber): ObjectStorage {
            if (!array_key_exists($phoneNumber, $this->_destNumbersMap))
                throw new \Exception("Phone number '{$phoneNumber}' does not exist in the destinations list.");

            return $this->_destNumbersMap[$phoneNumber][self::__DEST_COMP_LIST_LABEL__];
        }
        
        protected function getMappedDestinationById(string $destId): ComposerDestination{
            if (!$this->destinationIdExists($destId))
                throw new \Exception("Message identifier '{$destId}' does not exist.");
                
            // we will need the phone number
            return $this->_destIdsMap[$destId];
        }
        
        protected function getComposerDestinationById(string $destId): ComposerDestination {
            return $this->getMappedDestinationById($destId);
        }
        
        protected function getComposerDestinations(string $phoneNumber): ObjectStorage {
            return $this->getMappedDestinations($phoneNumber);
        }
        
        protected function formattedDestinationExists(string $phoneNumber) :bool {
            // see if it exists.
            return array_key_exists($phoneNumber, $this->_destNumbersMap);
        }
        
        public function destinationExists(string $phonenum): bool {
            $numberInfo = $this->formatPhoneNumber($phonenum);
            
            if (is_null($numberInfo))
                return false;
            
            // check with the formatted phone number.
            return $this->formattedDestinationExists($numberInfo[0]);
        }
        
        protected function formatPhoneNumber(string $phoneNumber, bool $throwEx = false): array | null {
            if (!isset($this->_userData))
                return UserData::createDestinationCountryMap ($phoneNumber, null);
            else
                return $this->_userData->formatPhoneNumber($phoneNumber, $throwEx);
        }
        
        protected function getFormattedPhoneNumber(string $phoneNumber): string {
            if (!$this->destinationExists($phoneNumber))
                throw new \Exception("Phone number '{$phoneNumber}' does not exist.");
                
            $numberInfo = $this->formatPhoneNumber($phoneNumber);
            return $numberInfo[0];
        }
        
        public function clearDestinations(): void {
            unset($this->_destIdsMap);
            unset($this->_destNumbersMap);
            $this->_destinations->clear();
            
            $this->_destIdsMap = $this->_destNumbersMap = array();
        }
        
        public function addDestinationsFromTextStream(string &$str): int {
            $addCount = 0;
            $validList = PhoneUtil::extractPhoneNumbers($str);
            
            if (!is_null($validList) && is_array($validList)){
                for ($i = 0; $i < count($validList); ++$i){
                    $phoneNum = $validList[$i];
                    
                    if ($this->addDestination($phoneNum, false) == NumberAddInfo::NAI_OK)
                        ++$addCount;
                }
            }
            
            return $addCount;
        }
        
        public function addDestinationsFromCollection(array &$phoneNumbers, bool $throwEx = false): int {
            $count = 0;
            
            foreach ($phoneNumbers as $phoneNum){
                if ($this->addDestination($phoneNum, $throwEx) == NumberAddInfo::NAI_OK){
                    $count++;
                }
            }
            
            return $count;
        }
        
        public function addDestination(string $phoneNumber, bool $throwEx = true, ?string $messageId = null): int {
            if (is_null($phoneNumber) || empty($phoneNumber)){
                if (!$throwEx)
                    return NumberAddInfo::NAI_REJTD_INVALID;
                
                throw new \Exception('Invalid value for adding message destination.');
            }
            
            if (!PhoneUtil::isValidPhoneNumber($phoneNumber)){
                if (!$throwEx)
                    return NumberAddInfo::NAI_REJTD_INVALID;
                
                throw new \Exception("'{$phoneNumber}' is not a valid phone number.");
            }
 
            if (!is_null($messageId) && !empty($messageId)) {
                if ($this->validateCustomMessageId($messageId, $throwEx) != NumberAddInfo::NAI_OK)
                    return NumberAddInfo::NAI_REJTD_MSGID_INVALID;
            }
            
            $numberInfo = $this->formatPhoneNumber($phoneNumber);
            
            if (is_null($numberInfo)){
                if (!$throwEx)
                    return NumberAddInfo::NAI_REJTD_ROUTE;

                throw new \Exception("'{$phoneNumber}' is not a valid destination on permitted routes.");
            }
            
            $fmtdNumber = $numberInfo[0];
            $countryCode = $numberInfo[1];
            
            // destination must not already exist
            if ($this->formattedDestinationExists($fmtdNumber)){
                if (!$throwEx)
                    return NumberAddInfo::NAI_REJTD_EXISTS;
                
                throw new \Exception("Phone number '{$phoneNumber}' already exists.");
            }

            // add and return status
            return $this->addDestinationInfo($fmtdNumber, $countryCode, $messageId, null);
        }
        
        protected function addDestinationInfo(string $phoneNumber, ?string $countryCode, ?string $messageId, mixed $destData): int{
            // Here, we will be adding a destination
            $destMode = DestinationMode::DM_ADD;
            
            // create the composer destination
            $compDest = $this->createComposerDestination($phoneNumber, $messageId, $destMode, $destData);
            $this->addComposerDestination($compDest, $countryCode);
            
            // it was added successfully
            return NumberAddInfo::NAI_OK;
        }
        
        protected function addComposerDestinationsList(array $compDestsList, ?string $countryCode): void {
            foreach ($compDestsList as $compdest)
                $this->addComposerDestination ($compdest, $countryCode);
        }
        
        protected function addComposerDestination(ComposerDestination $compDest, ?string $countryCode = null): void {
            $messageId = $compDest->getMessageId();
            
            if (!is_null($messageId) && !empty($messageId)){
                // message Id must not already exist
                if (array_key_exists($messageId, $this->_destIdsMap))
                    throw new \Exception("Message identifier '{$messageId}' already exists.");
                    
                // add to the message Ids map
                $this->_destIdsMap[$messageId] = $compDest;
            }
            
            // attach to the destinations collection
            $this->_destinations->attach($compDest);
            
            // get the phone number
            $phoneNumber = $compDest->getPhoneNumber();
            
            if (array_key_exists($phoneNumber, $this->_destNumbersMap)){
                $compDestStore = $this->getMappedDestinations($phoneNumber);
                $compDestStore->attach($compDest);
            }
            else {
                $destList = new ObjectStorage();
                $destList->attach($compDest);
                
                $infoContainer[self::__DEST_COUNTRYCODE_LABEL__] = $countryCode;
                $infoContainer[self::__DEST_COMP_LIST_LABEL__] = $destList;
                
                $this->_destNumbersMap[$phoneNumber] = $infoContainer;
            }
        }
        
        protected function createComposerDestination(string $phoneNumber, ?string $messageId, int $destMode, mixed $destData, bool $isScheduled = false): ComposerDestination {     
            // create and set key data mappings
            $compDestData = [
                'phoneNumber' => $phoneNumber,
                'messageId' => $messageId,
                'destMode' => $destMode,
                'destData' => $destData,
                'scheduled' => $isScheduled
            ];
            
            return ComposerDestination::create($compDestData);
        }

        protected function destinationIdExists(string $destId) :bool {
            if (empty($destId))
                throw new \Exception('Invalid reference for verifying destination identifier.');
            
            return array_key_exists($destId, $this->_destIdsMap);
        }
        
        protected function removeComposerDestinationsList(string $phoneNumber, ObjectStorage $compDestStore): void {
            $replaceList = array();
            $countryCode = $this->getDestinationCountryCode($phoneNumber);
            
            if ($compDestStore->getCount() > 0){
                $compDestArr = &$compDestStore->getItems();
 
                for ($i = 0; $i < count($compDestArr); $i++){
                    $compDest = $compDestArr[$i];
                    
                    if ($compDest->isScheduled()){
                        $destination = $compDest->getPhoneNumber();
                        $messageId = $compDest->getMessageId();
                        $mode = DestinationMode::DM_DELETE;
                        $data = $compDest->getData();
                        
                        // create new for replacement
                        $newCompDest = $this->createComposerDestination($destination, $messageId, $mode, $data, true);
                        $replaceList[] = $newCompDest;
                    }
                    
                    // remove
                    $this->removeComposerDestination($compDest);
                }
                
                // the array is not needed
                unset($compDestArr);
                
                // if there are any to replace (scheduled destinations), add them
                if (count($replaceList) > 0)
                    $this->addComposerDestinationsList ($replaceList, $countryCode);
            }
        }
        
        protected function removeComposerDestination(ComposerDestination $compDest){
            if ($this->_destinations->contains($compDest)){
                // If there is a message Id, disassociate it
                $messageId = $compDest->getMessageId();
                $phoneNumber = $compDest->getPhoneNumber();
                
                // If there is a message Id, remove it
                if (!is_null($messageId) && array_key_exists($messageId, $this->_destIdsMap))
                    unset($this->_destIdsMap[$messageId]);
                
                // Remove it from destinations mapped by this phone number
                $mappedDests = $this->getMappedDestinations($phoneNumber);
                $mappedDests->detach($compDest);
                
                // If there is no item in destinations mapped by the phone number,
                // then the phone number serving as a key should be removed
                if ($mappedDests->getCount() == 0)
                    unset($this->_destNumbersMap[$phoneNumber]);
 
                // remove it from the composer destinations collection
                $this->_destinations->detach($compDest);
                
                // return success
                return true;
            }
            
            return false;
        }

        public function removeDestination(string $phoneNumber): bool  {
            // destination should exist
            if (!$this->destinationExists($phoneNumber))
                return false;
            
            $numberInfo = $this->formatPhoneNumber($phoneNumber);
            $fmtdNumber = $numberInfo[0];
            
            $compDestStore = $this->getMappedDestinations($fmtdNumber);
            $this->removeComposerDestinationsList($fmtdNumber, $compDestStore);
            
            return true;
        }
        
        public function removeDestinationById(string $destId): bool {
            // it must exist
            if (!$this->destinationIdExists($destId))
                throw new \Exception("Message identifier '{$destId}' does not exist.");
            
            // get the destination for removal
            $compDest = $this->getComposerDestinationById($destId);
            
            // remove the destination
            return $this->removeComposerDestination($compDest);
        }
        
        protected function getPhoneNumberFromMessageId(string $messageId): string {
            // it should exist
            if (!array_key_exists($messageId, $this->_destIdsMap))
                throw new \Exception("Message identifier '{$messageId}' does not exist.");
                
            // get the mapped composer destination
            $compDest = $this->_destIdsMap[$messageId];
           
            // return phone number
            return $compDest->getPhoneNumber();
        }
        
        protected function validateCustomMessageId(string $messageId, bool $throwEx) :int {
            if (!empty($messageId)){
                // It should not exist in the message Ids list
                if (array_key_exists($messageId, $this->_destIdsMap)) {
                    if (!$throwEx)
                        return NumberAddInfo::NAI_REJTD_MSGID_EXISTS;
                    
                    // exception should be thrown
                    throw new \Exception("Message identifier '{$messageId}' already exists.");
                }
                
                // check length is accepted
                $len = strlen($messageId);
                
                if ($len < MessageUtil::__CUSTOM_MSGID_MIN_LEN__ || $len > MessageUtil::__CUSTOM_MSGID_MAX_LEN__) {
                    if (!$throwEx)
                        return NumberAddInfo::NAI_REJTD_MSGID_LENGTH;
                    
                    throw new \Exception('Invalid message identifier length.');
                }
                
                // should match allowed pattern
                $pattern = "/[A-Za-z0-9-]{".MessageUtil::__CUSTOM_MSGID_MIN_LEN__.",}/";
                
                if (!preg_match($pattern, $messageId)){
                    if (!$throwEx)
                        return NumberAddInfo::NAI_REJTD_MSGID_INVALID;
                    
                    throw new \Exception("Message identifier '{$messageId}' is not in the correct format.");
                }
            }
            
            return NumberAddInfo::NAI_OK;
        }
        
        protected function validateDestinationUpdate(string $prePhoneNumber, string $newPhoneNumber): array {
            // convert to international number formats
            $preNumberInfo = $this->formatPhoneNumber($prePhoneNumber);
            $newNumberInfo = $this->formatPhoneNumber($newPhoneNumber);
            
            if (is_null($preNumberInfo))
                throw new \Exception('Invalid or unsupported previous phone number for updating destination.');
            
            if (is_null($newNumberInfo))
                throw new \Exception('Invalid or unsupported new phone number for updating destination.');
            
            return array('pre'=>$preNumberInfo, 'new'=>$newNumberInfo);
        }
        
        protected function updateComposerDestination(ComposerDestination $compDest, string $newPhoneNumber) {
            $numInfo = $this->formatPhoneNumber($newPhoneNumber);
            $countryCode = $numInfo[1];
            
            $destData = $compDest->getData();
            $messageId = $compDest->getMessageId();
            $scheduled = $compDest->isScheduled();
            $destMode  = $scheduled ? DestinationMode::DM_UPDATE : $compDest->getWriteMode(); // update if scheduled
            
            // create new composer destination
            $newCompDest = $this->createComposerDestination($newPhoneNumber, $messageId, $destMode, $destData, $scheduled);
            
            // unlink previous one before adding new one
            if ($this->removeComposerDestination($compDest)){
                $this->addComposerDestination($newCompDest, $countryCode);
                return true;
            }
            
            return false;
        }
        
        public function updateDestination(string $prePhoneNumber, string $newPhoneNumber): bool  {
            // perform validation
            $numbersInfoData = $this->validateDestinationUpdate($prePhoneNumber, $newPhoneNumber);
            
            // get formatted phone numbers and country code
            $preFmtdNumber = $numbersInfoData['pre'][0];
            $compDestsStore = $this->getMappedDestinations($preFmtdNumber);
            
            // we will iterate and update composer destinations associated with the phone number
            if (!is_null($compDestsStore) && $compDestsStore->getCount() > 0){
                $compDestsArr = &$compDestsStore->getItems();
                
                foreach ($compDestsArr as $compDest){
                    $this->updateComposerDestination($compDest, $newPhoneNumber);
                }
                
                return true;
            }
            
            return false;
        }
        
        public function updateDestinationById(string $destId, string $newPhoneNumber): bool {
            if (!$this->destinationIdExists($destId))
                throw new \Exception("Message identifier '{0}' does not exist.");
            
            if (!PhoneUtil::isValidPhoneNumber($newPhoneNumber))
                throw new \Exception('Invalid phone number for updating destination.');
            
            // get the previous phone number and use it for the update
            $compDest = $this->getComposerDestinationById($destId);
            
            // update to the new phone number
            return $this->updateComposerDestination($compDest, $newPhoneNumber);
        }

        public function getDestinations(): ComposerDestinationsList {
            // create new composer destinations list for iteration
            $destValues = &$this->_destinations->getItems();
            
            // create an iterable list of the items
            return new ComposerDestinationsList($destValues);
        }
        
        public function getDestinationsCount(): int {
            return $this->_destinations->getCount();
        }
        
        public function setDefaultNumberPrefix($dialCode): void  {
            if (!isset($this->_userData))
                throw new \Exception('Authentication request has not been performed.');
            
            $this->_userData->setDefaultNumberPrefix($dialCode);
        }
        
        public function getDefaultNumberPrefix(): array | null {
            return !isset($this->_userData) ? null : $this->_userData->getDefaultNumberPrefix();
        }
        
        public function getRouteCountries(): array | null {
            if (!isset($this->_userData))
                return null;
            
            return $this->_userData->getRouteCountries();
        }
        
        protected function isRoutesPhoneNumber(string $phonenum): bool {
            if (!isset($this->_userData))
                throw new \Exception('Routes data have not been loaded.');
            
            $numberInfo = $this->formatPhoneNumber($phonenum);
            return !is_null($numberInfo);
        }

        public function getDefaultTimeZone(): array | null {
            if (!isset($this->_userData))
                return null;
            
            return $this->_userData->getDefaultTimeZone();
        } 
    }