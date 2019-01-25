# Monitoring with Zabbix and Elasticsearch training

This training consists of 2 parts. First we'll monitor a _machine_ using only Zabbix. Then we'll monitor an application using a combination of Zabbix, Monolog and ElasticSearch.

In this training we'll use virtual machines to create a private network. As a preparation, make sure you have two clean Ubuntu Bionic (18.04) machines running, and that they can communicate with each other. The easiest way to do this is probably by using `Vagrantfile` in the `vms` directory. To do that, follow the following steps:
```
$ cd vms
$ vagrant up

# the VMs will now spin up

# good <strike>sysadmins</strike> citizens as we are, we first update the machines

$ vagrant ssh web
web $ sudo su
web $ apt-get update && apt-get upgrade
web $ exit
web $ exit

$ vagrant ssh zabbix
zabbix $ sudo su
zabbix $ apt-get update && apt-get upgrade
zabbix $ exit
zabbix $ exit
```

From now on the prompts will mean the following

* `$` on the host machine
* `web $` on the web vm
* `zabbix $` on the zabbix vm

In all cases you need to be active as root on the VMs (`sudo su` after `vagrant ssh`).

We'll now continue with setting up Zabbix.

## Zabbix
Zabbix is a tool that can be used to monitor pretty much everything. In addition to monitoring, it can also send out alerts when something is wrong. It's highly configurable and I must say that I haven't really encountered anything that it can't monitor yet.

That said, it's not an easy to learn tool. There are a lot of options, and nomenclature is not always very clear. UI-wise it also uses some idioms that are not really common (such as having to double confirm stuff), resulting in situations where you think you've changed or added something, while you actually haven't.

To start you off on the right foot, a quick glossary of terms is in order:

* *Zabbix server* The backend application that processes incoming metrics.
* *Zabbix agent* An application that collects data on the to-be-monitored machine and sends them to Zabbix.
* *Zabbix UI* A PHP application that runs as a UI for all the data and configuration.
* *Host* A server to monitor (e.g. web)
* *Host group* A group of hosts (e.g. production, acceptance). We can use this to set common rules for all hosts in a group.
* *(Host) item* A specific thing that needs to be monitored (e.g. CPU utilization, available memory)
* *Trigger* A condition that signifies something we need to know (e.g. CPU util > 50%)
* *Action* Something that needs to happen when a Trigger condition is reached (e.g. send mail)
* *Graph* A visualization of one or more items (e.g. CPU wait IO, CPU idle)
* *Screen* A collection of graphs
* *Template* A collection of items, triggers, graphs that can be applied to a host. This is useful since hosts often are similar (e.g. Template OS Linux)
* *User* A user for logging into Zabbix
* *User group* A collection of users. Note that permissions can only be set on the _User group_ level, not on individual user basis.

With that out of the way, let's show the architecture in a diagram:

```
+-------------------------------------------+
| Zabbix VM                                 |
|                                           |
|                                           |            +---------------------------+
|  +------------------+                     |            | Webserver                 |
|  |                  |                     |            |                           |
|  | Zabbix UI (PHP)  |                     |            |                           |
|  |                  |                     |            |                           |
|  +----+-------------+                     |            | +---------------------+   |
|       |                                   |            | |                     |   |
|  +----v-----+        +----------------+   |            | |  Zabbix Agent       |   |
|  |          |        |                |   |            | |                     |   |
|  |  MySQL   <--------+  Zabbix Server <------------------+ (Monitors machine)  |   |
|  |          |        |                |   | Item data  | |                     |   |
|  +----------+        +----------------+   |            | +---------------------+   |
|                                           |            |                           |
+-------------------------------------------+            +---------------------------+
```

So the steps we need to take are:

1. Setup Zabbix Server + UI on the Zabbix VM
2. Setup Zabbix Agent on the webserver VM

### Setup Zabbix Server

Zabbix is available by default with Ubuntu so installation is easy:

```
# this will install zabbix server with the mysql backend (other database backends are available)
zabbix $ apt-get install zabbix-server-mysql

# setup the database for mysql (if you want different values change /etc/zabbix/zabbix_server.conf)
zabbix $ echo 'create database zabbix' | mysql
zabbix $ echo 'create user zabbix@localhost'
zabbix $ echo 'grant all on zabbix.* to zabbix@localhost' | mysql

# seed the database
zabbix $ zcat /usr/share/zabbix-server-mysql/{schema,images,data}.sql.gz | mysql -u zabbix zabbix

# start zabbix server
zabbix $ systemclt start zabbix-server

# and check if everything is running
zabbix $ tail /var/log/zabbix-server/zabbix_server.log
# => should show something like this, specifically look for errors
Cannot adopt OID in NET-SNMP-AGENT-MIB: nsNotifyShutdown ::= { netSnmpNotifications 2 }
Cannot adopt OID in NET-SNMP-AGENT-MIB: nsNotifyRestart ::= { netSnmpNotifications 3 }
Cannot adopt OID in UCD-SNMP-MIB: laErrMessage ::= { laEntry 101 }
Cannot adopt OID in UCD-SNMP-MIB: laErrorFlag ::= { laEntry 100 }
Cannot adopt OID in UCD-SNMP-MIB: laLoadFloat ::= { laEntry 6 }
Cannot adopt OID in UCD-SNMP-MIB: laLoadInt ::= { laEntry 5 }
Cannot adopt OID in UCD-SNMP-MIB: laConfig ::= { laEntry 4 }
Cannot adopt OID in UCD-SNMP-MIB: laLoad ::= { laEntry 3 }
Cannot adopt OID in UCD-SNMP-MIB: laNames ::= { laEntry 2 }
Cannot adopt OID in UCD-SNMP-MIB: laIndex ::= { laEntry 1 }

# now the server is running, we can setup the frontend
zabbix $ apt-get install zabbix-frontend-php php-mysql
# enable zabbix in apache
zabbix $ a2enconf
=> zabbix-frontend-php
zabbix $ systemctl reload apache2
```

We now have the Zabbix UI running on the VM. If you used the Vagrant, browse to http://192.168.50.4/zabbix on your host machine. (Replace the IP with your own if you setup manually, I'll assume this IP from now on). It should greet you with something like this:

img/zabbix-installed.png

Now walk through the setup wizard. Things that sometimes come up:

* If it requires you to change any PHP settings, do that in /etc/php/7.2/apache2/php.ini, and restart apache `systemctl restart apache2`.
* If you need to install a PHP extension, for example gd, do that with apt-get: `apt-get install php-gd`
* If MySQL is not listed as a DB option, `apt-get install php-mysql && systemctl restart apache2`

In the final step it needs to write out the config file, to allow this temporarily give write rights to `www-data` to `/etc/zabbix`: `chmod 777 /etc/zabbix`. Now save the file in the wizard and restore permissions: `chmod 755 /etc/zabbix`.

You should now be greeted with this nice login screen:

img/zabbix-after-setup.png

The credentials for the default user are `Admin`, password `zabbix`. You'll now see the following screen

img/zabbix-after-login.png

Great! Zabbix server has been setup. We can now go and create our first host: the web server. To do this, go to _Configuration -> Hosts_. Click the _Create host_ button at the upper left of the screen. Use the following values:

* *Host name* This is an arbitrary host name for Zabbix only. It will be used by the Zabbix Agent running on the web server to identify itself. Pick `demo-web` for now.
* *Visible name* Is something that you can configure to have the host have a different display name in the Zabbix UI. Leave empty for now, `demo-web` is clear enough.
* *Host groups* Not really important for now, but pick `Linux servers` and `Virtual machines` to be a complacent citizen.
* *Agent interfaces* This one is important, it specifies how Zabbix Server can reach the agent. The default port is fine, but change the _IP Address_ to the one you used (192.168.50.3 if your using the example Vagrant). Make sure _Connet to_ is kept on IP.

All the other fields are not necessary, but we do like to attach a template, so go to the _Templates_ tab. Add the _Template OS Linux_ template. Notice that this screen has one of the Zabbix quirks mentioned earlier, you first need to click the _Add_ link to add the template, and then the _Add_ button to actually add the host.

Congratulations: you've now added your first host to Zabbix. It should look something like this:

img/zabbix-after-host.png

Unfortunately, the our web VM isn't running an agent yet. So Zabbix will not be able to connect. After a while this will be clear in the UI by having the _Availability_ column in the hosts overview showing a red _ZBX_ to indicate the agent cannot be reached. You can also go to _Monitoring -> Latest data_ and select the `demo-web` host there to verify that the items are there (coming from the _Template OS Linux_ template), but none have any data. So let's setup the agent on the web server now.

```
web $ apt-get install zabbix-agent
# stop the agent so we can configure it
web $ systemctl stop zabbix-agent
```

Now edit `/etc/zabbix/zabbix_agentd.conf` to set it up:

* *ServerActive* Set this to `192.168.50.4`
* *Server* Set this to `192.168.50.4`
* *Hostname* Set this to `demo-web`, the hostname we specified during configuration in Zabbix.

```
web $ systemctl restart zabbix-agent
web $ tail /var/log/zabbix-agent/zabbix_agentd.log
=> should be something like this, specifically look for errors
3478:20190125:093805.915 IPv6 support:          YES
  3478:20190125:093805.915 TLS support:           YES
  3478:20190125:093805.915 **************************
  3478:20190125:093805.915 using configuration file: /etc/zabbix/zabbix_agentd.conf
  3478:20190125:093805.916 agent #0 started [main process]
  3479:20190125:093805.917 agent #1 started [collector]
  3484:20190125:093805.922 agent #4 started [listener #3]
  3481:20190125:093805.923 agent #3 started [listener #2]
  3486:20190125:093805.923 agent #5 started [active checks #1]
  3480:20190125:093805.929 agent #2 started [listener #1]
```

Now, refresh the _Latest data_ page in Zabbix to see that the data is now flowing in:

img/zabbix-flowing-in.png

Congratulations! You've now setup everything correctly. Be sure to look and play around a bit in Zabbix to get a feel of what's happening before you continue. And just for fun, let's hog the CPU a little bit:
```
web $ while true; do echo "zabbix rocks" > /dev/null; done
```
Be sure to at least check out _Latest data_ and _Graphs_.

### Getting notified of errors
We'd like to be notified of strange situations, such as when the CPU is overload. For this we use a combination of Triggers and Actions. Triggers define unusual situations, while Actions are used to act upon those triggers.

Go to _Configuration -> Hosts -> demo-web -> Triggers_ and press _Create trigger_.

* *Name* CPU util too high
* *Expression* Press add and use the constructor to set CPU User Time > 20%.
* *Severity* Average

And add the trigger.

If you now go to _Dashboard_ the trigger will show after a short while. Triggers are evaluated everytime new data comes in, so it could take up to a minute for it to appear in the dashboard.

So this is nice, but of course you also want to get notified directly, so let's set that up with e-mail. To do this we need to set up an action, go to _Configuration -> Actions_ and click the _Create action_ button.

* *Name* Notify via e-mail
* *Conditions* Keep default
* *Operations* Add an operation that will send to user group _Zabbix administrators_, and will send to *only* e-mail.

Press add.

We now also set up an e-mail address for the Administrator, which can be done at _Administration -> Users -> Admin -> Media (tab)_.

Then, we need to configure the e-mail server settings, which can be done at _Administration -> Media types -> Email_:
* *SMTP Server* localhost
* *SMTP Server port* 2500
* *SMTP helo* my.example
* *SMTP email* admin@my.example

Finally, we need to actually have a mailserver running on our Zabbix VM. For this, we'll run _mailslurper_ on it:

```
zabbix $ cd ~
zabbix $ curl -OL https://github.com/mailslurper/mailslurper/releases/download/1.14.1/mailslurper-1.14.1-linux.zip
zabbix $ apt-get install unzip
zabbix $ mkdir mailslurper && cd mailslurper && unzip ../mailslurper-1.14.1-linux.zip
# change config.json so wwwAddress and serviceAddress is on our ip (192.168.50.4)
zabbix $ ./mailslurper
```

Now, unhog the CPU on web (CTRL-C to exit it), and wait for the trigger to disappear. When it's disappeared, restart the hog, and you should be receiving an e-mail. Go to http://192.168.50.4:8080/ to see incoming mails.


### End of Zabbix
This concludes the Zabbix part of the tutorial. As a bonus question: try to setup a custom _item_ yourself.

## Elasticsearch
This second part of this training covers Elasticsearch. Elasticsearch is a set of tools that allows you to search through logs produced by your system. It consists of a couple of parts

* Elasticsearch, the engine, this is the database so to say.
* Kibana, this is a tool that allows you to search through the logs.
* Filebeat, this tools pumps the logs into the database.

### Demo app

*This section assumes you used vagrant to setup the environment, adjust accordingly if you didn't*

But before we get started, we actually need to log something. For this, there is a sample PHP application in the `php-login-app` directory.

Let's get it up and running on web

```
web $ apt-get install composer apache2 libapache2-mod-php unzip
web $ cd /vagrant/php-login-app
web $ composer install
web $ ln -s /vagrant/php-login-app /var/www/html/php-login-app
web $ mkdir /logs && chmod 777 /logs
```

On your host, in your browser go to http://192.168.50.3/php-login-app/

This should provide you with the following nice login screen:

img/login.png

Also, this should have logged something already:

web $ tail /logs/php-login-app.log
