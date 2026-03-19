<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Collections;
    
    use Zenoph\Notify\Store\PersonalisedValues;
    
    class PersonalisedValuesList implements \Iterator{
        private array $_valuesArr;
        private int $_pointer;
        
        public function __construct() {
            $this->_valuesArr = [];
            $this->_pointer = 0;
        }
        
        public function add(PersonalisedValues $item): void {
            $this->_valuesArr[] = $item;
        }
        
        public function getCount(): int {
            return count($this->_valuesArr);
        }
        
        public function get(int $idx): PersonalisedValues {
            if ($idx < 0 || $idx > count($this->_valuesArr))
                throw new \Exception('Index is out of range for getting personalised values item.');
            
            return $this->_valuesArr[$idx];
        }
        
        public function current(): mixed {
            return $this->_valuesArr[$this->_pointer];
        }
        
        public function next(): void {
            $this->_pointer++;
        }
        
        public function key(): int {
            return $this->_pointer;
        }
        
        public function rewind(): void {
            $this->_pointer = 0;
        }
        
        public function valid(): bool {
            return isset($this->_valuesArr[$this->_pointer]);
        }
    }