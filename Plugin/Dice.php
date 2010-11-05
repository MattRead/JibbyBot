<?php

/**
 * Checks incoming requests for dice/roll requests and processes the message for
 * the dice arguments and reponds with a message containing the dice results.
 */
class Phergie_Plugin_Dice extends Phergie_Plugin_Abstract_Command
{

    /**
     * Whether or not to allow parsing of expressions
     *
     * @var bool
     */
    protected $allowExpressions = true;

    /**
     * An array containing the max values for the given dice iterations, number
     * of dice and sides.
     *
     * @var array
     */
    protected $max = array(
        'total' => 20,
        'dice' => 100,
        'sides' => 100
    );

    /**
     * Holds the allowed characters and operators
     *
     * @var array
     */
    protected $allowed = array
    (
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
        '+', '-', '/', '*', ' ', '<<', '>>', '%', '&', '^', '|', '~'
    );


    /**
     * Processes a request to perform a calculations on an expression.
     *
     * @param string $expr Expression to evaluate
     * @return void
     */
    protected function processExpression($expr)
    {
        // Clean up the expression
        $expr = str_replace('\\', '/', preg_replace('/\s/', '', $expr));

        // Parse equation
        $out = '';
        $ptr = 1;
        while (strlen($expr) > 0) {
            $substr = substr($expr, 0, $ptr);
            // Allowed string
            if (array_search($substr, $this->allowed) !== false) {
                $out .= $substr;
                $expr = substr($expr, $ptr);
                $ptr = 0;
            // Parse error if we've consumed the entire equation without finding anything valid
            } elseif ($ptr >= strlen($expr)) {
                return null;
            } else {
                $ptr++;
            }
        }
        $res = @eval('return ' . $out . ';');
        if ($res === false) {
            return null;
        } else {
            return $res;
        }
    }

    /**
     * Processes a request to perform dice rolls from a given message as well as
     * prcessessing an optional expression that gets added to the final dice
     * tota;
     * Dice Syntax: [<number>[#| ]]<dice>d<sides>[[+|-]<expresion>]
     *
     * @param string $message Message to processes
     * @return void
     */
    protected function processDice($message, $recursive = false)
    {
        $message = trim($message);
        if (!empty($message)) {
            if (preg_match('{^(?:([0-9]+)[\#|:|\s])?(?:([0-9]+)[\s|d])?(?:[\s|d]?([0-9]+))(?:([+\*-])([^\s]*))?(.*)?$}ix', $message, $m)) {
                $numDice = ($m[1] < 1 ? 1 : ($m[1] > $this->max['total'] ? $this->max['total'] : $m[1]));
                $dice = ($m[2] < 1 ? 1 : ($m[2] > $this->max['dice'] ? $this->max['dice'] : $m[2]));
                $sides = ($m[3] < 1 ? 1 : ($m[4] > $this->max['sides'] ? $this->max['sides'] : $m[3]));
                $operator = trim($m[4]);
                $expression = ((isset($m[5]) && !empty($m[5])) ? trim($m[5]) : '0');
                $description = rtrim(trim($m[6]), '+-/*<>%&^|~');
                $diceMessage = ($numDice > 1 ? $numDice . '#' : '') . $dice . 'd' . $sides . (!empty($operator) && !empty($expression) ? $operator . $expression : '');

                $bonus = 0;
                if (!empty($expression) && $this->allowExpressions) {
                    $expression = preg_replace('/((?:[0-9]+)?d[0-9]+(?:[+\*-][^\s]+)?)/e', '$this->processDice("\\1", true)', $expression);
                    $bonus = $this->processExpression($expression);
                    if (is_null($bonus)) {
                        return ($recursive ? null : 'Error while processing the dice expression.');
                    }
                }

                $output = array();
                for ($i = 0; $i < $numDice; $i++) {
                    $total = 0;
                    for ($d = 0; $d < $dice; $d++) {
                        $total += mt_rand(1, $sides);
                        switch ($operator) {
                            case '+': $total += $bonus; break;
                            case '-': $total -= $bonus; break;
                            case '*': $total *= $bonus; break;
                        }
                    }
                    $output[] = $total;
                }
                return ($recursive ? implode('+', $output) : 'Rolls a ' . $diceMessage . (!empty($description) ? ' ' . $description : '') . ' and gets ' . implode(', ', $output) . '.');
            }
        }
        return false;
    }

    /**
     * Forwards the dice/roll commands onto a central handler.
     *
     * @return void
     */
    public function onDoRoll($message)
    {
        $source = $this->event->getSource();
        $target = $this->event->getNick();

        if ($result = $this->processDice($message)) {
            $this->doPrivmsg($source, $target . ': ' . $result);
        }
    }

    /**
     * Forwards the dice/roll commands onto a central handler.
     *
     * @return void
     */
    public function onDoDice($message)
    {
        $source = $this->event->getSource();
        $target = $this->event->getNick();

        if ($result = $this->processDice($message)) {
            $this->doPrivmsg($source, $target . ': ' . $result);
        }
    }

    /**
     * Proccesses incoming CTCP request for the CTCP request DICE or ROLL and
     * returns the dice results.
     *
     * @return void
     */
    public function onCtcp()
    {
        $source = $this->event->getSource();
        $ctcp = strtoupper($this->event->getArgument(1));
        list($ctcp, $message) = array_pad(explode(' ', $ctcp, 2), 2, null);

        if (($ctcp == 'DICE' || $ctcp == 'ROLL') and $result = $this->processDice($message)) {
            $this->doCtcpReply($source, $ctcp, $result);
        }
    }
}
