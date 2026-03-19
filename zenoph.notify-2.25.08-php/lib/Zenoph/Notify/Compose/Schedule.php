<?php
/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Compose;
    
    use Zenoph\Notify\Utils\MessageUtil;
    
    class Schedule {
        private ?\DateTime $_scheduleDateTime;
        private ?string $_scheduleUTCOffset;
        
        public function __construct() {
            $this->_scheduleDateTime = null;
            $this->_scheduleUTCOffset = null;
        }

        public function setScheduleDateTime(?\DateTime $dateTime, $val1 = null, $val2 = null): void {
            if (is_null($dateTime)){
                $this->_scheduleDateTime = $this->_scheduleUTCOffset = null;
                return;
            }
            else {
                // set details
                $this->_scheduleDateTime = $dateTime;
                
                // if both val1 and val2 are unset, then only dateTime is to be set.
                if (is_null($val1) && is_null($val2)) {
                    $this->_scheduleUTCOffset = null;
                    return;
                }
                
                if (is_null($val1)) {   // must not be null
                   throw new \Exception('Missing time zone region or UTC offset.');
                }
                
                if (!is_null($val1) && !is_null($val2)) { // time zone region and  city provided
                    $this->_scheduleUTCOffset = $this->getRegionOffset($val1, $val2);
                }
                else if (!is_null($val1) && is_null($val2)){    // utc offset provided
                    // Ensure the UTC offset is in the correct format.
                    if (!MessageUtil::isValidTimeZoneOffset($val1))
                        throw new \Exception("The specified time zone offset '{$val1}' is invalid or not in the correct format.");
                        
                    // set scheduling UTC offset
                    $this->_scheduleUTCOffset = $val1;
                }
            }     
        }
        
        private function getRegionOffset(string $userReg, string $userCity): string {
            // Go through the time zones to see if there is this region and city combination.
            $timeZones = MessageUtil::getTimeZones();
            $utcOffset = null;
            $found = false;
            
            if (is_null($timeZones))
                throw new \Exception('Time zones data has not been loaded.');

            foreach ($timeZones as $region=>$cities){
                if (strtolower($region) != strtolower($userReg))
                    continue;

                // iterate through the cities
                foreach ($cities as $city){
                    if (strtolower($city[0]) == strtolower($userCity)){
                        $utcOffset = $city[1];
                        $found = true;
                        break;
                    }
                }

                if ($found)
                    break;
            }

            if (!$found)
                throw new \Exception("Invalid time zone region and city specifiers.");
            
            // return the time offset
            return $utcOffset;
        }
        
        public function getDateTime(): \DateTime | null {
            return $this->_scheduleDateTime;
        }
        
        public function getUTCOffset(): string | null {
            return $this->_scheduleUTCOffset;
        }
    }