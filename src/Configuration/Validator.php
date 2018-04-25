<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 03/10/16
 * Time: 13:45
 */

namespace Keboola\DbWriter\Configuration;

use Keboola\DbWriter\Exception\UserException;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigException;
use Symfony\Component\Config\Definition\Processor;

class Validator
{
    public static function getValidator($definition)
    {
        return function ($parameters) use ($definition) {
            try {
                $processor = new Processor();
                $processedParameters = $processor->processConfiguration(
                    $definition,
                    [$parameters]
                );

                if (!empty($processedParameters['db']['#password'])) {
                    $processedParameters['db']['password'] = $processedParameters['db']['#password'];
                }

                return $processedParameters;
            } catch (ConfigException $e) {
                throw new UserException($e->getMessage(), 0, $e);
            }
        };
    }
}
