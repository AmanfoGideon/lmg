<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Report;

    use Zenoph\Notify\Collections\MessageDestinationsList;
    
    abstract class MessageReport {
        private $_batchId = null;
        private MessageDestinationsList $_destinations;
        private $_destsCount = 0;
        private $_category = null;
        private $_deliveryReport = false;
        
        protected function __construct() {
            $this->_destinations = new MessageDestinationsList();
        }
 
        protected function setCommonProperties(array &$p){
            if (array_key_exists('batch', $p))
                $this->_batchId = $p['batch'];
            
            if (array_key_exists('category', $p))
                $this->_category = $p['category'];

            if (array_key_exists('delivery', $p))
                $this->_deliveryReport = $p['delivery'];
            
            // When message validation fails, there won't be any destinations.
            if (array_key_exists('destinations', $p))
                $this->_destinations = $p['destinations'];
            
            if (array_key_exists('destsCount', $p)) {
                $this->_destsCount = $p['destsCount'];
            }
            else {
                $this->_destsCount = $this->_destinations->getCount();
            }
        }
        
        public function getDestiniationsCount(): int {
            return $this->_destinations->getCount();
        }
        
        public function getDestinations(): MessageDestinationsList {
            return $this->_destinations;
        }
        
        public function getBatchId(){
            return $this->_batchId;
        }

        public function getCategory(){
            return $this->_category;
        }
        
        public function isDeliveryReport(){
            return $this->_deliveryReport;
        }
    }