<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Build\Reader;
    
    use SimpleXMLElement;
    use Zenoph\Notify\Store\AuthProfile;
    use Zenoph\Notify\Utils\MessageUtil;
    use Zenoph\Notify\Enums\MessageCategory;
    use Zenoph\Notify\Compose\SMSComposer;
    use Zenoph\Notify\Compose\VoiceComposer;
    use Zenoph\Notify\Compose\MessageComposer;
    use Zenoph\Notify\Collections\MessageComposerList;
    
    class MessagePropertiesReader {
        private AuthProfile $_authProfile;
        private $_fragment;
        private \XMLReader $_xmlReader;
        private bool $_isScheduled;
        private bool $_done;
        
        public function __construct() {
            $this->_done = false;
            $this->_fragment = null;
            $this->_isScheduled = true; // by default assume reading loaded scheduled message
        }
        
        public function setAuthProfile(AuthProfile $ap): void {
            $this->_authProfile = $ap;
        }
        
        public function setDataFragment(string &$xmlFragment): void {
            $this->_xmlReader = new \XMLReader();
            $this->_xmlReader->XML($xmlFragment);
        }
        
        public function isScheduled(bool $scheduled): void {
            $this->_isScheduled = $scheduled;
        }
        
        private function readMessage(\XMLReader $xmlReader){
            $composer = null;
            
            // cursor should be on a message node before reading
            if ($xmlReader->nodeType == \XMLReader::ELEMENT && strtolower($xmlReader->name) === 'message'){
                $messageProps = new SimpleXMLElement($xmlReader->readOuterXml());
                
                $batchId = (string)$messageProps->batch;
                $category = MessageUtil::messageCategoryToEnum((string)$messageProps->category);
                
                // parameters for initialising message container
                $params['batch'] = $batchId;
                $params['scheduled'] = $this->_isScheduled;
                $params['category']  = $category;
                
                if (isset($this->_authProfile))
                    $params['authProfile'] = $this->_authProfile;

                if ($category == MessageCategory::SMS){
                    $params['category'] = $category; 

                    $composer = SMSComposer::create($params);
                    $this->setTextMessageProperties($composer, $messageProps);
                }
                else if ($category == MessageCategory::VOICE){
                    $composer = VoiceComposer::create($params);
                    $this->setVoiceMessageProperties($composer, $messageProps);
                }

                // common message properties
                $this->setCommonMessageProperties($composer, $messageProps);
            }
            
            return $composer;
        }
        
        private function setTextMessageProperties(SMSComposer $composer, SimpleXMLElement $xmlObj){
            $messageType = (int)$xmlObj->type;
            $messageText = (string)$xmlObj->text;
            $isPersonalised = false;

            // It could be a personalised one if it contains variables.
            if (isset($xmlObj->personalise)){
                $isPsnd = (string)$xmlObj->personalise;
                $isPersonalised = strtolower($isPsnd) === 'true' ? true : false;
            }

            $composer->setMessage($messageText, $isPersonalised);
            $composer->setSMSType($messageType);
        }
        
        private function setVoiceMessageProperties(VoiceComposer $composer, $xmlReader){
            
        }
        
        private function setCommonMessageProperties(MessageComposer $composer, SimpleXMLElement $xmlObj){
            // Schedule dateTime and UTC offset
            $scheduleDateTimeStr = (string)$xmlObj->schedule->dateTime;
            $scheduleUtcOffset   = (string)$xmlObj->schedule->offset;

            // format date and time
            $format = MessageUtil::DATETIME_FORMAT;
            $scheduleDateTime = \DateTime::createFromFormat($format, "{$scheduleDateTimeStr}");
            $composer->setScheduleDateTime($scheduleDateTime, $scheduleUtcOffset);
            
            // If there is message sender identifier
            if (isset($xmlObj->sender)){
                $composer->setSender((string)$xmlObj->sender);
            }

            // if there is notify URL given
            if (isset($xmlObj->callback)){
                $callbackURL = (string)($xmlObj->callback->url);
                $callbackAccept = (string)$xmlObj->callback->_accept;
                $composer->setDeliveryCallback($callbackURL, $callbackAccept);
            }
        }
        
        private function getNextMessage(): SMSComposer | VoiceComposer | null {
            while (!$this->_done && $this->_xmlReader->read()){
                $nodeType = $this->_xmlReader->nodeType;

                switch ($nodeType){
                    case \XMLReader::ELEMENT:
                        if (strtolower($this->_xmlReader->name) === 'message')
                            return $this->readMessage ($this->_xmlReader);
                        break;
                        
                    case \XMLReader::END_ELEMENT:
                        if (strtolower($this->_xmlReader->name) === 'data'){
                            $this->_done = true;
                        }
                        
                        break;
                }
            }
            
            // nothing to return
            return null;
        }
        
        public function getMessages(): MessageComposerList{
            $messagesList = new MessageComposerList();
            
            while (true){
                $msgComposer = $this->getNextMessage();
                
                if (is_null($msgComposer))
                    break;
                
                // add to the collection
                $messagesList->addItem($msgComposer);
            }
            
            return $messagesList;
        }
    }