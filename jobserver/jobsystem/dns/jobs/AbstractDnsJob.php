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

namespace RNTForest\jobsystem\ovz\jobs;

use RNTForest\jobsystem\general\jobs\AbstractJob;
use RNTForest\jobsystem\general\utility\Context;

abstract class AbstractDnsJob extends AbstractJob{


    public function __construct(Context $context) {
        parent::__construct($context);
    }

    /**
    * helper method
    *     
    * @param mixed $message
    */
    protected function commandSuccess($message){
        $this->Done = 1;    
        $this->Retval = $this->Context->getCli()->getOutput();
        $this->Context->getLogger()->debug($message);
    }

    /**
    * helper method
    * 
    * @param string $message
    */
    protected function commandFailed($message,$exitstatus){
        $this->Done = 2;
        $this->Error = $message." Exit Code: ".$exitstatus.", Output:\n".implode("\n",$this->Context->getCli()->getOutput());
        $this->Context->getLogger()->error($this->Error);
        return $exitstatus;
    }

}
