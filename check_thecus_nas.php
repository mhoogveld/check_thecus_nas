#!/usr/bin/env php
<?php
/*
 * Copyright (C) 2015 Maarten Hoogveld
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */


/**
 * Thecus NAS Nagios check plugin based on http-access of UI elements
 * Created for and tested on a Thecus NAS N5500, firmware V5.00.04
 *
 * @author Maarten Hoogveld <maarten@hoogveld.org> / <m.hoogveld@elevate.nl>
 * @date 2015-07-17
 *
 */

$thecus = new ThecusChecker();
$statusCode = $thecus->check();
exit($statusCode);


class ThecusChecker
{
    const VERSION = '1.0';

    /** Output constants for as defined by Nagios */
    const STATUS_OK = 0;
    const STATUS_WARNING = 1;
    const STATUS_CRITICAL = 2;
    const STATUS_UNKNOWN = 3;

    /** The check type constants */
    const TYPE_HEALTH = 'health';
    const TYPE_CPU = 'cpu';
    const TYPE_DISKUSAGE = 'disk-usage';
    const TYPE_MEMORY = 'memory';

    /** Default values for cpu usage thesholds in percentage */
    const DEFAULT_CPU_WARN = 90;
    const DEFAULT_CPU_CRIT = 95;

	/** Default values for memory usage thesholds in percentage */
    const DEFAULT_MEM_WARN = 90;
    const DEFAULT_MEM_CRIT = 95;

    /** Default values for disk usage thesholds in percentage */
    const DEFAULT_DISKUSAGE_WARN = 80;
    const DEFAULT_DISKUSAGE_CRIT = 90;

    /** Default values for disk temerature (SMART attr 194) thesholds in degrees Celcius */
    const DEFAULT_DISKTEMP_WARN = 55;
    const DEFAULT_DISKTEMP_CRIT = 60;

    /** Default values for number of Reallocated Sectors on disk (SMART attr 5) thesholds */
    const DEFAULT_REALLOCSECT_WARN = 32; // as defined used by Thecus device (see manual)
    const DEFAULT_REALLOCSECT_CRIT = 320;

    /** Default values for number of Current Pending Sectors on disk (SMART attr 197) thesholds */
    const DEFAULT_CURPENDINGSECT_WARN = 1;
    const DEFAULT_CURPENDINGSECT_CRIT = 1; // as defined used by Thecus device (see manual)

    /** Default values for uptime thesholds in seconds (check not implemented yet) */
    const DEFAULT_UPTIME_WARN = 1200;
    const DEFAULT_UPTIME_CRIT = 300;

    /** @var bool */
    protected $debug = false;

    /** @var string */
    protected $hostname;

    /** @var string */
    protected $username;

    /** @var string */
    protected $password;

    /** @var string */
    protected $checkType;

    /** @var int */
    protected $statusCode = self::STATUS_OK;

    /**
     * @var string[]  An array of strings which will be concatenated (comma separated)
     *                and printed as status text for check output
     */
    protected $statusText = array();

    /**
     * @var string[]  An array of performance data strings which will be concatenated (space separated)
     *                and printed as perfdata for check output
     */
    protected $perfData = array();

    /** @var string */
    protected $ignoreBadSectors;

    /** @var string */
    protected $ignoreSmartStatus;

    /** @var array  An (internally used) array of threshold types and values */
    protected $thresholds = array();

    /** @var string */
    protected $cookieDir = '/tmp';

    /**
     * @param string|null $hostname
     * @param string|null $username
     * @param string|null $password
     */
    public function __construct($hostname = null, $username = null, $password = null)
    {
        if (!empty($hostname)) {
            $this->setHostname($hostname);
        }
        if (!empty($username)) {
            $this->setUsername($username);
        }
        if (!empty($password)) {
            $this->setPassword($password);
        }

        // Default thresholds
        $this->setCpuUsageThreshold(self::STATUS_WARNING, self::DEFAULT_CPU_WARN);
        $this->setCpuUsageThreshold(self::STATUS_CRITICAL, self::DEFAULT_CPU_CRIT);

        $this->setMemoryUsageThreshold(self::STATUS_WARNING, self::DEFAULT_MEM_WARN);
        $this->setMemoryUsageThreshold(self::STATUS_CRITICAL, self::DEFAULT_MEM_CRIT);

        $this->setDiskUsageThreshold(self::STATUS_WARNING, self::DEFAULT_DISKUSAGE_WARN);
        $this->setDiskUsageThreshold(self::STATUS_CRITICAL, self::DEFAULT_DISKUSAGE_CRIT);

        $this->setDiskTempThreshold(self::STATUS_WARNING, self::DEFAULT_DISKTEMP_WARN);
        $this->setDiskTempThreshold(self::STATUS_CRITICAL, self::DEFAULT_DISKTEMP_CRIT);

        $this->setReallocSectThreshold(self::STATUS_WARNING, self::DEFAULT_REALLOCSECT_WARN);
        $this->setReallocSectThreshold(self::STATUS_CRITICAL, self::DEFAULT_REALLOCSECT_CRIT);

        $this->setCurPendingSectThreshold(self::STATUS_WARNING, self::DEFAULT_CURPENDINGSECT_WARN);
        $this->setCurPendingSectThreshold(self::STATUS_CRITICAL, self::DEFAULT_CURPENDINGSECT_CRIT);

        $this->setUptimeThreshold(self::STATUS_CRITICAL, self::DEFAULT_UPTIME_WARN);
        $this->setUptimeThreshold(self::STATUS_WARNING, self::DEFAULT_UPTIME_CRIT);
    }

    /**
     * @return string
     */
    public function getHostname()
    {
        return $this->hostname;
    }

    /**
     * @param string $hostname
     * @return self
     */
    public function setHostname($hostname)
    {
        $this->hostname = $hostname;
        return $this;
    }

    /**
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @param string $username
     * @return self
     */
    public function setUsername($username)
    {
        $this->username = $username;
        return $this;
    }

    /**
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @param string $password
     * @return self
     */
    public function setPassword($password)
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @param int $statusCode
     * @return self
     */
    protected function setStatusCode($statusCode)
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * @return array
     */
    public function getStatusText()
    {
        return $this->statusText;
    }

    /**
     * @param string[] $statusText
     * @return self
     */
    protected function setStatusText($statusText)
    {
        $this->statusText = $statusText;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getPerfData()
    {
        return $this->perfData;
    }

    /**
     * @param string[] $perfData
     * @return self
     */
    protected function setPerfData($perfData)
    {
        $this->perfData = $perfData;
        return $this;
    }

    /**
     * @return string
     */
    public function getIgnoreBadSectors()
    {
        if (isset($this->ignoreBadSectors)) {
            return $this->ignoreBadSectors;
        } else {
            return 0;
        }
    }

    /**
     * @param string $ignoreBadSectors
     * @return self
     */
    public function setIgnoreBadSectors($ignoreBadSectors)
    {
        $this->ignoreBadSectors = $ignoreBadSectors;
        return $this;
    }

    /**
     * @return string
     */
    public function getIgnoreSmartStatus()
    {
        if (isset($this->ignoreSmartStatus) && $this->ignoreSmartStatus == true) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param string $ignoreSmartStatus
     * @return self
     */
    public function setIgnoreSmartStatus($ignoreSmartStatus)
    {
        $this->ignoreSmartStatus = $ignoreSmartStatus;
        return $this;
    }

    /**
     * Sets the total status information for the check output.
     * Status texts can be given as a string or an array of strings
     * Performance data given as a string or an array of strings
     * Both status texts and performance data get concatenated (separated by commas and spaces respectively)
     * in the check output.
     *
     * @param int $code
     * @param string[]|string|null $text
     * @param string[]|string|null $perfData
     * @return self
     */
    protected function setStatusInfo($code, $text = null, $perfData = null)
    {
        $this->setStatusCode($code);

        if (empty($text)) {
            $this->statusText = array();
        } else if (is_array($text)) {
            $this->statusText = $text;
        } else {
            $this->statusText = array($text);
        }

        if (empty($perfData)) {
            $this->perfData = array();
        } else if (is_array($perfData)) {
            $this->perfData = $perfData;
        } else {
            $this->perfData = array($perfData);
        }

        return $this;
    }

    /**
     * Adds a part of status information which will get aggregated into the total check output.
     *
     * Higher values of a statuscCode will override lower values in consecutive calls (higher as defined by the
     * STATUS_XXX constant values)
     * Status texts will get added to a list of status texts
     * Performance data will get added to a list of performance data texts
     *
     * @param int $code
     * @param string|null $text
     * @param string|null $perfData
     * @return self
     */
    protected function addStatusInfo($code, $text = null, $perfData = null)
    {
        if (intval($code) > $this->getStatusCode()) {
            $this->setStatusCode($code);
        }

        if (null !== $text) {
            $this->statusText[] = $text;
        }

        if (null !== $perfData) {
            $this->perfData[] = $perfData;
        }

        return $this;
    }

    /**
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @return int|null
     */
    public function getReallocSectThreshold($level)
    {
        return $this->getThreshold('reallocated_sector', $level);
    }

    /**
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @param int|null $value
     * @return self
     */
    public function setReallocSectThreshold($level, $value)
    {
        return $this->setThreshold('reallocated_sector', $level, $value);
    }

    /**
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @return int|null
     */
    public function getDiskUsageThreshold($level)
    {
        return $this->getThreshold('disk_usage', $level);
    }

    /**
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @param int|null $value
     * @return self
     */
    public function setDiskUsageThreshold($level, $value)
    {
        return $this->setThreshold('disk_usage', $level, $value);
    }

    /**
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @return int|null
     */
    public function getDiskTempThreshold($level)
    {
        return $this->getThreshold('disk_temp', $level);
    }

    /**
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @param int|null $value
     * @return self
     */
    public function setDiskTempThreshold($level, $value)
    {
        return $this->setThreshold('disk_temp', $level, $value);
    }

    /**
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @return int|null
     */
    public function getCurPendingSectThreshold($level)
    {
        return $this->getThreshold('current_pending_sector', $level);
    }

    /**
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @param int|null $value
     * @return self
     */
    public function setCurPendingSectThreshold($level, $value)
    {
        return $this->setThreshold('current_pending_sector', $level, $value);
    }

    /**
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @return int|null
     */
    public function getCpuUsageThreshold($level)
    {
        return $this->getThreshold('cpu_usage', $level);
    }

    /**
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @param int|null $value
     * @return self
     */
    public function setCpuUsageThreshold($level, $value)
    {
        return $this->setThreshold('cpu_usage', $level, $value);
    }

	 /**
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @return int|null
     */
    public function getMemoryUsageThreshold($level)
    {
        return $this->getThreshold('mem_usage', $level);
    }

    /**
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @param int|null $value
     * @return self
     */
    public function setMemoryUsageThreshold($level, $value)
    {
        return $this->setThreshold('mem_usage', $level, $value);
    }

    /**
     * @param int $level One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @return int|null
     */
    public function getUptimeThreshold($level)
    {
        return $this->getThreshold('uptime', $level);
    }

    /**
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @param int|null $value
     * @return self
     */
    public function setUptimeThreshold($level, $value)
    {
        return $this->setThreshold('uptime', $level, $value);
    }


    /**
     * @param string $type
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @return int|null
     */
    protected function getThreshold($type, $level)
    {
        $value = null;
        if (isset($this->thresholds[$type][$level])) {
            $value = $this->thresholds[$type][$level];
        }

        return $value;
    }

    /**
     * @param $type
     * @param int $level  One of self::STATUS_WARNING or self::STATUS_CRITICAL
     * @param int|null $value
     * @return self
     */
    public function setThreshold($type, $level, $value)
    {
        if (!isset($this->thresholds[$type]) || !is_array($this->thresholds[$type])) {
            $this->thresholds[$type] = array();
        }
        $this->thresholds[$type][$level] = $value;

        return $this;
    }

    /**
     * @return string
     */
    public function getCookieDir()
    {
        return $this->cookieDir;
    }

    /**
     * @param string $cookieDir
     */
    public function setCookieDir($cookieDir)
    {
        $this->cookieDir = $cookieDir;
    }

    /**
     * @param $code
     * @return null|string
     */
    public static function statusCodeToText($code)
    {
        $text = null;
        switch ($code) {
            case self::STATUS_OK:
                $text = 'OK';
                break;
            case self::STATUS_WARNING:
                $text = 'WARNING';
                break;
            case self::STATUS_CRITICAL:
                $text = 'CRITICAL';
                break;
            case self::STATUS_UNKNOWN:
            default:
                $text = 'UNKNOWN';
        }

        return $text;
    }

    /**
     * @param string $type  One of the self::TYPE_XXX constants
     * @return self
     * @throws ThecusException
     */
    protected function setCheckType($type)
    {
        $type = strtolower(trim($type));

        if (self::TYPE_HEALTH == $type) {
            $this->checkType = self::TYPE_HEALTH;
        } else if (self::TYPE_CPU == $type) {
            $this->checkType = self::TYPE_CPU;
        } else if (self::TYPE_DISKUSAGE == $type) {
            $this->checkType = self::TYPE_DISKUSAGE;
        } else if (self::TYPE_MEMORY == $type) {
            $this->checkType = self::TYPE_MEMORY;
        } else {
            throw new ThecusException('Invalid check type');
        }

        return $this;
    }

    /**
     * @return string
     */
    protected function getCheckType()
    {
        return $this->checkType;
    }

    /**
     * Returnes a string containing the result of the check in the Nagios Plugin format
     *
     * @return string
     */
    protected function getCheckOutput()
    {
        $output = self::statusCodeToText($this->getStatusCode());
        $output .= ' - ';
        if (!empty($this->statusText)) {
            $output .= implode(', ', $this->statusText);
        } else {
            $output .= 'System working fine';
        }

        if (!empty($this->perfData)) {
            $output .= ' | ';
            $output .= implode(' ', $this->perfData);
        }

        $output .= PHP_EOL;

        return $output;
    }

    /**
     * This does the actual check of the Thecus device.
     * It:
     *  - handles command-line options (and possibly reads a config file,)
     *  - performs the requested check,
     *  - prints the check result to stdout (according to the Nagios plugin API format)
     *  - and returns the status as integer (according to the Nagios plugin API)
     *
     * @return int
     */
    public function check()
    {
        try {
            $this->parseOpts();
            $this->checkOpts();

            $this->initCookie();

            if (self::TYPE_HEALTH == $this->getCheckType()) {
                $this->checkHealth();
            } else if (self::TYPE_CPU == $this->getCheckType()) {
                $this->checkCpuUsage();
            } else if (self::TYPE_DISKUSAGE == $this->getCheckType()) {
                $this->checkDiskUsage();
            } else if (self::TYPE_MEMORY == $this->getCheckType()) {
                $this->checkMemoryUsage();
            }
        } catch (ThecusException $e) {
            $this->setStatusInfo(self::STATUS_UNKNOWN, $e->getMessage());
        }

        echo $this->getCheckOutput();

        return $this->getStatusCode();
    }

    /**
     * Parses and applies the command line options
     *
     * @throws ThecusException
     */
    public function parseOpts()
    {
        if ($this->debug) {
            print("Entering parseOpts()" . PHP_EOL);
        }

        $baseFilename = basename(__FILE__);

        $shortOpts = 'hvdH:u:p:t:c:';
        $longOpts = array(
            'help',
            'verbose',
            'version',
            'debug',
            'config-file:',
            'hostname:',
            'username:',
            'password:',
            'type:',
            'cpu-warning:',
            'cpu-critical:',
            'mem-warning:',
            'mem-critical:',
            'disk-usage-warning:',
            'disk-usage-critical:',
            'disk-temp-warning:',
            'disk-temp-critical:',
            'ignore-bad-sectors:',
            'ignore-smart-status:',
        );

        $opts = getopt($shortOpts, $longOpts);

        if (isset($opts['h']) || isset($opts['help'])) {
            $this->printHelp();
            exit(0);
        }

        if (isset($opts['version'])) {
            echo $baseFilename . ' version ' . self::VERSION . PHP_EOL;
            echo 'Copyright (C) 2017 Maarten Hoogveld.' . PHP_EOL;
            echo 'License GPLv3+: GNU GPL version 3 or later <http://gnu.org/licenses/gpl.html>.' . PHP_EOL;
            echo 'This is free software: you are free to change and redistribute it.' . PHP_EOL;
            echo 'There is NO WARRANTY, to the extent permitted by law.' . PHP_EOL;
            echo PHP_EOL;
            echo 'Written by Maarten Hoogveld.' . PHP_EOL;
            exit(0);
        }

        if (isset($opts['d']) || isset($opts['debug'])) {
            $this->debug = true;
        }

        if (isset($opts['c'])) {
            $configFile = $opts['c'];
        } else if (isset($opts['config-file'])) {
            $configFile = $opts['config-file'];
        }

        if (isset($configFile)) {
            if (!file_exists($configFile) || !is_readable($configFile)) {
                throw new ThecusException("Can't read config file");
            }

            $configOpts = @parse_ini_file($configFile);

            if (false === $configOpts) {
                throw new ThecusException("Can't parse config file");
            }

            // Combine options defined in the config file with the command line options
            // Command line options overwrite config file options
            $opts = array_merge($configOpts, $opts);
        }

        if (isset($opts['H'])) {
            $this->setHostname($opts['H']);
        } else if (isset($opts['hostname'])) {
            $this->setHostname($opts['hostname']);
        }

        if (isset($opts['u'])) {
            $this->setUsername($opts['u']);
        } else if (isset($opts['username'])) {
            $this->setUsername($opts['username']);
        }

        if (isset($opts['p'])) {
            $this->setPassword($opts['p']);
        } else if (isset($opts['password'])) {
            $this->setPassword($opts['password']);
        }

        if (isset($opts['t'])) {
            $this->setCheckType($opts['t']);
        } else if (isset($opts['type'])) {
            $this->setCheckType($opts['type']);
        }

        if (isset($opts['cpu-warning'])) {
            $this->setCpuUsageThreshold(ThecusChecker::STATUS_WARNING, intval($opts['cpu-warning']));
        }
        if (isset($opts['cpu-critical'])) {
            $this->setCpuUsageThreshold(ThecusChecker::STATUS_CRITICAL, intval($opts['cpu-critical']));
        }

		if (isset($opts['mem-warning'])) {
            $this->setMemoryUsageThreshold(ThecusChecker::STATUS_WARNING, intval($opts['mem-warning']));
        }
        if (isset($opts['mem-critical'])) {
            $this->setMemoryUsageThreshold(ThecusChecker::STATUS_CRITICAL, intval($opts['mem-critical']));
        }

        if (isset($opts['disk-usage-warning'])) {
            $this->setDiskUsageThreshold(ThecusChecker::STATUS_WARNING, intval($opts['disk-usage-warning']));
        }
        if (isset($opts['disk-usage-critical'])) {
            $this->setDiskUsageThreshold(ThecusChecker::STATUS_CRITICAL, intval($opts['disk-usage-critical']));
        }

        if (isset($opts['disk-temp-warning'])) {
            $this->setDiskTempThreshold(ThecusChecker::STATUS_WARNING, intval($opts['disk-temp-warning']));
        }
        if (isset($opts['disk-temp-critical'])) {
            $this->setDiskTempThreshold(ThecusChecker::STATUS_CRITICAL, intval($opts['disk-temp-critical']));
        }
        if (isset($opts['ignore-bad-sectors'])) {
            $this->setIgnoreBadSectors($opts['ignore-bad-sectors']);
        }
        if (isset($opts['ignore-smart-status'])) {
            $this->setIgnoreSmartStatus($opts['ignore-smart-status']);
        }
    }

    /**
     * Checks if there are missing options which must be set before the check can start
     *
     * @throws ThecusException
     */
    protected function checkOpts()
    {
        if ($this->debug) {
            print("Entering checkOpts()" . PHP_EOL);
        }

        if (empty($this->hostname)) {
            throw new ThecusException('Hostname missing. Use --help for usage information.');
        } else if (empty($this->username)) {
            throw new ThecusException('Username missing. Use --help for usage information.');
        } else if (empty($this->password)) {
            throw new ThecusException('Password missing. Use --help for usage information.');
        } else if (empty($this->checkType)) {
            throw new ThecusException('Check type not specified. Use --help for usage information.');
        }
    }

    /**
     * Prints the usage information and command line options to stdout
     */
    protected function printHelp()
    {
        $baseFilename = basename(__FILE__);
        echo $baseFilename . ' v' . self::VERSION . PHP_EOL;
        echo PHP_EOL;
        echo 'Usage: php ' . $baseFilename . ' [OPTIONS]...' . PHP_EOL;
        echo PHP_EOL;
        echo 'Options:' . PHP_EOL;
        echo '   -c, --config-file          Name of file containing configuration parameters' . PHP_EOL;
        echo '   -H, --hostname             The hostname' . PHP_EOL;
        echo '   -u, --username             The username (usually admin)' . PHP_EOL;
        echo '   -p, --password             The password' . PHP_EOL;
        echo '   -t, --type                 The check type. One of:' . PHP_EOL;
        echo '                                health     - check system health (fans, temp, disks, raid)'. PHP_EOL;
        echo '                                cpu        - check cpu usage'. PHP_EOL;
        echo '                                memory 	 - check memory usage'. PHP_EOL;
        echo '                                disk-usage - check disk usage'. PHP_EOL;
        echo '       --cpu-warning          CPU usage warning level in % (default: 90)' . PHP_EOL;
        echo '       --cpu-critical         CPU usage critical level in % (default: 95)' . PHP_EOL;
        echo '       --mem-warning          RAM usage warning level in % (default: 90)' . PHP_EOL;
        echo '       --mem-critical         RAM usage critical level in % (default: 95)' . PHP_EOL;
        echo '       --disk-usage-warning   Disk usage warning level in % (default: 80)' . PHP_EOL;
        echo '       --disk-usage-critical  Disk usage critical level % (default: 90)' . PHP_EOL;
        echo '       --disk-temp-warning    Disk temperature warning level in °C (default: 50)' . PHP_EOL;
        echo '       --disk-temp-critical   Disk temperature critical level in °C (default: 60)' . PHP_EOL;
        echo '       --ignore-bad-sectors   Ignore bad sectors until the given amount (default: 0)' . PHP_EOL;
        echo '       --ignore-smart-status  One might want to ignore smart status (default: false)' . PHP_EOL;
        echo '   -h, --help                 Display this help and exit' . PHP_EOL;
        echo '   -v, --verbose              Display extra information, useful for debugging' . PHP_EOL;
        echo '       --version              Display version information and exit' . PHP_EOL;
        echo PHP_EOL;
        echo 'Usage example:' . PHP_EOL;
        echo '  php ' . $baseFilename . ' --hostname=thecus.example.com --username=admin --password=password' . PHP_EOL;
        echo '  php ' . $baseFilename . ' -H thecus.example.com -u admin -p password --disk-temp-warning 60 --disk-temp-warning 70' . PHP_EOL;
        echo PHP_EOL;
    }

    /**
     * Make sure the cookie has the right file permissions, so noone can easily steal the (admin) session
     */
    protected function initCookie()
    {
        $cookieFile = $this->getCookieFilename();
        touch($cookieFile);
        chmod($cookieFile, 0600);
    }

    /**
     * Checks the health of the entire system which includes fans, RAID and the physical disks
     */
    protected function checkHealth()
    {
        if ($this->debug) {
            print("Entering checkHealth()" . PHP_EOL);
        }

        $this->checkSystemHealth();
        $this->checkRaidHealth();
        $this->checkDiskHealth();

        $statusCode = $this->getStatusCode();
        $statusText = $this->getStatusText();
        if (self::STATUS_OK == $statusCode && empty($statusText)) {
            $this->addStatusInfo($statusCode, 'System healthy');
        }
    }

    /**
     * Check the health of the hardware, which includes the CPU fan and system fans
     */
    protected function checkSystemHealth()
    {
        if ($this->debug) {
            print("Entering checkSystemHealth()" . PHP_EOL);
        }

        $this->addStatusInfo(self::STATUS_OK);

        $sysHealth = $this->getNasStatus();

        if (isset($sysHealth->fan) && ('on' != $sysHealth->fan && 'none' != $sysHealth->fan)) {
            $this->addStatusInfo(self::STATUS_CRITICAL, 'System fan not OK');
        }
        if (isset($sysHealth->raid) && 'on' != $sysHealth->raid) {
            $this->addStatusInfo(self::STATUS_CRITICAL, 'Raid not OK');
        }
        if (isset($sysHealth->disk) && 'on' != $sysHealth->disk) {
            $this->addStatusInfo(self::STATUS_CRITICAL, 'Disk not OK');
        }

        $sysInfo = $this->getSysStatus();

        if (isset($sysInfo)) {
            // Thecus N2520 does not provide system info
            if (isset($sysInfo->cpu_fan) && 'OK' != $sysInfo->cpu_fan) {
                $this->addStatusInfo(self::STATUS_CRITICAL, 'CPU fan not OK');
            }

            if (isset($sysInfo->sys_fan_speed) && 'OK' != $sysInfo->sys_fan_speed) {
                $this->addStatusInfo(self::STATUS_CRITICAL, 'System fan 1 not OK');
            }

            if (isset($sysInfo->sys_fan_speed1) && 'OK' != $sysInfo->sys_fan_speed1) {
                $this->addStatusInfo(self::STATUS_CRITICAL, 'System fan 1 not OK');
            }

            $fanNr = 2;
            $fanName = 'sys_fan_speed' . $fanNr;
            while (isset($sysInfo->$fanName)) {
                if ('OK' != $sysInfo->$fanName) {
                    $this->addStatusInfo(self::STATUS_CRITICAL, 'System fan ' . $fanNr . ' not OK');
                }

              // Set next fan name
              $fanNr += 1;
              $fanName = 'sys_fan_speed' . $fanNr;
            }
        }

        // If status is still ok, add a text message
        if (self::STATUS_OK == $this->getStatusCode()) {
            $this->addStatusInfo(self::STATUS_OK, 'Hardware working fine');
        }

        // Add various performance data if available on this model
        if (isset($sysInfo->series) && is_array($sysInfo->series)) {
            if (isset($sysInfo->serie->HDD_FAN1)) {
                $hddFanRpm = $sysInfo->serie->HDD_FAN1;
                $this->addStatusInfo(self::STATUS_OK, "HDD fan: " . $hddFanRpm . "rpm", "hddFanRpm=" . $hddFanRpm);
            }
            if (isset($sysInfo->serie->CPU_TEMP)) {
                $cpuTemp = $sysInfo->serie->CPU_TEMP;
                $this->addStatusInfo(self::STATUS_OK, "CPU temp: " . $cpuTemp . "°C", "CPU_temp=" . $cpuTemp);
            }
            if (isset($sysInfo->serie->SAS_TEMP)) {
                $sasTemp = $sysInfo->serie->SAS_TEMP;
                $this->addStatusInfo(self::STATUS_OK, "SAS temp: " . $sasTemp . "°C", "SAS_temp=" . $sasTemp);
            }
            if (isset($sysInfo->serie->SYS_TEMP)) {
                $sysTemp = $sysInfo->serie->SYS_TEMP;
                $this->addStatusInfo(self::STATUS_OK, "Sys temp: " . $sysTemp . "°C", "Sys_temp=" . $sysTemp);
            }
        }
    }

    /**
     * Checks the status of each defined RAID and also checks the access status of the RAID.
     * Not sure what the difference is, so please let the author know if you do.
     */
    protected function checkRaidHealth()
    {
        if ($this->debug) {
            print("Entering checkRaidHealth()" . PHP_EOL);
        }

        $statusTexts = array();

        // Check RAID access status
        $raidAccessStatus = $this->getRaidAccessStatus();
        if (isset($raidAccessStatus->status)) {
          // N5200XXXX does not return RAID access status
          if ('Damaged' == $raidAccessStatus->status) {
              $statusCode = self::STATUS_CRITICAL;
          } else if ('Degraded' == $raidAccessStatus->status) {
              $statusCode = self::STATUS_WARNING;
          } else if ('Healthy' == $raidAccessStatus->status) {
              $statusCode = self::STATUS_OK;
          } else {
              $statusCode = self::STATUS_UNKNOWN;
          }

          if (self::STATUS_OK != $statusCode) {
              $statusTexts[] = 'access status: ' . $raidAccessStatus->status;
          }
        }

        // Check each RAID status (may be the same as above)

        $raidList = $this->getRaidList();
        foreach ($raidList->raid_list as $raid) {
            if ('Damaged' == $raid->raid_status) {
                $statusCode = self::STATUS_CRITICAL;
            } else if ('Degraded' == $raid->raid_status) {
                $statusCode = self::STATUS_WARNING;
            } else if ('Healthy' == $raid->raid_status) {
                $statusCode = self::STATUS_OK;
            } else {
                $statusCode = self::STATUS_UNKNOWN;
            }

            if (self::STATUS_OK != $statusCode) {
                $statusTexts[] = $raid->raid_id . ' status: ' . $raid->raid_status;
            }
        }

        if (self::STATUS_OK == $statusCode) {
            $statusTexts[] = 'Healthy';
        }

        if (empty($statusTexts)) {
            $statusText = null;
        } else {
            $statusText = 'RAID ' . implode(', ', $statusTexts);
        }
        $this->addStatusInfo($statusCode, $statusText);
    }

    /**
     * Checks the status of the physical disks, including bad sectors and disk temperature
     */
    protected function checkDiskHealth()
    {
        if ($this->debug) {
            print("Entering checkDiskHealth()" . PHP_EOL);
        }

        $diskInfo = $this->getDiskInfo();

        $diskData = $diskInfo->disk_data;
        // Fill the disklist array with all disks found in a one or two dimensional array
        $diskList = array();
        foreach ($diskInfo->disk_data as $disk) {
            if (isset($disk->disks)) {
                foreach ($disk->disks as $d) {
                    $diskList[$d->tray_no][$d->disk_no] = $d;
                }
            } else if (!isset($diskList[$disk->trayno])) {
                foreach ($diskInfo->disk_data as $disk) {
                    if (!isset($diskList[$disk->trayno])) {
                        $diskList[$disk->trayno] = array();
                    }
                    $diskList[$disk->trayno][$disk->diskno] = $disk;
                }
            } else {
                $diskList[$disk->trayno][$disk->diskno] = $disk;
            }
        }

        // Check each disk in the disk list array
        foreach ($diskList as $trayNr => $trayData) {
            foreach ($trayData as $diskNr => $disk) {
                // Find the disk status
                if (isset($disk->s_status)) {
                    $diskStatus = $disk->s_status;
                } else {
                    if (isset($disk->status->state)) {
                        $diskStatus = $disk->status->state;
                    } else {
                        $diskStatus = 'Unknown';
                    }
                }

                if ('N/A' === $diskStatus) {
                    continue;
                } else {
                    if (('Critical' === $diskStatus) || ('2' === $diskStatus) || (2 === $diskStatus)) {
                        $statusText = 'Status of disk nr ' . $diskNr . ' in tray ' . $disk->tray_no . ': ' . $disk->s_status;
                        $this->addStatusInfo(self::STATUS_CRITICAL, $statusText);
                    } else {
                        if (('Warning' === $diskStatus) || ('1' === $diskStatus) || (1 === $diskStatus)) {
                            $smartInfo = $this->checkSmartInfo($trayNr, $diskNr);
                            $this->addStatusInfo(
                                max(self::STATUS_WARNING, $smartInfo['statusCode']),
                                $smartInfo['statusText'],
                                $smartInfo['perfData']
                            );
                        } else if (('OK' === $diskStatus) || ('Detected' === $diskStatus) || ('0' === $diskStatus) || (0 === $diskStatus)) {
                            // The cases 'OK', 'Detected', '0' (and possibly more?)
                            $smartInfo = $this->checkSmartInfo($trayNr, $diskNr);
                            $this->addStatusInfo($smartInfo['statusCode'], $smartInfo['statusText'], $smartInfo['perfData']);
                        }
                    }
                }
            }
        }
    }

    /**
     * Checks the CPU usage
     */
    protected function checkCpuUsage()
    {
        if ($this->debug) {
            print("Entering checkCpuUsage()" . PHP_EOL);
        }

        $crit = $this->getCpuUsageThreshold(self::STATUS_CRITICAL);
        $warn = $this->getCpuUsageThreshold(self::STATUS_WARNING);

        $sysInfo = $this->getSysStatus();
        if (isset($sysInfo)) {
          // Thecus N2520 does not provide system info
            if (isset($sysInfo->cpu_loading)) {
                $cpuUsage = intval($sysInfo->cpu_loading);
            } else if (isset($sysInfo->series->CPU)) {
                $cpuUsage = $sysInfo->series->CPU;
            } else {
                $this->addStatusInfo(self::STATUS_UNKNOWN, 'Cpu usage unknown');
                return;
            }

            if ((null !== $crit) && ($cpuUsage >= $crit)) {
                $statusCode = self::STATUS_CRITICAL;
            } else if (null !== $warn && $cpuUsage >= $warn) {
                $statusCode = self::STATUS_WARNING;
            } else {
                $statusCode = self::STATUS_OK;
            }
        }

        $statusText = 'CPU usage: ' . $cpuUsage . '%';
        $perfData = 'CPU=' . $cpuUsage . ';' . $warn . ';' . $crit . ';0;100';

        $this->addStatusInfo($statusCode, $statusText, $perfData);
    }

    /**
     * Checks the CPU usage
     */
    protected function new_checkCpuUsage()
    {
        $crit = $this->getCpuUsageThreshold(self::STATUS_CRITICAL);
        $warn = $this->getCpuUsageThreshold(self::STATUS_WARNING);

        $sysInfo = $this->getSysStatus();
        $sysArray = $sysInfo->series;
        $cpuUsage = $sysArray->CPU;

        if (null !== $crit && $cpuUsage >= $crit) {
            $statusCode = self::STATUS_CRITICAL;
        } else if ((null !== $warn) && ($cpuUsage >= $warn)) {
            $statusCode = self::STATUS_WARNING;
        } else {
            $statusCode = self::STATUS_OK;
        }

        $statusText = 'CPU usage: ' . $cpuUsage . '%';
        $perfData = 'CPU=' . $cpuUsage . ';' . $warn . ';' . $crit . ';0;100';

        $this->addStatusInfo($statusCode, $statusText, $perfData);
    }

	protected function checkMemoryUsage()
    {
        if ($this->debug) {
            print("Entering checkMemoryUsage()" . PHP_EOL);
        }

        $crit = $this->getMemoryUsageThreshold(self::STATUS_CRITICAL);
        $warn = $this->getMemoryUsageThreshold(self::STATUS_WARNING);

        $sysInfo = $this->getSysStatus();
        if (isset($sysInfo->series->Memory)) {
            $memUsage = $sysInfo->series->Memory;
        } else {
            $this->addStatusInfo(self::STATUS_UNKNOWN, 'Memory usage unknown');
            return;
        }

        if ((null !== $crit) && ($memUsage >= $crit)) {
            $statusCode = self::STATUS_CRITICAL;
        } else if ((null !== $warn) && ($memUsage >= $warn)) {
            $statusCode = self::STATUS_WARNING;
        } else {
            $statusCode = self::STATUS_OK;
        }

        $statusText = 'Memory usage: ' . $memUsage . '%';
        $perfData = 'mem_usage=' . $memUsage . '%;' . $warn . ';' . $crit . ';0;100';

        $this->addStatusInfo($statusCode, $statusText, $perfData);
    }

    /**
     * Checks the amount of free diskspace for each defined RAID
     */
    protected function checkDiskUsage()
    {
        if ($this->debug) {
            print("Entering checkDiskUsage()" . PHP_EOL);
        }

        $usageRegExp = '/([0-9.]+) ?([KMGTP]B)[^0-9.]+([0-9.]+) ?([KMGTP]B)/';

        $statusCode = self::STATUS_OK;
        $statusTexts = array();
        $perfDataList = array();

        $crit = $this->getDiskUsageThreshold(self::STATUS_CRITICAL);
        $warn = $this->getDiskUsageThreshold(self::STATUS_WARNING);

        $raidList = $this->getRaidList();

        foreach ($raidList->raid_list as $raid) {
            // Parse disk usage info
            $found = preg_match($usageRegExp, $raid->data_capacity, $dataCapMatches);

            if ($found) {
                if ($dataCapMatches[2] == $dataCapMatches[4]) {
                    $spaceUsed = floatval($dataCapMatches[1]);
                    $spaceAvailable = floatval($dataCapMatches[3]);
                    $pctUsed = round($spaceUsed / $spaceAvailable * 100, 2);
                    if (null !== $crit && $pctUsed >= $crit) {
                        $statusCode = max(self::STATUS_CRITICAL, $statusCode);
                    } else if (null !== $warn && $pctUsed >= $warn) {
                        $statusCode = max(self::STATUS_WARNING, $statusCode);
                    }

                    $perfDataList[] = $raid->raid_id . '_usage=' . $pctUsed . ';' . $warn . ';' . $crit . ';0;100';
                } else {
                    // Space used and space available are given in different units
                    // Need to implement this (if it ever happens)
                    $this->addStatusInfo(self::STATUS_UNKNOWN, "Can't parse disk usage data. Different units used.");
                    return;
                }

                $statusTexts[] = $raid->raid_id . ' ' . $pctUsed . '% (' . $raid->data_capacity . ')';
            } else {
                $this->addStatusInfo(self::STATUS_UNKNOWN, "Can't parse disk usage data.");
                return;
            }
        }

        if (empty($statusTexts)) {
            $statusText = null;
        } else {
            $statusText = 'Disk usage: ' . implode(', ', $statusTexts);
        }

        if (empty($perfDataList)) {
            $perfData = null;
        } else {
            $perfData = implode(' ', $perfDataList);
        }

        $this->addStatusInfo($statusCode, $statusText, $perfData);
    }

    /**
     * Checks the uptime of the device
     *
     * @todo Implement this
     *
     * @throws ThecusException
     */
    protected function checkUptime()
    {
        if ($this->debug) {
            print("Entering checkUptime()" . PHP_EOL);
        }

        throw new ThecusException('Needs to be implemented');

        $statusCode = self::STATUS_OK;

        $crit = $this->getUptimeThreshold(self::STATUS_CRITICAL);
        $warn = $this->getUptimeThreshold(self::STATUS_WARNING);

        $sysInfo = $this->getSysStatus();

        $uptime = $sysInfo->uptime;
        // Or
        $uptime = $sysInfo->up_time;

        // TODO Parse uptime
        $parsedUptime = $uptime;

        if (null !== $crit && $parsedUptime <= $crit) {
            $statusCode = max(self::STATUS_CRITICAL, $statusCode);
        } else if (null !== $warn && $parsedUptime <= $warn) {
            $statusCode = max(self::STATUS_WARNING, $statusCode);
        }

        $statusText = $parsedUptime;

        $this->addStatusInfo($statusCode, $statusText);
    }

    /**
     * Checks various SMART attibutes
     *
     * @param string $diskNr  The disk number as returned by $this->getStatusText()
     * @param string $trayNr  The tray number as returned by $this->getStatusText()
     * @return array Returns status info in an associative array with keys
     *               'statusCode', 'statusText' and 'perfData' which can be fed
     *               to $this->addStatusInfo()
     */
    protected function checkSmartInfo($diskNr, $trayNr)
    {
        if ($this->debug) {
            print('Entering checkSmartInfo(' . $diskNr . ', ' . $trayNr . ')' . PHP_EOL);
        }

        $statusCode = self::STATUS_OK;
        $statusTexts = array();
        $perfData = array();

        $smartInfo = $this->getSmartInfo($diskNr, $trayNr);
        if (0 !== $smartInfo->smart_status && !$this->getIgnoreSmartStatus()) {
            $statusCode = self::STATUS_CRITICAL;
            $statusTexts[] = 'Status not OK';
        }

        if (isset($smartInfo->ATTR5)) {
            $reallocSectorCount = intval($smartInfo->ATTR5);
            if ($reallocSectorCount > $this->getIgnoreBadSectors()) {
                $crit = $this->getReallocSectThreshold(self::STATUS_CRITICAL);
                $warn = $this->getReallocSectThreshold(self::STATUS_WARNING);
                if (null !== $crit && $reallocSectorCount >= $crit) {
                    $statusCode = self::STATUS_CRITICAL;
                    $statusTexts[] = 'Bad sector count: ' . $reallocSectorCount;
                } else if (null !== $warn && $reallocSectorCount >= $warn) {
                    $statusCode = max(self::STATUS_WARNING, $statusCode);
                    $statusTexts[] = 'Bad sector count: ' . $reallocSectorCount;
                }
            }
        }

        if (isset($smartInfo->ATTR194)) {
            $diskTemp = intval($smartInfo->ATTR194);
            $crit = $this->getDiskTempThreshold(self::STATUS_CRITICAL);
            $warn = $this->getDiskTempThreshold(self::STATUS_WARNING);
            if (null !== $crit && $diskTemp >= $crit) {
                $statusCode = self::STATUS_CRITICAL;
                $statusTexts[] = 'Temp: ' . $diskTemp . '°C';
            } else if (null !== $warn && $diskTemp >= $warn) {
                $statusCode = max(self::STATUS_WARNING, $statusCode);
                $statusTexts[] = 'Temp: ' . $diskTemp . '°C';
            }
            $perfData[] = 'Disk_' . $trayNr . '_' . $diskNr . '_temp=' . $diskTemp . ';' . $warn . ';' . $crit;
        }

        if (isset($smartInfo->ATTR197)) {
            $curPendingSectorCount = intval($smartInfo->ATTR197);
            $crit = $this->getCurPendingSectThreshold(self::STATUS_CRITICAL);
            $warn = $this->getCurPendingSectThreshold(self::STATUS_WARNING);
            if (null !== $crit && $curPendingSectorCount >= $crit) {
                $statusCode = self::STATUS_CRITICAL;
                $statusTexts[] = 'Unstable sector count: ' . $curPendingSectorCount;
            } else if (null !== $warn && $curPendingSectorCount >= $warn) {
                $statusCode = max(self::STATUS_WARNING, $statusCode);
                $statusTexts[] = 'Unstable sector count: ' . $curPendingSectorCount;
            }
        }

        if (empty($statusTexts)) {
            $statusText = null;
        } else {
            $statusText = 'Disk in tray ' . $trayNr . ' with nr ' . $diskNr . ' (' . $smartInfo->model . ') ' . implode(', ', $statusTexts);
        }

        if (empty($perfData)) {
            $totalPerfData = null;
        } else {
            $totalPerfData = implode(';', $perfData);
        }

        $statusInfo = array(
            'statusCode' => $statusCode,
            'statusText' => $statusText,
            'perfData' => $totalPerfData,
        );

        return $statusInfo;
    }

    /**
     * @return Object Information as returned in json by the Thecus device
     * @throws Exception
     * @throws ThecusAuthorizationException
     * @throws ThecusException
     */
    protected function getRaidList()
    {
        if ($this->debug) {
            print("Entering getRaidList()" . PHP_EOL);
        }

        $uri = '/adm/getmain.php?fun=raid&action=getraidlist';
        $response = $this->jsonRequest($uri);
        return $response;
    }

    /**
     * @return Object Information as returned in json by the Thecus device
     * @throws Exception
     * @throws ThecusAuthorizationException
     * @throws ThecusException
     */
    protected function getRaidAccessStatus()
    {
        if ($this->debug) {
            print("Entering getRaidAccessStatus()" . PHP_EOL);
        }

        $uri = '/adm/getmain.php?fun=raid&action=getAccessStatus';
        $response = $this->jsonRequest($uri);
        return $response;
    }

    /**
     * @return Object Information as returned in json by the Thecus device
     * @throws Exception
     * @throws ThecusAuthorizationException
     * @throws ThecusException
     */
    protected function getDiskInfo()
    {
        if ($this->debug) {
            print("Entering getDiskInfo()" . PHP_EOL);
        }

        $uri = '/adm/getmain.php?fun=disks&update=1';
        $response = $this->jsonRequest($uri);
        return $response;
    }

    /**
     * @param string $diskNo
     * @param string $trayNo
     * @return Object Information as returned in json by the Thecus device
     * @throws Exception
     * @throws ThecusAuthorizationException
     * @throws ThecusException
     */
    protected function getSmartInfo($diskNo, $trayNo)
    {
        if ($this->debug) {
            print("Entering getSmartInfo()" . PHP_EOL);
        }

        $uriList = array();

        $uri  = '/adm/getmain.php?fun=smart';
        $uri .= '&disk_no=' . $diskNo;
        $uri .= '&tray_no=' . $trayNo;
        $uriList[] = $uri;

        $uri  = '/adm/getmain.php?fun=smart';
        if ($diskNo === "no_disk_no") {
            // N2520 and N8800 need a translation of their disk no to char
            $letter = chr($trayNo + 96);
            $uri .= '&diskno=' . $letter;
        } else {
            $uri .= '&diskno=' . $diskNo;
        }
        $uri .= '&trayno=' . $trayNo;
        $uriList[] = $uri;

        $responses = $this->jsonTryMultipleRequests($uriList, null, true, true);
        // Sadly, both uri's could return json, but possibly without valid data
        // Find a valid response
        foreach ($responses as $response) {
            if (isset($response->model) && ($response->model != 'N/A')) {
                return $response;
            }
        }

        // No valid response received
        throw new ThecusException("No valid smart info response received.");
    }

    /**
     * @return Object Information as returned in json by the Thecus device
     * @throws Exception
     * @throws ThecusAuthorizationException
     * @throws ThecusException
     */
    protected function getSysStatus()
    {
        if ($this->debug) {
            print("Entering getSysStatus()" . PHP_EOL);
        }

        $uriList = array();
        $uriList[] = '/adm/getmain.php?fun=systatus&update=1';
        $uriList[] = '/adm/getmain.php?fun=monitor&action=update';

        $response = $this->jsonTryMultipleRequests($uriList);

        return $response;
    }

    /**
     * @return Object Information as returned in json by the Thecus device
     * @throws Exception
     * @throws ThecusAuthorizationException
     * @throws ThecusException
     */
    protected function getNasStatus()
    {
        if ($this->debug) {
            print("Entering getNasStatus()" . PHP_EOL);
        }

        $uri = '/adm/getmain.php?fun=nasstatus';
        $response = $this->jsonRequest($uri);
        return $response;
    }

    /**
     * @return Object Information as returned in json by the Thecus device
     * @throws Exception
     * @throws ThecusAuthenticationException
     * @throws ThecusAuthorizationException
     * @throws ThecusException
     */
    protected function login()
    {
        if ($this->debug) {
            print("Entering login()" . PHP_EOL);
        }

        $postInfo  = 'username=' . urlencode($this->getUsername());
        $postInfo .= '&pwd=' . urlencode($this->getPassword());
        $postInfo .= '&p_user=' . urlencode($this->getUsername());
        $postInfo .= '&p_pass=' . urlencode($this->getPassword());
        $postInfo .= '&action=login';
        $postInfo .= '&option=com_extplorer';
        $postInfo .= '&eplang=english';

        $response = $this->jsonRequest('/adm/login.php', $postInfo, false);
        if ('true' != $response->success) {
            $error = $response->errormsg->msg;
            throw new ThecusAuthenticationException($error);
        }

        return $response;
    }

    /**
     * @return Object Information as returned in json by the Thecus device
     * @throws Exception
     * @throws ThecusAuthorizationException
     * @throws ThecusException
     */
    protected function logout()
    {
        if ($this->debug) {
            print("Entering logout()" . PHP_EOL);
        }

        $uri = '/adm/logout.html';
        $response = $this->jsonRequest($uri);
        return $response;
    }

    /**
     * @param array $uriList  An array of uri's which are tried. The first valid response received is returned
     * @param string|null $post  POST data as string if this is a POST-request, null for a GET-request.
     * @param bool $autoLogin  If set to true and the initial request was deemed unauthorized by the Thecus device,
     *                         a login attempt will be made after which the request it sent again.
     * @param bool $returnAllResponses  If set to true, all (valid) responses will be returned in an array
     * @return Object|array  The parsed json data as object or array of objects
     * @throws ThecusException
     */
    protected function jsonTryMultipleRequests($uriList, $post = null, $autoLogin = true, $returnAllResponses = false)
    {
        if ($this->debug) {
            print("Entering jsonTryMultipleRequests()" . PHP_EOL);
        }

        if (!is_array($uriList)) {
            return $this->jsonRequest($uriList, $post, $autoLogin);
        }

        $responses = array();

        foreach ($uriList as $uri) {
            try {
                $response = $this->jsonRequest($uri, $post, $autoLogin);
                if ($returnAllResponses) {
                    $responses[] = $response;
                } else {
                    return $response;
                }
            } catch (ThecusClientErrorException $e) {
                // Try next uri in case of eg 404
                continue;
            } catch (ThecusServerErrorException $e) {
                // Try next uri in case of eg 500
                continue;
            } catch (ThecusJsonDecodeException $e) {
                // Try next uri in case of empty response of json decode error
                continue;
            } catch (ThecusException $e) {
                throw $e;
            }
        }

        if (empty($response)) {
            // None of the uri's returned a valid response
            throw new ThecusException("Request not supported. No valid response received.");
        }

        return $responses;
    }

    /**
     * @param string $uri  The URI to call on the host (eg "/adm/login.php")
     * @param string|null $post  POST data as string if this is a POST-request, null for a GET-request.
     * @param bool $autoLogin  If set to true and the initial request was deemed unauthorized by the Thecus device,
     *                         a login attempt will be made after which the request it sent again.
     * @return Object  The parsed json data
     * @throws Exception
     * @throws ThecusAuthenticationException
     * @throws ThecusAuthorizationException
     * @throws ThecusException
     */
    protected function jsonRequest($uri, $post = null, $autoLogin = true)
    {
        if ($this->debug) {
            print("Entering jsonRequest()" . PHP_EOL);
        }

        $url = 'http://' . $this->getHostname() . $uri;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_COOKIESESSION, false);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->getCookieFilename());
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->getCookieFilename());
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1000);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if (null !== $post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }

        if ($this->debug) {
            print("Request uri: " . $uri . PHP_EOL);
        }

        try {
            $responseBody = curl_exec($ch);
            $this->checkError($ch, $responseBody);
        } catch (ThecusAuthorizationException $e) {
            if ($autoLogin) {
                if ($this->debug) {
                    print("Unautorized. Retrying after logging in." . PHP_EOL);
                }
                $this->login();
                return $this->jsonRequest($uri, $post, false);
            } else {
                if ($this->debug) {
                    print("Unautorized." . PHP_EOL);
                }
                curl_close($ch);
                throw $e;
            }
        } catch (ThecusException $e) {
            if ($this->debug) {
                print("Exception fetching data: " . $e->getMessage() . PHP_EOL);
            }
            curl_close($ch);
            throw $e;
        }
        curl_close($ch);

        $jsonObj = json_decode($responseBody);
        if (null == $jsonObj) {
            if ($this->debug) {
                print("No data returned" . PHP_EOL);
            }
            throw new ThecusJsonDecodeException("Request not supported. No valid response received.");
        }

        // Print request and response for debugging
        if ($this->debug) {
            print("Response:" . PHP_EOL);
            print_r(json_encode($jsonObj, JSON_PRETTY_PRINT));
            print(PHP_EOL . PHP_EOL);
        }

        return $jsonObj;
    }

    /**
     * Checks in various ways to see if the request returned an error
     *
     * @param resource $ch  The curl resource
     * @param string $responseBody  The raw response body
     * @throws ThecusAuthenticationException
     * @throws ThecusAuthorizationException
     * @throws ThecusException
     */
    protected function checkError($ch, $responseBody)
    {
        if ($this->debug) {
            print("Entering checkError()" . PHP_EOL);
        }

        if (curl_error($ch)) {
            throw new ThecusException(curl_error($ch));
        }

        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ("4" == substr($responseCode, 0, 1)) {
            // HTTP response in the 400 range (eg 404 - Not Found)
            throw new ThecusClientErrorException("Request not supported (http-response: " . $responseCode . ").");
        } else if ("5" == substr($responseCode, 0, 1)) {
            // HTTP response in the 500 range (eg 500 - Internal Server Error)
            throw new ThecusServerErrorException("Server error during request (http-response: " . $responseCode . ").");
        }

        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        // Thecus N5500
        $logoutRedirectIndicator = '/adm/logout.php';
        if (false !== strpos($responseBody, $logoutRedirectIndicator)) {
            throw new ThecusAuthorizationException("Not authorized");
        }

        // Older Thecus devices?
        $unAuthUri = '/unauth.htm';
        if ($unAuthUri == substr($effectiveUrl, -strlen($unAuthUri))) {
            throw new ThecusAuthenticationException("Thecus login failed");
        }

        // Older Thecus devices?
        $inUseUri = '/adm/inuse.htm';
        if ($inUseUri == substr($effectiveUrl, -strlen($inUseUri))) {
            throw new ThecusException("Admin has already logged in from another host");
        }
    }

    public static function __callStatic($name, $arguments)
    {
        // TODO: Implement __callStatic() method.
    }

    /**
     * Returns the name of the cookie file to use specific for the currently set host- and username
     *
     * @return string
     */
    protected function getCookieFilename()
    {
        $cookieFile = $this->getCookieDir() . '/check_thecus_nas-' . $this->hostname . '-' . $this->username . '-cookie.txt';

        return $cookieFile;
    }
}

class ThecusException extends Exception
{
}

class ThecusClientErrorException extends ThecusException
{
}

class ThecusServerErrorException extends ThecusException
{
}

class ThecusJsonDecodeException extends ThecusException
{
}

class ThecusAuthenticationException extends ThecusException
{
}

class ThecusAuthorizationException extends ThecusException
{
}


