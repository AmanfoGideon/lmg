<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

    namespace Zenoph\Notify\Build\Writer;
    
    use Zenoph\Notify\Enums\AuthModel;
    use Zenoph\Notify\Enums\ContentType;
    use Zenoph\Notify\Utils\MessageUtil;
    use Zenoph\Notify\Build\Writer\IDataWriter;
    use Zenoph\Notify\Compose\MessageComposer;
    use Zenoph\Notify\Compose\Composer;
    
    abstract class DataWriter implements IDataWriter {
        protected string $_authModel;
        protected string $_authApiKey;
        protected string $_authLogin;
        protected string $_authPassword;
        protected bool $_authLoadAPS;

    //    protected abstract function writeAuthData(&$store);
        protected abstract function writeDestinations(Composer $mc, &$store);
        protected abstract function writeCommonMessageProperties(MessageComposer $mc, &$store);
        protected abstract function writeScheduleInfo(\DateTime $dateTime, string $utcOffset, &$store);
        protected abstract function writeCallbackInfo(string $url, int $contentType, &$store);
        
        private static $_AUTH_FACTOR_SEPARATOR = "__::";

        
        public static function create(int $contentType): MultiPartDataWriter|UrlEncodedDataWriter|XmlDataWriter{
            if ($contentType == ContentType::XML || $contentType == ContentType::GZBIN_XML)
                return new XmlDataWriter();
            else if ($contentType == ContentType::WWW_URL_ENCODED || $contentType == ContentType::GZBIN_WWW_URL_ENCODED)
                return new UrlEncodedDataWriter();
            else if ($contentType == ContentType::MULTIPART_FORM_DATA)
                return new MultiPartDataWriter();
            else
                throw new \Exception('Invalid or unsupported content type for initialising request writer.');
        }

        public function setAuthModel(string $model): void {
            if ($model != AuthModel::API_KEY && $model != AuthModel::PORTAL_PASS)
                throw new \Exception("Invalid model for writing authentication data.");
            
            $this->_authModel = $model;
        }
        
        public function setAuthApiKey(string $key): void {
            $this->_authApiKey = $key;
        }
        
        public function setAuthLogin(string $login): void {
            $this->_authLogin = $login;
        }
        
        public function setAuthPassword(string $psswd): void {
            $this->_authPassword = $psswd;
        }
        
        public function setAuthAPSLoad(bool $load): void {
            $this->_authLoadAPS = $load;
        }
     
        protected function assertAuthData(): void {
            if ($this->_authModel != AuthModel::API_KEY && $this->_authModel != AuthModel::PORTAL_PASS)
                throw new \Exception('Authentication model has not been set for writing request.');
            
            // If portal pass, login and password should be set
            if ($this->_authModel == AuthModel::PORTAL_PASS){
                if (!isset($this->_authLogin))
                    throw new \Exception('Account login has not been set for writing request.');
                
                if (!isset($this->_authPassword))
                    throw new \Exception('Account password has not been set for writing request.');
            }
            else {  // API key authentication then
                if (!isset($this->_authApiKey))
                    throw new \Exception('API key has not been set for writing request.');
            }
        }
        
        protected function validateScheduleInfo(\DateTime $dateTime, string $utcOffset): void {
            if (empty($utcOffset) || !MessageUtil::isValidTimeZoneOffset($utcOffset))
                throw new \Exception('Invalid time zone UTC offset specifier.');
        }
        
        protected function validateDeliveryNotificationInfo(string $url, int $contentType){
            if (empty($url))
                throw new \Exception('Invalid URL for message delivery notifications.');
            
            if ($contentType != ContentType::XML && $contentType != ContentType::JSON)
                throw new \Exception('Unsupported content type for message delivery notifications.');
        }
        
        protected function validateScheduledMessagesLoadData(array $dataArr): void {
            // keys must be present, even when null
            if (!array_key_exists('category', $dataArr))
                throw new \Exception('Message category not set for writing scheduled messages request.');
            
            if (!array_key_exists('dateFrom', $dataArr))
                throw new \Exception("Date 'From' has not been set for writing scheduled messages request.");
            
            if (!array_key_exists('dateTo', $dataArr))
                throw new \Exception("Date 'To' has not been set for writing scheduled messages request.");
            
            if (!array_key_exists('offset', $dataArr))
                throw new \Exception('Time zone offset has not been set for writing scheduled messages request.');
            
            // To know if it is specific message, templateId must be present, can be null for unspecified message
            if (!array_key_exists('batch', $dataArr))
                throw new \Exception('Message batch identifier has not been set for writing scheduled messages request.');
        }
    }