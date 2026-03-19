<?php
/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Utils;
    
    use Zenoph\Notify\Enums\SMSType;
    use Zenoph\Notify\Enums\MessageCategory;
    use Zenoph\Notify\Report\VoiceReport;
    use Zenoph\Notify\Report\SMSReport;
    
    final class MessageUtil {
        const PHONENUM_PATTERN = '\+?[0-9]{8,13}';  // taking notice of length of phone number
        const DATETIME_FORMAT = "Y-m-d H:i:s";
        const DATETIME_FORMAT_EX = "Y-m-d H:i:s T"; // inclusion of T to add time zone offset string
        const TZ_OFFSET_PATTERN = "/^(\+|-)(([0][0-9]:[0-5][0-9])|([1][0-2]:[0-5][0-9])|(13:00))$/";
        
        const __CUSTOM_MSGID_MIN_LEN__ = 30;
        const __CUSTOM_MSGID_MAX_LEN__ = 40;
        
        private static ?array $messageTypes = null;
        private static ?array $timeZones = null;
        
        public static function isValidTimeZoneOffset($offset) :bool{
            return preg_match(self::TZ_OFFSET_PATTERN, $offset);
        }
        
        public static function extractGeneralSettings(string &$df) :void {
            $xml = simplexml_load_string($df);
            
            // extract message types
            self::extractMessageTypes($xml);
            
            // extract the time zones.
            self::extractTimeZones($xml);
        }
        
        private static function extractMessageTypes(\SimpleXMLElement $xml) :void {
            $messageTypesNode = $xml->settings->general->messageTypes;
            
            foreach ($messageTypesNode->type as $typeInfo){
                $messageType['id'] = (int)$typeInfo->id;
                $messageType['label'] = (string)$typeInfo->label;
                $messageType['singleLen'] = (int)$typeInfo->singleLen;
                $messageType['concatLen'] = (int)$typeInfo->concatLen;
                $messageType['charLen'] = (int)$typeInfo->charLen;
                
                self::$messageTypes[] = $messageType;
            }
        }
        
        private static function extractTimeZones(\SimpleXMLElement $xml) :void {
            $timezonesNode = $xml->settings->general->timeZones->region;
            
            foreach ($timezonesNode as $region){
                $regionName = (string)$region['name'];
                $cities = array();
                
                // each region has a list of cities and their respective timezones
                foreach ($region->city as $cityInfo){
                    $city = (string)$cityInfo;
                    $tzOffset = (string)$cityInfo['offset'];
                    
                    $cities[] = array($city, $tzOffset);
                }
                
                self::$timeZones[$regionName] = $cities;
            }
        }
        
        public static function isValidMessageCategory(int $category) :bool{
            switch ($category){
                case MessageCategory::SMS:
                case MessageCategory::VOICE:
                    return true;
                    
                default:
                    return false;
            }
        }
        
        public static function createReport(array &$data): SMSReport | VoiceReport {
            if (!array_key_exists('category', $data))
                throw new \Exception('Missing message category specifier for report.');
            
            if ($data['category'] == MessageCategory::SMS)
                return SMSReport::create($data);
            else if ($data['category'] == MessageCategory::VOICE)
                return VoiceReport::create($data);
            else
                throw new \Exception('Invalid message report category specifier.');
        }
        
        public static function messageCategoryToEnum(string $category): int {
            switch ($category) {
                case "sms":
                    return MessageCategory::SMS;
                    
                case "voice":
                    return MessageCategory::VOICE;
                    
                case "ussd":
                    return MessageCategory::USSD;
                    
                default:
                    throw new \Exception("Unknown message category specifier: [$category]");
            }
        }
        
        public static function messageTypeToEnum(string $type) :int {
            if (is_null(self::$messageTypes))
                throw new \Exception('Text message types have not been loaded.');
            
            foreach (self::$messageTypes as $typeInfo){
                if (strtolower($typeInfo['label']) == strtolower($type)){
                    // return the identifier
                    return $typeInfo['id'];
                }
            }
            
            throw new \Exception("Text message type '{$type}' was not found.");
        }
        
        public static function messageTypeToStr(int $type) :string {
            if (is_null(self::$messageTypes))
                throw new \Exception('Text message types have not been loaded.');
            
            if (!SMSType::isDefined($type))
                throw new \Exception('Invalid text message type identifier.');
            
            foreach (self::$messageTypes as $typeInfo){
                if ($type === intval($typeInfo['id']))
                    return $typeInfo['label'];
            }
            
            throw new \Exception("Text message type identifier '{$type}' was not found.");
        }
        
        public static function getMessageTypeInfo(int $type) :array | null {
            if (is_null(self::$messageTypes))
                throw new \Exception('Text message types have not been loaded.');
            
            // we expect it to be a defined message type, not a string
            if (is_null($type) || !SMSType::isDefined($type))
                throw new \Exception('Invalid text message type identifier.');
            
            foreach (self::$messageTypes as $typeInfo){
                if (intval($typeInfo['id']) === $type)
                    return $typeInfo;
            }

            return null;
        }
        
        public static function dateTimeToStr(\DateTime $dateTime) :string {
            return $dateTime->format(self::DATETIME_FORMAT);
        }
        
        public static function getMessageCategoryLabel(int $category) :string {
            switch ($category){
                case MessageCategory::SMS:
                    return "sms";
                    
                case MessageCategory::VOICE:
                    return "voice";
                    
                case MessageCategory::USSD:
                    return "ussd";
                    
                default:
                    throw new \Exception('Unknown composer category identifier.');
            }
        }
        
        public static function isNumericSender(string $senderId) :bool {
            return preg_match('/^(\+)?\d+$/', $senderId);
        }
        
        public static function getTimeZones() :?array {
            return self::$timeZones;
        }
        
        public static function getTextMessageTypes() :?array {
            return self::$messageTypes;
        }
    }