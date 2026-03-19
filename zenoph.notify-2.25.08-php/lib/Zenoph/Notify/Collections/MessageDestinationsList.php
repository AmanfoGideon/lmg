<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Collections;
    
    use Zenoph\Notify\Store\MessageDestination;
    use Zenoph\Notify\Collections\ObjectStorage;
    
    class MessageDestinationsList implements \Iterator {
        private ObjectStorage $_store;
        
        public function __construct() {
            $this->_store = new ObjectStorage();
        }
        
        public function add(MessageDestination $item): void{
            // add to list
            $this->_store->attach($item);
        }
        
        public function &getItems(): array {
            return $this->_store->getItems();
        }
        
        public function getCount(): int {
            return $this->_store->getCount();
        }
        
        public function current(): mixed {
            return $this->_store->current();
        }
        
        public function next(): void {
            $this->_store->next();
        }
        
        public function key(): mixed {
            return $this->_store->key();
        }
        
        public function rewind(): void {
            $this->_store->rewind();
        }
        
        public function valid(): bool {
            return $this->_store->valid();
        }
    }