<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Request;
    
    use Zenoph\Notify\Response\APIResponse;
    use Zenoph\Notify\Store\AuthProfile;
    use Zenoph\Notify\Utils\MessageUtil;
    use Zenoph\Notify\Enums\MessageCategory;
    use Zenoph\Notify\Enums\ContentType;
    use Zenoph\Notify\Response\MessageResponse;
    use Zenoph\Notify\Compose\MessageComposer;
    use Zenoph\Notify\Compose\SMSComposer;
    use Zenoph\Notify\Build\Reader\MessagePropertiesReader;
    
    class ScheduledMessageRequest extends NotifyRequest {
        private $_fromDateTime = null;
        private $_toDateTime = null;
        private $_category = null;
        private $_utcOffset = null;
        private $_batchId = null;
        private static $_baseResource;
        
        public function __construct($authProfile = null) {
            if (is_null($authProfile))
                throw new \Exception('Authentication profile parameter not set for scheduled messages request.');
            
            // it should be an instance of auth profile and authenticated
            if ($authProfile instanceof AuthProfile == false)
                throw new \Exception('Invalid reference to authentication profile object.');
            
            // it should already be authenticated
            if (!$authProfile->authenticated())
                throw new \Exception('Authentication profile has not been verified.');
            
            parent::__construct($authProfile);
            $this->_category = MessageCategory::SMS;    // by default
        }
        
        public static function init(): void {
            self::$_baseResource = 'message/scheduled';
        }
        
        private function validateDates(): void {
            if ($this->_fromDateTime != null && $this->_toDateTime == null)
                throw new \Exception("'Date From' has not been set for scheduled message request.");
            
            if ($this->_fromDateTime == null && $this->_toDateTime != null)
                throw new \Exception("'Date To' has not been set for scheduled message request.");
        }
        
        private function prepareScheduledLoadParams(): array {
            $data = array('category'=> $this->_category,
                    'dateFrom'=>$this->_fromDateTime,
                    'dateTo'=>$this->_toDateTime,
                    'offset'=>$this->_utcOffset,
                    'batch'=>$this->_batchId
                );
            
            return $data;
        }
        
        private function loadScheduled(){
            // at least category should be specified
            if (is_null($this->_category))
                throw new \Exception('Message category has not been set for loading scheduled messages.');
            
            $this->setRequestResource(self::$_baseResource.'/load'.(is_null($this->_batchId) ? "" : "/{$this->_batchId}"));
            $this->setResponseContentType(ContentType::GZBIN_XML);
            
            // initialise request writing
            $this->initRequest();
            
            // If message batch identifier is specified, then it means we are to
            // load a specific message. In this case, the batch Id will be inserted
            // as part of the URL so there won't be any need to write additional 
            // information about messages to be loaded
            if (is_null($this->_batchId)) {
                $dataWriter = $this->createDataWriter();
                $this->_requestData = &$dataWriter->writeScheduledMessagesLoadRequest($this->prepareScheduledLoadParams());
            }
            
            // submit for response
            $apiResponse = parent::submit();
            
            // create messages
            return $this->createMessages($apiResponse->getDataFragment());
        }
        
        public function loadMessages($category, $dateFrom = null, $dateTo = null, $utcOffset = null){
            if (is_null($category) || !MessageCategory::isDefined($category))
                throw new \Exception('Invalid message category identifier.');
            
            $this->_category = $category;
            
            // not for a single message so $messageId must be set to nothing
            $this->_batchId = null;
            $this->_utcOffset = null;
            
            // We don't expect one dateTime to be set while the other is not
            if ((is_null($dateFrom) && !is_null($dateTo)) || (!is_null($dateFrom) && is_null($dateTo)))
                throw new \Exception('Invalid date time specifiers for loading scheduled messages.');
            
            if (!is_null($dateFrom) || !is_null($dateTo)){
                // Provided parameters must be instances of DateTime
                if (!($dateFrom instanceof \DateTime) || !($dateTo instanceof \DateTime))
                    throw new \Exception('Invalid date time specifiers for loading scheduled messages.');
            }
            
            // If UTC offset provided, validate and set it
            if (!is_null($utcOffset) && !empty($utcOffset)){
                if (!MessageUtil::isValidTimeZoneOffset($utcOffset))
                    throw new \Exception('Invalid UTC offset for loading scheduled messages.');
                
                // set the time zone utc offset
                $this->_utcOffset = $utcOffset;
            }
            
            $this->_fromDateTime = $dateFrom;
            $this->_toDateTime  = $dateTo;
            
            // validate the dates
            $this->validateDates();
            
            // load and return messages.
            return $this->loadScheduled();
        }
        
        public function loadMessage($batchId){
            if (is_null($batchId) || empty($batchId))
                throw new \Exception('Invalid identifier for loading scheduled message.');
            
            // For specified message, category and date definitions are not required
            $this->_fromDateTime = $this->_toDateTime = null;
            
            // Set the mssage id
            $this->_batchId = $batchId;
            
            // Prepare request and submit for response, then extract message details.
            $scheduledList = $this->loadScheduled();
            
            if (is_null($scheduledList) || $scheduledList->getCount() == 0)
                return null;

            // There is only one item
            return $scheduledList->getItem(0);
        }
     
        private function createMessages(&$dataFragment){ 
            $messagesArr = null;
            
            // If the data fragment is empty, then there are no scheduled messages
            if (is_null($dataFragment) || empty($dataFragment))
                return $messagesArr;
            
            $msgPropsReader = new MessagePropertiesReader();
            $msgPropsReader->setDataFragment($dataFragment);
            $msgPropsReader->isScheduled(true);
            
            if (!is_null($this->_authProfile))
                $msgPropsReader->setAuthProfile($this->_authProfile);
            
            // get and return the messages collection
            return $msgPropsReader->getMessages();
        }
        
        public function loadDestinations($mc){
            if (is_null($mc) || !($mc instanceof MessageComposer))
                throw new \Exception('Invalid reference to Message object for loading destinations.');
            
            if ($mc instanceof SMSComposer){
                $messageText = $mc->getMessage();
                
                if (is_null($messageText) || empty($messageText))
                    throw new \Exception('Message properties have not been loaded for loading destinations.');
            }
            
            // Set request parameters
            $this->setRequestResource(self::$_baseResource."/destinations/load/{$mc->getBatchId()}");
            $this->setResponseContentType(ContentType::GZBIN_XML);
            
            // initialise request writing
            $this->initRequest();
            
            // submit for response
            $apiResponse = $this->submit();
            return MessageComposer::populateScheduledDestinations($mc, $apiResponse->getDataFragment());
        }
        
        public function cancelSchedule(MessageComposer $composer){
            // message should be scheduled
            if (!$composer->isScheduled())
                throw new \Exception('The message has not been scheduled for cancelling.');
            
            $this->setRequestResource(self::$_baseResource."/cancel/{$composer->getBatchId()}");
            $this->initRequest();
            
            // submit response
            return $this->submit();
        }
        
        public function dispatchMessage(MessageComposer $composer): APIResponse {
            // message should be a scheduled one
            if (!$composer->isScheduled())
                throw new \Exception('The message has not been scheduled for dispatch.');
            
            // begin request
            $this->setRequestResource(self::$_baseResource."/dispatch/{$composer->getBatchId()}");
            $this->initRequest();

            // submit and return response
            return $this->submit();
        }
        
        public function updateMessage(MessageComposer $composer){
            $this->setRequestResource(self::$_baseResource."/update/".$composer->getBatchId());
            $this->setResponseContentType(ContentType::GZBIN_XML);
            
            // init request writing
            $this->initRequest();
            $dataWriter = $this->createDataWriter();
            $this->_requestData = &$dataWriter->writeScheduledMessageUpdateRequest($composer);
            
            // submit for response
            $apiResponse = $this->submit();
            $msgResponse = MessageResponse::create($apiResponse);
            
            // there will be only one message item
            $msgReport = $msgResponse->getReport();
            $msgDestsList = $msgReport->getDestinations();
            
            // if new destinations were added, write mode should be refreshed
            if (!is_null($msgDestsList) && $msgDestsList->getCount() > 0)
                $composer->refreshScheduledDestinationsUpdate($msgDestsList);
            
            // return message response
            return $msgResponse;
        }
    }
    
    ScheduledMessageRequest::init();