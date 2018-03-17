<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
It is published under the MIT Open Source License.

Copyright 2018, Egbert van der Wal

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

namespace Wedeto\DB;

use Wedeto\Util\Configuration;
use Wedeto\Util\DI\Injector;
use Wedeto\Util\Validation\Type;

use Wedeto\DB\Exception\ConfigurationException;

/**
 * Database configuration
 */
class DBConfig extends Configuration
{
    /** The Configuration can be reused */
    const WDI_REUSABLE = true;

    /** It can be auto instantiated, as the dependent Configuration must be present */
    const WDI_NOAUTO = false;

    /**
     * Create a new DBConfig instance. Suitable for use with DI.
     *
     * @param Configuration $config The complete configuration object
     * @param string $wdiSelector The default selector provided by DI. Omit to use default
     */
    public function __construct(Configuration $config = null, string $wdiSelector = Injector::DEFAULT_SELECTOR)
    {
        if (null === $config)
            $config = new Dictionary;

        $base = $config['sql'];
        $sub = ($wdiSelector === Injector::DEFAULT_SELECTOR) ? "default" : $wdiSelector;

        if (!$config->has('sql', $sub))
        {
            throw new ConfigurationException("Database $sub not defined in configuration");
        }

        // Enforce types for database configuration
        $config = $config->get('sql', $sub);
        $allowed = [
            'username' => Type::STRING,
            'password' => Type::STRING,
            'hostname' => Type::STRING,
            'port' => Type::INTEGER,
            'type' => Type::STRING,
            'dsn' => Type::STRING,
            'lazy' => Type::BOOLEAN,
            'database' => Type::STRING,
            'schema' => Type::STRING,
            'prefix' => Type::STRING
        ];

        parent::__construct($allowed, $config);
    }
}
