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
    
    class MessageDeliveryRequest extends NotifyRequest {
        private string $_batchId;
        
        public function __construct(?AuthProfile $authProfile = null) {
            parent::__construct($authProfile);
        }
        
        public function setBatchId(string $batchId): void {
            $this->_batchId = $batchId;
        }
        
        public function submit(): MessageResponse {
            if (!isset($this->_batchId))
                throw new \Exception('Message identifier has not been set for delivery status request.');
            
            $this->setRequestResource("report/message/delivery/{$this->_batchId}");
            $this->setResponseContentType(ContentType::GZBIN_XML);
            
            // initiate for request writing
            $this->initRequest();
            
            // submit for response
            $apiResponse = parent::submit();
            
            // create and return message response
            return MessageResponse::create($apiResponse);
        }
    }