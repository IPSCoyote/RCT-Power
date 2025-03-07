<?php

class RCTPowerInverter extends IPSModule
{

    public function Create()
    {
        /* Create is called ONCE on Instance creation and start of IP-Symcon.
           Status-Variables und Modul-Properties for permanent usage should be created here  */
        parent::Create();

        // Properties RCT Power Inverter
        $this->RegisterPropertyInteger("InputAPanelCount", 0);
        $this->RegisterPropertyInteger("InputANominalPowerPerPanel", 0);
        $this->RegisterPropertyInteger("InputBPanelCount", 0);
        $this->RegisterPropertyInteger("InputBNominalPowerPerPanel", 0);
        $this->RegisterPropertyInteger("LowerSoCLevel", 0);
        $this->RegisterPropertyBoolean("AutomaticUpdatesActive", true);
        $this->RegisterPropertyInteger("UpdateInterval", 0);
        $this->RegisterPropertyBoolean("DebugSwitch", false);
        $this->RegisterPropertyBoolean("ReactOnForeignPolls", false);
        $this->RegisterPropertyBoolean("IgnoreResponseSequence", false);
        $this->RegisterPropertyBoolean("IgnoreCRCErrors", false);

        // Timer
        $this->RegisterTimer("RCTPOWERINVERTER_UpdateTimer", 0, 'RCTPOWERINVERTER_UpdateData($_IPS[\'TARGET\']);');

        // No data requested yet
        $this->SetBuffer("CommunicationStatus", "Idle");
    }

    public function ApplyChanges()
    {
        /* Called on 'apply changes' in the configuration UI and after creation of the instance */
        parent::ApplyChanges();

        // Generate Profiles & Variables
        $this->registerProfiles();
        $this->registerVariables();

        $this->SetReceiveDataFilter(".*018EF6B5-AB94-40C6-AA53-46943E824ACF.*");
        if ($this->ReadPropertyBoolean("AutomaticUpdatesActive") and $this->ReadPropertyInteger("UpdateInterval") >= 10)
            $this->SetTimerInterval("RCTPOWERINVERTER_UpdateTimer", $this->ReadPropertyInteger("UpdateInterval") * 1000);
        else
            $this->SetTimerInterval("RCTPOWERINVERTER_UpdateTimer", 0);

        // No data requested yet
        $this->SetBuffer("CommunicationStatus", "Idle");
        $this->SetBuffer("UpdateWhilePreviousUpdate", 0);
    }

    public function Destroy()
    {
        //$this->UnregisterTimer("RCTPOWERINVERTER_UpdateTimer");
        // Never delete this line!
        parent::Destroy();
    }

    //=== Module Functions =========================================================================================
    public function ReceiveData($JSONString)
    {
        // We first collect all data coming till the "end address" (we wait for "1AC87AA0" as last address requested by
        // UpdateData) is received. Then we evaluate all received data if it fits to the request of the UpdateData call
        // but ignore all non-response packages or duplicate addresses (as master sends also slave data)

        // remind last ReceiveData
        $this->SetBuffer("LastReceiveData", strval(time()));

        if ($this->GetBuffer("CommunicationStatus") != "WAITING FOR RESPONSES") {

            $this->debugLog("Unexpected Data Received");
            if ($this->ReadPropertyBoolean("ReactOnForeignPolls") == false) {
                $this->SetBuffer("ReceivedDataBuffer", "");
                return true;
            } else {
                $this->debugLog("Data collected anyhow till end package (React on Foreign Polls Switch)");
            }
        }

        // in general we expect address "1AC87AA0" to be requested at last -> End of all expected Responses
        // till then we collect all given data in a long string
        $EndAddress = chr(26) . chr(200) . chr(122) . chr(160);

        if (strlen($JSONString) == 0) return;

        $ReceivedData = utf8_decode(json_decode($JSONString)->Buffer);

        $ReceivedDataBuffer = $this->GetBuffer("ReceivedDataBuffer");
        $CollectedReceivedData = $ReceivedDataBuffer . $ReceivedData;

        if (strpos($CollectedReceivedData, $EndAddress) > 0) {
            // End Address was received -> start analysing data	and clear received data buffer
            $this->SetBuffer("ReceivedDataBuffer", "");
        } else {
            // still waiting for the end of the package, so collect received data in buffer
            $this->debugLog("Expected Data Received, collecting...");
            $this->SetBuffer("ReceivedDataBuffer", $CollectedReceivedData);
            return true;
        }

        try {
            //--- End Address was received, so process data
            $this->debugLog("All Expected Data Received (" . strlen($CollectedReceivedData) . " bytes), start analyzing");

            $this->SetBuffer("CommunicationStatus", "ANALYSING"); // no more data expected, start analysis

            // Now cut the collected received data into single data packages
            // length 9 is a minimal usefull backage like a read package "2B 01 04 AA BB CC DD CS CS"
            $singleResponses = [];
            while (strlen($CollectedReceivedData) >= 9) {

                if ($CollectedReceivedData[0] == chr(43)) {
                    // we've a start byte "2B" in front -> package?

                    // first get the hole package (till beginning of next package (= 2B05, 2B02))
                    $nextPackage2B02Start = strpos($CollectedReceivedData, chr(43) . chr(2), 3);
                    $nextPackage2B05Start = strpos($CollectedReceivedData, chr(43) . chr(5), 3);
                    $nextPackageStart = 1000;
                    if (($nextPackage2B02Start != false) and ($nextPackage2B02Start <= $nextPackageStart)) $nextPackageStart = $nextPackage2B02Start;
                    if (($nextPackage2B05Start != false) and ($nextPackage2B05Start <= $nextPackageStart)) $nextPackageStart = $nextPackage2B05Start;

                    $singleResponse = substr($CollectedReceivedData, 0, $nextPackageStart);
                    $singleResponseBefore = $singleResponse;

                    $response = [];
                    $response['FullLength'] = strlen($singleResponse); // $response['Length']+5; // StartByte+Command+Length+CRC (incl. non conferted Bytes Stream!)

                    // first: Byte Stream Interpreting Rules (see communication protocol documentation)
                    $singleResponse = str_replace(chr(45) . chr(45), chr(45), $singleResponse);
                    $singleResponse = str_replace(chr(45) . chr(43), chr(43), $singleResponse);
                    $this->debugLog("Byte Stream Adoption: " . $this->decToHexString($singleResponseBefore) . " (before), " . $this->decToHexString($singleResponse) . " (after)");

                    $response['Command'] = $this->decToHexString($CollectedReceivedData[1]);
                    $response['Length'] = ord($CollectedReceivedData[2]);
                    if (strlen($CollectedReceivedData) < $response['Length'] + 5) {
                        // the remaining CollectedReceivedData is not long enough for the package
                        break; // while
                    }
                    $response['Address'] = $this->decToHexString(substr($singleResponse, 3, 4));
                    $response['Data'] = $this->decToHexString(substr($singleResponse, 7, $response['Length'] - 4));
                    $response['CRC'] = $this->decToHexString(substr($singleResponse, 3 + $response['Length'], 2));
                    $response['Complete'] = $singleResponse;

                    $calculatedCRC = $this->calcCRC($response['Command'] . $this->decToHexString($singleResponse[2]) . $response['Address'] . $response['Data']);

                    // shift data string for while statement
                    $CollectedReceivedData = substr($CollectedReceivedData, $response['FullLength']);

                    // check response
                    if ($response['Command'] <> '05') {
                        // we only look for command 05 = short response
                        $this->debugLog("Unexpected Command: " . $response['Command'] . ", PackageLength: " . $response['Length'] . ", Address: " . $response['Address'] . ", Data: " . $response['Data'] . ", CRC: " . $response['CRC'] . ", FullLength: " . $response['FullLength']);
                        continue;
                    }

                    if ($calculatedCRC != $response['CRC']) {
                        // CRC Check failed
                        $this->debugLog("CRC Error on Command: " . $response['Command'] . ", PackageLength: " . $response['Length'] . ", Address: " . $response['Address'] . ", Data: " . $response['Data'] . ", CRC: " . $response['CRC'] . ", FullLength: " . $response['FullLength'] . " - Calculated CRC: " . $calculatedCRC);
                        if ($this->ReadPropertyBoolean("IgnoreCRCErrors") == false) {
                            continue; // ignore responses with CRC errors
                        }
                    }

                    // add found response to resonpse stack
                    array_push($singleResponses, $response);

                } else {
                    // shift Data left by 1
                    $CollectedReceivedData = substr($CollectedReceivedData, 1);
                }
            } // while

            // get expected addresses in their sequence
            $RequestedAddressesSequence = json_decode($this->GetBuffer("RequestedAddressesSequence"));

            // check addres sequence is ok (ignoring duplicat addresses)
            $lastAddress = "";
            $y = 0;
            $sequenceOK = true;
            for ($x = 0; $x < count($singleResponses); $x++) {
                if ($singleResponses[$x]['Address'] != $lastAddress) {
                    if ($singleResponses[$x]['Address'] != $RequestedAddressesSequence[$y]) {
                        $this->debugLog("Sequence issue. Found Address " . $singleResponses[$x]['Address'] . " but expected Address " . $RequestedAddressesSequence[$y]);
                        $sequenceOK = false;
                    }
                    $lastAddress = $singleResponses[$x]['Address'];
                    $y++;
                }
            }

            if ($sequenceOK == false) {
                // if sequence is broken, we cannot rely on the results -> no analysis
                $this->debugLog("Sequence of requested addresses is not ok.");

                if ($this->ReadPropertyBoolean("IgnoreResponseSequence") == false) {
                    $this->debugLog("Analysis stopped as it's not sure if responses are for our requestes!");
                    $this->SetBuffer("CommunicationStatus", "Idle");
                    return;
                }
                $this->debugLog("Analysis still done (Response Sequence ignored!)");
                $this->debugLog("NOTE! RECEIVED DATA MIGHT NOT BE MEANT FOR OUR REQUEST. DATA INCONSISTENCY MIGHT BE THE RESULT!");
            } else {
                $this->debugLog("Sequence of requested addresses is ok.");
            }

            // Analyze Single Responses
            $lastAddress = "";
            for ($x = 0; $x < count($singleResponses); $x++) {
                if ($singleResponses[$x]['Address'] != $lastAddress) {
                    // if an address comes multiple times, only take the first values, as other values don't belong to this power inverter
                    $this->analyzeResponse($singleResponses[$x]['Address'], $singleResponses[$x]['Data']);
                }
                $lastAddress = $singleResponses[$x]['Address'];
            }

            $this->debugLog("Analysis completed");

        } catch (Exception $e) {
            $this->debugLog("Exception catched on Receiving data");
        } catch (\Throwable $e) {
            $this->debugLog("Throwable catched on Receiving data");
        }

        // reset data collection
        $RequestedAddressesSequence = [];
        $this->SetBuffer("RequestedAddressesSequence", json_encode($RequestedAddressesSequence));
        $this->SetBuffer("CommunicationStatus", "Idle"); // no more data expected

    }


    //=== Tool Functions ============================================================================================
    protected function analyzeResponse(string $address, string $data)
    {
        // precalculation
        $float = 0.0;
        if (strlen($data) == 8) {
            $float = $this->hexTo32Float($data);
            // Debug output
            $this->debugLog("Address " . $address . " with data " . $data . " (as Float " . number_format($float, 2) . ")");
        }

        if (strlen($data) > 8) {
            $string = $this->hexToString($data);
            // Debug output
            $this->debugLog("Address " . $address . " with data " . $data . " (as String " . $string . ")");
        }

        try {
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
                    $this->SetValue("DCInputAVoltage", round($float, 0));
                    break;

                case "B0041187": // DC input B voltage [V], Float (by Documentation 5BB8075A)
                    $this->SetValue("DCInputBVoltage", round($float, 0));
                    break;

                case "DB11855B": // DC input A power [W], Float
                    $this->SetValue("DCInputAPower", round($float, 0));
                    $PanelMaxA = $this->ReadPropertyInteger("InputAPanelCount") * $this->ReadPropertyInteger("InputANominalPowerPerPanel");
                    $PanelMaxB = $this->ReadPropertyInteger("InputBPanelCount") * $this->ReadPropertyInteger("InputBNominalPowerPerPanel");
                    if ($PanelMaxA > 0) {
                        // Calculate Input A Utilization
                        $Utilization = $float / $PanelMaxA * 100;
                        $this->SetValue("DCInputAUtilization", round($Utilization, 1));
                        if ($PanelMaxB == 0)
                            $this->SetValue("DCInputUtilization", round($Utilization, 1));
                    }
                    $PanelMaxTotal = $PanelMaxA + $PanelMaxB;
                    if ($PanelMaxTotal == 0)
                        $this->SetValue("DCInputUtilization", 0);
                    else {
                        // Calculate Total Input Power and Utilization
                        $TotalPowerInput = GetValueInteger($this->GetIDForIdent("DCInputAPower")) + GetValueInteger($this->GetIDForIdent("DCInputBPower"));
                        $Utilization = $TotalPowerInput / $PanelMaxTotal * 100;
                        $this->SetValue("DCInputUtilization", round($Utilization, 1));
                        $this->SetValue("DCInputPower", round($TotalPowerInput, 0));
                    }

                    break;

                case "0CB5D21B": // DC input B power [W], Float
                    $this->SetValue("DCInputBPower", round($float, 0));
                    $PanelMaxA = $this->ReadPropertyInteger("InputAPanelCount") * $this->ReadPropertyInteger("InputANominalPowerPerPanel");
                    $PanelMaxB = $this->ReadPropertyInteger("InputBPanelCount") * $this->ReadPropertyInteger("InputBNominalPowerPerPanel");
                    if ($PanelMaxB > 0) {
                        // Calculate Input B Utilization
                        $Utilization = $float / $PanelMaxB * 100;
                        $this->SetValue("DCInputBUtilization", round($Utilization, 1));
                        if ($PanelMaxA == 0)
                            $this->SetValue("DCInputUtilization", round($Utilization, 1));
                    }
                    $PanelMaxTotal = $PanelMaxA + $PanelMaxB;
                    if ($PanelMaxTotal == 0)
                        $this->SetValue("DCInputUtilization", 0);
                    else {
                        // Calculate Total Input Power and Utilization
                        $TotalPowerInput = GetValueInteger($this->GetIDForIdent("DCInputAPower")) + GetValueInteger($this->GetIDForIdent("DCInputBPower"));
                        $Utilization = $TotalPowerInput / $PanelMaxTotal * 100;
                        $this->SetValue("DCInputUtilization", round($Utilization, 1));
                        $this->SetValue("DCInputPower", round($TotalPowerInput, 0));
                    }

                    break;

                case "B408E40A": // Battery current measured by inverter, low pass filter with Tau = 1s [A], Float
                    break;

                case "A7FA5C5D": // "Battery voltage [V], Float
                    $this->SetValue("BatteryVoltage", round($float, 0));
                    break;

                case "959930BF": // Battery State of Charge (SoC) [0..1], Float
                    $this->SetValue("BatterySoC", round($float * 100, 1));
                    break;

                case "400F015B": // Battery power (positive if discharge) [W], Float
                    $this->SetValue("BatteryPower", round($float, 0));
                    break;

                case "902AFAFB": // Battery temperature [Grad C], Float
                    $this->SetValue("BatteryTemperature", round($float, 1));
                    break;

                case "91617C58": // Public grid power (house connection, negative by feed-in) [W], Float
                    $this->SetValue("PublicGridPower", round($float, 0));
                    break;

                case "E96F1844": // External power (additional inverters/generators in house internal grid) [W], Float
                    $this->SetValue("ExternalPower", round($float, 0));
                    break;

                //--- Energy Today
                case "BD55905F": // Todays energy [Wh], Float
                    $this->SetValue("EnergyDayEnergy", round($float, 0));
                    // Calculate FeedInLevel etc.
                    $FeedInLevel = 0.0;
                    if ((GetValueInteger($this->GetIDForIdent("EnergyDayGridFeedIn")) > 0) and (GetValueInteger($this->GetIDForIdent("EnergyDayEnergy")) > 0))
                        $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyDayGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyDayEnergy")) * 100;
                    $this->SetValue("EnergyDayGridFeedInLevel", round($FeedInLevel, 0));
                    $SelfConsumptionLevel = 0.0;
                    if (GetValueInteger($this->GetIDForIdent("EnergyDayEnergy")) > 0)
                        $SelfConsumptionLevel = 100 - $FeedInLevel;
                    if ($SelfConsumptionLevel >= 0 and $SelfConsumptionLevel <= 100) {
                        $this->SetValue("EnergyDaySelfConsumptionLevel", round($SelfConsumptionLevel, 0));
                    }
                    break;

                case "2AE703F2": // Tagesenergie Ertrag Input A in Wh
                    $this->SetValue("EnergyDayPVEarningInputA", round($float, 0));
                    if (GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputA")) > 0) {
                        $AB = GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputB"));
                        $this->SetValue("EnergyDayPVEarningInputAB", round($AB, 0));
                    }
                    if ((GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputA")) == 0) and (GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputB")) == 0)) {
                        $this->SetValue("EnergyDayPVEarningInputAB", 0);
                    }
                    break;

                case "FBF3CE97": // Tagesenergie Ertrag Input B in Wh
                    $this->SetValue("EnergyDayPVEarningInputB", round($float, 0));
                    if (GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputB")) > 0) {
                        $AB = GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputB"));
                        $this->SetValue("EnergyDayPVEarningInputAB", round($AB, 0));
                    }
                    if ((GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputA")) == 0) and (GetValueInteger($this->GetIDForIdent("EnergyDayPVEarningInputB")) == 0)) {
                        $this->SetValue("EnergyDayPVEarningInputAB", 0);
                    }
                    break;

                case "3C87C4F5": // Tagesenergie Netzeinspeisung in -Wh
                    $float = $float * -1;
                    $this->SetValue("EnergyDayGridFeedIn", round($float, 0));
                    // Calculate FeedInLevel etc.
                    $FeedInLevel = 0.0;
                    if ((GetValueInteger($this->GetIDForIdent("EnergyDayGridFeedIn")) > 0) and (GetValueInteger($this->GetIDForIdent("EnergyDayEnergy")) > 0))
                        $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyDayGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyDayEnergy")) * 100;
                    $this->SetValue("EnergyDayGridFeedInLevel", round($FeedInLevel, 0));
                    $SelfConsumptionLevel = 0.0;
                    if (GetValueInteger($this->GetIDForIdent("EnergyDayEnergy")) > 0)
                        $SelfConsumptionLevel = 100 - $FeedInLevel;
                    if ($SelfConsumptionLevel >= 0 and $SelfConsumptionLevel <= 100) {
                        $this->SetValue("EnergyDaySelfConsumptionLevel", round($SelfConsumptionLevel, 0));
                    }
                    break;

                case "867DEF7D": // Tagesenergie Netzverbrauch in Wh
                    $this->SetValue("EnergyDayGridUsage", round($float, 0));
                    if (GetValueInteger($this->GetIDForIdent("EnergyDayHouseholdTotal")) != 0) {
                        // Calculate AutonomousPowerLevel etc.
                        $GridPowerLevel = GetValueInteger($this->GetIDForIdent("EnergyDayGridUsage")) / GetValueInteger(
                                $this->GetIDForIdent("EnergyDayHouseholdTotal")
                            ) * 100;
                        $this->SetValue("EnergyDayGridPowerLevel", round($GridPowerLevel, 0));
                        $AutonomousPowerLevel = 100 - $GridPowerLevel;
                        if ($AutonomousPowerLevel >= 0 and $AutonomousPowerLevel <= 100) {
                            $this->SetValue("EnergyDayAutonomousPowerLevel", round($AutonomousPowerLevel, 0));
                        }
                    }
                    break;

                case "2F3C1D7D": // Tagesenergie Haushalt in Wh
                    $this->SetValue("EnergyDayHouseholdTotal", round($float, 0));
                    if (GetValueInteger($this->GetIDForIdent("EnergyDayHouseholdTotal")) != 0) {
                        // Calculate AutonomousPowerLevel etc.
                        $GridPowerLevel = GetValueInteger($this->GetIDForIdent("EnergyDayGridUsage")) / GetValueInteger(
                                $this->GetIDForIdent("EnergyDayHouseholdTotal")
                            ) * 100;
                        $this->SetValue("EnergyDayGridPowerLevel", round($GridPowerLevel, 0));
                        $AutonomousPowerLevel = 100 - $GridPowerLevel;
                        if ($AutonomousPowerLevel >= 0 and $AutonomousPowerLevel <= 100) {
                            $this->SetValue("EnergyDayAutonomousPowerLevel", round($AutonomousPowerLevel, 0));
                        }
                    }
                    break;

                //--- Energy Month
                case "10970E9D": // This month energy [Wh], Float
                    $this->SetValue("EnergyMonthEnergy", round($float, 0));
                    // Calculate FeedInLevel etc.
                    $FeedInLevel = 0.0;
                    if ((GetValueInteger($this->GetIDForIdent("EnergyMonthGridFeedIn")) > 0) and (GetValueInteger($this->GetIDForIdent("EnergyMonthEnergy")) > 0))
                        $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyMonthGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyMonthEnergy")) * 100;
                    $this->SetValue("EnergyMonthGridFeedInLevel", round($FeedInLevel, 0));
                    $SelfConsumptionLevel = 0.0;
                    if (GetValueInteger($this->GetIDForIdent("EnergyMonthEnergy")) > 0)
                        $SelfConsumptionLevel = 100 - $FeedInLevel;
                    if ($SelfConsumptionLevel >= 0 and $SelfConsumptionLevel <= 100) {
                        $this->SetValue("EnergyDaySelfConsumptionLevel", round($SelfConsumptionLevel, 0));
                    }
                    break;

                case "81AE960B": // Monatsenergie Ertrag Input A in Wh
                    $this->SetValue("EnergyMonthPVEarningInputA", round($float, 0));
                    $AB = GetValueInteger($this->GetIDForIdent("EnergyMonthPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyMonthPVEarningInputB"));
                    $this->SetValue("EnergyMonthPVEarningInputAB", round($AB, 0));
                    break;

                case "7AB9B045": // Monatsenergie Ertrag Input B in Wh
                    $this->SetValue("EnergyMonthPVEarningInputB", round($float, 0));
                    $AB = GetValueInteger($this->GetIDForIdent("EnergyMonthPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyMonthPVEarningInputB"));
                    $this->SetValue("EnergyMonthPVEarningInputAB", round($AB, 0));
                    break;

                case "65B624AB": // Monatsenergie Netzeinspeisung ins Netz in -Wh
                    $float = $float * -1;
                    $this->SetValue("EnergyMonthGridFeedIn", round($float, 0));
                    // Calculate FeedInLevel etc.
                    $FeedInLevel = 0.0;
                    if ((GetValueInteger($this->GetIDForIdent("EnergyMonthGridFeedIn")) > 0) and (GetValueInteger($this->GetIDForIdent("EnergyMonthEnergy")) > 0))
                        $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyMonthGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyMonthEnergy")) * 100;
                    $this->SetValue("EnergyMonthGridFeedInLevel", round($FeedInLevel, 0));
                    $SelfConsumptionLevel = 0.0;
                    if (GetValueInteger($this->GetIDForIdent("EnergyMonthEnergy")) > 0)
                        $SelfConsumptionLevel = 100 - $FeedInLevel;
                    if ($SelfConsumptionLevel >= 0 and $SelfConsumptionLevel <= 100) {
                        $this->SetValue("EnergyMonthSelfConsumptionLevel", round($SelfConsumptionLevel, 0));
                    }
                    break;

                case "126ABC86": // Monatsenergie Netzverbrauch in Wh
                    $this->SetValue("EnergyMonthGridUsage", round($float, 0));
                    if (GetValueInteger($this->GetIDForIdent("EnergyMonthHouseholdTotal")) != 0) {
                        // Calculate AutonomousPowerLevel etc.
                        $GridPowerLevel = GetValueInteger($this->GetIDForIdent("EnergyMonthGridUsage")) / GetValueInteger(
                                $this->GetIDForIdent("EnergyMonthHouseholdTotal")
                            ) * 100;
                        $this->SetValue("EnergyMonthGridPowerLevel", round($GridPowerLevel, 0));
                        $AutonomousPowerLevel = 100 - $GridPowerLevel;
                        if ($AutonomousPowerLevel >= 0 and $AutonomousPowerLevel <= 100) {
                            $this->SetValue("EnergyMonthAutonomousPowerLevel", round($AutonomousPowerLevel, 0));
                        }
                    }
                    break;

                case "F0BE6429": // Monatsenergie Haushalt in Wh
                    $this->SetValue("EnergyMonthHouseholdTotal", round($float, 0));
                    if (GetValueInteger($this->GetIDForIdent("EnergyMonthHouseholdTotal")) != 0) {
                        // Calculate AutonomousPowerLevel etc.
                        $GridPowerLevel = GetValueInteger($this->GetIDForIdent("EnergyMonthGridUsage")) / GetValueInteger(
                                $this->GetIDForIdent("EnergyMonthHouseholdTotal")
                            ) * 100;
                        $this->SetValue("EnergyMonthGridPowerLevel", round($GridPowerLevel, 0));
                        $AutonomousPowerLevel = 100 - $GridPowerLevel;
                        if ($AutonomousPowerLevel >= 0 and $AutonomousPowerLevel <= 100) {
                            $this->SetValue("EnergyMonthAutonomousPowerLevel", round($AutonomousPowerLevel, 0));
                        }
                    }
                    break;

                //--- Energy Year
                case "C0CC81B6": // This year energy [Wh], Float
                    $this->SetValue("EnergyYearEnergy", round($float, 0));
                    // Calculate FeedInLevel etc.
                    $FeedInLevel = 0.0;
                    if ((GetValueInteger($this->GetIDForIdent("EnergyYearGridFeedIn")) > 0) and (GetValueInteger($this->GetIDForIdent("EnergyYearEnergy")) > 0))
                        $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyYearGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyYearEnergy")) * 100;
                    $this->SetValue("EnergyYearGridFeedInLevel", round($FeedInLevel, 0));
                    $SelfConsumptionLevel = 0.0;
                    if (GetValueInteger($this->GetIDForIdent("EnergyYearEnergy")) > 0)
                        $SelfConsumptionLevel = 100 - $FeedInLevel;
                    if ($SelfConsumptionLevel >= 0 and $SelfConsumptionLevel <= 100) {
                        $this->SetValue("EnergyYearSelfConsumptionLevel", round($SelfConsumptionLevel, 0));
                    }
                    break;

                case "AF64D0FE": // Jahresenergie Ertrag Input A in Wh
                    $this->SetValue("EnergyYearPVEarningInputA", round($float, 0));
                    $AB = GetValueInteger($this->GetIDForIdent("EnergyYearPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyYearPVEarningInputB"));
                    $this->SetValue("EnergyYearPVEarningInputAB", round($AB, 0));
                    break;

                case "BD55D796": // Jahresenergie Ertrag Input B in Wh
                    $this->SetValue("EnergyYearPVEarningInputB", round($float, 0));
                    $AB = GetValueInteger($this->GetIDForIdent("EnergyYearPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyYearPVEarningInputB"));
                    $this->SetValue("EnergyYearPVEarningInputAB", round($AB, 0));
                    break;

                case "26EFFC2F": // Jahresenergie Netzinspeisung ins Netz in -Wh
                    $float = $float * -1;
                    $this->SetValue("EnergyYearGridFeedIn", round($float, 0));
                    // Calculate FeedInLevel etc.
                    $FeedInLevel = 0.0;
                    if ((GetValueInteger($this->GetIDForIdent("EnergyYearGridFeedIn")) > 0) and (GetValueInteger($this->GetIDForIdent("EnergyYearEnergy")) > 0))
                        $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyYearGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyYearEnergy")) * 100;
                    $this->SetValue("EnergyYearGridFeedInLevel", round($FeedInLevel, 0));
                    $SelfConsumptionLevel = 0.0;
                    if (GetValueInteger($this->GetIDForIdent("EnergyYearEnergy")) > 0)
                        $SelfConsumptionLevel = 100 - $FeedInLevel;
                    $this->SetValue("EnergyYearSelfConsumptionLevel", round($SelfConsumptionLevel, 0));
                    break;

                case "DE17F021": // Jahresenergie Netzverbrauch in Wh
                    $this->SetValue("EnergyYearGridUsage", round($float, 0));
                    // Calculate AutonomousPowerLevel etc.
                    $GridPowerLevel = GetValueInteger($this->GetIDForIdent("EnergyYearGridUsage")) / GetValueInteger($this->GetIDForIdent("EnergyYearHouseholdTotal")) * 100;
                    $this->SetValue("EnergyYearGridPowerLevel", round($GridPowerLevel, 0));
                    $AutonomousPowerLevel = 100 - $GridPowerLevel;
                    if ($AutonomousPowerLevel >= 0 and $AutonomousPowerLevel <= 100) {
                        $this->SetValue("EnergyYearAutonomousPowerLevel", round($AutonomousPowerLevel, 0));
                    }
                    break;

                case "C7D3B479": // Jahresenergie Haushalt in Wh
                    $this->SetValue("EnergyYearHouseholdTotal", round($float, 0));
                    // Calculate AutonomousPowerLevel etc.
                    $GridPowerLevel = GetValueInteger($this->GetIDForIdent("EnergyYearGridUsage")) / GetValueInteger($this->GetIDForIdent("EnergyYearHouseholdTotal")) * 100;
                    $this->SetValue("EnergyYearGridPowerLevel", round($GridPowerLevel, 0));
                    $AutonomousPowerLevel = 100 - $GridPowerLevel;
                    if ($AutonomousPowerLevel >= 0 and $AutonomousPowerLevel <= 100) {
                        $this->SetValue("EnergyYearAutonomousPowerLevel", round($AutonomousPowerLevel, 0));
                    }
                    break;

                //--- Energy Total
                case "B1EF67CE": // Total Energy [Wh], Float
                    $this->SetValue("EnergyTotalEnergy", round($float, 0));
                    // Calculate FeedInLevel etc.
                    $FeedInLevel = 0.0;
                    if ((GetValueInteger($this->GetIDForIdent("EnergyTotalGridFeedIn")) > 0) and (GetValueInteger($this->GetIDForIdent("EnergyTotalEnergy")) > 0))
                        $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyTotalGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyTotalEnergy")) * 100;
                    $this->SetValue("EnergyTotalGridFeedInLevel", round($FeedInLevel, 0));
                    $SelfConsumptionLevel = 0.0;
                    if (GetValueInteger($this->GetIDForIdent("EnergyTotalEnergy")) > 0)
                        $SelfConsumptionLevel = 100 - $FeedInLevel;
                    if ($SelfConsumptionLevel >= 0 and $SelfConsumptionLevel <= 100) {
                        $this->SetValue("EnergyTotalSelfConsumptionLevel", round($SelfConsumptionLevel, 0));
                    }
                    break;

                case "FC724A9E": // Gesamtenergie Ertrag Input A in Wh
                    $this->SetValue("EnergyTotalPVEarningInputA", round($float, 0));
                    $AB = GetValueInteger($this->GetIDForIdent("EnergyTotalPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyTotalPVEarningInputB"));
                    $this->SetValue("EnergyTotalPVEarningInputAB", round($AB, 0));
                    break;

                case "68EEFD3D": // Gesamtenergie Ertrag Input B in Wh
                    $this->SetValue("EnergyTotalPVEarningInputB", round($float, 0));
                    $AB = GetValueInteger($this->GetIDForIdent("EnergyTotalPVEarningInputA")) + GetValueInteger($this->GetIDForIdent("EnergyTotalPVEarningInputB"));
                    $this->SetValue("EnergyTotalPVEarningInputAB", round($AB, 0));
                    break;

                case "44D4C533": // Gesamtenergie Netzeinspeisung in -Wh
                    $float = $float * -1;
                    $this->SetValue("EnergyTotalGridFeedIn", round($float, 0));
                    // Calculate FeedInLevel etc.
                    $FeedInLevel = 0.0;
                    if ((GetValueInteger($this->GetIDForIdent("EnergyTotalGridFeedIn")) > 0) and (GetValueInteger($this->GetIDForIdent("EnergyTotalEnergy")) > 0))
                        $FeedInLevel = GetValueInteger($this->GetIDForIdent("EnergyTotalGridFeedIn")) / GetValueInteger($this->GetIDForIdent("EnergyTotalEnergy")) * 100;
                    $this->SetValue("EnergyTotalGridFeedInLevel", round($FeedInLevel, 0));
                    $SelfConsumptionLevel = 0.0;
                    if (GetValueInteger($this->GetIDForIdent("EnergyTotalEnergy")) > 0)
                        $SelfConsumptionLevel = 100 - $FeedInLevel;
                    if ($SelfConsumptionLevel >= 0 and $SelfConsumptionLevel <= 100) {
                        $this->SetValue("EnergyTotalSelfConsumptionLevel", round($SelfConsumptionLevel, 0));
                    }
                    break;

                case "62FBE7DC": // Gesamtenergie Netzverbrauch in Wh
                    $this->SetValue("EnergyTotalGridUsage", round($float, 0));
                    $GridPowerLevel = GetValueInteger($this->GetIDForIdent("EnergyTotalGridUsage")) / GetValueInteger($this->GetIDForIdent("EnergyTotalHouseholdTotal")) * 100;
                    $this->SetValue("EnergyTotalGridPowerLevel", round($GridPowerLevel, 0));
                    $AutonomousPowerLevel = 100 - $GridPowerLevel;
                    if ($AutonomousPowerLevel >= 0 and $AutonomousPowerLevel <= 100) {
                        $this->SetValue("EnergyTotalAutonomousPowerLevel", round($AutonomousPowerLevel, 0));
                    }
                    break;

                case "EFF4B537": // Gesamtenergie Haushalt in Wh
                    $this->SetValue("EnergyTotalHouseholdTotal", round($float, 0));
                    // Calculate AutonomousPowerLevel etc.
                    $GridPowerLevel = GetValueInteger($this->GetIDForIdent("EnergyTotalGridUsage")) / GetValueInteger($this->GetIDForIdent("EnergyTotalHouseholdTotal")) * 100;
                    $this->SetValue("EnergyTotalGridPowerLevel", round($GridPowerLevel, 0));
                    $AutonomousPowerLevel = 100 - $GridPowerLevel;
                    if ($AutonomousPowerLevel >= 0 and $AutonomousPowerLevel <= 100) {
                        $this->SetValue("EnergyTotalAutonomousPowerLevel", round($AutonomousPowerLevel, 0));
                    }
                    break;

                case "FE1AA500": // External Power Limit [0..1], Float
                    break;

                case "BD008E29": // External battery power target [W] (positive = discharge), Float
                    break;

                case "872F380B": // External load demand [W] (positive = feed in / 0=internal ), Float
                    break;

                //--- NOT DOCUMENTED !!! -------------------------------------------------------------------------
                case "8B9FF008": // Upper load boundary in %
                    $this->SetValue("BatteryUpperSoC", round($float * 100, 0));

                    $GrossCapacity = GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity"));
                    $RemainingPercentage = GetValueFloat($this->GetIDForIdent("BatterySoC")) - $this->ReadPropertyInteger("LowerSoCLevel");
                    if ($RemainingPercentage < 0) $RemainingPercentage = 0;
                    $RemainingCapacity = $GrossCapacity / 100 * $RemainingPercentage;
                    $this->SetValue("BatteryRemainingNetCapacity", round($RemainingCapacity, 2));
                    break;

                case "4BC0F974": // Installed PV Power kWp (was <V1.0 "gross battery capacity kwh" - error!)
                    $this->SetValue("InstalledPVPower", round($float / 1000, 2));
                    break;

                case "1AC87AA0": // Current House power consumption
                    $this->SetValue("HousePowerCurrent", round($float, 0));
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
                    if (substr($string, 1, 3) != "181") {
                        // we don't have a battery stack -> Battery Capacity is 0 kWh
                        $this->SetValue("BatteryGrossCapacity", 0);
                    } elseif (GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity")) < 1.9) {
                        $this->SetValue("BatteryGrossCapacity", 1.9);
                    }
                    break;

                case "99396810": // Battery Stack 1 serial number
                    if (substr($string, 1, 3) <> "181") {
                        // we don't have a 2nd stack panel -> Battery Capacity is max. 1.9kWh
                        if (GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity")) > 1.9)
                            $this->SetValue("BatteryGrossCapacity", 1.9);
                    } elseif (GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity")) < 3.8) {
                        // we have at least 2 stack panels -> Battery Capacity is min. 3.8
                        $this->SetValue("BatteryGrossCapacity", 3.8);
                    }
                    break;

                case "73489528": // Battery Stack 2 serial number
                    if (substr($string, 1, 3) <> "181") {
                        // we don't have a 3nd stack panel -> Battery Capacity is max. 3.8kWh
                        if (GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity")) > 3.8)
                            $this->SetValue("BatteryGrossCapacity", 3.8);
                    } elseif (GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity")) < 5.7) {
                        // we have at least 3 stack panels -> Battery Capacity is min. 5.7
                        $this->SetValue("BatteryGrossCapacity", 5.7);
                    }
                    break;

                case "257B7612": // Battery Stack 3 serial number
                    if (substr($string, 1, 3) <> "181") {
                        // we don't have a 4th stack panel -> Battery Capacity is max. 5.7kWh
                        if (GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity")) > 5.7)
                            $this->SetValue("BatteryGrossCapacity", 5.7);
                    } elseif (GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity")) < 7.6) {
                        // we have at least 4 stack panels -> Battery Capacity is min. 7.6
                        $this->SetValue("BatteryGrossCapacity", 7.6);
                    }
                    break;

                case "4E699086": // Battery Stack 4 serial number
                    if (substr($string, 1, 3) <> "181") {
                        // we don't have a 5th stack panel -> Battery Capacity is max. 7.6kWh
                        if (GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity")) > 7.6)
                            $this->SetValue("BatteryGrossCapacity", 7.6);
                    } elseif (GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity")) < 9.6) {
                        // we have at least 5 stack panels -> Battery Capacity is min. 9.6
                        $this->SetValue("BatteryGrossCapacity", 9.6);
                    }
                    break;

                case "162491E8": // Battery Stack 5 serial number
                    if (substr($string, 1, 3) <> "181") {
                        // we don't have a 5th stack panel -> Battery Capacity is max. 9.6kWh
                        if (GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity")) > 9.6)
                            $this->SetValue("BatteryGrossCapacity", 9.6);
                    } elseif (GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity")) < 11.5) {
                        // we have at least 6 stack panels -> Battery Capacity is min. 11.5
                        $this->SetValue("BatteryGrossCapacity", 11.5);
                    }
                    break;

                case "5939EC5D": // Battery Stack 6 serial number
                    if (substr($string, 1, 3) <> "181") {
                        // we don't have a 6th stack panel -> Battery Capacity is max. 11.5kWh
                        if (GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity")) > 11.5)
                            $this->SetValue("BatteryGrossCapacity", 11.5);
                    } elseif (GetValueFloat($this->GetIDForIdent("BatteryGrossCapacity")) < 13.4) {
                        // we have at least 7 stack panels -> Battery Capacity is min. 13.4
                        $this->SetValue("BatteryGrossCapacity", 13.4);
                    }
                    break;

                //--- Ignore -------------------------------------------------------------------------------------
                case "EBC62737": // Inverter Description
                    break;

                //--- Default Handling ---------------------------------------------------------------------------
                default:         // Unknown response
                    $this->debugLog("Unkown Response Address " . $address . " with data " . $data . " (as Float " . number_format($float, 2) . ")");

            }
        } catch (\Exception $e) {
            $this->debugLog("Error processing address " . $address);
            $this->debugLog("Exception catched: " . $e->getMessage());
        } catch (\Throwable $e) {
            $this->debugLog("Error processing address " . $address);
            $this->debugLog("Exception catched: " . $e->getMessage());
        }
    }

    protected function RequestData(string $command, int $length = 4)
    {
        $RequestAddress = $command;

        // build command
        $hexlength = strtoupper(dechex($length));
        if (strlen($hexlength) == 1) $hexlength = '0' . $hexlength;
        $command = "01" . $hexlength . $command;
        $command = "2B" . $command . $this->calcCRC($command);
        $hexCommand = "";
        for ($x = 0; $x < strlen($command) / 2; $x++)
            $hexCommand = $hexCommand . chr(hexdec(substr($command, $x * 2, 2)));

        // Store Address to Requested Addresses Buffer
        $this->debugLog("Request Data ".$command);
        $RequestedAddressesSequence = json_decode($this->GetBuffer("RequestedAddressesSequence"));
        array_push($RequestedAddressesSequence, $RequestAddress);
        // Remind Requested Address
        $this->SetBuffer("RequestedAddressesSequence", json_encode($RequestedAddressesSequence));

        // send Data to Parent (IO)...
        $this->SendDataToParent(json_encode(array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", "Buffer" => utf8_encode($hexCommand))));

        // slow down Transmit frequency to avoid client server connection get wrong packages with CRC issues
        // In 5.5 a value of 50.000 was successfull, while in 5.4 100.000 is necessary!
        usleep(100000);
    }

    protected function calcCRC(string $command)
    {
        $commandLength = strlen($command) / 2;
        if ($commandLength % 2 != 0) {
            // Command with an odd byte length (add 0x00 to make odd!) without(!) start byte (0x2B)
            $command = $command . '00';
            $commandLength = strlen($command) / 2;
        }
        $crc = 0xFFFF;
        for ($x = 0; $x < $commandLength; $x++) {
            $b = hexdec(substr($command, $x * 2, 2));
            for ($i = 0; $i < 8; $i++) {
                $bit = (($b >> (7 - $i) & 1) == 1);
                $c15 = ((($crc >> 15) & 1) == 1);
                $crc <<= 1;
                if ($c15 ^ $bit) $crc ^= 0x1021;
            }
            $crc &= 0xffff;
        }
        $crc = strtoupper(dechex($crc));
        // if the CRC is too short, add '0' at the beginning
        if (strlen($crc) == 2) $crc = '00' . $crc;
        if (strlen($crc) == 3) $crc = '0' . $crc;
        return $crc;
    }

    protected function hexTo32Float(string $strHex)
    {
        $bin = str_pad(base_convert($strHex, 16, 2), 32, "0", STR_PAD_LEFT);
        $sign = $bin[0];
        $v = hexdec($strHex);
        $x = ($v & ((1 << 23) - 1)) + (1 << 23) * ($v >> 31 | 1);
        $exp = ($v >> 23 & 0xFF) - 127;
        return $x * pow(2, $exp - 23) * ($sign ? -1 : 1);
    }

    protected function hexToString(string $hex)
    {
        if (strlen($hex) % 2 != 0) {
            return "";
        }
        $string = '';
        for ($i = 0; $i < strlen($hex) - 1; $i += 2) {
            if (hexdec($hex[$i] . $hex[$i + 1]) >= 32)
                $string .= chr(hexdec($hex[$i] . $hex[$i + 1]));
        }
        return $string;
    }

    protected function decToHexString(string $data)
    {
        $result = "";
        for ($x = 0; $x < strlen($data); $x++) {
            if (strlen(dechex(ord($data[$x]))) < 2) {
                $result = $result . "0";
            }
            $result = $result . strtoupper(dechex(ord($data[$x])));
        }
        return $result;
    }


    //=== Module Prefix Functions ===================================================================================
    /* Own module functions called via the defined prefix RCTPOWERINVERTER_*
    *
    * - RCTPOWERINVERTER_*($id);
    *
    */

    public function UpdateData()
    {
        /* get Data from RCT Power Inverter */
        $this->debugLog("UpdateData() called");

        if ($this->GetBuffer("CommunicationStatus") != "Idle") {
            // own communication still running!!
            $this->debugLog("Old UpdateData still pending! Clearing old Update Process");
            $alreadyhappened = $this->GetBuffer("UpdateWhilePreviousUpdate");
            if ($alreadyhappened >= 2) {
                $this->SetBuffer("CommunicationStatus", "Idle");
            } else {
                $alreadyhappened = $alreadyhappened + 1;
                $this->SetBuffer("UpdateWhilePreviousUpdate", $alreadyhappened);
                return false;
            }
        }

        $this->SetBuffer("UpdateWhilePreviousUpdate", 0);

        ///--- HANDLE Connection --------------------------------------------------------------------------------------
        // check Socket Connection (parent)
        $SocketConnectionInstanceID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($SocketConnectionInstanceID == 0) {
            $this->debugLog("No Parent (Gateway) assigned");
            return false; // No parent assigned
        }

        if (!$this->HasActiveParent()) {
            $this->debugLog("Parent Gateway not open!");
            return false; // wrong parent type
        }


        // GET SEMAPHORE TO AVOID PARALLEL ACCESS BY OTHER RCT POWER INVERTER INSTANCES!!!
        if (IPS_SemaphoreEnter("RCTPowerInverterUpdateData", 8000) == false) {
            // wait max. 8 sec. for semaphore
            $this->debugLog("Semaphore could not be entered");
            return false; // Semaphore not available
        }

        try {

            $this->debugLog("Semaphore RCTPowerInverterUpdateData entered");

            // Init Communication -----------------------------------------------------------------------------------------
            // Clear Buffer for Requested Addresses (Stack!)
            $RequestedAddressesSequence = [];
            $this->SetBuffer("RequestedAddressesSequence", json_encode($RequestedAddressesSequence));
            $this->SetBuffer("CommunicationStatus", "WAITING FOR RESPONSES"); // we're now requesting data -> receive and analyze it

            // Request Data -----------------------------------------------------------------------------------------------

            // $this->RequestData( "DB2D69AE" ); // Actual inverters AC-power [W]. ---> NO RESPONSE!

            // $this->RequestData( "CF053085" ); // Phase L1 voltage [V] --> not used
            // $this->RequestData( "54B4684E" ); // Phase L2 voltage [V] --> not used
            // $this->RequestData( "2545E22D" ); // Phase L3 voltage [V] --> not used

            $this->RequestData("B55BA2CE"); // DC input A voltage [V] (by Documentation B298395D)
            $this->RequestData("DB11855B"); // DC input A power [W]

            $this->RequestData("B0041187"); // DC input B voltage [V] (by Documentation 5BB8075A)
            $this->RequestData("0CB5D21B"); // DC input B power [W]

            // $this->RequestData( "B408E40A" ); usleep( 100000 ); // Battery current measured by inverter, low pass filter with Tau = 1s [A]

            $this->RequestData("A7FA5C5D"); // Battery voltage [V]
            $this->RequestData("959930BF"); // Battery State of Charge (SoC) [0..1]
            $this->RequestData("400F015B"); // Battery power (positive if discharge) [W]
            $this->RequestData("902AFAFB"); // Battery temperature [°C]

            $this->RequestData("91617C58"); // Public grid power (house connection, negative by feed-in) [W]

            $this->RequestData("E96F1844"); // External power (additional inverters/generators in house internal grid) [W]

            //--- Request Energies -------------------------------------
            // Todays Energy
            $this->RequestData("BD55905F"); // Todays energy [Wh]
            $this->RequestData("2AE703F2"); // Tagesenergie Ertrag Input A in Wh
            $this->RequestData("FBF3CE97"); // Tagesenergie Ertrag Input B in Wh
            $this->RequestData("3C87C4F5"); // Tagesenergie Netzeinspeisung in -Wh
            $this->RequestData("867DEF7D"); // Tagesenergie Netzverbrauch	in Wh
            $this->RequestData("2F3C1D7D"); // Tagesenergie Haushalt in Wh

            // Month Energy
            $this->RequestData("10970E9D"); // This month energy [Wh]
            $this->RequestData("81AE960B"); // Monatsenergie Ertrag Input A in Wh
            $this->RequestData("7AB9B045"); // Monatsenergie Ertrag Input B in Wh
            $this->RequestData("65B624AB"); // Monatsenergie Netzeinspeisung ins Netz in -Wh
            $this->RequestData("126ABC86"); // Monatsenergie Netzverbrauch in Wh
            $this->RequestData("F0BE6429"); // Monatsenergie Haushalt in Wh

            // Year Energy
            $this->RequestData("C0CC81B6"); // This year energy [Wh]
            $this->RequestData("AF64D0FE"); // Jahresenergie Ertrag Input A in Wh
            $this->RequestData("BD55D796"); // Jahresenergie Ertrag Input B in Wh
            $this->RequestData("26EFFC2F");  // Jahresenergie Netzinspeisung ins Netz in -Wh
            $this->RequestData("DE17F021"); // Jahresenergie Netzverbrauch in Wh
            $this->RequestData("C7D3B479"); // Jahresenergie Haushalt in Wh

            // Total Energy
            $this->RequestData("B1EF67CE"); // Total Energy [Wh]
            $this->RequestData("FC724A9E"); // Gesamtenergie Ertrag Input A in Wh
            $this->RequestData("68EEFD3D"); // Gesamtenergie Ertrag Input B in Wh
            $this->RequestData("44D4C533"); // Gesamtenergie Netzeinspeisung in -Wh
            $this->RequestData("62FBE7DC"); // Gesamtenergie Netzverbrauch in Wh
            $this->RequestData("EFF4B537"); // Gesamtenergie Haushalt in Wh


            // $this->RequestData( "FE1AA500" ); // External Power Limit [0..1]
            // $this->RequestData( "BD008E29" ); // External battery power target [W] (positive = discharge)
            // $this->RequestData( "872F380B" ); // External load demand [W] (positive = feed in / 0=internal

            // Bit-coded fault word 0-3
            // $this->RequestData( "37F9D5CA" );
            // $this->RequestData( "234B4736" );
            // $this->RequestData( "3B7FCD47" );
            // $this->RequestData( "7F813D73" );

            // Serial numbers and Descriptions
            // $this->RequestData( "7924ABD9"4 ); // Inverter serial number
            $this->RequestData("FBF6D834"); // Battery Stack 0 serial number
            $this->RequestData("99396810"); // Battery Stack 1 serial number
            $this->RequestData("73489528"); // Battery Stack 2 serial number
            $this->RequestData("257B7612"); // Battery Stack 3 serial number
            $this->RequestData("4E699086"); // Battery Stack 4 serial number
            $this->RequestData("162491E8"); // Battery Stack 5 serial number
            $this->RequestData("5939EC5D"); // Battery Stack 6 serial number

            //--- NOT DOCUMENTED -------------------------------------------------------------------------
            $this->RequestData("8B9FF008"); // Upper load boundary in %
            $this->RequestData("4BC0F974"); // Installed PV Panel kWp
            $this->RequestData("1AC87AA0"); // Current House power consumption 	<=== THIS HAS TO BE THE LAST REQUESTED ADDRESS !!!

            // Wait for answers (till Receive Data sets CommunicationStatus) or we run over 15 seconds
            $counter = 0;
            while (($this->GetBuffer("CommunicationStatus") != "Idle") and ($counter < 12)) {
                $counter++;
                sleep(1); // wait 1 second
            }
            if ($counter >= 12 ) {
                $this->debugLog("WAITING FOR RESPONSES TOOK TOO LONG - ABORTED");
            }

        } catch (Exception $e) {
            $this->debugLog("Exception catched on Update data");
        } catch (\Throwable $e) {
            $this->debugLog("Throwable catched on Update data");
        }

        // reset communication
        $RequestedAddressesSequence = [];
        $this->SetBuffer("RequestedAddressesSequence", json_encode($RequestedAddressesSequence));
        $this->SetBuffer("CommunicationStatus", "Idle"); // no more data expected
        $this->SetBuffer("UpdateWhilePreviousUpdate", 0);

        // release semaphore
        if (IPS_SemaphoreLeave("RCTPowerInverterUpdateData")) {
            $this->debugLog("Semaphore released");
        } else {
            $this->debugLog("Semaphore wasn't released properly");
        }

        // return result
        return true;
    }

    //=== Module Register Functions =============================================================================

    protected function registerProfiles()
    {
        // Generate Variable Profiles

        //--- Boolean (Type 0)

        //--- Integer (Type 1)
        if (!IPS_VariableProfileExists('RCTPOWER_Ampere')) {
            IPS_CreateVariableProfile('RCTPOWER_Ampere', 1);
            IPS_SetVariableProfileDigits('RCTPOWER_Ampere', 0);
            IPS_SetVariableProfileIcon('RCTPOWER_Ampere', 'Electricity');
            IPS_SetVariableProfileText('RCTPOWER_Ampere', "", " A");
        }

        if (!IPS_VariableProfileExists('RCTPOWER_Voltage')) {
            IPS_CreateVariableProfile('RCTPOWER_Voltage', 1);
            IPS_SetVariableProfileDigits('RCTPOWER_Voltage', 0);
            IPS_SetVariableProfileIcon('RCTPOWER_Voltage', 'Electricity');
            IPS_SetVariableProfileText('RCTPOWER_Voltage', "", " V");
        }

        if (!IPS_VariableProfileExists('RCTPOWER_Power')) {
            IPS_CreateVariableProfile('RCTPOWER_Power', 1);
            IPS_SetVariableProfileDigits('RCTPOWER_Power', 0);
            IPS_SetVariableProfileIcon('RCTPOWER_Power', 'Electricity');
            IPS_SetVariableProfileText('RCTPOWER_Power', "", " W");
        }

        if (!IPS_VariableProfileExists('RCTPOWER_Energy')) {
            IPS_CreateVariableProfile('RCTPOWER_Energy', 1);
            IPS_SetVariableProfileDigits('RCTPOWER_Energy', 0);
            IPS_SetVariableProfileIcon('RCTPOWER_Energy', 'Electricity');
            IPS_SetVariableProfileText('RCTPOWER_Energy', "", " Wh");
        }

        //--- Float (Type 2)
        if (!IPS_VariableProfileExists('RCTPOWER_Capacity.2')) {
            IPS_CreateVariableProfile('RCTPOWER_Capacity.2', 2);
            IPS_SetVariableProfileDigits('RCTPOWER_Capacity.2', 2);
            IPS_SetVariableProfileIcon('RCTPOWER_Capacity.2', 'Battery');
            IPS_SetVariableProfileText('RCTPOWER_Capacity.2', "", " kWh");
        }

        if (!IPS_VariableProfileExists('RCTPOWER_SoC.1')) {
            IPS_CreateVariableProfile('RCTPOWER_SoC.1', 2);
            IPS_SetVariableProfileDigits('RCTPOWER_SoC.1', 2);
            IPS_SetVariableProfileIcon('RCTPOWER_SoC.1', 'Battery');
            IPS_SetVariableProfileText('RCTPOWER_SoC.1', "", " %");
        }

        if (!IPS_VariableProfileExists('RCTPOWER_PVPower.2')) {
            IPS_CreateVariableProfile('RCTPOWER_PVPower.2', 2);
            IPS_SetVariableProfileDigits('RCTPOWER_PVPower.2', 2);
            IPS_SetVariableProfileIcon('RCTPOWER_PVPower.2', 'Electricity');
            IPS_SetVariableProfileText('RCTPOWER_PVPower.2', "", " kWp");
        }

        //--- String (Type 3)

    }

    protected function registerVariables()
    {

        $this->RegisterVariableInteger("DCInputAVoltage", "Eingang A Spannung", "RCTPOWER_Voltage", 100);
        $this->RegisterVariableInteger("DCInputAPower", "Eingang A Leistung", "RCTPOWER_Power", 101);
        $this->RegisterVariableFloat("DCInputAUtilization", "Eingang A Auslastung PV Module", "~Valve.F", 102);
        $this->RegisterVariableInteger("DCInputBVoltage", "Eingang B Spannung", "RCTPOWER_Voltage", 103);
        $this->RegisterVariableInteger("DCInputBPower", "Eingang B Leistung", "RCTPOWER_Power", 104);
        $this->RegisterVariableFloat("DCInputBUtilization", "Eingang B Auslastung PV Module", "~Valve.F", 105);
        $this->RegisterVariableInteger("DCInputPower", "Eingang Gesamtleistung", "RCTPOWER_Power", 106);
        $this->RegisterVariableFloat("DCInputUtilization", "Auslastung PV Module gesamt", "~Valve.F", 107);
        $this->RegisterVariableFloat("InstalledPVPower", "Installerierte Panelleistung", "RCTPOWER_PVPower.2", 108);

        $this->RegisterVariableInteger("BatteryVoltage", "Batterie Spannung", "RCTPOWER_Voltage", 200);
        $this->RegisterVariableInteger("BatteryPower", "Batterie Leistung", "RCTPOWER_Power", 201);
        $this->RegisterVariableFloat("BatteryGrossCapacity", "Batterie Brutto-Kapazität", "RCTPOWER_Capacity.2", 202);
        $this->RegisterVariableFloat("BatteryRemainingNetCapacity", "Batterie verf. Restkapazität", "RCTPOWER_Capacity.2", 202);
        $this->RegisterVariableFloat("BatterySoC", "Batterie Ladestand", "~Valve.F", 203);
        $this->RegisterVariableFloat("BatteryUpperSoC", "Batterie Ladegrenze", "~Valve.F", 204);
        $this->RegisterVariableFloat("BatteryTemperature", "Batterie Temperatur", "~Temperature", 205);

        $this->RegisterVariableInteger("HousePowerCurrent", "Haus Leistung", "RCTPOWER_Power", 250);

        $this->RegisterVariableInteger("ExternalPower", "Generator Leistung", "RCTPOWER_Power", 300);

        $this->RegisterVariableInteger("PublicGridPower", "Aussennetz Leistung", "RCTPOWER_Power", 400);

        // Energy Earnings and Consumption
        // Day
        $this->RegisterVariableInteger("EnergyDayEnergy", "Tag - PV Energie ans Haus (via Batteriepuffer)", "RCTPOWER_Energy", 500);
        $this->RegisterVariableInteger("EnergyDayPVEarningInputA", "Tag - PV Ertrag Eingang A", "RCTPOWER_Energy", 501);
        $this->RegisterVariableInteger("EnergyDayPVEarningInputB", "Tag - PV Ertrag Eingang B", "RCTPOWER_Energy", 502);
        $this->RegisterVariableInteger("EnergyDayPVEarningInputAB", "Tag - PV Ertrag Eingänge A+B", "RCTPOWER_Energy", 502);
        $this->RegisterVariableInteger("EnergyDayGridFeedIn", "Tag - Netzeinspeisung", "RCTPOWER_Energy", 503);
        $this->RegisterVariableInteger("EnergyDayGridUsage", "Tag - Netzverbrauch", "RCTPOWER_Energy", 504);
        $this->RegisterVariableInteger("EnergyDayHouseholdTotal", "Tag - Haushalt gesamt", "RCTPOWER_Energy", 505);
        $this->RegisterVariableInteger("EnergyDayAutonomousPowerLevel", "Tag - % Anteil PV am Tagesverbrauch", "~Valve", 506);
        $this->RegisterVariableInteger("EnergyDayGridPowerLevel", "Tag - % Anteil externer Strom am Tagesverbrauch", "~Valve", 507);
        $this->RegisterVariableInteger("EnergyDaySelfConsumptionLevel", "Tag - % PV Selbstverbrauch", "~Valve", 508);
        $this->RegisterVariableInteger("EnergyDayGridFeedInLevel", "Tag - % PV Netzeinspeisung", "~Valve", 509);

        // Month
        $this->RegisterVariableInteger("EnergyMonthEnergy", "Monat - PV Energie ans Haus (via Batteriepuffer)", "RCTPOWER_Energy", 600);
        $this->RegisterVariableInteger("EnergyMonthPVEarningInputA", "Monat - PV Ertrag Eingang A", "RCTPOWER_Energy", 601);
        $this->RegisterVariableInteger("EnergyMonthPVEarningInputB", "Monat - PV Ertrag Eingang B", "RCTPOWER_Energy", 602);
        $this->RegisterVariableInteger("EnergyMonthPVEarningInputAB", "Monat - PV Ertrag Eingänge A+B", "RCTPOWER_Energy", 603);
        $this->RegisterVariableInteger("EnergyMonthGridFeedIn", "Monat - Netzeinspeisung", "RCTPOWER_Energy", 604);
        $this->RegisterVariableInteger("EnergyMonthGridUsage", "Monat - Netzverbrauch", "RCTPOWER_Energy", 605);
        $this->RegisterVariableInteger("EnergyMonthHouseholdTotal", "Monat - Haushalt gesamt", "RCTPOWER_Energy", 606);
        $this->RegisterVariableInteger("EnergyMonthAutonomousPowerLevel", "Monat - % Anteil PV am Monatsverbrauch", "~Valve", 607);
        $this->RegisterVariableInteger("EnergyMonthGridPowerLevel", "Monat - % Anteil externer Strom am Monatsverbrauch", "~Valve", 608);
        $this->RegisterVariableInteger("EnergyMonthSelfConsumptionLevel", "Monat - % PV Selbstverbrauch", "~Valve", 609);
        $this->RegisterVariableInteger("EnergyMonthGridFeedInLevel", "Monat - % PV Netzeinspeisung", "~Valve", 610);

        // Year
        $this->RegisterVariableInteger("EnergyYearEnergy", "Jahr - PV Energie ans Haus (via Batteriepuffer)", "RCTPOWER_Energy", 700);
        $this->RegisterVariableInteger("EnergyYearPVEarningInputA", "Jahr - PV Ertrag Eingang A", "RCTPOWER_Energy", 701);
        $this->RegisterVariableInteger("EnergyYearPVEarningInputB", "Jahr - PV Ertrag Eingang B", "RCTPOWER_Energy", 702);
        $this->RegisterVariableInteger("EnergyYearPVEarningInputAB", "Jahr - PV Ertrag Eingänge A+B", "RCTPOWER_Energy", 702);
        $this->RegisterVariableInteger("EnergyYearGridFeedIn", "Jahr - Netzeinspeisung", "RCTPOWER_Energy", 703);
        $this->RegisterVariableInteger("EnergyYearGridUsage", "Jahr - Netzverbrauch", "RCTPOWER_Energy", 704);
        $this->RegisterVariableInteger("EnergyYearHouseholdTotal", "Jahr - Haushalt gesamt", "RCTPOWER_Energy", 705);
        $this->RegisterVariableInteger("EnergyYearAutonomousPowerLevel", "Jahr - % Anteil PV am Jahresverbrauch", "~Valve", 706);
        $this->RegisterVariableInteger("EnergyYearGridPowerLevel", "Jahr - % Anteil externer Strom am Jahresverbrauch", "~Valve", 707);
        $this->RegisterVariableInteger("EnergyYearSelfConsumptionLevel", "Jahr - % PV Selbstverbrauch", "~Valve", 708);
        $this->RegisterVariableInteger("EnergyYearGridFeedInLevel", "Jahr - % PV Netzeinspeisung", "~Valve", 709);

        // Total
        $this->RegisterVariableInteger("EnergyTotalEnergy", "Gesamt - PV Energie ans Haus (via Batteriepuffer)", "RCTPOWER_Energy", 800);
        $this->RegisterVariableInteger("EnergyTotalPVEarningInputA", "Gesamt - PV Ertrag Eingang A", "RCTPOWER_Energy", 801);
        $this->RegisterVariableInteger("EnergyTotalPVEarningInputB", "Gesamt - PV Ertrag Eingang B", "RCTPOWER_Energy", 802);
        $this->RegisterVariableInteger("EnergyTotalPVEarningInputAB", "Gesamt - PV Ertrag Eingänge A+B", "RCTPOWER_Energy", 802);
        $this->RegisterVariableInteger("EnergyTotalGridFeedIn", "Gesamt - Netzeinspeisung", "RCTPOWER_Energy", 803);
        $this->RegisterVariableInteger("EnergyTotalGridUsage", "Gesamt - Netzverbrauch", "RCTPOWER_Energy", 804);
        $this->RegisterVariableInteger("EnergyTotalHouseholdTotal", "Gesamt - Haushalt gesamt", "RCTPOWER_Energy", 805);
        $this->RegisterVariableInteger("EnergyTotalAutonomousPowerLevel", "Gesamt - % Anteil PV am Gesamtverbrauch", "~Valve", 806);
        $this->RegisterVariableInteger("EnergyTotalGridPowerLevel", "Gesamt - % Anteil externer Strom am Gesamtverbrauch", "~Valve", 807);
        $this->RegisterVariableInteger("EnergyTotalSelfConsumptionLevel", "Gesamt - % PV Selbstverbrauch", "~Valve", 808);
        $this->RegisterVariableInteger("EnergyTotalGridFeedInLevel", "Gesamt - % PV Netzeinspeisung", "~Valve", 809);

        $this->RegisterVariableBoolean("Errorstatus", "Fehlerstatus", "~Alert", 1000);
    }


    protected function debugLog(string $message)
    {
        if ($this->ReadPropertyBoolean("DebugSwitch") == true) {
            $this->SendDebug("RCTPower", $message, 0);
            $this->LogMessage($message, KL_DEBUG );
        };
    }

}

?>
