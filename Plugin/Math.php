<?php

/**
 * Checks incoming requests for simple mathematical expressions, computes the
 * result of such expressions, and responds with a message containing the
 * result.
 */
class Phergie_Plugin_Math extends Phergie_Plugin_Abstract_Command
{
    /**
     * Backwards compatibility for constants not defined in PHP 5.1.x
     */
    public function onInit()
    {
        if(!defined('M_EULER')) {
            define('M_EULER', '0.57721566490153286061');
        }
        if(!defined('M_LNPI')) {
            define('M_LNPI', '1.14472988584940017414');
        }
        if(!defined('M_SQRTPI')) {
            define('M_SQRTPI', '1.77245385090551602729');
        }
    }

    /**
     * Holds the allowed function, characters, operators and constants
     *
     * @var array
     */
    protected $allowed = array(
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
        '+', '-', '/', '*', '.', ' ', '<<', '>>', '%', '&', '^', '|', '~',
        'abs(', 'ceil(', 'floor(', 'exp(', 'log10(',
        'cos(', 'sin(', 'sqrt(', 'tan(', 'rad2deg(',
        'acosh(', 'asin(', 'atan(', 'atanh(', 'cosh(',
        'sinh(', 'tanh(',
        'M_PI', 'INF', 'M_E', 'M_LOG2E', 'M_LOG10E',
        'M_LN2', 'M_LN10', 'M_PI_2', 'M_PI_4', 'M_1_PI',
        'M_2_PI', 'M_SQRTPI', 'M_2_SQRTPI', 'M_SQRT2',
        'M_SQRT2', 'M_SQRT1_2', 'M_LNPI', 'M_EULER'
    );

    /**
     * Holds the functions that are allowed that are contained in the class
     *
     * @return void
     */
    protected $classFuncs = array(
        'sind(', 'cosd(', 'tand(',
        'atand(', 'asind(', 'acosd('
    );

    /**
     * Holds the functions that can accept multiple arguments.
     *
     * @return void
     */
    protected $funcs = array(
        'round(', 'log(', 'pow(',
        'max(', 'min(', 'rand(',
        'atan2(', 'mt_rand('
    );
    /**
     * Custom functions as defined in $classFuncs to be used in math operations
     * these automatically get prepended with $this-> before the calculations
     * take place.
     */
    //return sine of <an angle in degrees>
    protected function sind($degrees)
    {
        return sin(deg2rad($degrees));
    }
    //return cosd of <an angle in degrees>
    protected function cosd($degrees)
    {
        return cos(deg2rad($degrees));
    }
    //return tand of <an angle in degrees>
    protected function tand($degrees)
    {
        return tan(deg2rad($degrees));
    }
    //return atand of <an angle in degrees>
    protected function atand($x)
    {
        return rad2deg(atan($x));
    }
    //return asind of <an angle in degrees>
    protected function asind($x)
    {
        return rad2deg(asin($x));
    }
    //return acosd of <an angle in degrees>
    protected function acosd($x)
    {
        return rad2deg(acos($x));
    }

    /**
     * Processes a request to perform a calculations.
     *
     * @param string $expr Expression to evaluate
     * @return void
     */
    protected function processRequest($expr, $quietMode = false)
    {
        $user = $this->event->getNick();

        // Replace constants
        $equation = str_ireplace(
            array('pi', 'M_PI()', 'chucknorris', 'inf', ' e ', '\\'),
            array('M_PI', 'M_PI', 1e10000, 'INF', ' M_E ', '/'),
            $expr
        );
        $equationSrc = $equation;
        $equation = preg_replace('/\s/', '', $equation);
        $this->allowed = array_merge($this->allowed, $this->classFuncs);

        // Parse equation
        $out = '';
        $ptr = 1;
        $allowcomma = 0;
        while (strlen($equation) > 0) {
            $substr = substr($equation, 0, $ptr);
            // Allowed string
            if (array_search($substr, $this->allowed) !== false) {
                $out .= $substr;
                $equation = substr($equation, $ptr);
                $ptr = 0;
            // Allowed func
            } elseif (array_search($substr, $this->funcs) !== false) {
                $out .= $substr;
                $equation = substr($equation, $ptr);
                $ptr = 0;
                $allowcomma++;
                if ($allowcomma === 1) {
                    $this->allowed[] = ',';
                }
                // Opening parenthesis
            } elseif ($substr === '(') {
                if ($allowcomma > 0) {
                    $allowcomma++;
                }
                $last = substr($out, -1);
                if (!empty($last) && !in_array($last, array('+', '-', '/', '*', '('))) {
                    $out .= '*';
                }
                $out .= $substr;
                $equation = substr($equation, $ptr);
                $ptr = 0;
            // Closing parenthesis
            } elseif ($substr === ')') {
                if ($allowcomma > 0) {
                    $allowcomma--;
                    if ($allowcomma === 0) {
                        array_pop($this->allowed);
                    }
                }

                $out .= $substr;
                $next = substr($equation, 1, 1);
                if (!empty($next) && !in_array($next, array('+', '-', '/', '*', ')'))) {
                    $out .= '*';
                }
                $equation = substr($equation, $ptr);
                $ptr = 0;
            // Parse error if we've consumed the entire equation without finding anything valid
            } elseif ($ptr >= strlen($equation)) {
                if (!$quietMode) {
                    $this->doNotice($user, 'Syntax error at "' . $substr . '" in equation "' . $equationSrc . '"');
                }
                return;
            } else {
                $ptr++;
            }
        }

        foreach($this->classFuncs as $func) {
            $out = str_replace($func, '$this->' . $func, $out);
        }

        $res = @eval('return ' . $out . ';');
        $source = $this->event->getSource();
        if ($res === false) {
            if (!$quietMode) {
                $this->doNotice($user, 'Computation error, nothing was returned, perhaps division by zero?');
            }
        } else {
            $this->doPrivmsg($source, $user . ': Result -> ' . $res);
        }
    }

    /**
     * Forwards math commands onto a central handler.
     *
     * @return void
     */
    public function onDoMath($expr)
    {
        $this->processRequest($expr, true);
    }

    /**
     * Forwards calc commands onto a central handler.
     *
     * @return void
     */
    //public function onDoCalc($expr)
    //{
    //    $this->processRequest($expr);
    //}
}
