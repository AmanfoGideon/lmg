<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Build\Reader;
    
    use Zenoph\Notify\Report\SMSReport;
    use Zenoph\Notify\Report\VoiceReport;
    use Zenoph\Notify\Utils\MessageUtil;
    use Zenoph\Notify\Collections\MessageDestinationsList;
    use Zenoph\Notify\Build\Reader\MessageDestinationsReader;
    
    class MessageReportReader {
        private bool $_done;
        private \XMLReader $_xmlReader;
        
        public function __construct() {
            $this->_done = false;
        }
        
        public function setData(string | \XMLReader &$data): void {
            // If string, the xml fragment should be validated
            if (is_string($data)) {
                $this->validateXMLFragment($data);
                $this->_xmlReader = new \XMLReader();
                $this->_xmlReader->XML($data);
            }
            else {
                $this->_xmlReader = $data;
            }
        }
     
        public function read(): SMSReport | VoiceReport | null {
            if (!isset($this->_xmlReader))
                throw new \Exception('Invalid reference to reader for reading next message report.');
            
            while ($this->_xmlReader->read() && !$this->_done){
                if ($this->_xmlReader->nodeType == \XMLReader::ELEMENT && 
                    strtolower($this->_xmlReader->name) == "data"){
                    return $this->readMessageReport($this->_xmlReader);
                }
                else if ($this->_xmlReader->nodeType == \XMLReader::END_ELEMENT &&
                    strtolower($this->_xmlReader->name) == "data"){
                    $this->_done = true;
                }
            }
            
            // return null at this point
            return null;
        }
        
        private function readMessageReport(\XMLReader $xmlReader): SMSReport | VoiceReport | null {
            $readEnded = false;
            $msgReport = null;
            
            // we read only when the cursor is currently on message element
            if ($xmlReader->nodeType == \XMLReader::ELEMENT && strtolower($xmlReader->name) === 'data'){
                $dataArr = [];
                
                // continue reading
                while ($xmlReader->read() && !$readEnded){
                    if ($xmlReader->nodeType == \XMLReader::ELEMENT){
                        $name = strtolower($xmlReader->name);
                        
                        switch ($name){
                            case 'batch':
                                $dataArr['batch'] = $xmlReader->readString();
                                break;
                            
                            case 'category':
                                $xmlDoc = new \SimpleXMLElement($xmlReader->readOuterXml());
                                $dataArr['category'] = MessageUtil::messageCategoryToEnum((string)$xmlReader->readString());
                                break;
                            
                            case 'text':
                                $xmlDoc = new \SimpleXMLElement($xmlReader->readOuterXml());
                                $dataArr['text'] = $xmlReader->readString();
                                break;
                            
                            case 'type':
                                $xmlDoc = new \SimpleXMLElement($xmlReader->readOuterXml());
                                $dataArr['type'] = (int)$xmlReader->readString();
                                break;
                            
                            case 'sender':
                                $xmlDoc = new \SimpleXMLElement($xmlReader->readOuterXml());
                                $dataArr['sender'] = $xmlReader->readString();
                                break;

                            case 'personalised':
                                $xmlDoc = new \SimpleXMLElement($xmlReader->readOuterXml());
                                $dataArr['personalised'] = strtolower($xmlReader->readString()) === 'true';
                                break;
                            
                            case 'delivery':
                                $xmlDoc = new \SimpleXMLElement($xmlReader->readOuterXml());
                                $dataArr['delivery'] = strtolower($xmlReader->readString()) === 'true';
                                break;
                            
                            case 'destinationscount':
                                $dataArr['destsCount'] = (int)$xmlReader->readString();
                                break;
                            
                            case 'destinations':
                                $dataArr['destinations'] = $this->readMessageDestinations($xmlReader);
                                break;
                        }
                    }
                    else if ($xmlReader->nodeType == \XMLReader::END_ELEMENT && strtolower($xmlReader->name) === 'data'){
                        $readEnded = true;
                    }
                }
                
                if (count($dataArr) > 0)
                    $msgReport = MessageUtil::createReport($dataArr);
            }
            
            // return message report
            return $msgReport;
        }
        
        private function readMessageDestinations(\XMLReader $xmlReader): MessageDestinationsList {
            $destsList = new MessageDestinationsList();

            // we read when cursor is already on destinations element
            if ($xmlReader->nodeType == \XMLReader::ELEMENT && strtolower($xmlReader->name) === 'destinations'){
                $destsReader = new MessageDestinationsReader();
                $destsReader->setData($xmlReader);
                
                while (true){
                    $msgDest = $destsReader->getNextItem();
                    
                    if (is_null($msgDest))
                        break;
                    
                    // add to the destinations collection
                    $destsList->add($msgDest);
                }
            }

            // return destinations list
            return $destsList;
        }
        
        private function validateXMLFragment(string &$fragment){
            
        }
    }