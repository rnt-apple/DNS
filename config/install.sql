/* used by installer for createing tables in database */

CREATE TABLE IF NOT EXISTS `dns_slaves` (
  `id` int(11) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `fqdn` varchar(50) NOT NULL,
  `ipv4` varchar(39) NOT NULL,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

/* https://de.wikipedia.org/wiki/SOA_Resource_Record */
CREATE TABLE IF NOT EXISTS `dns_zones` (
  `id` int(11) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `customer` int(11) unsigned NOT NULL COMMENT 'FK customers',
  `extern` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 = intern, 1 = extern',
  `name` varchar(70) NOT NULL,
  `ttl` int(10) NOT NULL DEFAULT '600' COMMENT 'default TTL',
  `primary` varchar(50) NOT NULL,
  `mail` varchar(50) NOT NULL,
  `refresh` int(10) NOT NULL DEFAULT '3600',
  `retry` int(10) NOT NULL DEFAULT '1800',
  `expire` int(10) NOT NULL DEFAULT '604800',
  `minimum` int(10) NOT NULL DEFAULT '86400',
  `dnssec` text COMMENT 'DNSSEC Informations',
  `comment` text ,
  `pending` text,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

/* https://de.wikipedia.org/wiki/Resource_Record */
CREATE TABLE IF NOT EXISTS `dns_records` (
  `id` int(11) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `dns_zone` int(11) unsigned NOT NULL COMMENT 'FK dns_zones',
  `host` varchar(255) NOT NULL,
  `ttl` int(10) DEFAULT NULL COMMENT 'record TTL',
  `type` varchar(16) NOT NULL, /* supported records: *,A,AAAA,CNAME,MX,NS,SRV,TXT,hosting,ipssl,redirect */
  `value` text NOT NULL,
  /* start HWS only */
  `hosting` int(11) NOT NULL COMMENT 'FK hostings; if ssl activated FK ip/ssl', 
  `redirect` text NOT NULL COMMENT 'URL for redirect',
  /* end HWS only */
  `comment` text,
  `pending` text,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

