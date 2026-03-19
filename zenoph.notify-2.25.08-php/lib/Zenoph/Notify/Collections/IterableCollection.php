<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Collections;
    
    abstract class IterableCollection implements \Iterator {
        private array $_dataArr;
        private $_index = 0;
        
        public function __construct(?array &$dataArr = null){
            // First, set the data array to empty
            $this->_dataArr = [];

            // If the argument isn't null, then set it to the data array member
            if (!is_null($dataArr) && is_array($dataArr))
                $this->_dataArr = &$dataArr;
            
            $this->_index = 0;
        }
        
        public function next(): void {
            $this->_index++;
        }
        
        public function current(): mixed {
            return $this->_dataArr[$this->_index];
        }
        
        public function key(): int {
            return $this->_index;
        }
        
        public function rewind(): void {
            $this->_index = 0;
        }
        
        public function valid(): bool{
            return isset($this->_dataArr[$this->_index]);
        }
    }