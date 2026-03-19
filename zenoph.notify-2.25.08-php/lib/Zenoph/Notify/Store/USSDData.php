<?php
    namespace Zenoph\Notify\Store;
    
    class USSDData {
        private $_isStart = true;
        private $_session = null;
        private $_phoneNumber = null;
        private $_userInput = null;
        private $_userOptions = null;
        
        private function __construct() {
            $this->_userOptions = array();
        }
        
        public static function create(&$data){
            
        }
        
        public function getSession(){
            return $this->_session;
        }
        
        public function getPhoneNumber(){
            return $this->_phoneNumber;
        }
        
        public function getUserInput(){
            return $this->_userInput;
        }
        
        public function isStart(){
            return $this->_isStart;
        }
    }

