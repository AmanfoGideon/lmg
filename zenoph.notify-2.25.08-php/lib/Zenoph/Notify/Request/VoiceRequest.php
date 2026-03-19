<?php

    namespace Zenoph\Notify\Request;
    
    use Zenoph\Notify\Enums\ContentType;
    use Zenoph\Notify\Compose\IVoiceComposer;
    use Zenoph\Notify\Compose\VoiceComposer;
    use Zenoph\Notify\Request\MessageRequest;
    use Zenoph\Notify\Response\MessageResponse;
    use Zenoph\Notify\Build\Writer\XmlDataWriter;
    use Zenoph\Notify\Build\Writer\MultiPartDataWriter;
    
    class VoiceRequest extends MessageRequest implements IVoiceComposer {
        private static $_baseResource = 'message/voice/send';
        static $VOICE_UPLOAD_KEY_NAME = "voice_file";
        
        public function __construct($ap = null) {
            parent::__construct($ap);
            $this->_composer = new VoiceComposer($ap);
        }
        
        public function setOfflineVoice($fileName, $saveRef = null) {
            $this->assertComposer();
            $this->_composer->setOfflineVoice($fileName, $saveRef);
        }
        
        public function getOfflineVoice() {
            $this->assertComposer();
            return $this->_composer->getOfflineVoice();
        }
        
        public function setTemplateReference($ref) {
            $this->assertComposer();
            $this->_composer->setTemplateReference($ref);
        }
        
        public function getTemplateReference() {
            $this->assertComposer();
            return $this->_composer->getTemplateReference();
        }
        
        public function isOfflineVoice() {
            $this->assertComposer();
            return $this->_composer->isOfflineVoice();
        }
        
        private static function &constructDataFragment(VoiceComposer $composer, $contentType){
            $writer = null;
            
            if ($contentType == ContentType::XML)
                $writer = new XmlDataWriter();
            else if ($contentType == ContentType::MULTIPART_FORM_DATA)
                $writer = new MultiPartDataWriter();
            
            $data = &$writer->writeVoiceRequest($composer);
            return $data;
        }

        public function submit(): MessageResponse {
            if ($this->_composer->isOfflineVoice()){
                // data will be sent as multipart/form-data
                $this->_contentType = ContentType::MULTIPART_FORM_DATA;
            }
            
            $this->setRequestResource(self::$_baseResource);
            
            $this->dataFragment = &self::constructDataFragment($this->_composer, $this->_contentType);
            $this->setRequestResource(self::$_baseResource);
            
            // submit for response */
            $apiResponse = parent::submit();
            
            // Create and return the message response object
            return MessageResponse::create($apiResponse); 
        }
        
        public static function submitComposer(VoiceComposer $composer, $param1, $param2 = null): MessageResponse {
            if (is_null($param1) || empty($param1))
                throw new \Exception('Invalid authentication parameter for request.');
            
            $request = new VoiceRequest();
            $request->_composer = &$composer;

            // initialize request authentication
            self::initRequestAuth($request, $param1, $param2);
            
            // submit the request
            return $request->submit();
        }
    }