<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Build\Writer;
    
    use Zenoph\Notify\Enums\MessageCategory;
    use Zenoph\Notify\Enums\DestinationMode;
    use Zenoph\Notify\Utils\MessageUtil;
    use Zenoph\Notify\Utils\RequestUtil;
    use Zenoph\Notify\Store\PersonalisedValues;
    use Zenoph\Notify\Build\Writer\DataWriter;
    use Zenoph\Notify\Compose\MessageComposer;
    use Zenoph\Notify\Compose\VoiceComposer;
    use Zenoph\Notify\Compose\SMSComposer;
    use Zenoph\Notify\Compose\Composer;
    
    abstract class KeyValueDataWriter extends DataWriter {
        const PSND_VALUES_UNIT_SEP = "__@";
        const PSND_VALUES_GRP_SEP = "__#";
        const DESTINATIONS_SEPARATOR = ",";
        
        protected array $_keyValueArr;

        public function __construct() {
            $this->_keyValueArr = [];
        }

        public function &writeSMSRequest(SMSComposer $composer) {
            $store = &$this->_keyValueArr;
            
            // write message properties
            $this->writeSMSProperties($composer, $store);
            $this->writeCommonMessageProperties($composer, $store);
            
            // write destinations
            $this->writeDestinations($composer, $store);
            
            // return request data
            return $this->prepareRequestData();
        }
        
        private function writeSMSProperties(SMSComposer $composer, array &$store): void {
            $messageText = $composer->getMessage();
            $messageType = $composer->getSMSType();
            
            $this->appendKeyValueData($store, "text", $messageText);
            $this->appendKeyValueData($store, "type", $messageType);
            $this->appendKeyValueData($store, "sender", $composer->getSender());
            
            // message personalisation flag
            if (SMSComposer::getMessageVariablesCount($messageText) > 0){
                if (!$composer->personalise())
                    $this->appendKeyValueData ($store, "personalise", "false");
            }
        }

        private function writeVoiceMessageProperties(VoiceComposer $composer, array &$store): void {
            $sender = $composer->getSender();
            $template = $composer->getTemplateReference();
            
            if (!is_null($sender) && !empty($sender))
                $this->appendKeyValueData($store, "sender", $sender);
            
            if (!is_null($template) && !empty($template))
                $this->appendKeyValueData($store, "template", $template);
        }
        
        protected function writeVoiceMessageData(VoiceComposer $composer, array &$store){
            // message properties
            $this->writeVoiceMessageProperties($composer, $store);
            $this->writeCommonMessageProperties($composer, $store);
            
            // message destinations
            $this->writeDestinations($composer, $store);
        }
        
        protected function writeCommonMessageProperties(MessageComposer $composer, &$store) {
            // if message is to be scheduled
            if ($composer->schedule()){
                $scheduleInfo = $composer->getScheduleInfo();
                $this->writeScheduleInfo($scheduleInfo[0], $scheduleInfo[1], $store);
            }
            
            // if delivery notifications are requested
            if ($composer->notifyDeliveries()){
                $notifyInfo = $composer->getDeliveryCallback();
                $this->writeCallbackInfo($notifyInfo[0], $notifyInfo[1], $store);
            }
        }
        
        protected function writeScheduleInfo(\DateTime $dateTime, string $utcOffset, &$store): void {
            // validate
            $this->validateScheduleInfo($dateTime, $utcOffset);
            
            // append data
            $this->appendKeyValueData($store, "schedule", MessageUtil::dateTimeToStr($dateTime));
            
            // if utc offset is provided we write it
            if (!is_null($utcOffset) && !empty($utcOffset))
                $this->appendKeyValueData ($store, "offset", $utcOffset);
        }
        
        protected function writeCallbackInfo(string $url, int $contentType, &$store): void {
            // validate
            $this->validateDeliveryNotificationInfo($url, $contentType);
            
            // append data
            $this->appendKeyValueData($store, "callback_url", $url);
            $this->appendKeyValueData($store, "callback_accept", RequestUtil::getDataContentTypeLabel($contentType));
        }
        
        protected function writeDestinations(Composer $composer, &$store): void {
            // get destinations
            $compDestsList = $composer->getDestinations();
            
            if ($compDestsList->getCount() == 0)
                throw new \Exception('There are no items to write message destinations.');
            
            $destsStr = "";
            $valuesStr = "";
            
            foreach ($compDestsList as $compDest){
                if ($compDest->getWriteMode() == DestinationMode::DM_NONE)
                    continue;
                
                $phoneNumber = $compDest->getPhoneNumber();
                
                // validate destination sender Id
                if ($composer instanceof MessageComposer)
                    $composer->validateDestinationSenderName($phoneNumber);
                
                // other data
                $messageId = $compDest->getMessageId();
                $destData  = $compDest->getData();
                $tempDestsStr = $phoneNumber;
                
                if (!is_null($messageId) && !empty($messageId))
                    $tempDestsStr = "{$messageId}@{$phoneNumber}";
                    
                if (!is_null($destData) && $destData instanceof PersonalisedValues){
                    $valStr = $this->getPersonalisedValuesStr($destData);
                    
                    // append to the personalised values string
                    $valuesStr .= (empty($valuesStr) ? "" : self::PSND_VALUES_GRP_SEP).$valStr;
                }
                
                // update destinations str
                $destsStr .= (empty($destsStr) ? "" : self::DESTINATIONS_SEPARATOR).$tempDestsStr;
            }
            
            // append destinations
            $this->appendKeyValueData($store, "to", $destsStr);
            
            // if there are personalised values, append them too
            if (!empty($valuesStr))
                $this->appendKeyValueData ($store, "values", $valuesStr);
        }
        
        private function getPersonalisedValuesStr(PersonalisedValues $pv): string {
            $valStr = "";
            
            foreach ($pv->export() as $value)
                $valStr .= (empty($valStr) ? "" : self::PSND_VALUES_UNIT_SEP).$value;
            
            // return values
            return $valStr;
        }
        
        public function &writeDestinationsDeliveryRequest(array $messageIdsArr): array {
            // message Ids
            if (count($messageIdsArr) == 0)
                throw new \Exception('Invalid reference to list for writing destinations delivery request.');
            
            $store = &$this->_keyValueArr;
            $idsStr = "";

            foreach ($messageIdsArr as $messageId)
                $idsStr .= (empty($idsStr) ? "" : self::DESTINATIONS_SEPARATOR).$messageId;
            
            // append message Ids
            $this->appendKeyValueData($store, "to", $idsStr);
            
            // return request body string
            return $this->prepareRequestData();
        }
        
        public function &writeDestinationsData(Composer $composer): array {
            $this->writeDestinations($composer, $this->_keyValueArr);
            
            // return it
            return $this->prepareRequestData();
        }
        
        public function &writeScheduledMessagesLoadRequest(array $filter): array {
            // perform validation
            $this->validateScheduledMessagesLoadData($filter);
            
            $store = &$this->_keyValueArr;

            // message category to load
            if (!is_null($filter['category'])){
                $this->appendKeyValueData($store, "category", $filter['category']);
            }
            
            // date specifications
            if (!is_null($filter['dateFrom']) && !is_null($filter['dateTo'])){
                $dateFromStr = MessageUtil::dateTimeToStr($filter['dateFrom']);
                $dateToStr   = MessageUtil::dateTimeToStr($filter['dateTo']);
                
                $this->appendKeyValueData($store, "from", $dateFromStr);
                $this->appendKeyValueData($store, "to", $dateToStr);
                
                // if there is UTC offset append it
                if (!is_null($filter['offset']) || !empty($filter['offset']))
                    $this->appendKeyValueData($store, "offset", $filter['offset']);
            }
            
            // reutrn request body string
            return $this->prepareRequestData();
        }
        
        public function &writeScheduledMessageUpdateRequest(Composer $composer) {
            $store = &$this->_keyValueArr;
            $category = $composer->getCategory();
            
            // append template Id
            // $this->writeMessageBatchId($mc->getBatchId(), $store);
            
            // properties to be written will depend on the message category
            if ($category == MessageCategory::SMS || $category == MessageCategory::USSD && $composer instanceof SMSComposer)
                $this->writeSMSProperties($composer, $store);
            else if ($composer instanceof VoiceComposer)
                $this->writeVoiceMessageProperties($composer, $store);
            
            // write and append message destinations if any
            if ($composer->getDestinationsCount() > 0)
                $this->writeScheduledMessageDestinations($composer, $store);
            
            // return request string
            return $this->prepareRequestData();
        }
        
        private function writeScheduledMessageDestinations(MessageComposer $composer, &$store): void {
            $compDestsList = $composer->getDestinations();
            
            if (is_null($compDestsList) || $compDestsList->getCount() == 0)
                return;
            
            $addDestStr = "";
            $addValuesStr = "";
            $updateDestStr = "";
            $updateValuesStr = "";
            $deleteDestStr = "";
            
            foreach ($compDestsList as $compDest){
                $destMode = $compDest->getWriteMode();
                
                // interested in destinations that have been added, updated, or to be deleted
                if ($destMode == DestinationMode::DM_NONE)
                    continue;
                
                $phoneNumber = $compDest->getPhoneNumber();
                $composer->validateDestinationSenderName($phoneNumber);
                
                // other data
                $destData = $compDest->getData();
                $messageId = $compDest->getMessageId();
                
                switch ($destMode){
                    case DestinationMode::DM_ADD:
                        $tempStr = $phoneNumber.(!is_null($messageId) && !empty($messageId) ? "@{$messageId}" : "");
                        $addDestStr .= (empty($addDestStr) ? "" : self::DESTINATIONS_SEPARATOR).$tempStr;
                        
                        // check for personalised values
                        if (!is_null($destData) && $destData instanceof PersonalisedValues){
                            $valStr = $this->getPersonalisedValuesStr($destData);
                            
                            // append
                            $addValuesStr .= (empty($addValuesStr) ? "" : self::PSND_VALUES_GRP_SEP).$valStr;
                        }
                        break;
                    
                    case DestinationMode::DM_UPDATE:
                        // the update can be phone number or in the case of text messages, the personalised values.
                        // So here the main key will be the message id
                        $updateDestStr .= (empty($updateDestStr) ? "" : self::DESTINATIONS_SEPARATOR)."{$messageId}@{$phoneNumber}";
                        
                        // check for personalised values
                        if (!is_null($destData) && $destData instanceof PersonalisedValues){
                            $valStr = $this->getPersonalisedValuesStr($destData);
                            
                            // append
                            $updateValuesStr .= (empty($updateValuesStr) ? "" : self::PSND_VALUES_GRP_SEP).$valStr;
                        }
                        break;
                    
                    case DestinationMode::DM_DELETE:
                        if (!is_null($messageId) && !empty($messageId))
                            $deleteDestStr .= (empty($deleteDestStr) ? "" : self::DESTINATIONS_SEPARATOR).$messageId;
                        break;
                }
            }
            
            // update those with data
            if (!empty($addDestStr)) {
                $this->appendKeyValueData($store, "to-add", $addDestStr);
                
                if (!empty($addValuesStr))
                    $this->appendKeyValueData($store, "values-add", $addValuesStr);
            }
            
            if (!empty($updateDestStr)){
                $this->appendKeyValueData($store, "to-update", $updateDestStr);
                
                if (!empty($updateValuesStr))
                    $this->appendKeyValueData ($store, "values-update", $updateValuesStr);
            }
            
            if (!empty($deleteDestStr))
                $this->appendKeyValueData($store, "to-delete", $deleteDestStr);
        }
        
        public function &writeUSSDRequest($ucArr): void {
            
        }
        
        private function writeUSSDData($tmc, &$store){
            // in future implementation
        }
 
        protected function &prepareRequestData(): array {
            $requestDataArr = array('keyValues'=> $this->_keyValueArr);
            
            // return it
            return $requestDataArr;
        }
        
        protected function appendKeyValueData(&$store, $key, $value): void {
            $store[$key] = $value;
        }
    }