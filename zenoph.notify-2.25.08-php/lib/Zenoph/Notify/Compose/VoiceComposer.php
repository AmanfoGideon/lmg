<?php
/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Compose;
    
    use Zenoph\Notify\Compose\MessageComposer;
    
    class VoiceComposer extends MessageComposer implements IVoiceComposer {
        private $offlineFile = null;
        private $templateRef = null;

        public function __construct($data = null) {
            parent::__construct($data);
        }
        
        public static function create(&$p): VoiceComposer{
            $composer = new VoiceComposer();

            return $composer;
        }
        
        public function getOfflineVoice(){
            return $this->offlineFile;
        }
        
        public function isOfflineVoice(){
            return !(is_null($this->offlineFile) || empty($this->offlineFile));
        }
        
        public function getTemplateReference() {
            return $this->templateRef;
        }
        
        public function setOfflineVoice($fileName, $saveRef = null) {
            if (is_null($fileName) || empty($fileName))
                throw new \Exception('Missing or invalid reference to voice file.');
            
            if (!is_null($saveRef) && empty($saveRef))
                throw new \Exception("Invalid name for saving offline voice file as template.");
            
            // the file must exist.
            if (!file_exists($fileName))
                throw new \Exception("Voice file was not found.");
            
            // See if the offline file should be saved as a template
            $this->templateRef = (!is_null($saveRef) ? $saveRef : null);
            
            // Voice message is a file on client machine
            $this->offlineFile = $fileName;
        }
        
        public function setTemplateReference($ref) {
            if (is_null($ref) || empty($ref))
                throw new \Exception('Missing or invalid voice template reference name.');
            
            // Voice message is not a local file
            $this->offlineFile = null;
            
            // Voice message will be a saved template on server
            $this->templateRef = $ref;
        }
    }