<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Collections;
    
    class ObjectStorage implements \Iterator {
        private array $_store;
        
        public function __construct() {
            $this->_store = [];
        }
        
        public function attach(mixed $object): void {
            // get a unique identifier for the object
            $hashKey = $this->computeObjectHash($object);
            
            // check to see if this hash exists or not
            if (array_key_exists($hashKey, $this->_store))
                throw new \Exception("Object already exists in the objects store.");
            
            // include in the collection
            $this->_store[$hashKey] = $object;
        }
        
        public function contains(mixed $object): bool {
            // compute the hash and check to see if it exists or not
            $hashKey = $this->computeObjectHash($object);
            
            // check and return existence
            return array_key_exists($hashKey, $this->_store);
        }
        
        public function detach(mixed $object): bool {
            // it should exist
            $hashKey = $this->computeObjectHash($object);
            
            // if it exists remove it
            if (array_key_exists($hashKey, $this->_store)) {
                unset($this->_store[$hashKey]);
                return true;
            }
            
            return false;
        }
        
        public function getCount(): int {
            return count($this->_store);
        }
        
        public function &getItems(): array {
            $values = array_values($this->_store);
            return $values;
        }
        
        public function clear(): void {
            if (count($this->_store) > 0){
                unset($this->_store);
                $this->_store = array();
            }
        }
        
        private function computeObjectHash($object): string {
            return spl_object_hash($object);
        }
        
        public function current(): mixed {
            return current($this->_store);
        }
        
        public function next(): void {
            next($this->_store);
        }
        
        public function key(): mixed {
            return key($this->_store);
        }
        
        public function rewind() :void {
            reset($this->_store);
        }
        
        public function valid(): bool {
            return isset($this->_store[key($this->_store)]);
        }
    }