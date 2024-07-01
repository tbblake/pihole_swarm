#!/bin/bash
# light up the green led
# if this is our active node

if [ $# -lt 1 ];then
	echo need a process name
	exit
fi
pgrep "$1" >/dev/null 2>&1
foundProcess=$?

if [ $foundProcess -eq 0 ];then
	echo default-on > /sys/class/leds/ACT/trigger
else
	echo mmc0 > /sys/class/leds/ACT/trigger
fi
