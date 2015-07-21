# check_thecus_nas
Nagios check plugin for Thecus NAS devices

## Overview
This plugin can check the health and status of a Thecus NAS device. It has been developed and tested 
against a Thecus N5500 running firmware V5.00.04
Is can check and report CPU usage, system- and CPU-fans, RAID status, available disk space, disk health (bad-sectors) 
and disk temperature.

The Thecus NAS device has (or at least some have) the option to enable and read out SNMP, but this option gives far 
less information than what can be seen in (and retrieved from) the management user-interface.
This check gets its information from this management user-interface through a series of http-requests
which return information in json-format. 

Basing a check on a user-interface is generally not a good idea because user-interfaces are not
guaranteed to be the same between different Thecus models and even firmware versions of the same
model. This is however the only way to extract extra information like raid-status, drive temperatures
and failures.

## Installation
Requirements:
* PHP version 5.3 (earlier versions are untested)
* php5_curl
    For Debian based systems (e.g. Ubuntu): <code>sudo apt-get install php5-curl</code>

Place the check script anywhere you'd like (eg /usr/local/lib/nagios/plugins) and run it

## Usage
For a complete overview run the check with the parameter "--help".
For security reasons, it's a good idea to store the password in a config file instead of specifying 
the password as a parameter on the command line. Every user, that can log on to the machine, can see 
all running processes with arguments (which would include the password.) By using a config file, you
will not suffer from this issue.

General use cases:
Check the health of a device using a config file 
<code>
check_thecus_nas.php --hostname nas01.example.com --config-file nas01.conf --type health
</code>

Check the cpu usage of a device specifying the username/password on the commandline (insecure, read above)
<code>
check_thecus_nas.php -h nas01.example.com -u admin -p password -t cpu --cpu-warning 80 --cpu-critical 90
</code>

Check the available disk space of a device
<code>
check_thecus_nas.php -h nas01.example.com -c nas01.conf -t disk-usage --disk-usage-warning 80 --disk-usage-critical 90
</code>

## Nagios configuration examples
Command definition examples:
<code>
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
</code>

Service template examples:
<code>
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
</code>

Service definition examples:
<code>
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
</code>


