<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Collections;

    abstract class DataList implements \Iterator {
        private array $_dataArray;
        private int $_key = 0;
        
        public function __construct(array &$dataArray) {
            $this->_dataArray = &$dataArray;
            $this->_key = 0;
        }
        
        public function next(): void {
            $this->_key++;
        }
        
        public function current(): mixed {
            return $this->_dataArray[$this->_key];
        }
        
        public function key(): mixed{
            return $this->_key;
        }
        
        public function rewind(): void {
            $this->_key = 0;
        }
        
        public function valid(): bool {
            return isset($this->_dataArray[$this->_key]);
        }
    }