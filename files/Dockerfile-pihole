FROM pihole/pihole:latest
USER root
COPY 16-dhcpLeases.conf /etc/lighttpd/conf-enabled/
COPY dhcpLeases.php /var/www/html
