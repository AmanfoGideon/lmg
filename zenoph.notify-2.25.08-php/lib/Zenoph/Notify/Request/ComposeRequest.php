<?php
/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Request;
   
    use Zenoph\Notify\Store\AuthProfile;
    use Zenoph\Notify\Compose\IComposer;
    use Zenoph\Notify\Compose\SMSComposer;
    use Zenoph\Notify\Compose\VoiceComposer;
    use Zenoph\Notify\Collections\ComposerDestinationsList;
    
    abstract class ComposeRequest extends NotifyRequest implements IComposer {
        protected SMSComposer | VoiceComposer $_composer;
        
        public function __construct(?AuthProfile $ap = null) {
            parent::__construct($ap);
        }
        
        protected function assertComposer(): void {
            if (!isset($this->_composer))
                throw new \Exception('Invalid reference to message composer object.');
        }
   
        protected function validate(): void {
            $this->assertComposer();
            $message = $this->_composer->getMessage();
            
            if (is_null($message) || empty($message))
                throw new \Exception("Message body has not been set.");
            
            // there should be message destinations
            if ($this->_composer->getDestinationsCount() == 0)
                throw new \Exception("There are no destinations for submitting message.");
        }
        
        public function getComposer(): SMSComposer | VoiceComposer{
            return $this->_composer;
        }
        
        public function setAuthProfile(AuthProfile $ap): void {
            // validate auth profile
            $this->validateAuthProfile($ap);
            
            // composer object must already be initialised
            if (!isset($this->_composer))
                throw new \Exception('Composer object has not been initialised.');
            
            // set the user object data
            $this->_composer->setUserData($ap->getUserData());
            
            // parent will set auth profile
            parent::setAuthProfile($ap);
        }
        
        public function getRouteCountries(): array | null {
            $this->assertComposer();
            return $this->_composer->getRouteCountries();
        }
     
        public function getDefaultNumberPrefix(): array | null {
            $this->assertComposer();
            return $this->_composer->getDefaultNumberPrefix();
        }
        
        public function setDefaultNumberPrefix($dialCode): void {
            $this->assertComposer();
            $this->_composer->setDefaultNumberPrefix($dialCode);
        }

        public function getDefaultTimeZone(): array | null {
            $this->assertComposer();
            return $this->_composer->getDefaultTimeZone();
        }
        
        public function getDestinationCountry(string $phoneNumber): string | null {
            $this->assertComposer();
            return $this->_composer->getDestinationCountry($phoneNumber);
        }
        
        public function getDefaultDestinationCountry(): array {
            $this->assertComposer();
            return $this->_composer->getDefaultDestinationCountry();
        }
        
        public function getDestinationWriteMode($phoneNumber): int {
            $this->assertComposer();
            return $this->_composer->getDestinationWriteMode($phoneNumber);
        }
        
        public function getDestinationWriteModeById(string $messageId): int {
            $this->assertComposer();
            return $this->_composer->getDestinationWriteModeById($messageId);
        }
        
        public function getDestinations(): ComposerDestinationsList {
            $this->assertComposer();
            return $this->_composer->getDestinations();
        }
        
        public function getDestinationsCount(): int {
            $this->assertComposer();
            return $this->_composer->getDestinationsCount();
        }
        
        public function updateDestination(string $prePhoneNumber, string $newPhoneNumber): bool  {
            $this->assertComposer();
            return $this->_composer->updateDestination($prePhoneNumber, $newPhoneNumber);
        }
        
        public function updateDestinationById(string $messageId, string $newPhoneNumber): bool {
            $this->assertComposer();
            return $this->_composer->updateDestinationById($messageId, $newPhoneNumber);
        }
        
        public function clearDestinations(): void {
            $this->assertComposer();
            $this->_composer->clearDestinations();
        }
        
        public function addDestinationsFromTextStream(string &$str): int {
            $this->assertComposer();
            return $this->_composer->addDestinationsFromTextStream($str);
        }
        
        public function addDestinationsFromCollection(&$phoneNumbers, $throwEx = false): int {
            $this->assertComposer();
            return $this->_composer->addDestinationsFromCollection($phoneNumbers, $throwEx);
        }
        
        public function addDestination(string $phoneNumber, bool $throwEx = true, ?string $messageId = null): int {
            $this->assertComposer();
            return $this->_composer->addDestination($phoneNumber, $throwEx,  $messageId);
        }
        
        public function removeDestination(string $phoneNumber): bool {
            $this->assertComposer();
            return $this->_composer->removeDestination($phoneNumber);
        }
        
        public function removeDestinationById(string $messageId): bool {
            $this->assertComposer();
            return $this->_composer->removeDestinationById($messageId);
        }
        
        public function destinationExists(string $phonenum): bool {
            $this->assertComposer();
            return $this->_composer->destinationExists($phonenum);
        }

        public function getCategory(): int {
            $this->assertComposer();
            return $this->_composer->getCategory();
        }
    }