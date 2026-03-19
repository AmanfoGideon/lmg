<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Collections;
    
    class ComposerDestinationsList implements \Iterator {
        private $_items = null;
        private $_pointer = 0;
        public function __construct(&$destItems) {
            if (is_null($destItems) || !is_array($destItems))
                throw new \Exception('Invalid object reference for composer destinations list.');
            
            $this->_items = &$destItems;
        }
        
        public function current(): mixed {
            return $this->_items[$this->_pointer];
        }
        
        public function next() :void {
            $this->_pointer++;
        }
        
        public function key() :mixed {
            return $this->_pointer;
        }
        
        public function rewind() :void {
            $this->_pointer = 0;
        }
        
        public function valid() :bool{
            return isset($this->_items[$this->_pointer]);
        }
        
        public function getCount(): int {
            return count($this->_items);
        }
    }