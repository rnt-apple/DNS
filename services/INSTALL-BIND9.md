# Installation of Bind9 on Debian Stretch or CentOS 7

*serial 2017040501*

In this manuel the following topics are explained:
 - CentOS 7
 - Debian Stretch (9) 

Which distribution to use is your decision.
 
## CentOS 7
First of all you need to install CentOS.

Now you are able to connect to your System via SSH-Client (Putty)

In some cases the ISOs can be out of date, therefore you need to update your system before going on to the settings:
```
yum -y update
```

Install some needed packages
```
# installation some tools needed in addition
yum -y install mc ntp wget mailx nano php-cli php-pdo
```

And at least the needed package for the dns server
```
# installation the bind dns server
yum install bind bind-utils
```

## Debian Stretch

Install Updates.
```
apt-get update
apt-get upgrade
```

# installation some tools needed in addition
apt-get -y install mc ntp ntpdate wget postfix nano php-cli php-pdo php-sqlite3
```

And at least the needed package for the dns server
```
apt-get -y install bind9 bind9utils
```


Finished! :-)
