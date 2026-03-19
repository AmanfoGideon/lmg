<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Store;
    
    use Zenoph\Notify\Enums\DestinationMode;
    
    class ComposerDestination {
        private int $_destMode;
        private string $_phoneNumber;
        private ?string $_messageId;
        private $_destData = null;
        private bool $_scheduled;
        
        public function __construct() {
            $this->_destMode = DestinationMode::DM_NONE;

            // by default, message isn't scheduled
            $this->_scheduled = false;
        }
        
        public static function &create(array &$data) :ComposerDestination {
            // validate the data
            self::validateCreateData($data);
            
            // create object and set data
            $cd = new ComposerDestination();
            $cd->_destMode = $data['destMode'];
            $cd->_phoneNumber = $data['phoneNumber'];
            $cd->_messageId = $data['messageId'];
            $cd->_destData = $data['destData'];
            $cd->_scheduled = $data['scheduled'];
            
            // return the composer destination
            return $cd;
        }
        
        private static function validateCreateData(array &$data) :void {
            if (!array_key_exists('destMode', $data))
                throw new \Exception('Destination mode specifier not set.');
            
            if (!array_key_exists('phoneNumber', $data))
                throw new \Exception('Phone number not specified for composer destination.');
            
            if (!array_key_exists('messageId', $data))
                throw new \Exception('Message identifier not specified for composer destination.');
            
            if (!array_key_exists('destData', $data))
                throw new \Exception('Custom data not specified for composer destination.');
            
            if (!array_key_exists('scheduled', $data))
                throw new \Exception('Scheduling indicator not specified for composer destination.');
        }
        
        public function reset() :void {
            // reset is only allowed on on scheduled destinations that have been loaded
            if (!$this->_scheduled)
                throw new \Exception('Cannot reset write mode for non-scheduled destinations.');
            
            // set to none
            $this->_destMode = DestinationMode::DM_NONE;
        }
        
        public function getMessageId() :?string {
            return $this->_messageId;
        }
        
        public function getPhoneNumber() :string {
            return $this->_phoneNumber;
        }
        
        public function getWriteMode() :int {
            return $this->_destMode;
        }
        
        public function getData() :mixed {
            return $this->_destData;
        }
        
        public function isScheduled() :bool {
            return $this->_scheduled;
        }
    }