<?php
namespace wapmorgan\PhpCodeFixer;

if (!defined('T_TRAIT')) define('T_TRAIT', 'trait');

class PhpCodeFixer {

    /**
     * Version of scanner
     */
    const VERSION = '2.0.14';

    /**
     * @var integer Size of file to process. Can be decreased to use less memory or prevent crashes due to memory limit.
     */
    static public $fileSizeLimit;

    /**
     * @var array Extensions of file to process.
     */
    static public $fileExtensions = ['php', 'php5', 'phtml'];

    /**
     * @param string $dir
     * @param IssuesBank $issues
     * @param array $excludeNamesList
     * @param array $skipChecks
     * @return Report
     */
    static public function checkDir($dir, IssuesBank $issues, array $excludeNamesList = [], array $skipChecks = []) {
        TerminalInfo::echoWithColor('Scanning '.$dir.' ...'.PHP_EOL, TerminalInfo::GRAY_TEXT);
        $report = new Report('Folder '.$dir, $dir);
        self::checkDirInternal($dir, $issues, $report, $excludeNamesList, $skipChecks);
        return $report;
    }

    /**
     * @param string $dir
     * @param IssuesBank $issues
     * @param Report $report
     * @param array $excludedNames
     * @param array $skipChecks
     */
    static protected function checkDirInternal($dir, IssuesBank $issues, Report $report, array $excludedNames, array $skipChecks) {
        foreach (glob($dir.'/*') as $file) {
            if (is_dir($file)) {
                if (in_array(strtolower(basename($file)), $excludedNames, true))
                    TerminalInfo::echoWithColor('Folder '.$file.' skipped'.PHP_EOL, TerminalInfo::GRAY_TEXT);
                else
                    self::checkDirInternal($file, $issues, $report, $excludedNames, $skipChecks);
            } else if (is_file($file) && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::$fileExtensions)) {
                self::checkFile($file, $issues, $skipChecks, $report);
            }
        }
    }

    /**
     * @param array $checks
     * @param array $skipChecks
     * @return array
     */
    static private function filterSkippedChecks(array $checks, array $skipChecks) {
        return array_filter($checks, function($key) use ($skipChecks) {
            foreach($skipChecks as $skipCheck) {
                if(stripos($key, $skipCheck) !== false) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array $tokens
     * @param int $class_pos
     * @param string $default -- default: ''
     * @return bool|string
     */
    static private function findClassNamespaceInTokens(array $tokens, $class_pos, $default = '') {
        $namespace_tokens = array_slice($tokens, 0, $class_pos - 1);
        $namespace_pos = array_search_column($namespace_tokens, T_NAMESPACE, 0);

        if (empty($namespace_pos)) {
            return $default;
        }

        $namespace_tokens = array_slice($namespace_tokens, $namespace_pos);
        $namespace = '';

        foreach ($namespace_tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_STRING, T_NS_SEPARATOR])) {
                $namespace .= $token[1];
            }
            else if (in_array($token, [';', '{'])) {
                break;
            }
        }

        return (!empty($namespace)) ? $namespace : '';
    }

    /**
     * @param string $file
     * @param IssuesBank $issues
     * @param Report|null $report
     * @param array $skipChecks
     * @return Report|bool
     */
    static public function checkFile($file, IssuesBank $issues, array $skipChecks, Report $report = null) {
        if (self::$fileSizeLimit !== null && filesize($file) > self::$fileSizeLimit) {
            TerminalInfo::echoWithColor('Skipping file '.$file.' due to file size limit.'.PHP_EOL, TerminalInfo::GRAY_TEXT);
            return false;
        }
        if (empty($report)) $report = new Report('File '.basename($file), dirname(realpath($file)));
        $tokens = token_get_all(file_get_contents($file));

        // cut off heredoc, comments
        while (in_array_column($tokens, T_START_HEREDOC, 0)) {
            $start = array_search_column($tokens, T_START_HEREDOC, 0);
            $end = array_search_column($tokens, T_END_HEREDOC, 0);
            array_splice($tokens, $start, ($end - $start + 1));
        }

        // find for deprecated functions
        $deprecated_functions = self::filterSkippedChecks($issues->getAll('functions'), $skipChecks);
        $used_functions = array_filter_by_column($tokens, T_STRING, 0, true);
        foreach ($used_functions as $used_function_i => $used_function) {
            if (isset($deprecated_functions[$used_function[1]])) {
                // additional check for "(" after this token
                if (!isset($tokens[$used_function_i+1]) || $tokens[$used_function_i+1] !== '(')
                    continue;
                // additional check for lack of "->" and "::" before this token
                if (isset($tokens[$used_function_i-1])
                    && is_array($tokens[$used_function_i-1])
                    && in_array($tokens[$used_function_i-1][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true))
                    continue;
                // additional check for lack of "function" before this token
                if (isset($tokens[$used_function_i-2]) && is_array($tokens[$used_function_i-2]) && $tokens[$used_function_i-2][0] === T_FUNCTION)
                    continue;

                $function = $deprecated_functions[$used_function[1]];
                $report->add($function[1], 'function', $used_function[1], ($function[0] != $used_function[1] ? $function[0] : null), $file, $used_function[2]);
            }
        }

        // find for deprecated constants
        $deprecated_constants = self::filterSkippedChecks($issues->getAll('constants'), $skipChecks);
        $used_constants = array_filter_by_column($tokens, T_STRING, 0, true);
        foreach ($used_constants as $used_constant_i => $used_constant) {
            if (isset($deprecated_constants[$used_constant[1]])) {
                $constant = $deprecated_constants[$used_constant[1]];
                $report->add($constant[1], 'constant', $used_constant[1], ($constant[0] != $used_constant[1] ? $constant[0] : null), $file, $used_constant[2]);
            }
        }

        // find for deprecated ini settings
        $deprecated_ini_settings = self::filterSkippedChecks($issues->getAll('ini_settings'), $skipChecks);
        foreach ($tokens as $i => $token) {
            if ($token[0] == T_STRING && in_array($token[1], array('ini_alter', 'ini_set', 'ini_get', 'ini_restore'))) {
                // syntax structure check
                if ($tokens[$i+1] == '(' && is_array($tokens[$i+2]) && $tokens[$i+2][0] == T_CONSTANT_ENCAPSED_STRING) {
                    $ini_setting = $tokens[$i+2]; // ('ini_setting'
                    $ini_setting[1] = trim($ini_setting[1], '\'"');
                    if (isset($deprecated_ini_settings[$ini_setting[1]])) {
                        $deprecated_setting = $deprecated_ini_settings[$ini_setting[1]];
                        $report->add($deprecated_setting[1], 'ini', $ini_setting[1], ($deprecated_setting[0] != $ini_setting[1] ? $deprecated_setting[0] : null), $file, $ini_setting[2]);
                    }
                }
            }
        }

        // find for deprecated functions usage
        $deprecated_functions_usage = self::filterSkippedChecks($issues->getAll('functions_usage'), $skipChecks);

        /** @var array $global_deprecated_usage_checkers List of global checkers (for all function calls) */
        $global_deprecated_usage_checkers = [];
        foreach ($deprecated_functions_usage as $function => $function_usage_checker) {
            if (is_int($function)) {
                $global_deprecated_usage_checkers[] = $function_usage_checker;
                unset($deprecated_functions_usage[$function]);
            }
        }

        $function_declaration = false;

        foreach ($tokens as $i => $token) {
            if ($token[0] == T_FUNCTION) {
                $function_declaration = true;
                continue;
            }

            if ($function_declaration === true) {
                if ($token === '{') {
                    $function_declaration = false;
                }

                continue;
            }

            // not a string: for sure not a function / method call
            if ($token[0] != T_STRING) {
                continue;
            }

            // check if the next non-whitespace character is '('
            if ((!isset($tokens[$i + 1]) || $tokens[$i + 1] !== '(') && (!isset($tokens[$i + 2]) || $tokens[$i + 2] !== '(')) {
                continue;
            }

            if (!isset($deprecated_functions_usage[$token[1]]) && empty($global_deprecated_usage_checkers))
                continue;

            // get func arguments
            $functionTokens = [$token];
            $k = $i+2;
            $braces = 1;
            while ($braces > 0 && isset($tokens[$k])) {
                if (count($functionTokens) > 1 || $tokens[$k] !== ')') $functionTokens[] = $tokens[$k];
                if ($tokens[$k] == ')') {/*var_dump($tokens[$k]);*/ $braces--;}
                else if ($tokens[$k] == '(') {/*var_dump($tokens[$k]);*/ $braces++; }
                // var_dump($braces);
                $k++;
            }
            //$function[] = $tokens[$k];

            // checking exactly this function usage
            if (isset($deprecated_functions_usage[$token[1]])) {
                $result = self::callFunctionUsageChecker(ltrim($deprecated_functions_usage[$token[1]][0], '@'),
                    $token[1],
                    $functionTokens);
                if ($result) {
                    $report->add($deprecated_functions_usage[$token[1]][1],
                        'function_usage',
                        $token[1] . '() (' . $deprecated_functions_usage[$token[1]][0] . ')',
                        is_string($result) ? $result : null,
                        $file,
                        $token[2]);
                }
            }

            // checking global function usages
            if (!empty($global_deprecated_usage_checkers)) {
                foreach ($global_deprecated_usage_checkers as $global_function_usage_checker) {
                    $result = self::callFunctionUsageChecker(ltrim($global_function_usage_checker[0], '@'),
                        $token[1],
                        $functionTokens);
                    if ($result) {
                        $report->add($global_function_usage_checker[1],
                            'function_usage',
                            $token[1] . '() (' . $global_function_usage_checker[0] . ')',
                            is_string($result) ? $result : null,
                            $file,
                            $token[2]);
                    }
                }
            }
        }

        // find for deprecated variables
        $deprecated_variables = self::filterSkippedChecks($issues->getAll('variables'), $skipChecks);
        $used_variables = array_filter_by_column($tokens, T_VARIABLE, 0);
        foreach ($used_variables as $used_variable) {
            if (isset($deprecated_variables[$used_variable[1]])) {
                $variable = $deprecated_variables[$used_variable[1]];
                $report->add($variable[1], 'variable', $used_variable[1], ($variable[0] != $used_variable[1] ? $variable[0] : null), $file, $used_variable[2]);
            }
        }

        // oop reserved words
        $oop_words = [T_CLASS, T_INTERFACE];
        if (defined('T_TRAIT')) $oop_words[] = T_TRAIT;

        // find for reserved identifiers used as names
        $identifiers = self::filterSkippedChecks($issues->getAll('identifiers'), $skipChecks);
        if (!empty($identifiers)) {
            foreach ($tokens as $i => $token) {
                if (in_array($token[0], $oop_words)) {
                    if (isset($tokens[$i+2]) && is_array($tokens[$i+2]) && $tokens[$i+2][0] == T_STRING) {
                        $used_identifier = $tokens[$i+2];
                        if (isset($identifiers[$used_identifier[1]])) {
                            $identifier = $identifiers[$used_identifier[1]];
                            $report->add($identifier[1], 'identifier', $used_identifier[1], null, $file, $used_identifier[2]);
                        }
                    }
                }
            }
        }

        // find for methods naming deprecations
        $methods_naming = self::filterSkippedChecks($issues->getAll('methods_naming'), $skipChecks);
        if (!empty($methods_naming)) {
            $namespace = null;
            while (in_array_column($tokens, T_CLASS, 0)) {
                $total = count($tokens);
                $i = array_search_column($tokens, T_CLASS, 0);
                $class_start = $i;
                if (!is_array($tokens[$class_start-1]) || $tokens[$class_start-1][1] != '::') {
                    $namespace = self::findClassNamespaceInTokens($tokens, $class_start, $namespace);
                    $class_name = $tokens[$i+2][1];
                    $methods = [];
                    $braces = 1;
                    while($tokens[$i] !== '{') {
                        $i++;
                    }
                    $i++;
                    while (($braces > 0) && (($i+1) <= $total)) {
                        if ($tokens[$i] == '{') {
                            $braces++;
                            /*echo '++';*/
                        } else if ($tokens[$i] == '}') {
                            $braces--;
                            /*echo '--';*/
                        } else if (is_array($tokens[$i]) && $tokens[$i][0] == T_FUNCTION && is_array($tokens[$i+2])) {
                            $method_attributes = [];
                            $attributes_index = 2;
                            while (is_array($tokens[$i - $attributes_index])
                                && in_array($tokens[$i - $attributes_index][1], ['static', 'public', 'private', 'protected'], true)) {
                                $method_attributes[] = $tokens[$i - $attributes_index][1];
                                $attributes_index += 2;
                            }
                            $method_name = $tokens[$i+2][1];
                            $methods[$method_name] = [
                                'line' => $tokens[$i][2],
                                'attributes' => $method_attributes
                            ];
                        }
                        $i++;
                    }
                    foreach ($methods as $method_name => $method_data) {
                        foreach ($methods_naming as $methods_naming_checker) {
                            $checker = ltrim($methods_naming_checker[0], '@');
                            require_once dirname(dirname(__FILE__)).'/data/'.$checker.'.php';
                            $checker = __NAMESPACE__.'\\'.$checker;
                            $result = $checker($class_name, $method_name, $method_data['attributes'], $methods, $namespace);
                            if($result !== false) {
                                $report->add($methods_naming_checker[1], 'method_name', $method_name.':'.$class_name.' ('.$methods_naming_checker[0].')', null, $file, $method_data['line']);
                            }
                        }
                    }
                } else {
                    // ::class
                    $i++;
                }
                array_splice($tokens, 0, $i);
            }
        }
        return $report;
    }

    /**
     * Creates a tokens hierarchy by () from plain list
     * @param array $tokens
     * @return array
     */
    static public function makeFunctionCallTree(array $tokens) {
        $tree = [];
        $braces = 0;
        $i = 1;

        while (/*$braces > 0 &&*/ isset($tokens[$i])) {
            if ($tokens[$i] == '(') $braces++;
            else if ($tokens[$i] == ')') $braces--;
            else $tree[$braces][] = $tokens[$i];
            $i++;
        }
        return $tree;
    }

    /**
     * Divide first level of tokens hierarchy by comma
     * @param array $tokens
     * @return array
     */
    static public function divideByComma(array $tokens) {
        $delimited = [];
        $comma = 0;
        foreach ($tokens as $token) {
            if ($token == ',') $comma++;
            else $delimited[$comma][] = $token;
        }
        return $delimited;
    }

    /**
     * Removes all T_WHITESPACE tokens from tokens hierarchy
     * @param array $tokens
     * @return array
     */
    static public function trimSpaces(array $tokens) {
        $trimmed = [];
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] == T_WHITESPACE)
                    continue;
                else
                    $trimmed[] = self::trimSpaces($token);
            }
            else
                $trimmed[] = $token;
        }
        return $trimmed;
    }

    /**
     * Calls function-usage checker
     * @param string $checker
     * @param string $functionName
     * @param array $callTokens
     * @return boolean
     */
    protected static function callFunctionUsageChecker($checker, $functionName, array $callTokens)
    {
        require_once dirname(dirname(__FILE__)).'/data/'.$checker.'.php';
        $checker = __NAMESPACE__ . '\\' . $checker;
        $result = $checker($callTokens, $functionName);
        return $result;
    }
}