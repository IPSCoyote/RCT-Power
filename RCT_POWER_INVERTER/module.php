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
	  $FullResponse = utf8_decode( $data->Buffer );
	  $SingleResponses = explode( chr(43), $FullResponse ); // split on 0x2B 
	  for ($x=1; $x<count($SingleResponses); $x++) {  
            if ( ord( $SingleResponses[$x][1] ) + 4 == strlen( $SingleResponses[$x] ) ) {
	      // lenght of response package is correct, so check CRC
	      // first convert into 0xYY format
              $response = "";
	      for ( $y=0; $y<strlen($SingleResponses[$x]); $y++ ) {
	        $hex = strtoupper( dechex( ord($SingleResponses[$x][$y]) ) );
                if ( strlen( $hex ) == 1 ) $hex = '0'.$hex;
	        $response = $response.$hex;
	      }	     
	      $CRC = $this->calcCRC( substr( $response,0,ord( $SingleResponses[$x][1] )*2+4 ));
	      if ( $CRC == substr( $response, -4 ) )
		// CRC is also ok, so analyze the response
	        $this->analyzeResponse( substr( $response, 4, 8 ), substr( $response, 12, ord( $SingleResponses[$x][1] )*2-8) );
	    }
	  }
          return true;
        }
       
        //=== Tool Functions ============================================================================================
	function analyzeResponse( string $address, string $data ) {
		
	  // precalculation
	  $float = 0.0;
	  if ( strlen( $data ) == 8 ) $float = round( $this->hexTo32Float( $data ), 2 );
		
	  switch ($address) {
		  case "DB2D69AE": // Actual inverters AC-power [W], Float
			  $this->sendDebug( "RCTPower", "Actual inverters AC-power [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  case "CF053085": // Phase L1 voltage [V], Float
			  $this->sendDebug( "RCTPower", "Phase L1 voltage [V]: ".round( $float, 0 )."V", 0 );
	      		  break;
			  
		  case "54B4684E": // Phase L2 voltage [V], Float	
			  $this->sendDebug( "RCTPower", "Phase L2 voltage [V]: ".number_format( $float, 0 )."V", 0 );
	      		  break;
			  
		  case "2545E22D": // Phase L3 voltage [V], Float	
			  $this->sendDebug( "RCTPower", "Phase L3 voltage [V]: ".number_format( $float, 0 )."V", 0 );
	      		  break; 
			  
		  case "B298395D": // DC input A voltage [V], Float	
			  $this->sendDebug( "RCTPower", "DC input A voltage [V]: ".number_format( $float, 0 )."V", 0 );
	      		  break;
			  
		  case "5BB8075A": // DC input B voltage [V], Float	
			  $this->sendDebug( "RCTPower", "DC input B voltage [V]: ".number_format( $float, 0 )."V", 0 );
	      		  break;
			  
		  case "DB11855B": // DC input A power [W], Float	
			  $this->sendDebug( "RCTPower", "DC input A power [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  case "0CB5D21B": // DC input B power [W], Float	
			  $this->sendDebug( "RCTPower", "DC input B power [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  case "B408E40A": // Battery current measured by inverter, low pass filter with Tau = 1s [A], Float	
			  $this->sendDebug( "RCTPower", "Battery current measured by inverter, low pass filter with Tau = 1s [A]: ".number_format( $float, 0 )."A", 0 );
	      		  break;
		
		  case "A7FA5C5D": // "Battery voltage [V], Float	
			  $this->sendDebug( "RCTPower", "Battery voltage [V]: ".number_format( $float, 0 )."V", 0 );
	      		  break;
			  
		  case "959930BF": // Battery State of Charge (SoC) [0..1], Float
			  $this->sendDebug( "RCTPower", "Battery State of Charge: ".number_format( $float*100, 0 )."%", 0 );
	      		  break;
			  
		  case "400F015B": // Battery power (positive if discharge) [W], Float	
			  $this->sendDebug( "RCTPower", "Battery power (positive if discharge) [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  case "902AFAFB": // Battery temperature [Grad C], Float	
			  $this->sendDebug( "RCTPower", "Battery temperature [Grad C]: ".number_format( $float, 1 )."C", 0 );
	      		  break;
			  
		  case "91617C58": // Public grid power (house connection, negative by feed-in) [W], Float	
			  $this->sendDebug( "RCTPower", "Public grid power (house connection, negative by feed-in) [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  case "E96F1844": // External power (additional inverters/generators in house internal grid) [W], Float	
			  $this->sendDebug( "RCTPower", "External power (additional inverters/generators in house internal grid) [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			 
		  case "BD55905F": // Todays energy [Wh], Float	
			  $this->sendDebug( "RCTPower", "Todays energy [Wh]: ".number_format( $float, 0 )."Wh", 0 );
	      		  break;
			  
		  case "10970E9D": // This month energy [Wh], Float	
			  $this->sendDebug( "RCTPower", "This month energy [Wh]: ".number_format( $float, 0 )."Wh", 0 );
	      		  break;
			  
		  case "C0CC81B6": // This year energy [Wh], Float	
			  $this->sendDebug( "RCTPower", "This year energy [Wh]: ".number_format( $float, 0 )."Wh", 0 );
	      		  break;
			  
		  case "B1EF67CE": // Total Energy [Wh], Float	
			  $this->sendDebug( "RCTPower", "Total Energy [Wh]: ".number_format( $float, 0 )."Wh", 0 );
	      		  break;
			  
		  case "FE1AA500": // External Power Limit [0..1], Float	
			  $this->sendDebug( "RCTPower", "External Power Limit [0..1]: ".number_format( $float*100, 0 )."%", 0 );
	      		  break;
			  
		  case "BD008E29": // External battery power target [W] (positive = discharge), Float	
			  $this->sendDebug( "RCTPower", "External battery power target [W] (positive = discharge): ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  case "872F380B": // External load demand [W] (positive = feed in / 0=internal ), Float	
			  $this->sendDebug( "RCTPower", "External load demand [W] (positive = feed in / 0=internal ): ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  //--- NOT DOCUMENTED !!! -------------------------------------------------------------------------
		  case "8B9FF008": // Upper load boundary in %
			  $this->sendDebug( "RCTPower", "Upper battery charge level [0..1]: ".number_format( $float*100, 0 )."%", 0 );
	      		  break;
			  
		  case "4BC0F974": // gross battery capacity kwh
			  $this->sendDebug( "RCTPower", "Gross Battery Capacity [kwh]: ".number_format( $float, 0 )."kwh", 0 );
	      		  break;
			  
		  case "1AC87AA0": // Current House power consumption
			  $this->sendDebug( "RCTPower", "Current House Power Consumption [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  default:         // Unknown response
			  $this->sendDebug( "RCTPower", "Unkown Response Address ".$address." with data ".$data, 0 );
			  
	  }
		
	}
	  
	  
	function requestData( string $command, int $length ) {
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
        /* Own module functions called via the defined prefix RCTPOWERINVERTER_* 
        *
        * - RCTPOWERINVERTER_*($id);
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
          // Actual inverters AC-power [W]
	  $this->requestData( "DB2D69AE", 4 );
          usleep( 100000 );
          // Phase L1 voltage [V]
	  $this->requestData( "CF053085", 4 );
	  usleep( 100000 );
          // Phase L2 voltage [V]
	  $this->requestData( "54B4684E", 4 );
	  usleep( 100000 );
          // Phase L3 voltage [V]
	  $this->requestData( "2545E22D", 4 );
	  usleep( 100000 );
          // DC input A voltage [V]
          $this->requestData( "B298395D", 4 );
          usleep( 100000 );
          // DC input B voltage [V]
          $this->requestData( "5BB8075A", 4 );
          usleep( 100000 );
          // DC input A power [W]
          $this->requestData( "DB11855B", 4 );
          usleep( 100000 );
          // DC input B power [W]
          $this->requestData( "0CB5D21B", 4 );
          usleep( 100000 );
          //// Battery current measured by inverter, low pass filter with Tau = 1s [A]
	  // $this->requestData( "B408E40A", 4 );
          usleep( 10000 );
          // Battery voltage [V]
          $this->requestData( "A7FA5C5D", 4 );
          usleep( 100000 );
          // Battery State of Charge (SoC) [0..1]
          $this->requestData( "959930BF", 4 );	
          usleep( 100000 );
          // Battery power (positive if discharge) [W]
          $this->requestData( "400F015B", 4 );
          usleep( 100000 );
          // Battery temperature [°C]
          $this->requestData( "902AFAFB", 4 );
          usleep( 100000 );
          // Public grid power (house connection, negative by feed-in) [W]
          $this->requestData( "91617C58", 4 );
          usleep( 100000 );
          // External power (additional inverters/generators in house internal grid) [W]
          $this->requestData( "E96F1844", 4 );
          usleep( 100000 );
          // Todays energy [Wh]
          $this->requestData( "BD55905F", 4 );
          usleep( 100000 );
          // This month energy [Wh]
          $this->requestData( "10970E9D", 4 );
          usleep( 100000 );
          // This year energy [Wh]
          $this->requestData( "C0CC81B6", 4 );
          usleep( 100000 );
          // Total Energy [Wh]
          $this->requestData( "B1EF67CE", 4 );
          usleep( 100000 );
          // External Power Limit [0..1]
          $this->requestData( "FE1AA500", 4 );
          usleep( 100000 );
          // External battery power target [W] (positive = discharge)
          $this->requestData( "BD008E29", 4 );
          usleep( 100000 );
          // External load demand [W] (positive = feed in / 0=internal
          $this->requestData( "872F380B", 4 );	
          usleep( 100000 );
		
          //--- NOT DOCUMENTED -------------------------------------------------------------------------
	  // Upper load boundary in %
	  $this->requestData( "8B9FF008", 4 );
          usleep( 100000 );
		
	  // gross battery capacity kwh
	  $this->requestData( "4BC0F974", 4 );
          usleep( 100000 );
		
	  // Current House power consumption
	  $this->requestData( "1AC87AA0", 4 );
	  usleep( 100000 );
		
	  // return result
          return true;
        }
        
    }
?>
