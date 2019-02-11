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
	  $this->sendDebug( "RCTPower", "ReceiveData", 0 );
          $data = json_decode($JSONString);	
	  $responses = explode( utf8_decode( $data->Buffer ), chr(43) ); // split on 0x2B ('+' + '05' = Response)
	  $result = utf8_decode( $data->Buffer );
	  $this->sendDebug( "RCTPower", "Test: ".strlen($result), 0 );
	  $this->sendDebug( "RCTPower", "Received Datarecord: ".count($responses), 0 );
      
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
	  $this->SendDataToParent(json_encode(Array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", 
	  					    "Buffer" => utf8_encode($hexCommand) )));	
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
		
	  ///--- HANDLE Connection --------------------------------------------------------------------------------------	
          // check Socket Connection (parent)
          $SocketConnectionInstanceID = IPS_GetInstance($this->InstanceID)['ConnectionID']; 
          if ( $SocketConnectionInstanceID == 0 ) return false; // No parent assigned  
            
          $ModuleID = IPS_GetInstance($SocketConnectionInstanceID)['ModuleInfo']['ModuleID'];      
          if ( $ModuleID !== '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}' ) return false; // wrong parent type
		
          // Init Communication -----------------------------------------------------------------------------------------
		
	  // Request Data -----------------------------------------------------------------------------------------------	
//	 $this->sendDebug( "RCTPower", "Actual inverters AC-power [W]: ".$this->requestData( "DB2D69AE", 4, "FLOAT" ), 0);
	  $this->sendDebug( "RCTPower", "Phase L1 voltage [V]: ".$this->requestData( "CF053085", 4, "FLOAT" ), 0);
		usleep( 100000 );
	  $this->sendDebug( "RCTPower", "Phase L2 voltage [V]: ".$this->requestData( "54B4684E", 4, "FLOAT" ), 0);
		usleep( 100000 );
//	  $this->sendDebug( "RCTPower", "Phase L3 voltage [V]: ".$this->requestData( "2545E22D", 4, "FLOAT" ), 0);
		usleep( 100000 );
	  $this->sendDebug( "RCTPower", "DC input A voltage [V]: ".$this->requestData( "B298395D", 4, "FLOAT" ), 0);
		usleep( 100000 );
	  $this->sendDebug( "RCTPower", "DC input B voltage [V]: ".$this->requestData( "5BB8075A", 4, "FLOAT" ), 0);
		usleep( 100000 );
	  $this->sendDebug( "RCTPower", "DC input A power [W]: ".$this->requestData( "DB11855B", 4, "FLOAT" ), 0);
		usleep( 100000 );
          $this->sendDebug( "RCTPower", "DC input B power [W]: ".$this->requestData( "0CB5D21B", 4, "FLOAT" ), 0);
		usleep( 100000 );
//         $this->sendDebug( "RCTPower", "Battery current measured by inverter, low pass filter with Tau = 1s [A]: ".$this->requestData( "B408E40A", 4, "FLOAT" ), 0);
          $this->sendDebug( "RCTPower", "Battery voltage [V]: ".$this->requestData( "A7FA5C5D", 4, "FLOAT" ), 0);
		usleep( 100000 );
          $this->sendDebug( "RCTPower", "Battery State of Charge (SoC) [0..1]: ".$this->requestData( "959930BF", 4, "FLOAT" ), 0);		
                usleep( 100000 );
	  $this->sendDebug( "RCTPower", "Battery power (positive if discharge) [W]: ".$this->requestData( "400F015B", 4, "FLOAT" ), 0);
		usleep( 100000 );
          $this->sendDebug( "RCTPower", "Battery temperature [°C]: ".$this->requestData( "902AFAFB", 4, "FLOAT" ), 0);	
		usleep( 100000 );
          $this->sendDebug( "RCTPower", "Public grid power (house connection, negative by feed-in) [W]: ".$this->requestData( "91617C58", 4, "FLOAT" ), 0);
		usleep( 100000 );
          $this->sendDebug( "RCTPower", "External power (additional inverters/generators in house internal grid) [W]: ".$this->requestData( "E96F1844", 4, "FLOAT" ), 0);
		usleep( 100000 );
          $this->sendDebug( "RCTPower", "Todays energy [Wh]: ".$this->requestData( "BD55905F", 4, "FLOAT" ), 0);	
		usleep( 100000 );
          $this->sendDebug( "RCTPower", "This month energy [Wh]: ".$this->requestData( "10970E9D", 4, "FLOAT" ), 0);	
		usleep( 100000 );
          $this->sendDebug( "RCTPower", "This year energy [Wh]: ".$this->requestData( "C0CC81B6", 4, "FLOAT" ), 0);	
		usleep( 100000 );
          $this->sendDebug( "RCTPower", "Total Energy [Wh]: ".$this->requestData( "B1EF67CE", 4, "FLOAT" ), 0);	
		usleep( 100000 );
          $this->sendDebug( "RCTPower", "External Power Limit [0..1]: ".$this->requestData( "FE1AA500", 4, "FLOAT" ), 0);
		usleep( 100000 );
          $this->sendDebug( "RCTPower", "External battery power target [W] (positive = discharge): ".$this->requestData( "BD008E29", 4, "FLOAT" ), 0);	
		usleep( 100000 );
          $this->sendDebug( "RCTPower", "External load demand [W] (positive = feed in / 0=internal: ".$this->requestData( "872F380B", 4, "FLOAT" ), 0);	
		usleep( 100000 );
		
		
		// return result
          return true;
        }
        
    }
?>
