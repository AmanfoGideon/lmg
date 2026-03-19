<?php

    include_once (__DIR__.'/../../lib/Zenoph/Notify/AutoLoader.php');
    
    use Zenoph\Notify\Enums\AuthModel;
    use Zenoph\Notify\Request\SMSRequest;
    use Zenoph\Notify\Enums\SMSType;
    
    try {
        // initialise request
        $request = new SMSRequest();
		
		/**
         * Replace [messaging_website_domain] with the website domain on which account exists
         * 
         * Eg, if website domain is thewebsite.com, 
         * then set host as api.thewebsite.com
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
        
        $request->setSender('SENDER_ID');    // message sender Id must be requested from account to be used
        $request->setMessage('Hello {$name}! Your balance is ${$balance}.');     // must be single quoted string
        $request->setSMSType(SMSType::GSM_DEFAULT);
        
        // data for two clients
        $data[] = array('name'=>'Daniel', 'phone'=>'233246314915', 'balance'=>59.45);
        $data[] = array('name'=>'Oppong', 'phone'=>'0242053072', 'balance'=>984.45);
        
        // add personalised data to destinations
        foreach ($data as $clientData){
            $phone = $clientData['phone'];
            $name  = $clientData['name'];
            $balance = $clientData['balance'];
            $values = array($name, $balance);
            
            $request->addPersonalisedDestination($phone, false, $values);
        }
        
        // submit must be after the loop
        $msgResp = $request->submit();
    } 
    
    catch (\Exception $ex) {
        die (printf("Error: %s.",  $ex->getMessage()));
    }
    