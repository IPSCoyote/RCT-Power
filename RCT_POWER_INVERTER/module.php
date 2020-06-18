<?php
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
	  $this->RegisterPropertyInteger("InstalledBatteryModules", 0); 
	  $this->RegisterPropertyInteger("LowerSoCLevel", 0);
          $this->RegisterPropertyInteger("UpdateInterval", 0);
	  $this->RegisterPropertyBoolean("DebugSwitch", false );
		
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
		
	  // Set Battery Gross Capacity based on installed Battery Modules
	  switch ( $this->ReadPropertyInteger("InstalledBatteryModules") ) {
	    case 0: SetValue($this->GetIDForIdent("BatteryGrossCapacity"), 0 );
	  	    break;
	    case 2: SetValue($this->GetIDForIdent("BatteryGrossCapacity"), 3.8 );
	  	    break;	
	    case 3: SetValue($this->GetIDForIdent("BatteryGrossCapacity"), 5.7 );
	  	    break;	
	    case 4: SetValue($this->GetIDForIdent("BatteryGrossCapacity"), 7.6 );
	  	    break;	
	    case 5: SetValue($this->GetIDForIdent("BatteryGrossCapacity"), 9.6 );
	  	    break;	
	    case 6: SetValue($this->GetIDForIdent("BatteryGrossCapacity"), 11.5 );
	  	    break;	
	  }
		
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
		
	  $Debugging = $this->ReadPropertyBoolean ("DebugSwitch");	
		
	  // precalculation
	  $float = 0.0;
	  if ( strlen( $data ) == 8 ) $float = $this->hexTo32Float( $data );
		
	  // Debug output
	  if ( $Debugging == true ) {
	    $this->sendDebug( "RCTPower", "Address ".$address." with data ".$data." (as Float ".number_format( $float, 2 ).")", 0 );	
	  }
		
	  switch ($address) {
		  case "DB2D69AE": // Actual inverters AC-power [W], Float
			 break;
			  
		  case "CF053085": // Phase L1 voltage [V], Float
			  break;
			  
		  case "54B4684E": // Phase L2 voltage [V], Float	
			  break;
			  
		  case "2545E22D": // Phase L3 voltage [V], Float	
			  break; 
			  
		  case "B55BA2CE": // DC input A voltage [V], Float (by Documentation B298395D!)
			  SetValue($this->GetIDForIdent("DCInputAVoltage"), round( $float, 0 ) ); 
			  break;
			  
		  case "B0041187": // DC input B voltage [V], Float (by Documentation 5BB8075A)
			  SetValue($this->GetIDForIdent("DCInputBVoltage"), round( $float, 0 ) ); 
			  break;
			  
		  case "DB11855B": // DC input A power [W], Float
			  SetValue($this->GetIDForIdent("DCInputAPower"), round( $float, 0 ) ); 
			  $PanelMaxA = $this->ReadPropertyInteger("InputAPanelCount") * $this->ReadPropertyInteger("InputANominalPowerPerPanel" );
			  $PanelMaxB = $this->ReadPropertyInteger("InputBPanelCount") * $this->ReadPropertyInteger("InputBNominalPowerPerPanel" );
			  if ( $PanelMaxA > 0 ) {
			    // Calculate Input A Utilization
			    $Utilization = $float / $PanelMaxA * 100;	  
			    SetValue($this->GetIDForIdent("DCInputAUtilization"), round( $Utilization, 1 ) );   
			    if ( $PanelMaxB == 0 )
			      SetValue($this->GetIDForIdent("DCInputUtilization"), round( $Utilization, 1 ) );   
			  }
			  $PanelMaxTotal = $PanelMaxA + $PanelMaxB;
			  if ( $PanelMaxTotal == 0 ) 
		            SetValue($this->GetIDForIdent("DCInputUtilization"), 0 );   
			  else {
			    // Calculate Total Input Power and Utilization
			    $TotalPowerInput = GetValueInteger($this->GetIDForIdent("DCInputAPower")) + GetValueInteger($this->GetIDForIdent("DCInputBPower"));
			    $Utilization = $TotalPowerInput / $PanelMaxTotal * 100;	
			    SetValue($this->GetIDForIdent("DCInputUtilization"), round( $Utilization, 1 ) ); 
			    SetValue($this->GetIDForIdent("DCInputPower"), round( $TotalPowerInput, 0 ) );
			  }
			  
			  break;
			  
		  case "0CB5D21B": // DC input B power [W], Float
			  SetValue($this->GetIDForIdent("DCInputBPower"), round( $float, 0 ) ); 
			  $PanelMaxA = $this->ReadPropertyInteger("InputAPanelCount") * $this->ReadPropertyInteger("InputANominalPowerPerPanel" );
			  $PanelMaxB = $this->ReadPropertyInteger("InputBPanelCount") * $this->ReadPropertyInteger("InputBNominalPowerPerPanel" );
			  if ( $PanelMaxB > 0 ) {
			    // Calculate Input B Utilization
			    $Utilization = $float / $PanelMaxB * 100;	  
			    SetValue($this->GetIDForIdent("DCInputBUtilization"), round( $Utilization, 1 ) ); 
			    if ( $PanelMaxA == 0 )
			      SetValue($this->GetIDForIdent("DCInputUtilization"), round( $Utilization, 1 ) );     
			  }
			  $PanelMaxTotal = $PanelMaxA + $PanelMaxB;
			  if ( $PanelMaxTotal == 0 ) 
		            SetValue($this->GetIDForIdent("DCInputUtilization"), 0 );   
			  else {
			    // Calculate Total Input Power and Utilization
			    $TotalPowerInput = GetValueInteger($this->GetIDForIdent("DCInputAPower")) + GetValueInteger($this->GetIDForIdent("DCInputBPower"));
		            $Utilization = $TotalPowerInput / $PanelMaxTotal * 100;	
			    SetValue($this->GetIDForIdent("DCInputUtilization"), round( $Utilization, 1 ) ); 
			    SetValue($this->GetIDForIdent("DCInputPower"), round( $TotalPowerInput, 0 ) );
			  }
			  
			  break;
			  
		  case "B408E40A": // Battery current measured by inverter, low pass filter with Tau = 1s [A], Float	
			  break;
		
		  case "A7FA5C5D": // "Battery voltage [V], Float
			  SetValue($this->GetIDForIdent("BatteryVoltage"), round( $float, 0 ) ); 
			  break;
			  
		  case "959930BF": // Battery State of Charge (SoC) [0..1], Float
			  SetValue($this->GetIDForIdent("BatterySoC"), round( $float*100, 1 ) ); 
			  break;
			  
		  case "400F015B": // Battery power (positive if discharge) [W], Float	
			  SetValue($this->GetIDForIdent("BatteryPower"), round( $float, 0 ) ); 
			  break;
			  
		  case "902AFAFB": // Battery temperature [Grad C], Float
			  SetValue($this->GetIDForIdent("BatteryTemperature"), round( $float, 1) ); 
			  break;
			  
		  case "91617C58": // Public grid power (house connection, negative by feed-in) [W], Float
			  SetValue($this->GetIDForIdent("PublicGridPower"), round( $float, 0 ) ); 
			  break;
			  
		  case "E96F1844": // External power (additional inverters/generators in house internal grid) [W], Float	
			  SetValue($this->GetIDForIdent("ExternalPower"), round( $float, 0 ) ); 
			  break;
			
		  //--- Energy Today		     
		  case "BD55905F": // Todays energy [Wh], Float
			  SetValue($this->GetIDForIdent("EnergyDayEnergy"), round( $float, 0 ) ); 
		          // Calculate FeedInLevel etc.
			  $FeedInLevel = 0.0;
			  if ( ( GetValueInteger($this->GetIDForIdent("EnergyDayGridFeedIn") ) > 0 ) and ( GetValueInteger($this->GetIDForIdent("EnergyDayEnergy") ) > 0 ) )
			    $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyDayGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyDayEnergy")) * 100;	
			  SetValue($this->GetIDForIdent("EnergyDayGridFeedInLevel"), round( $FeedInLevel, 0 ) ); 
			  $SelfConsumptionLevel = 0.0;  
			  if ( GetValueInteger($this->GetIDForIdent("EnergyDayEnergy")) > 0 )
			    $SelfConsumptionLevel = 100 - $FeedInLevel;
                          SetValue($this->GetIDForIdent("EnergyDaySelfConsumptionLevel"), round( $SelfConsumptionLevel, 0 ) );
	      		  break;  
		  case "2AE703F2": // Tagesenergie Ertrag Input A in Wh
			  $this->sendDebug( "RCTPower", "Tages Ertrag Input A: ".number_format( $float, 0 )."W", 0 );
			  SetValue($this->GetIDForIdent("EnergyDayPVEarningInputA"), round( $float, 0 ) ); 
			  $AB = GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputB"));	
	      		  SetValue($this->GetIDForIdent("EnergyDayPVEarningInputAB"), round( $AB, 0 ) );   
			  break;
		  case "FBF3CE97": // Tagesenergie Ertrag Input B in Wh
			  SetValue($this->GetIDForIdent("EnergyDayPVEarningInputB"), round( $float, 0 ) ); 
			  $AB = GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputB"));	
	      		  SetValue($this->GetIDForIdent("EnergyDayPVEarningInputAB"), round( $AB, 0 ) );   
		          break;    
		  case "3C87C4F5": // Tagesenergie Netzeinspeisung in -Wh
			  $float = $float * -1;
			  SetValue($this->GetIDForIdent("EnergyDayGridFeedIn"), round( $float, 0 ) ); 
		          // Calculate FeedInLevel etc.
			  $FeedInLevel = 0.0;
			  if ( ( GetValueInteger($this->GetIDForIdent("EnergyDayGridFeedIn") ) > 0 ) and ( GetValueInteger($this->GetIDForIdent("EnergyDayEnergy") ) > 0 ) )	
			    $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyDayGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyDayEnergy")) * 100;	
			  SetValue($this->GetIDForIdent("EnergyDayGridFeedInLevel"), round( $FeedInLevel, 0 ) ); 
			  $SelfConsumptionLevel = 0.0;  
			  if ( GetValueInteger($this->GetIDForIdent("EnergyDayEnergy")) > 0 )
			    $SelfConsumptionLevel = 100 - $FeedInLevel;
                          SetValue($this->GetIDForIdent("EnergyDaySelfConsumptionLevel"), round( $SelfConsumptionLevel, 0 ) );
	      		  break;      
		  case "867DEF7D": // Tagesenergie Netzverbrauch in Wh
			  SetValue($this->GetIDForIdent("EnergyDayGridUsage"), round( $float, 0 ) ); 
			  // Calculate AutonomousPowerLevel etc.
			  $GridPowerLevel= GetValueInteger($this->GetIDForIdent("EnergyDayGridUsage")) / GetValueInteger($this->GetIDForIdent("EnergyDayHouseholdTotal")) * 100;
			  SetValue($this->GetIDForIdent("EnergyDayGridPowerLevel"), round( $GridPowerLevel, 0 ) ); 
			  $AutonomousPowerLevel = 100 - $GridPowerLevel;
			  SetValue($this->GetIDForIdent("EnergyDayAutonomousPowerLevel"), round( $AutonomousPowerLevel, 0 ) );         
	      		  break;  
		  case "2F3C1D7D": // Tagesenergie Haushalt in Wh     
			  SetValue($this->GetIDForIdent("EnergyDayHouseholdTotal"), round( $float, 0 ) ); 
			  // Calculate AutonomousPowerLevel etc.
			  $GridPowerLevel= GetValueInteger($this->GetIDForIdent("EnergyDayGridUsage")) / GetValueInteger($this->GetIDForIdent("EnergyDayHouseholdTotal")) * 100;
			  SetValue($this->GetIDForIdent("EnergyDayGridPowerLevel"), round( $GridPowerLevel, 0 ) ); 
			  $AutonomousPowerLevel = 100 - $GridPowerLevel;
			  SetValue($this->GetIDForIdent("EnergyDayAutonomousPowerLevel"), round( $AutonomousPowerLevel, 0 ) );         
	      		  break;  
				     
		  //--- Energy Month  
		  case "10970E9D": // This month energy [Wh], Float	
			  SetValue($this->GetIDForIdent("EnergyMonthEnergy"), round( $float, 0 ) );
		          // Calculate FeedInLevel etc.
			  $FeedInLevel = 0.0;
			  if ( ( GetValueInteger($this->GetIDForIdent("EnergyMonthGridFeedIn") ) > 0 ) and ( GetValueInteger($this->GetIDForIdent("EnergyMonthEnergy") ) > 0 ) )
			    $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyMonthGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyMonthEnergy")) * 100;	
			  SetValue($this->GetIDForIdent("EnergyMonthGridFeedInLevel"), round( $FeedInLevel, 0 ) ); 
			  $SelfConsumptionLevel = 0.0;  
			  if ( GetValueInteger($this->GetIDForIdent("EnergyMonthEnergy")) > 0 )
			    $SelfConsumptionLevel = 100 - $FeedInLevel;
                          SetValue($this->GetIDForIdent("EnergyMonthSelfConsumptionLevel"), round( $SelfConsumptionLevel, 0 ) );
	      		  break; 
		  case "81AE960B": // Monatsenergie Ertrag Input A in Wh
			  SetValue($this->GetIDForIdent("EnergyMonthPVEarningInputA"), round( $float, 0 ) ); 
			  $AB = GetValueInteger($this->GetIDForIdent("EnergyMonthPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyMonthPVEarningInputB"));	
	      		  SetValue($this->GetIDForIdent("EnergyMonthPVEarningInputAB"), round( $AB, 0 ) );   
	      		  break; 
		  case "7AB9B045": // Monatsenergie Ertrag Input B in Wh
			  SetValue($this->GetIDForIdent("EnergyMonthPVEarningInputB"), round( $float, 0 ) ); 
			  $AB = GetValueInteger($this->GetIDForIdent("EnergyMonthPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyMonthPVEarningInputB"));	
	      		  SetValue($this->GetIDForIdent("EnergyMonthPVEarningInputAB"), round( $AB, 0 ) );   
	      		  break; 
		  case "65B624AB": // Monatsenergie Netzeinspeisung ins Netz in -Wh
			  $float = $float * -1;
			  SetValue($this->GetIDForIdent("EnergyMonthGridFeedIn"), round( $float, 0 ) ); 
		          // Calculate FeedInLevel etc.
			  $FeedInLevel = 0.0;
			  if ( ( GetValueInteger($this->GetIDForIdent("EnergyMonthGridFeedIn") ) > 0 ) and ( GetValueInteger($this->GetIDForIdent("EnergyMonthEnergy") ) > 0 ) )
			    $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyMonthGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyMonthEnergy")) * 100;	
			  SetValue($this->GetIDForIdent("EnergyMonthGridFeedInLevel"), round( $FeedInLevel, 0 ) ); 
			  $SelfConsumptionLevel = 0.0;  
			  if ( GetValueInteger($this->GetIDForIdent("EnergyMonthEnergy")) > 0 )
			    $SelfConsumptionLevel = 100 - $FeedInLevel;
                          SetValue($this->GetIDForIdent("EnergyMonthSelfConsumptionLevel"), round( $SelfConsumptionLevel, 0 ) );
	      		  break;  
		  case "126ABC86": // Monatsenergie Netzverbrauch in Wh
			  SetValue($this->GetIDForIdent("EnergyMonthGridUsage"), round( $float, 0 ) ); 
			  // Calculate AutonomousPowerLevel etc.
			  $GridPowerLevel= GetValueInteger($this->GetIDForIdent("EnergyMonthGridUsage")) / GetValueInteger($this->GetIDForIdent("EnergyMonthHouseholdTotal")) * 100;
			  SetValue($this->GetIDForIdent("EnergyMonthGridPowerLevel"), round( $GridPowerLevel, 0 ) ); 
			  $AutonomousPowerLevel = 100 - $GridPowerLevel;
			  SetValue($this->GetIDForIdent("EnergyMonthAutonomousPowerLevel"), round( $AutonomousPowerLevel, 0 ) );         
	      		  break;  
		  case "F0BE6429": // Monatsenergie Haushalt in Wh	     
			  SetValue($this->GetIDForIdent("EnergyMonthHouseholdTotal"), round( $float, 0 ) ); 
			  // Calculate AutonomousPowerLevel etc.
			  $GridPowerLevel= GetValueInteger($this->GetIDForIdent("EnergyMonthGridUsage")) / GetValueInteger($this->GetIDForIdent("EnergyMonthHouseholdTotal")) * 100;
			  SetValue($this->GetIDForIdent("EnergyMonthGridPowerLevel"), round( $GridPowerLevel, 0 ) ); 
			  $AutonomousPowerLevel = 100 - $GridPowerLevel;
			  SetValue($this->GetIDForIdent("EnergyMonthAutonomousPowerLevel"), round( $AutonomousPowerLevel, 0 ) );         
	      		  break;  
				     
		  //--- Energy Year
		  case "C0CC81B6": // This year energy [Wh], Float	
			  SetValue($this->GetIDForIdent("EnergyYearEnergy"), round( $float, 0 ) );
		          // Calculate FeedInLevel etc.
			  $FeedInLevel = 0.0;
			  if ( ( GetValueInteger($this->GetIDForIdent("EnergyYearGridFeedIn") ) > 0 ) and ( GetValueInteger($this->GetIDForIdent("EnergyYearEnergy") ) > 0 ) )
			    $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyYearGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyYearEnergy")) * 100;	
			  SetValue($this->GetIDForIdent("EnergyYearGridFeedInLevel"), round( $FeedInLevel, 0 ) ); 
			  $SelfConsumptionLevel = 0.0;  
			  if ( GetValueInteger($this->GetIDForIdent("EnergyYearEnergy")) > 0 )
			    $SelfConsumptionLevel = 100 - $FeedInLevel;
                          SetValue($this->GetIDForIdent("EnergyYearSelfConsumptionLevel"), round( $SelfConsumptionLevel, 0 ) );
	      		  break; 
		  case "AF64D0FE": // Jahresenergie Ertrag Input A in Wh
			  SetValue($this->GetIDForIdent("EnergyYearPVEarningInputA"), round( $float, 0 ) ); 
			  $AB = GetValueInteger($this->GetIDForIdent("EnergyYearPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyYearPVEarningInputB"));	
	      		  SetValue($this->GetIDForIdent("EnergyYearPVEarningInputAB"), round( $AB, 0 ) );   
	      		  break;     
		  case "BD55D796": // Jahresenergie Ertrag Input B in Wh
			  SetValue($this->GetIDForIdent("EnergyYearPVEarningInputB"), round( $float, 0 ) ); 
			  $AB = GetValueInteger($this->GetIDForIdent("EnergyYearPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyYearPVEarningInputB"));	
	      		  SetValue($this->GetIDForIdent("EnergyYearPVEarningInputAB"), round( $AB, 0 ) );   
	      		  break;    
		  case "26EFFC2F": // Jahresenergie Netzinspeisung ins Netz in -Wh
			  $float = $float * -1;
			  SetValue($this->GetIDForIdent("EnergyYearGridFeedIn"), round( $float, 0 ) ); 
		          // Calculate FeedInLevel etc.
			  $FeedInLevel = 0.0;
			  if ( ( GetValueInteger($this->GetIDForIdent("EnergyYearGridFeedIn") ) > 0 ) and ( GetValueInteger($this->GetIDForIdent("EnergyYearEnergy") ) > 0 ) )
			    $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyYearGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyYearEnergy")) * 100;	
			  SetValue($this->GetIDForIdent("EnergyYearGridFeedInLevel"), round( $FeedInLevel, 0 ) ); 
			  $SelfConsumptionLevel = 0.0;  
			  if ( GetValueInteger($this->GetIDForIdent("EnergyYearEnergy")) > 0 )
			    $SelfConsumptionLevel = 100 - $FeedInLevel;
                          SetValue($this->GetIDForIdent("EnergyYearSelfConsumptionLevel"), round( $SelfConsumptionLevel, 0 ) );
	      		  break;   
		  case "DE17F021": // Jahresenergie Netzverbrauch in Wh
			  SetValue($this->GetIDForIdent("EnergyYearGridUsage"), round( $float, 0 ) ); 
			  // Calculate AutonomousPowerLevel etc.
			  $GridPowerLevel= GetValueInteger($this->GetIDForIdent("EnergyYearGridUsage")) / GetValueInteger($this->GetIDForIdent("EnergyYearHouseholdTotal")) * 100;
			  SetValue($this->GetIDForIdent("EnergyYearGridPowerLevel"), round( $GridPowerLevel, 0 ) ); 
			  $AutonomousPowerLevel = 100 - $GridPowerLevel;
			  SetValue($this->GetIDForIdent("EnergyYearAutonomousPowerLevel"), round( $AutonomousPowerLevel, 0 ) );         
	      		  break;     
		  case "C7D3B479": // Jahresenergie Haushalt in Wh    
			  SetValue($this->GetIDForIdent("EnergyYearHouseholdTotal"), round( $float, 0 ) ); 
			  // Calculate AutonomousPowerLevel etc.
			  $GridPowerLevel= GetValueInteger($this->GetIDForIdent("EnergyYearGridUsage")) / GetValueInteger($this->GetIDForIdent("EnergyYearHouseholdTotal")) * 100;
			  SetValue($this->GetIDForIdent("EnergyYearGridPowerLevel"), round( $GridPowerLevel, 0 ) ); 
			  $AutonomousPowerLevel = 100 - $GridPowerLevel;
			  SetValue($this->GetIDForIdent("EnergyYearAutonomousPowerLevel"), round( $AutonomousPowerLevel, 0 ) );         
	      		  break;    
				     
		  //--- Energy Total
		  case "B1EF67CE": // Total Energy [Wh], Float	
			  SetValue($this->GetIDForIdent("EnergyTotalEnergy"), round( $float, 0 ) );
		          // Calculate FeedInLevel etc.
			  $FeedInLevel = 0.0;
			  if ( ( GetValueInteger($this->GetIDForIdent("EnergyTotalGridFeedIn") ) > 0 ) and ( GetValueInteger($this->GetIDForIdent("EnergyTotalEnergy") ) > 0 ) )
			    $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyTotalGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyTotalEnergy")) * 100;	
			  SetValue($this->GetIDForIdent("EnergyTotalGridFeedInLevel"), round( $FeedInLevel, 0 ) ); 
			  $SelfConsumptionLevel = 0.0;  
			  if ( GetValueInteger($this->GetIDForIdent("EnergyTotalEnergy")) > 0 )
			    $SelfConsumptionLevel = 100 - $FeedInLevel;
                          SetValue($this->GetIDForIdent("EnergyTotalSelfConsumptionLevel"), round( $SelfConsumptionLevel, 0 ) );
	      		  break; 
		  case "FC724A9E": // Gesamtenergie Ertrag Input A in Wh
			  SetValue($this->GetIDForIdent("EnergyTotalPVEarningInputA"), round( $float, 0 ) );   
			  $AB = GetValueInteger($this->GetIDForIdent("EnergyTotalPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyTotalPVEarningInputB"));	
	      		  SetValue($this->GetIDForIdent("EnergyTotalPVEarningInputAB"), round( $AB, 0 ) );   
			  break; 	     
		  case "68EEFD3D": // Gesamtenergie Ertrag Input B in Wh
			  SetValue($this->GetIDForIdent("EnergyTotalPVEarningInputB"), round( $float, 0 ) );   
			  $AB = GetValueInteger($this->GetIDForIdent("EnergyTotalPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyTotalPVEarningInputB"));	
	      		  SetValue($this->GetIDForIdent("EnergyTotalPVEarningInputAB"), round( $AB, 0 ) );   
	      		  break;  	     
		  case "44D4C533": // Gesamtenergie Netzeinspeisung in -Wh
			  $float = $float * -1;
			  SetValue($this->GetIDForIdent("EnergyTotalGridFeedIn"), round( $float, 0 ) ); 
		          // Calculate FeedInLevel etc.
			  $FeedInLevel = 0.0;
			  if ( ( GetValueInteger($this->GetIDForIdent("EnergyTotalGridFeedIn") ) > 0 ) and ( GetValueInteger($this->GetIDForIdent("EnergyTotalEnergy") ) > 0 ) )
			    $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyTotalGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyTotalEnergy")) * 100;	
			  SetValue($this->GetIDForIdent("EnergyTotalGridFeedInLevel"), round( $FeedInLevel, 0 ) ); 
			  $SelfConsumptionLevel = 0.0;  
			  if ( GetValueInteger($this->GetIDForIdent("EnergyTotalEnergy")) > 0 )
			    $SelfConsumptionLevel = 100 - $FeedInLevel;
                          SetValue($this->GetIDForIdent("EnergyTotalSelfConsumptionLevel"), round( $SelfConsumptionLevel, 0 ) );
	      		  break; 	     
		  case "62FBE7DC": // Gesamtenergie Netzverbrauch in Wh
			  SetValue($this->GetIDForIdent("EnergyTotalGridUsage"), round( $float, 0 ) ); 
			  $GridPowerLevel= GetValueInteger($this->GetIDForIdent("EnergyTotalGridUsage")) / GetValueInteger($this->GetIDForIdent("EnergyTotalHouseholdTotal")) * 100;
			  SetValue($this->GetIDForIdent("EnergyTotalGridPowerLevel"), round( $GridPowerLevel, 0 ) ); 
			  $AutonomousPowerLevel = 100 - $GridPowerLevel;
			  SetValue($this->GetIDForIdent("EnergyTotalAutonomousPowerLevel"), round( $AutonomousPowerLevel, 0 ) );         											    
	      		  break; 	     
		  case "EFF4B537": // Gesamtenergie Haushalt in Wh     
			  SetValue($this->GetIDForIdent("EnergyTotalHouseholdTotal"), round( $float, 0 ) ); 
			  // Calculate AutonomousPowerLevel etc.
			  $GridPowerLevel= GetValueInteger($this->GetIDForIdent("EnergyTotalGridUsage")) / GetValueInteger($this->GetIDForIdent("EnergyTotalHouseholdTotal")) * 100;
			  SetValue($this->GetIDForIdent("EnergyTotalGridPowerLevel"), round( $GridPowerLevel, 0 ) ); 
			  $AutonomousPowerLevel = 100 - $GridPowerLevel;
			  SetValue($this->GetIDForIdent("EnergyTotalAutonomousPowerLevel"), round( $AutonomousPowerLevel, 0 ) );         
	      		  break;    
				     
				     
		  case "FE1AA500": // External Power Limit [0..1], Float	
			 break;
			  
		  case "BD008E29": // External battery power target [W] (positive = discharge), Float	
			  break;
			  
		  case "872F380B": // External load demand [W] (positive = feed in / 0=internal ), Float	
			  break;
			  
		  //--- NOT DOCUMENTED !!! -------------------------------------------------------------------------
		  case "8B9FF008": // Upper load boundary in %
			  SetValue($this->GetIDForIdent("BatteryUpperSoC"), round( $float*100, 0 ) );
			  
			  $GrossCapacity = GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity"));
			  $RemainingPercentage = GetValueFloat($this->GetIDForIdent("BatterySoC")) - $this->ReadPropertyInteger("LowerSoCLevel");
			  if ( $RemainingPercentage < 0 ) $RemainingPercentage = 0;
			  $RemainingCapacity = $GrossCapacity/100*$RemainingPercentage;
			  SetValue($this->GetIDForIdent("BatteryRemainingNetCapacity"), round( $RemainingCapacity, 2 ) );
			  
			  break;
			  
		  case "4BC0F974": // Installed PV Power kWp (was <V1.0 "gross battery capacity kwh" - error!)
			  SetValue($this->GetIDForIdent("InstalledPVPower"), round( $float/1000, 2 ) );
			  break;
			  
		  case "1AC87AA0": // Current House power consumption
			  SetValue($this->GetIDForIdent("HousePowerCurrent"), round( $float, 0 ) );
			  break;
			  
		  case "37F9D5CA": // Bit-coded fault word 0
			  break;
		  case "234B4736": // Bit-coded fault word 1
			  break;
		  case "3B7FCD47": // Bit-coded fault word 2
			  break;
		  case "7F813D73": // Bit-coded fault word 3
			  break;
			  
		  case "EBC62737": // android description
			  break;
			  
		  case "7924ABD9": // Inverter serial number
			  break;
			  
  		  case "FBF6D834": // Battery Stack 0 serial number
			  break;
			  
		  case "99396810": // Battery Stack 1 serial number
			  break;
			  
		  case "73489528": // Battery Stack 2 serial number
			  break;
			  
		  case "257B7612": // Battery Stack 3 serial number
			  break;
			  
		  case "4E699086": // Battery Stack 4 serial number
			  break;
			  
		  case "162491E8": // Battery Stack 5 serial number
			  break;
			  
		  case "5939EC5D": // Battery Stack 5 serial number
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
          // build command		
	  $hexlength = strtoupper( dechex($length) );
          if ( strlen( $hexlength ) == 1 ) $hexlength = '0'.$hexlength;
	  $command = "01".$hexlength.$command;
	  $command = "2B".$command.$this->calcCRC( $command );
	  $hexCommand = "";
	  for( $x=0; $x<strlen($command)/2;$x++)
	    $hexCommand = $hexCommand.chr(hexdec(substr( $command, $x*2, 2 )));
		
	  // send Data to Parent (IO)...
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
          
	  $this->requestData( "DB2D69AE",4 ); usleep( 100000 ); // Actual inverters AC-power [W]
          
	  $this->requestData( "CF053085",4 ); usleep( 100000 ); // Phase L1 voltage [V]
	  $this->requestData( "54B4684E",4 ); usleep( 100000 ); // Phase L2 voltage [V]
	  $this->requestData( "2545E22D",4 ); usleep( 100000 ); // Phase L3 voltage [V]

          
          $this->requestData( "B55BA2CE",4 ); usleep( 100000 ); // DC input A voltage [V] (by Documentation B298395D) 
          $this->requestData( "DB11855B",4 ); usleep( 100000 ); // DC input A power [W]

	  $this->requestData( "B0041187",4 ); usleep( 100000 ); // DC input B voltage [V] (by Documentation 5BB8075A)
          $this->requestData( "0CB5D21B",4 ); usleep( 100000 ); // DC input B power [W]

	  // $this->requestData( "B408E40A", 4 ); usleep( 100000 ); // Battery current measured by inverter, low pass filter with Tau = 1s [A]

          $this->requestData( "A7FA5C5D",4 ); usleep( 100000 ); // Battery voltage [V]
          $this->requestData( "959930BF",4 ); usleep( 100000 ); // Battery State of Charge (SoC) [0..1]
          $this->requestData( "400F015B",4 ); usleep( 100000 ); // Battery power (positive if discharge) [W]
          $this->requestData( "902AFAFB",4 ); usleep( 100000 ); // Battery temperature [°C]

          
          $this->requestData( "91617C58",4 ); usleep( 100000 ); // Public grid power (house connection, negative by feed-in) [W]
          
          $this->requestData( "E96F1844",4 ); usleep( 100000 ); // External power (additional inverters/generators in house internal grid) [W]
		
	  //--- Request Energies -------------------------------------
          // Todays Energy
          $this->requestData( "BD55905F",4 ); usleep( 100000 ); // Todays energy [Wh]
          $this->requestData( "2AE703F2",4 ); usleep( 100000 ); // Tagesenergie Ertrag Input A in Wh
          $this->requestData( "FBF3CE97",4 ); usleep( 100000 ); // Tagesenergie Ertrag Input B in Wh
          $this->requestData( "3C87C4F5",4 ); usleep( 100000 ); // Tagesenergie Netzeinspeisung in -Wh
          $this->requestData( "867DEF7D",4 ); usleep( 100000 ); // Tagesenergie Netzverbrauch	in Wh
          $this->requestData( "2F3C1D7D",4 ); usleep( 100000 ); // Tagesenergie Haushalt in Wh	
	
          // Month Energy
          $this->requestData( "10970E9D",4 ); usleep( 100000 ); // This month energy [Wh]
          $this->requestData( "81AE960B",4 ); usleep( 100000 ); // Monatsenergie Ertrag Input A in Wh
          $this->requestData( "7AB9B045",4 ); usleep( 100000 ); // Monatsenergie Ertrag Input B in Wh
          $this->requestData( "65B624AB",4 ); usleep( 100000 ); // Monatsenergie Netzeinspeisung ins Netz in -Wh
          $this->requestData( "126ABC86",4 ); usleep( 100000 ); // Monatsenergie Netzverbrauch in Wh
          $this->requestData( "F0BE6429",4 ); usleep( 100000 ); // Monatsenergie Haushalt in Wh
		
          // Year Energy
          $this->requestData( "C0CC81B6",4 ); usleep( 100000 ); // This year energy [Wh]
          $this->requestData( "AF64D0FE",4 ); usleep( 100000 ); // Jahresenergie Ertrag Input A in Wh
          $this->requestData( "BD55D796",4 ); usleep( 100000 ); // Jahresenergie Ertrag Input B in Wh
          $this->requestData( "26EFFC2F",4 ); usleep( 100000 ); // Jahresenergie Netzinspeisung ins Netz in -Wh
          $this->requestData( "DE17F021",4 ); usleep( 100000 ); // Jahresenergie Netzverbrauch in Wh
          $this->requestData( "C7D3B479",4 ); usleep( 100000 ); // Jahresenergie Haushalt in Wh	
		
          // Total Energy
          $this->requestData( "B1EF67CE",4 ); usleep( 100000 ); // Total Energy [Wh]
          $this->requestData( "FC724A9E",4 ); usleep( 100000 ); // Gesamtenergie Ertrag Input A in Wh
          $this->requestData( "68EEFD3D",4 ); usleep( 100000 ); // Gesamtenergie Ertrag Input B in Wh
          $this->requestData( "44D4C533",4 ); usleep( 100000 ); // Gesamtenergie Netzeinspeisung in -Wh
          $this->requestData( "62FBE7DC",4 ); usleep( 100000 ); // Gesamtenergie Netzverbrauch in Wh
          $this->requestData( "EFF4B537",4 ); usleep( 100000 ); // Gesamtenergie Haushalt in Wh 
		
		
          $this->requestData( "FE1AA500",4 ); usleep( 100000 ); // External Power Limit [0..1]
          $this->requestData( "BD008E29",4 ); usleep( 100000 ); // External battery power target [W] (positive = discharge)
          $this->requestData( "872F380B",4 ); usleep( 100000 ); // External load demand [W] (positive = feed in / 0=internal
		
	  // Bit-coded fault word 0-3	
          $this->requestData( "37F9D5CA",4 ); usleep( 100000 );
	  $this->requestData( "234B4736",4 ); usleep( 100000 );
	  $this->requestData( "3B7FCD47",4 ); usleep( 100000 );
	  $this->requestData( "7F813D73",4 ); usleep( 100000 );
		
	  // Serial numbers and Descriptions
	  $this->requestData( "EBC62737", 4 ); usleep( 100000 ); // android description
          $this->requestData( "7924ABD9", 4 ); usleep( 100000 ); // Inverter serial number
          $this->requestData( "FBF6D834", 4 ); usleep( 100000 ); // Battery Stack 0 serial number
          $this->requestData( "99396810", 4 ); usleep( 100000 ); // Battery Stack 1 serial number
          $this->requestData( "73489528", 4 ); usleep( 100000 ); // Battery Stack 2 serial number
          $this->requestData( "257B7612", 4 ); usleep( 100000 ); // Battery Stack 3 serial number
          $this->requestData( "4E699086", 4 ); usleep( 100000 ); // Battery Stack 4 serial number
          $this->requestData( "162491E8", 4 ); usleep( 100000 ); // Battery Stack 5 serial number
          $this->requestData( "5939EC5D", 4 ); usleep( 100000 ); // Battery Stack 5 serial number
			     
          //--- NOT DOCUMENTED -------------------------------------------------------------------------
	  $this->requestData( "8B9FF008", 4 ); usleep( 100000 ); // Upper load boundary in %
	  $this->requestData( "4BC0F974", 4 ); usleep( 100000 ); // Installed PV Panel kWp
	  $this->requestData( "1AC87AA0", 4 ); usleep( 100000 ); // Current House power consumption
		
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
                IPS_SetVariableProfileText('RCTPOWER_Capacity.2', "", " kWh" );
            }
            
            if ( !IPS_VariableProfileExists('RCTPOWER_SoC.1') ) {
                IPS_CreateVariableProfile('RCTPOWER_SoC.1', 2 );
                IPS_SetVariableProfileDigits('RCTPOWER_SoC.1', 2 );
                IPS_SetVariableProfileIcon('RCTPOWER_SoC.1', 'Battery' );
                IPS_SetVariableProfileText('RCTPOWER_SoC.1', "", " %" );
            }
		 
            if ( !IPS_VariableProfileExists('RCTPOWER_PVPower.2') ) {
                IPS_CreateVariableProfile('RCTPOWER_PVPower.2', 2 );
                IPS_SetVariableProfileDigits('RCTPOWER_PVPower.2', 2 );
                IPS_SetVariableProfileIcon('RCTPOWER_PVPower.2', 'Electricity' );
                IPS_SetVariableProfileText('RCTPOWER_PVPower.2', "", " kWp" );
            }
		 
	    //--- String (Type 3)
		 
        }
        
        protected function registerVariables() {
		
          $this->RegisterVariableInteger("DCInputAVoltage",   "Eingang A Spannung","RCTPOWER_Voltage",100);
          $this->RegisterVariableInteger("DCInputAPower",     "Eingang A Leistung","RCTPOWER_Power",101);
	  $this->RegisterVariableFloat("DCInputAUtilization", "Eingang A Auslastung PV Module","~Valve.F",102);
          $this->RegisterVariableInteger("DCInputBVoltage",   "Eingang B Spannung","RCTPOWER_Voltage",103);
          $this->RegisterVariableInteger("DCInputBPower",     "Eingang B Leistung","RCTPOWER_Power",104);
	  $this->RegisterVariableFloat("DCInputBUtilization", "Eingang B Auslastung PV Module","~Valve.F",105);
	  $this->RegisterVariableInteger("DCInputPower",      "Eingang Gesamtleistung","RCTPOWER_Power",106);
	  $this->RegisterVariableFloat("DCInputUtilization",  "Auslastung PV Module gesamt","~Valve.F",107);
	  $this->RegisterVariableFloat("InstalledPVPower",    "Installerierte Panelleistung","RCTPOWER_PVPower.2",108);
		
		
          $this->RegisterVariableInteger("BatteryVoltage",     "Batterie Spannung","RCTPOWER_Voltage",200);
	  $this->RegisterVariableInteger("BatteryPower",       "Batterie Leistung","RCTPOWER_Power",201);	
	  $this->RegisterVariableFloat("BatteryGrossCapacity", "Batterie Brutto-Kapazität","RCTPOWER_Capacity.2",202);
	  $this->RegisterVariableFloat("BatteryRemainingNetCapacity","Batterie verf. Restkapazität","RCTPOWER_Capacity.2",202);	
	  $this->RegisterVariableFloat("BatterySoC",           "Batterie Ladestand","~Valve.F",203);
	  $this->RegisterVariableFloat("BatteryUpperSoC",      "Batterie Ladegrenze","~Valve.F",204);	
	  $this->RegisterVariableFloat("BatteryTemperature",   "Batterie Temperatur","~Temperature",205);	
		
	  $this->RegisterVariableInteger("ExternalPower",  "Generator Leistung","RCTPOWER_Power",300); 
		
          $this->RegisterVariableInteger("PublicGridPower","Aussennetz Leistung","RCTPOWER_Power",400); 
	  
	  // Energy Earnings and Consumption
	  // Day
          $this->RegisterVariableInteger("EnergyDayEnergy",               "Tag - PV Energie ans Haus (via Batteriepuffer)","RCTPOWER_Energy",500);
	  $this->RegisterVariableInteger("EnergyDayPVEarningInputA",      "Tag - PV Ertrag Eingang A","RCTPOWER_Energy",501);
	  $this->RegisterVariableInteger("EnergyDayPVEarningInputB",      "Tag - PV Ertrag Eingang B","RCTPOWER_Energy",502);
	  $this->RegisterVariableInteger("EnergyDayPVEarningInputAB",     "Tag - PV Ertrag Eingänge A+B","RCTPOWER_Energy",502);
	  $this->RegisterVariableInteger("EnergyDayGridFeedIn",           "Tag - Netzeinspeisung","RCTPOWER_Energy",503);
	  $this->RegisterVariableInteger("EnergyDayGridUsage",            "Tag - Netzverbrauch","RCTPOWER_Energy",504);
	  $this->RegisterVariableInteger("EnergyDayHouseholdTotal",       "Tag - Haushalt gesamt","RCTPOWER_Energy",505);
	  $this->RegisterVariableInteger("EnergyDayAutonomousPowerLevel", "Tag - % Anteil PV am Tagesverbrauch","~Valve", 506); 
	  $this->RegisterVariableInteger("EnergyDayGridPowerLevel",       "Tag - % Anteil externer Strom am Tagesverbrauch","~Valve", 507); 
	  $this->RegisterVariableInteger("EnergyDaySelfConsumptionLevel", "Tag - % PV Selbstverbrauch","~Valve", 508); 
	  $this->RegisterVariableInteger("EnergyDayGridFeedInLevel",      "Tag - % PV Netzeinspeisung","~Valve", 509); 
		
	  // Month 
	  $this->RegisterVariableInteger("EnergyMonthEnergy",               "Monat - PV Energie ans Haus (via Batteriepuffer)","RCTPOWER_Energy",600);
	  $this->RegisterVariableInteger("EnergyMonthPVEarningInputA",      "Monat - PV Ertrag Eingang A","RCTPOWER_Energy",601);
	  $this->RegisterVariableInteger("EnergyMonthPVEarningInputB",      "Monat - PV Ertrag Eingang B","RCTPOWER_Energy",602);
	  $this->RegisterVariableInteger("EnergyMonthPVEarningInputAB",     "Monat - PV Ertrag Eingänge A+B","RCTPOWER_Energy",60);
	  $this->RegisterVariableInteger("EnergyMonthGridFeedIn",           "Monat - Netzeinspeisung","RCTPOWER_Energy",603);
	  $this->RegisterVariableInteger("EnergyMonthGridUsage",            "Monat - Netzverbrauch","RCTPOWER_Energy",604);
	  $this->RegisterVariableInteger("EnergyMonthHouseholdTotal",       "Monat - Haushalt gesamt","RCTPOWER_Energy",605);
	  $this->RegisterVariableInteger("EnergyMonthAutonomousPowerLevel", "Monat - % Anteil PV am Monatsverbrauch","~Valve", 606); 
	  $this->RegisterVariableInteger("EnergyMonthGridPowerLevel",       "Monat - % Anteil externer Strom am Monatsverbrauch","~Valve", 607); 
	  $this->RegisterVariableInteger("EnergyMonthSelfConsumptionLevel", "Monat - % PV Selbstverbrauch","~Valve", 608); 
	  $this->RegisterVariableInteger("EnergyMonthGridFeedInLevel",      "Monat - % PV Netzeinspeisung","~Valve", 609); 
		
          // Year
	  $this->RegisterVariableInteger("EnergyYearEnergy",               "Jahr - PV Energie ans Haus (via Batteriepuffer)","RCTPOWER_Energy",700);
	  $this->RegisterVariableInteger("EnergyYearPVEarningInputA",      "Jahr - PV Ertrag Eingang A","RCTPOWER_Energy",701);
	  $this->RegisterVariableInteger("EnergyYearPVEarningInputB",      "Jahr - PV Ertrag Eingang B","RCTPOWER_Energy",702);
	  $this->RegisterVariableInteger("EnergyYearPVEarningInputAB",     "Jahr - PV Ertrag Eingänge A+B","RCTPOWER_Energy",702);
	  $this->RegisterVariableInteger("EnergyYearGridFeedIn",           "Jahr - Netzeinspeisung","RCTPOWER_Energy",703);
	  $this->RegisterVariableInteger("EnergyYearGridUsage",            "Jahr - Netzverbrauch","RCTPOWER_Energy",704);
	  $this->RegisterVariableInteger("EnergyYearHouseholdTotal",       "Jahr - Haushalt gesamt","RCTPOWER_Energy",705);
	  $this->RegisterVariableInteger("EnergyYearAutonomousPowerLevel", "Jahr - % Anteil PV am Jahresverbrauch","~Valve", 706); 
	  $this->RegisterVariableInteger("EnergyYearGridPowerLevel",       "Jahr - % Anteil externer Strom am Jahresverbrauch","~Valve", 707); 
	  $this->RegisterVariableInteger("EnergyYearSelfConsumptionLevel", "Jahr - % PV Selbstverbrauch","~Valve", 708); 
	  $this->RegisterVariableInteger("EnergyYearGridFeedInLevel",      "Jahr - % PV Netzeinspeisung","~Valve", 709); 
		
	  // Total
	  $this->RegisterVariableInteger("EnergyTotalEnergy",               "Gesamt - PV Energie ans Haus (via Batteriepuffer)","RCTPOWER_Energy",800);
	  $this->RegisterVariableInteger("EnergyTotalPVEarningInputA",      "Gesamt - PV Ertrag Eingang A","RCTPOWER_Energy",801);
	  $this->RegisterVariableInteger("EnergyTotalPVEarningInputB",      "Gesamt - PV Ertrag Eingang B","RCTPOWER_Energy",802);
	  $this->RegisterVariableInteger("EnergyTotalPVEarningInputAB",     "Gesamt - PV Ertrag Eingänge A+B","RCTPOWER_Energy",802);
	  $this->RegisterVariableInteger("EnergyTotalGridFeedIn",           "Gesamt - Netzeinspeisung","RCTPOWER_Energy",803);
	  $this->RegisterVariableInteger("EnergyTotalGridUsage",            "Gesamt - Netzverbrauch","RCTPOWER_Energy",804);
	  $this->RegisterVariableInteger("EnergyTotalHouseholdTotal",       "Gesamt - Haushalt gesamt","RCTPOWER_Energy",805);
	  $this->RegisterVariableInteger("EnergyTotalAutonomousPowerLevel", "Gesamt - % Anteil PV am Gesamtverbrauch","~Valve", 806); 
	  $this->RegisterVariableInteger("EnergyTotalGridPowerLevel",       "Gesamt - % Anteil externer Strom am Gesamtverbrauch","~Valve", 807); 
	  $this->RegisterVariableInteger("EnergyTotalSelfConsumptionLevel", "Gesamt - % PV Selbstverbrauch","~Valve", 808); 
	  $this->RegisterVariableInteger("EnergyTotalGridFeedInLevel",      "Gesamt - % PV Netzeinspeisung","~Valve", 809); 
		
          $this->RegisterVariableInteger("HousePowerCurrent","Haus Leistung","RCTPOWER_Power",900);
	
	  $this->RegisterVariableBoolean("Errorstatus",      "Fehlerstatus","~Alert",1000);
        } 
	  
	  
    }
?>
