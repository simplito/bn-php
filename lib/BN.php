<?php
namespace BN;

use \JsonSerializable;
use \Exception;

if (version_compare(PHP_VERSION, "5.6") >= 0) {
    eval('function is_gmp($x) { return $x instanceof GMP; }');
} else {
    function is_gmp($x) { return is_resource($x); }
}

class BN implements JsonSerializable
{
    public $gmp;
    public $red;

    function __construct($number, $base = 10, $endian = null)
    {
        if( $number instanceof BN ) {
            $this->gmp = $number->gmp;
            $this->red = $number->red;
            return;
        }

        // Reduction context
        $this->red = null;

        if ( is_gmp($number) ) {
            $this->gmp = $number;
            return;
        }

        if( is_array($number) )
        {
            $number = call_user_func_array("pack", array_merge(array("C*"), $number));
            $number = bin2hex($number);
            $base = 16;
        }

        if( $base == "hex" )
            $base = 16;

        if ($endian == 'le') {
            if ($base != 16)
                throw new \Exception("Not implemented");
            $number = bin2hex(strrev(hex2bin($number)));
        }

        $this->gmp = gmp_init($number, $base);
    }

    public function negative() {
        return gmp_sign($this->gmp) < 0 ? 1 : 0;
    }

    public static function isBN($num) {
        return ($num instanceof BN);
    }

    public static function max($left, $right) {
        return ( $left->cmp($right) > 0 ) ? $left : $right;
    }

    public static function min($left, $right) {
        return ( $left->cmp($right) < 0 ) ? $left : $right;
    }

    public function copy($dest)
    {
        $dest->gmp = $this->gmp;
        $dest->red = $this->red;
    }

    public function _clone() {
        return clone($this);
    }

    public function toString($base = 10, $padding = 0)
    {
        if( $base == "hex" )
            $base = 16;
        $str = gmp_strval(gmp_abs($this->gmp), $base);
        if ($padding > 0) {
            $len = strlen($str);
            $mod = $len % $padding;
            if ($mod > 0)
                $len = $len + $padding - $mod;
            $str = str_pad($str, $len, "0", STR_PAD_LEFT);
        }
        if( $this->negative() )
            return "-" . $str;
        return $str;
    }

    public function toNumber() {
        return gmp_intval($this->gmp);
    }

    public function jsonSerialize() {
        return $this->toString(16);
    }

    public function toArray($endian = "be", $length = -1)
    {
        $hex = $this->toString(16);
        if( $hex[0] === "-" )
            $hex = substr($hex, 1);

        if( strlen($hex) % 2 )
            $hex = "0" . $hex;

        $bytes = array_map(
            function($v) { return hexdec($v); },
            str_split($hex, 2)
        );

        if( $length > 0 )
        {
            $count = count($bytes);
            if( $count > $length )
                throw new Exception("Byte array longer than desired length");

            for($i = $count; $i < $length; $i++)
                array_unshift($bytes, 0);
        }

        if( $endian === "le" )
            $bytes = array_reverse($bytes);

        return $bytes;
    }

    public function bitLength() {
        $bin = $this->toString(2);
        return strlen($bin) - ( $bin[0] === "-" ? 1 : 0 );
    }

    public function zeroBits() {
        return gmp_scan1($this->gmp, 0);
    }

    public function byteLength() {
        return ceil($this->bitLength() / 8);
    }

    //TODO toTwos, fromTwos

    public function isNeg() {
        return $this->negative() !== 0;
    }

    // Return negative clone of `this`
    public function neg() {
        return $this->_clone()->ineg();
    }

    public function ineg() {
        $this->gmp = gmp_neg($this->gmp);
        return $this;
    }

    // Or `num` with `this` in-place
    public function iuor(BN $num) {
        $this->gmp = gmp_or($this->gmp, $num->gmp);
        return $this;
    }

    public function ior(BN $num) {
        assert('!$this->negative() && !num->negative()');
        return $this->iuor($num);
    }

    // Or `num` with `this`
    public function _or(BN $num) {
        if( $this->ucmp($num) > 0 )
            return $this->_clone()->ior($num);
        return $num->_clone()->ior($this);
    }

    public function uor(BN $num) {
        if( $this->ucmp($num) > 0 )
            return $this->_clone()->iuor($num);
        return $num->_clone()->ior($this);
    }

    // And `num` with `this` in-place
    public function iuand(BN $num) {
        $this->gmp = gmp_and($this->gmp, $num->gmp);
        return $this;
    }

    public function iand(BN $num) {
        assert('!$this->negative() && !num->negative()');
        return $this->iuand($num);
    }

    // And `num` with `this`
    public function _and(BN $num) {
        if( $this->ucmp($num) > 0 )
            return $this->_clone()->iand($num);
        return $num->_clone()->iand($this);
    }

    public function uand(BN $num) {
        if( $this->ucmp($num) > 0 )
            return $this->_clone()->iuand($num);
        return $num->_clone()->iuand($this);
    }

    // Xor `num` with `this` in-place
    public function iuxor(BN $num) {
        $this->gmp = gmp_xor($this->gmp, $num->gmp);
        return $this;
    }

    public function ixor(BN $num) {
        assert('!$this->negative() && !num->negative()');
        return $this->iuxor($num);
    }

    // Xor `num` with `this`
    public function _xor(BN $num) {
        if( $this->ucmp($num) > 0 )
            return $this->_clone()->ixor($num);
        return $num->_clone()->ixor($this);
    }

    public function uxor(BN $num) {
        if( $this->ucmp($num) > 0 )
            return $this->_clone()->iuxor($num);
        return $num->_clone()->iuxor($this);
    }

    // Not ``this`` with ``width`` bitwidth
    public function inotn($width)
    {
        assert('is_integer($width) && $width >= 0');
        $neg = false;
        if( $this->isNeg() )
        {
            $this->negi();
            $neg = true;
        }

        for($i = 0; $i < $width; $i++)
            $this->gmp = gmp_setbit($this->gmp, $i, !gmp_testbit($this->gmp, $i));

        return $neg ? $this->negi() : $this;
    }

    public function notn($width) {
        return $this->_clone()->inotn($width);
    }

    // Set `bit` of `this`
    public function setn($bit, $val) {
        assert('is_integer($bit) && $bit > 0');
        $this->gmp = gmp_setbit($this->gmp, $bit, !!$val);
        return $this;
    }

    // Add `num` to `this` in-place
    public function iadd(BN $num) {
        $this->gmp = gmp_add($this->gmp, $num->gmp);
        return $this;
    }

    // Add `num` to `this`
    public function add(BN $num) {
        return $this->_clone()->iadd($num);
    }

    // Subtract `num` from `this` in-place
    public function isub(BN $num) {
        $this->gmp = gmp_sub($this->gmp, $num->gmp);
        return $this;
    }

    // Subtract `num` from `this`
    public function sub(BN $num) {
        return $this->_clone()->isub($num);
    }

    // Multiply `this` by `num`
    public function mul(BN $num) {
        return $this->_clone()->imul($num);
    }

    // In-place Multiplication
    public function imul(BN $num) {
        $this->gmp = gmp_mul($this->gmp, $num->gmp);
        return $this;
    }

    public function imuln($num)
    {
        assert('is_numeric($num)');
        $int = intval($num);
        $res = gmp_mul($this->gmp, $int);

        if( ($num - $int) > 0 )
        {
            $mul = 10;
            $frac = ($num - $int) * $mul;
            $int = intval($frac);
            while( ($frac - $int) > 0 )
            {
                $mul *= 10;
                $frac *= 10;
                $int = intval($frac);
            }

            $tmp = gmp_mul($this->gmp, $int);
            $tmp = gmp_div($tmp, $mul);
            $res = gmp_add($res, $tmp);
        }

        $this->gmp = $res;
        return $this;
    }

    public function muln($num) {
        return $this->_clone()->imuln($num);
    }

    // `this` * `this`
    public function sqr() {
        return $this->mul($this);
    }

    // `this` * `this` in-place
    public function isqr() {
        return $this->imul($this);
    }

    // Math.pow(`this`, `num`)
    public function pow(BN $num) {
        $res = clone($this);
        $res->gmp = gmp_pow($res->gmp, $num->gmp);
        return $res;
    }

    // Shift-left in-place
    public function iushln($bits) {
        assert('is_integer($bits) && $bits >= 0');
        if ($bits < 54) {
            $this->gmp = gmp_mul($this->gmp, 1 << $bits);
        } else {
            $this->gmp = gmp_mul($this->gmp, gmp_pow(2, $bits));
        }
        return $this;
    }

    public function ishln($bits) {
        assert('!$this->negate()');
        return $this->iushln($bits);
    }

    // Shift-right in-place
    // NOTE: `hint` is a lowest bit before trailing zeroes
    // NOTE: if `extended` is present - it will be filled with destroyed bits
    public function iushrn($bits, $hint = 0, &$extended = null) {
        if( $hint != 0 )
            throw new Exception("Not implemented");

        assert('is_integer($bits) && $bits >= 0');

        if( $extended != null )
            $extended = $this->maskn($bits);
               
        if ($bits < 54) {
            $this->gmp = gmp_div($this->gmp, 1 << $bits);
        } else {
            $this->gmp = gmp_div($this->gmp, gmp_pow(2, $bits));
        }
        return $this;
    }

    public function ishrn($bits, $hint = null, $extended = null) {
        assert('!$this->negative()');
        return $this->iushrn($bits, $hint, $extended);
    }

    // Shift-left
    public function shln($bits) {
        return $this->_clone()->ishln($bits);
    }

    public function ushln($bits) {
        return $this->_clone()->iushln($bits);
    }

    // Shift-right
    public function shrn($bits) {
        return $this->_clone()->ishrn($bits);
    }

    public function ushrn($bits) {
        return $this->_clone()->iushrn($bits);
    }

    // Test if n bit is set
    public function testn($bit) {
        assert('is_integer($bit) && $bit >= 0');
        return gmp_testbit($this->gmp, $bit);
    }

    // Return only lowers bits of number (in-place)
    public function imaskn($bits) {
        assert('is_integer($bits) && $bits >= 0');
        assert('!$this->negative()');
        $mask = "";
        for($i = 0; $i < $bits; $i++)
            $mask .= "1";
        return $this->iand(new BN($mask, 2));
    }

    // Return only lowers bits of number
    public function maskn($bits) {
        return $this->_clone()->imaskn($bits);
    }

    // Add plain number `num` to `this`
    public function iaddn($num) {
        assert('is_numeric($num)');
        $this->gmp = gmp_add($this->gmp, intval($num));
        return $this;
    }

    // Subtract plain number `num` from `this`
    public function isubn($num) {
        assert('is_numeric($num)');
        $this->gmp = gmp_sub($this->gmp, intval($num));
        return $this;
    }

    public function addn($num) {
        return $this->_clone()->iaddn($num);
    }

    public function subn($num) {
        return $this->_clone()->isubn($num);
    }

    public function iabs() {
        if (gmp_sign($this->gmp) < 0) {
            $this->gmp = gmp_abs($this->gmp);
        }
        return $this;
    }

    public function abs() {
        $res = clone($this);
        if (gmp_sign($res->gmp) < 0) 
            $res->gmp = gmp_abs($res->gmp);
        return $res;
    }

    // Find `this` / `num`
    public function div(BN $num) {
        assert('!$num->isZero()');
        $res = clone($this);
        $res->gmp = gmp_div($res->gmp, $num->gmp);
        return $res;
    }

    // Find `this` % `num`
    public function mod(BN $num) {
        assert('!$num->isZero()');
        $res = clone($this);
        $res->gmp = gmp_div_r($res->gmp, $num->gmp);
        return $res;
    }

    public function umod(BN $num) {
        assert('!$num->isZero()');
        $gmp = gmp_sign($num->gmp) < 0 ? gmp_abs($num->gmp) : $num->gmp;        
        $res = clone($this);
        $res->gmp = gmp_mod($this->gmp, $gmp);
        return $res;
    }

    // Find Round(`this` / `num`)
    public function divRound(BN $num)
    {
        assert('!$num->isZero()');

        $negative = $this->negative() !== $num->negative();

        $res = $this->_clone()->abs();
        $arr = gmp_div_qr($res->gmp, gmp_abs($num->gmp));
        $res->gmp = $arr[0];
        $tmp = gmp_sub($num->gmp, gmp_mul($arr[1], 2));
        if( gmp_cmp($tmp, 0) <= 0 && (!$negative || $this->negative() === 0) )
            $res->iaddn(1);
        return $negative ? $res->negi() : $res;
    }

    public function modn($num) {
        assert('is_numeric($num) && $num != 0');
        return gmp_intval( gmp_div_r($this->gmp, intval($num)) );
    }

    // In-place division by number
    public function idivn($num) {
        assert('is_numeric($num) && $num != 0');
        $this->gmp = gmp_div($this->gmp, intval($num));
        return $this;
    }

    public function divn($num) {
        return $this->_clone()->idivn();
    }

    public function gcd(BN $num) {
        $res = clone($this);
        $res->gmp = gmp_gcd($this->gmp, $num->gmp);
        return $res;
    }

    public function invm(BN $num) {
        $res = clone($this);
        $res->gmp = gmp_invert($res->gmp, $num->gmp);
        return $res;
    }

    public function isEven() {
        return !gmp_testbit($this->gmp, 0);
    }

    public function isOdd() {
        return gmp_testbit($this->gmp, 0);
    }

    public function andln($num) {
        assert('is_numeric($num)');
        return gmp_intval(gmp_and($this->gmp, $num));
    }

    public function bincn($num) {
        $tmp = (new BN(1))->iushln($num);
        return $this->add($tmp);
    }

    public function isZero() {
        return gmp_sign($this->gmp) == 0;
    }

    public function cmpn($num) {
        assert('is_numeric($num)');
        return gmp_cmp($this->gmp, $num);
    }

    // Compare two numbers and return:
    // 1 - if `this` > `num`
    // 0 - if `this` == `num`
    // -1 - if `this` < `num`
    public function cmp(BN $num) {
        return gmp_cmp($this->gmp, $num->gmp);
    }

    public function ucmp(BN $num) {
        return gmp_cmp(gmp_abs($this->gmp), gmp_abs($num->gmp));
    }

    public function gtn($num) {
        return $this->cmpn($num) > 0;
    }

    public function gt(BN $num) {
        return $this->cmp($num) > 0;
    }

    public function gten($num) {
        return $this->cmpn($num) >= 0;
    }

    public function gte(BN $num) {
        return $this->cmp($num) >= 0;
    }

    public function ltn($num) {
        return $this->cmpn($num) < 0;
    }

    public function lt(BN $num) {
        return $this->cmp($num) < 0;
    }

    public function lten($num) {
        return $this->cmpn($num) <= 0;
    }

    public function lte(BN $num) {
        return $this->cmp($num) <= 0;
    }

    public function eqn($num) {
        return $this->cmpn($num) === 0;
    }

    public function eq(BN $num) {
        return $this->cmp($num) === 0;
    }

    public function toRed(Red &$ctx) {
        if( $this->red !== null )
            throw new Exception("Already a number in reduction context");
        if( $this->negative() !== 0 )
            throw new Exception("red works only with positives");
        return $ctx->convertTo($this)->_forceRed($ctx);
    }

    public function fromRed() {
        if( $this->red === null )
            throw new Exception("fromRed works only with numbers in reduction context");
        return $this->red->convertFrom($this);
    }

    public function _forceRed(Red &$ctx) {
        $this->red = $ctx;
        return $this;
    }

    public function forceRed(Red &$ctx) {
        if( $this->red !== null )
            throw new Exception("Already a number in reduction context");
        return $this->_forceRed($ctx);
    }

    public function redAdd(BN $num) {
        if( $this->red === null )
            throw new Exception("redAdd works only with red numbers");

        $res = clone($this);
        $res->gmp = gmp_add($res->gmp, $num->gmp);
        if (gmp_cmp($res->gmp, $this->red->m->gmp) >= 0)
            $res->gmp = gmp_sub($res->gmp, $this->red->m->gmp);
        return $res;
        // return $this->red->add($this, $num);
    }

    public function redIAdd(BN $num) {
        if( $this->red === null )
            throw new Exception("redIAdd works only with red numbers");
        $res = $this;
        $res->gmp = gmp_add($res->gmp, $num->gmp);
        if (gmp_cmp($res->gmp, $this->red->m->gmp) >= 0)
            $res->gmp = gmp_sub($res->gmp, $this->red->m->gmp);
        return $res;
        //return $this->red->iadd($this, $num);
    }

    public function redSub(BN $num) {
        if( $this->red === null )
            throw new Exception("redSub works only with red numbers");
        $res = clone($this);
        $res->gmp = gmp_sub($this->gmp, $num->gmp);
        if (gmp_sign($res->gmp) < 0)
            $res->gmp = gmp_add($res->gmp, $this->red->m->gmp);
        return $res;
        //return $this->red->sub($this, $num);
    }

    public function redISub(BN $num) {
        if( $this->red === null )
            throw new Exception("redISub works only with red numbers");
        $this->gmp = gmp_sub($this->gmp, $num->gmp);
        if (gmp_sign($this->gmp) < 0)
            $this->gmp = gmp_add($this->gmp, $this->red->m->gmp);
        return $this;
            
//        return $this->red->isub($this, $num);
    }

    public function redShl(BN $num) {
        if( $this->red === null )
            throw new Exception("redShl works only with red numbers");
        return $this->red->shl($this, $num);
    }

    public function redMul(BN $num) {
        if( $this->red === null )
            throw new Exception("redMul works only with red numbers");
        $res = clone($this);
        $res->gmp = gmp_mod( gmp_mul($this->gmp, $num->gmp), $this->red->m->gmp );
        return $res;            
        /*
        return $this->red->mul($this, $num);
        */
    }

    public function redIMul(BN $num) {
        if( $this->red === null )
            throw new Exception("redIMul works only with red numbers");
        $this->gmp = gmp_mod( gmp_mul($this->gmp, $num->gmp), $this->red->m->gmp );
        return $this;
        //return $this->red->imul($this, $num);
    }

    public function redSqr() {
        if( $this->red === null )
            throw new Exception("redSqr works only with red numbers");
        $res = clone($this);
        $res->gmp = gmp_mod( gmp_mul( $this->gmp, $this->gmp ), $this->red->m->gmp );
        return $res;
        /*
        $this->red->verify1($this);
        return $this->red->sqr($this);
        */
    }

    public function redISqr() {
        if( $this->red === null )
            throw new Exception("redISqr works only with red numbers");
        $res = $this;
        $res->gmp = gmp_mod( gmp_mul( $this->gmp, $this->gmp ), $this->red->m->gmp );
        return $res;
/*        $this->red->verify1($this);
        return $this->red->isqr($this);
        */
    }

    public function redSqrt() {
        if( $this->red === null )
            throw new Exception("redSqrt works only with red numbers");
        $this->red->verify1($this);
        return $this->red->sqrt($this);
    }

    public function redInvm() {
        if( $this->red === null )
            throw new Exception("redInvm works only with red numbers");
        $this->red->verify1($this);
        return $this->red->invm($this);
    }

    public function redNeg() {
        if( $this->red === null )
            throw new Exception("redNeg works only with red numbers");
        $this->red->verify1($this);
        return $this->red->neg($this);
    }

    public function redPow(BN $num) {
        $this->red->verify2($this, $num);
        return $this->red->pow($this, $num);
    }

    public static function red($num) {
        return new Red($num);
    }

    public static function mont($num) {
        return new Red($num);
    }

    public function inspect() {
        return ($this->red == null ? "<BN: " : "<BN-R: ") . $this->toString(16) . ">";
    }

    public function __debugInfo() {
        if ($this->red != null) {
            return ["BN-R" => $this->toString(16)];
        } else {
            return ["BN" => $this->toString(16)];
        }
    }
}

?>
