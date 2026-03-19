<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Collections;
    
    use Zenoph\Notify\Compose\MessageComposer;
    
    class MessageComposerList implements \Iterator {
        private array $_messagesList;
        private int $_pointer = 0;
        
        public function __construct() {
            $this->_messagesList = [];
        }
        
        public function addItem(MessageComposer $item): void {
            // add to collection
            $this->_messagesList[] = $item;
        }
        
        public function getItem(int $idx): MessageComposer {
            if ($idx < 0 || $idx > count($this->_messagesList))
                throw new \Exception('Index is out of range for message composer item.');
            
            return $this->_messagesList[$idx];
        }
        
        public function getCount(): int {
            return count($this->_messagesList);
        }
        
        public function current(): mixed {
            return $this->_messagesList[$this->_pointer];
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
            return count($this->_messagesList) > 0 && 
                isset($this->_messagesList[$this->_pointer]);
        }
    }