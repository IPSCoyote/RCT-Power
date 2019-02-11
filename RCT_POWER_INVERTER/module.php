<?
  class RCTPowerInverter extends IPSModule {
 
	const COMPORT_OPEN           = 'Open';             // Comport was just opened
	const COMPORT_PREINIT        = 'PreInit';          // Viessmann INIT was requested (0x04)
	const COMPORT_INIT           = 'Init';             // Viessmann INIT was requested (0x16 0x00 0x00)
	const COMPORT_READY          = 'Ready';            // Viessmann confirmed INIT; Control is READY to take commands
	const COMPORT_CLOSED         = 'Closed';           // Comport is closed
	const COMPORT_DATA_REQUESTED = 'DataRequested';    // Data was requested from the Control
	    
        public function Create() {
          /* Create is called ONCE on Instance creation and start of IP-Symcon.
             Status-Variables und Modul-Properties for permanent usage should be created here  */
          parent::Create(); 
        }
 
        public function ApplyChanges() {
          /* Called on 'apply changes' in the configuration UI and after creation of the instance */
          parent::ApplyChanges();
		
          $this->SetReceiveDataFilter(".*7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7.*");
        }
 
        //=== Module Functions =========================================================================================
        public function ReceiveData($JSONString) {
	
	  $this->sendDebug( "RCTPower", "ReceiveData Begin", 0 );	
		
          // Receive data from serial port I/O
          $data = json_decode($JSONString);	
		
          // Process data

          $this->sendDebug( "RCTPower", "ReceiveData End", 0 );
          return true;
        }
        
        //=== Private Functions for Communication handling with Vitotronic ==============================================
        private function startCommunication() {
	  ///--- HANDLE SERIAL PORT -------------------------------------------------------------------------------------	
          // check serial port (parent)
          $SerialPortInstanceID = IPS_GetInstance($this->InstanceID)['ConnectionID']; 
          if ( $SerialPortInstanceID == 0 ) return false; // No parent assigned  
            
          $ModuleID = IPS_GetInstance($SerialPortInstanceID)['ModuleInfo']['ModuleID'];      
          if ( $ModuleID !== '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}' ) return false; // wrong parent type
		
          ///--- INIT CONNECTION ----------------------------------------------------------------------------------------
          // send 0x04 to bring communication into a defined state (Protocol 300)
	  $this->SendDataToParent(json_encode(Array("DataID" => "{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}", 
						    "Buffer" => utf8_encode("\x04") )));
          $this->SetBuffer( "PortState", ViessControl::COMPORT_PREINIT );
		
          // now wait for connection to be COMPORT_READY (not too long ;))
	  $tryCounter = 10;
	  do {
	    //$this->SendDataToParent(json_encode(Array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", 
		//				      "Buffer" => utf8_encode("\x16\x00\x00") )));
            sleep(1); // wait 1 second
	    $tryCounter--;	  
	  } while ( $this->GetBuffer( "PortState" ) != ViessControl::COMPORT_READY AND $tryCounter > 0 );
		
	  if ( $this->GetBuffer( "PortState" ) != ViessControl::COMPORT_READY ) {
            // connection failed
	    return false; } 
	  else { 
	    // connection established
            return true; }
        } 
        
        private function endCommunication() {
	  // get serial port (parent) and check
	  $SerialPortInstanceID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
	  if ( $SerialPortInstanceID == 0 ) return false; // No parent assigned     
          // check parent is serial port  
          $ModuleID = IPS_GetInstance($SerialPortInstanceID)['ModuleInfo']['ModuleID'];      
          if ( $ModuleID !== '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}' ) return false; // wrong parent type
	  if ( IPS_GetProperty( $SerialPortInstanceID, "Open" ) != true ) return false; // com port closed	
		
	  // send 0x04		 
	  $this->SendDataToParent(json_encode(Array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", 
						    "Buffer" => utf8_encode("\x04") )));	
		
	  // Close serial port
	  if ( IPS_GetProperty( $SerialPortInstanceID, "Open" ) != false )
          {
	        IPS_SetProperty( $SerialPortInstanceID, "Open", false );
	        IPS_ApplyChanges( $SerialPortInstanceID );
          }
	  $this->SetBuffer( "PortState", ViessControl::COMPORT_CLOSED );	
		
	  return true;			
        }
       
        //=== Tool Functions ============================================================================================
        private function strToHex($string){
		$this->sendDebug( "Viess", "strToHex Start", 0 );
            $hex = '';
            for ($i=0; $i<strlen($string); $i++){
                $ord = ord($string[$i]);
                $hexCode = dechex($ord);
                $hex .= substr('0'.$hexCode, -2);
            }
		$this->sendDebug( "Viess", "strToHex End", 0 );
            return strToUpper($hex);
	}
	    
        private function hexToStr($hex){
            $string='';
            for ($i=0; $i < strlen($hex)-1; $i+=2){
                $string .= chr(hexdec($hex[$i].$hex[$i+1]));
            }
            return $string;
        }  
	    
        private function createPackage( $type, $address, $countOfBytes, $bytesToWrite ) {
          // determination of payload length (bytes between 0x41 and checksum)
          $payloadLength = 5;
          if ( $type == 2 ) { $payloadLength = $payloadLength + $countOfBytes; }
          $package = "\x41".chr($payloadLength);
	  
          // perpare payload
	  $package = $package."\x0".chr($type).chr( hexdec( substr( $address,0,2 ) ) ).chr( hexdec( substr( $address,2,2 ) ) ).chr($countOfBytes);
	  // add Bytes to write in case of write request
	  if ( $type == 2 ) {
	    for( $i=0; $i<$countOfBytes; $i++ ) {
		  $package = $package.chr( hexdec( substr( $bytesToWrite( $i*2, 2 ) ) ) );
		}
	  }
	  
	  // calculate checksum
	  $sum = 0;
	  for( $i=1; $i<strlen( $package ); $i++ ) {
	    $sum = $sum + ord( $package[$i] );
	  }
	  $hexCode = substr(dechex( $sum ),-2);
          $hexCode .= substr('0'.$hexCode, -2);	  
	  $package = $package.chr(hexdec($hexCode));
	  
	  return $package;
	}
	    
	private function getDataFromControl( $address, $requestedLength )
	{
	  if ( $this->GetBuffer( "PortState" ) == ViessControl::COMPORT_READY )
	  {
	    // Clear old data
	    $this->SetBuffer( "ReceiveBuffer", "" );
            $this->SetBuffer( "RequestedData", "" );
	
	    // Calculate package
	    $requestPackage = $this->createPackage( 1, $address, $requestedLength, "" );
	    // send request
	    $this->SetBuffer( "PortState", ViessControl::COMPORT_DATA_REQUESTED ); // to be done before request is send
	    $this->SendDataToParent(json_encode(Array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", 
                                                      "Buffer" => utf8_encode($requestPackage))));	
	    $tryCounter = 10;
	    do {
              sleep(1); // wait 1 second
	      $tryCounter--;	  
	    } while ( $this->GetBuffer( "PortState" ) != ViessControl::COMPORT_READY AND $tryCounter > 0 );
		
	    if ( $this->GetBuffer( "PortState" ) == ViessControl::COMPORT_READY ) {
	      return $this->GetBuffer( "RequestedData" );
	    }
	    else { 
	      return false; 
	    }
	  }
	  else { 
            return false; 
	  }
	}
	    
        //=== Module Prefix Functions ===================================================================================
        /* Own module functions called via the defined prefix ViessControl_* 
        *
        * - ViessControl_identifyHeatingControl($id);
        *
        */
        
        public function IdentifyHeatingControl() {
          /* identify the connected Heating Control */
          
	  $string = file_get_contents("./ControlData/Controls.json");	
	  $ViessmannControls = json_decode($string, true);	
	  $this->sendDebug( "Viess", $ViessmannControls[0][control], 0 );
		
          // Init Communication
          if ( $this->startCommunication() === true ) {
            // Init successful, request Data
	    $result = $this->getDataFromControl( "00F8", 2 );	  
            // End Communication
            $this->endCommunication();
            // return result
            return $result;
	  }
          else { 
            return false; 
	  }
        }
        
    }
?>
