<?php

class ControlByte
// Start and Stop Bytes
{
    const START = 0x2B;
    const STOP  = 0x2D;
}

class MasterCommandByte
// These commands are used direct communication with the master (single inverter)
{
    const READ              = 0x01; // 0000 0001
    const WRITE             = 0x02; // 0000 0010
    const LONG_WRITE        = 0x03; // 0000 0011
    const Reserved          = 0x04; // 0000 0100
    const RESPONSE          = 0x05; // 0000 0101
    const LONG_RESPONSE     = 0x06; // 0000 0110
    const Reserved2         = 0x07; // 0000 0111
    const READ_PERIODICALLY = 0x08; // 0000 1000
    const EXTENSION         = 0x3C; // 1100 0011
}

class SlaveCommandByte
// These modified commands are used for networked plants communication. The Master device should
//not interpret this commands, but forward them.
{
    const READ              = 0x41; // 0100 0001
    const WRITE             = 0x42; // 0100 0010
    const LONG_WRITE        = 0x43; // 0100 0011
    const Reserved          = 0x44; // 0100 0100
    const RESPONSE          = 0x45; // 0100 0101
    const LONG_RESPONSE     = 0x46; // 0100 0110
    const Reserved2         = 0x47; // 0100 0111
    const READ_PERIODICALLY = 0x48; // 0100 1000
}

//=== COM SERVICES =====================================================================================================
// This is a special variable. It is regularly polled. If it changes - it will be interpreted as later described.
// The following table presents the meaning of different possible values for the „com_service“ variable
// (ID 0x8FC89B10). Note that the inverter take actions only by changing of the „com_service“ variable,
// so to „execute“ the same command again the value of the „com_service“ must be reset to 0 first.
// Note: before changing the „com_service“ it’s a good practice to reset it to 0
define('COM_SERVICE', [
    'NO_MEANING'                        => ['Name' => 'NO_MEANING', 'Value' => '0'],
    'RESERVED_FOR_INTERNAL_USAGE1'      => ['Name' => 'RESERVED_FOR_INTERNAL_USAGE1', 'Value' => '1'],
    'RESERVED_FOR_INTERNAL_USAGE2'      => ['Name' => 'RESERVED_FOR_INTERNAL_USAGE2', 'Value' => '2'],
    'RESERVED_FOR_INTERNAL_USAGE3'      => ['Name' => 'RESERVED_FOR_INTERNAL_USAGE3', 'Value' => '3'],
    'RESERVED_FOR_INTERNAL_USAGE4'      => ['Name' => 'RESERVED_FOR_INTERNAL_USAGE4', 'Value' => '4'],
    'FLASH_PARAMETERS'                  => ['Name' => 'FLASH_PARAMETERS', 'Value' => '5'],
    'ERASE_PARAMETERS_FLASH'            => ['Name' => 'ERASE_PARAMETERS_FLASH', 'Value' => '6'],
    'RESERVED_FOR_INTERNAL_USAGE5'      => ['Name' => 'RESERVED_FOR_INTERNAL_USAGE5', 'Value' => '7'],
    'RESERVED_FOR_INTERNAL_USAGE6'      => ['Name' => 'RESERVED_FOR_INTERNAL_USAGE6', 'Value' => '8'],
    'WRITE_WIFI_SETTINGS_TO_WIFI_BOARD' => ['Name' => 'WRITE_WIFI_SETTINGS_TO_WIFI_BOARD', 'Value' => '9'],
    'READ_WIFI_SETTINGS_FROM_WIFI_BOARD'=> ['Name' => 'READ_WIFI_SETTINGS_FROM_WIFI_BOARD', 'Value' => '10'],
    'BULK_ERASE_OF_WHILE_DATALOG'       => ['Name' => 'BULK_ERASE_OF_WHILE_DATALOG', 'Value' => '11'],
    'TUNE_CURRENT_SENSORS'              => ['Name' => 'TUNE_CURRENT_SENSORS', 'Value' => '12'],
    'START_BATTERY_BOOSTER_TEST'        => ['Name' => 'START_BATTERY_BOOSTER_TEST', 'Value' => '13'],
    'STOP_BATTERY_BOOSTER_TEST'         => ['Name' => 'TOP_BATTERY_BOOSTER_TEST', 'Value' => '14'],
    'START_BATTERY_COMMISSION'          => ['Name' => 'START_BATTERY_COMMISSION', 'Value' => '15'],
    'STOP_BATTERY_COMMISSION'           => ['Name' => 'STOP_BATTERY_COMMISSION', 'Value' => '16'],
    'STOP_BMS_TEST'                     => ['Name' => 'STOP_BMS_TEST', 'Value' => '17'],
    'RESERVED_FOR_INTERNAL_USAGE7'      => ['Name' => 'RESERVED_FOR_INTERNAL_USAGE7', 'Value' => '18'],
    'START_BMW_TEST'                    => ['Name' => 'START_BMW_TEST', 'Value' => '19'],
    'RESERVED_FOR_INTERNAL_USAGE8'      => ['Name' => 'RESERVED_FOR_INTERNAL_USAGE8', 'Value' => '20']
]);


// If the internal bootloader is active (during update) - the normal communication protocol (COM
// protocol) is not valid. All requests should be stopped. The bootloader sends periodically (every
// 500ms) by communication troubles during update the "magic number": 0x50F705AB (MSBF). Stop all
// request as soon as possible by detecting the magic number sequence.
const BOOTLOADER = 0x50F705AB;

//=== INVERTER STATES ==================================================================================================
define('INVERTER_STATE', [
    ['Description']  => "Standby",                                                                          // 0
    ['Description']  => "Initialization",                                                                   // 1
    ['Description']  => "Standby",                                                                          // 2
    ['Description']  => "Efficiency (debug state for development purposes)",                                 // 3
    ['Description']  => "Insulation check",                                                                 // 4
    ['Description']  => "Island check (decision where to go - grid connected or island)",                   // 5
    ['Description']  => "Power check (decision if enough energy to start or not)",                          // 6
    ['Description']  => "Symmetry (DC-link alignment)",                                                     // 7
    ['Description']  => "Relays test",                                                                      // 8
    ['Description']  => "Grid Passive (inverter get power from grid without bridge clocking)",              // 9
    ['Description']  => "Prepare Bat Passive",                                                              // 10
    ['Description']  => "Battery Passive (inverter disconnected from grid and get power from battery)",     // 11
    ['Description']  => "H/W check (prepare to start)",                                                     // 12
    ['Description']  => "Feed in"                                                                           // 13
    ]);

//=== FAULTS ===========================================================================================================
// There are two classes of the faults
// - Inverter faults
// - Battery (BMS) faults
// The faults are bit-coded in four 32-bit variables, where each bit is reserved for one fault, so there are
// 4 x 32 faults at all. Bit 0 of the „fault[0].flt“ variable corresponds fault index 0.