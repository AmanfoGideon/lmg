<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Response;
    
    use Zenoph\Notify\Report\SMSReport;
    use Zenoph\Notify\Report\VoiceReport;
    use Zenoph\Notify\Build\Reader\MessageReportReader;
    
    class MessageResponse extends APIResponse {
        protected SMSReport | VoiceReport | null $_report;
        
        protected function __construct() {
            parent::__construct();
        }
        
        public static function isValidDataFragment(string &$fragment){
            $matches = [];
            preg_match("/<data>(.*)?<\/data>/s", $fragment, $matches); 
           
            return count($matches) > 0;
        }
        
        public function getReport(): SMSReport | VoiceReport | null {
            return $this->_report;
        }
        
        public static function create(APIResponse $apiResponse): MessageResponse {
            $dataFragment = &$apiResponse->getDataFragment();
            $msgResponse = new MessageResponse();
            
            $msgResponse->_httpStatusCode = $apiResponse->getHttpStatusCode();
            $msgResponse->_requestHandShake = $apiResponse->getRequestHandshake();
            
            if (!is_null($dataFragment) && !empty($dataFragment)){
                // Ensure response data fragment is correct
                if (!self::isValidDataFragment($dataFragment)){
                    throw new \Exception('Invalid response data fragment.');
                }

                // extract response details
                $msgResponse->_report = self::extractReport($dataFragment);
            }
            
            return $msgResponse;
        }
        
        protected static function extractReport(string &$dataFragment): SMSReport | VoiceReport | null {
            $reportReader = new MessageReportReader();
            $reportReader->setData($dataFragment);
            
            // read and return message report
            return $reportReader->read();
        }
    }