<?php

    namespace Zenoph\Notify\Request;
    
    use Zenoph\Notify\Request\NotifyRequest;
    use Zenoph\Notify\Response\APIResponse;
    
    class USSDRequest extends NotifyRequest {
        private $_statements;
        private $_options;
        
        const NEW_LINE = '\r\n';
        
        public function __construct($ap = null) {
            parent::__construct($ap);
            
            $this->_statements = array();
            $this->_options = array();
        }
        
        public function addOption($value, $key = null){
            
        }
        
        public function addStatement($stmt, $newLine = false){
            $this->_statements[] = $stmt;
            
            if ($newLine === true)
                $this->_statements[] = self::NEW_LINE;
        }
        
        public function newLine(){
            $this->_statements[] = self::NEW_LINE;
        }
        
        public function getStatements(){
            
        }
        
        public function getOptions() {
            return $this->_options;
        }
        
        public function getOutput(){
            $str = "";
            
            foreach ($this->_statements as $stmt)
                $str .= $stmt;
            
            // if there are options we should append
            if (count($this->_options)){
                $str .= self::NEW_LINE;
                
                for ($i = 0; $i < count($this->_options); $i++)
                    $str .= $this->_options[$i].($i < (count($this->_options) - 1) ? self::NEW_LINE : '');
            }
            
            return $str;
        }
        
        public function getLength(){
            return strlen($this->getOutput());
        }
        
        public function setIndexedOptions($options){
            if (is_null($options) || !is_array($options) || count($options) == 0)
                throw new \Exception("Invalid indexed options data.");
            
            // we need to ensure that the data is integer indexed
            $tempData = array();
            
            foreach ($options as $key=>$value){
                if (!is_numeric($key)) 
                    throw new \Exception("Invalid index key type for options.");
                    
                // value should empty
                if (is_null($value) || empty($value))
                    throw new \Exception("Invalid indexed optiona value.");

                // add to the temporal storage
                $tempData[$key] = $value;
            }
        }
        
        public function submit(): APIResponse {
            return parent::submit();
        }
        
        public static function captureMO(&$data, $contentType){
            
        }
    }

