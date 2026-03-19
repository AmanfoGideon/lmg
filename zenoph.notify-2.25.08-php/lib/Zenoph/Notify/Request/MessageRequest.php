<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Request;
    
    use Zenoph\Notify\Compose\ISchedule;
    use Zenoph\Notify\Compose\IMessageComposer;
    use Zenoph\Notify\Collections\MessageDestinationsList;
    
    abstract class MessageRequest extends ComposeRequest implements IMessageComposer, ISchedule {
        public function __construct($ap = null) {
            parent::__construct($ap);
        }
        
        public function getBatchId(): string {
            $this->assertComposer();
            return $this->_composer->getBatchId();
        }
        
        public function setMessage(string $message, mixed $info = null): void {
            $this->assertComposer();
            $this->_composer->setMessage($message, $info);
        }
        
        public function getMessage(): string | null {
            $this->assertComposer();
            return $this->_composer->getMessage();
        }
        
        public function setSender(string $sender): void {
            $this->assertComposer();
            $this->_composer->setSender($sender);
        }
        
        public function getSender(): string | null {
            $this->assertComposer();
            return $this->_composer->getSender();
        }
        
        public function schedule() {
            $this->assertComposer();
            return $this->_composer->schedule();
        }
        
        public function isScheduled(): bool {
            $this->assertComposer();
            return $this->_composer->isScheduled();
        }
        
        public function getMessageId(string $phoneNumber): string {
            $this->assertComposer();
            return $this->_composer->getMessageId($phoneNumber);
        }
        
        public function messageIdExists(string $messageId): bool {
            $this->assertComposer();
            return $this->_composer->messageIdExists($messageId);
        }
        
        public function getScheduleInfo(): array {
            $this->assertComposer();
            return $this->_composer->getScheduleInfo();
        }
        
        public function setDeliveryCallback(string | null $url, int $contentType): void {
            $this->assertComposer();
            $this->_composer->setDeliveryCallback($url, $contentType);
        }
        
        public function getDeliveryCallback(): array {
            $this->assertComposer();
            return $this->_composer->getDeliveryCallback();
        }
        
        public function notifyDeliveries(): bool {
            $this->assertComposer();
            return $this->_composer->notifyDeliveries();
        }
        
        public function setScheduleDateTime($dateTime, $val1 = null, $val2 = null): void {
            $this->assertComposer();
            $this->_composer->setScheduleDateTime($dateTime, $val1, $val2);
        }
        
        public function validateDestinationSenderName(string $phoneNumber) : void {
            $this->assertComposer();
            $this->_composer->validateDestinationSenderName($phoneNumber);
        }
        
        public function refreshScheduledDestinationsUpdate(MessageDestinationsList $destsList): void  {
            $this->assertComposer();
            $this->_composer->refreshScheduledDestinationsUpdate($destsList);
        }
        
        public function removeDestinationById(string $messageId): bool {
            $this->assertComposer();
            return $this->_composer->removeDestinationById($messageId);
        }
        
        public function updateDestinationById(string $messageId, string $phoneNumber): bool {
            $this->assertComposer();
            return $this->_composer->updateDestinationById($messageId, $phoneNumber);
        }
    }