<?php
/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Store;
    
    use Zenoph\Notify\Enums\AuthModel;
    use Zenoph\Notify\Store\UserData;
    
    class AuthProfile {
        private string $authLogin;
        private string $authPassword;
        private string $authApiKey;
        private string $authModel;
        private bool $authed = false;
        private UserData $userData;
        
        public function __construct() {
            $this->authModel = AuthModel::API_KEY;
            $this->authLogin = '';
            $this->authPassword = '';
            $this->authApiKey = '';
        }
        
        public function authenticated() :bool {
            return $this->authed;
        }
        
        public function getAuthModel(): string {
            return $this->authModel;
        }
        
        public function setAuthModel(string $model): void {
            switch ($model){
                case AuthModel::PORTAL_PASS:
                case AuthModel::API_KEY:
                    break;
                
                default:
                    throw new \Exception('Invalid authentication model specifier.');
            }
            
            // set it.
            $this->authModel = $model;
        }
        
        public function setAuthLogin(string $login): void {
            $this->authLogin = $login;
        }
        
        public function getAuthLogin() :string {
            return $this->authLogin;
        }
        
        public function setAuthPassword(string $psswd) :void {
            $this->authPassword = $psswd;
        }
        
        public function getAuthPassword() :string {
            return $this->authPassword;
        }
        
        public function setAuthApiKey(string $apiKey) :void {
            $this->authApiKey = $apiKey;
        }
        
        public function getAuthApiKey() :string {
            return $this->authApiKey;
        }
        
        public function extractUserData(string &$df) :void {
            $this->userData = UserData::create($df);
            $this->authed = true;
        }
        
        public function getUserData() :UserData {
            if (!isset($this->userData))
                throw new \Exception('User data has not been initialized.');
            
            return $this->userData;
        }
    }
