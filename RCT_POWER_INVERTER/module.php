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
	  ///--- HANDLE Connection --------------------------------------------------------------------------------------	
          // check Socket Connection (parent)
          $SocketConnectionInstanceID = IPS_GetInstance($this->InstanceID)['ConnectionID']; 
          if ( $SocketConnectionInstanceID == 0 ) return false; // No parent assigned  
            
          $ModuleID = IPS_GetInstance($SocketConnectionInstanceID)['ModuleInfo']['ModuleID'];      
          if ( $ModuleID !== '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}' ) return false; // wrong parent type
		
          // check connection status
		
          ///--- INIT CONNECTION ----------------------------------------------------------------------------------------
          // send command to RCT Power Inverter
		
	  $command = "\0x2B0104400F015B58B4";	
		
	  $this->SendDataToParent(json_encode(Array("DataID" => "{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}", 
						    "Buffer" => utf8_encode($command) )));

        } 
        
        private function endCommunication() {
	  return true;			
        }
       
        //=== Tool Functions ============================================================================================

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
