<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Response;
    
    class CreditBalanceResponse extends APIResponse {
        private float | int $_balance;
        private ?string $_currencyName = null;
        private ?string $_currencyCode = null;
        private ?string $_isCurrencyModel = false;
        
        private static $CURRENCY_CREDIT_MODEL = 'currency';
        
        protected function __construct() {
            parent::__construct();
        }
        
        public static function create(APIResponse $apiResponse): CreditBalanceResponse {
            $dataFragment = &$apiResponse->getDataFragment();
            
            $cbr = new CreditBalanceResponse();
            $cbr->_httpStatusCode = $apiResponse->getHttpStatusCode();
            $cbr->_requestHandShake = $apiResponse->getRequestHandshake();
            
            // extract the balance information
            $balanceInfo = self::extractBalanceInfo($dataFragment);
            $cbr->_balance = $balanceInfo['balance'];
            $cbr->_currencyName = $balanceInfo['currencyName'];
            $cbr->_currencyCode = $balanceInfo['currencyCode'];
            $cbr->_isCurrencyModel = $balanceInfo['isCurrencyModel'];
            
            // return the balance response object
            return $cbr;
        }
        
        private static function extractBalanceInfo(&$data): array {
            $xml = simplexml_load_string($data);
            
            $balance = (float)$xml->balance;
            $isCurrencyCreditModel = ((string)$xml->model === self::$CURRENCY_CREDIT_MODEL);
            
            $balanceInfo['isCurrencyModel'] = $isCurrencyCreditModel;
            $balanceInfo['balance'] = $isCurrencyCreditModel ? $balance : (int)$balance;
            $balanceInfo['currencyName'] = (string)$xml->currencyName;
            $balanceInfo['currencyCode'] = (string)$xml->currencyCode;
            
            // return extracted balance details
            return $balanceInfo;
        }
        
        public function getBalance(): float | int {
            return $this->_balance;
        }
        
        public function getCurrencyName(): string | null {
            return $this->_currencyName;
        }
        
        public function getCurrencyCode(): string | null {
            return $this->_currencyCode;
        }
        
        public function isCurrencyModel(): string | null {
            return $this->_isCurrencyModel;
        }
    }