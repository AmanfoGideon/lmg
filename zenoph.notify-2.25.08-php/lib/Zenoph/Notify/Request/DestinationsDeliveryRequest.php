<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Request;
    
    use Zenoph\Notify\Enums\ContentType;
    use Zenoph\Notify\Store\AuthProfile;
    use Zenoph\Notify\Response\MessageResponse;
    
    class DestinationsDeliveryRequest extends NotifyRequest {
        private array $_messageIds;
        private string $_batchId;
        
        public function __construct(?AuthProfile $authProfile = null) {
            parent::__construct($authProfile);
            
            $this->_messageIds = [];
        }
        
        public function addMessageId(string $messageId): void {
            $this->_messageIds[] = $messageId;
        }
        
        public function setBatchId(string $batchId): void {
            $this->_batchId = $batchId;
        }
        
        private function validate(): void {
            if (!isset($this->_batchId))
                throw new \Exception('Message template identifier has not been set for writing request.');
            
            if (count($this->_messageIds) == 0)
                throw new \Exception('There are no message identifiers for writing request.');
        }
        
        public function submit(): MessageResponse  {
            // perform validation
            $this->validate();
            
            $this->setRequestResource("report/message/delivery/destinations/{$this->_batchId}");
            $this->setResponseContentType(count($this->_messageIds) > 5000 ? ContentType::GZBIN_XML : ContentType::XML);
            
            // initialise for writing request
            $this->initRequest();

            // intialise data writer
            $dataWriter = $this->createDataWriter();
            $this->_requestData = &$dataWriter->writeDestinationsDeliveryRequest($this->_messageIds);
            
            // submit for response
            $apiResponse = parent::submit();
            
            // create and return message response
            return MessageResponse::create($apiResponse);
        }
    }