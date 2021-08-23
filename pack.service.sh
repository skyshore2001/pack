#!/bin/sh

svc=`basename $0 .sh`
if [[ $UID != 0 ]]; then
	echo "*** Error: use root user or sudo"
	exit 1
fi

cat <<. > /usr/lib/systemd/system/$svc
[Unit]
Description=pack http with tcp
After=syslog.target network.target auditd.service

[Service]
Type=simple
User=builder
ExecStart=/var/www/src/pack/pack.sh
WorkingDirectory=/var/www/src/pack/
Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target

.

echo "=== install $svc"
systemctl enable $svc
systemctl daemon-reload

echo "=== restart $svc"
systemctl restart $svc
systemctl status $svc
