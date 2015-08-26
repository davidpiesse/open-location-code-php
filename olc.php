<?php

class OpenLocationCode
{
    private static $SEPARATOR_ = '+';
    private static $SEPARATOR_POSITION_ = 8;
    private static $PADDING_CHARACTER_ = '0';
    private static $CODE_ALPHABET_ = '23456789CFGHJMPQRVWX';
    private static $LATITUDE_MAX_ = 90;
    private static $LONGITUDE_MAX_ = 180;
    private static $PAIR_CODE_LENGTH_ = 10;
    private static $PAIR_RESOLUTIONS_ = [20.0, 1.0, .05, .0025, .000125];
    private static $GRID_COLUMNS_ = 4;
    private static $GRID_ROWS_ = 5;
    private static $GRID_SIZE_DEGREES_ = 0.000125;
    private static $MIN_TRIMMABLE_CODE_LEN_ = 6;

    private static function ENCODING_BASE_() {
        return strlen(self::$CODE_ALPHABET_);
    }

    public static function getAlphabet(){
        return self::$CODE_ALPHABET_;
    }

    private static function charAt($string, $index){
        return substr($string,$index,1);
    }

    private static function indexOf($string,$char){
        return strpos($string,$char);
    }

    private static function lastIndexOf($string,$char){
        return strrpos($string,$char);
    }

    private static function subString($string,$startIndex,$characterLength){
        return substr($string,$startIndex,$characterLength);
    }

    private static function length($string){
        return strlen($string);
    }

    private static function upper($string){
        return strtoupper($string);
    }

    private static function remove($string,$removedString){
        return str_replace($removedString,'',$string);
    }

    //custon functions for easy code
    private static function separatorLocation($code){
        return self::indexOf($code,self::$SEPARATOR_);
    }
    private static function paddingLocation($code){
        return self::indexOf($code,self::$PADDING_CHARACTER_);
    }

    private static function alphabetIndex($character){
        return strpos(self::$CODE_ALPHABET_,$character);
    }
    private static function alphabetAtIndex($index){
        return substr(self::$CODE_ALPHABET_,$index,1);
    }

    //Determines if a code is valid.
    public static function isValid($code){
        if(!$code)
            return false;
        if(self::separatorLocation($code)===false)
            return false;
        if(self::separatorLocation($code) != strrpos($code,self::$SEPARATOR_))
            return false;
        if(self::length($code) == 1)
            return false;
        if(self::separatorLocation($code) > self::$SEPARATOR_POSITION_ || (self::separatorLocation($code) % 2 == 1) )
            return false;
        if(self::paddingLocation($code) === true) {
            if (self::paddingLocation($code) == 0)
                return false;
            $padMatch = [];
            preg_match_all('(' . self::$PADDING_CHARACTER_ . '+)', $code, $padMatch);
            if (count($padMatch) > 1 || (count($padMatch[0]) % 2 == 1) || (count($padMatch[0]) > self::$SEPARATOR_POSITION_ - 2)) {
                return false;
            }
        }
        if((self::length($code) - self::separatorLocation($code) -1) == 1)
            return false;
        $code = preg_replace("([".self::$SEPARATOR_."])",'',$code);
        $code = preg_replace('(['.self::$PADDING_CHARACTER_.'])','',$code);
        for($i =0, $len = self::length($code); $i < $len; $i++){
            $character = self::charAt($code,$i);
            if($character != self::$SEPARATOR_ && self::alphabetIndex($character) == -1 )
                return false;
        }
        return true;
    }

    public static function isShort($code){
        if(!self::isValid($code))
            return false;
        if((self::separatorLocation($code) >= 0) && self::separatorLocation($code) < self::$SEPARATOR_POSITION_)
            return true;
        return false;
    }

    public static function isFull($code){
        if(!self::isValid($code))
            return false;
        if(self::isShort($code))
            return false;
        $firstLatValue =  self::alphabetIndex(self::upper(self::charAt($code,0))) * self::ENCODING_BASE_() ;
        if($firstLatValue >= self::$LATITUDE_MAX_ * 2)
            return false;
        if(self::length($code)>1){
            $firstLngValue = self::alphabetIndex(self::upper(self::charAt($code,1))) * self::ENCODING_BASE_() ;
            if($firstLngValue >= self::$LONGITUDE_MAX_ * 2)
                return false;
        }
        return true;
    }

    public static function encode($latitude, $longitude, $codelength = null){
        if(is_null($codelength))
            $codelength = self::$PAIR_CODE_LENGTH_;
        if($codelength < 2 || ( $codelength < self::$SEPARATOR_POSITION_ && $codelength % 2 == 1))
            throw new \InvalidArgumentException('Invalid Open Location Code length');
        $latitude = self::clipLatitude($latitude);
        $longitude = self::normalizeLongitude($longitude);

        if($latitude == 90)
            $latitude = $latitude - self::computeLatitudePrecision($codelength);
        $code = self::encodePairs($latitude,$longitude,min($codelength,self::$PAIR_CODE_LENGTH_));
        if($codelength > self::$PAIR_CODE_LENGTH_){
            $code .= self::encodeGrid($latitude,$longitude,($codelength - self::$PAIR_CODE_LENGTH_));
        }
        return $code;
    }

    public static function decode($code){
        if(!self::isFull($code))
            throw new \InvalidArgumentException("Passed Open Location Code is not a valid full code: " . $code);
        $code = self::remove($code, self::$SEPARATOR_);
        $code = self::remove($code, self::$PADDING_CHARACTER_);
        $code = self::upper($code);
        $codeArea = self::decodePairs(self::subString($code, 0, min(self::length($code), self::$PAIR_CODE_LENGTH_)));
        if(self::length($code) <= self::$PAIR_CODE_LENGTH_)
            return $codeArea;
        $gridArea =self::decodeGrid(substr($code,self::$PAIR_CODE_LENGTH_));
        return new CodeArea(
            $codeArea->latitudeLo + $gridArea->latitudeLo,
            $codeArea->longitudeLo + $gridArea->longitudeLo,
            $codeArea->latitudeLo + $gridArea->latitudeHi,
            $codeArea->longitudeLo + $gridArea->longitudeHi,
            $codeArea->codeLength + $gridArea->codeLength
        );
    }

//    public static function shortenBy4(){
//    }
//
//    public static function shortenBy6(){
//    }

    public static function shorten($code, $latitude, $longitude){
        if(!self::isFull($code))
            throw new \UnexpectedValueException('Passed code is not valid and full: '.$code);
        if(self::paddingLocation($code) != -1)
            throw new \UnexpectedValueException('Cannot shorten padded codes: '.$code);
        $code = self::upper($code);
        $codeArea = self::decode($code);
        if($codeArea->codeLength < self::$MIN_TRIMMABLE_CODE_LEN_)
            throw new \UnexpectedValueException(' Code length must be at least '.self::$MIN_TRIMMABLE_CODE_LEN_);
        $latitude = self::clipLatitude($latitude);
        $longitude = self::normalizeLongitude($longitude);
        $range = max(abs($codeArea->latitudeCenter - $latitude), abs($codeArea->longitudeCenter - $longitude));
        for($i = strlen(self::$PAIR_RESOLUTIONS_) -2; $i >= 1; $i--){
            if($range < (self::$PAIR_RESOLUTIONS_[$i] * 0.3))
                return substr($code, ($i+1)*2);
        }
        return $code;
    }

    public static function recoverNearest($shortCode, $referenceLatitude, $referenceLongitude){
        if(!self::isShort($shortCode)){
            if(self::isFull($shortCode))
                return $shortCode;
            else
                throw new \UnexpectedValueException('Passed short code is not valid: ' . $shortCode);
        }
        $referenceLatitude = self::clipLatitude($referenceLatitude);
        $referenceLongitude = self::normalizeLongitude($referenceLongitude);
        $shortCode = self::upper($shortCode);
        $paddingLength = self::$SEPARATOR_POSITION_ - self::separatorLocation($shortCode);
        $resolution = pow(20,2-($paddingLength /2));
        $areaToEdge = $resolution / 2.0;
        $roundedLatitude = floor($referenceLatitude / $resolution)*$resolution;
        $roundedLongitude = floor($referenceLongitude / $resolution)*$resolution;
        $codeArea = self::decode(substr(self::encode($roundedLatitude,$roundedLongitude),0,$paddingLength).$shortCode);
        $degreesDifference = $codeArea->latitudeCenter - $referenceLatitude;
        if($degreesDifference > $areaToEdge)
            $codeArea->latitudeCenter -= $resolution;
        elseif($degreesDifference < -$areaToEdge)
            $codeArea->latitudeCenter += $resolution;
        $degreesDifference = $codeArea->longitudeCenter - $referenceLongitude;
        if($degreesDifference > $areaToEdge)
            $codeArea->longitudeCenter -= $resolution;
        elseif($degreesDifference < -$areaToEdge)
            $codeArea->longitudeCenter += $resolution;
        return self::encode($codeArea->latitudeCenter, $codeArea->longitudeCenter, $codeArea->codeLength);
    }

    private static function clipLatitude($latitude){
        return min(90,max(-90,$latitude));
    }

    private static function normalizeLongitude($longitude){
        while($longitude < -180){
            $longitude = $longitude + 360;
        }
        while($longitude >= 180){
            $longitude = $longitude - 360;
        }
        return $longitude;
    }

    private static function computeLatitudePrecision($codeLength){
        if($codeLength <= 10)
            return pow(20,floor(($codeLength / -2) + 2));
        return pow(20,-3) / pow(self::$GRID_ROWS_, $codeLength -10);
    }

    private static function encodePairs($latitude,$longitude,$codeLength){
        $code = '';
        $adjustedLatitude = $latitude + self::$LATITUDE_MAX_;
        $adjustedLongitude = $longitude + self::$LONGITUDE_MAX_;
        $digitCount = 0;

        while($digitCount < $codeLength){
            $placeValue = self::$PAIR_RESOLUTIONS_[intval(floor($digitCount/2))];
            $digitValue = floor($adjustedLatitude / $placeValue);
            $adjustedLatitude -= $digitValue * $placeValue;
            $code .= self::alphabetAtIndex($digitValue);
            $digitCount += 1;
            $digitValue = floor($adjustedLongitude / $placeValue);
            $adjustedLongitude -= $digitValue * $placeValue;
            $code .= self::alphabetAtIndex($digitValue);
            $digitCount+=1;
            if($digitCount == self::$SEPARATOR_POSITION_ && $digitCount < $codeLength)
                $code .= self::$SEPARATOR_;
        };
        if(self::length($code) < self::$SEPARATOR_POSITION_){
            $array = Array(self::$SEPARATOR_POSITION_ - (self::length($code)-1));
            //CHANGE MADE
            $code .= $array[0];
            $code .= self::$PADDING_CHARACTER_;
        }
        if(self::length($code) == self::$SEPARATOR_POSITION_)
            $code .= self::$SEPARATOR_;
        return $code;
    }

    private static function encodeGrid($latitude,$longitude,$codeLength){
        $code = '';
        $latPlaceValue = self::$GRID_SIZE_DEGREES_;
        $lngPlaceValue = self::$GRID_SIZE_DEGREES_;
        $adjustedLatitude = ($latitude + self::$LATITUDE_MAX_) % $latPlaceValue;
        $adjustedLongitude = ($longitude + self::$LONGITUDE_MAX_) % $lngPlaceValue;
        for($i =0; $i < $codeLength; $i++){
            $row = floor($adjustedLatitude / ($latPlaceValue / self::$GRID_ROWS_));
            $col = floor($adjustedLongitude / ($lngPlaceValue / self::$GRID_COLUMNS_));
            $latPlaceValue /= self::$GRID_ROWS_;
            $lngPlaceValue /= self::$GRID_COLUMNS_;
            $adjustedLatitude -= $row * $latPlaceValue;
            $adjustedLongitude -= $col * $lngPlaceValue;
            $code.= self::alphabetAtIndex($row*self::$GRID_COLUMNS_ + $col);
        }
        return $code;
    }

    private static function decodePairs($code){
        $latitude = self::decodePairsSequence($code,0);
        $longitude = self::decodePairsSequence($code,1);
        return new CodeArea(
            $latitude[0] - self::$LATITUDE_MAX_,
            $longitude[0] - self::$LONGITUDE_MAX_,
            $latitude[1] - self::$LATITUDE_MAX_,
            $longitude[1] - self::$LONGITUDE_MAX_,
            self::length($code)
        );
    }

    private static function decodePairsSequence($code,$offset){
        $i = 0;
        $value = 0;
        while($i * 2 + $offset < self::length($code)){
            $codeIndex = $i * 2 + $offset;
            $codeChar = self::charAt($code,$codeIndex);
            $val = self::alphabetIndex($codeChar);
            $value .= $val * self::$PAIR_RESOLUTIONS_[$i];
            $i++;
        }
        return [$value,$value + self::$PAIR_RESOLUTIONS_[$i -1]];
    }

    private static function decodeGrid($code){
        $latitudeLo = 0.0;
        $longitudeLo = 0.0;
        $latPlaceValue = self::$GRID_SIZE_DEGREES_;
        $lngPlaceValue = self::$GRID_SIZE_DEGREES_;
        $i = 0;
        while($i < strlen($code)){
            $codeIndex = strpos(self::$CODE_ALPHABET_,substr($code,$i,1));
            $row = floor($codeIndex / self::$GRID_COLUMNS_);
            $col = $codeIndex % self::$GRID_COLUMNS_;
            $latPlaceValue /= self::$GRID_ROWS_;
            $lngPlaceValue /= self::$GRID_COLUMNS_;
            $latitudeLo += $row * $latPlaceValue;
            $longitudeLo += $col * $lngPlaceValue;
            $i++;
        }
        return new CodeArea(
            $latitudeLo,
            $longitudeLo,
            $latitudeLo+$latPlaceValue,
            $longitudeLo+$lngPlaceValue,
            strlen($code)
        );
    }

}

//Class for CodeArea Object
class CodeArea extends \stdClass{
    public $latitudeLo = 0;
    public $longitudeLo = 0;
    public $latitudeHi = 0;
    public $longitudeHi = 0;
    public $codeLength = 0;

    public $latitudeCenter = 0;
    public $longitudeCenter = 0;

    private static $LATITUDE_MAX_ = 90;
    private static $LONGITUDE_MAX_ = 180;

    function __construct($latitudeLo_,$longitudeLo_,$latitudeHi_,$longitudeHi_,$codeLength_){
        $this->latitudeLo = $latitudeLo_;
        $this->latitudeHi = $latitudeHi_;
        $this->longitudeLo = $longitudeLo_;
        $this->longitudeHi = $longitudeHi_;
        $this->codeLength = $codeLength_;
        $this->latitudeCenter = min(($this->latitudeLo + ($this->latitudeHi - $this->latitudeLo)/2),self::$LATITUDE_MAX_);
        $this->longitudeCenter = min(($this->longitudeLo + ($this->longitudeHi - $this->longitudeLo)/2),self::$LONGITUDE_MAX_);
    }


}
