<?php
    include_once (__DIR__.'/../../lib/Zenoph/Notify/AutoLoader.php');
    
    use Zenoph\Notify\Enums\AuthModel;
    use Zenoph\Notify\Enums\SMSType;
    use Zenoph\Notify\Request\SMSRequest;
    
    try {
        // create request subject
        $request = new SMSRequest();
		
		/**
         * Replace [messaging_website_domain] with the website domain on which account exists
         * 
         * Eg, if website domain is thewebsite.com, then set host as: api.thewebsite.com
         * 
         * For further information, read the documentation for what you should set as the host
         */
        $request->setHost('api.[messaging_website_domain]');
		
		
		/* By default, HTTPS connection is used to send requests. If you want to disable the use of HTTPS
         * and rather use HTTP connection, comment out the call to useSecureConnection below below this comment
         * block and pass false as argument to the function call.
         * 
         * When testing on local machine on which https connection does not work, you may encounter 
         * request submit error with status value zero (0). If you want to use HTTPS connection on local machine, 
         * then you can instruct that the Certificate Authority file (cacert.pem) which accompanies the SDK be 
         * used to be able to use HTTPS from your local machine by setting the second argument of the function call to 'true'.
         * That is:
         *         $request->useSecureConnection(true, true);
         * 
         * You can download the current Certificates Authority file (cacert.pem) file from https://curl.se/docs/caextract.html
         * to replace the one in the main root directory of the SDK. Please maintain the file name as cacert.pem
         */
        // $request->useSecureConnection(true);
		
        $request->setAuthModel(AuthModel::API_KEY);
        $request->setAuthApiKey('API_KEY');
        
        // set message properties
        $request->setMessage('A test message from PHP SDK.');
        $request->setSMSType(SMSType::GSM_DEFAULT);
        
        // message sender ID to be used. It must have been requested from account and granted
        $request->setSender('TEST');
        
        // add message destinations. 
        $request->adddestination('0246314915');
        
        // an array of destinations to be added
        $destsArr = array('0242053072', '0246314915');
        
        // add phone numbers in an array
        $request->addDestinationsFromCollection($destsArr);
        
        // submit message for response
        $msgResp = $request->submit();
    } 
    
    catch (\Exception $ex) {
        die ($ex->getMessage());
    }
    