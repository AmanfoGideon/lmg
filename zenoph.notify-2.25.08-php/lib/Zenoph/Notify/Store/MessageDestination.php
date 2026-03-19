<?php
/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Store;
    
    use Zenoph\Notify\Utils\MessageUtil;
    use Zenoph\Notify\Enums\DestinationStatus;
    use Zenoph\Notify\Enums\DestinationValidation;
    
    class MessageDestination {
        private string $_phoneNumber;
        private string $_country;
        private string $_messageId;
        private string $_message;
        private $_status;
        private int $_statusId;
        private \DateTime $_submitDateTime;
        private \DateTime $_reportDateTime;
        private int $_messageCount;
        private mixed $_dataObject;
        
        public function __construct() {
            $this->_status = DestinationStatus::DS_UNKNOWN;

            $this->_messageCount = 0;
        }
        
        public static function create(array &$data) :MessageDestination {
            $msgDest = new MessageDestination();
            
            if (isset($data['phoneNumber']) && !empty($data['phoneNumber']))
                $msgDest->_phoneNumber = $data['phoneNumber'];
            
            if (isset($data['country']))
                $msgDest->_country = $data['country'];
            
            if (isset($data['messageId']) && !empty($data['messageId']))
                $msgDest->_messageId = $data['messageId'];
            
            if (isset($data['message']) && !empty($data['message']))
                $msgDest->_message = $data['message'];
            
            if (isset($data['statusId']) && is_numeric($data['statusId'])) {
                $msgDest->_statusId = $data['statusId'];
                self::setDestinationStatus($msgDest);
            }
            
            if (isset($data['messageCount']) && is_numeric($data['messageCount']))
                $msgDest->_messageCount = $data['messageCount'];
            
            if (isset($data['psndValues']))
                $msgDest->_dataObject = $data['psndValues'];
            
            // The dateTime properties.
            if (isset($data['submitDateTime']) && !is_null($data['submitDateTime'])){
                $msgDest->_submitDateTime = \DateTime::createFromFormat(MessageUtil::DATETIME_FORMAT, 
                    $data['submitDateTime'], new \DateTimeZone(date_default_timezone_get()));
            }
            
            // delivery report dateTime, if available
            if (isset($data['reportDateTime']) && !is_null($data['reportDateTime'])){
                $dateTime = \DateTime::createFromFormat(MessageUtil::DATETIME_FORMAT, $data['reportDateTime']); // new \DateTimeZone(date_default_timezone_get()));
                $msgDest->_reportDateTime = $dateTime;
            }
            
            // return it.
            return $msgDest;
        }
        
        private static function setDestinationStatus(MessageDestination $destInfo) :void {
            if (!DestinationStatus::isDefined($destInfo->_statusId)){
                $destInfo->_status = DestinationStatus::DS_UNKNOWN;
            }
            else {
                $destInfo->_status = $destInfo->_statusId;
            }
        }
        
        public function getMessageCount() :int {
            return $this->_messageCount;
        }
        
        public function getPhoneNumber() :?string {
            return $this->_phoneNumber;
        }
        
        public function getCountry(){
            return $this->_country;
        }
        
        public function getMessageId() :?string {
            return $this->_messageId;
        }
        
        public function getMessage() :?string {
            return $this->_message;
        }
        
        public function getStatus() :int {
            return $this->_status;
        }
        
        public function getStatusId() :?int {
            return $this->_statusId;
        }
        
        public function getSubmitDateTime() :?\DateTime {
            return $this->_submitDateTime;
        }
        
        public function getReportDateTime() :?\DateTime {
            return $this->_reportDateTime;
        }
        
        public function getData(){
            return $this->_dataObject;
        }
    }