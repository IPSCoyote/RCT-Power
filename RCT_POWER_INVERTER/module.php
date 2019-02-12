<?
  class RCTPowerInverter extends IPSModule {

        public function Create() {
          /* Create is called ONCE on Instance creation and start of IP-Symcon. 
             Status-Variables und Modul-Properties for permanent usage should be created here  */
          parent::Create(); 
		
          // Properties Charger
          $this->RegisterPropertyInteger("InputAPanelCount", 0); 
          $this->RegisterPropertyInteger("InputANominalPowerPerPanel", 0);
          $this->RegisterPropertyInteger("InputBPanelCount", 0); 
          $this->RegisterPropertyInteger("InputBNominalPowerPerPanel", 0);
          $this->RegisterPropertyInteger("UpdateInterval", 0);  
		
          // Timer
          $this->RegisterTimer("RCTPOWERINVERTER_UpdateTimer", 0, 'RCTPOWERINVERTER_UpdateData($_IPS[\'TARGET\']);');
        }
 
        public function ApplyChanges() { 
          /* Called on 'apply changes' in the configuration UI and after creation of the instance */
          parent::ApplyChanges();
		
          // Generate Profiles & Variables
          $this->registerProfiles();
          $this->registerVariables();  
		
          $this->SetReceiveDataFilter(".*018EF6B5-AB94-40C6-AA53-46943E824ACF.*");
	  if ( $this->ReadPropertyInteger("UpdateInterval") >= 10 )
	    $this->SetTimerInterval("RCTPOWERINVERTER_UpdateTimer", $this->ReadPropertyInteger("UpdateInterval")*1000);	
          else
            $this->SetTimerInterval("RCTPOWERINVERTER_UpdateTimer", 0);	  
        }
 
        public function Destroy() {
            //$this->UnregisterTimer("RCTPOWERINVERTER_UpdateTimer");
            // Never delete this line!
            parent::Destroy();
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
	  if ( strlen( $data ) == 8 ) $float = $this->hexTo32Float( $data );
		
	  switch ($address) {
		  case "DB2D69AE": // Actual inverters AC-power [W], Float
			  //$this->sendDebug( "RCTPower", "Actual inverters AC-power [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  case "CF053085": // Phase L1 voltage [V], Float
			  //$this->sendDebug( "RCTPower", "Phase L1 voltage [V]: ".round( $float, 0 )."V", 0 );
	      		  break;
			  
		  case "54B4684E": // Phase L2 voltage [V], Float	
			  //$this->sendDebug( "RCTPower", "Phase L2 voltage [V]: ".number_format( $float, 0 )."V", 0 );
	      		  break;
			  
		  case "2545E22D": // Phase L3 voltage [V], Float	
			  //$this->sendDebug( "RCTPower", "Phase L3 voltage [V]: ".number_format( $float, 0 )."V", 0 );
	      		  break; 
			  
		  case "B55BA2CE": // DC input A voltage [V], Float (by Documentation B298395D!)
			  SetValue($this->GetIDForIdent("DCInputAVoltage"), round( $float, 0 ) ); 
			  //$this->sendDebug( "RCTPower", "DC Input A voltage [V]: ".number_format( $float, 0 )."V", 0 );
	      		  break;
			  
		  case "B0041187": // DC input B voltage [V], Float (by Documentation 5BB8075A)
			  SetValue($this->GetIDForIdent("DCInputBVoltage"), round( $float, 0 ) ); 
			  //$this->sendDebug( "RCTPower", "DC Input B voltage [V]: ".number_format( $float, 0 )."V", 0 );
	      		  break;
			  
		  case "DB11855B": // DC input A power [W], Float
			  SetValue($this->GetIDForIdent("DCInputAPower"), round( $float, 0 ) ); 
			  //$this->sendDebug( "RCTPower", "DC Input A power [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  case "0CB5D21B": // DC input B power [W], Float
			  SetValue($this->GetIDForIdent("DCInputBPower"), round( $float, 0 ) ); 
			  //$this->sendDebug( "RCTPower", "DC Input B power [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  case "B408E40A": // Battery current measured by inverter, low pass filter with Tau = 1s [A], Float	
			  //$this->sendDebug( "RCTPower", "Battery current measured by inverter, low pass filter with Tau = 1s [A]: ".number_format( $float, 0 )."A", 0 );
	      		  break;
		
		  case "A7FA5C5D": // "Battery voltage [V], Float
			  SetValue($this->GetIDForIdent("BatteryVoltage"), round( $float, 0 ) ); 
			  //$this->sendDebug( "RCTPower", "Battery voltage [V]: ".number_format( $float, 0 )."V", 0 );
	      		  break;
			  
		  case "959930BF": // Battery State of Charge (SoC) [0..1], Float
			  SetValue($this->GetIDForIdent("BatterySoC"), $float*100 ) ); 
			  //$this->sendDebug( "RCTPower", "Battery State of Charge: ".number_format( $float*100, 1 )."%", 0 );
	      		  break;
			  
		  case "400F015B": // Battery power (positive if discharge) [W], Float	
			  SetValue($this->GetIDForIdent("BatteryPower"), round( $float, 0 ) ); 
			  //$this->sendDebug( "RCTPower", "Battery power (positive if discharge) [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  case "902AFAFB": // Battery temperature [Grad C], Float
			  SetValue($this->GetIDForIdent("BatteryTemperature"), $float ); 
			  //$this->sendDebug( "RCTPower", "Battery temperature [Grad C]: ".number_format( $float, 1 )."C", 0 );
	      		  break;
			  
		  case "91617C58": // Public grid power (house connection, negative by feed-in) [W], Float
			  SetValue($this->GetIDForIdent("PublicGridPower"), round( $float, 0 ) ); 
			  //$this->sendDebug( "RCTPower", "Public grid power (house connection, negative by feed-in) [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  case "E96F1844": // External power (additional inverters/generators in house internal grid) [W], Float	
			  SetValue($this->GetIDForIdent("ExternalPower"), round( $float, 0 ) ); 
			  //$this->sendDebug( "RCTPower", "External power (additional inverters/generators in house internal grid) [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			 
		  case "BD55905F": // Todays energy [Wh], Float
			  SetValue($this->GetIDForIdent("EnergyToday"), round( $float, 0 ) ); 
			  //$this->sendDebug( "RCTPower", "Todays energy [Wh]: ".number_format( $float, 0 )."Wh", 0 );
	      		  break;
			  
		  case "10970E9D": // This month energy [Wh], Float	
			  SetValue($this->GetIDForIdent("EnergyThisMonth"), round( $float, 0 ) );
			  //$this->sendDebug( "RCTPower", "This month energy [Wh]: ".number_format( $float, 0 )."Wh", 0 );
	      		  break; 
			  
		  case "C0CC81B6": // This year energy [Wh], Float	
			  SetValue($this->GetIDForIdent("EnergyThisYear"), round( $float, 0 ) );
			  //$this->sendDebug( "RCTPower", "This year energy [Wh]: ".number_format( $float, 0 )."Wh", 0 );
	      		  break;
			  
		  case "B1EF67CE": // Total Energy [Wh], Float	
			  SetValue($this->GetIDForIdent("EnergyTotal"), round( $float, 0 ) );
			  //$this->sendDebug( "RCTPower", "Total Energy [Wh]: ".number_format( $float, 0 )."Wh", 0 );
	      		  break;
			  
		  case "FE1AA500": // External Power Limit [0..1], Float	
			  //$this->sendDebug( "RCTPower", "External Power Limit [0..1]: ".number_format( $float*100, 0 )."%", 0 );
	      		  break;
			  
		  case "BD008E29": // External battery power target [W] (positive = discharge), Float	
			  //$this->sendDebug( "RCTPower", "External battery power target [W] (positive = discharge): ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  case "872F380B": // External load demand [W] (positive = feed in / 0=internal ), Float	
			  //$this->sendDebug( "RCTPower", "External load demand [W] (positive = feed in / 0=internal ): ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  //--- NOT DOCUMENTED !!! -------------------------------------------------------------------------
		  case "8B9FF008": // Upper load boundary in %
			  SetValue($this->GetIDForIdent("BatteryUpperSoC"), $float*100 );
			  //$this->sendDebug( "RCTPower", "Upper battery charge level [0..1]: ".number_format( $float*100, 0 )."%", 0 );
	      		  break;
			  
		  case "4BC0F974": // gross battery capacity kwh
			  SetValue($this->GetIDForIdent("BatteryGrossCapacity"), $float/1000 );
			  //$this->sendDebug( "RCTPower", "Gross Battery Capacity [kwh]: ".number_format( $float/1000, 2 )."kwh", 0 );
	      		  break;
			  
		  case "1AC87AA0": // Current House power consumption
			  SetValue($this->GetIDForIdent("HousePowerCurrent"), round( $float, 0 ) );
			  //$this->sendDebug( "RCTPower", "Current House Power Consumption [W]: ".number_format( $float, 0 )."W", 0 );
	      		  break;
			  
		  case "37F9D5CA": // Bit-coded fault word 0
			  if ( $data != '00000000' ) { $error = true; }
			  break;
		  case "234B4736": // Bit-coded fault word 1
			  if ( $data != '00000000' ) { $error = true; }
			  break;
		  case "3B7FCD47": // Bit-coded fault word 2
			  if ( $data != '00000000' ) { $error = true; }
			  break;
		  case "7F813D73": // Bit-coded fault word 3
			  if ( $data != '00000000' ) { $error = true; }
			  break;
			  
		  //--- Ignore -------------------------------------------------------------------------------------
		  case "EBC62737": // Inverter Description 
			  break;	  
			  
		  //--- Default Handling ---------------------------------------------------------------------------
		  default:         // Unknown response
			  $this->sendDebug( "RCTPower", "Unkown Response Address ".$address." with data ".$data." (as Float ".number_format( $float, 2 ).")", 0 );
			  
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
          $bin = str_pad(base_convert($strHex, 16, 2), 32, "0", STR_PAD_LEFT); 
          $sign = $bin[0]; 
          $v = hexdec($strHex);
          $x = ($v & ((1 << 23) - 1)) + (1 << 23) * ($v >> 31 | 1);
          $exp = ($v >> 23 & 0xFF) - 127;
          return $x * pow(2, $exp - 23) * ($sign ? -1 : 1); ;
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
          // DC input A voltage [V] (by Documentation B298395D)
          $this->requestData( "B55BA2CE", 4 );
          usleep( 100000 );
          // DC input B voltage [V] (by Documentation 5BB8075A)
          $this->requestData( "B0041187", 4 );
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
	  // Bit-coded fault word 0-3	
          $this->requestData( "37F9D5CA", 4 );
	  usleep( 100000 );
	  $this->requestData( "234B4736", 4 );
	  usleep( 100000 );
	  $this->requestData( "3B7FCD47", 4 );
	  usleep( 100000 );
	  $this->requestData( "7F813D73", 4);
		
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
	  
        //=== Module Register Functions =============================================================================
        
	 protected function registerProfiles() {
            // Generate Variable Profiles          
		 
	    //--- Boolean (Type 0)
		 
	    //--- Integer (Type 1)
            if ( !IPS_VariableProfileExists('RCTPOWER_Ampere') ) {
                IPS_CreateVariableProfile('RCTPOWER_Ampere', 1 );
                IPS_SetVariableProfileDigits('RCTPOWER_Ampere', 0 );
                IPS_SetVariableProfileIcon('RCTPOWER_Ampere', 'Electricity' );
                IPS_SetVariableProfileText('RCTPOWER_Ampere', "", " A" );
            }
		 
            if ( !IPS_VariableProfileExists('RCTPOWER_Voltage') ) {
                IPS_CreateVariableProfile('RCTPOWER_Voltage', 1 );
                IPS_SetVariableProfileDigits('RCTPOWER_Voltage', 0 );
                IPS_SetVariableProfileIcon('RCTPOWER_Voltage', 'Electricity' );
                IPS_SetVariableProfileText('RCTPOWER_Voltage', "", " V" );
            }   
            
            if ( !IPS_VariableProfileExists('RCTPOWER_Power') ) {
                IPS_CreateVariableProfile('RCTPOWER_Power', 1 );
                IPS_SetVariableProfileDigits('RCTPOWER_Power', 0 );
                IPS_SetVariableProfileIcon('RCTPOWER_Power', 'Electricity' );
                IPS_SetVariableProfileText('RCTPOWER_Power', "", " W" );
            }   
		 
            if ( !IPS_VariableProfileExists('RCTPOWER_Energy') ) {
                IPS_CreateVariableProfile('RCTPOWER_Energy', 1 );
                IPS_SetVariableProfileDigits('RCTPOWER_Energy', 0 );
                IPS_SetVariableProfileIcon('RCTPOWER_Energy', 'Electricity' );
                IPS_SetVariableProfileText('RCTPOWER_Energy', "", " Wh" );
            }   
		 
            //--- Float (Type 2)
            if ( !IPS_VariableProfileExists('RCTPOWER_Capacity.2') ) {
                IPS_CreateVariableProfile('RCTPOWER_Capacity.2', 2 );
                IPS_SetVariableProfileDigits('RCTPOWER_Capacity.2', 2 );
                IPS_SetVariableProfileIcon('RCTPOWER_Capacity.2', 'Battery' );
                IPS_SetVariableProfileText('RCTPOWER_Capacity.2', "", " kwh" );
            }
            
            if ( !IPS_VariableProfileExists('RCTPOWER_SoC.1') ) {
                IPS_CreateVariableProfile('RCTPOWER_SoC.1', 2 );
                IPS_SetVariableProfileDigits('RCTPOWER_SoC.1', 2 );
                IPS_SetVariableProfileIcon('RCTPOWER_SoC.1', 'Battery' );
                IPS_SetVariableProfileText('RCTPOWER_SoC.1', "", " %" );
            }
		 
	    //--- String (Type 3)
		 
        }
        
        protected function registerVariables() {
		
          $this->RegisterVariableInteger("DCInputAVoltage", "Eingang A Spannung","RCTPOWER_Voltage",1);
          $this->RegisterVariableInteger("DCInputAPower",   "Eingang A Leistung","RCTPOWER_Power",2);
          $this->RegisterVariableInteger("DCInputBVoltage", "Eingang B Spannung","RCTPOWER_Voltage",5);
          $this->RegisterVariableInteger("DCInputAPower",   "Eingang B Leistung","RCTPOWER_Power",6);
		
		
          $this->RegisterVariableInteger("BatteryVoltage",     "Batterie Spannung","RCTPOWER_Voltage",20);
	  $this->RegisterVariableInteger("BatteryPower",       "Batterie Spannung","RCTPOWER_Power",21);	
	  $this->RegisterVariableFloat("BatteryGrossCapacity", "Batterie Brutto-Kapazität","RCTPOWER_Capacity.2",22);
	  $this->RegisterVariableFloat("BatterySoC",           "Batterie Ladestand","~Valve.F",23);
	  $this->RegisterVariableFloat("BatteryUpperSoC",      "Batterie Ladegrenze","~Valve.F",24);	
	  $this->RegisterVariableFloat("BatteryTemperature",   "Batterie Temperatur","~Temperature",25);	
		
	  $this->RegisterVariableInteger("ExternalPower",  "Generator Leistung","RCTPOWER_Power",30); 
		
          $this->RegisterVariableInteger("PublicGridPower","Aussennetz Leistung","RCTPOWER_Power",40); 
		
          $this->RegisterVariableInteger("EnergyToday",     "PV Energie Tag","RCTPOWER_Energy",50);
	  $this->RegisterVariableInteger("EnergyThisMonth", "PV Energie Monat","RCTPOWER_Energy",51);
          $this->RegisterVariableInteger("EnergyThisYear",  "PV Energie Jahr","RCTPOWER_Energy",52);
          $this->RegisterVariableInteger("EnergyTotal",     "PV Energie Gesamt","RCTPOWER_Energy",53);

          $this->RegisterVariableInteger("HousePowerCurrent","Haus Leistung","RCTPOWER_Power",60);
	
	  $this->RegisterVariableBoolean("Errorstatus",      "Fehlerstatus","~Alert",70);
        }
	  
	  
    }
?>
