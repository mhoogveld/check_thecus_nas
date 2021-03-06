# check_thecus_nas
Monitoring plugin for Thecus NAS devices

## Overview
This plugin can check the health and status of a Thecus NAS device by querying the Web UI (User Interface) of
the NAS. It has been developed and tested 
against 
- Thecus N5500           (firmware V5.00.04)
- Thecus N5200XXX        (firmware V5.03.02)
- Thecus N2520 running   (firmware OS6.build_341)
- Thecus N8800PROv2      (firmware V2.05.08.v2)
- NASBOX5G2
- NAS models with similar Web UI's

It can check and report CPU usage, system- and CPU-fans, RAID status, available disk space, disk health (bad-sectors) 
and disk temperature.

The Thecus NAS device has (or at least some have) the option to enable and read out SNMP, but this option gives far 
less information than what can be seen in (and retrieved from) the management user-interface.
This check gets its information from this management user-interface through a series of http-requests
which return information in json-format. 

Basing a check on a user-interface is generally not a good idea because user-interfaces are not
guaranteed to be the same between different Thecus models and even firmware versions of the same
model. This is however the only way to extract extra information like raid-status, drive temperatures
and failures.

## Changelog
* **v1.0 - 2015-07-28:**
  Initial version
* **v2.0 - 2018-05-16:**
  Added support for various new Thecus NAS models with support by Daniel Rauer and Chris
  Added memory check

## Installation
Requirements:
* PHP version 5.3 or 7.x (earlier versions are untested)
* php5_curl or php_curl
    For Debian based systems (e.g. Ubuntu): `sudo apt-get install php-curl`

Place the check script anywhere you'd like (eg /usr/local/lib/nagios/plugins) and run it

## Usage
For a complete overview run the check with the parameter "--help".
For security reasons, it's a good idea to store the password in a config file instead of specifying 
the password as a parameter on the command line. Every user, that can log on to the machine, can see 
all running processes with arguments (which would include the password). By using a config file, you
will not suffer from this issue.

General use cases:
Get full usage information  
```
./check_thecus_nas.php --help
```

Check the health of a device using a config file 
```
./check_thecus_nas.php --hostname nas01.example.com --config-file nas01.conf \
    --type health
```

Check the cpu usage of a device specifying the username/password on the commandline (insecure, read above)
```
./check_thecus_nas.php -h nas01.example.com -u admin -p password -t cpu \
    --cpu-warning 80 --cpu-critical 90
```

Check the available disk space of a device
```
./check_thecus_nas.php -h nas01.example.com -c nas01.conf -t disk-usage \
    --disk-usage-warning 80 --disk-usage-critical 90
```

## Nagios configuration examples
Command definition examples:
```
define command {
    command_name                    check_thecus_nas_health
    command_line                    $USER2$/check_thecus_nas.php --hostname "$HOSTADDRESS$" --config-file "$ARG1$" --type health
    ;command_example                !/path/to/thecus_nas.conf
    ;$ARG1$                         config file location
}

define command {
    command_name                    check_thecus_nas_cpu
    command_line                    $USER2$/check_thecus_nas.php --hostname "$HOSTADDRESS$" --config-file "$ARG1$" --type cpu
    ;command_example                !/path/to/thecus_nas.conf
    ;$ARG1$                         confing file location
}

define command {
    command_name                    check_thecus_nas_diskusage
    command_line                    $USER2$/check_thecus_nas.php --hostname "$HOSTADDRESS$" --config-file "$ARG1$" --type disk-usage
    ;command_example                !/path/to/thecus_nas.conf
    ;$ARG1$                         confing file location
}
```

Service template examples:
```
define service {
    name                            Thecus-nas-health
    service_description             Thecus NAS - Health
    use                             generic-service
    check_command                   check_thecus_nas_health!$ARG1$
    ;ARG1                           Config file
    check_interval                  30
    retry_check_interval            5
    register                        0
}

define service {
    name                            Thecus-nas-cpu
    service_description             Thecus NAS - CPU
    use                             Thecus-nas-check-generic
    check_command                   check_thecus_nas_cpu!$ARG1$
    ;ARG1                           Config file
    check_interval                  5
    retry_check_interval            1
    register                        0
}

define service {
    name                            Thecus-nas-diskusage
    service_description             Thecus NAS - Disk usage
    use                             Thecus-nas-check-generic
    check_command                   check_thecus_nas_diskusage!$ARG1$
    ;ARG1                           Config file
    check_interval                  30
    retry_check_interval            5
    register                        0
}
```

Service definition examples:
```
define service {
    host_name                       nas02.example.com
    use                             Thecus-nas-health
    check_command                   check_thecus_nas_health!/path/to/nas02.conf
}

define service {
    host_name                       nas02.example.com
    use                             Thecus-nas-cpu
    check_command                   check_thecus_nas_cpu!/path/to/nas02.conf
}

define service {
    host_name                       nas02.example.com
    use                             Thecus-nas-diskusage
    check_command                   check_thecus_nas_diskusage!/path/to/nas02.conf
}
```


## Support for other Thecus NAS models

If you have a Thecus NAS (or a NAS similar to a Thecus) and this check does not work as expected, 
the URIs and/or returned JSON might not match what the supported models use. 
Your model might require calling different URIs or returns the data in a different JSON structure.
To see what data is returned in JSON format by the currently configured URIs, run the script 
with the `--debug` parameter for each check type (health, cpu, memory and disk-usage). The returned JSON
can then be matched against the php code and necessary changes can be made to add support.
To see what URIs should be used, in the case that no or not enough information is returned, 
you can use a webbrowser and log in to your NAS. Use the network monitoring part of the browsers developer tools 
to see what the Web UI uses by browsing to the pages where this information is displayed. 
In Firefox use F12, in Chrome use Ctrl-Shift-i.

Please feel free to make any improvements to this script and send me a pull request. In case of support for 
a new model, please create a file with model specific output, like the ones in the model-output directory.
These files are base on the output of the check when run with the `--debug` parameter.
If you would like support for a model, but you don't know how to add it, you could try to send me the output 
as mentioned above and I might be able to add support that way.
