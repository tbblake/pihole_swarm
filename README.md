# HA Pi-Hole w/DHCP and DNS, docker swarm

My ultimate goal was to setup a pihole instance that's equally usable as running it on a single raspberry pi, and utilizing some sort of container orchestration.  To that end I worked through it and the requirements came out to be:

* Works on four raspberry pi 3b+'s.  It's what I have lying around, I didn't want to buy more hardware.
* Docker Swarm.  Kubernetes is something I'd like to work towards but my hardware is slim, and admittedly K8S is very complex.  I'm thinking docker swarm will be a stepping stone to learning K3s, and eventually kubernetes.
* Keepalived - Used to provide a single IP that's always available regardless of which nodes are online.  This is configured so that it follows which node is running pihole at any point in time.
* PiHole - running in a container, in the networking "host" mode.  This is because the nodes that utilize port forwarding end up masking the traffic as coming from the containers internal IP, rendering a lot of DNS stats useless.  By putting them in host mode, I get source IPs on everything.  The downside here is DHCP broadcasts are not forwarded but that's the case no matter what networking mode we use.  We end up running the pihole DHCP server on port 66 instead and forwarding requests using an external forwarder installed in a container on each pi.
* DHCP Forwarder - A separate dnsmasq instance on each node (used for DHCP forwarding).  What we do is run a separate dnsmasq instance on each node that forwards all DHCP requests to the HA IP controlled by keepalived.
* GlusterFS for a clustered filesystem so pihole will retain it's configuration across all cluster nodes.
* Ansible - I wanted to automate and reduce reliance on my swarm hardware as much as possible.  The answer being ansible running from an external Linux VM.  This VM is running RHEL 9 in my case, but really any host that can run ansible should do here.

So a little information on me.  Like many folks in IT I've got an actual interest and passion for it.  For decades I've always wanted to work on environments that are resilient and make use of useful technologies to provide highly available services.  I know that's sales speak but I beleive in it.  The most recent way to do this seems to be containers with orchestration, and from what I see, the most famous way to do that, is with Kubernetes.

My background is in UNIX/Linux administration since the mid 90s, and most recently I maintain monitoring applications for a living.  So I've got plenty of experience with OSs and applications, but this journey started with only a superficial understanding of containers, and barely any understanding of container orchestration beyond what the name obviously implies.

Now unfortunately, my day job is being slow with training so I'm learning it on my own.  I started by learning docker which I feel like I've got a decent handle on, but still plenty to learn.  I started looking at kubernetes next but it seemed a big leap from just a single host with docker, and also seemed to require more hardware than I have in my house.  Since docker swarm seems to be a decent stop along the way to kubernetes, I figured I'd make that my current goal.

Since this has been a learning experience in several technologies, I'm certain there are many better ways on the docker image builds, ansible methods, etc to do things so I'm hoping by putting this out there I'll also learn more from others.  All constructive criticism is welcome and encouraged.

## Assumptions

1. A seperate linux instance (PC/VM/anything) with ansible installed exists
1. We'll be using Raspberry Pi 3B+'s
1. We'll be installing raspberry pi os bookworm, the lite 64-bit version.
1. Our IPs will be 192.168.2.101, 192.168.2.102, and so on.  192.168.2.2 will be the highly available IP
1. Our hostnames will be pi301, pi302, and so on.
1. Each pi will get a static IP assigned on the pi itself, not via DHCP, since this cluster will be our DHCP server.
1. We have a DHCP server (our router)

## High level steps

1. Installed raspberry pi os (bookworm at the time of this writing) on each pi, configure a hostname (pi301...), static IP (192.168.2.10x...), user account (tblake).
1. Ran the ansible playbook that:
    1. Install the latest version of docker from docker.io on each pi (via ansible).
    1. Install and configure GlusterFS on each pi (via ansible)
    1. Create the swarm
    1. Deploy the necessary docker files to all three nodes for keepalived and dnsmasq to run on each node, and pihole to run on one node at a time.

I'd initially thought I'd write up a tutorial, but given that I think I've got a lot to learn on good practices in what I'm learning here, and there be many much better tutorials accomplishing the same thing, I figured I'd at least start with sharing what I did code-wise. Maybe, eventually, it'll be a tutorial, but by the time I feel good about it, docker swarm will be long gone I'm sure :).

At a minimum ansible needs more work with some roles I think.  I also need to add some node and service monitoring (I use zabbix).

The only thing that needs to be done after running the playbook is to reset the password.  I do this by logging into the node that has the HA IP, and finding the container id with `sudo docker ps`, and then running `sudo docker exec -it <containerid> pihole -a -p`.

Also not included is 04-pihole-static-dhcp.conf and custom.list.  I have them deployed because I pulled it from my old pihole install.

An example run:
```
$ ansible-playbook deploy.yml

PLAY [download & install pihole docker swarm] ************************************************************************************

TASK [Gathering Facts] ***********************************************************************************************************
ok: [192.168.2.102]
ok: [192.168.2.104]
ok: [192.168.2.103]
ok: [192.168.2.101]

TASK [Check for host count, end if less than 3] **********************************************************************************
skipping: [192.168.2.101]

TASK [get docker keyring] ********************************************************************************************************
changed: [192.168.2.102]
changed: [192.168.2.103]
changed: [192.168.2.101]
changed: [192.168.2.104]

TASK [Gather dpkg architecture] **************************************************************************************************
changed: [192.168.2.103]
changed: [192.168.2.101]
changed: [192.168.2.102]
changed: [192.168.2.104]

TASK [docker repo file] **********************************************************************************************************
changed: [192.168.2.103]
changed: [192.168.2.102]
changed: [192.168.2.104]
changed: [192.168.2.101]

TASK [install all packages] ******************************************************************************************************
changed: [192.168.2.104]
changed: [192.168.2.102]
changed: [192.168.2.103]
changed: [192.168.2.101]

TASK [systemd startup docker] ****************************************************************************************************
changed: [192.168.2.104]
changed: [192.168.2.102]
changed: [192.168.2.103]
changed: [192.168.2.101]

TASK [init docker swarm (first node)] ********************************************************************************************
skipping: [192.168.2.102]
skipping: [192.168.2.103]
skipping: [192.168.2.104]
changed: [192.168.2.101]

TASK [join docker swarm nodes (managers, 2nd & 3rd nodes)] ***********************************************************************
skipping: [192.168.2.101]
skipping: [192.168.2.104]
changed: [192.168.2.102]
changed: [192.168.2.103]

TASK [join docker swarm nodes (workers, remaining nodes)] ************************************************************************
skipping: [192.168.2.101]
skipping: [192.168.2.102]
skipping: [192.168.2.103]
changed: [192.168.2.104]

TASK [systemd startup glusterd] **************************************************************************************************
changed: [192.168.2.102]
changed: [192.168.2.101]
changed: [192.168.2.103]
changed: [192.168.2.104]

TASK [add gluster peers] *********************************************************************************************************
skipping: [192.168.2.102]
skipping: [192.168.2.103]
skipping: [192.168.2.104]
changed: [192.168.2.101]

TASK [create gluster volume] *****************************************************************************************************
skipping: [192.168.2.102]
skipping: [192.168.2.103]
skipping: [192.168.2.104]
changed: [192.168.2.101]

TASK [mount gluster volume] ******************************************************************************************************
changed: [192.168.2.102]
changed: [192.168.2.101]
changed: [192.168.2.104]
changed: [192.168.2.103]

TASK [deploy file Dockerfile-keepalived] *****************************************************************************************
changed: [192.168.2.101]
changed: [192.168.2.103]
changed: [192.168.2.102]
changed: [192.168.2.104]

TASK [deploy file Dockerfile-dnsmasq] ********************************************************************************************
changed: [192.168.2.102]
changed: [192.168.2.104]
changed: [192.168.2.101]
changed: [192.168.2.103]

TASK [build keepalived image] ****************************************************************************************************
changed: [192.168.2.102]
changed: [192.168.2.101]
changed: [192.168.2.104]
changed: [192.168.2.103]

TASK [build dnsmasq image] *******************************************************************************************************
changed: [192.168.2.102]
changed: [192.168.2.101]
changed: [192.168.2.104]
changed: [192.168.2.103]

TASK [remove extraneous alpine image] ********************************************************************************************
changed: [192.168.2.101]
changed: [192.168.2.103]
changed: [192.168.2.102]
changed: [192.168.2.104]

TASK [pull pihole image] *********************************************************************************************************
changed: [192.168.2.104]
changed: [192.168.2.102]
changed: [192.168.2.103]
changed: [192.168.2.101]

TASK [Create pihole-data volume mount] *******************************************************************************************
skipping: [192.168.2.102]
skipping: [192.168.2.103]
skipping: [192.168.2.104]
changed: [192.168.2.101]

TASK [Create pihole-dnsmasq volume mount] ****************************************************************************************
skipping: [192.168.2.102]
skipping: [192.168.2.103]
skipping: [192.168.2.104]
changed: [192.168.2.101]

TASK [deploy template 02-pihole-dhcp-custom.conf] ********************************************************************************
skipping: [192.168.2.102]
skipping: [192.168.2.103]
skipping: [192.168.2.104]
changed: [192.168.2.101]

TASK [deploy template dnsmasq.conf] **********************************************************************************************
changed: [192.168.2.101]
changed: [192.168.2.104]
changed: [192.168.2.102]
changed: [192.168.2.103]

TASK [deploy template keepalived.conf] *******************************************************************************************
changed: [192.168.2.103]
changed: [192.168.2.104]
changed: [192.168.2.102]
changed: [192.168.2.101]

TASK [deploy file 04-pihole-static-dhcp.conf] ************************************************************************************
skipping: [192.168.2.102]
skipping: [192.168.2.103]
skipping: [192.168.2.104]
changed: [192.168.2.101]

TASK [deploy file custom.list] ***************************************************************************************************
skipping: [192.168.2.102]
skipping: [192.168.2.103]
skipping: [192.168.2.104]
changed: [192.168.2.101]

TASK [deploy / start pihole swarm service] ***************************************************************************************
skipping: [192.168.2.102]
skipping: [192.168.2.103]
skipping: [192.168.2.104]
changed: [192.168.2.101]

TASK [start keepalived container] ************************************************************************************************
changed: [192.168.2.104]
changed: [192.168.2.102]
changed: [192.168.2.101]
changed: [192.168.2.103]

TASK [start dnsmasq container] ***************************************************************************************************
changed: [192.168.2.104]
changed: [192.168.2.103]
changed: [192.168.2.102]
changed: [192.168.2.101]

TASK [deploy file proc_leds.sh] **************************************************************************************************
changed: [192.168.2.102]
changed: [192.168.2.104]
changed: [192.168.2.103]
changed: [192.168.2.101]

TASK [cron entry (proc_leds)] ****************************************************************************************************
changed: [192.168.2.102]
changed: [192.168.2.103]
changed: [192.168.2.104]
changed: [192.168.2.101]

PLAY RECAP ***********************************************************************************************************************
192.168.2.101              : ok=29   changed=28   unreachable=0    failed=0    skipped=2    rescued=0    ignored=0
192.168.2.102              : ok=21   changed=20   unreachable=0    failed=0    skipped=10   rescued=0    ignored=0
192.168.2.103              : ok=21   changed=20   unreachable=0    failed=0    skipped=10   rescued=0    ignored=0
192.168.2.104              : ok=21   changed=20   unreachable=0    failed=0    skipped=10   rescued=0    ignored=0

$  ssh tblake@192.168.2.2
Warning: Permanently added '192.168.2.2' (ED25519) to the list of known hosts.
Linux pi301.tblake.org 6.6.20+rpt-rpi-v8 #1 SMP PREEMPT Debian 1:6.6.20-1+rpt1 (2024-03-07) aarch64

The programs included with the Debian GNU/Linux system are free software;
the exact distribution terms for each program are described in the
individual files in /usr/share/doc/*/copyright.

Debian GNU/Linux comes with ABSOLUTELY NO WARRANTY, to the extent
permitted by applicable law.
Last login: Mon Jul  1 11:14:38 2024 from 192.168.2.10
tblake@pi301:~ $ sudo docker ps
CONTAINER ID   IMAGE                  COMMAND                  CREATED         STATUS                   PORTS                                                                                                             NAMES
0ebbb7c8bd32   dnsmasq                "/usr/sbin/dnsmasq -k"   7 minutes ago   Up 6 minutes                                                                                                                               dnsmasq
6244b6668cc4   keepalived             "/usr/sbin/keepalive…"   7 minutes ago   Up 41 seconds                                                                                                                              keepalived
5752c8071c2f   pihole/pihole:latest   "/s6-init"               7 minutes ago   Up 7 minutes (healthy)   0.0.0.0:53->53/tcp, 0.0.0.0:53->53/udp, :::53->53/tcp, :::53->53/udp, 80/tcp, 0.0.0.0:66->67/udp, :::66->67/udp   pihole.1.5z2yp32xap3e57d312r0ekf6q
tblake@pi301:~ $ sudo docker exec -it 5752c8071c2f pihole -a -p
Enter New Password (Blank for no password):
Confirm Password:
  [✓] New password set
tblake@pi301:~ $ logout
Connection to 192.168.2.2 closed.
$


```