<?php

class HelperFunctions
{
    // Static property
    protected static $initialized = false;
    protected static $tableOfIds;

    // static initialization method to load configuration
    public static function initialize() {
        if (!self::$initialized) {
            // Perform initialization tasks here
            $jsonFilePath = dirname(__DIR__).'/libs/RCTAddressData.json';
            $lines = file($jsonFilePath, FILE_IGNORE_NEW_LINES);
            // remove lines with comments
            $filteredLines = array_filter($lines, function($line) {
                // Check if the line should be kept
                return strpos($line, '//') === false && trim($line) !== '';
            });
            $filteredLines = array_values($filteredLines);
            $singleString = implode('', $filteredLines);
            // convert json and assign data
            $RCTAddressData = json_decode($singleString, true);
            self::$tableOfIds = $RCTAddressData["tableOfIds"];
            self::$initialized = true;
        }
    }

    static function getTableOfIds() {
        self::initialize();
        return self::$tableOfIds;
    }

    static function getConfigById($ID) {
        self::initialize();
        foreach(self::$tableOfIds as $config) {
            if (isset($config['id']) && $config['id'] == $ID) {
                return $config;
            }
        }
        return false;
    }

    static function getPollingIds() {
        $filteredEntries = array_filter(self::getTableOfIds(), function($id) {
            // Check if the line should be kept
            return (isset($id["polling"]) && $id["polling"] && isset($id["id"]));
        });
        return array_values($filteredEntries);
    }

    static function getHexReadCommandString( String $Address ) {
        // build command
        $length=strlen($Address)/2;
        $hexlength = strtoupper(dechex($length));
        if (strlen($hexlength) == 1) $hexlength = '0' . $hexlength;
        $command = "01" . $hexlength . $Address;
        $command = "2B" . $command . self::calcCRC($command);
        $hexCommand = "";
        for ($x = 0; $x < strlen($command) / 2; $x++)
            $hexCommand = $hexCommand . chr(hexdec(substr($command, $x * 2, 2)));
        return $hexCommand;
    }

    static function calcCRC(string $command)
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

    static function hexTo32Float(string $strHex)
    {
        $bin = str_pad(base_convert($strHex, 16, 2), 32, "0", STR_PAD_LEFT);
        $sign = $bin[0];
        $v = hexdec($strHex);
        $x = ($v & ((1 << 23) - 1)) + (1 << 23) * ($v >> 31 | 1);
        $exp = ($v >> 23 & 0xFF) - 127;
        return $x * pow(2, $exp - 23) * ($sign ? -1 : 1);
    }

    static function hexToString(string $hex)
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

    static function decToHexString(string $data)
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

    static function hexUInt8ToDec($hexString)
    {
        // Ensure the hex string is only one byte (2 hex characters)
        if (strlen($hexString) != 2) {
            return 0;
        }
        // Convert the hex string to a decimal integer
        $decimal = hexdec($hexString);
        // Ensure the result fits within the range of uint8 (0 to 255)
        return $decimal & 0xFF;
    }

    static function reverseMSBFHex(string $hexString)
    {
        // Ensure the string has an even number of characters
        if (strlen($hexString) % 2 != 0) {
            return 0; // ignore
        }
        // Split the hex string into pairs of characters (bytes)
        $bytes = str_split($hexString, 2);
        // Reverse the array of bytes
        $reversedBytes = array_reverse($bytes);
        // Join the reversed bytes back into a string
        return implode('', $reversedBytes);
    }
}