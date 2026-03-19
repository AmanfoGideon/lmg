<?php

    namespace Zenoph\Notify\Report;
    
    use Zenoph\Notify\Report\MessageReport;
    
    class VoiceReport extends MessageReport {
        public function __construct() {
            parent::__construct();
        }
        
        public static function create(array &$data): VoiceReport {
            // create voice report object
            $report = new VoiceReport();
            
            // call base to set common base properties
            $report->setCommonProperties($data);
            
            // return voice report
            return $report;
        }
    }

