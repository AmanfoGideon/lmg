<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choos e Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Request;
    
    use Zenoph\Notify\Collections\PersonalisedValuesList;
    use Zenoph\Notify\Response\APIResponse;
    use Zenoph\Notify\Response\MessageResponse;
    use Zenoph\Notify\Compose\SMSComposer;
    use Zenoph\Notify\Compose\ISMSComposer;
    use Zenoph\Notify\Store\PersonalisedValues;
    
    class SMSRequest extends MessageRequest implements ISMSComposer {
        protected static $messageTypes = null;
        private static $_baseResource = 'message/sms/send';
    
        public function __construct($authProfile = null) {
            parent::__construct($authProfile);
            
            if (is_null($authProfile))
                $this->_composer = new SMSComposer();
            else
                $this->_composer = new SMSComposer($authProfile);
        }

        protected function validate(): void {
            parent::validate();
            
            // message sender is mandatory for text messages
            $sender = $this->_composer->getSender();
            
            // check message sender
            if (is_null($sender) || empty($sender))
                throw new \Exception("Message sender has not been set.");
        }
        
        public static function getBaseResource(){
            return self::$_baseResource;
        }
        
        public function submit(): APIResponse {
            $this->setRequestResource(self::$_baseResource);
 
            $this->initRequest();
            $dataWriter = $this->createDataWriter();
            $this->_requestData = &$dataWriter->writeSMSRequest($this->_composer);

            // submit for response
            $response = parent::submit();

            // Create and return the message response object
            return MessageResponse::create($response); 
        }

        public static function submitComposer(&$sc, $param1, $param2 = null) {
            if (is_null($sc) || ! $sc instanceof SMSComposer)
                throw new \Exception('Invalid SMS composer object for dispatching message.');
            if (is_null($param1))
                throw new \Exception('Invalid authentication parameter for dispatching message.');
             
            $sr = new SMSRequest();
            $sr->_composer = $sc;
            self::initRequestAuth($sr, $param1, $param2);
            
            return $sr->submit();
        }
        
        public static function getMessageCount($message, $type): int {
            return SMSComposer::getMessageCount($message, $type);
        }
        
        public function getRegisteredSenderIds() {
            $this->assertComposer();
            return $this->_composer->getRegisteredSenderIds();
        }
          
        public function setSMSType($type): void {
            $this->assertComposer();
            $this->_composer->setSMSType($type);
        }

        public function personalise() {
            $this->assertComposer();
            return $this->_composer->personalise();
        }
        
        public function getDefaultSMSType() {
            $this->assertComposer();
            return $this->_composer->getDefaultSMSType();
        }
        
        public function getPersonalisedValues($phoneNumber): PersonalisedValuesList {
            $this->assertComposer();
            return $this->_composer->getPersonalisedValues($phoneNumber); 
        }
        
        public function getPersonalisedValuesById($messageId): PersonalisedValues {
            $this->assertComposer();
            return $this->_composer->getPersonalisedValuesById($messageId);
        }
        
        public function updatePersonalisedValuesById($destId, $values): bool {
            $this->assertComposer();
            return $this->_composer->updatePersonalisedValuesById($destId, $values);
        }
        
        public function updatePersonalisedValues($phoneNumber, $newValues, $prevValues = null): bool {
            $this->assertComposer();
            return $this->_composer->updatePersonalisedValues($phoneNumber, $newValues, $prevValues);
        }
        
        public function updatePersonalisedValuesWithId($phoneNumber, $newValues, $newMessageId): bool {
            $this->assertComposer();
            return $this->_composer->updatePersonalisedValuesWithId($phoneNumber, $newValues, $newMessageId);
        }
        
        public function removePersonalisedValues($phoneNumber, $values) {
            $this->assertComposer();
            return $this->_composer->removePersonalisedValues($phoneNumber, $values);
        }
        
        public function removePersonalisedDestination($phoneNumber, $values) {
            $this->assertComposer();
            return $this->_composer->removePersonalisedDestination($phoneNumber, $values);
        }
        
        public function addPersonalisedDestination($phoneNumber, $throwEx, $values, $messageId = null): int {
            $this->assertComposer();
            return $this->_composer->addPersonalisedDestination($phoneNumber, $throwEx, $values, $messageId);
        }
        
        public function getPersonalisedDestinationMessageId($phoneNumber, $values) {
            $this->assertComposer();
            return $this->_composer->getPersonalisedDestinationMessageId($phoneNumber, $values);
        }
        
        public function getPersonalisedDestinationWriteMode($phoneNumber, $values) {
            $this->assertComposer();
            return $this->_composer->getPersonalisedDestinationWriteMode($phoneNumber, $values);
        }
       
        public function personalisedValuesExists($phoneNumber, $values): bool {
            $this->assertComposer();
            return $this->_composer->personalisedValuesExists($phoneNumber, $values);
        }
        
        public function getSMSType() {
            $this->assertComposer();
            return $this->_composer->getSMSType();
        }

        public static function getMessageVariablesCount($message): int {
            return SMSComposer::getMessageVariablesCount($message);
        }
        
        public static function getMessageVariables($message, $trim = false): array {
            return SMSComposer::getMessageVariables($message, $trim);
        }
        
        public static function getSMSTypes(): array {
            return SMSComposer::getSMSTypes();
        }
    }