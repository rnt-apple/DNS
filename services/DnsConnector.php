<?php
/**
* @copyright Copyright (c) ARONET GmbH (https://aronet.swiss)
* @license AGPL-3.0
*
* This code is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License, version 3,
* along with this program.  If not, see <http://www.gnu.org/licenses/>
*
*/

namespace RNTForest\ovz\services;

use RNTForest\core\libraries\RemoteSshConnection;
use RNTForest\ovz\models\VirtualServers;

/**
* The DnsConnector is used to connect a preinstalled bind9 server to the DNSCP or ECP.
* Consult the INSTALL-BIND9.md to read about the correct usage.
* Connector will be called from the Phalcon App in a wizard like manier.
* 
* The Connector does the following to the bind9 Server:
* - checks the hostsystem (centos7 or debian9 system?, php >= 5.4?)
* - makes general configurations (postfix, ntp, ssh)
* - generates asymmetrical keypair, saves it locally on the server and saves the public key in the central db in phalcon app
* - puts the public key of the adminserver (where the phalcon app runs) as a file on the server
* - creates and configures a linux user/group for running the webservice
* - sends all needed files in the jobsystem directory to the server
* - creates directories for db and logs
* - installation and configuration of composer
* - sets file permissions
* - configures sudoers, so that the job-component can be started with root permissions
* - configures dnscp.service (systemd)
* - checks bind9 installation
* - configures bind9
* - writes the dnscp.local.config.php
* - sends a test job (general_test_sendmail to the specified rootalias)
* - sets the dns server to DNS=1 if successfully connected
* 
* Can be executed more than once.
*/
class DnsConnector extends \Phalcon\DI\Injectable
{
    private $ConfigDnsJobsystemRootDir = '/srv/jobsystem/';
    private $ConfigMyPublicKeyFilePath = '/srv/jobsystem/keys/public.pem';
    private $ConfigMyPrivateKeyFilePath = '/srv/jobsystem/keys/private.key';
    private $ConfigAdminPublicKeyFilePath = '/srv/jobsystem/keys/adminpublic.key';

    private $PathsToJobsystemDirectoriesOnAdminServer = [
        BASE_PATH.'/vendor/rnt-forest/core/jobserver/',
        BASE_PATH.'/vendor/rnt-forest/dns/jobserver/',
    ];
    
    private static $DEBIAN = "debian";
    private static $CENTOS = "centos";

    /**
    * @var Dependency Injection
    */
    private $di;
    
    /**
    * @var mixed
    */
    private $Logger;
    
    /**
    * @var RemoteSshConnection
    */
    private $RemoteSshConnection; 
    
    /**
    * @var VirtualServers
    */
    private $VirtualServer;
    
    /**
    * Username for authenticate ssh connection
    * 
    * @var string
    */
    private $RootUsername;
    
    /**
    * Password for authenticate ssh connection
    * 
    * @var string
    */
    private $RootPassword;
    
    /**
    * either debian, centos or empty
    * 
    * @var string
    */
    private $Distribution = '';
    
    public function __construct(VirtualServers $virtualServer, $rootUsername, $rootPassword){
        $this->di = $this->getDI();
        $this->Logger = $this->getDI()['logger'];
        $this->VirtualServer = $virtualServer;
        $this->RootUsername = $rootUsername;
        $this->RootPassword = $rootPassword;
        $this->RemoteSshConnection = new RemoteSshConnection($this->di, $this->VirtualServer->getFqdn(), $this->RootUsername, $this->RootPassword);
    }
    
    public function go(){
        if(!$this->RemoteSshConnection->isOpen()){
            throw new \Exception("Remote SSH Connection is not open. Aborting...");
        }
        $this->checkEnvironment();
        $this->preInstallation();
        
        $this->createAsymmetricKeys();
        $this->sendAdminPublicKeyToRemoteserver();
        $this->createLinuxUserAndGroup();
        $this->writeDnscpLocalConfig();
        $this->copyJobsystemScriptsToServer();
        $this->prepareFurtherJobsystemDirectories();
        $this->configureComposer();
        $this->cleanPermissionsInJobsystemDirectories();
        $this->configureSudoers();
        $this->configureDnscpService();
        
        $this->postInstallation();
        $this->testJobSystem();
    }
    
    private function checkEnvironment(){
        try{
            $noValidDistributionFound = "Wrong linux distribution. Accepted: CentOS 7 or Debian 9";
            
            $system_release_file = "/etc/system-release";
            $output = $this->RemoteSshConnection->exec('if [ -f "'.$system_release_file.'" ]; then echo "1"; else echo 0; fi');
            if(intval($output)==1){
                $output = $this->RemoteSshConnection->exec('cat '.$system_release_file);    
                if(!preg_match('`^(CentOS Linux release 7.).*$`',$output)){
                    throw new \Exception($noValidDistributionFound);       
                }else{
                    $this->Distribution = DnsConnector::$CENTOS;    
                }
            }
            
            unset($output);
            $debian_version_file = "/etc/debian_version";
            $output = $this->RemoteSshConnection->exec('if [ -f "'.$debian_version_file.'" ]; then echo "1"; else echo 0; fi');
            if(intval($output)==1){
                $output = $this->RemoteSshConnection->exec('cat '.$debian_version_file);    
                if(!preg_match('`^(9).*$`',$output)){
                    throw new \Exception($noValidDistributionFound);       
                }else{
                    $this->Distribution = DnsConnector::$DEBIAN;    
                }
            }
            
            if($this->Distribution == DnsConnector::$CENTOS){
                unset($output);
                $output = $this->RemoteSshConnection->exec('php -v');
                if(!preg_match('`^(PHP 5.4).*`',$output)){
                    throw new \Exception("No accepted PHP version found. Accepted for CentOS: PHP 5.4.x (yum install php-cli)");
                }
                
                unset($output);
                $output = $this->RemoteSshConnection->exec('php -m');
                if(!preg_match('`PDO`',$output)){
                    throw new \Exception("PHP PDO Extension not found. (yum install php-pdo)");
                }
                
                unset($output);
                $output = $this->RemoteSshConnection->exec('echo "<?php phpinfo(INFO_MODULES);" | php | grep "SQLite Library"');
                if(!preg_match('`^(SQLite Library => 3.).*`',$output)){
                    throw new \Exception("PHP PDO Extension not found. (yum install php-pdo)");
                }
                
                unset($output);
                $output = $this->RemoteSshConnection->exec('rpm -qa | grep mailx');
                if(!preg_match('`mailx`',$output)){
                    throw new \Exception("Mailx not found. (yum install mailx)");
                }
            }elseif($this->Distribution == DnsConnector::$DEBIAN){
                unset($output);
                $output = $this->RemoteSshConnection->exec('php -v');
                if(!preg_match('`^(PHP 7.0).*`',$output)){
                    throw new \Exception("No accepted PHP version found. Accepted for Debian: PHP 7.0.x (apt-get install php-cli)");
                }
                
                unset($output);
                $output = $this->RemoteSshConnection->exec('php -m');
                if(!preg_match('`PDO`',$output)){
                    throw new \Exception("PHP PDO Extension not found. (apt-get install php-pdo)");
                }
                
                unset($output);
                $output = $this->RemoteSshConnection->exec('echo "<?php phpinfo(INFO_MODULES);" | php | grep "SQLite Library"');
                if(!preg_match('`^(SQLite Library => 3.).*`',$output)){
                    throw new \Exception("PHP PDO Extension not found. (apt-get install php-sqlite3)");
                }

                unset($output);
                $output = $this->RemoteSshConnection->exec('dpkg-query -l | grep postfix');
                if(!preg_match('`postfix`',$output)){
                    throw new \Exception("Postfix not found. (apt-get install postfix)");
                }
            }else{
                // if no valid distribution is found
                throw new \Exception($noValidDistributionFound);
            }
            
        }catch(\Exception $e){
            $error = 'System is not supported: '.$this->MakePrettyException($e);
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error);
        }
    }
    
    private function preInstallation(){
        try{
            // control if SSH Key exists, if not create a new pair
            $files = array('/root/.ssh/id_rsa','/root/.ssh/id_rsa.pub');
            $id_rsa = true;
            foreach($files as $file) {
                $output = $this->RemoteSshConnection->exec('if [ -f "'.$file.'" ]; then echo "1"; else echo 0; fi');
                if(intval($output)!=1) $id_rsa = false;
            }
            if(!$id_rsa){
                $this->RemoteSshConnection->exec('rm -f /root/.ssh/id_rsa');
                $this->RemoteSshConnection->exec('rm -f /root/.ssh/id_rsa.pub');
                $this->RemoteSshConnection->exec('ssh-keygen -b 2048 -t rsa -f /root/.ssh/id_rsa -q -N ""');
            }
            
            // configure mail service (todo...)
            //$relayhost = $this->di['config']->mail['relayhost'];
//            $rootalias = $this->di['config']->mail['rootalias'];
//            if(!empty($relayhost) && !empty($rootalias)){
//                $sshCommandToSetRelayhost = 'sed -i \'s/.*#relayhost\\x20\\x3D\\x20\[gateway.*/relayhost = ['.$relayhost.']/\' /etc/postfix/main.cf';
//                $sshCommandToSetAdminAddress = 'sed -i \'s/.*#root:.*/root:'.$rootalias.'/\' /etc/aliases';
//                $output = $this->RemoteSshConnection->exec($sshCommandToSetRelayhost);
//                $output = $this->RemoteSshConnection->exec($sshCommandToSetAdminAddress);
//                $output = $this->RemoteSshConnection->exec('newaliases');
//            } 
            
            // configure ntpd
            $ntpdService = 'ntpd';
            if($this->Distribution == DnsConnector::$DEBIAN){
                $ntpdService = 'ntp';
            }
            $output = $this->RemoteSshConnection->exec('systemctl enable '.$ntpdService);
            $output = $this->RemoteSshConnection->exec('ntpdate pool.ntp.org');
            $output = $this->RemoteSshConnection->exec('systemctl start '.$ntpdService);
                
        }catch (\Exception $e) {                                                                                 
            $error = 'Error while preInstallation: '.$this->MakePrettyException($e);
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error);
        }
    }
    
    private function createAsymmetricKeys(){
        try{
            $this->createDirectoryIfNotExists($this->ConfigDnsJobsystemRootDir.'keys');
            
            // keys are generated each time because it is cheap and more reliable
            $config = array(
                "digest_alg" => "sha256",
                "private_key_bits" => 2048,
                "private_key_type" => OPENSSL_KEYTYPE_RSA,
            );
                
            // Create the private and public key
            $res = openssl_pkey_new($config);
            // Extract the private key from $res to $privKey
            openssl_pkey_export($res, $privKey);

            // Extract the public key from $res to $pubKey
            $pub = openssl_pkey_get_details($res);
            $pubKey = $pub['key'];
            
            // save the keypair on the server in files
            $this->RemoteSshConnection->exec('echo "'.$pubKey.'" > '.$this->ConfigMyPublicKeyFilePath);
            $this->RemoteSshConnection->exec('echo "'.$privKey.'" > '.$this->ConfigMyPrivateKeyFilePath);
                    
            // and update the public key in the model 
            $this->VirtualServer->setJobPublicKey($pubKey);
            $this->VirtualServer->save();
        }catch(\Exception $e){
            $error = 'Problem while creating asymmetric keypair: '.$this->MakePrettyException($e);
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error);  
        }
    }
    
    private function sendAdminPublicKeyToRemoteserver(){
        try{
            $source = $this->di['config']->push['adminpublickeyfile'];
            if(!file_exists($source)){
                throw new \Exception("Public key file of adminserver does not exist in path '".$source."'. Please create this file (see INSTALL)");
            }
            $destination = $this->ConfigAdminPublicKeyFilePath;
            $this->RemoteSshConnection->sendFile($source, $destination);
        }catch(\Exception $e){               
            $error = 'Problem while sending admin public key: '.$this->MakePrettyException($e);
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error);  
        }
    }
    
    private function createLinuxUserAndGroup(){
        try{
            // --system means no shell and no password
            // --user-group means that it creates a group with same name
            $this->RemoteSshConnection->exec('useradd --system --user-group dnscp');
        }catch(\Exception $e){
            $error = 'Problem while creating linux user and group: '.$this->MakePrettyException($e);
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error);  
        }
    }
    
    private function copyJobsystemScriptsToServer(){
        try{
            foreach($this->PathsToJobsystemDirectoriesOnAdminServer as $pathToJobsystemDirectoryOnAdminServer){
                // iterate recursively over the directory with source code files for jobsystem and store them in a array $files
                // this array consists of the source filepath in the key and the representative destination filepath in the value
                // the second array $directories is for previously creating the needed directories
                $directory = new \RecursiveDirectoryIterator($pathToJobsystemDirectoryOnAdminServer,\FilesystemIterator::SKIP_DOTS);
                $iterator = new \RecursiveIteratorIterator($directory);
                $files = array();
                $directories = array();
                foreach ($iterator as $info) {
                    $localFilepath = $info->getPathname();
                    $destinationFilepath = str_replace($pathToJobsystemDirectoryOnAdminServer,$this->ConfigDnsJobsystemRootDir,$localFilepath);
                    $files[$localFilepath] = $destinationFilepath;
                    $destinationDirectory = str_replace($pathToJobsystemDirectoryOnAdminServer,$this->ConfigDnsJobsystemRootDir,$info->getPath().'/');
                    $directories[$destinationDirectory] = true;
                }

                foreach($directories as $directory => $novalue){
                    $this->RemoteSshConnection->exec('mkdir -p '.$directory);
                }

                foreach($files as $source => $destination){
                    $this->RemoteSshConnection->sendFile($source, $destination);
                }   
            }
        }catch(\Exception $e){
            $error = 'Problem while sending jobsystem source code files: '.$this->MakePrettyException($e);
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error);  
        }        
    }
    
    private function prepareFurtherJobsystemDirectories(){
        try{
            $folders = array(
                $this->ConfigDnsJobsystemRootDir.'db',
                $this->ConfigDnsJobsystemRootDir.'log',
            );
            foreach($folders as $folder) {
                $this->createDirectoryIfNotExists($folder);
            }
        }catch(\Exception $e){
            $error = 'Problem while creating further jobsystem directories: '.$this->MakePrettyException($e);
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error);  
        }
    }
    
    private function configureComposer(){
        try{
            $output = $this->RemoteSshConnection->exec('curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer 2>&1');
            if($this->RemoteSshConnection->getLastExitStatus() != 0){
                throw new \Exception('Could not install composer. Got exitcode '.$exitCode.' and output: "'.$output.'"');
            }
            $output = $this->RemoteSshConnection->exec('(cd '.$this->ConfigDnsJobsystemRootDir.'; composer update 2>&1)');
            if($this->RemoteSshConnection->getLastExitStatus() != 0){
                throw new \Exception('Could not update composer. Got exitcode '.$exitCode.' and output: "'.$output.'"');
            }
        }catch(\Exception $e){
            $error = 'Problem while configuring composer: '.$this->MakePrettyException($e);
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error);  
        }
    }
    
    private function cleanPermissionsInJobsystemDirectories(){
        try{
            $this->RemoteSshConnection->exec('chown root:dnscp -R '.$this->ConfigDnsJobsystemRootDir.'*');
            $this->RemoteSshConnection->exec('chmod 640 -R '.$this->ConfigDnsJobsystemRootDir.'*');
            $this->RemoteSshConnection->exec('chmod 660 -R '.$this->ConfigDnsJobsystemRootDir.'log');
            $this->RemoteSshConnection->exec('chmod 660 -R '.$this->ConfigDnsJobsystemRootDir.'db');
            $this->RemoteSshConnection->exec('chmod u+X,g+X -R '.$this->ConfigDnsJobsystemRootDir.'*');
            $this->RemoteSshConnection->exec('chmod 750 '.$this->ConfigDnsJobsystemRootDir.'JobSystemStarter.php');
        }catch(\Exception $e){
            $error = 'Problem while cleaning permissions in jobsystem directories: '.$this->MakePrettyException($e);
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error);  
        }
    }
    
    private function configureSudoers(){
        try{
            $output = $this->RemoteSshConnection->exec('cat /etc/sudoers | grep \'dnscp ALL=(ALL)\'');
            if(empty($output)){
                $sudoersConfig = "dnscp ALL=(ALL) NOPASSWD: /srv/jobsystem/JobSystemStarter.php\n".
                    "Defaults!/srv/jobsystem/JobSystemStarter.php !requiretty\n";
                $this->RemoteSshConnection->exec('echo "'.$sudoersConfig.'" >> /etc/sudoers');
            }         
        }catch(\Exception $e){
            $error = 'Problem while configuring sudoers: '.$this->MakePrettyException($e);
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error);  
        }
    }
    
    private function configureDnscpService(){
        try{
            $serviceFile = '/usr/lib/systemd/system/dnscp.service';
            $dnscpServiceFileConfig = 
                "[Unit]\n".
                "Description=DNS Control Panel Remoteserver Service\n".
                "After=network.target\n".
                "\n".
                "[Service]\n".
                "Type=simple\n".
                "ExecStart=/usr/bin/php -S ".$this->VirtualServer->getFqdn().":8000 /srv/jobsystem/RestServiceStarter.php\n".
                "WorkingDirectory=/srv/jobsystem\n".
                "User=dnscp\n".
                "Restart=on-failure\n".
                "\n".
                "[Install]\n".
                "WantedBy=multi-user.target\n";
            
            // try to stop the service (if it exists already)
            $this->RemoteSshConnection->exec('systemctl stop dnscp.service 2>&1');
            
            // write complete file new
            $this->RemoteSshConnection->exec('echo "'.$dnscpServiceFileConfig.'" > '.$serviceFile);
            
            // link the init file
            $this->RemoteSshConnection->exec('ln -s '.$serviceFile.' /etc/systemd/system/multi-user.target.wants/dnscp.service');
            
            // start the service
            $this->RemoteSshConnection->exec('systemctl daemon-reload');
            $output = $this->RemoteSshConnection->exec('systemctl start dnscp.service');
            if($this->RemoteSshConnection->getLastExitStatus() != 0){
                throw new \Exception('Could not start dnscp.service. Got exitcode '.$exitCode.' and output: "'.$output.'"');
            }
            
        }catch(\Exception $e){
            $error = 'Problem while configuring dnscp.service: '.$this->MakePrettyException($e);
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error);  
        } 
    }
    
    private function writeDnscpLocalConfig(){
        try{
            $config = ''.
                "<?php\n".
                "\t"."// Server"."\n".
                "\t"."define('SERVERFQDN','".$this->VirtualServer->getFqdn()."');"."\n".
                "\t".""."\n".
                "\t"."// FileLogger"."\n".
                "\t"."define('LOGFILE','".$this->ConfigDnsJobsystemRootDir."log/filelogger.log');"."\n".
                "\t"."define('LOGLEVEL','NOTICE');"."\n".
                "\t".""."\n".
                "\t"."// DNS"."\n".
                //"\t"."define('OVZ_PRIVATE_PATH','/vz/private');"."\n".
                "\t".""."\n".
                "\t"."// Key Pair"."\n".
                "\t"."define('PUBLIC_KEY_FILE','".$this->ConfigMyPublicKeyFilePath."');"."\n".
                "\t"."define('PRIVATE_KEY_FILE','".$this->ConfigMyPrivateKeyFilePath."');"."\n".
                "\t"."define('ADMIN_PUBLIC_KEY_FILE','".$this->ConfigAdminPublicKeyFilePath."');"."\n".
                "\t"."define('SYSTEMWIDE_SECRETKEY','".$this->di['config']->push['jwtsigningkey']."');"."\n".
                '';
            $configFilepath = '/srv/dnscp.local.config.php';
            $this->RemoteSshConnection->exec('echo "'.$config.'" > '.$configFilepath);
            $this->RemoteSshConnection->exec('chown root:dnscp '.$configFilepath);    
            $this->RemoteSshConnection->exec('chmod 640 '.$configFilepath);
        }catch(\Exception $e){
            $error = 'Problem while writing dnscp local config: '.$this->MakePrettyException($e);
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error);
        }    
    }
    
    private function postInstallation(){
        try{
            // Todo: set dns to 1, but will this field be added to the VirtualServers model?
            //$this->VirtualServer->setDns(1);
            $this->VirtualServer->save(); 
        }catch(\Exception $e){
            $error = 'Problem in post installation: '.$this->MakePrettyException($e);
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error); 
        }
    }
    
    private function testJobSystem(){
        $params = array("TO"=>$this->di['config']->mail['rootalias'],"MESSAGE"=>'This is a test message generated from the Connector while connecting to '.$this->VirtualServer->getFqdn());
        $push = $this->getPushService();
        $job = $push->executeJob($this->VirtualServer,'general_test_sendmail',$params);

        if($job->getDone() != 1){
            $error = 'Problem in testing job system: '.$job->getError();
            $this->Logger->error('DnsConnector: '.$error);
            throw new \Exception($error); 
        }
    }
    
    /**
    * dummy method only for auto completion purpose
    * 
    * @return \RNTForest\core\services\Push
    */
    private function getPushService(){
        return $this->di['push'];
    }
    
    /**
    * Create the specified path in the remoteconnection.
    * 
    * @param string $path
    * @throws Exception
    */
    private function createDirectoryIfNotExists($path){
        // create directories and check afterwards if they exists
        // -p option "no error if existing, make parent directories as needed"
        $this->RemoteSshConnection->exec('mkdir -p '.$path);
        $output = $this->RemoteSshConnection->exec('if [ -d "'.$path.'" ]; 
                                                then echo "1"; else echo 0; fi');
        if(intval($output)!=1){
            throw new \Exception("Directory ".$path." not found after creation (could not be created...)");   
        }        
    }
    
    /**
    * Hilfsfunktion um schöne Exception-Strings erstellen zu können. Anstatt $e->getMessage() im catch-Block aufzurufen
    * 
    * @param Exception $e
    * @return String
    */
    private function MakePrettyException(\Exception $e) {
        $trace = $e->getTrace();

        $result = 'Exception: "';
        $result .= $e->getMessage();
        $result .= '" @ ';   
        if($trace[0]['class'] != '') {
            $result .= $trace[0]['class'];
            $result .= '->';
        }
        $result .= $trace[0]['function'];
        $result .= '(); on Line '.$e->getLine().'<br />';

        return $result;
    }
}
