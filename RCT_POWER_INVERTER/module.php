<?
  class RCTPowerInverter extends IPSModule {

        public function Create() {
          /* Create is called ONCE on Instance creation and start of IP-Symcon.
             Status-Variables und Modul-Properties for permanent usage should be created here  */
          parent::Create(); 
        }
 
        public function ApplyChanges() {
          /* Called on 'apply changes' in the configuration UI and after creation of the instance */
          parent::ApplyChanges();
		
          $this->SetReceiveDataFilter(".*018EF6B5-AB94-40C6-AA53-46943E824ACF.*");
        }
 
        //=== Module Functions =========================================================================================
        public function ReceiveData($JSONString) {
          // Receive data from serial port I/O
          $data = json_decode($JSONString);	
		  
	  $this->sendDebug( "RCTPower", "Received: ".strlen( utf8_decode( $data->Buffer ) ), 0 );
	  $receivedData = $this->GetBuffer( "ReceiveBuffer" );        // Get previously received data
	  $receivedData = $receivedData.utf8_decode( $data->Buffer ); // Append newly received data
	  $this->SetBuffer( "ReceiveBuffer", $receivedData );         // Store fully received data to buffer
		
	  if ( strlen( $receivedData ) >= $this->GetBuffer( "RCT_ExpectedLength" ) and $this->GetBuffer( "RCT_ExpectedLength" ) > 0) {
		  
            // Process data
	    $response = "";
	    for ( $x=0; $x<strlen($receivedData); $x++ ) {
	      $hex = strtoupper( dechex( ord($receivedData[$x]) ) );
              if ( strlen( $hex ) == 1 ) $hex = '0'.$hex;
	      $response = $response.$hex;
	    }	  
		
	    $expectedLength = $this->GetBuffer( "RCT_ExpectedLength" );
	    $this->SetBuffer( "RCT_Response", substr( $response,0, $expectedLength*2 ));
            $this->GetBuffer( "ReceiveBuffer", "" );
	    $this->SetBuffer( "RCT_ExpectedLength", 0 );
		  
          } 
          return true;
        }
        
        //=== Private Functions for Communication handling with Vitotronic ==============================================
        private function startCommunication() {
		
	  $this->sendDebug( "RCTPower", "startCommunication", 0 );	
		
	  ///--- HANDLE Connection --------------------------------------------------------------------------------------	
          // check Socket Connection (parent)
          $SocketConnectionInstanceID = IPS_GetInstance($this->InstanceID)['ConnectionID']; 
          if ( $SocketConnectionInstanceID == 0 ) return false; // No parent assigned  
            
          $ModuleID = IPS_GetInstance($SocketConnectionInstanceID)['ModuleInfo']['ModuleID'];      
          if ( $ModuleID !== '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}' ) return false; // wrong parent type
		
          // check connection status
		
          ///--- INIT CONNECTION ----------------------------------------------------------------------------------------
          // send command to RCT Power Inverter		
	  $this->sendDebug( "RCTPower", $this->requestData( "400F015B", 4, "FLOAT" ), 0);

        } 
        
        private function endCommunication() {
	  return true;			
        }
       
        //=== Tool Functions ============================================================================================
	function requestData( string $command, int $length, string $format ) {
	  // does not work for string requests!!!
          // build command		
	  $hexlength = strtoupper( dechex($length) );
          if ( strlen( $hexlength ) == 1 ) $hexlength = '0'.$hexlength;
	  $command = "01".$hexlength.$command;
	  $command = "2B".$command.$this->calcCRC( $command );
	  $hexCommand = "";
	  for( $x=0; $x<strlen($command)/2;$x++)
	    $hexCommand = $hexCommand.chr(hexdec(substr( $command, $x*2, 2 )));
				 
	  $expectedLength = 9 + $length; // 
		
	  // clear expected Response and send Data to Parent...
	  $this->SetBuffer("ReceiveBuffer", "" );
	  $this->SetBuffer("RCT_Response", "");
	  $this->SetBuffer("RCT_ExpectedLength", $expectedLength );
	  $this->SendDataToParent(json_encode(Array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", 
	  					    "Buffer" => utf8_encode($hexCommand) )));
	  // and wait for response
	  while ( $this->GetBuffer( "RCT_Response" ) == "" ) usleep( 250000 ); // wait a 1/4 second
	  
	  $response = $this->GetBuffer( "RCT_Response" );
		
	  // check responsonse CRC
	  $CRC = $this->calcCRC( substr( $response, 2, strlen( $response ) - 6 ) );
		
	  if ( $CRC == substr( $response, strlen( $response ) - 4, 4 ) ) {
	    // Response is correct, so return it formatted (if format known)
	    $result = substr( $response, 14, $length*2 );
	    switch ( $format ) {
              case 'FLOAT': 
	        $float = 0.0;
	        $float = hexTo32Float($result);
	        return $float;
                break;
              default: return $result;	    	    
	    }
	  }
	  else
	    return false;		
	}  
	  
	function calcCRC( string $command ) {
          // Command with an odd byte length (add 0x00 to make odd!) without(!) start byte (0x2B)
          $commandLength = strlen( $command ) / 2;
          $crc = 0xFFFF; 	
          for ( $x = 0; $x <$commandLength; $x++ ) {
            $b = hexdec( substr( $command, $x*2, 2 ) );
            for( $i = 0; $i<8; $i++ ) {
              $bit = (($b >> (7 - $i) & 1) == 1);
	      $c15 = ((($crc >> 15) & 1) == 1); 
	      $crc <<= 1;
              if ($c15 ^ $bit) $crc ^= 0x1021;
            }
            $crc &= 0xffff;
          }  
          $crc = strtoupper( dechex( $crc ) );
	  if ( strlen( $crc ) == 3 ) $crc = '0'.$crc;
	  return $crc;
        }    
	  
	  
        function hexTo32Float($strHex) {
          $v = hexdec($strHex);
          $x = ($v & ((1 << 23) - 1)) + (1 << 23) * ($v >> 31 | 1);
          $exp = ($v >> 23 & 0xFF) - 127;
          return $x * pow(2, $exp - 23);
        }
		
		
        //=== Module Prefix Functions ===================================================================================
        /* Own module functions called via the defined prefix ViessControl_* 
        *
        * - ViessControl_identifyHeatingControl($id);
        *
        */
        
        public function UpdateData() {
          /* get Data from RCT Power Inverter */
          
          // Init Communication
          if ( $this->startCommunication() === true ) {
            // Init successful, request Data
  
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
