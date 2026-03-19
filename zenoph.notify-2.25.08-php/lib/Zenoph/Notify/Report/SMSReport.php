<?php

    namespace Zenoph\Notify\Report;
    
    use Zenoph\Notify\Report\MessageReport;
    
    class SMSReport extends MessageReport {
        private string $_text = '';
        private $_type;
        private string $_sender = '';
        private bool $_personalised = false;
        
        public function __construct() {
            parent::__construct();
        }
        
        public static function create(array &$data): SMSReport {
            // create SMS report object
            $report = new SMSReport();
            
            // set common base properties
            $report->setCommonProperties($data);
            
            // set other data
            if (array_key_exists('text', $data))
                $report->_text = $data['text'];
            
            if (array_key_exists('type', $data))
                $report->_type = $data['type'];
            
            if (array_key_exists('sender', $data))
                $report->_sender = $data['sender'];
            
            if (array_key_exists('personalised', $data))
                $report->_personalised = $data['personalised'];

            // return SMS report
            return $report;
        }
        
        public function getMessage() {
            return $this->_text;
        }
        
        public function getSender() {
            return $this->_sender;
        }
        
        public function getSMSType() {
            return $this->_type;
        }
        
        public function isPersonalised() {
            return $this->_personalised;
        }
    }

